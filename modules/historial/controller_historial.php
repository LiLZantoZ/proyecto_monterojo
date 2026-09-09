<?php
// modules/historial/controller_historial.php
//   · restaurar      (POST, JSON) — vuelve a dejar pendiente un pedido ya despachado
//   · codigo_barras  (GET,  SVG)  — el código de barras de un rótulo (reimpreso desde el historial)
//   · pdf            (GET,  PDF)  — la hoja de alistamiento de UN pedido ya despachado
//   · pdf_masivo      (POST, PDF)  — la hoja de varios pedidos despachados en un solo archivo
//
// Las hojas y los rótulos de acá son un REIMPRESO: el pedido ya salió, esto es una copia por si se
// perdió la original o hay que volver a mandarla. El cálculo de cajas es el mismo que en Picking
// (ver agruparPorEntregaHistorial en model_historial.php), así que la hoja no puede discrepar de
// la que se imprimió el día del despacho.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/model_historial.php';

// requierePermiso() redirige, y una redirección le llega al fetch() como HTML que no puede
// interpretar. Acá se responde con el código correcto y sin cuerpo HTML.
if (!tienePermiso('modulo_historial')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exito' => false, 'error' => 'No tienes permiso para esta acción.']);
    exit();
}

// -------------------------------------------------------------------------------------------
// CÓDIGO DE BARRAS DE UN RÓTULO
// Igual que el de Picking (ver controller_picking.php): sin CSRF porque es una lectura, con
// lista blanca de caracteres porque el SVG se sirve desde nuestro propio dominio.
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
// REPORTE GENERAL: todos los pedidos del Historial que cumplen el filtro, uno por CEDI.
//
// A diferencia de "pdf" y "pdf_masivo" —que reimprimen la hoja de alistamiento de pedidos
// puntuales—, esto es el LISTADO completo que se ve en pantalla, para archivar o mandar por
// fuera del sistema. Por eso no pasa por la paginación de la vista: el filtro de búsqueda sí se
// respeta (es lo que decide qué se ve), pero la página en la que estaba parado no.
// -------------------------------------------------------------------------------------------
if (($_GET['accion'] ?? '') === 'pdf_reporte') {
    $filtros = ['busqueda' => trim($_GET['q'] ?? '')];

    $porCedi = agruparPorCediHistorial(agruparPorEntregaHistorial(filasHistorial($pdo, $filtros)));

    require_once __DIR__ . '/helper_historial_pdf.php';
    descargarHistorialPdf($porCedi, 'Historial_de_pedidos_' . date('Ymd_His') . '.pdf');
    // descargarHistorialPdf() termina la ejecución.
}

// -------------------------------------------------------------------------------------------
// RÓTULOS DE TODOS LOS PEDIDOS DEL HISTORIAL QUE CUMPLEN EL FILTRO
//
// Mismo criterio que "pdf_reporte": no pasa por la paginación de la vista, respeta el buscador.
// Puede ser un archivo grande —un pedido de 20 cajas son 20 páginas—, pero es exactamente lo que
// se pide: los rótulos de TODO lo que hay en el módulo, no de una selección puntual.
// -------------------------------------------------------------------------------------------
if (($_GET['accion'] ?? '') === 'rotulos_pdf') {
    $filtros = ['busqueda' => trim($_GET['q'] ?? '')];

    $entregas = array_values(agruparPorEntregaHistorial(filasHistorial($pdo, $filtros)));

    require_once __DIR__ . '/helper_rotulos_pdf.php';
    descargarRotulosPdf($entregas, 'Rotulos_Historial_' . date('Ymd_His') . '.pdf');
    // descargarRotulosPdf() termina la ejecución.
}

// -------------------------------------------------------------------------------------------
// HOJA DE ALISTAMIENTO DE UN PEDIDO YA DESPACHADO
// -------------------------------------------------------------------------------------------
if (($_GET['accion'] ?? '') === 'pdf') {
    $vistaHistorial = BASE_URL . '/modules/historial/views/historial.php';

    $idCarga = (int) ($_GET['carga'] ?? 0);
    $entrega = $idCarga > 0
        ? entregaHistorial($pdo, $idCarga, trim($_GET['cedi'] ?? ''), trim($_GET['oc'] ?? ''), trim($_GET['pv'] ?? ''))
        : null;

    if ($entrega === null) {
        header("Location: {$vistaHistorial}?error=invalid_id");
        exit();
    }

    $meta = cargaPorId($pdo, $idCarga) ?? [];

    require_once __DIR__ . '/../picking/helper_picking_pdf.php';
    descargarPickingPdf($entrega, $meta);
    // descargarPickingPdf() termina la ejecución.
}

