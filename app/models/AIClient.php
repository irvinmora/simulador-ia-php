<?php
class AIClient {
    private string $model;
    public function __construct(string $model){
        // Normalizar alias comunes del selector
        $m=strtolower(trim($model));
    if($m==='gpt' || $m==='gpt-4o') $model='gpt-4o';
    if($m==='gemini' || $m==='gemini-flash') $model='gemini-1.5-flash';
    // Mapear a IDs vigentes de Anthropic (2025)
    if($m==='claude' || $m==='claude-3-sonnet' || $m==='claude-3.5-sonnet' || $m==='claude-3-5-sonnet') $model='claude-3-5-sonnet-20240620';
    if($m==='claude-3-haiku') $model='claude-3-haiku-20240307';
    if($m==='claude-3-opus') $model='claude-3-opus-20240229';
        if(str_starts_with($m,'ollama:')===false && $m==='llama3') $model='ollama:llama3:latest';
        $this->model=$model;
        logger('info','AIClient model set',['model'=>$this->model]);
    }

    public function answerBatch(array $prompts): array {
        // Llama proveedor según prefijo. Se usa fallback simulado si faltan claves.
        $answers=[]; foreach($prompts as $p){ $answers[]=$this->singleAnswer($p); } return $answers;
    }

    public function singleAnswer(string $prompt): string {
        $m=$this->model;
        $answer=''; $provider='';
        try {
            if(str_starts_with($m,'ollama:')) { $provider='ollama'; $this->enforceRpm($provider); $this->respectRateBackoff($provider); $answer=$this->ollamaAnswer($prompt, substr($m,7)); }
            elseif(str_contains($m,'gpt')) { $provider='openai'; $this->enforceRpm($provider); $this->respectRateBackoff($provider); $answer=$this->openAIAnswer($prompt); }
            elseif(str_contains($m,'gemini')) { $provider='gemini'; $this->enforceRpm($provider); $this->respectRateBackoff($provider); $answer=$this->geminiAnswer($prompt); }
            elseif(str_starts_with($m,'claude')) { $provider='claude'; $this->enforceRpm($provider); $this->respectRateBackoff($provider); $answer=$this->claudeAnswer($prompt); }
            else { $answer='[IA simulada] '.substr($prompt,0,120); }
        } catch(\Throwable $t){
            logger('error','IA exception',['provider'=>$provider,'err'=>$t->getMessage()]);
            $answer='';
        }
        logger('info','IA provider used',['provider'=>$provider,'model'=>$this->model]);
        if($this->isErrorAnswer($answer)){
            $fallback = env('FALLBACK_MODEL',''); // fallback desactivado por defecto
            if($fallback && $fallback!==$this->model){
                logger('warning','Falling back LLM',['from'=>$this->model,'to'=>$fallback]);
                $fbClient=new self($fallback);
                $fbAns=$fbClient->singleAnswer("(Fallback) ".$prompt);
                if(!$this->isErrorAnswer($fbAns)) return $fbAns; else return '[Error LLM]';
            }
        }
        return $answer;
    }

