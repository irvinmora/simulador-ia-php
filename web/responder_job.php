<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../app/models/AIClient.php';
require_once __DIR__.'/../app/models/Parser.php';
header('Content-Type: application/json; charset=utf-8');
$id = preg_replace('/[^a-zA-Z0-9_\-]/','', $_GET['id'] ?? '');
$secret = $_GET['s'] ?? '';
$jobFile = TEMP_PATH."/$id.job.json";
if(!$id || !is_file($jobFile)){ echo json_encode(['error'=>'job not found']); exit; }
$job = json_decode(file_get_contents($jobFile),true);
if(!$job || ($job['secret']??'') !== $secret){ echo json_encode(['error'=>'unauthorized']); exit; }

if(isset($_GET['cancel']) && $_GET['cancel']=='1' && ($job['status']??'running')==='running'){
	$job['status']='cancelled';
	file_put_contents($jobFile,json_encode($job,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
	echo json_encode(['status'=>'cancelled','processed'=>$job['processed'],'total'=>$job['total'],'files'=>$job['files']??[],'source'=>$job['source']]);
	exit;
}

if(($job['status']??'')==='done' || ($job['status']??'')==='cancelled'){
	echo json_encode([
		'status'=>$job['status'],
		'processed'=>$job['processed'],
		'total'=>$job['total'],
		'files'=>$job['files'],
		'source'=>$job['source']
	]); exit;
}

// Guard de tasa: limitar llamadas a IA por minuto (RPM) a nivel de proceso
$rateFile = TEMP_PATH.'/.ai_rate.json';
$rateState = [];
if(is_file($rateFile)){
	$raw=@file_get_contents($rateFile); $j=@json_decode($raw,true); if(is_array($j)) $rateState=$j;
}
$nowMs = (int)round(microtime(true)*1000);
$rpm = max(1, (int)env('AI_RPM','4')); // por defecto 4 req/min
$minGap = (int)ceil(60000/$rpm) + 200; // +200ms colchón
// Seleccionar proveedor según modelo del job
$providerKey = (function($m){
	$ml=strtolower($m);
	if(str_starts_with($ml,'ollama:')) return 'ollama';
	if(str_contains($ml,'gpt')) return 'openai';
	if(str_contains($ml,'gemini')) return 'gemini';
	if(str_starts_with($ml,'claude')) return 'claude';
	return 'default';
})($job['modelo'] ?? '');
$last = (int)($rateState[$providerKey]['last'] ?? 0);
$until = (int)($rateState[$providerKey]['until'] ?? 0);
$waitMs = 0;
if($nowMs < $until){ $waitMs = $until - $nowMs; }
elseif(($nowMs - $last) < $minGap){ $waitMs = $minGap - ($nowMs - $last); }
if($waitMs > 0){
	// Aún no es turno de llamar a la IA: devolvemos progreso sin procesar
	echo json_encode([
		'status'=>$job['status'],
		'processed'=>$job['processed'],
		'total'=>$job['total'],
		'files'=>$job['files'] ?? [],
		'source'=>$job['source'],
		'rate_wait_ms'=>$waitMs
	]);
	exit;
}

// Procesar siguiente bloque
// Parámetros de rendimiento (controlados por .env)
$batch = max(1, min( (int)env('BATCH_SIZE','5'), 10)); // default 5, hasta 10
$retries = 1; // reintentos por bloque en error
$mode = $_GET['mode'] ?? ($job['mode'] ?? 'full');
// Modo AUTO: la IA detecta preguntas y responde desde el texto completo
$autoReq = (bool)($job['auto'] ?? false);
// Solo usar AUTO como fallback si el formato no es reconocido o el parser falló al segmentar
$recognized = ['PREG_OPC','PREG_OPC_RESP','PREG_SOLAS'];
$auto = false;
if($autoReq){
	$fmt = $job['formato'] ?? '';
	$totalBlocks = (int)($job['total'] ?? 0);
	$first = $job['bloques'][0] ?? '';
	$looksLikeQ = (bool)preg_match('/¿[^?]{3,}\?/u', $first);
	$hasOptionsLabel = (bool)preg_match('/Opciones\s*:/i', $first);
	if(!in_array($fmt, $recognized, true) || $totalBlocks===0 || ($totalBlocks===1 && !$looksLikeQ && !$hasOptionsLabel)){
		$auto = true;
	}
}
// Estilo de respuesta para preguntas sin opciones: short|medium|long
// Eliminado: estilos de respuesta (short/medium/long) para PREG_SOLAS
// Auto-batching server-side
$fast = env('AUTO_BATCH','1')==='1';
$ultra = false; // desactivado por defecto salvo AUTO_ULTRA_SMALL
$job['mode']=$mode; // persistir
// Cálculo de lote adaptativo según tamaño del documento y longitud media
$computeAdaptiveBatch = function(array $job) use ($batch): int {
	$rest = max(0, ($job['total'] - $job['processed']));
	if($rest <= 1) return 1;
	$minB = max(1, (int)env('BATCH_MIN','2'));
	$maxB = max($minB, min(10, (int)env('BATCH_MAX','10')));
	$base = max($minB, min($batch, $maxB));
	// Base por tamaño de documento
	if($rest >= 40) $base = max($base, min(10,$maxB));
	elseif($rest >= 20) $base = max($base, min(8,$maxB));
	elseif($rest >= 9) $base = max($base, min(6,$maxB));
	else $base = max($base, min(4,$maxB));
	// Ajuste por longitud promedio (evitar prompts gigantes)
	$sample = min($rest, (int)env('AVG_CHAR_SAMPLE','5'));
	$sum = 0; for($i=0;$i<$sample;$i++){ $sum += strlen($job['bloques'][$job['processed']+$i] ?? ''); }
	$avg = $sample>0 ? $sum/$sample : 250.0;
	$maxChars = max(1000, (int)env('MAX_CHARS_PER_CALL','4500'));
	$overhead = (int)env('PROMPT_OVERHEAD_PER_Q','120'); // instrucción + numeración
	$allowed = max(1, (int) floor($maxChars / max(1, ($avg+$overhead))));
	$final = max(1, min($base, $allowed, $rest));
	return $final;
};

// Auto ultra para documentos pequeños
if(env('AUTO_ULTRA_SMALL','1')==='1'){
	$rest = $job['total'] - $job['processed'];
	if($rest>0 && $rest<=3){
		$pend = array_slice($job['bloques'],$job['processed']);
		$chars = strlen(implode("\n", $pend));
		if($chars<2500) $ultra=true;
	}
}

// Tier inteligente para Claude: haiku en PREG_SOLAS, sonnet en opciones
$pickModel = function(string $base, string $formato): string {
	if(env('CLAUDE_TIER_STRATEGY','smart')!=='smart') return $base;
	$m=strtolower($base);
	if(str_starts_with($m,'claude')){
		if($formato==='PREG_SOLAS') return 'claude-3-haiku-20240307';
		return 'claude-3-5-sonnet-20240620';
	}
	return $base;
};

// Caché simple
$cacheFile = TEMP_PATH.'/.cache.json';
$cache = [];
if(env('ANSWER_CACHE','1')==='1' && is_file($cacheFile)){
	$raw=@file_get_contents($cacheFile);
	$j=@json_decode($raw,true); if(is_array($j)) $cache=$j;
}
$cacheGet = function($key) use (&$cache){ return $cache[$key] ?? null; };
$cachePut = function($key,$val) use (&$cache,$cacheFile){ $cache[$key]=$val; @file_put_contents($cacheFile,json_encode($cache,JSON_UNESCAPED_UNICODE)); };
$job['mode']=$mode; // persistir

$inicio = microtime(true);
// Normalizador de bloques: separa opciones en línea y 'Respuesta correcta' en nuevas líneas
$normalizeBlock = function(string $txt): string {
	// Insertar salto antes de cada marca de opción si viene en la misma línea
	$t = preg_replace('/\s+(?=(?:\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])\s+)/u', "\n", $txt);
	// Asegurar separación cuando la marca de opción está pegada a la palabra anterior ("...requerimientosb)")
	$t = preg_replace('/([^\s])((?:\(?[A-Ha-h]\)|[A-Ha-h][\).]))/u', '$1 $2', $t);
	// Reparar cortes obvios dentro de palabras antes de ')': p.ej. 'Controllin g)' -> 'Controlling)'
	$t = preg_replace('/\b(Controllin)\s+g\)/i', 'Controlling)', $t);
	// Evitar tratar como marcador cuando la letra minúscula precedida por n termina en 'in g)' (caso inglés)
	$t = preg_replace('/in\s+g\)/i', 'ing)', $t);
	// Asegurar salto después de 'Opciones:' y evitar duplicados 'Opciones: Opciones:'
	$t = preg_replace('/(Opciones\s*:)[ \t]*/i', "$1\n", $t);
	$t = preg_replace('/^\s*Opciones\s*:\s*Opciones\s*:\s*/im', 'Opciones: ', $t);
	// Asegurar salto antes de 'Respuesta correcta:'
	$t = preg_replace('/\s*(Respuesta\s+correcta\s*:)/i', "\n$1 ", $t);
	// Quitar 'Opciones:' si quedó pegado a un marcador de opción en la misma línea
	$t = preg_replace('/\bOpciones\s*:\s*(?=\(?[A-Ha-h]\)|[A-Ha-h][\).])/im', '', $t);
	// Compactar espacios múltiples
	$t = preg_replace('/[\t\x0B\f\r]+/', ' ', $t);
	return trim($t);
};

// ========== MODO AUTO (detección y respuesta con IA sobre texto completo) ==========
if($auto && $job['processed'] < $job['total']){
	$formato=$job['formato'];
	$modelo = $pickModel($job['modelo'], $formato);
	$ai = new AIClient($modelo);
	// Preparar texto completo (desde el inicio al final) y chunking controlado
	$fullText = '';
	foreach($job['bloques'] as $bl){ $fullText .= $bl."\n\n"; }
	$maxChars = max(5000, (int)env('AUTO_MAX_CHARS_PER_CALL','12000'));
	$chunks = [];
	// Partir por dobles saltos si es posible, para no cortar preguntas
	$parts = preg_split('/\n\s*\n/', $fullText);
	$buf='';
	foreach($parts as $p){
		if(mb_strlen($buf.'\n\n'.$p) <= $maxChars){ $buf = $buf ? ($buf."\n\n".$p) : $p; }
		else { if($buf!=='') $chunks[]=$buf; $buf=$p; }
	}
	if($buf!=='') $chunks[]=$buf;
	// Prompt de extracción+respuesta por chunk (contrato estable)
	$makePrompt = function(string $segment){
		return "Analiza el TEXTO y devuelve SOLO un JSON array. Cada elemento debe tener: "
			."{tipo:'opc'|'sola', pregunta:string, opciones?:string[], correcta?:string, respuesta?:string}. "
			."- Si detectas opciones, normaliza cada una como 'A) texto', 'B) texto', en orden. "
			."- Elige la opción correcta (campo 'correcta' con la letra). "
			."- Si no hay opciones, responde en 'respuesta' (breve y precisa). "
			."- Conserva el orden en que aparecen en el texto. "
			."- Devuelve SOLO JSON, sin explicaciones ni texto adicional.\n\n"
			."TEXTO:\n".$segment;
	};
	$allItems=[]; $chunkIndex=0;
	foreach($chunks as $seg){
		$chunkIndex++;
		$prompt = $makePrompt($seg);
		$ck='AUTO|'.$modelo.'|'.md5($seg);
		$ans = $cacheGet($ck) ?: $ai->singleAnswer($prompt);
		if(!$cacheGet($ck)){
			if(stripos($ans,'429')===false){
				$rateState[$providerKey] = ['last'=>$nowMs, 'until'=>$nowMs + $minGap];
				@file_put_contents($rateFile, json_encode($rateState));
			}
		}
		if($ans && ($ans[0]=='[' || $ans[0]=='{')) $cachePut($ck,$ans);
		$parsed=json_decode($ans,true);
		if(is_array($parsed)){
			if(isset($parsed['pregunta']) || array_is_list($parsed)==false){ $parsed = [$parsed]; }
			foreach($parsed as $it){
				if(!is_array($it)) continue;
				$tipo = $it['tipo'] ?? (isset($it['opciones'])?'opc':'sola');
				$preg = trim((string)($it['pregunta'] ?? ''));
				if($preg==='') continue;
				$opciones = [];
				if($tipo==='opc'){
					$ops = $it['opciones'] ?? [];
					if(is_array($ops)){
						// Normalizar A) texto
						$letters=['A','B','C','D','E','F','G','H']; $ix=0; $norm=[];
						foreach($ops as $o){
							$t=trim((string)$o);
							$t=preg_replace('/^\s*Opciones\s*:\s*/i','',$t);
							if(!preg_match('/^[A-H][)\.-]/i',$t)) $t = ($letters[$ix]??('A')) . ') ' . $t;
							$norm[]=$t; $ix++;
						}
						$opciones=$norm;
					}
				}
				$correcta = isset($it['correcta']) ? strtoupper(trim((string)$it['correcta'])) : null;
				$respuesta = isset($it['respuesta']) ? trim((string)$it['respuesta']) : null;
				$allItems[] = [ 'tipo'=>$tipo, 'pregunta'=>$preg, 'opciones'=>$opciones, 'correcta'=>$correcta, 'respuesta'=>$respuesta ];
			}
		}
		// Límite de tiempo por job tick
		if(microtime(true)-$inicio>10) break;
	}
	// Convertir allItems a docBlocks con el mismo formato final
	$docBlocks=[]; $contenidoFinal="Formato detectado: AUTO\n\n"; $i=1;
	foreach($allItems as $it){
		$q = $it['pregunta']; $ops=$it['opciones'] ?? []; $corr=$it['correcta'] ?? null; $resp=$it['respuesta'] ?? null;
		$block = "Pregunta $i:\n$q";
		if(!empty($ops)){
			$block .= "\nOpciones:\n".implode("\n", $ops);
		}
		$label = !empty($ops) ? 'Respuesta correcta' : 'Respuesta';
		$ansText = '';
		if(!empty($ops)){
			$prefix = $corr ? ($corr.') ') : '';
			$letters=['A','B','C','D','E','F','G','H'];
			$idx = ($corr!==null) ? array_search($corr,$letters,true) : false;
			$optTxt = '';
			if($idx!==false && isset($ops[$idx])){
				$optTxt = preg_replace('/^\(?[A-H]\)?[)\.\-]?\s*/i','', $ops[$idx]);
			}
			$ansText = $prefix.$optTxt;
		} else {
			$ansText = $resp ?? '';
		}
		$block .= "\n$label:\n".trim((string)$ansText);
		$docBlocks[]=$block; $contenidoFinal.=$block."\n\n"; $i++;
	}
	// Exportar archivos de salida
	$baseName = $job['id'].'-procesado';
	$txtOut = TEMP_PATH.'/'.$baseName.'.txt'; file_put_contents($txtOut,$contenidoFinal);
	$docxOut = TEMP_PATH.'/'.$baseName.'.docx'; $pdfOut = TEMP_PATH.'/'.$baseName.'.pdf'; $htmlOut = TEMP_PATH.'/'.$baseName.'.html';
	$docxOk = export_docx($docBlocks,$docxOut);
	$pdfHtml=''; $htmlPrintable='';
	foreach($docBlocks as $b){
		$lines = explode("\n", $b);
		$title = htmlentities(trim($lines[0] ?? 'Pregunta'));
		$qText=''; $optsText=''; $ansText=''; $modeSec='q';
		for($ii=1;$ii<count($lines);$ii++){
			$ln=$lines[$ii];
			if(preg_match('/^Opciones\s*:/i',$ln)){ $modeSec='opts'; continue; }
			if(preg_match('/^Respuesta(\s+correcta)?\s*:/i',$ln)){ $modeSec='ans'; continue; }
			if($modeSec==='q'){ $qText .= ($qText?"\n":"").$ln; }
			elseif($modeSec==='opts'){ $optsText .= ($optsText?"\n":"").$ln; }
			else { $ansText .= ($ansText?"\n":"").$ln; }
		}
		$ansStrong = preg_match('/^Respuesta\s+correcta/i', trim($lines[array_search('ans',['q','opts','ans'])] ?? '')) ? 'Respuesta correcta:' : (strpos($b,'Opciones:')!==false ? 'Respuesta correcta:' : 'Respuesta:');
		$pdfHtml .= '<p><strong>'.$title.'</strong><br>'.nl2br(htmlentities($qText)).'<br>'
			. ($optsText!==''? '<strong>Opciones:</strong><br>'.nl2br(htmlentities($optsText)).'<br>' : '')
			. '<strong>'.($optsText!==''?'Respuesta correcta:':'Respuesta:').'</strong> '.nl2br(htmlentities($ansText)).'</p>';
		$htmlPrintable .= '<div class="item">'
			. '<div class="q"><strong>'.$title.'</strong><br>'.nl2br(htmlentities($qText)).'</div>'
			. ($optsText!==''? '<div class="opts"><strong>Opciones:</strong><br>'.nl2br(htmlentities($optsText)).'</div>' : '')
			. '<div class="ans"><strong>'.($optsText!==''?'Respuesta correcta:':'Respuesta:').'</strong> '.nl2br(htmlentities($ansText)).'</div>'
			. '</div>';
	}
	$pdfOk=false; if(env('PDF_API_URL')){ $pdfOk = export_pdf_external($pdfHtml,$pdfOut); }
	$htmlOk = export_html_printable($htmlPrintable,$htmlOut);
	$files=[basename($txtOut)]; if($docxOk && is_file($docxOut)) $files[]=basename($docxOut); if($pdfOk && is_file($pdfOut)) $files[]=basename($pdfOut); if($htmlOk && is_file($htmlOut)) $files[]=basename($htmlOut);
	$job['files']=$files; $job['status']='done';
	// Persistir y devolver inmediatamente para evitar seguir con el pipeline clásico
	file_put_contents($jobFile,json_encode($job,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
	echo json_encode([
		'status'=>$job['status'],
		'processed'=>$job['processed'],
		'total'=>$job['total'],
		'files'=>$job['files'] ?? [],
		'source'=>$job['source']
	]);
	exit;
}
// ========== FIN MODO AUTO ==========
// Modo ultra: una sola llamada con todas las preguntas restantes
if($ultra && $job['processed'] < $job['total']){
	$pendientes = array_map($normalizeBlock, array_slice($job['bloques'],$job['processed']));
	$formato=$job['formato']; $modelo=$pickModel($job['modelo'],$formato); $ai=new AIClient($modelo);
	$expected = count($pendientes);
	$plantilla = match($formato){
	'PREG_SOLAS' => "Devuelve SOLO JSON array. Cada elemento: {pregunta:string, respuesta:string}.",
		'PREG_OPC' => ($mode==='minimal'?
			"Devuelve SOLO JSON array. Cada elemento: {pregunta:string, opciones:[...], correcta:letra, correcta_texto:string}. 'correcta_texto' debe ser el texto exacto de la opción correcta sin la letra. Sin explicación." :
			"Devuelve SOLO JSON array. Cada elemento: {pregunta:string, opciones:[...], correcta:letra, explicacion:string}."),
		'PREG_OPC_RESP' => ($mode==='minimal'?
			"Devuelve SOLO JSON array. Cada elemento: {pregunta:string, opciones:[...], correcta:letra, correcta_texto:string}. 'correcta_texto' debe ser el texto exacto de la opción correcta sin la letra. Sin justificación." :
			"Devuelve SOLO JSON array. Cada elemento: {pregunta:string, opciones:[...], correcta:letra, justificacion:string}."),
		default => 'Devuelve SOLO JSON array de objetos {pregunta, respuesta}'
	};
	$prompt=$plantilla." Devuelve EXACTAMENTE $expected elementos, en el mismo orden, y no incluyas nada fuera del JSON.\nPreguntas:\n"; $n=1; foreach($pendientes as $p){ $prompt.=$n++.". ".trim($p)."\n"; }
	$ck='ULTRA|'.$modelo.'|'.$mode.'|'.md5($prompt);
	$ans=$cacheGet($ck) ?: $ai->singleAnswer($prompt);
	if(!$cacheGet($ck)){
		// No sobreescribir backoff si hubo 429
		if(stripos($ans,'429')===false){
			$rateState[$providerKey] = ['last'=>$nowMs, 'until'=>$nowMs + $minGap];
			@file_put_contents($rateFile, json_encode($rateState));
		}
	}
	if($ans && $ans[0]=='{') $cachePut($ck,$ans);
	$parsed=json_decode($ans,true);
	if(is_array($parsed) && array_is_list($parsed) && count($parsed)==count($pendientes)){
		foreach($parsed as $i=>$item){
			$job['respuestas'][]=['entrada'=>$pendientes[$i],'resultado'=>json_encode($item,JSON_UNESCAPED_UNICODE)];
			$job['processed']++;
		}
	} else {
		// si falla se desactiva ultra y continúa con fast/normal
		$ultra=false;
	}
}

if(!$ultra && $fast){
	$formato = $job['formato'];
	$modelo = $pickModel($job['modelo'],$formato);
	$ai = new AIClient($modelo);
	// Agrupar adaptativamente en una sola llamada
	$grupo=[]; $mapIdx=[]; $lim = $computeAdaptiveBatch($job);
	for($k=0;$k<$lim;$k++){
		$idx=$job['processed'] + $k; $b=$job['bloques'][$idx]; $grupo[]=$normalizeBlock($b); $mapIdx[]=$idx; }
	if($grupo){
		// Prompt multi ajustado por modo
		$expected = count($grupo);
		$plantilla = match($formato){
			'PREG_SOLAS' => 'Devuelve JSON array. Cada elemento: {pregunta:string, respuesta:string}. Solo JSON.',
			'PREG_OPC' => ($mode==='minimal'?
				"Devuelve JSON array. Cada elemento: {pregunta:string, opciones:[...], correcta: letra, correcta_texto: string}. 'correcta_texto' debe ser el texto exacto de la opción correcta sin la letra. Sin explicación. Solo JSON." :
				"Devuelve JSON array. Cada elemento: {pregunta:string, opciones:[...], correcta: letra, explicacion:string}. Solo JSON."),
			'PREG_OPC_RESP' => ($mode==='minimal'?
				"Devuelve JSON array. Cada elemento: {pregunta:string, opciones:[...], correcta: letra, correcta_texto: string}. 'correcta_texto' debe ser el texto exacto de la opción correcta sin la letra. Sin justificación. Solo JSON." :
				"Devuelve JSON array. Cada elemento: {pregunta:string, opciones:[...], correcta: letra, justificacion:string}. Solo JSON."),
			default => 'Devuelve JSON array de objetos {pregunta, respuesta}'
		};
	$prompt = $plantilla." Devuelve EXACTAMENTE $expected elementos, en el mismo orden, y no incluyas nada fuera del JSON.\nPreguntas:\n";
		$n=1; foreach($grupo as $g){ $prompt .= $n++.'. '.trim($g)."\n"; }
	$ck='FAST|'.$modelo.'|'.$mode.'|'.md5($prompt);
	$ans = $cacheGet($ck) ?: $ai->singleAnswer($prompt);
	if(!$cacheGet($ck)){
		if(stripos($ans,'429')===false){
			$rateState[$providerKey] = ['last'=>$nowMs, 'until'=>$nowMs + $minGap];
			@file_put_contents($rateFile, json_encode($rateState));
		}
	}
	if($ans && $ans[0]=='{') $cachePut($ck,$ans);
	$parsed = json_decode($ans,true);
	if(is_array($parsed) && array_is_list($parsed) && count($parsed)===$expected){
			// Asignar resultados uno a uno
			foreach($parsed as $i=>$item){
				$idxReal = $mapIdx[$i] ?? null; if($idxReal===null) continue;
				$job['respuestas'][]=['entrada'=>$job['bloques'][$idxReal],'resultado'=>json_encode($item,JSON_UNESCAPED_UNICODE)];
				$job['processed']++;
			}
		} else {
			// Fallback: procesar individual normal
			$fast=false; // desactiva para este ciclo
		}
	}
}

if(!$ultra && !$fast && $batch>0 && $job['processed'] < $job['total']){
	$formato = $job['formato'];
	$modelo = $pickModel($job['modelo'],$formato);
	$ai = new AIClient($modelo);
}
while(!$ultra && !$fast && $batch>0 && $job['processed'] < $job['total']){
	$idx = $job['processed'];
	$bloque = $normalizeBlock($job['bloques'][$idx]);
		if (trim($bloque) === '') {
			// Skip empty block and mark as error
			$job['respuestas'][] = ['entrada' => $bloque, 'resultado' => '(Error: bloque vacío)'];
			$job['processed']++;
			$batch--;
			continue;
		}
	$prompt = match($formato){
	'PREG_SOLAS' => "Devuelve JSON con campos: pregunta, respuesta. Responde solo JSON. Pregunta:\n".$bloque,
		'PREG_OPC' => ($mode==='minimal'?
			"Devuelve JSON: pregunta, opciones(array en orden), correcta(letra), correcta_texto(string). 'correcta_texto' debe ser el texto exacto de la opción correcta sin la letra. Sin explicación. Responde solo JSON. Texto:\n".$bloque :
			"Devuelve JSON: pregunta, opciones(array en orden), correcta(letra), explicacion(breve). Responde solo JSON. Texto:\n".$bloque),
		'PREG_OPC_RESP' => ($mode==='minimal'?
			"Valida respuesta. JSON: pregunta, opciones(array), correcta(letra), correcta_texto(string). 'correcta_texto' debe ser el texto exacto de la opción correcta sin la letra. Sin justificación. Responde solo JSON. Texto:\n".$bloque :
			"Valida respuesta. JSON: pregunta, opciones(array), correcta(letra), justificacion. Responde solo JSON. Texto:\n".$bloque),
		default => $bloque
	};
	$ck='ONE|'.$modelo.'|'.$mode.'|'.md5($prompt);
	$ans = $cacheGet($ck) ?: $ai->singleAnswer($prompt);
	if(!$cacheGet($ck)){
		if(stripos($ans,'429')===false){
			$rateState[$providerKey] = ['last'=>$nowMs, 'until'=>$nowMs + $minGap];
			@file_put_contents($rateFile, json_encode($rateState));
		}
	}
	if($ans && $ans[0]!=='[' && $ans[0]!=='<' && strlen($ans)>5) $cachePut($ck,$ans);
	if(str_contains($ans,'[Error') && $retries>0){ $ans = $ai->singleAnswer($prompt." (reintento)"); }
	$job['respuestas'][] = ['entrada'=>$bloque,'resultado'=>$ans];
	$job['processed'] = $idx + 1; $batch--; if(microtime(true)-$inicio>12) break;
}

if($job['processed'] >= $job['total']){
	// Construir salida final
	$contenidoFinal = "Formato detectado: ".$job['formato']."\n\n";
	$i=1;
	$docBlocks=[];
	foreach($job['respuestas'] as $r){
		$preg = trim($r['entrada']);
		// limpiar prefijo PREGUNTA N:
		$preg = preg_replace('/^PREGUNTA\s*\d+:\s*/i','',$preg);
		$respRaw = $r['resultado'];
		$respuestaLimpiada='(Error al obtener respuesta)';
		$opcionesStr='';
		// Utilidades para mapear correcta
		$extractLT = function(string $opt): array {
			$t=trim($opt);
			if(preg_match('/^\(?([A-G])\)?[)\.-]?\s*(.+)$/i',$t,$m)){
				return ['letter'=>strtoupper($m[1]), 'text'=>trim($m[2])];
			}
			return ['letter'=>null,'text'=>$t];
		};
		// Reparar líneas partidas dentro del bloque de opciones (hard-wrap):
		$repairOptions = function(string $optsMultiline) use ($extractLT): string {
			$lines = preg_split('/\r?\n/', $optsMultiline);
			$items = [];
			foreach($lines as $ln){
				$ln = trim($ln);
				if($ln==='') continue;
				if(preg_match('/^\s*(?:\(?([A-Ha-h])\)|([A-Ha-h])[\).]|([A-Ha-h])\s*[-–:])\s*(.+)$/', $ln, $m)){
					$letter = strtoupper($m[1] ?: ($m[2] ?: $m[3]));
					$text = trim($m[4]);
					$items[] = [$letter, $text];
				} else {
					// línea continuación: anexar a último ítem
					if(!empty($items)){
						$items[count($items)-1][1] = trim($items[count($items)-1][1] . ' ' . $ln);
					}
				}
			}
			// Re-emitir normalizado A) texto
			$out = [];
			foreach($items as [$ltr,$txt]){ $out[] = $ltr.') '.$txt; }
			return implode("\n", $out);
		};
		// Deduplicar por letra conservando el texto más largo
		$dedupeOptions = function(string $optsMultiline) use ($extractLT): string {
			$lines = preg_split('/\r?\n/', $optsMultiline);
			$byLetter = [];
			foreach($lines as $ln){
				$ln = trim($ln); if($ln==='') continue;
				$lt = $extractLT($ln);
				if(!$lt['letter']) continue;
				$key = strtoupper($lt['letter']);
				if(!isset($byLetter[$key]) || strlen($lt['text']) > strlen($byLetter[$key]['text'])){
					$byLetter[$key] = ['letter'=>$key,'text'=>$lt['text']];
				}
			}
			// Ordenar por A..H
			$order = array_intersect(['A','B','C','D','E','F','G','H'], array_keys($byLetter));
			$out=[]; foreach($order as $k){ $out[] = $k.') '.$byLetter[$k]['text']; }
			return implode("\n", $out);
		};
		// Construir enunciado solo hasta antes de la primera opción/'Opciones:'/'Respuesta correcta:'
		$buildQuestionText = function(string $entrada) : string {
			$lines = preg_split('/\r?\n/', $entrada);
			$buf = [];
			foreach($lines as $ln){
				if(preg_match('/^\s*(Opciones\s*:|Respuesta\s+correcta\s*:)/i',$ln)) break;
				if(preg_match('/^\s*(?:\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])\s+.+/',$ln)) break;
				$buf[] = rtrim($ln);
			}
			$txt = trim(implode("\n", $buf));
			// Compactar espacios múltiples
			$txt = preg_replace('/(\r?\n)\s*(\r?\n)+/m', "$1", $txt);
			return $txt;
		};
		// Extraer 'Respuesta correcta' multi-línea desde el bloque original normalizado
		$extractOriginalCorrect = function(string $entrada) use ($extractLT): array {
			$lines=preg_split('/\r?\n/', $entrada);
			$n=count($lines);
			for($i=0;$i<$n;$i++){
				if(preg_match('/Respuesta\s+correcta\s*:/i', $lines[$i])){
					// Tomar lo que venga después de ':' en esta línea
					$after = trim(preg_replace('/^.*?Respuesta\s+correcta\s*:\s*/i','',$lines[$i]));
					$buf = $after;
					$tookFirstOptionLike = false;
					for($j=$i+1;$j<$n;$j++){
						$ln = trim($lines[$j]);
						if($ln==='') break;
						if(preg_match('/^(Pregunta\s*\d+\s*:|Opciones\s*:|Respuesta\s+correcta\s*:)/i',$ln)) break;
						// Si la línea parece una opción, solo la tomamos si aún no tomamos una semejante
						if(preg_match('/^(?:\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])\s+.+/',$ln)){
							if($buf!=='' && $tookFirstOptionLike) break;
							$tookFirstOptionLike = true;
						}
						$buf = trim($buf.' '. $ln);
					}
					$buf = preg_replace('/\s+/',' ', trim($buf));
					if($buf!==''){
						$lt = $extractLT($buf);
						$letter = $lt['letter'];
						$text = $lt['text'];
						return ['letter'=>$letter, 'text'=>$text];
					}
					// si no hay texto, intentar detectar solo letra
					if(preg_match('/\(?([A-Ha-h])\)?[)\.-]?/',$after,$m)){ return ['letter'=>strtoupper($m[1]), 'text'=>null]; }
					break;
				}
			}
			return ['letter'=>null,'text'=>null];
		};
		$findCorrect = function(array $ops, $correctVal) use ($extractLT): array {
			$n=count($ops); $letters=['A','B','C','D','E','F','G','H'];
			$norm=array_map($extractLT,$ops);
			// 1) correcta como letra
			if(is_string($correctVal) && preg_match('/^[A-H]$/i',trim($correctVal))){
				$ltr=strtoupper(trim($correctVal));
				// buscar índice por letra embebida
				foreach($norm as $i=>$it){ if($it['letter'] && $it['letter']===$ltr){ return ['index'=>$i,'letter'=>$ltr,'text'=>$it['text']]; } }
				// si ninguna opción trae letra, usar posición por letra
				$idx=array_search($ltr,$letters,true); if($idx!==false && $idx<$n){ return ['index'=>$idx,'letter'=>$ltr,'text'=>$norm[$idx]['text']]; }
			}
			// 2) correcta como número (1-based o 0-based raramente)
			if(is_numeric($correctVal)){
				$num=(int)$correctVal; if($num>=1 && $num<=$n){ $idx=$num-1; $ltr=$letters[$idx]??null; return ['index'=>$idx,'letter'=>$ltr,'text'=>$norm[$idx]['text']]; }
				if($num>=0 && $num<$n){ $idx=$num; $ltr=$letters[$idx]??null; return ['index'=>$idx,'letter'=>$ltr,'text'=>$norm[$idx]['text']]; }
			}
			// 3) correcta como texto (igual o contiene)
			if(is_string($correctVal)){
				$cv=trim($correctVal);
				foreach($norm as $i=>$it){ if(mb_strtolower($it['text'])===mb_strtolower($cv)){ $ltr=$it['letter'] ?: ($letters[$i]??null); return ['index'=>$i,'letter'=>$ltr,'text'=>$it['text']]; } }
				foreach($norm as $i=>$it){ if(mb_stripos($it['text'],$cv)!==false){ $ltr=$it['letter'] ?: ($letters[$i]??null); return ['index'=>$i,'letter'=>$ltr,'text'=>$it['text']]; } }
			}
			return ['index'=>null,'letter'=>null,'text'=>null];
		};
	if(!str_contains($respRaw,'[Error')){
			$json = json_decode($respRaw,true);
			if(is_array($json)){
				$pq = $json['pregunta'] ?? $json['question'] ?? $preg;
				$pq = preg_replace('/^PREGUNTA\s*\d+:\s*/i','',$pq);
				$ans = $json['respuesta'] ?? $json['answer'] ?? ($json['correcta'] ?? '');
				$opciones = $json['opciones'] ?? $json['options'] ?? null;
				// Limpieza: evitar que la respuesta contenga accidentalmente la clave pregunta
				if(is_string($ans)){
					$ans = preg_replace('/^\s*"?pregunta"?\s*:\s*".*?"\s*,?\s*/is','', $ans);
					$ans = preg_replace('/^\s*\{\s*"?pregunta"?.*$/is','', $ans);
					$ans = trim($ans, "\"' \t\r\n");
				}
				// Preferir extraer opciones del texto original si hay al menos 2
				$optsSource = Parser::extractOptions($r['entrada']);
				if(is_array($optsSource) && count($optsSource) >= 2){ $opciones = $optsSource; }
				if(is_array($opciones)){
					// Normalizar opciones: si no tienen letra, asignar
					$norm=[]; $letters=['A','B','C','D','E','F','G','H']; $ix=0;
					foreach($opciones as $opt){
						$optTrim=trim($opt);
						// quitar 'Opciones:' si vino incrustado (al inicio o antes del marcador)
						$optTrim=preg_replace('/^\s*Opciones\s*:\s*/i','',$optTrim);
						$optTrim=preg_replace('/\bOpciones\s*:\s*(?=\(?[A-Ha-h]\)|[A-Ha-h][\).])/i','',$optTrim);
						if(!preg_match('/^[A-G][)\.-]/i',$optTrim)){
							$optTrim=$letters[$ix] . ') ' . $optTrim;
						}
						$norm[]=$optTrim; $ix++;
					}
					$opcionesStr = implode("\n", $norm);
					// Eliminar 'Opciones:' suelto o pegado al inicio de línea dentro del bloque
					$opcionesStr = preg_replace('/^\s*Opciones\s*:\s*$/im','', $opcionesStr);
					$opcionesStr = preg_replace('/^\s*Opciones\s*:\s*/im','', $opcionesStr);
					// Reparar líneas partidas
					$opcionesStr = $repairOptions($opcionesStr);
					// Deduplicar por letra conservando el texto más largo
					$opcionesStr = $dedupeOptions($opcionesStr);
				}
				// En modo minimal, preferir el TEXTO de la opción correcta
				if($mode==='minimal' && ($job['formato']==='PREG_OPC' || $job['formato']==='PREG_OPC_RESP')){
					// Preferir la Respuesta correcta del texto original si existe (multi-línea)
					$orig = $extractOriginalCorrect($r['entrada']);
					$origLetter = $orig['letter'] ?: Parser::detectCorrectLetter($r['entrada']);
					$origText = $orig['text'];
					$correctLetter = $origLetter ?: (is_string($json['correcta'] ?? null) ? strtoupper(trim($json['correcta'])) : null);
					$correctText = $origText ?: (is_string($json['correcta_texto'] ?? null) ? trim($json['correcta_texto']) : null);
					if(is_array($opciones)){
						$found = $findCorrect($opciones, $json['correcta'] ?? $origLetter);
						// Si original está vacío/truncado (acaba en coma) o es más corto, usa el de opciones
						if($found['text']){
							if(!$correctText || preg_match('/[,;]$/',$correctText) || (mb_strlen($found['text'])>mb_strlen($correctText))){
								$correctText=$found['text']; $correctLetter=$found['letter'] ?: $correctLetter;
							}
						}
					}
					// Fallback: intenta extraer del bloque original "Respuesta correcta: X) ..."
					if(!$correctText){ $orig2 = $extractOriginalCorrect($r['entrada']); if($orig2['text']) $correctText = $orig2['text']; }
						$respuestaLimpiada = $correctText ?: ($correctLetter ?: '(Error al obtener respuesta)');
				} else {
					if(is_array($ans)) $ans = implode('; ',$ans);
					// En completo, si hay letra y opciones, mostrar "B) Texto"
					if($mode==='full' && ($job['formato']==='PREG_OPC' || $job['formato']==='PREG_OPC_RESP')){
						// Preferir la Respuesta correcta del texto original si existe (multi-línea)
						$orig = $extractOriginalCorrect($r['entrada']);
						$origLetter = $orig['letter'] ?: Parser::detectCorrectLetter($r['entrada']);
						$origText = $orig['text'];
						$correctVal = $origLetter ?: ($json['correcta'] ?? null);
						$correctLetter = is_string($correctVal) && preg_match('/^[A-H]$/i',trim($correctVal)) ? strtoupper(trim($correctVal)) : ($origLetter ?: null);
						$correctText = null; $letterOut=$correctLetter;
						if(is_array($opciones)){
							$found = $findCorrect($opciones,$correctVal);
							// Elegir mejor entre original y opciones
							if($found['text'] && (!$origText || preg_match('/[,;]$/',$origText) || (mb_strlen($found['text'])>mb_strlen($origText)))){
								$correctText=$found['text']; $letterOut=$found['letter'] ?: $letterOut;
							} else if($origText){
								$correctText=$origText;
							}
						}
						if($letterOut && !$correctText && is_array($opciones)){
							// último intento: usar índice por letra incluso sin letras en opciones
							$letters=['A','B','C','D','E','F','G','H']; $idx=array_search($letterOut,$letters,true);
							if($idx!==false && isset($opciones[$idx])){ $tmp=$extractLT($opciones[$idx]); $correctText=$tmp['text']; }
						}
						if(!$correctText){ $orig2 = $extractOriginalCorrect($r['entrada']); if($orig2['text']){ $correctText=$orig2['text']; $letterOut = $letterOut ?: $orig2['letter']; } }
						if($correctText){ $respuestaLimpiada = ($letterOut?$letterOut.') ':'').$correctText; }
						else { $respuestaLimpiada = trim($ans) !== '' ? $ans : '(Sin respuesta)'; }
					} else {
						$respuestaLimpiada = trim($ans) !== '' ? $ans : '(Sin respuesta)';
					}
				}
				// Enunciado: tomar solo hasta antes de la primera opción/'Opciones:'/'Respuesta correcta:'
				$preg = $buildQuestionText($pq ?: $r['entrada']);
			} else {
				// Fallback: JSON inválido. Derivar desde el texto original en formatos con opciones para ambos modos
				if($job['formato']==='PREG_OPC' || $job['formato']==='PREG_OPC_RESP'){
					$opts = Parser::extractOptions($r['entrada']);
					if(is_array($opts)){
						// Normalizar y listar opciones SIEMPRE (independiente del modo)
						$norm=[]; $letters=['A','B','C','D','E','F','G','H']; $ix=0;
						foreach($opts as $opt){
							$optTrim=preg_replace('/^\s*Opciones\s*:\s*/i','',trim($opt));
							$optTrim=preg_replace('/\bOpciones\s*:\s*(?=\(?[A-Ha-h]\)|[A-Ha-h][\).])/i','', $optTrim);
							if(!preg_match('/^[A-H][)\.\-]/i',$optTrim)) $optTrim = ($letters[$ix]??chr(65+$ix)).') '.$optTrim;
							$norm[]=$optTrim; $ix++;
						}
						$opcionesStr = implode("\n", $norm);
						$opcionesStr = preg_replace('/^\s*Opciones\s*:\s*$/im','', $opcionesStr);
						$opcionesStr = preg_replace('/^\s*Opciones\s*:\s*/im','', $opcionesStr);
						// Reparar líneas partidas
						$opcionesStr = $repairOptions($opcionesStr);
						// Deduplicar por letra conservando el texto más largo
						$opcionesStr = $dedupeOptions($opcionesStr);
						// Detectar letra/texto correctos
						$letter = Parser::detectCorrectLetter($r['entrada']);
						$correctText = null;
						foreach($opts as $opt){ if(preg_match('/^\s*\(?([A-H])\)?[)\.-]?\s*(.+)$/i', trim($opt), $mm)){ if(strtoupper($mm[1])===$letter){ $correctText = trim($mm[2]); break; } } }
						if(!$correctText){ $orig = $extractOriginalCorrect($r['entrada']); if($orig['letter']) $letter=$orig['letter']; if($orig['text']) $correctText = $orig['text']; }
						if($mode==='minimal'){
							$respuestaLimpiada = $correctText ?: ($letter ?: '(Error al obtener respuesta)');
						} else {
							if($letter && $correctText){ $respuestaLimpiada = $letter.") ".$correctText; }
							else if($correctText){ $respuestaLimpiada = $correctText; }
							else if($letter){ $respuestaLimpiada = $letter; }
							else { $respuestaLimpiada = '(Error al obtener respuesta)'; }
						}
					} else {
						$respuestaLimpiada = '(Error al obtener respuesta)';
					}
					// Enunciado: tomar solo hasta antes de la primera opción/'Opciones:'/'Respuesta correcta:'
					$preg = $buildQuestionText($r['entrada']);
				} else {
					// Formato sin opciones (PREG_SOLAS): intentar extraer "respuesta" del texto aunque el JSON esté sucio
					$extractAnswerFromRaw = function(string $raw): string {
						$raw = trim($raw);
						if($raw==='') return '';
						// 1) Intentar decodificar si viene como objeto o array de 1
						$try = json_decode($raw,true);
						if(is_array($try)){
							if(isset($try['respuesta']) && is_string($try['respuesta'])) return trim($try['respuesta']);
							if(array_is_list($try) && isset($try[0]['respuesta'])) return trim((string)$try[0]['respuesta']);
						}
						// 2) Buscar campo respuesta con regex tolerante a saltos de línea
						if(preg_match('/"respuesta"\s*:\s*"([\s\S]*?)"/u', $raw, $m)){
							$val = $m[1];
							// Desescapar comillas y caracteres comunes
							$val = stripcslashes($val);
							return trim($val);
						}
						// 2b) Si aparecen claves dentro de texto, eliminar línea que comienza con pregunta:
						$raw = preg_replace('/^\s*\"?pregunta\"?\s*:.*/mi','', $raw);
						// 3) Si trae llaves/campos, quitar líneas de clave:valor y quedarse con el resto
						$lines = preg_split('/\r?\n/', $raw);
						$buf=[];
						foreach($lines as $ln){
							$t=trim($ln);
							if($t==='' || $t==='{ ' || $t==='{' || $t==='}' ) continue;
							if(preg_match('/^\s*"?(pregunta|respuesta|opciones|correcta)"?\s*:/i',$t)) continue;
							$buf[]=$t;
						}
						if(!empty($buf)) return trim(implode(' ', $buf));
						// 4) Último recurso: primera línea no vacía sin llaves
						foreach($lines as $ln){ $t=trim($ln); if($t!=='' && !str_contains($t,'{') && !str_contains($t,'}')) return $t; }
						return $raw;
					};
					$respuestaLimpiada = $extractAnswerFromRaw($respRaw);
					// Micro-fallback por pregunta: si quedó vacía, pedir a la IA solo esta pregunta
					if($respuestaLimpiada==='' && env('AI_AUTO_DETECT','1')==='1'){
						$modeloMF = $pickModel($job['modelo'],'PREG_SOLAS');
						$aiMF = new AIClient($modeloMF);
						$promptMF = "Del siguiente TEXTO extrae una sola pregunta (si existe) y responde. Devuelve SOLO JSON {pregunta, respuesta}. TEXTO:\n".$bloque;
						$ansMF = $aiMF->singleAnswer($promptMF);
						$jsonMF = json_decode($ansMF,true);
						if(is_array($jsonMF)){
							$respuestaLimpiada = trim((string)($jsonMF['respuesta'] ?? ''));
						}
					}
				}
			}
		}
		// Fallback final: si seguimos en error y el formato es de opciones, derivar desde el texto original
		if($respuestaLimpiada==='(Error al obtener respuesta)' && ($job['formato']==='PREG_OPC' || $job['formato']==='PREG_OPC_RESP')){
			$letter = Parser::detectCorrectLetter($r['entrada']);
			$opts = Parser::extractOptions($r['entrada']);
			$correctText = null;
			if($letter && $opts){
				foreach($opts as $opt){ if(preg_match('/^\(?([A-G])\)?[)\.-]?\s*(.+)$/i', $opt, $mm)){ if(strtoupper($mm[1])===$letter){ $correctText = trim($mm[2]); break; } } }
			}
			if(!$correctText){ $orig = $extractOriginalCorrect($r['entrada']); if($orig['letter']) $letter=$orig['letter']; if($orig['text']) $correctText = $orig['text']; }
			if($mode==='minimal'){
				$respuestaLimpiada = $correctText ?: ($letter ?: '(Error al obtener respuesta)');
			} else {
				if($letter && $correctText){ $respuestaLimpiada = $letter.") ".$correctText; }
				else if($correctText){ $respuestaLimpiada = $correctText; }
				else if($letter){ $respuestaLimpiada = $letter; }
			}
			// Micro-fallback IA por pregunta (último recurso): si aún queda error, pedir JSON solo para esta pregunta
			if($respuestaLimpiada==='(Error al obtener respuesta)' && env('AI_AUTO_DETECT','1')==='1'){
				try{
					$modeloFix = $pickModel($job['modelo'],$job['formato']);
					$aiFix = new AIClient($modeloFix);
					$promptFix = ($mode==='minimal'?
						"Del siguiente TEXTO devuelve SOLO JSON {pregunta, opciones:[], correcta, correcta_texto}. 'correcta_texto' debe ser el texto exacto de la opción correcta sin la letra. TEXTO:\n".$bloque :
						"Del siguiente TEXTO devuelve SOLO JSON {pregunta, opciones:[], correcta, explicacion}. TEXTO:\n".$bloque
					);
					$ckFix='ONE_FIX|'.$modeloFix.'|'.$mode.'|'.md5($promptFix);
					$ansFix = $cacheGet($ckFix) ?: $aiFix->singleAnswer($promptFix);
					if(!$cacheGet($ckFix)){
						if(stripos($ansFix,'429')===false){
							$rateState[$providerKey] = ['last'=>$nowMs, 'until'=>$nowMs + $minGap];
							@file_put_contents($rateFile, json_encode($rateState));
						}
					}
					if($ansFix && $ansFix[0]!=='<' && strlen($ansFix)>5) $cachePut($ckFix,$ansFix);
					$jFix = json_decode($ansFix,true);
					if(is_array($jFix)){
						$corr = $jFix['correcta'] ?? null;
						$corrTxt = $jFix['correcta_texto'] ?? null;
						if($mode==='minimal'){
							$respuestaLimpiada = trim((string)($corrTxt ?: $corr ?: $respuestaLimpiada));
						} else {
							if($corrTxt && is_string($corr)){ $respuestaLimpiada = strtoupper(trim((string)$corr)).") ".trim((string)$corrTxt); }
							elseif(is_string($corrTxt)){ $respuestaLimpiada = trim((string)$corrTxt); }
							elseif(is_string($corr)){ $respuestaLimpiada = strtoupper(trim((string)$corr)); }
						}
					}
				}catch(\Throwable $e){ /* ignorar, mantener error */ }
			}
		}
	// Reintento final post-proceso por pregunta si la respuesta quedó vacía o genérica
	$needsRetry = false;
	if(is_string($respuestaLimpiada)){
		$rl = trim($respuestaLimpiada);
		if($rl==='' || $rl==='(Sin respuesta)' || $rl==='(Error al obtener respuesta)') $needsRetry = true;
	} else { $needsRetry = true; }
	if($needsRetry && env('AI_AUTO_DETECT','1')==='1'){
		try{
			if($job['formato']==='PREG_SOLAS'){
				$modeloMF = $pickModel($job['modelo'],'PREG_SOLAS');
				$aiMF = new AIClient($modeloMF);
				$promptMF = "Del siguiente TEXTO extrae una sola pregunta (si existe) y responde. Devuelve SOLO JSON {pregunta, respuesta}. TEXTO:\n".$preg."\n\n".$r['entrada'];
				$ansMF = $aiMF->singleAnswer($promptMF);
				$jsonMF = json_decode($ansMF,true);
				if(is_array($jsonMF)){
					$cand = trim((string)($jsonMF['respuesta'] ?? ''));
					if($cand!=='') $respuestaLimpiada = $cand;
				}
			} else if($job['formato']==='PREG_OPC' || $job['formato']==='PREG_OPC_RESP'){
				$modeloFix = $pickModel($job['modelo'],$job['formato']);
				$aiFix = new AIClient($modeloFix);
				$promptFix = ($mode==='minimal'?
					"Del siguiente TEXTO devuelve SOLO JSON {pregunta, opciones:[], correcta, correcta_texto}. 'correcta_texto' debe ser el texto exacto de la opción correcta sin la letra. TEXTO:\n".$r['entrada'] :
					"Del siguiente TEXTO devuelve SOLO JSON {pregunta, opciones:[], correcta, explicacion}. TEXTO:\n".$r['entrada']
				);
				$ansFix = $aiFix->singleAnswer($promptFix);
				$jFix = json_decode($ansFix,true);
				if(is_array($jFix)){
					if($mode==='minimal'){
						$corr = $jFix['correcta_texto'] ?? ($jFix['correcta'] ?? '');
						if(is_string($corr) && trim($corr)!=='') $respuestaLimpiada = trim($corr);
					} else {
						$corr = isset($jFix['correcta']) ? strtoupper(trim((string)$jFix['correcta'])) : null;
						$ctx = trim((string)($jFix['correcta_texto'] ?? ''));
						if($corr && $ctx!=='') $respuestaLimpiada = $corr.') '.$ctx;
						elseif($ctx!=='') $respuestaLimpiada = $ctx;
						elseif(is_string($corr) && $corr!=='') $respuestaLimpiada = $corr;
					}
				}
			}
		}catch(\Throwable $e){ /* ignorar */ }
	}

	// Si el documento global es PREG_SOLAS pero este bloque contiene opciones, ascender a opciones
	if($job['formato']==='PREG_SOLAS' && trim((string)($opcionesStr ?? ''))===''){
		// Intentar extraer opciones desde el bloque normalizado
		$normBlock = $normalizeBlock($r['entrada']);
		$lines = preg_split('/\r?\n/', $normBlock);
		$optsFound = [];
		foreach($lines as $ln){
			$ln = trim($ln);
			if($ln==='') continue;
			if(preg_match('/^\s*(?:\(?([A-Ha-h])\)|([A-Ha-h])[\).]|([A-Ha-h])\s*[-–:])\s+(.+)$/', $ln, $m)){
				$letter = strtoupper($m[1] ?: ($m[2] ?: $m[3]));
				$text = trim($m[4]);
				$optsFound[] = $letter.') '.$text;
			}
		}
		if(count($optsFound) >= 2){
			// Normalizar, reparar y deduplicar
			$opcionesStr = implode("\n", $optsFound);
			$opcionesStr = preg_replace('/^\s*Opciones\s*:\s*$/im','', $opcionesStr);
			$opcionesStr = preg_replace('/^\s*Opciones\s*:\s*/im','', $opcionesStr);
			$opcionesStr = $repairOptions($opcionesStr);
			$opcionesStr = $dedupeOptions($opcionesStr);
			// Derivar correcta por letra/texto original si existe
			$orig = $extractOriginalCorrect($r['entrada']);
			$letterOut = $orig['letter'] ?: Parser::detectCorrectLetter($r['entrada']);
			$textOut = $orig['text'];
			if(!$textOut && $letterOut){
				// Buscar texto por letra en opcionesStr
				foreach(preg_split('/\r?\n/', $opcionesStr) as $ol){
					if(preg_match('/^\(?([A-H])\)?[)\.-]?\s*(.+)$/i', trim($ol), $mm)){
						if(strtoupper($mm[1])===$letterOut){ $textOut = trim($mm[2]); break; }
					}
				}
			}
			if($mode==='minimal'){
				// Minimal debe mostrar solo el texto correcto
				if($textOut){ $respuestaLimpiada = $textOut; }
				elseif($letterOut){
					// Micro-fix: elegir letra y mapear a texto
					try{
						$opsList = [];
						foreach(preg_split('/\r?\n/', $opcionesStr) as $ol){ if(trim($ol)!=='') $opsList[] = trim($ol); }
						$modeloPick = $pickModel($job['modelo'],'PREG_OPC');
						$aiPick = new AIClient($modeloPick);
						$promptPick = "Elige la opción correcta para la PREGUNTA. Devuelve SOLO una letra (A-H).\nPREGUNTA:\n".$preg."\nOPCIONES:\n".implode("\n", $opsList);
						$ansPick = $aiPick->singleAnswer($promptPick);
						if(preg_match('/([A-H])/i', trim($ansPick), $mx)){
							$L = strtoupper($mx[1]);
							foreach($opsList as $ol){ if(preg_match('/^\(?([A-H])\)?[)\.-]?\s*(.+)$/i', $ol, $mm) && strtoupper($mm[1])===$L){ $respuestaLimpiada = trim($mm[2]); break; } }
						}
					}catch(\Throwable $e){ /* ignore */ }
				}
			} else {
				// Completo: "L) Texto"
				if($textOut && $letterOut){ $respuestaLimpiada = $letterOut.") ".$textOut; }
				elseif($textOut){ $respuestaLimpiada = $textOut; }
				elseif($letterOut){
					// Micro-fix: elegir letra y recomponer
					try{
						$opsList = [];
						foreach(preg_split('/\r?\n/', $opcionesStr) as $ol){ if(trim($ol)!=='') $opsList[] = trim($ol); }
						$modeloPick = $pickModel($job['modelo'],'PREG_OPC');
						$aiPick = new AIClient($modeloPick);
						$promptPick = "Elige la opción correcta para la PREGUNTA. Devuelve SOLO una letra (A-H).\nPREGUNTA:\n".$preg."\nOPCIONES:\n".implode("\n", $opsList);
						$ansPick = $aiPick->singleAnswer($promptPick);
						if(preg_match('/([A-H])/i', trim($ansPick), $mx)){
							$L = strtoupper($mx[1]);
							foreach($opsList as $ol){ if(preg_match('/^\(?([A-H])\)?[)\.-]?\s*(.+)$/i', $ol, $mm) && strtoupper($mm[1])===$L){ $respuestaLimpiada = $L.") ".trim($mm[2]); break; } }
						}
					}catch(\Throwable $e){ /* ignore */ }
				}
			}
		}
	}

	// Verificación y corrección posterior
	if(($job['formato']==='PREG_OPC' || $job['formato']==='PREG_OPC_RESP') && isset($opcionesStr) && trim($opcionesStr)!==''){
		$optsLines = preg_split('/\r?\n/', trim($opcionesStr));
		$optsTexts = [];
		foreach($optsLines as $ol){
			$ol=trim($ol); if($ol==='') continue;
			if(preg_match('/^\(?([A-H])\)?[)\.-]?\s*(.+)$/i',$ol,$m)){ $optsTexts[$m[1]] = trim($m[2]); }
		}
		$allTexts = array_map('mb_strtolower', array_values($optsTexts));
		$isMinimal = ($mode==='minimal');
		$needFix=false; $letterChosen=null; $textChosen=null;
		if($isMinimal){
			$ansTxt = mb_strtolower(trim((string)$respuestaLimpiada));
			// Si la respuesta es solo una letra válida, tomamos el texto de la opción
			if(preg_match('/^[A-H]$/i', $ansTxt)){
				$letterChosen = strtoupper($ansTxt);
				$textChosen = $optsTexts[$letterChosen] ?? null;
				if($textChosen){ $respuestaLimpiada = $textChosen; }
				else { $needFix=true; }
			} else {
				// Verificar que el texto pertenezca a alguna opción
				$found=false; foreach($allTexts as $t){ if(mb_strpos($t, $ansTxt)!==false && $ansTxt!=='') { $found=true; break; } }
				if(!$found) $needFix=true;
			}
		} else {
			// Completo: debería ser "L) Texto"
			if(preg_match('/^\s*([A-H])\)\s+(.+)$/i', (string)$respuestaLimpiada, $mm)){
				$L = strtoupper($mm[1]); $T = trim($mm[2]);
				$target = $optsTexts[$L] ?? null;
				if(!$target || mb_strtolower($target)!==mb_strtolower($T)){
					$needFix=true;
				}
			} else { $needFix=true; }
		}
		if($needFix){
			try{
				$modeloPick = $pickModel($job['modelo'],$job['formato']);
				$aiPick = new AIClient($modeloPick);
				// Construir prompt de elección de letra
				$opsList = [];
				foreach($optsTexts as $k=>$v){ $opsList[] = $k.") ".$v; }
				$promptPick = "Elige la opción correcta para la PREGUNTA. Devuelve SOLO una letra (A-H).\nPREGUNTA:\n".$preg."\nOPCIONES:\n".implode("\n", $opsList);
				$ckPick='CHOOSE|'.$modeloPick.'|'.md5($promptPick);
				$ansPick = $cacheGet($ckPick) ?: $aiPick->singleAnswer($promptPick);
				if(!$cacheGet($ckPick)){
					if(stripos($ansPick,'429')===false){
						$rateState[$providerKey] = ['last'=>$nowMs, 'until'=>$nowMs + $minGap];
						@file_put_contents($rateFile, json_encode($rateState));
					}
				}
				if($ansPick){ $cachePut($ckPick,$ansPick); }
				if(preg_match('/([A-H])/i', trim($ansPick), $mx)){
					$L = strtoupper($mx[1]); $T = $optsTexts[$L] ?? null;
					if($T){
						$respuestaLimpiada = $isMinimal ? $T : ($L.") ".$T);
					}
				}
			}catch(\Throwable $e){ /* ignorar */ }
		}
	} else if($job['formato']==='PREG_SOLAS'){
		// Heurística: evitar respuestas genéricas (p.ej., PYTHON) o una sola palabra fuera de contexto
		$ansTrim = trim((string)$respuestaLimpiada);
		$isSingleWord = (bool)preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{2,20}$/u',$ansTrim);
		$inQuestion = mb_stripos(mb_strtolower($preg), mb_strtolower($ansTrim))!==false;
		$badList = ['python','java','sí','si','no','verdadero','falso'];
		$isBadWord = in_array(mb_strtolower($ansTrim), $badList, true) || (mb_strtoupper($ansTrim)===$ansTrim && mb_strlen($ansTrim)>=4);
		if(($isSingleWord && !$inQuestion) || $isBadWord){
			try{
				$modeloMF = $pickModel($job['modelo'],'PREG_SOLAS');
				$aiMF = new AIClient($modeloMF);
				$promptMF = "Responde la pregunta en 1 línea, concreta y relevante al tema. Evita palabras genéricas o nombres de lenguajes. Devuelve SOLO JSON {pregunta, respuesta}. Pregunta:\n".$preg;
				$ansMF = $aiMF->singleAnswer($promptMF);
				$jsonMF = json_decode($ansMF,true);
				if(is_array($jsonMF)){
					$cand = trim((string)($jsonMF['respuesta'] ?? ''));
					if($cand!=='') $respuestaLimpiada = $cand;
				}
			}catch(\Throwable $e){ /* ignorar */ }
			// Si sigue siendo una sola palabra mala, degradar a '(Sin respuesta)'
			$ansTrim2 = trim((string)$respuestaLimpiada);
			if($ansTrim2!=='' && (in_array(mb_strtolower($ansTrim2), $badList, true) || (mb_strtoupper($ansTrim2)===$ansTrim2 && mb_strlen($ansTrim2)>=4))){
				$respuestaLimpiada = '(Sin respuesta)';
			}
		}
	}
	// Caso mixto: documento de opciones pero este bloque no tiene opciones => tratar como PREG_SOLAS
	else if(($job['formato']==='PREG_OPC' || $job['formato']==='PREG_OPC_RESP') && (trim((string)($opcionesStr ?? ''))==='')){
		$ansTrim = trim((string)$respuestaLimpiada);
		$isSingleWord = (bool)preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{2,20}$/u',$ansTrim);
		$inQuestion = mb_stripos(mb_strtolower($preg), mb_strtolower($ansTrim))!==false;
		$badList = ['python','java','sí','si','no','verdadero','falso'];
		$isBadWord = in_array(mb_strtolower($ansTrim), $badList, true) || (mb_strtoupper($ansTrim)===$ansTrim && mb_strlen($ansTrim)>=4);
		if(($isSingleWord && !$inQuestion) || $isBadWord || $ansTrim===''){
			try{
				$modeloMF = $pickModel($job['modelo'],'PREG_SOLAS');
				$aiMF = new AIClient($modeloMF);
				$promptMF = "Responde la pregunta en 1 línea, concreta y relevante al tema. Devuelve SOLO JSON {pregunta, respuesta}. Pregunta:\n".$preg;
				$ansMF = $aiMF->singleAnswer($promptMF);
				$jsonMF = json_decode($ansMF,true);
				if(is_array($jsonMF)){
					$cand = trim((string)($jsonMF['respuesta'] ?? ''));
					if($cand!=='') $respuestaLimpiada = $cand;
				}
			}catch(\Throwable $e){ /* ignorar */ }
			// Si sigue siendo una sola palabra mala, degradar a '(Sin respuesta)'
			$ansTrim2 = trim((string)$respuestaLimpiada);
			if($ansTrim2!=='' && (in_array(mb_strtolower($ansTrim2), $badList, true) || (mb_strtoupper($ansTrim2)===$ansTrim2 && mb_strlen($ansTrim2)>=4))){
				$respuestaLimpiada = '(Sin respuesta)';
			}
		}
	}

	$block = "Pregunta $i:\n$preg";
	if($opcionesStr!==''){
		// Saneamiento final del bloque de opciones para TXT/DOCX/HTML: eliminar restos de 'Opciones:'
		$opClean = preg_replace('/^\s*Opciones\s*:\s*$/im','', $opcionesStr);
		$opClean = preg_replace('/^\s*Opciones\s*:\s*/im','', $opClean);
		$opClean = preg_replace('/\bOpciones\s*:\s*(?=\(?[A-Ha-h]\)|[A-Ha-h][\).])/im','', $opClean);
		$block .= "\nOpciones:\n".trim($opClean);
	}
	$ansLabel = (trim((string)($opcionesStr ?? ''))==='' ? 'Respuesta' : 'Respuesta correcta');
	$block .= "\n$ansLabel:\n$respuestaLimpiada";
		$docBlocks[] = $block;
		$contenidoFinal .= $block."\n\n";
		$i++;
	}
	$baseName = $job['id'].'-procesado';
	$txtOut = TEMP_PATH.'/'.$baseName.'.txt';
	file_put_contents($txtOut,$contenidoFinal);
	$docxOut = TEMP_PATH.'/'.$baseName.'.docx';
	$pdfOut = TEMP_PATH.'/'.$baseName.'.pdf';
	$htmlOut = TEMP_PATH.'/'.$baseName.'.html';
	$docxOk = export_docx($docBlocks,$docxOut);
	$pdfHtml = '';
	$htmlPrintable = '';
	foreach($docBlocks as $b){
		$lines = explode("\n", $b);
		$title = htmlentities(trim($lines[0] ?? 'Pregunta'));
		$qText=''; $optsText=''; $ansText='';
		$modeSec='q';
		for($ii=1;$ii<count($lines);$ii++){
			$ln = $lines[$ii];
			if(preg_match('/^Opciones\s*:/i',$ln)){ $modeSec='opts'; continue; }
			if(preg_match('/^Respuesta(\s+correcta)?\s*:/i',$ln)){ $modeSec='ans'; continue; }
			if($modeSec==='q'){ $qText .= ($qText?"\n":"").$ln; }
			elseif($modeSec==='opts'){ $optsText .= ($optsText?"\n":"").$ln; }
			else { $ansText .= ($ansText?"\n":"").$ln; }
		}
		// Saneamiento extra para evitar 'Opciones:' repetido dentro del bloque de opciones
		if($optsText!==''){
			// Quitar líneas que sean solo 'Opciones:' y prefijos residuales
			$optsText = preg_replace('/^\s*Opciones\s*:\s*$/im','', $optsText);
			$optsText = preg_replace('/^\s*Opciones\s*:\s*/im','', $optsText);
			$optsText = trim($optsText);
		}
	// Etiqueta dinámica por bloque según presencia de opciones
	$ansStrong = ($optsText!=='' ? 'Respuesta correcta:' : 'Respuesta:');
	$pdfHtml .= '<p><strong>'.$title.'</strong><br>'.nl2br(htmlentities($qText)).'<br>'
		. ($optsText!==''? '<strong>Opciones:</strong><br>'.nl2br(htmlentities($optsText)).'<br>' : '')
		. '<strong>'.$ansStrong.'</strong> '.nl2br(htmlentities($ansText)).'</p>';
		$htmlPrintable .= '<div class="item">'
				. '<div class="q"><strong>'.$title.'</strong><br>'.nl2br(htmlentities($qText)).'</div>'
				. ($optsText!==''? '<div class="opts"><strong>Opciones:</strong><br>'.nl2br(htmlentities($optsText)).'</div>' : '')
						. '<div class="ans"><strong>'.$ansStrong.'</strong> '.nl2br(htmlentities($ansText)).'</div>'
						. '</div>';
	}
	// PDF de servidor: solo si hay API externa configurada; si no, se usa el botón de navegador
	$pdfOk = false;
	if(env('PDF_API_URL')){
		$pdfOk = export_pdf_external($pdfHtml,$pdfOut);
	}
	// Generar HTML imprimible siempre
	$htmlOk = export_html_printable($htmlPrintable,$htmlOut);
	$files = [basename($txtOut)];
	if($docxOk && is_file($docxOut)) $files[] = basename($docxOut);
	if($pdfOk && is_file($pdfOut)) $files[] = basename($pdfOut);
	if($htmlOk && is_file($htmlOut)) $files[] = basename($htmlOut);
	$job['files']=$files;
	$job['status']='done';
}

file_put_contents($jobFile,json_encode($job,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

echo json_encode([
	'status'=>$job['status'],
	'processed'=>$job['processed'],
	'total'=>$job['total'],
	'files'=>$job['files'] ?? [],
	'source'=>$job['source']
]);
