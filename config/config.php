<?php
// Carga .env simple
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$k,$v] = array_pad(explode('=',$line,2),2,null);
        if($k) $_ENV[$k]=trim($v);
    }
}

date_default_timezone_set($_ENV['TIMEZONE'] ?? 'UTC');

function env($key,$default=null){return $_ENV[$key] ?? $default;}

const BASE_PATH = __DIR__ . '/..';
const VIEW_PATH = __DIR__ . '/../app/views';
const STORAGE_PATH = __DIR__ . '/../storage';
const TEMP_PATH = STORAGE_PATH . '/temp';
const LOG_PATH = STORAGE_PATH . '/logs/app.log';

// Autoload Composer (si existe)
$autoload = BASE_PATH . '/vendor/autoload.php';
if(file_exists($autoload)) require_once $autoload;

if(!is_dir(TEMP_PATH)) mkdir(TEMP_PATH,0777,true);
if(!is_dir(dirname(LOG_PATH))) mkdir(dirname(LOG_PATH),0777,true);
if(session_status()===PHP_SESSION_NONE) @session_start();

// Limpieza de archivos temporales antiguos (>2h) - se ejecuta a lo sumo cada 10 min
function cleanup_temp($maxAgeSeconds=7200){
    foreach(glob(TEMP_PATH.'/*') as $f){
        if(is_file($f) && time()-@filemtime($f) > $maxAgeSeconds){ @unlink($f); }
    }
}
function maybe_cleanup_temp(){
    static $ran=false; if($ran) return; $ran=true; // evitar doble ejecución en la misma request
    $flag=TEMP_PATH.'/.last_cleanup';
    $interval=600; // 10 minutos
    if(!file_exists($flag) || (time()-@filemtime($flag))>$interval){
        cleanup_temp();
        @touch($flag);
    }
}
maybe_cleanup_temp();

function logger($level,$msg,$context=[]) {
    $min = env('LOG_LEVEL','debug');
    $levels = ['debug'=>0,'info'=>1,'warning'=>2,'error'=>3];
    if(($levels[$level]??0) < ($levels[$min]??0)) return;
    $line = date('c')." [$level] ".$msg;
    if($context) $line .= ' '.json_encode($context,JSON_UNESCAPED_UNICODE);
    file_put_contents(LOG_PATH,$line."\n",FILE_APPEND);
}

function render($view,$data=[]) {
    extract($data);
    $file = VIEW_PATH . '/' . $view . '.php';
    if(!file_exists($file)) { echo "Vista no encontrada: $view"; return; }
    include VIEW_PATH . '/partials/header.php';
    include $file;
    include VIEW_PATH . '/partials/footer.php';
}

function flash_errors_script(){
    if(!empty($GLOBALS['FLASH_ERRORS'])){
        echo '<script>(function(){\n'
            . 'function safeShow(msg){ try { if(window.showErrorModal){ window.showErrorModal(msg); } else { alert(msg); } } catch(e){ try{ alert(msg); }catch(_){} } }\n';
        foreach($GLOBALS['FLASH_ERRORS'] as $e){
            echo 'safeShow('.json_encode($e,JSON_UNESCAPED_UNICODE).');';
        }
        echo '})();</script>';
    }
}

function add_error($msg){
    $GLOBALS['FLASH_ERRORS'][]=$msg;
    logger('error',$msg);
}

