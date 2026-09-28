<?php
// modules/consolidados/helper_consolidado_externo_pdf.php
// El consolidado EXTERNO en PDF: una hoja por CEDI, abierta por punto de venta.
//
// Es el mismo pedido que el consolidado interno, mirado desde el otro lado:
//
//   · el INTERNO (helper_consolidado_pdf.php) junta todo lo que va a un CEDI en un solo total por
//     producto. Es el papel del elevador: baja de bodega una sola vez lo que después se reparte,
//     y para eso no le sirve saber a qué tienda va cada unidad.
//
//   · el EXTERNO lo abre por tienda, porque es lo que se entrega o se le muestra a la cadena. Ahí
//     "del CEDI salen 40 cajas" no le sirve a nadie si no dice cuántas son de cada local.
//
// Comparte la hoja de estilos con el interno a propósito (cssConsolidadoPdf): son dos vistas del
// mismo documento y tienen que verse como de la misma casa. Lo único propio de acá es el renglón
// que abre cada punto de venta y su subtotal.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/model_consolidados.php';
require_once __DIR__ . '/helper_consolidado_pdf.php';   // cssConsolidadoPdf(), logoConsolidadoDataUri()

use Dompdf\Dompdf;
use Dompdf\Options;

// Lo que este PDF agrega a la hoja de estilos compartida: el renglón que encabeza cada tienda y
// el de su subtotal. Van en gris y no en negro como el total del CEDI, para que se lea la
// jerarquía de un vistazo —tienda, subtotal, y recién al final el total de la hoja.
function cssConsolidadoExternoPdf() {
    return cssConsolidadoPdf() . <<<'CSS'

tr.punto td {
    background: #e8e3dd; font-weight: bold; font-size: 8.5pt;
    border: 0.5pt solid #b9b0a6; padding: 5pt 4pt;
}
tr.punto .oc { font-weight: normal; color: #555; }

tr.subtotal td {
    background: #f0ece7; font-weight: bold; font-size: 8pt;
    border: 0.5pt solid #b9b0a6; padding: 4pt;
}

.dir { font-weight: normal; color: #666; font-size: 7pt; }
CSS;
}

// Una hoja del PDF: el encabezado del CEDI y su tabla, abierta por punto de venta.
function seccionCediExternoPdf($cedi, array $datos, $meta) {
    $totales = $datos['totales'];
    $logo    = logoConsolidadoDataUri();
    $esc     = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    $num     = fn($n) => number_format((int) $n, 0, ',', '.');

    $html = '<table class="encabezado" width="100%"><tr>';
    if ($logo !== '') {
        $html .= '<td width="60"><img src="' . $logo . '" class="logo"></td>';
    }
    $html .= '<td><div class="titulo">Consolidado para rótulos</div>'
           . '<div class="cedi">' . $esc($cedi) . '</div></td>'
           . '<td class="meta">Emitido: ' . date('d/m/Y H:i') . '<br>'
           . 'Archivo: ' . $esc($meta['nombre_archivo'] ?? '') . '<br>'
           . 'Puntos de venta: ' . (int) $totales['puntos_venta'] . '<br>'
           . 'Productos: ' . (int) $totales['productos'] . '</td>'
           . '</tr></table>';

    $html .= '<table class="datos">'
           . '<thead><tr>'
           . '<th width="9%">PLU</th><th width="9%">SKU</th><th>Descripción</th>'
           . '<th width="11%" class="num">Unidades</th>'
           . '<th width="9%" class="num">Cajas</th>'
           . '<th width="9%" class="num">Saldos</th>'
           . '</tr></thead><tbody>';

    foreach ($datos['puntos'] as $punto => $pv) {
        $sub = $pv['totales'];

        // El renglón que abre la tienda. La orden de compra va acá y no en una columna: es la
        // misma para todas las líneas de esa tienda, y repetirla en cada fila gastaría una
        // columna entera para decir siempre lo mismo.
        $html .= '<tr class="punto"><td colspan="6">' . $esc($punto);
        if (!empty($pv['orden_compra'])) {
            $html .= ' <span class="oc">· O/C ' . $esc($pv['orden_compra']) . '</span>';
        }
        if (!empty($pv['direccion'])) {
            $html .= '<br><span class="dir">' . $esc($pv['direccion']) . '</span>';
        }
        $html .= '</td></tr>';

        // Las filas se pintan alternadas desde PHP porque dompdf no soporta :nth-child.
        $i = 0;
        foreach ($pv['filas'] as $f) {
            $clase = (++$i % 2 === 0) ? ' class="par"' : '';
            $html .= '<tr' . $clase . '>'
                  . '<td>' . $esc($f['plu']) . '</td>'
                  . '<td>' . ($f['sku'] !== null ? $esc($f['sku']) : '<span class="falta">—</span>') . '</td>'
                  . '<td>' . ($f['descripcion'] !== null
                        ? $esc($f['descripcion'])
                        : '<span class="falta">sin descripción en el maestro</span>') . '</td>'
                  . '<td class="num">' . $num($f['unidades']) . '</td>';

            if ($f['sin_maestro']) {
                // Dos celdas con una raya y no con un cero: un cero diría que no lleva ninguna
                // caja, y lo que pasa es que falta el dato para calcularlas.
                $html .= '<td class="num falta">—</td><td class="num falta">—</td>';
            } else {
                $html .= '<td class="num">' . $num($f['cajas']) . '</td>'
                      .  '<td class="num">' . ((int) $f['saldos'] > 0 ? $num($f['saldos']) : '') . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '<tr class="subtotal">'
               . '<td colspan="3">Subtotal ' . $esc($punto) . '</td>'
               . '<td class="num">' . $num($sub['unidades']) . '</td>'
               . '<td class="num">' . $num($sub['cajas']) . '</td>'
               . '<td class="num">' . $num($sub['saldos']) . '</td>'
               . '</tr>';
    }

    $peso = $totales['peso_kg'] > 0 ? number_format($totales['peso_kg'], 0, ',', '.') . ' kg' : '';

    $html .= '</tbody><tr class="totales">'
           . '<td colspan="3">TOTAL ' . $esc($cedi)
           . ' · ' . (int) $totales['puntos_venta'] . ' punto(s) de venta'
           . ($peso !== '' ? ' · ' . $peso : '') . '</td>'
           . '<td class="num">' . $num($totales['unidades']) . '</td>'
           . '<td class="num">' . $num($totales['cajas']) . '</td>'
           . '<td class="num">' . $num($totales['saldos']) . '</td>'
           . '</tr></table>';

    if ($totales['sin_maestro'] > 0) {
        $html .= '<div class="nota"><strong>Atención:</strong> ' . (int) $totales['sin_maestro']
              . ' línea(s) de esta hoja corresponden a productos que no están en el maestro, así que '
              . 'no se pudieron convertir a cajas y no suman en los totales. Van marcadas con una '
              . 'raya y hay que contarlas aparte.</div>';
    }

    $html .= '<table class="firmas"><tr>'
           . '<td><div class="linea-firma">Entregado por</div></td>'
           . '<td><div class="linea-firma">Recibido por</div></td>'
           . '</tr></table>';

    return $html;
}

/**
 * Genera el PDF del consolidado externo y lo manda al navegador como descarga.
 * No devuelve: termina la ejecución.
 *
 * $porCedi es la salida de consolidadoExternoPorCedi(). Cada CEDI va en su propia hoja, así el
 * mismo archivo sirve para imprimir todo de una y entregar una hoja por destino.
 */
function descargarConsolidadoExternoPdf(array $porCedi, $meta, $nombreArchivo) {
    $opciones = new Options();
    $opciones->set('isRemoteEnabled', false);   // el logo va como data URI; nada sale a la red
    $opciones->set('defaultFont', 'Helvetica');

    $paginas = [];
    foreach ($porCedi as $cedi => $datos) {
        $paginas[] = seccionCediExternoPdf($cedi, $datos, $meta);
    }

    // El salto va ENTRE hojas y no al final de cada una: con un page-break después de la última,
    // el PDF termina con una página en blanco.
    $cuerpo = implode('<div style="page-break-before: always;"></div>', $paginas);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
          . cssConsolidadoExternoPdf() . '</style></head><body>' . $cuerpo . '</body></html>';

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    // Se limpia cualquier salida previa (un aviso de PHP, un espacio suelto antes de un <?php):
    // si algo ya se imprimió, se mezcla con los bytes del PDF y el archivo llega corrupto.
    if (ob_get_length()) {
        ob_end_clean();
    }

    $dompdf->stream($nombreArchivo, ['Attachment' => true]);
    exit();
}

// Deja el nombre del CEDI usable como nombre de archivo, igual que nombreArchivoCedi() pero
// diciendo que es el externo: quien lo baja termina con los dos en la misma carpeta.
function nombreArchivoCediExterno($cedi) {
    $limpio = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $cedi), '_');
    return 'Consolidado_rotulos_' . ($limpio !== '' ? $limpio : 'CEDI') . '_' . date('Ymd') . '.pdf';
}
