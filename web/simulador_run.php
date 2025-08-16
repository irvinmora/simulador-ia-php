<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../app/models/Parser.php';
require_once __DIR__.'/../app/models/AIClient.php';
include VIEW_PATH.'/partials/header.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){ add_error('Método inválido'); echo 'Error'; include VIEW_PATH.'/partials/footer.php'; exit; }
csrf_check();
if(empty($_FILES['documento']['name'])){ add_error('Archivo requerido'); echo 'Error'; include VIEW_PATH.'/partials/footer.php'; exit; }

$origName=$_FILES['documento']['name'];
$ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
$tmp=$_FILES['documento']['tmp_name'];
$uid=uniqid('sim_');
$dest=TEMP_PATH."/$uid.$ext"; move_uploaded_file($tmp,$dest);

$text='';
if($ext==='txt'){
  $text=@file_get_contents($dest);
} elseif($ext==='pdf' && class_exists('Smalot\\PdfParser\\Parser')){
  try{ $cls='Smalot\\PdfParser\\Parser'; $parser=new $cls(); $pdf=$parser->parseFile($dest); $text=$pdf->getText(); }catch(\Throwable $e){ $text=''; }
} elseif($ext==='docx' && class_exists('PhpOffice\\PhpWord\\IOFactory')){
  try{ $io='PhpOffice\\PhpWord\\IOFactory'; $phpWord=$io::load($dest,'Word2007'); foreach($phpWord->getSections() as $section){ foreach($section->getElements() as $e){ if(method_exists($e,'getText')) $text.=$e->getText()."\n"; } } }catch(\Throwable $e){ $text=''; }
} else { $text=@file_get_contents($dest); }
$text=(string)$text;

$formato=Parser::detectFormat($text);
$bloques=Parser::splitQuestions($text);
$total=count($bloques);
$cantidad = max(1, min(intval($_POST['cantidad']??1), max(1,$total)));
shuffle($bloques);
$seleccion=array_slice($bloques,0,$cantidad);

$strict = isset($_POST['strict']) ? (($_POST['strict']=='1'||$_POST['strict']==='on') ? true : false) : (env('SIM_STRICT','0')==='1');
$smartStrict = (env('SIM_SMART_STRICT','1')==='1');
$minOptions = max(2, min(8, (int)env('SIM_MIN_OPTIONS','4')));
$forceAiCorrect = isset($_POST['force_ai_correct'])
  ? (($_POST['force_ai_correct']=='1'||$_POST['force_ai_correct']==='on') ? true : false)
  : (env('SIM_FORCE_AI_CORRECT','0')==='1');
$aiModel = trim((string)($_POST['model'] ?? ($_POST['llm_model'] ?? env('DEFAULT_MODEL','claude-3-5-sonnet-20240620'))));
$simDate = date('Y-m-d H:i');
$aiClient = new AIClient($aiModel);

