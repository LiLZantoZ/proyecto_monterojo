<?php
// modules/rotulos/controller_rotulos.php
// Dos acciones:
//   · codigo_barras (GET,  SVG) — el código de barras de un rótulo, igual que en Picking/Historial
//   · pdf           (POST, PDF) — descarga los rótulos armados a mano en la pantalla

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';

$vistaRotulos = BASE_URL . '/modules/rotulos/views/rotulos.php';

if (!tienePermiso('modulo_rotulos')) {
    // codigo_barras lo pide un <img>, así que una redirección no serviría de nada —el navegador
    // la mostraría como una imagen rota, que es exactamente lo que pasa igual acá.
    http_response_code(403);
    exit();
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

    $limitar = function ($valor, $porDefecto, $minimo, $maximo) {
        $valor = (int) $valor;
        if ($valor < $minimo) { $valor = $porDefecto; }
        if ($valor > $maximo) { $valor = $maximo; }
        return $valor;
    };

    $cantidad = $limitar($_POST['cantidad'] ?? 1, 1, 1, 99);
    $desde    = $limitar($_POST['desde'] ?? 1, 1, 1, 999);
    $total    = $limitar($_POST['total'] ?? 1, 1, 1, 999);
    $total    = max($total, $desde + $cantidad - 1);

    $pv       = trim(mb_substr((string) ($_POST['pv'] ?? ''), 0, 180));
    $oc       = trim(mb_substr((string) ($_POST['oc'] ?? ''), 0, 40));
    $cedi     = trim(mb_substr((string) ($_POST['cedi'] ?? ''), 0, 120));
    $producto = trim(mb_substr((string) ($_POST['producto'] ?? ''), 0, 255));
    $eanPv    = trim(mb_substr((string) ($_POST['ean_pv'] ?? ''), 0, 40));

    if ($pv === '') {
        // Sin punto de venta el identificador de la caja quedaría vacío en esa parte, y el
        // rótulo no serviría para identificar nada. Es el único campo que de verdad hace falta.
        header("Location: {$vistaRotulos}?error=falta_punto_venta");
        exit();
    }

    $entrega = [
        'cedi'            => $cedi,
        'orden_compra'    => $oc,
        'punto_venta'     => $pv,
        'ean_punto_venta' => $eanPv !== '' ? $eanPv : null,
        'totales'         => ['cajas_rotulo' => $total],
        'lineas'          => [[
            'descripcion'  => $producto !== '' ? $producto : null,
            'sku'          => null,
            'plu'          => null,
            'cajas_rotulo' => $cantidad,
            'caja_desde'   => $desde,
            'cajas_pedido' => $total,
        ]],
    ];

    require_once __DIR__ . '/../historial/helper_rotulos_pdf.php';
    descargarRotulosPdf($entrega, 'Rotulo_manual_' . date('Ymd_His') . '.pdf');
    // descargarRotulosPdf() termina la ejecución.
}

header("Location: {$vistaRotulos}");
exit();
