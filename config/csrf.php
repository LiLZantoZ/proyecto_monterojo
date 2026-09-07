<?php
// config/csrf.php
// Protección CSRF: un token único por sesión que debe viajar en cada formulario/enlace
// que modifique datos. Si no llega o no coincide con el de la sesión, se rechaza la petición.

function generarTokenCSRF() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Imprime el campo oculto listo para pegar dentro de un <form method="POST">...</form>
function campoCSRF() {
    $token = generarTokenCSRF();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

// Valida el token recibido (POST o, para enlaces de eliminar, GET) contra el de la sesión.
// Si no coincide, la acción NO se ejecuta: se responde 403 y se devuelve al usuario a la pantalla
// de donde vino con un aviso, en vez de dejarlo en una página en blanco con un texto suelto (que
// era lo que pasaba antes y no daba ninguna salida).
//
// Las peticiones hechas por fetch() reciben JSON en lugar de una redirección: ahí no hay navegación
// que seguir y una redirección les llegaría como HTML imposible de interpretar. Se distinguen por
// la cabecera Sec-Fetch-Dest, que el navegador manda solo (document = navegación normal).
// De dónde sale el token que manda el cliente.
//
// Los formularios normales lo dejan en $_POST y los enlaces de eliminar en $_GET. Pero una
// petición fetch() con cuerpo JSON no llena NINGUNO de los dos: PHP solo arma $_POST cuando el
// Content-Type es de formulario (urlencoded o multipart). Por eso, ahí el token viaja dentro del
// propio JSON y hay que leerlo del cuerpo crudo.
//
// php://input se puede leer varias veces (PHP >= 5.6), así que esto no le quita el cuerpo al
// controlador que después lo vuelve a leer para sacar sus datos.
function tokenCSRFRecibido() {
    if (isset($_POST['csrf_token'])) { return (string) $_POST['csrf_token']; }
    if (isset($_GET['csrf_token']))  { return (string) $_GET['csrf_token']; }

    $tipoContenido = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($tipoContenido, 'application/json') !== false) {
        $cuerpo = json_decode(file_get_contents('php://input'), true);
        if (is_array($cuerpo) && isset($cuerpo['csrf_token'])) {
            return (string) $cuerpo['csrf_token'];
        }
    }

    return '';
}

function validarCSRF() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $tokenRecibido = tokenCSRFRecibido();
    $tokenSesion = $_SESSION['csrf_token'] ?? '';

    if (empty($tokenSesion) || empty($tokenRecibido) || !hash_equals($tokenSesion, $tokenRecibido)) {
        require_once __DIR__ . '/mensajes.php';
        http_response_code(403);

        $destino = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document';
        if ($destino !== 'document') {
            header('Content-Type: application/json');
            echo json_encode(['exito' => false, 'error' => textoMensajeSistema('error', 'csrf')]);
            exit();
        }

        guardarMensajeFlash('error', 'csrf');
        header('Location: ' . urlSeguraDeRetorno(URL_LOGIN));
        exit();
    }
}
?>