$ai_extract = function(AIClient $ai,string $block) use ($uid){
  $prompt = "Analiza el siguiente bloque de examen y devuelve SOLO JSON válido, sin texto adicional, con las claves: pregunta (string), opciones (array de strings) y correcta (índice 0-based).\n\nBloque:\n".$block."\n\nFormato: {\"pregunta\":\"...\",\"opciones\":[\"...\",\"...\"],\"correcta\":0}";
  $raw = $ai->singleAnswer($prompt);
  @file_put_contents(TEMP_PATH."/$uid.ai.extract.txt", $raw, FILE_APPEND);
  $json = json_decode(trim($raw), true);
  if(!is_array($json)) return [null, [], -1, $raw];
  $q = (string)($json['pregunta'] ?? '');
  $ops = [];
  $opsRaw = $json['opciones'] ?? [];
  if(is_array($opsRaw)){
    foreach($opsRaw as $opt){
      if(is_array($opt)){
        $cand = $opt['texto'] ?? ($opt['text'] ?? ($opt['label'] ?? ($opt['value'] ?? null)));
        if($cand===null){ $cand = json_encode($opt, JSON_UNESCAPED_UNICODE); }
        $ops[] = trim(preg_replace('/\s+/',' ', (string)$cand));
      } else {
        $ops[] = trim(preg_replace('/\s+/',' ', (string)$opt));
      }
    }
  }
  $k = (int)($json['correcta'] ?? -1);
  if($k<0 && isset($json['correcta_index'])) $k=(int)$json['correcta_index'];
  if($k>=count($ops)) $k = max(-1, min(count($ops)-1, $k));
  return [$q, $ops, $k, $raw];
};
$ai_pick = function(AIClient $ai,string $q,array $ops) use ($uid){
  $opsList = '';
  for($i=0;$i<count($ops);$i++){ $opsList .= "\n".chr(65+$i).") ".preg_replace('/[\r\n]+/',' ', (string)$ops[$i]); }
  $prompt = "Elige la opción correcta para la pregunta. Devuelve SOLO JSON válido {'correcta': k} con k entre 0 y ".(count($ops)-1)." (0-based). Si no puedes decidir, usa -1.\n\nPregunta:\n".$q."\nOpciones:".$opsList;
  $raw = $ai->singleAnswer($prompt);
  @file_put_contents(TEMP_PATH."/$uid.ai.pick.txt", $raw, FILE_APPEND);
  $j = json_decode(trim($raw), true);
  $k = is_array($j) && isset($j['correcta']) ? (int)$j['correcta'] : -1;
  if($k>=count($ops)) $k=-1;
  return [$k, $raw];
};
$ai_generate = function(AIClient $ai,string $q) use ($uid){
  $prompt = "Genera 5 opciones plausibles y la correcta para la pregunta dada. Evita 'Todas las anteriores' y 'Ninguna'. Devuelve SOLO JSON válido: {opciones:[...5...], correcta:k (0-based)}.\n\nPregunta:\n".$q;
  $raw = $ai->singleAnswer($prompt);
  @file_put_contents(TEMP_PATH."/$uid.ai.gen.txt", $raw, FILE_APPEND);
  $j = json_decode(trim($raw), true);
  $ops = []; $k=-1;
  if(is_array($j)){
    if(isset($j['opciones']) && is_array($j['opciones'])){
      foreach($j['opciones'] as $opt){
        if(is_array($opt)){
          $cand = $opt['texto'] ?? ($opt['text'] ?? ($opt['label'] ?? ($opt['value'] ?? null)));
          if($cand===null){ $cand = json_encode($opt, JSON_UNESCAPED_UNICODE); }
          $ops[] = trim(preg_replace('/\s+/',' ', (string)$cand));
        } else {
          $ops[] = trim(preg_replace('/\s+/',' ', (string)$opt));
        }
      }
    }
    if(isset($j['correcta'])) $k = (int)$j['correcta'];
  }
  if(count($ops)<5){
    while(count($ops)<5){ $ops[] = 'Opción '.(count($ops)+1); }
    if($k<0 || $k>=5) $k=0;
  }
  return [$ops,$k,$raw];
};

$normalizeBlock = function(string|array $txt): string {
  if(is_array($txt)){
    $txt = implode("\n", array_map('strval', $txt));
  }
  $t = preg_replace('/\s+(?=(?:\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])\s+)/u', "\n", $txt);
  $t = preg_replace('/([^\s])((?:\(?[A-Ha-h]\)|[A-Ha-h][\).]))/u', '$1 $2', $t);
  $t = preg_replace('/(Opciones\s*:)[ \t]*/i', "$1\n", $t);
  $t = preg_replace('/\bOpciones\s*:\s*(?=\(?[A-Ha-h]\)|[A-Ha-h][\).])/im','', $t);
  $t = preg_replace('/\s*(Respuesta\s+correcta\s*:)/i', "\n$1 ", $t);
  return trim($t);
};

