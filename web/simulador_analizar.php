<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../app/models/Parser.php';
require_once __DIR__.'/../app/models/AIClient.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ echo json_encode(['error'=>'Método inválido']); exit; }
try{ csrf_check(); }catch(\Throwable $e){ http_response_code(400); echo json_encode(['error'=>'csrf']); exit; }
if(empty($_FILES['documento']['name'])){ echo json_encode(['error'=>'Archivo requerido']); exit; }
$origName=$_FILES['documento']['name'];
$ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
$tmp=$_FILES['documento']['tmp_name'];
$uid=uniqid('sim_anal_');
$dest=TEMP_PATH."/$uid.$ext"; @move_uploaded_file($tmp,$dest);
$text = '';
if($ext==='txt'){
  $text = @file_get_contents($dest);
} elseif($ext==='pdf' && class_exists('Smalot\\PdfParser\\Parser')){
  try{ $cls='Smalot\\PdfParser\\Parser'; $parser=new $cls(); $pdf=$parser->parseFile($dest); $text=$pdf->getText(); }catch(\Throwable $e){ $text=''; }
} elseif($ext==='docx' && class_exists('PhpOffice\\PhpWord\\IOFactory')){
  try{ $io='PhpOffice\\PhpWord\\IOFactory'; $phpWord=$io::load($dest,'Word2007'); foreach($phpWord->getSections() as $section){ foreach($section->getElements() as $e){ if(method_exists($e,'getText')) $text.=$e->getText()."\n"; } } }catch(\Throwable $e){ $text=''; }
} else {
  $text = @file_get_contents($dest);
}
if($text===false || trim($text)===''){ echo json_encode(['error'=>'No se pudo leer']); exit; }
$formato = Parser::detectFormat($text);
$bloques = Parser::splitQuestions($text);
$total = is_array($bloques)? count($bloques) : 0;
if(($formato==='DESCONOCIDO' || $total===0) && env('AI_AUTO_DETECT','1')==='1'){
  $modelo = $_POST['model'] ?? env('DEFAULT_MODEL','ollama:llama3:latest');
  $ai = new AIClient($modelo);
  $prompt = "Analiza el siguiente texto y responde SOLO un número entero con la cantidad de preguntas tipo examen que detectas.\n"
        ."Cuenta como pregunta cualquier enunciado que requiera respuesta (con o sin opciones).\n"
        ."Texto:\n".mb_substr($text,0,12000);
  $ans = $ai->singleAnswer($prompt);
  if(preg_match('/(\d{1,4})/', $ans, $m)){
    $total = max($total, intval($m[1]));
    if($formato==='DESCONOCIDO' && $total>0){ $formato = 'AUTO'; }
  }
}

echo json_encode([
  'ok'=>true,
  'total'=>$total,
  'formato'=>$formato,
]);
?>
