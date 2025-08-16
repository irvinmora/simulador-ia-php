<?php require_once __DIR__ . '/../../../config/config.php';
// Detectar disponibilidad por API keys
$haveOpenAI = (bool)env('OPENAI_API_KEY');
$haveGemini = (bool)env('GEMINI_API_KEY');
$haveClaude = (bool)(env('CLAUDE_API_KEY') ?: env('ANTHROPIC_API_KEY'));
// Descubrir modelos de Ollama instalados (opcional)
$ollamaModels = [];
if (env('ENABLE_OLLAMA', '1') === '1' && function_exists('curl_init')) {
  $ep = rtrim(env('OLLAMA_ENDPOINT', 'http://localhost:11434'), '/') . '/api/tags';
  try {
    $ch = curl_init($ep);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 1,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw && $code === 200) {
      $j = json_decode($raw, true);
      if (isset($j['models']) && is_array($j['models'])) {
        foreach ($j['models'] as $m) {
          $name = is_array($m) ? ($m['name'] ?? null) : null;
          if ($name && is_string($name)) $ollamaModels[] = $name; // p.ej. "llama3:latest"
        }
      }
    }
  } catch (\Throwable $e) { /* silencioso */
  }
}

$available = [];
if ($haveOpenAI) $available['gpt-4o'] = 'GPT';
if ($haveGemini) $available['gemini-flash'] = 'Gemini Flash';
if ($haveClaude) $available['claude-3.5-sonnet'] = 'Claude 3.5 Sonnet';
// Agregar modelos Ollama instalados; si no hay, no se muestran
foreach ($ollamaModels as $om) {
  $available['ollama:' . $om] = 'Ollama: ' . $om;
}
?>
<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Simulador IA</title>
  <link rel="stylesheet" href="/simul/public/css/styles.css" />
  <script src="/simul/public/js/app.js" defer></script>
  <script>
    // Publicar lista de modelos disponibles al frontend
    window.AVAILABLE_MODELS = <?php echo json_encode(array_keys($available)); ?>;
    window.DEFAULT_MODEL = <?php echo json_encode(env('DEFAULT_MODEL', 'claude-3.5-sonnet')); ?>;
  </script>
</head>

<body>
  <div class="app-wrapper">
    <header>
      <div class="container" style="display:flex;flex-direction:column;gap:.6rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
          <div class="header-brand">Simulador con IA </div>
          <nav style="display:flex;gap:.6rem;flex-wrap:wrap;">
            <a class="btn secondary" style="padding:.45rem .8rem;" href="/simul/index.php">Inicio</a>
            <a class="btn secondary" style="padding:.45rem .8rem;" href="/simul/web/responder.php">Responder</a>
            <a class="btn secondary" style="padding:.45rem .8rem;" href="/simul/web/simulador.php">Simulador</a>
          </nav>
          <div class="flex gap" style="align-items:center;">
            <label style="font-size:.8rem;opacity:.8;">Modelo:</label>
            <select id="modelSelect" class="select-model">
              <?php
              if ($available) {
                foreach ($available as $val => $label) {
                  echo '<option value="' . htmlspecialchars($val) . '">' . htmlspecialchars($label) . '</option>';
                }
              } else {
                echo '<option value="" disabled>(Configura API keys o instala modelos de Ollama)</option>';
              }
              ?>
            </select>
            <script>
              (function() {
                try {
                  var s = localStorage.getItem('llm_model');
                  if (s) {
                    var sel = document.getElementById('modelSelect');
                    if (sel) sel.value = s;
                  }
                } catch (e) {}
              })();
            </script>
          </div>
        </div>
      </div>
    </header>
    <main class="container">