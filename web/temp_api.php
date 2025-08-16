<?php
require_once __DIR__.'/../config/config.php';
$f=basename($_GET['f']??'');
$file=TEMP_PATH."/$f.json";
header('Content-Type: application/json; charset=utf-8');
if(!is_file($file)){ echo json_encode(['error'=>'not found']); exit; }
echo file_get_contents($file);
