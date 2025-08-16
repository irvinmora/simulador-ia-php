<h1>Simulador</h1>
<form method="post" action="/simul/web/simulador_run.php" enctype="multipart/form-data" id="simForm">
  <?php csrf_field(); ?>
  <div class="form-group">
    <label>Documento (PDF / DOCX / TXT)</label>
    <input type="file" name="documento" id="docInput" required />
  </div>
  <div class="form-group">
    <label>Cantidad de preguntas a practicar (1 hasta el máximo detectado)</label>
    <input type="number" name="cantidad" id="cantidadInput" min="1" value="5" />
    <small id="detectedInfo"></small>
  </div>
  <input type="hidden" name="model" id="modelField" />
  <button class="btn" type="submit">Iniciar Simulación</button>
  <script>document.getElementById('modelField').value=localStorage.getItem('llm_model')||'';</script>
  <script>
  (function(){
    const docInput=document.getElementById('docInput');
    const cantidad=document.getElementById('cantidadInput');
    const info=document.getElementById('detectedInfo');
    const form=document.getElementById('simForm');
    const overlay=document.createElement('div');
    overlay.style.position='fixed'; overlay.style.inset='0'; overlay.style.background='rgba(255,255,255,.85)'; overlay.style.display='none'; overlay.style.zIndex='9999'; overlay.style.alignItems='center'; overlay.style.justifyContent='center';
    overlay.innerHTML='<div style="text-align:center;max-width:420px;padding:16px;border:1px solid #eee;border-radius:10px;background:#fff;box-shadow:0 6px 30px rgba(0,0,0,.1)"><div style="font-weight:700;margin-bottom:8px">Preparando simulación…</div><div style="height:10px;background:#eee;border-radius:6px;overflow:hidden"><div id="pbInner" style="height:100%;width:10%;background:#4f46e5;transition:width .3s"></div></div><div style="margin-top:8px;color:#444;font-size:13px">Esto puede tardar unos segundos por los límites del modelo. No cierres la página.</div></div>';
    document.body.appendChild(overlay);
    let timer; let p=10;
    function startProgress(){ p=10; const inner=overlay.querySelector('#pbInner'); inner.style.width=p+'%'; overlay.style.display='flex'; timer=setInterval(()=>{ p=Math.min(98, p + (Math.random()*6)); inner.style.width=p+'%'; }, 700); }
    function stopProgress(){ clearInterval(timer); const inner=overlay.querySelector('#pbInner'); inner.style.width='100%'; setTimeout(()=>{ overlay.style.display='none'; }, 300); }
    form.addEventListener('submit', ()=>{ startProgress(); const sbm = form.querySelector('button[type="submit"]'); if(sbm) sbm.disabled=true; });
    docInput.addEventListener('change', async ()=>{
      if(!docInput.files || !docInput.files[0]) return;
      const fd=new FormData();
      fd.append('documento', docInput.files[0]);
      fd.append('_csrf', (document.querySelector('input[name=_csrf]')||{}).value||'');
      info.textContent='Analizando documento…';
      try{
  const r=await fetch('/simul/web/simulador_analizar.php',{method:'POST', body:fd});
        const j=await r.json();
        if(j && j.ok){
          const total=j.total||0;
          const formato=j.formato||'-';
          cantidad.max=Math.max(1,total);
          if(parseInt(cantidad.value,10)>total){ cantidad.value=Math.max(1,total); }
          info.textContent='Detectadas '+total+' preguntas. Formato: '+formato+'.';
        } else {
          info.textContent='No se pudo analizar.';
        }
      }catch(e){ info.textContent='No se pudo analizar.'; }
    });
  })();
  </script>
</form>
