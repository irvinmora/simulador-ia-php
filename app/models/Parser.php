<?php
class Parser {
    public static function detectFormat(string $text): string {
        // Detección por líneas y también por opciones en línea
        $lines=preg_split('/\r?\n/',$text);
        $optionLinesIdx=[]; $countAns=0; $groups=0; $inlineGroups=0;
        foreach($lines as $i=>$l){
            $trim=trim($l);
            if($trim==='') continue;
            // Opción en inicio de línea
            if(preg_match('/^(?:\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])\s+.+/',$trim)){
                $optionLinesIdx[]=$i;
            }
            // Opciones inline en una sola línea (al menos dos marcas a)/b)/c) ...)
            if(preg_match_all('/(?:\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])\s+\S+/', $trim, $mm) && count($mm[0])>=2){
                $inlineGroups++;
            }
            if(preg_match('/respuesta\s+correcta|correcta\s*[:=-]|respuesta\s*[:=-]/i',$trim)) $countAns++;
        }
        // Agrupar líneas de opciones contiguas (separación <=2 líneas)
        if($optionLinesIdx){
            $prev=$optionLinesIdx[0]; $inGroupCount=1;
            for($k=1;$k<count($optionLinesIdx);$k++){
                $idx=$optionLinesIdx[$k];
                if($idx-$prev<=2){ $inGroupCount++; }
                else { if($inGroupCount>=2) $groups++; $inGroupCount=1; }
                $prev=$idx;
            }
            if($inGroupCount>=2) $groups++;
        }
        $hasGroups = ($groups>0) || ($inlineGroups>0);
        if($hasGroups && $countAns>0) return 'PREG_OPC_RESP';
        if($hasGroups) return 'PREG_OPC';
        return 'PREG_SOLAS';
    }

    public static function splitQuestions(string $text): array {
        // Normalización básica
    $text=str_replace("\r","",$text);
    // Inserta saltos antes de 'Pregunta N:' y antes de '¿'
        $text=preg_replace('/\s*(Pregunta\s*\d+\s*:)/i', "\n$1 ", $text);
    $text=preg_replace('/(Respuesta\s+correcta\s*:[^\n]+)\s*(?=¿)/i', "$1\n", $text);
    $text=preg_replace('/([^\n])¿/u', "$1\n¿", $text);

        // Si hay marcadores "Pregunta N:" usamos estos para segmentar
        if(preg_match('/Pregunta\s*\d+\s*:/i', $text)){
            $parts=preg_split('/(?=Pregunta\s*\d+\s*:)/i', $text, -1, PREG_SPLIT_NO_EMPTY);
            $out=[];
            foreach($parts as $p){
                // quitar encabezado 'Pregunta N:' y limpiar separadores
                $p=trim(preg_replace('/^Pregunta\s*\d+\s*:\s*/i','',$p));
                if($p==='') continue;
                $lines=preg_split('/\n/',$p);
                $clean=[];
                foreach($lines as $ln){ $t=trim($ln); if($t==='') continue; if(preg_match('/^[-\s]{5,}$/',$t)) continue; $clean[]=$t; }
                $cleaned=trim(implode("\n", $clean));
                if($cleaned==='') continue;
                // Si dentro del segmento hay múltiples '¿...?', dividir internamente
                if(preg_match_all('/¿[\s\S]*?\?/u', $cleaned, $sub)){ foreach($sub[0] as $q){ $q=trim($q); if($q!=='') $out[]=$q; } }
                else { $out[]=$cleaned; }
            }
            return $out;
        }

        // Si no, segmentamos por bloques que inician con '¿' e incluyen sus opciones hasta la siguiente '¿' o encabezado 'Pregunta N:'
        $lines=preg_split('/\n/',$text);
        $out=[]; $buf=[]; $inQ=false;
        for($i=0;$i<count($lines);$i++){
            $ln=trim($lines[$i]);
            if($ln==='') { if($inQ){ $buf[]=''; } continue; }
            $isHeader = preg_match('/^Pregunta\s*\d+\s*:/i',$ln);
            $startsQ = strpos($ln,'¿')!==false;
            if(!$inQ && $startsQ){
                $inQ=true; $buf=[]; $buf[]=$ln; continue;
            }
            if($inQ){
                // Si comienza una nueva pregunta o encabezado, cerramos el bloque anterior
                if($startsQ || $isHeader){
                    $clean=trim(implode("\n", $buf)); if($clean!=='') $out[]=$clean; $buf=[]; $inQ=$startsQ; if($inQ){ $buf[]=$ln; }
                } else {
                    $buf[]=$ln;
                }
            }
        }
        if($inQ && $buf){ $clean=trim(implode("\n", $buf)); if($clean!=='') $out[]=$clean; }
        if($out) return $out;

        // Fallback: lógica por líneas y separadores
    $lines=preg_split('/\n/',$text);
    $out=[]; $buf=[];
        foreach($lines as $raw){
            $l=trim($raw);
            if($l==='') { if($buf){ $out[]=trim(implode(' ', $buf)); $buf=[]; } continue; }
            // separadores tipo ----- o con espacios: -- -- --, etc.
            if(preg_match('/^[-\s]{5,}$/',$l)) { if($buf){ $out[]=trim(implode(' ', $buf)); $buf=[]; } continue; }
            if(preg_match('/^PREGUNTA\s*\d+:/i',$l)) { continue; }
            $buf[]=$l;
        }
        if($buf) $out[]=trim(implode(' ', $buf));
        return $out;
    }

