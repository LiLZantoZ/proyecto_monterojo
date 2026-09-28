<?php
// modules/cajas_punto_venta/controller_cajas_punto_venta.php
// Una sola acción: bajar en PDF la planilla de cajas por punto de venta.
//
// El PDF se arma con la misma hoja de estilos que los consolidados (cssConsolidadoPdf) porque es
// el mismo juego de papeles: quien recibe la entrega en el CEDI va a tener los dos en la mano, y
// que se vean distintos solo haría dudar de si son del mismo despacho.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/model_cajas_punto_venta.php';
require_once __DIR__ . '/../consolidados/helper_consolidado_pdf.php';   // css y logo compartidos

use Dompdf\Dompdf;
use Dompdf\Options;

$vista = BASE_URL . '/cajas-punto-venta';

requierePermiso('modulo_cajas_punto_venta', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// -------------------------------------------------------------------------------------------
// LOS CÓDIGOS DE LA VISTA PREVIA
//
// La etiqueta impresa lleva dos códigos y la vista previa tiene que mostrar los mismos, o deja de
// ser una vista previa. La diferencia con el papel es de dónde salen: allá los dibuja la impresora
// con su firmware (comandos BARCODE y QRCODE), y acá hay que generarlos como imagen.
//
// Los dos son de LECTURA y sin efectos, así que no llevan CSRF —igual que codigo_barras en
// Picking y el Historial—. Lo que sí llevan es validación estricta de lo que reciben: son cadenas
// que terminan adentro de una imagen que se sirve desde nuestro propio dominio.
// -------------------------------------------------------------------------------------------

// El código de barras: lleva el EAN del producto, para la pistola láser.
if (($_GET['accion'] ?? '') === 'codigo_barras') {
    $texto = trim($_GET['texto'] ?? '');

    if ($texto === '' || strlen($texto) > 60 || !preg_match('/^[A-Za-z0-9\-]+$/', $texto)) {
        http_response_code(400);
        exit();
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    $generador = new Picqer\Barcode\BarcodeGeneratorSVG();
    // Sin texto legible debajo, igual que en la etiqueta: el EAN no se muestra, se escanea.
    $svg = $generador->getBarcode($texto, $generador::TYPE_CODE_128, 2, 60);

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    echo $svg;
    exit();
}

// Subir la lista oficial de almacenes del Éxito (Dependencia → Nombre). Ver model_almacenes_exito.php.
if (($_POST['accion'] ?? '') === 'subir_almacenes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/mensajes.php';
    require_once __DIR__ . '/../consolidados/model_almacenes_exito.php';
    validarCSRF();

    $archivo = $_FILES['archivo'] ?? null;

    if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        // is_uploaded_file: sin esto, un POST armado a mano podría pasar una ruta del servidor en
        // tmp_name y hacer que el sistema lea un archivo que no subió nadie.
        guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
    } elseif (!in_array(strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
        guardarMensajeFlashTexto('error', 'La lista de almacenes tiene que ser un Excel (.xlsx o .xls).');
    } else {
        $resultado = importarAlmacenesExito($pdo, $archivo['tmp_name']);
        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
    }

    header("Location: {$vista}");
    exit();
}

// El PDF de la planilla, pero SOLO de los puntos de venta tildados en la barra de selección (uno o
// varios CEDI mezclados). Por POST y no por GET como el de arriba: la lista de puntos puede ser
// larga para una URL. Comparte hojaCajasPorPuntoPdf() y descargarCajasPorPuntoPdf() con la acción
// 'pdf' de más abajo, para que las dos plantillas de PDF sean literalmente la misma función.
if (($_POST['accion'] ?? '') === 'pdf_seleccion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/mensajes.php';
    validarCSRF();

    // Arreglos paralelos (cedi[] / punto[]) y no una clave ya armada: así el HTML no tiene que
    // preocuparse por el separador '|', que en teoría podría venir dentro de un nombre de tienda.
    $cedis  = (array) ($_POST['cedi'] ?? []);
    $puntos = (array) ($_POST['punto'] ?? []);
    $claves = [];
    foreach ($cedis as $i => $c) {
        if (is_string($c) && isset($puntos[$i]) && is_string($puntos[$i]) && $c !== '' && $puntos[$i] !== '') {
            $claves[] = $c . '|' . $puntos[$i];
        }
    }

    if (!$claves) {
        guardarMensajeFlashTexto('error', 'Seleccioná al menos un punto de venta para descargar.');
        header("Location: {$vista}");
        exit();
    }

    $porCedi = cajasPorPuntoDeVenta($pdo, ['puntos' => $claves]);
    if (empty($porCedi)) {
        header("Location: {$vista}?error=sin_datos");
        exit();
    }

    descargarCajasPorPuntoPdf(
        $porCedi,
        count($claves) . '_puntos_de_venta_' . date('Ymd') . '.pdf'
    );
    // descargarCajasPorPuntoPdf() termina la ejecución.
}

// El QR y los tokens de los rótulos se mudaron el 2026-09-14 a
// modules/historial/controller_rotulos_enlace.php, que comparten las cuatro pantallas que muestran
// rótulos. Acá exigían el permiso de este módulo, y Picking, Historial y Generar rótulos también
// los necesitan.
if (($_GET['accion'] ?? '') !== 'pdf') {
    header("Location: {$vista}");
    exit();
}

$filtros = [
    'cedi'  => trim($_GET['cedi'] ?? ''),
    'punto' => trim($_GET['punto'] ?? ''),
];

$porCedi = cajasPorPuntoDeVenta($pdo, $filtros);

if (empty($porCedi)) {
    header("Location: {$vista}?error=sin_datos");
    exit();
}

// Una hoja por CEDI: así se le entrega a cada transportista la suya.
function hojaCajasPorPuntoPdf($cedi, array $datos) {
    $esc = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    $num = fn($n) => number_format((int) $n, 0, ',', '.');
    $t   = $datos['totales'];
    $logo = logoConsolidadoDataUri();

    $html = '<table class="encabezado" width="100%"><tr>';
    if ($logo !== '') {
        $html .= '<td width="60"><img src="' . $logo . '" class="logo"></td>';
    }
    $html .= '<td><div class="titulo">Cajas por punto de venta</div>'
           . '<div class="cedi">' . $esc($cedi) . '</div></td>'
           . '<td class="meta">Emitido: ' . date('d/m/Y H:i') . '<br>'
           . 'Puntos de venta: ' . $num($t['puntos']) . '<br>'
           . 'Cajas: ' . $num($t['cajas']) . '</td>'
           . '</tr></table>';

    // Las columnas son las de la LISTA DE EMPAQUE que usan las cadenas, para que este papel se
    // pueda comparar renglón a renglón con el que firma el CEDI al recibir. 'Refrigerado' y
    // 'Otros' van vacías: Monterojo no despacha refrigerado, pero las casillas se dejan porque
    // el formato es de ellos y quitarlas obligaría a quien recibe a contar posiciones.
    $html .= '<table class="datos"><thead><tr>'
           . '<th width="10%">Código</th><th>Nombre del almacén</th>'
           . '<th width="13%" class="num">Cajas producto seco</th>'
           . '<th width="13%" class="num">Cajas producto refrigerado</th>'
           . '<th width="10%" class="num">Otros</th>'
           . '<th width="10%" class="num">Total</th>'
           . '</tr></thead><tbody>';

    // Las filas se pintan alternadas desde PHP porque dompdf no soporta :nth-child.
    $i = 0;
    foreach ($datos['puntos'] as $p) {
        $clase = (++$i % 2 === 0) ? ' class="par"' : '';
        $html .= '<tr' . $clase . '>'
              . '<td>' . ($p['numero'] !== '' ? $esc($p['numero']) : '<span class="falta">—</span>') . '</td>'
              . '<td>' . ($p['nombre'] !== '' ? $esc($p['nombre']) : '<span class="falta">sin nombre en el archivo</span>') . '</td>'
              . '<td class="num">' . $num($p['cajas_seco']) . '</td>'
              . '<td class="num"></td>'
              . '<td class="num"></td>'
              . '<td class="num">' . $num($p['total']) . '</td>'
              . '</tr>';
    }

    $html .= '</tbody><tr class="totales">'
           . '<td colspan="2">TOTAL ' . $esc($cedi) . '</td>'
           . '<td class="num">' . $num($t['cajas']) . '</td>'
           . '<td class="num"></td>'
           . '<td class="num"></td>'
           . '<td class="num">' . $num($t['total']) . '</td>'
           . '</tr></table>';

    if ($t['sin_maestro'] > 0) {
        $html .= '<div class="nota"><strong>Atención:</strong> ' . $num($t['sin_maestro'])
              . ' línea(s) corresponden a productos que no están en el maestro o no tienen unidades '
              . 'por caja, así que NO suman cajas en esta planilla. Hay que contarlas aparte.</div>';
    }

    $html .= '<table class="firmas"><tr>'
           . '<td><div class="linea-firma">Entregado por</div></td>'
           . '<td><div class="linea-firma">Recibido por</div></td>'
           . '</tr></table>';

    return $html;
}

// Arma el PDF de $porCedi (la salida de cajasPorPuntoDeVenta) y lo manda como descarga. No
// devuelve: termina la ejecución. La usan la acción 'pdf' (todo lo filtrado, o un CEDI) y
// 'pdf_seleccion' (solo los puntos tildados) — es la MISMA plantilla en los dos casos.
function descargarCajasPorPuntoPdf(array $porCedi, $nombreArchivo) {
    $paginas = [];
    foreach ($porCedi as $cedi => $datos) {
        $paginas[] = hojaCajasPorPuntoPdf($cedi, $datos);
    }

    // El salto va ENTRE hojas y no al final de cada una: con un page-break después de la última,
    // el PDF termina con una página en blanco.
    $cuerpo = implode('<div style="page-break-before: always;"></div>', $paginas);

    $opciones = new Options();
    $opciones->set('isRemoteEnabled', false);   // el logo va como data URI; nada sale a la red
    $opciones->set('defaultFont', 'Helvetica');

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . cssConsolidadoPdf() . '</style></head><body>' . $cuerpo . '</body></html>',
        'UTF-8'
    );
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    // Se limpia cualquier salida previa: si algo ya se imprimió, se mezcla con los bytes del PDF y
    // el archivo llega corrupto.
    if (ob_get_length()) {
        ob_end_clean();
    }

    $dompdf->stream($nombreArchivo, ['Attachment' => true]);
    exit();
}

$nombre = $filtros['cedi'] !== ''
    ? 'Cajas_por_punto_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', $filtros['cedi']), '_') . '_' . date('Ymd') . '.pdf'
    : 'Cajas_por_punto_de_venta_' . date('Ymd') . '.pdf';

descargarCajasPorPuntoPdf($porCedi, $nombre);
// descargarCajasPorPuntoPdf() termina la ejecución.
