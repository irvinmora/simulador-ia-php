<?php
require_once __DIR__.'/../config/config.php';
$f = basename($_GET['f'] ?? '');
$o = basename($_GET['o'] ?? '');
$file = TEMP_PATH . '/' . $f;
$orig = TEMP_PATH . '/' . $o;
if(!$f || !is_file($file)) { add_error('Archivo no encontrado'); http_response_code(404); echo 'Archivo no encontrado'; exit; }
$mime = mime_content_type($file) ?: 'application/octet-stream';
if(function_exists('ob_get_level')){ while(ob_get_level()) { @ob_end_clean(); } }
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($file));
header('Content-Disposition: attachment; filename="'.$f.'"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
$fp=fopen($file,'rb');
while(!feof($fp)){ echo fread($fp,8192); }
fclose($fp);
?>