    public static function extractOptions(string $block): array {
        $opts=[];
        // Limpiar 'Opciones:' si está incrustado en el bloque (inicio de línea o antes de marcadores)
        $block=preg_replace('/^\s*Opciones\s*:\s*/im','', $block);
        $block=preg_replace('/\bOpciones\s*:\s*(?=\(?[A-Ha-h]\)|[A-Ha-h][\).])/im','', $block);
        // Normalizar espacios duros: reemplazar múltiples espacios y tabs antes de marcadores por un solo espacio
        $block=preg_replace('/\s{2,}(?=\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])/u',' ', $block);
        // 1) Pase por líneas: acumular continuaciones bajo la última opción
        $lines=preg_split('/\r?\n/',$block);
        $items=[]; $currentIdx=-1;
        foreach($lines as $raw){
            $ln=trim($raw);
            if($ln==='') continue;
            if(preg_match('/^Respuesta\s+correcta\s*:/i',$ln)) break;
            if(preg_match('/^(?:\(?([A-Ha-h])\)|([A-Ha-h])[\).]|([A-Ha-h])\s*[-–:])\s*(.+)$/',$ln,$m)){
                $letter = strtoupper($m[1] ?: ($m[2] ?: $m[3]));
                $text = trim($m[4]);
                $items[] = [$letter, $text];
                $currentIdx = count($items)-1;
            } else if($currentIdx>=0 && !preg_match('/^(Pregunta\s*\d+\s*:|Opciones\s*:)/i',$ln)){
                // Puede contener nuevas marcas inline en la misma línea (p.ej., "... c) ... d) ...")
                $inlinePattern='/(?:\(?([A-Ha-h])\)|([A-Ha-h])[\).]|([A-Ha-h])\s*[-–:])\s*(.*?)(?=(?:\s*\(?[A-Ha-h]\)|\s*[A-Ha-h][\).]|\s*[A-Ha-h]\s*[-–:]|\s*Respuesta\s+correcta|$))/u';
                if(preg_match_all($inlinePattern,$ln,$mI,PREG_SET_ORDER|PREG_OFFSET_CAPTURE)){
                    // Prefijo antes de la primera marca pertenece a la opción actual
                    $firstOff = $mI[0][0][1]; // offset de todo el match
                    $prefix = trim(substr($ln, 0, max(0,$firstOff)));
                    if($prefix!==''){ $items[$currentIdx][1] = trim($items[$currentIdx][1].' '.$prefix); }
                    // Agregar nuevas opciones por cada marca inline encontrada
                    foreach($mI as $mi){
                        $ltr = strtoupper(($mi[1][0]?:($mi[2][0]?:$mi[3][0])));
                        $txt = trim($mi[4][0]);
                        if($txt!==''){ $items[] = [$ltr,$txt]; $currentIdx = count($items)-1; }
                    }
                } else {
                    // línea continuación simple
                    $items[$currentIdx][1] = trim($items[$currentIdx][1].' '.$ln);
                }
            } else if($currentIdx<0){
                // Intento: opciones en la misma línea del enunciado "... A) ... B) ..."
                $inlinePattern='/(?:\(?([A-Ha-h])\)|([A-Ha-h])[\).]|([A-Ha-h])\s*[-–:])\s*([^A-Ha-h\n][^\n]*?)(?=(?:\s*\(?[A-Ha-h]\)|\s*[A-Ha-h][\).]|\s*[A-Ha-h]\s*[-–:]|\s*Respuesta\s+correcta|$))/u';
                if(preg_match_all($inlinePattern,$ln,$mI,PREG_SET_ORDER)){
                    foreach($mI as $mi){ $ltr=strtoupper($mi[1] ?: ($mi[2] ?: $mi[3])); $txt=trim($mi[4]); if($txt!=='') $items[] = [$ltr,$txt]; }
                }
            }
        }
        // Post-proceso: si algún texto de opción contiene nuevas marcas, dividir en sub-opciones
        if(!empty($items)){
            $splitItems=[];
            $inlinePattern='/(?:\(?([A-Ha-h])\)|([A-Ha-h])[\).]|([A-Ha-h])\s*[-–:])\s*(.*?)(?=(?:\s*\(?[A-Ha-h]\)|\s*[A-Ha-h][\).]|\s*[A-Ha-h]\s*[-–:]|\s*Respuesta\s+correcta|$))/u';
            foreach($items as [$ltr,$txt]){
                // Separar posibles opciones extra en el texto
                if(preg_match_all($inlinePattern, $txt, $mm, PREG_SET_ORDER)){
                    // Prefijo antes de la primera marca pertenece a la opción actual
                    $first = strpos($txt, $mm[0][0]);
                    $prefix = $first!==false ? trim(substr($txt,0,$first)) : '';
                    if($prefix!==''){ $splitItems[] = [$ltr, $prefix]; }
                    foreach($mm as $m1){
                        $nl = strtoupper($m1[1] ?: ($m1[2] ?: $m1[3]));
                        $nt = trim($m1[4]); if($nt!=='') $splitItems[] = [$nl,$nt];
                    }
                } else {
                    $splitItems[] = [$ltr, trim($txt)];
                }
            }
            $items = $splitItems;
        }
        if(count($items)>=2){
            foreach($items as [$ltr,$txt]){ $opts[] = $ltr.') '.$txt; }
            return $opts;
        }
        // 2) Opciones inline en el mismo párrafo (delimitadas por nueva marca o 'Respuesta correcta')
        $pattern='/(?:\(?([A-Ha-h])\)|([A-Ha-h])[\).]|([A-Ha-h])\s*[-–:])\s*(.*?)(?=(?:\s*\(?[A-Ha-h]\)|\s*[A-Ha-h][\).]|\s*[A-Ha-h]\s*[-–:]|\s*Respuesta\s+correcta|$))/u';
        if(preg_match_all($pattern,$block,$m2,PREG_SET_ORDER)){
            foreach($m2 as $mm){ $letter=strtoupper($mm[1] ?: ($mm[2] ?: $mm[3])); $text=trim($mm[4]); if($text!=='') $opts[]=$letter+') '.$text; }
        }
        // 3) Bullets
        if(count($opts)<2 && preg_match_all('/^[\-*•]\s+(.+)$/m',$block,$m3)){
            $letters=['A','B','C','D','E','F','G']; $i=0; foreach($m3[1] as $raw){ $opts[]=$letters[$i].') '.trim($raw); $i++; }
        }
        return $opts;
    }

    public static function detectCorrectLetter(string $block): ?string {
    // Patrones comunes por letra explícita
    if(preg_match('/\b(correcta|respuesta|clave|soluci[óo]n)\b\s*[:\-]?\s*([A-H])/i',$block,$mm)) return strtoupper($mm[2]);
    if(preg_match('/\bletra\b\s*[:\-]?\s*([A-H])/i',$block,$mmL)) return strtoupper($mmL[1]);
    if(preg_match('/\bes\s+la\s+([A-H])\b/i',$block,$mmE)) return strtoupper($mmE[1]);
    // Marca con asterisco: opción marcada con * en la línea (al final o intermedio)
    if(preg_match('/^\s*\(?([A-H])\)?[\).]\s+.*?\*\s*$/m',$block,$mm3)) return strtoupper($mm3[1]);
    if(preg_match('/^\s*\*\s*\(?([A-H])\)?[\).]?\s+/m',$block,$mm4)) return strtoupper($mm4[1]);
        return null;
    }
}
