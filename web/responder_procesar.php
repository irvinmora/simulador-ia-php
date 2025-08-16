<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../app/models/Parser.php';
include VIEW_PATH.'/partials/header.php';
csrf_check();
if($_SERVER['REQUEST_METHOD']!=='POST'){ add_error('Método inválido'); echo '<p>Solicitud inválida.</p>'; include VIEW_PATH.'/partials/footer.php'; exit; }
// Permitir reuso del archivo si venimos de la elección de modo
if(!empty($_POST['_reprocess']) && !empty($_POST['_src']) && !empty($_POST['_name'])){
  $origName = $_POST['_name'];
  $dest = TEMP_PATH.'/'.basename($_POST['_src']);
  $ext = strtolower(pathinfo($dest,PATHINFO_EXTENSION));
  $uid = preg_replace('/\.[^.]+$/','', basename($dest));
} else {
  if(empty($_FILES['documento']['name'])){ add_error('No se subió archivo'); echo '<p>Falta archivo.</p>'; include VIEW_PATH.'/partials/footer.php'; exit; }
  if(!validate_upload($_FILES['documento'])){ echo '<p>Error validación archivo.</p>'; include VIEW_PATH.'/partials/footer.php'; exit; }

  $origName = $_FILES['documento']['name'];
  $tmpName = $_FILES['documento']['tmp_name'];
  $ext = strtolower(pathinfo($origName,PATHINFO_EXTENSION));
  $allowed = ['pdf','docx','txt'];
  if(!in_array($ext,$allowed)) { add_error('Tipo de archivo no permitido'); echo 'Tipo no permitido'; include VIEW_PATH.'/partials/footer.php'; exit; }

  $uid = uniqid('job_');
  $dest = TEMP_PATH . "/$uid.$ext";
  if(!move_uploaded_file($tmpName,$dest)) { add_error('Error moviendo archivo'); echo 'Error moviendo'; include VIEW_PATH.'/partials/footer.php'; exit; }
}

// Extracción SYNCHRONOUS ligera (solo texto base) - sin IA
$text='';
try {
  if($ext==='txt') $text=file_get_contents($dest);
  elseif($ext==='pdf' && class_exists('Smalot\\PdfParser\\Parser')){ $cls='Smalot\\PdfParser\\Parser'; $parser=new $cls(); $pdf=$parser->parseFile($dest); $text=$pdf->getText(); }
  elseif($ext==='docx' && class_exists('PhpOffice\\PhpWord\\IOFactory')){ $io='PhpOffice\\PhpWord\\IOFactory'; $phpWord=$io::load($dest,'Word2007'); foreach($phpWord->getSections() as $section){ foreach($section->getElements() as $e){ if(method_exists($e,'getText')) $text.=$e->getText()."\n"; } } }
  else $text=file_get_contents($dest);
} catch(Throwable $t){ add_error('Extracción fallback: '.$t->getMessage()); $text=file_get_contents($dest); }

$formato = Parser::detectFormat($text);
$bloques = Parser::splitQuestions($text);
$jobSecret = bin2hex(random_bytes(8));
$modelo = trim($_POST['model'] ?? '') ?: env('DEFAULT_MODEL','ollama:llama3:latest');
if(!$modelo){ $modelo = 'ollama:llama3:latest'; }
$modeChoice = $_POST['mode_choice'] ?? null;
if(!$modeChoice){ $modeEnv=env('DEFAULT_OPTIONS_MODE',''); if($modeEnv==='minimal'||$modeEnv==='full') $modeChoice=$modeEnv; }
if(!$modeChoice && in_array($formato,['PREG_OPC','PREG_OPC_RESP'])){
  // Mostrar pantalla para elegir modo antes de crear el job
  echo '<h1>Responder Documento</h1>';
  echo '<p>Se detectaron preguntas con opciones. ¿Cómo quieres el resultado?</p>';
  echo '<form method="post" action="/simul/web/responder_procesar.php" enctype="multipart/form-data">';
  csrf_field();
  echo '<input type="hidden" name="model" value="'.htmlspecialchars($modelo).'" />';
  // reenviar el archivo original guardado en temp
  echo '<input type="hidden" name="_reprocess" value="1" />';
  echo '<input type="hidden" name="_src" value="'.htmlspecialchars(basename($dest)).'" />';
  echo '<input type="hidden" name="_name" value="'.htmlspecialchars($origName).'" />';
  echo '<div style="display:flex;gap:.5rem;flex-wrap:wrap;">';
  echo '<button class="btn" name="mode_choice" value="full" type="submit">Completo (todas las opciones)</button>';
  echo '<button class="btn secondary" name="mode_choice" value="minimal" type="submit">Minimal (solo correcta)</button>';
  echo '</div>';
  echo '<p style="margin-top:1rem;"><a class="btn secondary" href="responder.php">Volver</a></p>';
  include VIEW_PATH.'/partials/footer.php';
  exit;
}

