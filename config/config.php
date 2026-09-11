<?php
// config/config.php
// Configuración global: entorno, sesión, cabeceras de seguridad, conexión a la base y los
// helpers que usan todas las pantallas. Es el PRIMER archivo que carga cualquier request.
//
// Adaptado del sistema de bodega (proyecto_bogeda). Lo que cambia respecto de aquel:
//   · el nombre de la base y de BASE_URL,
//   · NOMBRE_SISTEMA, que allá estaba escrito a mano en el <title> de cada vista,
//   · no hay pantalla de selección de perfil, así que todo lo que "vuelve al login" apunta
//     directo al formulario (URL_LOGIN).

// Entorno de la aplicación: en el servidor de producción, definir la variable de entorno
// APP_ENV=production (Apache SetEnv, panel de hosting, o Docker), sin tocar este archivo.
// Si no está definida, cae de vuelta a 'development' para no romper el entorno local (XAMPP).
define('APP_ENV', getenv('APP_ENV') ?: 'development');

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/php-error.log');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Nombre visible del sistema. Se imprime en el <title>, en el panel del login y en el sidebar.
// Está acá y no escrito en cada vista para que cambiarlo sea tocar UNA línea: en el sistema de
// bodega el texto estaba repetido en cada archivo y quedaron títulos que no coinciden entre sí.
define('NOMBRE_SISTEMA', 'Sistema Monterojo');

// Cookies de sesión seguras. Deben configurarse ANTES de la primera llamada a session_start()
// en toda la aplicación; por eso viven aquí, en el primer archivo que todos los módulos cargan.
if (session_status() === PHP_SESSION_NONE) {
    $conexionEsSegura = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // En producción, forzar la cookie segura sin depender de la heurística de arriba (que confía
    // en X-Forwarded-Proto, un header que un cliente podría falsear si el servidor PHP fuera
    // alcanzable directamente sin pasar por el proxy de confianza).
    if (APP_ENV === 'production') {
        $conexionEsSegura = true;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $conexionEsSegura, // true automáticamente cuando el sitio corre bajo HTTPS
        'httponly' => true,              // JavaScript no puede leer la cookie de sesión
        'samesite' => 'Strict',
    ]);
}

// Cabeceras de seguridad HTTP, aplicadas a toda respuesta (este archivo se incluye en cada request)
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data: https:; frame-ancestors 'none'");
if (!empty($conexionEsSegura)) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}

// Protección CSRF (token de formulario), disponible en toda la aplicación
require_once __DIR__ . '/csrf.php';

// Configuración de la base de datos. En producción, definir las variables de entorno
// DB_HOST/DB_NAME/DB_USER/DB_PASS para no dejar credenciales reales en el repositorio; si no
// están definidas, cae de vuelta a los valores de desarrollo local (XAMPP).
//
// DB_NAME también sale del entorno porque en un hosting compartido el nombre de la base no lo
// elige uno: viene con el prefijo de la cuenta (u123456_monterojo). Sin esto habría que editar
// este archivo en el servidor después de cada despliegue.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'proyecto_monterojo');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Ruta de la aplicación DENTRO del dominio, sin barra al final. La usan los enlaces, el action de
// los formularios, las rutas de las imágenes y las redirecciones.
//
// En este XAMPP el proyecto vive en htdocs/proyecto_monterojo, así que la ruta es
// '/proyecto_monterojo'. En un servidor de verdad la aplicación casi siempre cuelga del dominio
// (https://elsitio.com/), y ahí el valor correcto es la cadena VACÍA. Si se dejara el valor de
// desarrollo, cada enlace, cada formulario y cada hoja de estilo apuntarían a
// /proyecto_monterojo/... que no existe.
//
// Se define con la variable de entorno BASE_URL:
//   · sin definir            → '/proyecto_monterojo' (desarrollo local, no hay que tocar nada)
//   · BASE_URL=""            → la aplicación está en la raíz del dominio
//   · BASE_URL="/monterojo"  → está en una subcarpeta
//
// OJO con el ?: que a propósito NO se usa acá abajo: la cadena vacía es un valor VÁLIDO (es
// justamente el caso "raíz del dominio") y ?: la trataría como "no definida", devolviendo el
// valor de desarrollo en el peor momento posible. Por eso se compara contra false, que es lo que
// getenv() devuelve cuando la variable realmente no existe.
function normalizarBaseUrl($valor) {
    $valor = trim((string) $valor);
    if ($valor === '' || $valor === '/') {
        return '';
    }
    // Una sola barra al principio y ninguna al final, escriban '/monterojo', 'monterojo' o '/monterojo/'
    return '/' . trim($valor, '/');
}

$baseUrlEntorno = getenv('BASE_URL');
define('BASE_URL', normalizarBaseUrl($baseUrlEntorno === false ? '/proyecto_monterojo' : $baseUrlEntorno));

// La pantalla de entrada. En el sistema de bodega hay DOS (una portada para elegir perfil y
// después el formulario), y cada redirección tiene que acordarse de a cuál de las dos manda.
// Acá el login es una sola pantalla, y esta constante es la única que la nombra.
define('URL_LOGIN', BASE_URL . '/modules/login/auth.php');