    private function openAIAnswer(string $prompt): string {
        $key=env('OPENAI_API_KEY'); if(!$key) return '[Simulado GPT] '.substr($prompt,0,80);
        $url='https://api.openai.com/v1/chat/completions';
        $payload=[ 'model'=>$this->model, 'messages'=>[['role'=>'user','content'=>$prompt]], 'temperature'=>0.2 ];
        $res=$this->curlJson($url,$payload,[ 'Authorization: Bearer '.$key ]);
        // Backoff si 429 (un reintento automático)
        if(($res['__http_code'] ?? 200)===429){
            $this->applyRateBackoff('openai', $res['__headers'] ?? []);
            $this->respectRateBackoff('openai');
            $res=$this->curlJson($url,$payload,[ 'Authorization: Bearer '.$key ]);
            if(($res['__http_code'] ?? 200)===429){ return '[Error GPT 429]'; }
        }
        return $res['choices'][0]['message']['content'] ?? '[Error GPT]';
    }
    private function geminiAnswer(string $prompt): string {
        $key=env('GEMINI_API_KEY'); if(!$key) return '[Simulado Gemini] '.substr($prompt,0,80);
        $m=$this->model==='gemini-flash'?'gemini-1.5-flash':$this->model;
        $url='https://generativelanguage.googleapis.com/v1beta/models/'.$m.':generateContent?key='.$key;
        $payload=['contents'=>[['parts'=>[['text'=>$prompt]]]]];
        $res=$this->curlJson($url,$payload,[]);
        if(($res['__http_code'] ?? 200)===429){
            $this->applyRateBackoff('gemini', $res['__headers'] ?? []);
            $this->respectRateBackoff('gemini');
            $res=$this->curlJson($url,$payload,[]);
            if(($res['__http_code'] ?? 200)===429){ return '[Error Gemini 429]'; }
        }
        return $res['candidates'][0]['content']['parts'][0]['text'] ?? '[Error Gemini]';
    }
    private function claudeAnswer(string $prompt): string {
        $key=env('CLAUDE_API_KEY') ?: env('ANTHROPIC_API_KEY');
        if(!$key) return '[Simulado Claude] '.substr($prompt,0,80);
        $url='https://api.anthropic.com/v1/messages';
        $headers=[ 'x-api-key: '.$key, 'anthropic-version: 2023-06-01', 'content-type: application/json' ];
        // Reducir max_tokens automáticamente según contenido
        $isOptions = preg_match('/opcion|opciones|correcta|letra|\bA\)/i',$prompt)===1;
        $maxT = $isOptions ? (int)env('CLAUDE_MAX_TOKENS_OPC',200) : (int)env('CLAUDE_MAX_TOKENS_SOLAS',120);
        $temperature = (float)env('TEMPERATURE',0);
        $payload=['model'=>$this->model,'max_tokens'=>$maxT,'temperature'=>$temperature,'messages'=>[[
            'role'=>'user',
            'content'=>[
                ['type'=>'text','text'=>$prompt]
            ]
        ]]];
        $res=$this->curlJson($url,$payload,$headers);
        if(($res['__http_code'] ?? 200)===429){
            $this->applyRateBackoff('claude', $res['__headers'] ?? []);
            $this->respectRateBackoff('claude');
            $res=$this->curlJson($url,$payload,$headers);
            if(($res['__http_code'] ?? 200)===429){ return '[Error Claude 429]'; }
        }
        if(isset($res['content'][0]['text'])) return $res['content'][0]['text'];
        // Fallback: si la versión Sonnet 3.0 no existe en esta cuenta, reintentar con 3.5
        if($this->model==='claude-3-sonnet-20240229'){
            logger('warning','Claude model 3.0 no disponible, probando 3.5 Sonnet');
            $payload['model']='claude-3-5-sonnet-20240620';
            $res2=$this->curlJson($url,$payload,$headers);
            if(($res2['__http_code'] ?? 200)===429){ $this->applyRateBackoff('claude', $res2['__headers'] ?? []); return '[Error Claude 429]'; }
            if(isset($res2['content'][0]['text'])) return $res2['content'][0]['text'];
        }
        // Fallback adicional: si 3.5 no está disponible, intentar con Haiku 3.0
        if(str_starts_with($this->model,'claude-3-5-sonnet')){
            logger('warning','Claude 3.5 Sonnet no disponible, probando 3 Haiku');
            $payload['model']='claude-3-haiku-20240307';
            $res3=$this->curlJson($url,$payload,$headers);
            if(($res3['__http_code'] ?? 200)===429){ $this->applyRateBackoff('claude', $res3['__headers'] ?? []); $this->respectRateBackoff('claude'); $res3=$this->curlJson($url,$payload,$headers); if(($res3['__http_code'] ?? 200)===429){ return '[Error Claude 429]'; } }
            if(isset($res3['content'][0]['text'])) return $res3['content'][0]['text'];
        }
        // Fallback simétrico: si Haiku falla, intentar 3.5 Sonnet
        if(str_starts_with($this->model,'claude-3-haiku')){
            logger('warning','Claude 3 Haiku no disponible, probando 3.5 Sonnet');
            $payload['model']='claude-3-5-sonnet-20240620';
            $res4=$this->curlJson($url,$payload,$headers);
            if(($res4['__http_code'] ?? 200)===429){ $this->applyRateBackoff('claude', $res4['__headers'] ?? []); $this->respectRateBackoff('claude'); $res4=$this->curlJson($url,$payload,$headers); if(($res4['__http_code'] ?? 200)===429){ return '[Error Claude 429]'; } }
            if(isset($res4['content'][0]['text'])) return $res4['content'][0]['text'];
        }
        return '[Error Claude]';
    }
    private function ollamaAnswer(string $prompt,string $name): string {
        $endpoint=rtrim(env('OLLAMA_ENDPOINT','http://localhost:11434'),'/');
        $url=$endpoint.'/api/generate';
        $payload=['model'=>$name,'prompt'=>$prompt,'stream'=>false];
        $res=$this->curlJson($url,$payload,[]);
        if(($res['__http_code'] ?? 200)===429){
            $this->applyRateBackoff('ollama', $res['__headers'] ?? []);
            $this->respectRateBackoff('ollama');
            $res=$this->curlJson($url,$payload,[]);
            if(($res['__http_code'] ?? 200)===429){ return '[Error Ollama 429]'; }
        }
        return $res['response'] ?? '[Error Ollama]';
    }

