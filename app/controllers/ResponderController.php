<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../models/Parser.php';
require_once __DIR__.'/../models/AIClient.php';

class ResponderController {
    public static function form(){
        render('responder/form');
    }
    public static function procesar(){
        // Reutilizamos la lógica de web/responder_procesar.php pero como acción
        require __DIR__.'/../../web/responder_procesar.php';
    }
    public static function job(){
        // JSON pipeline del job; reutilizamos el existente
        require __DIR__.'/../../web/responder_job.php';
    }
}
