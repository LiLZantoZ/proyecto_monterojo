<?php
// modules/rotulos/controller_rotulos.php
// Dos acciones:
//   · codigo_barras    (GET,  SVG)  — el código de barras de un rótulo, igual que en Picking/Historial
//   · pdf              (POST, PDF)  — descarga los rótulos armados a mano en la pantalla
//   · imprimir_rotulos (POST, JSON) — los manda directo a la etiquetadora, sin PDF de por medio

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';

$vistaRotulos = BASE_URL . '/rotulos';

if (!tienePermiso('modulo_rotulos')) {
    // codigo_barras lo pide un <img>, así que una redirección no serviría de nada —el navegador
    // la mostraría como una imagen rota, que es exactamente lo que pasa igual acá.
    http_response_code(403);
    exit();
}

// -------------------------------------------------------------------------------------------
// IMPRIMIR LOS RÓTULOS DIRECTO EN LA ETIQUETADORA
//
// Va por JSON y no por el formulario del PDF a propósito: imprimir no debería recargar la
// pantalla y perder lo que la persona acaba de escribir a mano, que es justamente el trabajo de
// este módulo. Ver modules/historial/helper_rotulos_tspl.php.
// -------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {

    header('Content-Type: application/json; charset=utf-8');

    // El cuerpo viene como JSON, así que $_POST está vacío y hay que leer php://input.
    $cuerpo = json_decode(file_get_contents('php://input'), true) ?: [];
    $_POST['csrf_token'] = $cuerpo['csrf_token'] ?? '';
    validarCSRF();

    if (($cuerpo['accion'] ?? '') !== 'imprimir_rotulos') {
        http_response_code(400);
        echo json_encode(['exito' => false, 'error' => 'Acción desconocida.']);
        exit();
    }

    require_once __DIR__ . '/../historial/helper_rotulos_tspl.php';
    responderImpresionDeRotulos($cuerpo);   // termina la ejecución
}
// -------------------------------------------------------------------------------------------
// CÓDIGO DE BARRAS DE UN RÓTULO
// Igual que en controller_picking.php / controller_historial.php: sin CSRF porque es una
// lectura, con lista blanca de caracteres porque el SVG se sirve desde nuestro propio dominio.
// -------------------------------------------------------------------------------------------
if (($_GET['accion'] ?? '') === 'codigo_barras') {
    $texto = trim($_GET['texto'] ?? '');

    if ($texto === '' || strlen($texto) > 60 || !preg_match('/^[A-Za-z0-9\-]+$/', $texto)) {
        http_response_code(400);
        exit();
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    $generador = new Picqer\Barcode\BarcodeGeneratorSVG();
    $svg = $generador->getBarcode($texto, $generador::TYPE_CODE_128, 2, 60);

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    echo $svg;
    exit();
}

// -------------------------------------------------------------------------------------------
// DESCARGAR EN PDF LOS RÓTULOS ARMADOS A MANO
//
// Se arma una "entrega" de un solo producto con los datos del formulario y se le pasa a
// descargarRotulosPdf() de Historial —es la misma función que ya genera los rótulos de un pedido
// real, sin ninguna modificación: acá 'lineas' tiene una sola línea en vez de varias, y
// 'cajas_rotulo' de esa línea es la CANTIDAD de rótulos a imprimir, mientras que
// 'totales.cajas_rotulo' es el TOTAL del pedido que se imprime en "CAJ X DE total" —son cosas
// distintas a propósito (ver el modal de rótulos): "cuántos imprimo ahora" no siempre es "cuántos
// tiene el pedido completo".
// -------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'pdf') {
    validarCSRF();

    // La lista llega armada desde la pantalla, igual que en Picking, y no se recalcula acá. Antes
    // este módulo mandaba los campos sueltos y el servidor rearmaba los rótulos por su cuenta: dos
    // cálculos del mismo rótulo, y el PDF podía no coincidir con la vista previa. Ahora es la
    // misma lista que se ve en pantalla y que se manda a la etiquetadora.
    $rotulos = json_decode($_POST['rotulos'] ?? '', true);

    require_once __DIR__ . '/../historial/helper_rotulos_lista.php';
    $rotulos = is_array($rotulos) ? normalizarListaDeRotulos($rotulos) : [];

    if (!$rotulos) {
        // Sin punto de venta el rótulo no identifica ninguna caja: normalizarListaDeRotulos() lo
        // descarta. Es el único campo que de verdad hace falta.
        header("Location: {$vistaRotulos}?error=falta_punto_venta");
        exit();
    }

    require_once __DIR__ . '/../historial/helper_rotulos_pdf.php';
    descargarRotulosPdfDeLista($rotulos, 'Rotulo_manual_' . date('Ymd_His') . '.pdf');
    // descargarRotulosPdfDeLista() termina la ejecución.
}

header("Location: {$vistaRotulos}");
exit();