// CSRF simple
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_field(){ $t=csrf_token(); echo '<input type="hidden" name="_csrf" value="'.$t.'" />'; }
function csrf_check(){ if($_SERVER['REQUEST_METHOD']==='POST'){ $ok = !empty($_POST['_csrf']) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'],$_POST['_csrf']); if(!$ok) { add_error('Token CSRF inválido'); http_response_code(400); exit('CSRF'); } } }

// Export helpers
function export_docx($blocks,$file){
    if(!class_exists('PhpOffice\\PhpWord\\PhpWord')){ logger('warning','PhpWord no disponible - omitiendo DOCX'); return false; }
    if(!extension_loaded('zip')){ logger('error','Extensión zip faltante - DOCX no generado'); return false; }
    try {
        $phpWord = new PhpOffice\PhpWord\PhpWord();
        $section=$phpWord->addSection();
        foreach($blocks as $b){
            $lines=preg_split('/\n/',$b);
            if(count($lines)>0){
                $first=array_shift($lines);
                $section->addText($first,[ 'name'=>'Arial','size'=>11,'bold'=>preg_match('/^Pregunta/i',$first)?true:false ]);
                foreach($lines as $ln){
                    $t=trim($ln); if($t==='') continue;
                    $bold = preg_match('/^(Opciones\s*:|Respuesta\s+correcta\s*:)/i',$t) ? true : false;
                    $section->addText($ln,[ 'name'=>'Arial','size'=>11,'bold'=>$bold ]);
                }
            }
            $section->addTextBreak(1);
        }
        $writer=PhpOffice\PhpWord\IOFactory::createWriter($phpWord,'Word2007');
        $writer->save($file);
        clearstatcache(true,$file);
        $size=@filesize($file);
        if(!$size || $size<800){ logger('error','DOCX tamaño inesperado',['size'=>$size]); return false; }
        return true;
    } catch(\Throwable $e){ logger('error','Fallo export_docx',['err'=>$e->getMessage()]); return false; }
}
function export_pdf($html,$file){
    if(!class_exists('Mpdf\\Mpdf')) { logger('warning','mpdf no disponible - omitiendo PDF'); return false; }
    try {
        if(function_exists('ini_set')) @ini_set('memory_limit', env('PDF_MEMORY_LIMIT','256M'));
        $cls='Mpdf\\Mpdf';
        $config=['mode'=>'utf-8','tempDir'=>TEMP_PATH,'default_font'=>'dejavusans'];
        $mpdf=new $cls($config);
        $mpdf->useSubstitutions = true; // sustituir fuentes faltantes
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $doc='<!DOCTYPE html><html><head><meta charset="utf-8" /><style>body{font-family:DejaVu Sans, Arial; font-size:11pt} p{margin:0 0 10px}</style></head><body>'.$html.'</body></html>';
        $mpdf->WriteHTML($doc);
        $mpdf->Output($file,'F');
        clearstatcache(true,$file);
        $size=@filesize($file);
        if(!$size || $size<300){ // tamaño anómalo (muy pequeño)
            logger('error','PDF tamaño inesperado',['size'=>$size,'file'=>$file]);
            // reintento con configuración alternativa
            $mpdf=new $cls(['mode'=>'utf-8','format'=>'A4','tempDir'=>TEMP_PATH,'default_font'=>'dejavusans']);
            $mpdf->useSubstitutions = true; $mpdf->autoScriptToLang = true; $mpdf->autoLangToFont = true;
            $mpdf->WriteHTML($doc);
            $mpdf->Output($file,'F');
            clearstatcache(true,$file);
            $size=@filesize($file);
            if(!$size || $size<300){ return false; }
        }
        // Validar cabecera %PDF
        $fh=@fopen($file,'rb');
        if($fh){
            $head=fread($fh,5); fclose($fh);
            if(strncmp($head,'%PDF-',5)!==0){
                logger('error','PDF cabecera inválida',['head'=>bin2hex($head),'file'=>$file,'size'=>$size]);
                // reintento final
                $mpdf=new $cls(['mode'=>'utf-8','format'=>'A4','tempDir'=>TEMP_PATH,'default_font'=>'dejavusans']);
                $mpdf->useSubstitutions = true; $mpdf->autoScriptToLang = true; $mpdf->autoLangToFont = true;
                $mpdf->WriteHTML($doc);
                $mpdf->Output($file,'F');
                clearstatcache(true,$file);
                $fh=@fopen($file,'rb'); if($fh){ $head=fread($fh,5); fclose($fh); if(strncmp($head,'%PDF-',5)!==0) return false; } else { return false; }
            }
        } else {
            logger('error','No se pudo abrir PDF para validar',['file'=>$file]);
            return false;
        }
        return true;
    } catch(\Throwable $e){
        logger('error','Fallo export_pdf',['err'=>$e->getMessage()]);
        return false;
    }
}

/**
 * Exporta PDF usando un servicio externo vía HTTP.
 * Requiere configurar en .env:
 *  - PDF_API_URL: endpoint que recibe HTML y devuelve binario PDF.
 *  - PDF_API_KEY (opcional): token Bearer.
 *  - PDF_API_HTML_FIELD (opcional, default 'html'): nombre del campo con el HTML.
 *  - PDF_API_METHOD (opcional, default 'POST')
 * El cuerpo se envía como JSON: { [HTML_FIELD]: html }
 */
function export_pdf_external(string $html, string $file): bool {
    $url = env('PDF_API_URL');
    if(!$url) return false;
    try {
        $payload = [ env('PDF_API_HTML_FIELD','html') => $html ];
        $ch = curl_init($url);
        $headers = [ 'Content-Type: application/json' ];
        $key = env('PDF_API_KEY'); if($key) $headers[] = 'Authorization: Bearer '.$key;
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if($err){ logger('error','PDF externo error curl',[ 'err'=>$err ]); return false; }
        if($code < 200 || $code >= 300){ logger('error','PDF externo HTTP no OK',[ 'code'=>$code, 'ctype'=>$ctype ]); return false; }
        if(!$resp || strlen($resp) < 300){ logger('error','PDF externo respuesta corta',[ 'len'=>strlen($resp) ]); return false; }
        // Validar que parezca PDF
        if($ctype && stripos($ctype,'pdf')===false){ logger('warning','PDF externo content-type inesperado',[ 'ctype'=>$ctype ]); }
        @file_put_contents($file, $resp);
        clearstatcache(true,$file);
        $size = @filesize($file);
        if(!$size || $size < 300){ return false; }
        $fh=@fopen($file,'rb'); if($fh){ $head=fread($fh,5); fclose($fh); if(strncmp($head,'%PDF-',5)!==0){ logger('warning','PDF externo cabecera no estándar'); } }
        return true;
    } catch(\Throwable $e){ logger('error','Fallo export_pdf_external',[ 'err'=>$e->getMessage() ]); return false; }
}

/**
 * Genera un HTML imprimible en un archivo .html
 */
function export_html_printable(string $innerHtml, string $file): bool {
    try {
    $doc = '<!DOCTYPE html><html><head><meta charset="utf-8" />'
             . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
             . '<title>Resultado</title>'
         . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:900px;margin:24px auto;padding:0 16px;color:#000;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact} '
         . '.toolbar{position:sticky;top:0;background:#fff;padding:8px 0;margin-bottom:12px;border-bottom:1px solid #eee;display:flex;gap:8px} '
         . 'button, a.btn{appearance:none;border:1px solid #ccc;background:#f7f7f7;border-radius:6px;padding:8px 12px;cursor:pointer;text-decoration:none;color:#000} '
         . '@media print {.toolbar{display:none!important} body{margin:0}} '
         . 'h1{font-size:20px;margin:8px 0 16px;color:#000} p{margin:0 0 8px;color:#000} .item{margin:0 0 14px} .q{font-weight:700;color:#000} .opts{margin:4px 0 4px 0;white-space:pre-wrap;color:#000} .ans{margin-top:4px;color:#000}
                code{white-space:pre-wrap;}
             </style></head><body>'
             . '<div class="toolbar"><button onclick="window.print()">Imprimir / Guardar como PDF</button>'
             . '<a class="btn" href="#" onclick="window.scrollTo({top:0,behavior:\'smooth\'});return false;">Arriba</a>'
             . '</div>'
             . '<h1>Resultado</h1>'
             . $innerHtml
             . '</body></html>';
        file_put_contents($file, $doc);
        clearstatcache(true,$file);
        return is_file($file) && filesize($file) > 100;
    } catch(\Throwable $e){ logger('error','Fallo export_html_printable',[ 'err'=>$e->getMessage() ]); return false; }
}

// Constantes de validación
const MAX_UPLOAD_SIZE = 15 * 1024 * 1024; // 15MB
const ALLOWED_MIME = [
    'application/pdf',
    'text/plain',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

function validate_upload($file){
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK){ add_error('Error al subir archivo (código '.$file['error'].')'); return false; }
    if(($file['size']??0) > MAX_UPLOAD_SIZE){ add_error('Archivo supera el límite de 15MB'); return false; }
    $fMime = mime_content_type($file['tmp_name']);
    if($fMime && !in_array($fMime,ALLOWED_MIME)){
        // Permitir algunos falsos positivos por fallback
        if(!str_contains($fMime,'text') && !str_contains($fMime,'pdf')){ add_error('Tipo MIME no permitido: '.$fMime); return false; }
    }
    return true;
}
