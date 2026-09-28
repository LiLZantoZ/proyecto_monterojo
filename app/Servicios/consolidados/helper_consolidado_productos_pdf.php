<?php
// modules/consolidados/helper_consolidado_productos_pdf.php
// Todos los productos de los CEDI seleccionados en UNA sola tabla, en PDF.
//
// Es el tercer papel del mismo pedido, al lado del interno (una hoja por CEDI con el total por
// producto) y el externo (una hoja por CEDI abierta por tienda): acá no importa a qué CEDI va cada
// cosa, sino cuánto hay que tener en total de cada producto. Los datos los arma
// productosDelConsolidado() en model_consolidados.php.
//
// Comparte la hoja de estilos con los otros dos (cssConsolidadoPdf) para que se vean de la misma casa.

require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/model_consolidados.php';
require_once __DIR__ . '/helper_consolidado_pdf.php';   // cssConsolidadoPdf(), logoConsolidadoDataUri()

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Genera el PDF y lo manda al navegador como descarga. Devuelve la respuesta de descarga (antes cortaba el pedido con exit()).
 *
 * $datos es la salida de productosDelConsolidado(): ['filas' => [...], 'cedis' => [nombres]].
 */
function descargarProductosConsolidadoPdf(array $datos, $meta, $nombreArchivo) {
    $filas   = $datos['filas'];
    $cedis   = $datos['cedis'];
    $totales = totalesDelGrupo($filas);
    $logo    = logoConsolidadoDataUri();
    $esc     = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    $num     = fn($n) => number_format((int) $n, 0, ',', '.');

    $subtitulo = count($cedis) === 1 ? $cedis[0] : count($cedis) . ' CEDI';

    $html = '<table class="encabezado" width="100%"><tr>';
    if ($logo !== '') {
        $html .= '<td width="60"><img src="' . $logo . '" class="logo"></td>';
    }
    $html .= '<td><div class="titulo">Productos del consolidado</div>'
           . '<div class="cedi">' . $esc($subtitulo) . '</div></td>'
           . '<td class="meta">Emitido: ' . date('d/m/Y H:i') . '<br>'
           . 'Archivo: ' . $esc($meta['nombre_archivo'] ?? '') . '<br>'
           . 'CEDI: ' . count($cedis) . '<br>'
           . 'Productos: ' . $totales['productos'] . '</td>'
           . '</tr></table>';

    // Qué CEDI entraron en la suma. Sin esto, una tabla de totales no dice de qué es el total, y
    // dos PDF de selecciones distintas se verían iguales.
    if (count($cedis) > 1) {
        $html .= '<div class="incluye"><strong>Incluye:</strong> '
               . implode(' · ', array_map($esc, $cedis)) . '</div>';
    }

    $html .= '<table class="datos">'
           . '<thead><tr>'
           . '<th width="14%">PLU</th><th width="8%">SKU</th><th>Descripción</th>'
           . '<th width="6%" class="centro">CEDI</th>'
           . '<th width="9%" class="num">Unidades</th>'
           . '<th width="8%" class="num">Cajas</th>'
           . '<th width="8%" class="num">Saldos</th>'
           . '</tr></thead><tbody>';

    // Las filas se pintan alternadas desde PHP porque dompdf no soporta :nth-child.
    $i = 0;
    foreach ($filas as $f) {
        $clase = (++$i % 2 === 0) ? ' class="par"' : '';
        $html .= '<tr' . $clase . '>'
              . '<td>' . $esc($f['plu']) . '</td>'
              . '<td>' . ($f['sku'] !== null ? $esc($f['sku']) : '<span class="falta">—</span>') . '</td>'
              . '<td>' . ($f['descripcion'] !== null ? $esc($f['descripcion']) : '<span class="falta">sin descripción en el maestro</span>') . '</td>'
              . '<td class="centro">' . (int) $f['cedis'] . '</td>'
              . '<td class="num">' . $num($f['unidades']) . '</td>';

        if ($f['sin_maestro']) {
            // Una raya y no un cero: un cero diría que no lleva ninguna caja, y lo que pasa es que
            // falta el dato para calcularlas.
            $html .= '<td class="num falta">—</td><td class="num falta">—</td>';
        } else {
            $html .= '<td class="num">' . $num($f['cajas']) . '</td>'
                  .  '<td class="num">' . ((int) $f['saldos'] > 0 ? $num($f['saldos']) : '') . '</td>';
        }
        $html .= '</tr>';
    }

    $peso = $totales['peso_kg'] > 0 ? number_format($totales['peso_kg'], 0, ',', '.') . ' kg' : '';

    $html .= '</tbody><tr class="totales">'
           . '<td colspan="4">TOTAL · ' . $totales['productos'] . ' producto(s)'
           . ($peso !== '' ? ' · ' . $peso : '') . '</td>'
           . '<td class="num">' . $num($totales['unidades']) . '</td>'
           . '<td class="num">' . $num($totales['cajas']) . '</td>'
           . '<td class="num">' . $num($totales['saldos']) . '</td>'
           . '</tr></table>';

    if ($totales['sin_maestro'] > 0) {
        $html .= '<div class="nota"><strong>Atención:</strong> ' . $totales['sin_maestro']
              . ' producto(s) no están en el maestro, así que no se pudieron convertir a cajas y no '
              . 'suman en el total. Van marcados con una raya y hay que contarlos aparte.</div>';
    }

    $html .= '<table class="firmas"><tr>'
           . '<td><div class="linea-firma">Alistado por</div></td>'
           . '<td><div class="linea-firma">Verificado por</div></td>'
           . '</tr></table>';

    $css = cssConsolidadoPdf() . "\n.incluye { font-size: 7.5pt; color: #444; line-height: 1.45; margin: -4pt 0 8pt; }\n";

    $opciones = new Options();
    $opciones->set('isRemoteEnabled', false);   // el logo va como data URI; nada sale a la red
    $opciones->set('defaultFont', 'Helvetica');

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml('<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css
                    . '</style></head><body>' . $html . '</body></html>', 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    // Se limpia cualquier salida previa: si algo ya se imprimió, se mezcla con los bytes del PDF y
    // el archivo llega corrupto.
    if (ob_get_length()) {
        ob_end_clean();
    }

    return descargaPdf($dompdf, $nombreArchivo);
}
