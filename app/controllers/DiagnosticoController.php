<?php
require_once __DIR__.'/../../config/config.php';

class DiagnosticoController {
    public static function index(){ require __DIR__.'/../../web/diagnostico.php'; }
    public static function pdf(){ require __DIR__.'/../../web/diagnostico_pdf.php'; }
}
