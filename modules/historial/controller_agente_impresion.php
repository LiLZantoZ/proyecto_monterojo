<?php
// modules/historial/controller_agente_impresion.php
// El otro extremo del modo remoto de impresión (ver IMPRESION_ROTULOS_MODO en config/config.php y
// imprimirRotulosEnEtiquetadora() en helper_rotulos_tspl.php): a esto le habla el agente que corre
// en la PC donde está conectada la etiquetadora por USB (scripts/agente_impresion_remota.ps1), no
// un navegador con sesión.
//
// Por eso NO pasa por auth_guard.php ni por el CSRF de los formularios: es un script sin usuario,
// sin cookies y sin pantalla, corriendo solo en esa otra PC. En su lugar se autentica con un token
// fijo (TOKEN_AGENTE_IMPRESION) que viaja en cada pedido — la misma idea que una contraseña, para
// un actor que no puede iniciar sesión.
//
// Dos acciones:
//   · siguiente  (GET)  — el trabajo pendiente más viejo, como bytes crudos. 204 si no hay nada.
//   · confirmar  (POST) — el agente avisa si pudo imprimirlo o no.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/helper_rotulos_tspl.php';

function tokenAgenteEsValido() {
    $recibido = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    // hash_equals y no === : compara en tiempo constante, para que un atacante no pueda ir
    // adivinando el token letra por letra midiendo cuánto tarda en responder cada intento.
    return $recibido !== '' && hash_equals(TOKEN_AGENTE_IMPRESION, $recibido);
}

if (!tokenAgenteEsValido()) {
    http_response_code(403);
    exit('Token inválido.');
}

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

// ---------------------------------------------------------------------------------------------
// EL AGENTE PIDE EL SIGUIENTE TRABAJO
// ---------------------------------------------------------------------------------------------
if ($accion === 'siguiente' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $trabajo = reclamarSiguienteTrabajoImpresion($pdo);

    if (!$trabajo) {
        http_response_code(204);   // nada pendiente; el agente vuelve a preguntar en un rato
        exit();
    }

    // application/octet-stream y no text/plain: el trabajo trae el logo como bitmap, bytes
    // binarios de verdad, no solo texto. El id y la cantidad van en cabeceras propias porque el
    // cuerpo es EXACTAMENTE lo que hay que mandarle a la impresora, sin envolverlo en JSON.
    header('Content-Type: application/octet-stream');
    header('X-Trabajo-Id: ' . (int) $trabajo['id_trabajo']);
    header('X-Etiquetas: ' . (int) $trabajo['etiquetas']);
    header('Content-Length: ' . strlen($trabajo['tspl']));
    echo $trabajo['tspl'];
    exit();
}

// ---------------------------------------------------------------------------------------------
// EL AGENTE AVISA SI PUDO IMPRIMIR
// ---------------------------------------------------------------------------------------------
if ($accion === 'confirmar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $idTrabajo = (int) ($_POST['id_trabajo'] ?? 0);
    $resultado = $_POST['resultado'] ?? '';
    $mensaje   = isset($_POST['mensaje']) ? mb_substr((string) $_POST['mensaje'], 0, 255) : null;

    if ($idTrabajo <= 0 || !in_array($resultado, ['ok', 'error'], true)) {
        http_response_code(400);
        exit('Faltan datos.');
    }

    confirmarTrabajoImpresion($pdo, $idTrabajo, $resultado === 'ok', $mensaje);

    header('Content-Type: application/json');
    echo json_encode(['exito' => true]);
    exit();
}

http_response_code(404);