$job = [
  'id'=>$uid,
  'secret'=>$jobSecret,
  'ext'=>$ext,
  'source'=>basename($dest),
  'original_name'=>$origName,
  'modelo'=>$modelo,
  'mode'=> $modeChoice ?: 'full',
  'auto'=> (env('AI_AUTO_DETECT','1')==='1'),
  'formato'=>$formato,
  'bloques'=>$bloques,
  'total'=>count($bloques),
  'processed'=>0,
  'respuestas'=>[],
  'status'=>'running',
  'output'=>null
];
file_put_contents(TEMP_PATH."/$uid.job.json", json_encode($job,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
?>
<h1>Procesando Documento</h1>
<p>Archivo: <?php echo htmlspecialchars($origName); ?> | Preguntas detectadas: <?php echo count($bloques); ?> | Formato: <?php echo htmlspecialchars($formato); ?> | Modelo: <?php echo htmlspecialchars($modelo); ?></p>
<div class="progress-wrapper"><div class="progress-bar" id="jobBar"></div></div>
<div id="jobStatus" style="margin-top:.5rem;font-size:.85rem;opacity:.8;">Iniciando...</div>
<div id="downloadSection" class="hidden" style="margin-top:1rem;display:flex;gap:1rem;flex-wrap:wrap;"></div>
<?php if(in_array($formato,['PREG_OPC','PREG_OPC_RESP']) && !$modeChoice): ?>
<div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
  <span style="font-size:.8rem;opacity:.75;">Modo opciones:</span>
  <button type="button" class="btn" id="modeFull">Completo (todas las opciones)</button>
  <button type="button" class="btn secondary" id="modeMinimal">Minimal (solo correcta)</button>
</div>
<?php endif; ?>
<div style="margin-top:2rem;display:flex;gap:.8rem;flex-wrap:wrap;">
  <a class="btn secondary" href="/simul/web/responder.php">Volver</a>
  <a class="btn secondary" href="/simul/index.php">Inicio</a>
  <button type="button" class="btn danger" id="cancelBtn">Cancelar</button>
</div>
<script>
const jobId = <?php echo json_encode($uid); ?>;
const jobSecret = <?php echo json_encode($jobSecret); ?>;
let outputMode = <?php echo json_encode($modeChoice ? $modeChoice : (in_array($formato,['PREG_OPC','PREG_OPC_RESP']) ? 'full' : 'minimal')); ?>;
const autoBrowserPdf = <?php echo json_encode(env('AUTO_BROWSER_PDF','1')==='1'); ?>;
<?php
  $localPdf = dirname(__DIR__).'//public/js/vendor/pdfmake/pdfmake.min.js';
  $localVfs = dirname(__DIR__).'//public/js/vendor/pdfmake/vfs_fonts.min.js';
  $hasLocal = is_file($localPdf) && is_file($localVfs);
  $useLocal = ($hasLocal && env('PDFMAKE_LOCAL_FIRST','1')==='1');
?>
const useLocalPdfMake = <?php echo json_encode($useLocal); ?>;
const btnFull=document.getElementById('modeFull');
const btnMin=document.getElementById('modeMinimal');
if(btnFull&&btnMin){
  btnFull.addEventListener('click',()=>{outputMode='full';btnFull.classList.remove('secondary');btnMin.classList.add('secondary');});
  btnMin.addEventListener('click',()=>{outputMode='minimal';btnMin.classList.remove('secondary');btnFull.classList.add('secondary');});
}
let cancelled=false;
document.getElementById('cancelBtn')?.addEventListener('click', async ()=>{
  if(!confirm('¿Cancelar procesamiento?')) return;
  cancelled=true;
  document.getElementById('jobStatus').textContent='Cancelando...';
  try{ await fetch('/simul/web/responder_job.php?id='+jobId+'&s='+jobSecret+'&cancel=1'); }catch(e){}
  setTimeout(()=>{ window.location='/simul/index.php'; },600);
});
if(typeof window.loadScriptOnce !== 'function'){
  window.loadScriptOnce = function(src){
    return new Promise((resolve,reject)=>{
      const sel = 'script[data-once="'+src+'"]';
      const prev = document.querySelector(sel);
      if(prev && prev.__loaded){ return resolve(); }
      const s=document.createElement('script');
      s.src=src; s.async=true; s.defer=true; s.setAttribute('data-once',src);
      s.onload=()=>{ s.__loaded=true; resolve(); };
      s.onerror=()=>reject(new Error('Fallo al cargar '+src));
      document.head.appendChild(s);
    });
  }
}
async function pollJob(){
  try {
  const r = await fetch('/simul/web/responder_job.php?id='+jobId+'&s='+jobSecret+'&mode='+outputMode);
    const j = await r.json();
    if(j.error){ document.getElementById('jobStatus').textContent = 'Error: '+j.error; return; }
    const pct = j.total? Math.round((j.processed/j.total)*100):0;
    const bar = document.getElementById('jobBar'); if(bar) bar.style.width = pct+'%';
  document.getElementById('jobStatus').textContent = 'Procesadas '+j.processed+' / '+j.total+' ('+pct+'%)';
  if(j.status==='done'){
       const ds=document.getElementById('downloadSection');
       ds.classList.remove('hidden');
       ds.innerHTML='';
       const btn = document.createElement('button');
       btn.className='btn';
       btn.textContent='Descargar PDF';
       btn.addEventListener('click', async ()=>{
         try{
           const htmlFile = (j.files||[]).find(name=>/\.html$/i.test(name));
           const target = htmlFile ? '/simul/web/descargar.php?f='+encodeURIComponent(htmlFile)+'&o='+encodeURIComponent(j.source) : null;
           let rawHtml='';
           if(target){
             const resp = await fetch(target);
             rawHtml = await resp.text();
           } else {
             const txtFile = (j.files||[]).find(name=>/\.txt$/i.test(name));
             if(!txtFile) throw new Error('No hay contenido para generar PDF');
             const resp = await fetch('/simul/web/descargar.php?f='+encodeURIComponent(txtFile)+'&o='+encodeURIComponent(j.source));
             const txt = await resp.text();
             rawHtml = '<!DOCTYPE html><html><head><meta charset="utf-8" /><title>Resultado</title></head><body><pre style="white-space:pre-wrap;font-family:Arial,Helvetica,sans-serif;">'+
                       txt.replace(/[&<>]/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[s]))+
                       '</pre></body></html>';
           }
           let inner = rawHtml;
           const m = rawHtml.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
           if(m) inner = m[1];
            try{
              const localPdfMake = '/simul/public/js/vendor/pdfmake/pdfmake.min.js';
              const localVfs = '/simul/public/js/vendor/pdfmake/vfs_fonts.min.js';
              const cdn1PdfMake = 'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js';
              const cdn1Vfs = 'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.min.js';
              const cdn2PdfMake = 'https://cdn.jsdelivr.net/npm/pdfmake@0.2.7/build/pdfmake.min.js';
              const cdn2Vfs = 'https://cdn.jsdelivr.net/npm/pdfmake@0.2.7/build/vfs_fonts.min.js';

              if(useLocalPdfMake){
                try{
                  await loadScriptOnce(localPdfMake);
                  await loadScriptOnce(localVfs);
                }catch(_local){
                  try{
                    await loadScriptOnce(cdn1PdfMake);
                    await loadScriptOnce(cdn1Vfs);
                  }catch(_cdn1){
                    await loadScriptOnce(cdn2PdfMake);
                    await loadScriptOnce(cdn2Vfs);
                  }
                }
              } else {
                try{
                  await loadScriptOnce(cdn1PdfMake);
                  await loadScriptOnce(cdn1Vfs);
                }catch(_cdn1){
                  await loadScriptOnce(cdn2PdfMake);
                  await loadScriptOnce(cdn2Vfs);
                }
              }
              const parser = new DOMParser();
              const doc = parser.parseFromString('<div>'+inner+'</div>','text/html');
              const container = doc.body.firstElementChild;
              const content = [{ text: 'Resultado', style: 'header', margin: [0,0,0,8] }];
              const items = container.querySelectorAll('.item');
              if(items.length){
                items.forEach((el)=>{
                  const q = el.querySelector('.q');
                  const opts = el.querySelector('.opts');
                  const ans = el.querySelector('.ans');
                  let title = (q?.querySelector('strong')?.textContent || 'Pregunta');
                  let qBody = '';
                  if(q){
                    const clone = q.cloneNode(true);
                    const st = clone.querySelector('strong'); if(st) st.remove();
                    qBody = clone.textContent.replace(/^[\s:\-–]+/,'').trim();
                  }
                  const optsText = (opts?.textContent || '').replace(/^\s*Opciones\s*:\s*/i,'').trim();
                  const ansLabel = (ans?.querySelector('strong')?.textContent || 'Respuesta correcta:').trim();
                  const ansText = (ans?.textContent || '').replace(/^\s*Respuesta\s*(?:correcta)?\s*:\s*/i,'').trim();
                  const stack = [ { text: title, bold: true } ];
                  if(qBody) stack.push({ text: qBody });
                  if(optsText){
                    stack.push({ text: 'Opciones:', bold: true });
                    stack.push({ text: optsText });
                  }
                  stack.push({ text: ansLabel, bold: true });
                  stack.push({ text: ansText });
                  content.push({ stack, margin: [0,0,0,8] });
                });
              } else {
                const plain = container.textContent.replace(/\n\s*\n+/g,'\n').trim();
                content.push({ text: plain });
              }
              const dd = { pageSize: 'A4', pageMargins: [25,25,25,25], defaultStyle: { fontSize: 11 }, styles: { header: { fontSize: 18, bold: true } }, content };
              const filename = (j.source? j.source.replace(/\.[^.]+$/,'') : 'resultado') + '.pdf';
              // @ts-ignore
              pdfMake.createPdf(dd).download(filename);
              return;
            } catch(_e) {
              const holder = document.createElement('div');
              holder.style.position='fixed'; holder.style.left='-9999px'; holder.style.top='-9999px';
              holder.innerHTML = '<div id="printableContent" style="color:#000;background:#fff">'+inner.replace('<div class="toolbar"','<div class="toolbar" style="display:none"')+'</div>';
              document.body.appendChild(holder);
              await loadScriptOnce('https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js');
              const opt = {
                margin:       10,
                filename:     (j.source? j.source.replace(/\.[^.]+$/,'') : 'resultado') + '.pdf',
                image:        { type: 'jpeg', quality: 0.95 },
                html2canvas:  { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
              };
              await html2pdf().set(opt).from(holder.querySelector('#printableContent')).save();
              holder.remove();
            }
         }catch(e){
           window.showErrorModal('No se pudo generar el PDF en el navegador: '+(e&&e.message?e.message:e));
         }
       });
       ds.appendChild(btn);
  j.files.filter(name=>!/\.html$/i.test(name)).forEach(f=>{ const a=document.createElement('a'); a.className='btn secondary'; a.href='/simul/web/descargar.php?f='+encodeURIComponent(f)+'&o='+encodeURIComponent(j.source); a.textContent='Descargar '+f.split('.').pop().toUpperCase(); ds.appendChild(a); });
       if(autoBrowserPdf){ setTimeout(()=>btn.click(), 200); }
       return;
    }
  if(j.status==='cancelled'){ document.getElementById('jobStatus').textContent='Cancelado'; return; }
  if(j.rate_wait_ms && Number.isFinite(j.rate_wait_ms)){
    setTimeout(pollJob, Math.max(800, j.rate_wait_ms));
    return;
  }
  const remaining = j.total - j.processed;
  const delay = remaining>10 ? 900 : 1400;
  setTimeout(pollJob, delay);
  } catch(e){ if(!cancelled){ document.getElementById('jobStatus').textContent='Error conexión'; setTimeout(pollJob,2000);} }
}
pollJob();
</script>
<?php include VIEW_PATH.'/partials/footer.php'; ?>
