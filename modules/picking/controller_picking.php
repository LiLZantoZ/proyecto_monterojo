<?php
// modules/picking/controller_picking.php
// Tres acciones:
//   · guardar_pedido_sap (POST, JSON)  — el número que se escribe en la cabecera de la entrega
//   · codigo_barras      (GET,  SVG)   — el código de barras de un rótulo
//   · pdf                (GET,  PDF)   — la hoja de alistamiento de una entrega
//
// La primera responde JSON porque la llama un fetch() desde la propia pantalla: recargar la
// página entera cada vez que alguien escribe un número haría perder el scroll y el filtro.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/model_picking.php';

// requierePermiso() redirige, y una redirección le llega al fetch como HTML que no puede
// interpretar. Acá se responde con el código correcto y sin cuerpo HTML.
if (!tienePermiso('modulo_picking')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exito' => false, 'error' => 'No tienes permiso para esta acción.']);
    exit();
}

// -------------------------------------------------------------------------------------------
// CÓDIGO DE BARRAS DE UN RÓTULO
//
// Va por GET y devuelve un SVG porque lo consume un <img src="..."> del rótulo: así el navegador
// lo cachea y, al imprimir, ya está cargado. Un SVG y no un PNG para que las barras salgan
// nítidas a cualquier resolución de impresora — un código de barras pixelado no se lee.
//
// No lleva token CSRF: es una lectura que no cambia nada. Sí exige sesión y permiso, como todo
// lo demás (el auth_guard de arriba).
// -------------------------------------------------------------------------------------------
if (($_GET['accion'] ?? '') === 'codigo_barras') {
    $texto = trim($_GET['texto'] ?? '');

    // Solo lo que este sistema genera: dígitos, letras y guiones. La lista blanca evita que
    // alguien use el generador para meter contenido arbitrario en un SVG que después se sirve
    // desde nuestro propio dominio.
    if ($texto === '' || strlen($texto) > 60 || !preg_match('/^[A-Za-z0-9\-]+$/', $texto)) {
        http_response_code(400);
        exit();
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    // Code 128: acepta letras, dígitos y guiones, y comprime solo los tramos de dígitos, que es
    // lo que hace entrar un identificador de 22 caracteres en los 86 mm útiles del rótulo con
    // barras de 0,37 mm — bastante por encima del mínimo que lee un lector de mano.
    $generador = new Picqer\Barcode\BarcodeGeneratorSVG();
    $svg = $generador->getBarcode($texto, $generador::TYPE_CODE_128, 2, 60);

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    echo $svg;
    exit();
}

// -------------------------------------------------------------------------------------------
// HOJA DE ALISTAMIENTO DE UNA ENTREGA
//
// Por GET, como el PDF del consolidado: es un enlace normal, no pasa por JavaScript, y así se
// puede abrir en otra pestaña o guardar el enlace.
// -------------------------------------------------------------------------------------------
if (($_GET['accion'] ?? '') === 'pdf') {
    $vistaPicking = BASE_URL . '/modules/picking/views/picking.php';

    $carga = cargaVigente($pdo);
    if (!$carga) {
        header("Location: {$vistaPicking}?error=sin_datos");
        exit();
    }

    $entrega = entregaPicking(
        $pdo,
        $carga['id_carga'],
        trim($_GET['cedi'] ?? ''),
        trim($_GET['oc'] ?? ''),
        trim($_GET['pv'] ?? '')
    );

    // La entrega se identifica por tres valores que vienen de la URL. Si no coinciden con
    // ninguna, se devuelve al listado en vez de generar una hoja vacía que alguien podría
    // imprimir y llevarse a la bodega.
    if ($entrega === null) {
        header("Location: {$vistaPicking}?error=invalid_id");
        exit();
    }

    require_once __DIR__ . '/helper_picking_pdf.php';
    descargarPickingPdf($entrega, $carga);
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

if (!in_array($accion, ['guardar_pedido_sap', 'asignar_personal'], true)) {
    http_response_code(400);
    echo json_encode(['exito' => false, 'error' => 'Acción desconocida.']);
    exit();
}

$carga = cargaVigente($pdo);
if (!$carga) {
    http_response_code(409);
    echo json_encode(['exito' => false, 'error' => 'No hay un Consolidado cargado.']);
    exit();
}

// Las dos acciones apuntan a una ENTREGA, identificada por los mismos tres valores.
$cedi        = trim($cuerpo['cedi'] ?? '');
$ordenCompra = trim($cuerpo['orden_compra'] ?? '');
$puntoVenta  = trim($cuerpo['punto_venta'] ?? '');

if ($cedi === '' || $ordenCompra === '' || $puntoVenta === '') {
    http_response_code(400);
    echo json_encode(['exito' => false, 'error' => 'Faltan datos para identificar la entrega.']);
    exit();
}

if ($accion === 'asignar_personal') {
    require_once __DIR__ . '/../personal/model_personal.php';

    // Vacío o 0 = quitar la asignación. Se distingue de "no vino el dato" para que dejar sin
    // asignar sea una acción explícita y no el resultado de un campo que no llegó.
    $idPersonal = trim((string) ($cuerpo['id_personal'] ?? ''));
    $idPersonal = ($idPersonal === '' || $idPersonal === '0') ? null : (int) $idPersonal;

    $nombre = null;
    if ($idPersonal !== null) {
        $persona = obtenerPersona($pdo, $idPersonal);
        // Se comprueba contra la base y no se confía en lo que manda el navegador: si esa persona
        // se eliminó mientras la pantalla estaba abierta, la clave foránea rechazaría el UPDATE y
        // el error saldría como un fallo genérico en vez de decir qué pasó.
        if (!$persona) {
            http_response_code(409);
            echo json_encode(['exito' => false, 'error' => 'Esa persona ya no está en el personal. Recargá la pantalla.']);
            exit();
        }
        $nombre = $persona['nombre'];
    }

    if (!asignarPersonalAEntrega($pdo, $carga['id_carga'], $cedi, $ordenCompra, $puntoVenta, $idPersonal)) {
        http_response_code(500);
        echo json_encode(['exito' => false, 'error' => 'No se pudo guardar la asignación. Inténtalo de nuevo.']);
        exit();
    }

    echo json_encode([
        'exito'       => true,
        'id_personal' => $idPersonal,
        'nombre'      => $nombre,
        'grupo'       => $cedi . '|' . $ordenCompra . '|' . $puntoVenta,
    ]);
    exit();
}

$pedidoSap = trim($cuerpo['pedido_sap'] ?? '');
$guardado  = guardarPedidoSap($pdo, $carga['id_carga'], $cedi, $ordenCompra, $puntoVenta, $pedidoSap);

if (!$guardado) {
    http_response_code(500);
    echo json_encode(['exito' => false, 'error' => 'No se pudo guardar. Inténtalo de nuevo.']);
    exit();
}

echo json_encode([
    'exito'      => true,
    'pedido_sap' => $pedidoSap,
    'grupo'      => $cedi . '|' . $ordenCompra . '|' . $puntoVenta,
]);