    private function curlJson(string $url,array $payload,array $headers): array {
        $ch=curl_init($url);
        $base=[ 'Content-Type: application/json' ];
        $respHeaders = [];
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>60,
            CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_HTTPHEADER=>array_merge($base,$headers),
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_HEADERFUNCTION=>function($ch,$header) use (&$respHeaders){
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if(count($parts)==2){ $respHeaders[trim(strtolower($parts[0]))] = trim($parts[1]); }
                return $len;
            }
        ]);
        $raw=curl_exec($ch); if($raw===false){ logger('error','curl '.curl_error($ch)); return ['__http_code'=>0,'__headers'=>$respHeaders]; }
        $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        $data=json_decode($raw,true);
        if($code>=400){ logger('error','IA error',['code'=>$code,'body'=>substr($raw,0,500)]); }
        if(!$data){ logger('warning','IA empty JSON',['snippet'=>substr($raw,0,120)]); $data=[]; }
        if(is_array($data)){
            $data['__http_code']=$code; $data['__headers']=$respHeaders; $data['__raw']=$raw;
            return $data;
        }
        return ['__http_code'=>$code,'__headers'=>$respHeaders,'__raw'=>$raw];
    }

    private function applyRateBackoff(string $providerKey, array $headers): void {
        // Detectar Retry-After (segundos o milisegundos)
        $h = [];
        foreach($headers as $k=>$v){ $h[strtolower($k)] = $v; }
        $ra = $h['retry-after'] ?? ($h['retry-after-ms'] ?? null);
        $waitMs = 15000; // por defecto 15s
        if($ra!==null){
            $val = trim($ra);
            if(is_numeric($val)){
                $num = (float)$val; $waitMs = $num>1000 ? (int)$num : (int)round($num*1000);
            }
        }
        $rateFile = TEMP_PATH.'/.ai_rate.json';
        $rateState = [];
        if(is_file($rateFile)){
            $raw=@file_get_contents($rateFile); $j=@json_decode($raw,true); if(is_array($j)) $rateState=$j;
        }
        $nowMs = (int)round(microtime(true)*1000);
        $rateState[$providerKey] = ['last'=>$nowMs, 'until'=>$nowMs + $waitMs];
        @file_put_contents($rateFile, json_encode($rateState));
        logger('warning','Rate limited 429; backing off',['provider'=>$providerKey,'wait_ms'=>$waitMs]);
    }

    private function respectRateBackoff(string $providerKey): void {
        // Si recientemente recibimos 429 para este proveedor, esperar hasta el tiempo indicado
        $rateFile = TEMP_PATH.'/.ai_rate.json';
        if(!is_file($rateFile)) return;
        $raw=@file_get_contents($rateFile); $state=@json_decode($raw,true);
        if(!is_array($state)) return;
        $info = $state[$providerKey] ?? null; if(!$info) return;
        $until = (int)($info['until'] ?? 0);
        $nowMs = (int)round(microtime(true)*1000);
        if($until > $nowMs){
            $sleepMs = min(20000, max(1000, $until - $nowMs)); // entre 1s y 20s
            logger('info','Esperando por backoff 429',['provider'=>$providerKey,'sleep_ms'=>$sleepMs]);
            usleep($sleepMs * 1000);
        }
    }

    private function enforceRpm(string $providerKey): void {
        // Limitador simple por proveedor para no exceder RPM. Lee de .env o usa defaults.
        $limits = [
            'claude' => (int)env('RPM_CLAUDE', 5),
            'openai' => (int)env('RPM_OPENAI', 60),
            'gemini' => (int)env('RPM_GEMINI', 60),
            'ollama' => (int)env('RPM_OLLAMA', 120),
        ];
        $limit = $limits[$providerKey] ?? 60; if($limit <= 0) return;
        $rpmFile = TEMP_PATH.'/.ai_rpm.json';
        $state = [];
        if(is_file($rpmFile)){
            $raw=@file_get_contents($rpmFile); $j=@json_decode($raw,true); if(is_array($j)) $state=$j;
        }
        $now = microtime(true);
        $window = 60.0; // segundos
        $arr = isset($state[$providerKey]) && is_array($state[$providerKey]) ? $state[$providerKey] : [];
        // Filtrar a la ventana de 60s
        $arr = array_values(array_filter($arr, function($ts) use ($now,$window){ return is_numeric($ts) && ($now - (float)$ts) < $window; }));
        if(count($arr) >= $limit){
            sort($arr); // el más antiguo primero
            $oldest = (float)$arr[0];
            $wait = ($oldest + $window) - $now; // segundos a esperar hasta liberar 1 slot
            if($wait > 0){
                $sleepMs = (int)min(30000, max(500, ceil($wait*1000)));
                logger('info','Throttle RPM esperando',['provider'=>$providerKey,'sleep_ms'=>$sleepMs,'limit'=>$limit,'used'=>count($arr)]);
                usleep($sleepMs * 1000);
                // Recalcular después de dormir
                $now = microtime(true);
                $arr = array_values(array_filter($arr, function($ts) use ($now,$window){ return is_numeric($ts) && ($now - (float)$ts) < $window; }));
            }
        }
        // Reservar slot actual y persistir
        $arr[] = $now;
        $state[$providerKey] = $arr;
        @file_put_contents($rpmFile, json_encode($state));
    }

    private function isErrorAnswer(?string $ans): bool {
        if(!$ans) return true;
        return str_contains($ans,'[Error') || strlen(trim($ans))<3;
    }
}