// Cierre de sesión automático por inactividad
define('TIEMPO_INACTIVIDAD_SEGUNDOS', 600); // 10 minutos

// ── Etiquetadora de rótulos ──────────────────────────────────────────────────────────────────
// La impresora de etiquetas NO recibe una página: recibe comandos TSPL que ella
// misma dibuja. Todo lo de acá abajo son los parámetros de ESE trabajo de impresión; están juntos
// y con nombre para que cambiar de impresora, de rollo o de sentido no sea tocar código.
// Ver modules/historial/helper_rotulos_tspl.php para cómo se arma el trabajo.

// El tamaño FÍSICO de la etiqueta que hay puesta en la impresora, en milímetros. Es de lo único
// que dependen la maqueta del rótulo, la página del PDF y la vista previa en pantalla: las tres
// se calculan a partir de estos dos números, así que cambiar de rollo es cambiarlos acá y nada
// más. (El 2026-09-08 el rollo era de 100x40; el 2026-09-11 se pasó a 100x100, que es el que
// permitió volver al diseño con logo y con cada campo en su renglón.)
define('ROTULO_ANCHO_MM', 100);
define('ROTULO_ALTO_MM', 100);

// El área que se DIBUJA, centrada dentro del sticker. Es más chica a propósito: con el rótulo
// ocupando los 100mm exactos, el marco quedaba pegado al filo del papel y la primera impresión
// salió con el recuadro casi tocando las esquinas. Dibujando 95x95 quedan 2,5mm de aire por lado,
// que además absorben el pequeño corrimiento lateral que tiene el avance del rollo.
//
// OJO: esto NO reemplaza a ROTULO_ANCHO_MM / ROTULO_ALTO_MM. Esos dos siguen siendo la medida
// FÍSICA del sticker y son los que la impresora usa para saber cuánto papel avanzar entre una
// etiqueta y la siguiente; si se los tocara para "achicar el rótulo", el rollo se iría corriendo
// un poco en cada etiqueta hasta desalinearse del todo.
define('ROTULO_DIBUJO_ANCHO_MM', 95);
define('ROTULO_DIBUJO_ALTO_MM', 95);

// El nombre EXACTO con el que la impresora aparece en Windows (Configuración > Impresoras).
// Si no coincide, el sistema lo avisa en pantalla en vez de fallar en silencio.
//
// Tiene que ser una impresora que hable TSPL y sea de 203 dpi, que es la resolución sobre la que
// está calculada toda la maqueta del rótulo (8 puntos por milímetro, ver TSPL_PUNTOS_POR_MM en
// modules/historial/helper_rotulos_tspl.php). Con una de 300 dpi la etiqueta saldría a dos tercios
// del tamaño, encogida contra la esquina superior izquierda.
define('IMPRESORA_ROTULOS', getenv('IMPRESORA_ROTULOS') ?: 'TSC TA210');

// Sentido en el que sale la etiqueta. Esto es lo que arregla el "sale al revés": si el texto sale
// cabeza abajo, cambiar 1 por 0 (o al revés) y volver a imprimir. No hace falta tocar nada más.
define('ROTULO_TSPL_DIRECCION', 1);

// Separación entre una etiqueta y la siguiente en el rollo, en milímetros. El valor típico de un
// rollo troquelado es 2mm; si la impresora saca etiquetas de más o corta a destiempo, este es el
// número a revisar (y conviene recalibrar el sensor desde TSC Console).
define('ROTULO_TSPL_GAP_MM', 2);

// Qué tan oscuro imprime (0 a 15) y a qué velocidad (pulgadas por segundo). Más densidad = más
// negro pero barras más gordas; si el lector no engancha el código de barras, bajar la velocidad
// antes que subir la densidad.
define('ROTULO_TSPL_DENSIDAD', 8);
define('ROTULO_TSPL_VELOCIDAD', 4);

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";

    $opciones = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,        // Lanzar excepciones en errores
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,   // Devolver arrays asociativos
        PDO::ATTR_EMULATE_PREPARES => false,                // Usar sentencias reales
    ];

    $conexion = new PDO($dsn, DB_USER, DB_PASS, $opciones);
    $pdo = $conexion;

} catch (PDOException $e) {
    // No exponer el detalle de la excepción (puede incluir host/usuario/nombre de BD) al usuario final
    error_log("Error de conexión a la base de datos: " . $e->getMessage());
    die("Error de conexión a la base de datos. Por favor, contacta al administrador del sistema.");
}

// Ruta absoluta en disco a la raíz del proyecto (la carpeta que contiene /assets, /modules,
// /config, /public), para armar rutas sin calcular un __DIR__ distinto por archivo según cuán
// anidado esté.
define('ROOT_PATH', dirname(__DIR__));