$getQuestionText = function(string $entrada): string {
  $lines = preg_split('/\r?\n/', $entrada);
  $buf=[]; foreach($lines as $ln){
    if(preg_match('/^\s*(Opciones\s*:|Respuesta\s+correcta\s*:)/i',$ln)) break;
    if(preg_match('/^\s*(?:\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])\s+.+/',$ln)) break;
    $buf[] = rtrim($ln);
  }
  return trim(implode("\n", $buf));
};

$quiz=[]; $i=1;
foreach($seleccion as $b){
  $origin=''; $opts=[]; $correct=0;
  $nb = $normalizeBlock($b);
  $qText = $getQuestionText($nb);

  if($formato==='PREG_SOLAS'){
    if($strict && !$smartStrict){
      $opts=[]; $correct=-1; $origin='sin-opciones';
    } else {
      [$ops,$k,$raw] = $ai_generate($aiClient, $qText ?: $nb);
      $opts = [];
      for($jj=0;$jj<count($ops);$jj++){ $opts[] = chr(65+$jj).') '.$ops[$jj]; }
      $correct = ($k>=0 && $k<count($opts)) ? $k : 0;
      $origin = 'ia-extraccion';
      @file_put_contents(TEMP_PATH."/$uid.q{$i}.gen.json", json_encode(['pregunta'=>$qText?:$nb,'opciones'=>$ops,'correcta'=>$k,'raw'=>$raw], JSON_UNESCAPED_UNICODE));
    }
  } elseif(in_array($formato,['PREG_OPC','PREG_OPC_RESP','AUTO'],true)){
    $opts = Parser::extractOptions($nb) ?: [];
    $kFromAI = null;
    if(count($opts) < 2){
      $inline = preg_split('/\s+(?=\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])/u', $nb);
      $cand=[]; foreach($inline as $seg){ $seg=trim($seg); if(preg_match('/^(\(?[A-Ha-h]\)|[A-Ha-h][\).]|[A-Ha-h]\s*[-–:])\s+.+$/',$seg)) $cand[]=$seg; }
      if(count($cand)>=2) $opts = $cand;
      if(count($opts) < 2 && (!$strict || $smartStrict)){
        [$qAI,$opsAI,$kAI,$rawAI] = $ai_extract($aiClient, $nb);
        if(is_array($opsAI) && count($opsAI)>=2){
          $opts=[]; for($jj=0;$jj<count($opsAI);$jj++){ $opts[] = chr(65+$jj).') '.$opsAI[$jj]; }
          $qText = $qAI ?: $qText; $origin='ia-extraccion';
          if(is_int($kAI) && $kAI>=0 && $kAI<count($opsAI)) $kFromAI = $kAI;
        }
        @file_put_contents(TEMP_PATH."/$uid.q{$i}.extract.json", json_encode(['pregunta'=>$qAI,'opciones'=>$opsAI,'correcta'=>$kAI,'raw'=>$rawAI], JSON_UNESCAPED_UNICODE));
      }
    }
    if(count($opts) > 0 && count($opts) < $minOptions && (!$strict || $smartStrict)){
      [$qAI,$opsAI,$kAI,$rawAI] = $ai_extract($aiClient, $nb);
      if(is_array($opsAI) && count($opsAI) >= max($minOptions, 2)){
        $opts=[]; for($jj=0;$jj<count($opsAI);$jj++){ $opts[] = chr(65+$jj).') '.$opsAI[$jj]; }
        $qText = $qAI ?: $qText; $origin='ia-extraccion';
        if(is_int($kAI) && $kAI>=0 && $kAI<count($opsAI)) $kFromAI = $kAI;
      }
      @file_put_contents(TEMP_PATH."/$uid.q{$i}.extract.json", json_encode(['pregunta'=>$qAI,'opciones'=>$opsAI,'correcta'=>$kAI,'raw'=>$rawAI,'reason'=>'minOptions'], JSON_UNESCAPED_UNICODE));
    }
    if(count($opts) < 2){
      $opts=[]; $correct=-1; $origin='sin-opciones';
    } else {
      $correct=null; $letterDoc = Parser::detectCorrectLetter($nb);
      if($letterDoc){ foreach($opts as $idx=>$o){ if(preg_match('/^\s*\(?'.preg_quote($letterDoc,'/').'\)?[)\.-]?\s*/i',$o)){$correct=$idx; $origin='doc-letra'; break;} } }
      if($correct===null && preg_match('/\b(correcta|respuesta|clave)\b\s*[:\-]?\s*(?:opci[oó]n\s*)?(\d{1,2})\b/i', $nb, $mnum)){
        $n = intval($mnum[2]); if($n>=1 && $n<=count($opts)){ $correct=$n-1; $origin='doc-numero'; }
      }
      if($correct===null && preg_match('/Respuesta\s+correcta\s*:\s*(.+)$/mi', $nb, $mrc)){
        $corrRaw = trim($mrc[1]);
        $corrRaw = preg_split('/\s*(?:\.|;|\-|Explicaci[óo]n|Justificaci[óo]n)\b/u', $corrRaw)[0] ?? $corrRaw;
        $normalizeAns = function(string $s): string {
          $s = trim($s);
          $s = preg_replace('/^\s*\(?[A-Ha-h]\)?[)\.-]?\s*/','', $s);
          $s2 = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
          if($s2!==false && $s2!==null){ $s = $s2; }
          $s = mb_strtolower($s);
          $s = preg_replace('/[^\p{L}\p{N}\s]/u',' ', $s);
          $s = preg_replace('/\s+/',' ', $s);
          return trim($s);
        };
        $corrTxtN = $normalizeAns($corrRaw);
        if($corrTxtN!==''){
          $bestIdx = -1; $bestScore = 0.0;
          for($ii=0;$ii<count($opts);$ii++){
            $optTxt = preg_replace('/^\s*\(?[A-Ha-h]\)?[)\.-]?\s*/','', $opts[$ii]);
            $optN = $normalizeAns($optTxt);
            if($optN==='') continue;
            if($optN === $corrTxtN){ $bestIdx=$ii; $bestScore=1.0; break; }
            if(strpos($optN, $corrTxtN) !== false || strpos($corrTxtN, $optN) !== false){
              $score = min(strlen($corrTxtN), strlen($optN)) / max(strlen($corrTxtN), strlen($optN));
              if($score > $bestScore){ $bestScore=$score; $bestIdx=$ii; }
              continue;
            }
            $simPerc = 0.0; similar_text($optN, $corrTxtN, $simPerc);
            $score = $simPerc / 100.0;
            if($score > $bestScore){ $bestScore=$score; $bestIdx=$ii; }
          }
          if($bestIdx>=0 && $bestScore>=0.6){ $correct=$bestIdx; $origin='doc-texto'; }
        }
      }
      if($correct===null){
        if($kFromAI!==null && $kFromAI>=0 && $kFromAI<count($opts)){
          $correct = $kFromAI; $origin = 'ia-extraccion';
        }
      }
      if($forceAiCorrect && count($opts)>=2){
        $opsClean=[]; foreach($opts as $oo){ $opsClean[] = preg_replace('/^\s*\(?[A-Ha-h]\)?[)\.-]?\s*/','', $oo); }
        [$kPick,$rawPick] = $ai_pick($aiClient, $qText ?: $nb, $opsClean);
        if($kPick>=0 && $kPick<count($opts)){ $correct=$kPick; $origin='ia-forzada'; }
        @file_put_contents(TEMP_PATH."/$uid.q{$i}.pick.json", json_encode(['pregunta'=>$qText?:$nb,'opciones'=>$opsClean,'correcta'=>$kPick,'raw'=>$rawPick,'forced'=>true], JSON_UNESCAPED_UNICODE));
      }
      if($correct===null){
        if(!$strict || $smartStrict){
          $opsClean=[]; foreach($opts as $oo){ $opsClean[] = preg_replace('/^\s*\(?[A-Ha-h]\)?[)\.-]?\s*/','', $oo); }
          [$kPick,$rawPick] = $ai_pick($aiClient, $qText ?: $nb, $opsClean);
          if($kPick>=0 && $kPick<count($opts)){ $correct=$kPick; $origin='ia-correcta'; }
          @file_put_contents(TEMP_PATH."/$uid.q{$i}.pick.json", json_encode(['pregunta'=>$qText?:$nb,'opciones'=>$opsClean,'correcta'=>$kPick,'raw'=>$rawPick], JSON_UNESCAPED_UNICODE));
        }
        if($correct===null){ $correct=-1; $origin='sin-clave'; }
      }
    }
  } else {
    if($strict){
      $opts=[]; $correct=-1; $origin='desconocido';
    } else {
      [$qAI,$opsAI,$kAI,$rawAI] = $ai_extract($aiClient, $nb);
      $qText = $qAI ?: $qText;
      $opts = [];
      for($jj=0;$jj<count($opsAI);$jj++){ $opts[] = chr(65+$jj).') '.$opsAI[$jj]; }
      $correct = ($kAI>=0 && $kAI<count($opts)) ? $kAI : -1;
      $origin = 'ia-extraccion';
      @file_put_contents(TEMP_PATH."/$uid.q{$i}.extract.json", json_encode(['pregunta'=>$qAI,'opciones'=>$opsAI,'correcta'=>$kAI,'raw'=>$rawAI], JSON_UNESCAPED_UNICODE));
    }
  }

  $quiz[]=[
    'num'=>$i,
    'pregunta'=>$qText!==''?$qText:$b,
    'opciones'=>$opts,
    'correcta'=>$correct,
    'origen'=>$origin
  ];
  $i++;
}

