# Simulador IA PHP

Proyecto final de aula para la materia Nuevas Tecnologías De 8"Vo"Semestre, de la carrera Ingenieria En Sistemas De Informacion, de la Universidad Tecnica de Babahoyo. Se trata de un proyecto MVC simple con front-controller (index.php) que permite procesar documentos y generar simuladores de preguntas utilizando múltiples proveedores de LLM como OpenAI, Gemini, Claude y Ollama local. Este proyecto fue realizado por:

Mora Julio

Mora Irvin

Herrera Wilson

Suarez Xiomara

## Requisitos
- PHP >= 8.1 con extensiones curl, mbstring, xml, zip
- Composer
- Servidor (XAMPP recomendado)
- Opcional: Ollama ejecutando `llama3`

## Instalación
```powershell
cd c:\xampp\htdocs\simul
composer install
```
Copiar `.env` y colocar las API keys.

## Configuración de XAMPP y php.ini (Windows)

Sigue estos pasos para evitar errores comunes (extensiones faltantes, límites de tamaño/tiempo, permisos):

1) XAMPP/Apache
- Instala XAMPP con PHP 8.1 o superior.
- Coloca el proyecto en `c:\xampp\htdocs\simul`.
- Inicia Apache desde XAMPP Control Panel y accede a `http://localhost/simul/`.
- Opcional (VirtualHost limpio): en `apache\conf\extra\httpd-vhosts.conf` agrega un vhost y en `C:\Windows\System32\drivers\etc\hosts` mapea el host. Asegúrate de que `httpd-vhosts.conf` esté incluido desde `httpd.conf` y que `AllowOverride All` esté activo si piensas usar `.htaccess`.

2) Permisos de escritura
- Asegura que Apache pueda escribir en:
	- `storage/logs/`
	- `storage/temp/`
	En Windows normalmente basta con que la carpeta no sea de solo lectura.

3) php.ini – extensiones requeridas
Edita `c:\xampp\php\php.ini` y verifica que estas líneas NO estén comentadas (sin `;` al inicio):
- extension=curl
- extension=mbstring
- extension=xml  (necesaria para DOM/libxml; DOCX es XML comprimido)
- extension=zip
- extension=fileinfo
- extension=openssl
Nota: aunque no uses Excel ni base de datos, XML/DOM es necesario porque el proyecto procesa DOCX/HTML. En algunas distribuciones de PHP, DOM y XML vienen separados; si ves estas entradas, habilítalas también: `extension=dom`, `extension=simplexml`, `extension=xmlreader`, `extension=xmlwriter`.

4) php.ini – ajustes recomendados
En el mismo `php.ini`, ajusta (valores sugeridos; puedes incrementarlos según el tamaño de tus archivos):
- date.timezone = America/Guayaquil
  (o tu zona horaria)
- file_uploads = On
- upload_max_filesize = 32M
- post_max_size = 32M
- memory_limit = 512M
- max_execution_time = 120
- max_input_time = 120
- allow_url_fopen = On

5) Logs de PHP (opcional pero útil)
- Habilita registro de errores para depurar:
	- log_errors = On
	- error_log = "c:/xampp/htdocs/simul/storage/logs/php_errors.log"
	Crea la carpeta si no existe y reinicia Apache.

6) Reinicio
- Tras modificar `php.ini`, reinicia Apache desde XAMPP Control Panel para aplicar cambios.

7) .htaccess (opcional)
- El proyecto funciona con `index.php` como front-controller. Si más adelante deseas URLs amigables, habilita `mod_rewrite` en Apache y usa un `.htaccess` apropiado. Este paso no es obligatorio para correr el proyecto.

### Variables de entorno

Ejemplo de `.env`:

```
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=ya29....
CLAUDE_API_KEY=anthropic-...
# Alternativa válida para Claude
# ANTHROPIC_API_KEY=anthropic-...

# Modelo por defecto opcional
DEFAULT_MODEL=claude-3.5-sonnet

# Fallback desactivado por defecto (dejar vacío)
FALLBACK_MODEL=
```

## Estructura
- index.php (router + menú principal)
- app/controllers (Responder, Simulador, Utility)
- app/views (home, responder, simulador, errors, partials)
- app/models (AIClient, Parser)
- public/css, public/js
- storage/temp (archivos temporales)

## Modelos soportados
GPT (OpenAI), Gemini Flash, Claude, Llama3 (Ollama).

- gpt-4o
- gemini-flash (normaliza a gemini-1.5-flash)
- claude-3.5-sonnet (normaliza a claude-3-5-sonnet-20240620)
	- Fallback automático a claude-3-haiku-20240307 si 3.5 no está disponible
- ollama:llama3:latest

## Notas
Características de Excel/CSV y Base de Datos han sido eliminadas del proyecto.

## Limpieza
Archivos procesados se eliminan al descargar; otros temporales se deben limpiar manualmente o con tarea programada.

## Seguridad
Añadir validaciones adicionales para producción (tamaños, sanitización, antivirus).