// Parámetro de versión (?v=fecha de modificación) para añadir a los <script src="..."> y
// <link rel="stylesheet" href="...">. Sin esto, el navegador puede seguir sirviendo una versión
// en caché después de actualizar el archivo y un cambio nuevo parece "no existir" aunque el
// código ya esté desplegado. $rutaAbsolutaArchivo es la ruta EN DISCO, no la URL.
function assetVersion($rutaAbsolutaArchivo) {
    return file_exists($rutaAbsolutaArchivo) ? filemtime($rutaAbsolutaArchivo) : time();
}

// URL de la foto de perfil que se muestra en el pie del sidebar.
// $imagenBD es lo que hay guardado en usuarios.imagen_url_Usuario (normalmente vía
// $_SESSION['usuario_imagen']): puede venir vacío, como URL absoluta, como ruta que arranca en /,
// o como ruta relativa a la raíz del proyecto.
function rutaImagenPerfil($imagenBD) {
    if (empty($imagenBD)) {
        return BASE_URL . '/assets/img/avatar_por_defecto.svg';
    }
    // Absoluta (http/https) o ya empieza en la raíz del sitio: se deja intacta
    if (strpos($imagenBD, 'http') === 0 || strpos($imagenBD, '/') === 0) {
        return $imagenBD;
    }
    return BASE_URL . '/' . ltrim($imagenBD, '/');
}

// URL de una imagen de marca, o null si ese archivo todavía no está en disco.
//
// POR QUÉ NO SE DEVUELVE LA URL A SECAS
// Las fotos de producto y de campaña de Monterojo se van agregando a mano en assets/img/. Un
// <img> apuntando a un archivo que no existe muestra el icono de imagen rota, que se ve peor que
// no poner nada: parece que el sistema falló, cuando lo único que pasa es que falta subir la
// foto. Con esta función, cada pantalla puede preguntar primero y mostrar su alternativa —
// normalmente el logo sobre negro, que es la identidad de la marca igual.
//
// $rutaRelativa va desde assets/img/ (por ejemplo 'fondo.jpg' o 'sabores/lima_limon.jpg').
function imagenDeMarca($rutaRelativa) {
    $enDisco = ROOT_PATH . '/assets/img/' . ltrim($rutaRelativa, '/');
    if (!is_file($enDisco)) {
        return null;
    }
    return BASE_URL . '/assets/img/' . ltrim($rutaRelativa, '/') . '?v=' . assetVersion($enDisco);
}

// Imprime los atributos de la etiqueta <body>: la clase que activa la foto de fondo y la variable
// CSS con su ruta.
//
// Está como función porque lo necesitan TODAS las pantallas. Copiado en cada vista, el día que se
// cambie el nombre del archivo de fondo habría que acordarse de las seis — y la que se olvide
// queda con un fondo distinto sin que nada falle de forma visible.
//
// $clasesExtra son las clases propias de esa pantalla (por ejemplo 'pantalla-login').
function atributosDelCuerpo($clasesExtra = '') {
    $fondo  = imagenDeMarca('fondo.jpg');
    $clases = trim($clasesExtra . ($fondo ? ' con-fondo' : ''));

    $atributos = $clases !== '' ? ' class="' . htmlspecialchars($clases, ENT_QUOTES, 'UTF-8') . '"' : '';

    // La ruta va como variable CSS y solo cuando el archivo existe: un url() apuntando a la nada
    // el navegador lo pide igual y devuelve un 404 en cada carga de cada pantalla.
    if ($fondo) {
        $atributos .= ' style="--foto-fondo: url(\'' . htmlspecialchars($fondo, ENT_QUOTES, 'UTF-8') . '\');"';
    }

    echo $atributos;
}

// Destruye por completo la sesión actual (variables, cookie y datos en el servidor),
// igual que hace modules/login/controller/logout.php.
function destruirSesionActual() {
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_unset();
    session_destroy();
}

// Verifica si hay una sesión de usuario iniciada y aún vigente (no vencida por inactividad).
// Si estaba vencida, la destruye por completo antes de devolver false, para que el llamador
// simplemente trate el resultado como "no hay sesión". Si sigue vigente, renueva la marca de
// tiempo de última actividad.
function sesionUsuarioActiva() {
    if (!isset($_SESSION['usuario_id'])) {
        return false;
    }

    if (isset($_SESSION['ultima_actividad']) && (time() - $_SESSION['ultima_actividad']) > TIEMPO_INACTIVIDAD_SEGUNDOS) {
        destruirSesionActual();
        return false;
    }

    $_SESSION['ultima_actividad'] = time();
    return true;
}

// A dónde va un usuario recién autenticado. Hoy hay UN solo panel para todos los roles: lo que
// cambia entre un rol y otro son los módulos que le aparecen en el sidebar, no la pantalla de
// inicio. Está como función y no como constante para que, el día que un rol necesite su propio
// panel, ese if viva únicamente acá y no repartido por el login, el guardián y el sidebar —que
// es exactamente el problema del sistema de bodega, donde la misma lista de roles está copiada
// en cuatro archivos y desincronizarla produce un bucle infinito de redirecciones.
function urlPanelDelRol($idRol = null) {
    return BASE_URL . '/modules/inicio/dashboard.php';
}
