<?php
require_once __DIR__.'/../config/config.php';
$f = basename($_GET['f'] ?? '');
if(!$f){ echo 'Falta parametro f'; exit; }
$path = TEMP_PATH.'/'.$f;
if(!is_file($path)){ echo 'No existe archivo'; exit; }
$size = filesize($path);
$fh=@fopen($path,'rb'); $head=$fh?fread($fh,8):''; if($fh) fclose($fh);
header('Content-Type: text/plain; charset=utf-8');
printf("Archivo: %s\nTamaño: %d bytes\nCabecera HEX: %s\nCabecera ASCII: %s\n", $f,$size,bin2hex($head),$head);
$fh=@fopen($path,'rb');
if($fh){ echo "Primeros 200 bytes HEX:\n"; echo bin2hex(fread($fh,200)); fclose($fh);} else { echo "No se pudo reabrir archivo"; }
