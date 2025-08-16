<?php
require_once __DIR__.'/../config/config.php';
$requisitos = [
  'curl','mbstring','json','zip','xml','gd'
];
$faltan = [];
foreach($requisitos as $ext){ if(!extension_loaded($ext)) $faltan[]=$ext; }
header('Content-Type: text/plain; charset=utf-8');
echo "Diagnóstico de extensiones PHP\n";
echo "--------------------------------\n";
foreach($requisitos as $ext){ echo str_pad($ext,12).' : '.(extension_loaded($ext)?'OK':'FALTA')."\n"; }
if($faltan){
  echo "\nFaltan: ".implode(', ',$faltan)."\n";
  echo "Revisa php.ini y descomenta extension=nombre (o instala).\n";
} else {
  echo "\nTodo listo.\n";
}