file_put_contents(TEMP_PATH."/$uid.json",json_encode($quiz,JSON_UNESCAPED_UNICODE));
?>
<h1>Simulación en Progreso</h1>
<p>Total detectado: <?php echo $total; ?> | Usando: <?php echo $cantidad; ?> | Modo estricto: <strong><?php echo $strict?'Sí':'No'; ?></strong></p>
<div id="quiz" class="quiz-shell" data-file="<?php echo $uid; ?>" data-strict="<?php echo $strict?'1':'0'; ?>" data-csrf="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES); ?>" data-model="<?php echo htmlspecialchars($aiModel,ENT_QUOTES); ?>" data-simdate="<?php echo htmlspecialchars($simDate,ENT_QUOTES); ?>">
  <div id="pregunta"></div>
  <div id="opciones" class="options-area" style="margin:1rem 0;"></div>
  <div class="question-nav" id="navPreg"></div>
  <div class="actions-sticky">
    <div class="row">
      <button class="btn secondary" id="prevBtn" disabled>Anterior</button>
      <button class="btn" id="nextBtn">Siguiente</button>
    </div>
    <div class="row">
      <button class="btn danger" id="finishBtn">Finalizar</button>
      <button class="btn secondary" id="restartBtn" type="button">Reiniciar</button>
      <a class="btn" id="homeBtn" href="/simul/index.php">Inicio</a>
      <select id="formatSel" class="select-model">
        <option value="txt">TXT</option>
        <option value="pdf">PDF</option>
        <option value="docx">Word</option>
      </select>
      <button class="btn" id="downloadBtn" type="button" disabled>Descargar</button>
    </div>
  </div>
  <div id="resultado"></div>
  <div id="detalles"></div>
