<?php
// modules/ordenes_compra/helper_ordenes_compra_pdf.php
// La tabla de Órdenes de compra en PDF: la misma que hay en pantalla, con el mismo filtro.
//
// Usa la hoja de estilos de los Consolidados (cssConsolidadoPdf) porque es del mismo juego de papeles
// que se entregan con el despacho: que se vean distintos solo haría dudar de si son del mismo pedido.
//
// Va en hoja HORIZONTAL: son diez columnas, y en vertical el valor y el nombre del vehículo no entran
// sin partir los números en dos renglones.

require_once __DIR__ . '/../consolidados/helper_consolidado_pdf.php';   // cssConsolidadoPdf(), logoConsolidadoDataUri()
require_once __DIR__ . '/model_ordenes_compra.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Arma el PDF con el resultado de ordenesDeCompraExito() y lo manda como descarga. Devuelve la
 * respuesta de descarga (antes cortaba el pedido con exit()).
 */
function descargarOrdenesCompraPdf(array $datos, array $filtros) {
    $esc   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $num   = fn($n) => number_format((float) $n, 0, ',', '.');
    $m3    = fn($n) => rtrim(rtrim(number_format((float) $n, 7, ',', '.'), '0'), ',') ?: '0';
    $plata = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');

    $ordenes = $datos['ordenes'];
    $t       = $datos['totales'];
    $logo    = logoConsolidadoDataUri();

    // ---------------- Encabezado ----------------
    $html = '<table class="encabezado" width="100%"><tr>';
    if ($logo !== '') {
        $html .= '<td width="60"><img src="' . $logo . '" class="logo"></td>';
    }
    $html .= '<td><div class="titulo">Órdenes de compra (Éxito)</div>'
           . '<div class="cedi">' . ($filtros['oc'] !== ''
                ? 'Órdenes que contienen "' . $esc($filtros['oc']) . '"'
                : 'Pendientes de despacho') . '</div></td>'
           . '<td class="meta">Emitido: ' . date('d/m/Y H:i') . '<br>'
           . 'Órdenes: ' . $num($t['ordenes']) . '<br>'
           . 'Cajas: ' . $num($t['cajas']) . ' · ' . $m3($t['m3']) . ' m³</td>'
           . '</tr></table>';

    // ---------------- La tabla ----------------
    $html .= '<table class="datos"><thead><tr>'
           . '<th width="11%">Orden</th>'
           . '<th>CEDI</th>'
           . '<th width="6%" class="num">Cajas</th>'
           . '<th width="8%" class="num">Unidades</th>'
           . '<th width="8%" class="num">Estibas*</th>'
           . '<th width="8%" class="num">Peso (kg)</th>'
           . '<th width="8%" class="num">m³</th>'
           . '<th width="13%" class="num">Valor</th>'
           . '<th width="16%">Carro</th>'
           . '</tr></thead><tbody>';

    // Las filas se pintan alternadas desde PHP porque dompdf no soporta :nth-child.
    $i = 0;
    foreach ($ordenes as $o) {
        $clase = (++$i % 2 === 0) ? ' class="par"' : '';

        if ($o['carro'] !== null) {
            $carro = $esc($o['carro']) . ($o['carro_incompleto'] ? ' <span class="falta">(revisar)</span>' : '');
        } else {
            $carro = '<span class="falta">' . ($o['carro_excede'] ? 'No entra en un vehículo' : 'Sin vehículos') . '</span>';
        }

        // La marca "†" en la orden remite a la nota de faltantes de abajo: en papel no hay un ícono
        // que se pueda señalar con el mouse para ver qué le falta.
        $marca = ($o['sin_maestro'] + $o['sin_cubicaje'] + $o['sin_precio']) > 0 ? ' †' : '';

        $html .= '<tr' . $clase . '>'
               . '<td><strong>' . $esc($o['orden']) . '</strong>' . $marca . '</td>'
               . '<td>' . $esc($o['cedi']) . '</td>'
               . '<td class="num">' . $num($o['cajas']) . '</td>'
               . '<td class="num">' . $num($o['unidades']) . '</td>'
               . '<td class="num">' . $num($o['estibas']) . '</td>'
               . '<td class="num">' . $num($o['peso_kg']) . '</td>'
               . '<td class="num">' . $m3($o['m3']) . '</td>'
               . '<td class="num">' . $plata($o['valor']) . '</td>'
               . '<td>' . $carro . '</td>'
               . '</tr>';
    }

    $html .= '</tbody><tr class="totales">'
           . '<td colspan="2">TOTAL · ' . $num($t['ordenes']) . ' órdenes</td>'
           . '<td class="num">' . $num($t['cajas']) . '</td>'
           . '<td class="num">' . $num($t['unidades']) . '</td>'
           . '<td class="num">' . $num($t['estibas']) . '</td>'
           . '<td class="num">' . $num($t['peso_kg']) . '</td>'
           . '<td class="num">' . $m3($t['m3']) . '</td>'
           . '<td class="num">' . $plata($t['valor']) . '</td>'
           . '<td></td>'
           . '</tr></table>';

    // ---------------- Notas ----------------
    // Lo mismo que avisa la pantalla. Un papel sin estas notas se leería como completo, y alguien
    // podría pedir un vehículo con un volumen al que le faltan productos.
    $html .= '<div class="nota"><strong>* Estibas:</strong> pendiente. Todavía no hay regla para calcularlas y van en 0; '
           . 'no se usan para elegir el carro. Peso = cajas × ' . ORDENES_KG_POR_CAJA . ' kg. '
           . 'Valor = unidades × precio bruto. Carro = vehículo seco más chico en el que entran el peso y el volumen de la orden.</div>';

    if (!empty($datos['sin_cubicaje']) || $t['sin_maestro'] > 0 || $t['sin_precio'] > 0) {
        $partes = [];
        if (!empty($datos['sin_cubicaje'])) {
            $partes[] = count($datos['sin_cubicaje']) . ' producto(s) sin cubicaje, que no suman m³ ('
                      . implode(', ', array_map(fn($sku) => $esc($sku), array_keys($datos['sin_cubicaje']))) . ')';
        }
        if ($t['sin_maestro'] > 0) {
            $partes[] = $num($t['sin_maestro']) . ' línea(s) sin maestro, que no suman cajas, peso ni m³';
        }
        if ($t['sin_precio'] > 0) {
            $partes[] = $num($t['sin_precio']) . ' línea(s) sin precio, que no suman valor';
        }

        $html .= '<div class="nota"><strong>† Datos incompletos:</strong> ' . implode('; ', $partes) . '. '
               . 'En las órdenes marcadas, el peso y el volumen reales son mayores que los de la tabla, y el carro '
               . 'marcado "(revisar)" podría quedar chico.</div>';
    }

    // ---------------- El PDF ----------------
    $opciones = new Options();
    $opciones->set('isRemoteEnabled', false);   // el logo va como data URI; nada sale a la red
    $opciones->set('defaultFont', 'Helvetica');

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . cssConsolidadoPdf()
        . '</style></head><body>' . $html . '</body></html>',
        'UTF-8'
    );
    $dompdf->setPaper('letter', 'landscape');
    $dompdf->render();

    $nombre = 'Ordenes_de_compra_Exito_'
            . ($filtros['oc'] !== '' ? trim(preg_replace('/[^A-Za-z0-9]+/', '_', $filtros['oc']), '_') . '_' : '')
            . date('Ymd') . '.pdf';

    // Se limpia cualquier salida previa: si algo ya se imprimió, se mezcla con los bytes del PDF y el
    // archivo llega corrupto.
    if (ob_get_length()) {
        ob_end_clean();
    }

    return descargaPdf($dompdf, $nombre);
}
