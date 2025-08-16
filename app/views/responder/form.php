<h1>Responder Documento</h1>
<form method="post" action="/simul/web/responder_procesar.php" enctype="multipart/form-data" onsubmit="setTimeout(()=>startFakeProcessing&&startFakeProcessing(),300);">
  <?php csrf_field(); ?>
  <div class="form-group">
    <label>Documento (PDF / DOCX / TXT)</label>
    <input type="file" name="documento" accept="application/pdf,.pdf,.docx,text/plain" required />
  </div>
  <input type="hidden" name="model" id="modelField" />
  <button class="btn" type="submit">Procesar</button>
  <script>document.getElementById('modelField').value=localStorage.getItem('llm_model')||'';</script>
</form>