</div>
<div id="overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;color:#fff;align-items:center;justify-content:center;font-size:1.1rem;">
  <div style="background:#111;padding:1rem 1.2rem;border-radius:8px;border:1px solid #333;">
    <div id="ovText">Preparando simulación...</div>
    <div style="margin-top:.6rem;width:260px;height:8px;background:#222;border-radius:6px;overflow:hidden;">
      <div id="ovBar" style="height:100%;width:0;background:#22c55e;transition:width .3s ease;"></div>
    </div>
  </div>
</div>
<script>
// Utilidades
function fetchJson(url){
  return fetch(url, {cache:'no-store'})
    .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); });
}
(function(){
  const root = document.getElementById('quiz');
  const file = root?.getAttribute('data-file');
  const strict = root?.getAttribute('data-strict')==='1';
  const csrf = root?.getAttribute('data-csrf') || '';
  const model = root?.getAttribute('data-model') || '';
  const simDate = root?.getAttribute('data-simdate') || '';
  if(!file){ console.error('Falta data-file'); return; }
  const elQ = document.getElementById('pregunta');
  const elOps = document.getElementById('opciones');
  const btnPrev = document.getElementById('prevBtn');
  const btnNext = document.getElementById('nextBtn');
  const btnFinish = document.getElementById('finishBtn');
  const btnRestart = document.getElementById('restartBtn');
  const nav = document.getElementById('navPreg');
  const res = document.getElementById('resultado');
  const details = document.getElementById('detalles');
  const overlay = document.getElementById('overlay');
  const ovText = document.getElementById('ovText');
  const ovBar = document.getElementById('ovBar');
  // const actions = document.querySelector('#quiz .actions-sticky');

  let data = [];
  let idx = 0;
  let answers = [];

  function showOverlay(txt, pct){ if(!overlay) return; overlay.style.display='flex'; if(ovText) ovText.textContent=txt||''; if(typeof pct==='number' && ovBar){ ovBar.style.width = Math.max(0,Math.min(100,pct))+'%'; } }
  function hideOverlay(){ if(!overlay) return; overlay.style.display='none'; }

  function buildNav(){
    nav.innerHTML='';
    data.forEach((_,i)=>{
      const b=document.createElement('button');
      b.textContent = (i+1);
      if(i===idx) b.classList.add('active');
      if(answers[i]!==-1) b.classList.add('answered');
      b.addEventListener('click',()=>{ idx=i; render(); });
      nav.appendChild(b);
    });
  }

  // layout dinámico removido (reversión a estado anterior)

  function render(){
    const q = data[idx];
    if(!q){ return; }
    elQ.innerHTML = '<h2 style="margin:.2rem 0 0">Pregunta '+q.num+'</h2><p style="margin:.3rem 0 0">'+escapeHtml(q.pregunta||'')+'</p>';
    elOps.innerHTML='';
    const ops = Array.isArray(q.opciones)? q.opciones : [];
    if(ops.length===0){
      const p=document.createElement('p'); p.textContent='Sin opciones disponibles para esta pregunta.'; elOps.appendChild(p);
    } else {
      const ul=document.createElement('ul');
      ul.style.listStyle='none'; ul.style.margin='0'; ul.style.padding='0';
      ops.forEach((txt,i)=>{
        const li=document.createElement('li');
        li.style.margin='0 0 .5rem 0';
        const btn=document.createElement('button');
        btn.className='btn secondary option-btn';
        btn.style.display='block'; btn.style.width='100%'; btn.style.textAlign='left'; btn.style.whiteSpace='normal'; btn.style.wordBreak='break-word'; btn.style.minHeight='44px';
        btn.innerHTML = '<span class="opt-label" style="font-weight:700;margin-right:.5rem">'+String.fromCharCode(65+i)+')</span>'
                      + '<span class="opt-text">'+escapeHtml(txt.replace(/^\s*\(?[A-Ha-h]\)?[)\.-]?\s*/,''))+'</span>';
        if(answers[idx]===i) btn.classList.remove('secondary');
        btn.addEventListener('click',(e)=>{ e.preventDefault(); answers[idx]=i; render(); });
        li.appendChild(btn); ul.appendChild(li);
      });
      elOps.appendChild(ul);
    }
    btnPrev.disabled = (idx===0);
    btnNext.disabled = (idx>=data.length-1);
    buildNav();
    res.innerHTML='';
  details.innerHTML='';
  }

  function escapeHtml(s){ return (s||'').replace(/[&<>]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

  btnPrev?.addEventListener('click', (e)=>{ e.preventDefault(); if(idx>0){ idx--; render(); }});
  btnNext?.addEventListener('click', (e)=>{ e.preventDefault(); if(idx<data.length-1){ idx++; render(); }});
  btnRestart?.addEventListener('click', (e)=>{ e.preventDefault(); answers = data.map(()=>-1); idx=0; render(); });
  function cleanOptText(s){ return String(s||'').replace(/^\s*\(?[A-Ha-h]\)?[)\.-]?\s*/, ''); }

  function buildSummaryAndDetails(){
    const total = data.length;
    let correct=0, unanswered=0;
    const blocks = [];
    data.forEach((q,i)=>{
      const a=answers[i];
      const hasOps = Array.isArray(q.opciones) && q.opciones.length>0;
      const corr = (typeof q.correcta==='number') ? q.correcta : -1;
      let estado = 'sin-responder';
      let good = false;
      if(!hasOps){ estado='sin-opciones'; }
      else if(a===-1){ estado='sin-responder'; unanswered++; }
      else if(corr>=0){ if(a===corr){ estado='correcta'; correct++; good=true; } else { estado='incorrecta'; } }
      else { estado='sin-clave'; }

      const userTxt = (hasOps && a>=0) ? cleanOptText(q.opciones[a]) : '—';
      const corrTxt = (hasOps && corr>=0) ? cleanOptText(q.opciones[corr]) : '—';
      const cls = (estado==='correcta') ? 'good' : (estado==='incorrecta' ? 'bad' : 'neutral');
      const html = '<div class="qa '+cls+'">'
        +'<h4 style="margin:.2rem 0 .6rem;font-size:1.05rem">'+(i+1)+') '+escapeHtml(q.pregunta||'')+'</h4>'
        +'<p style="margin:.2rem 0"><strong>Tu respuesta:</strong> '+escapeHtml(userTxt)+'</p>'
        +'<p style="margin:.2rem 0"><strong>Correcta:</strong> '+escapeHtml(corrTxt)+'</p>'
        +'</div>';
      blocks.push(html);
    });
    const wrong = total - correct - unanswered;
    const score10 = total>0 ? (correct/total*10) : 0;
    const puntaje10 = Math.round(score10);
    const cal10 = Math.round(score10*100)/100;

    res.innerHTML = '<div class="card">'
      +'<div class="results-inline meta-row" style="margin-bottom:.5rem;color:#cbd5e1;font-size:.9rem">'
      +'<span class="meta-item">Fecha: <strong>'+escapeHtml(simDate)+'</strong></span>'
      +'<span class="sep" aria-hidden="true">|</span>'
      +'<span class="meta-item">Modelo: <strong>'+escapeHtml(model)+'</strong></span>'
      +'<span class="sep" aria-hidden="true">|</span>'
      +'<span class="meta-item">Total: <strong>'+total+'</strong></span>'
      +'</div>'
      +'<div class="results-inline">'
      +'<span class="pill good">Correctas: <strong>'+correct+'</strong></span>'
      +'<span class="sep" aria-hidden="true">|</span>'
      +'<span class="pill bad">Incorrectas: <strong>'+wrong+'</strong></span>'
      +'<span class="sep" aria-hidden="true">|</span>'
      +'<span class="pill neutral">Sin responder: <strong>'+unanswered+'</strong></span>'
      +'<span class="sep" aria-hidden="true">|</span>'
      +'<span class="pill neutral">Puntaje: <strong>'+puntaje10+'/10</strong></span>'
      +'<span class="sep" aria-hidden="true">|</span>'
      +'<span class="pill neutral">Calificación: <strong>'+cal10+'/10</strong></span>'
  +'<div style="flex-basis:100%;height:0"></div>'
  +'<small style="opacity:.7">La calificación es el promedio sobre 10 con dos decimales (correctas/total*10).</small>'
      +'</div>'
      +'</div>';
    details.innerHTML = blocks.join('');
    const dBtn = document.getElementById('downloadBtn');
    if(dBtn) dBtn.disabled = false;
  }

  async function descargarResultados(){
    const total = data.length;
    let correct=0, unanswered=0;
    const preguntas = [];
    data.forEach((q,i)=>{
      const a=answers[i];
      const corr=(typeof q.correcta==='number')?q.correcta:-1;
      const hasOps=Array.isArray(q.opciones)&&q.opciones.length>0;
      const userTxt=(hasOps&&a>=0)? cleanOptText(q.opciones[a]) : '—';
      const corrTxt=(hasOps&&corr>=0)? cleanOptText(q.opciones[corr]) : '—';
      let estado='';
      if(!hasOps){ estado='Sin opciones'; }
      else if(a===-1){ estado='Sin responder'; unanswered++; }
      else if(corr>=0){ if(a===corr){ estado='Correcta'; correct++; } else { estado='Incorrecta'; } }
      else { estado='Sin clave'; }
      preguntas.push({ pregunta: q.pregunta||'', respuesta: userTxt, correcta_txt: corrTxt, estado });
    });
    const wrong = total - correct - unanswered;
    const score10 = total>0 ? (correct/total*10) : 0;
    const puntaje10 = Math.round(score10);
    const cal10 = Math.round(score10*100)/100;
  const resumen = { correctas: correct, incorrectas: wrong, sinResponder: unanswered, puntaje10, cal10 };
    const formato = document.getElementById('formatSel')?.value || 'txt';
  const payload = { _csrf: csrf, file, formato, meta: { fecha: simDate, modelo: model, total }, data: { preguntas, resumen } };
    showOverlay('Generando archivo...', 50);
    const r = await fetch('/simul/web/descargar_resultados.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
    });
    hideOverlay();
    if(!r.ok){ alert('No se pudo generar el archivo'); return; }
    const j = await r.json();
    if(!j.ok){ alert(j.error||'No se pudo generar el archivo'); return; }
    // Redirigir a descarga física
    const url = '/simul/web/descargar.php?f='+encodeURIComponent(j.file);
    window.location.href = url;
  }

  btnFinish?.addEventListener('click', (e)=>{
    e.preventDefault();
    buildSummaryAndDetails();
  });

  document.getElementById('downloadBtn')?.addEventListener('click', (e)=>{ e.preventDefault(); descargarResultados(); });

  // (sin ajuste dinámico)

  // Cargar datos y renderizar
  showOverlay('Cargando preguntas...', 10);
  fetchJson('/simul/web/temp_api.php?f='+encodeURIComponent(file))
    .then(j=>{
      if(!Array.isArray(j)) throw new Error('Formato inesperado');
      data = j; answers = data.map(()=>-1);
      hideOverlay();
      render();
    })
    .catch(err=>{
      console.error(err);
      if(ovText) ovText.textContent = 'No se pudo cargar el cuestionario';
      if(ovBar) ovBar.style.width='100%';
      setTimeout(()=>{ hideOverlay(); }, 1200);
      elQ.innerHTML = '<p class="alert-error">No se pudo cargar el cuestionario.</p>';
    });
})();
</script>
<?php include VIEW_PATH.'/partials/footer.php'; ?>
