<?php
require_once __DIR__.'/../../config/config.php';

class UtilityController {
  public static function descargar(){
    require __DIR__.'/../../web/descargar.php';
  }
  public static function temp(){
    require __DIR__.'/../../web/temp_api.php';
  }
}
