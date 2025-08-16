<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../models/Parser.php';
require_once __DIR__.'/../models/AIClient.php';

class SimuladorController {
    public static function form(){
        render('simulador/form');
    }
    public static function analizar(){
        require __DIR__.'/../../web/simulador_analizar.php';
    }
    public static function run(){
        require __DIR__.'/../../web/simulador_run.php';
    }
}