// -------------------------------------------------------------------------------------------
// HOJAS DE VARIOS PEDIDOS DESPACHADOS EN UN SOLO PDF
// -------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'pdf_masivo') {
    validarCSRF();

    $vistaHistorial = BASE_URL . '/modules/historial/views/historial.php';

    $cargas = (array) ($_POST['carga'] ?? []);
    $cedis  = (array) ($_POST['cedi'] ?? []);
    $ocs    = (array) ($_POST['oc'] ?? []);
    $pvs    = (array) ($_POST['pv'] ?? []);

    $claves = [];
    foreach ($cargas as $i => $idCarga) {
        if (isset($cedis[$i], $ocs[$i], $pvs[$i])) {
            $claves[] = ['id_carga' => $idCarga, 'cedi' => $cedis[$i], 'oc' => $ocs[$i], 'pv' => $pvs[$i]];
        }
    }

    $entregas = entregasHistorial($pdo, $claves);

    if (!$entregas) {
        header("Location: {$vistaHistorial}?error=invalid_id");
        exit();
    }

    // Las entregas del lote pueden ser de cargas distintas, así que no hay UN "meta" que ponerle
    // al encabezado del PDF entero: se deja vacío y cada hoja mostraría "Archivo: " sin nombre.
    // Es una limitación menor de un reimpreso masivo, no de uno solo.
    require_once __DIR__ . '/../picking/helper_picking_pdf.php';
    descargarPickingPdf($entregas, [], 'Historial_' . count($entregas) . '_pedidos_' . date('Ymd_His') . '.pdf');
    // descargarPickingPdf() termina la ejecución.
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['exito' => false, 'error' => 'Método no permitido.']);
    exit();
}

// El cuerpo viene como JSON, así que $_POST está vacío y hay que leer php://input.
// validarCSRF() ya sabe buscar el token ahí dentro (ver tokenCSRFRecibido en config/csrf.php).
$cuerpo = json_decode(file_get_contents('php://input'), true) ?: [];
$_POST['csrf_token'] = $cuerpo['csrf_token'] ?? '';
validarCSRF();

$accion = $cuerpo['accion'] ?? '';

// -------------------------------------------------------------------------------------------
// IMPRIMIR LOS RÓTULOS DIRECTO EN LA ETIQUETADORA
//
// Misma lista y misma respuesta que en Picking: el rótulo de un pedido despachado se reimprime
// igual que el de uno pendiente. Ver helper_rotulos_tspl.php para por qué esto no pasa por el
// PDF ni por el diálogo de impresión del navegador.
// -------------------------------------------------------------------------------------------
if ($accion === 'imprimir_rotulos') {
    require_once __DIR__ . '/helper_rotulos_tspl.php';
    responderImpresionDeRotulos($cuerpo);   // termina la ejecución
}

if (!in_array($accion, ['restaurar'], true)) {
    http_response_code(400);
    echo json_encode(['exito' => false, 'error' => 'Acción desconocida.']);
    exit();
}

$idCarga     = (int) ($cuerpo['id_carga'] ?? 0);
$cedi        = trim((string) ($cuerpo['cedi'] ?? ''));
$ordenCompra = trim((string) ($cuerpo['orden_compra'] ?? ''));
$puntoVenta  = trim((string) ($cuerpo['punto_venta'] ?? ''));

if ($idCarga <= 0 || $cedi === '' || $ordenCompra === '' || $puntoVenta === '') {
    http_response_code(400);
    echo json_encode(['exito' => false, 'error' => 'Faltan datos para identificar el pedido.']);
    exit();
}

$resultado = restaurarEntregaHistorial($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta);

if (!$resultado['exito']) {
    // 409: no es una falla del servidor, es que la condición pedida (que siga siendo la carga
    // vigente, o que el pedido siga despachado) no se cumple.
    http_response_code(409);
    echo json_encode(['exito' => false, 'error' => $resultado['mensaje']]);
    exit();
}

echo json_encode(['exito' => true]);
