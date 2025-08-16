<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD'] !== 'POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Método inválido']); exit; }

// Entrada: { file: uid, formato: 'txt'|'pdf'|'docx', data: { preguntas:[{pregunta, opciones[], correcta, respuesta}], resumen:{correctas,incorrectas,sinResponder,puntaje10,cal10} } }
try {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if(!is_array($j)) throw new Exception('JSON inválido');
    // Validar CSRF desde JSON (_csrf) o encabezado X-CSRF
    $token = (string)($j['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''));
    if(empty($_SESSION['csrf']) || empty($token) || !hash_equals($_SESSION['csrf'], $token)){
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'CSRF inválido']);
        exit;
    }
    $uid = preg_replace('/[^A-Za-z0-9_\-\.]/','', (string)($j['file'] ?? ''));
    $fmt = strtolower((string)($j['formato'] ?? 'txt'));
    $data = $j['data'] ?? null;
    $meta = $j['meta'] ?? [];
    if(!$uid || !is_array($data)) throw new Exception('Datos requeridos');

    $pregs = $data['preguntas'] ?? [];
    $resumen = $data['resumen'] ?? [];

    // Construir contenido base (HTML y texto)
    $lines = [];
    $lines[] = 'Resultados del simulador';
    if(!empty($meta['fecha'])) $lines[] = 'Fecha: '.$meta['fecha'];
    if(!empty($meta['modelo'])) $lines[] = 'Modelo: '.$meta['modelo'];
    $lines[] = 'ID: '.$uid; $lines[] = '';
    foreach($pregs as $i=>$q){
        $lines[] = ($i+1).') '.($q['pregunta'] ?? '');
        $lines[] = '  - Tu respuesta: '.($q['respuesta'] ?? '—');
        $lines[] = '  - Correcta: '.($q['correcta_txt'] ?? '—');
        $lines[] = '  - Estado: '.($q['estado'] ?? '');
        $lines[] = '';
    }
    $lines[] = 'Correctas: '.($resumen['correctas'] ?? 0);
    $lines[] = 'Incorrectas: '.($resumen['incorrectas'] ?? 0);
    $lines[] = 'Sin responder: '.($resumen['sinResponder'] ?? 0);
    $lines[] = 'Puntaje: '.($resumen['puntaje10'] ?? 0).'/10';
    $lines[] = 'Calificación: '.($resumen['cal10'] ?? 0).'/10';
    $txt = implode("\n", $lines);

    $htmlItems='';
    foreach($pregs as $i=>$q){
        $cls = ($q['estado']==='Correcta') ? 'color:green' : (($q['estado']==='Incorrecta') ? 'color:#b91c1c' : 'color:#555');
        $htmlItems .= '<div class="item">'
                    . '<div class="q">'.($i+1).') '.htmlspecialchars($q['pregunta']??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div>'
                    . '<div class="ans" style="'.$cls.'">Tu respuesta: '.htmlspecialchars($q['respuesta']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div>'
                    . '<div class="ans">Correcta: '.htmlspecialchars($q['correcta_txt']??'—',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div>'
                    . '</div>';
    }
    $htmlResumen = '<p style="margin:6px 0 10px 0">'
                 . (!empty($meta['fecha']) ? '<span style="margin-right:12px">Fecha: <strong>'.htmlspecialchars((string)$meta['fecha'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</strong></span>' : '')
                 . (!empty($meta['modelo']) ? '<span style="margin-right:12px">Modelo: <strong>'.htmlspecialchars((string)$meta['modelo'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</strong></span>' : '')
                 . '<span style="margin-right:12px">Total: <strong>'.count($pregs).'</strong></span>'
                 . '</p>'
                 . '<p><strong>Correctas:</strong> '.($resumen['correctas']??0)
                 .' &nbsp; <strong>Incorrectas:</strong> '.($resumen['incorrectas']??0)
                 .' &nbsp; <strong>Sin responder:</strong> '.($resumen['sinResponder']??0)
                 .' &nbsp; <strong>Puntaje:</strong> '.($resumen['puntaje10']??0).'/10'
                 .' &nbsp; <strong>Calificación:</strong> '.($resumen['cal10']??0).'/10</p>';
    $innerHtml = $htmlResumen . $htmlItems;

    $outBase = $uid.'.result.';
    $outPath = TEMP_PATH . '/';
    $downloadName = '';
    $ok = false;
    if($fmt==='txt'){
        $downloadName = $outBase.'txt';
        $ok = (bool)@file_put_contents($outPath.$downloadName, $txt);
    } elseif($fmt==='pdf'){
        $downloadName = $outBase.'pdf';
        // Preferir mPDF; si no hay, intentar servicio externo
        $ok = export_pdf($innerHtml, $outPath.$downloadName);
        if(!$ok){ $ok = export_pdf_external($innerHtml, $outPath.$downloadName); }
        if(!$ok){ // fallback HTML imprimible
            $alt = $outBase.'html';
            if(export_html_printable($innerHtml, $outPath.$alt)){
                $downloadName = $alt; $ok = true;
            }
        }
    } elseif($fmt==='docx'){
        $downloadName = $outBase.'docx';
        $blocks = [];
    foreach($pregs as $i=>$q){
            $blocks[] = 'Pregunta '.($i+1)."\n"
                      . ($q['pregunta']??'')."\n"
                      . 'Tu respuesta: '.($q['respuesta']??'—')."\n"
                      . 'Correcta: '.($q['correcta_txt']??'—')."\n";
        }
        $ok = export_docx($blocks, $outPath.$downloadName);
    } else {
        throw new Exception('Formato no soportado');
    }

    if(!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se pudo generar el archivo']); exit; }
    echo json_encode(['ok'=>true,'file'=>$downloadName]);
} catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
?>
