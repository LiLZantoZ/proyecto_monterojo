<?php
// modules/historial/controller_rotulos_enlace.php
// El QR de los rótulos, para las cuatro pantallas que los muestran (ver assets/js/rotulo.js).
// Dos acciones:
//   · enlaces (POST, JSON) — el token de cada rótulo de un lote, en el mismo orden
//   · qr      (GET,  PNG)  — la imagen del QR de un token, para la vista previa
//
// POR QUÉ UN CONTROLADOR APARTE
// Hasta el 2026-09-14 estas dos acciones vivían en el controlador de Cajas por punto de venta, que
// exige el permiso de ese módulo. Al llevar el diseño nuevo del rótulo a Picking, Historial y
// Generar rótulos, un usuario con permiso para esas pantallas pero no para Cajas se habría
// quedado sin QR en la vista previa, sin ningún error que lo explicara. Acá alcanza con el permiso
// de cualquiera de las cuatro.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/helper_rotulos_lista.php';
require_once __DIR__ . '/helper_rotulos_enlace.php';

$puedeVerRotulos = tienePermiso('modulo_picking')
    || tienePermiso('modulo_historial')
    || tienePermiso('modulo_rotulos')
    || tienePermiso('modulo_cajas_punto_venta');

if (!$puedeVerRotulos) {
    // 403 y no una redirección: lo piden un <img> y un fetch(), y a ninguno de los dos le sirve
    // recibir el HTML de otra pantalla.
    http_response_code(403);
    exit();
}

// -------------------------------------------------------------------------------------------
// LA IMAGEN DEL QR
//
// Recibe el TOKEN y no la URL entera, para que nadie pueda hacer que el sistema genere un QR que
// apunte a donde quiera. Es de lectura y sin efectos, así que no lleva CSRF.
// -------------------------------------------------------------------------------------------
if (($_GET['accion'] ?? '') === 'qr') {
    // Se suelta la sesión apenas se comprobó el permiso, ANTES de ponerse a generar la imagen.
    //
    // PHP mantiene la sesión bloqueada en exclusiva mientras dura la petición, así que dos
    // peticiones del mismo usuario no corren a la vez: la segunda espera a que la primera
    // termine. Y la vista previa de un lote pide UN QR POR RÓTULO, cada uno en su propio <img>:
    // con trescientos rótulos son trescientas peticiones que el navegador manda en paralelo y el
    // servidor atiende de a una. Puestas en fila detrás de algo lento —el PDF de ese mismo lote,
    // por ejemplo— la última esperaba tanto que se pasaba de max_execution_time y moría a medio
    // dibujar el QR (pasó el 2026-09-18: "Maximum execution time exceeded" en MaskUtil.php).
    //
    // Acá abajo no se escribe nada en la sesión, solo se lee $_GET y se devuelve un PNG, así que
    // cerrarla no pierde nada: lo que ya está en $_SESSION se sigue pudiendo leer.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $token = trim($_GET['t'] ?? '');

    if (!preg_match('/^[0-9A-Za-z]{1,16}$/', $token)) {
        http_response_code(400);
        exit();
    }

    $base = rtrim((string) URL_PUBLICA_ROTULOS, '/');
    if ($base === '') {
        http_response_code(404);
        exit();
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    $qr = new Endroid\QrCode\QrCode(
        data: $base . '/public/rotulo.php?r=' . $token,
        size: 240,
        margin: 0,
        errorCorrectionLevel: Endroid\QrCode\ErrorCorrectionLevel::Low
    );

    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');
    echo (new Endroid\QrCode\Writer\PngWriter())->write($qr)->getString();
    exit();
}

// -------------------------------------------------------------------------------------------
// LOS TOKENS DE UN LOTE
//
// UNA sola petición para todo el lote y no una por etiqueta. El token de un rótulo depende de sus
// datos (ver tokenDeRotulo): reimprimir la misma caja devuelve el mismo token, así que pedirlo
// para la vista previa y después imprimir no crea dos.
// -------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $cuerpo = json_decode(file_get_contents('php://input'), true) ?: [];
    $_POST['csrf_token'] = $cuerpo['csrf_token'] ?? '';
    validarCSRF();

    if (($cuerpo['accion'] ?? '') !== 'enlaces') {
        http_response_code(400);
        echo json_encode(['exito' => false, 'error' => 'Acción desconocida.']);
        exit();
    }

    // Se normaliza de a UN rótulo y no la lista entera, aunque parezca lo mismo. La lista entera
    // DESCARTA los rótulos sin punto de venta, así que la respuesta quedaría más corta que el lote
    // y el JavaScript —que empareja por posición— le pondría a cada caja el QR de la siguiente. En
    // Generar rótulos eso pasa de verdad: mientras se escribe, el punto de venta está vacío. Un
    // rótulo descartado devuelve null en su lugar y sale sin QR.
    $lote = is_array($cuerpo['rotulos'] ?? null) ? $cuerpo['rotulos'] : [];

    $tokens = [];
    foreach (array_slice($lote, 0, ROTULOS_MAXIMO_POR_TRABAJO) as $crudo) {
        $limpio   = normalizarListaDeRotulos([$crudo]);
        $tokens[] = $limpio ? tokenDeRotulo($pdo, $limpio[0]) : null;
    }

    echo json_encode(['exito' => true, 'tokens' => $tokens]);
    exit();
}

http_response_code(400);
exit();
