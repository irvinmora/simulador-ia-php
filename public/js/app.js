window.addEventListener('DOMContentLoaded',()=>{
  const modelSelect = document.querySelector('#modelSelect');
  if(modelSelect){
    // Filtrar opciones según availability publicado por PHP
    try{
      const allowed = Array.isArray(window.AVAILABLE_MODELS) ? window.AVAILABLE_MODELS : [];
      if(allowed.length>0){
        // Eliminar opciones no permitidas (sin API key)
        Array.from(modelSelect.options).forEach(opt=>{
          if(!allowed.includes(opt.value)) opt.remove();
        });
      }
    }catch(_e){}
  // Si el texto de la opción está vacío por alguna razón, usar value como etiqueta
  Array.from(modelSelect.options).forEach(opt=>{ if(!opt.text || !opt.text.trim()){ opt.text = opt.value; } });

    modelSelect.addEventListener('change',()=>{
      localStorage.setItem('llm_model',modelSelect.value);
      document.querySelectorAll('.current-model').forEach(e=>e.textContent=modelSelect.value);
      // Sincronizar inputs ocultos que se envían en formularios
      document.querySelectorAll('input[name="model"],#modelField').forEach(inp=>{ inp.value=modelSelect.value; });
    });
    const stored = localStorage.getItem('llm_model');
    // Validar que el almacenado sea permitido; si no, usar default o primera opción
  const allowed = Array.isArray(window.AVAILABLE_MODELS) ? window.AVAILABLE_MODELS : [];
  let def = (typeof window.DEFAULT_MODEL==='string' && window.DEFAULT_MODEL) ? window.DEFAULT_MODEL : (modelSelect.options[0]?.value || '');
  if(allowed.length && !allowed.includes(def)) def = (modelSelect.options[0]?.value || '');
  const initial = (stored && (!allowed.length || allowed.includes(stored))) ? stored : def;
    if(initial){
      modelSelect.value=initial;
      localStorage.setItem('llm_model', initial);
      document.querySelectorAll('.current-model').forEach(e=>e.textContent=initial);
      document.querySelectorAll('input[name="model"],#modelField').forEach(inp=>{ inp.value=initial; });
    }
  }
});

window.showErrorModal = function(msg){
  const wrap = document.createElement('div');
  wrap.className='modal-backdrop';
  wrap.innerHTML=`<div class="modal"><button class="close-btn" onclick="this.closest('.modal-backdrop').remove()">✕</button><h2>Error</h2><div class='alert-error'>${msg}</div></div>`;
  document.body.appendChild(wrap);
}

function simulateProgress(cb){
  const bar = document.querySelector('.progress-bar');
  if(!bar){ cb&&cb(); return; }
  let p=0; const int=setInterval(()=>{
    p+=Math.random()*15; if(p>=100){p=100; clearInterval(int); cb&&cb();}
    if(bar) bar.style.width=p+'%';
  },400);
}
window.startFakeProcessing = function(){
  simulateProgress(()=>{
    const ds=document.querySelector('#downloadSection');
    if(ds) ds.classList.remove('hidden');
  });
}

// Carga un script externo una sola vez
window.loadScriptOnce = function(src){
  return new Promise((resolve,reject)=>{
    const prev = document.querySelector('script[data-once="'+src+'"]');
    if(prev){
      if(prev.__loaded){ return resolve(); }
      // ya existe pero no cargó aún: enganchar callbacks
      prev.addEventListener('load', ()=>resolve(), { once:true });
      prev.addEventListener('error', ()=>reject(new Error('Fallo al cargar '+src)), { once:true });
      return;
    }
    const s=document.createElement('script');
    s.src=src; s.async=true; s.defer=true; s.setAttribute('data-once',src);
    s.onload=()=>{ s.__loaded=true; resolve(); };
    s.onerror=()=>reject(new Error('Fallo al cargar '+src));
    document.head.appendChild(s);
  });
}
