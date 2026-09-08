<?php
// modules/picking/helper_picking_pdf.php
// La hoja de alistamiento de UNA entrega: lo que se le pasa al picker para que arme la tienda.
//
// Es una maqueta HTML propia pasada por dompdf, igual que el consolidado por CEDI. La diferencia
// con esa hoja es para quién es: el consolidado le dice al elevador qué bajar de bodega para todo
// un CEDI, y esta le dice al picker qué meter en la caja de una tienda concreta.
//
// Lleva una columna vacía para marcar. Una hoja de alistamiento sin dónde ir tildando obliga a
// llevar la cuenta de memoria, y el picker termina marcando sobre la descripción con lapicero.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function cssPickingPdf() {
    return <<<CSS
@page { margin: 12mm 10mm; }
body  { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; font-size: 9pt; }

.encabezado { border-bottom: 2pt solid #000; padding-bottom: 6pt; margin-bottom: 8pt; }
.encabezado td { vertical-align: middle; border: none; padding: 0; }
.logo   { width: 46pt; }
.titulo { font-size: 15pt; font-weight: bold; }
.tienda { font-size: 12pt; margin-top: 2pt; }
.meta   { font-size: 8pt; color: #444; text-align: right; line-height: 1.5; }

/* Los datos de la entrega, en una banda de cuatro casillas debajo del encabezado. Van arriba y
   grandes porque son los que hay que verificar ANTES de empezar a armar la caja: si la hoja es
   de otra tienda, todo lo que siga está mal. */
table.datos-entrega { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
table.datos-entrega td {
    border: 0.5pt solid #bbb; padding: 4pt 6pt; width: 25%;
}
.dato-etiqueta { font-size: 7pt; text-transform: uppercase; color: #555; letter-spacing: 0.3pt; }
.dato-valor    { font-size: 10pt; font-weight: bold; }
.dato-vacio    { color: #999; font-weight: normal; }

table.datos { width: 100%; border-collapse: collapse; }
table.datos th {
    background: #111; color: #fff; font-size: 7.5pt; text-transform: uppercase;
    letter-spacing: 0.3pt; padding: 5pt 4pt; text-align: left; border: 0.5pt solid #111;
}
table.datos td { padding: 5pt 4pt; border: 0.5pt solid #bbb; }
table.datos tr.par td { background: #f6f4f1; }

.num    { text-align: right; }
.centro { text-align: center; }
.falta  { color: #8e1b1b; font-style: italic; }

/* La casilla para tildar. Un cuadro dibujado con borde y no un carácter: un checkbox en texto
   depende de que la fuente del PDF lo tenga, y dompdf incrusta solo las básicas. */
.marca {
    display: block; width: 10pt; height: 10pt;
    border: 0.8pt solid #000; margin: 0 auto;
}

tr.totales td {
    background: #111; color: #fff; font-weight: bold;
    border: 0.5pt solid #111; padding: 5pt 4pt;
}

.nota {
    margin-top: 10pt; padding: 6pt 8pt; font-size: 7.5pt;
    background: #fdf3e3; border-left: 2pt solid #8e1b1b; color: #7c4a03;
}

.firmas { margin-top: 24pt; width: 100%; font-size: 8pt; }
.firmas td { border: none; padding-top: 22pt; }
.linea-firma { border-top: 0.5pt solid #000; padding-top: 3pt; width: 70%; }
CSS;
}

// El logo va incrustado como data URI: dompdf no resuelve rutas http de forma fiable.
function logoPickingDataUri() {
    $ruta = ROOT_PATH . '/assets/img/monterojo.png';
    return is_file($ruta)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($ruta))
        : '';
}

function htmlPickingPdf(array $entrega, $meta) {
    $esc = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    $t   = $entrega['totales'];
    $logo = logoPickingDataUri();

    $html = '<table class="encabezado" width="100%"><tr>';
    if ($logo !== '') {
        $html .= '<td width="60"><img src="' . $logo . '" class="logo"></td>';
    }
    $html .= '<td><div class="titulo">Hoja de alistamiento</div>'
           . '<div class="tienda">' . $esc($entrega['punto_venta']) . '</div></td>'
           . '<td class="meta">Emitido: ' . date('d/m/Y H:i') . '<br>'
           . 'Archivo: ' . $esc($meta['nombre_archivo'] ?? '') . '</td>'
           . '</tr></table>';

    // Quién alista. Puede estar sin asignar: se imprime la casilla igual, con una raya, para que
    // se pueda escribir a mano sobre la hoja si se reparte en el momento.
    $asignado = !empty($entrega['personal_nombre'])
        ? $esc($entrega['personal_nombre'])
        : '<span class="dato-vacio">___________</span>';

    $html .= '<table class="datos-entrega"><tr>'
           . '<td><div class="dato-etiqueta">CEDI</div><div class="dato-valor">' . $esc($entrega['cedi']) . '</div></td>'
           . '<td><div class="dato-etiqueta">Orden de compra</div><div class="dato-valor">' . $esc($entrega['orden_compra']) . '</div></td>'
           . '<td><div class="dato-etiqueta">Alista</div><div class="dato-valor">' . $asignado . '</div></td>'
           . '<td><div class="dato-etiqueta">Cajas / saldos</div><div class="dato-valor">'
           . number_format($t['cajas'], 0, ',', '.') . ' / ' . number_format($t['saldos'], 0, ',', '.')
           . '</div></td>'
           . '</tr></table>';

    $html .= '<table class="datos">'
           . '<thead><tr>'
           . '<th width="6%" class="centro">✓</th>'
           . '<th width="10%">SKU</th><th>Descripción</th>'
           . '<th width="10%" class="num">Unidades</th>'
           . '<th width="9%" class="num">Cajas</th>'
           . '<th width="9%" class="num">Saldos</th>'
           . '</tr></thead><tbody>';

    // Las filas alternadas se pintan desde PHP: dompdf no soporta :nth-child.
    $i = 0;
    foreach ($entrega['lineas'] as $linea) {
        $clase = (++$i % 2 === 0) ? ' class="par"' : '';
        $html .= '<tr' . $clase . '>'
              . '<td class="centro"><span class="marca"></span></td>'
              . '<td>' . ($linea['sku'] !== null ? $esc($linea['sku']) : $esc($linea['plu']) . ' <span class="falta">(PLU)</span>') . '</td>'
              . '<td>' . ($linea['descripcion'] !== null ? $esc($linea['descripcion']) : '<span class="falta">sin descripción en el maestro</span>') . '</td>'
              . '<td class="num">' . number_format((int) $linea['unidades'], 0, ',', '.') . '</td>';

        if ($linea['sin_maestro']) {
            // Raya y no cero: un cero diría que no lleva ninguna caja, y lo que pasa es que falta
            // el dato para calcularlas.
            $html .= '<td class="num falta">—</td><td class="num falta">—</td>';
        } else {
            $html .= '<td class="num">' . number_format((int) $linea['cajas'], 0, ',', '.') . '</td>'
                  .  '<td class="num">' . ((int) $linea['saldos'] > 0 ? number_format((int) $linea['saldos'], 0, ',', '.') : '') . '</td>';
        }

        $html .= '</tr>';
    }

    $html .= '</tbody><tr class="totales">'
           . '<td colspan="3">TOTAL · ' . $t['lineas'] . ' producto(s)'
           . ($t['peso_kg'] > 0 ? ' · ' . number_format($t['peso_kg'], 1, ',', '.') . ' kg' : '') . '</td>'
           . '<td class="num">' . number_format($t['unidades'], 0, ',', '.') . '</td>'
           . '<td class="num">' . number_format($t['cajas'], 0, ',', '.') . '</td>'
           . '<td class="num">' . number_format($t['saldos'], 0, ',', '.') . '</td>'
           . '</tr></table>';

    if ($t['sin_maestro'] > 0) {
        $html .= '<div class="nota"><strong>Atención:</strong> ' . $t['sin_maestro']
              . ' producto(s) de esta hoja no están en el maestro, así que no se pudieron convertir '
              . 'a cajas y no suman en el total. Van marcados con una raya y hay que contarlos aparte.</div>';
    }

    $html .= '<table class="firmas"><tr>'
           . '<td><div class="linea-firma">Alistado por</div></td>'
           . '<td><div class="linea-firma">Verificado por</div></td>'
           . '</tr></table>';

    return $html;
}

/**
 * Genera el PDF de UNA o VARIAS entregas y lo manda al navegador como descarga. No devuelve:
 * termina la ejecución.
 *
 * Con varias sale un solo archivo con una hoja por entrega, y no un ZIP ni varias descargas: el
 * picker imprime el lote entero de una y le quedan las hojas en orden.
 */
function descargarPickingPdf(array $entregas, $meta, $nombreArchivo = null) {
    // Se acepta tanto una entrega suelta como una lista, para que quien llame no tenga que
    // envolverla. Una entrega tiene la clave 'lineas'; una lista, no.
    if (isset($entregas['lineas'])) {
        $entregas = [$entregas];
    }

    if (!$entregas) {
        return;
    }

    $opciones = new Options();
    $opciones->set('isRemoteEnabled', false);   // el logo va como data URI; nada sale a la red
    $opciones->set('defaultFont', 'Helvetica');

    $hojas = [];
    foreach ($entregas as $entrega) {
        $hojas[] = htmlPickingPdf($entrega, $meta);
    }

    // El salto va ENTRE hojas y no al final de cada una: con un page-break después de la última,
    // el PDF termina con una página en blanco.
    $cuerpo = implode('<div style="page-break-before: always;"></div>', $hojas);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
          . cssPickingPdf() . '</style></head><body>' . $cuerpo . '</body></html>';

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    // Se limpia cualquier salida previa (un aviso de PHP, un espacio suelto antes de un <?php):
    // si algo ya se imprimió, se mezcla con los bytes del PDF y el archivo llega corrupto.
    if (ob_get_length()) {
        ob_end_clean();
    }

    if ($nombreArchivo === null) {
        $nombreArchivo = count($entregas) === 1
            ? nombreArchivoPicking($entregas[0])
            : 'Picking_' . count($entregas) . '_pedidos_' . date('Ymd_His') . '.pdf';
    }

    $dompdf->stream($nombreArchivo, ['Attachment' => true]);
    exit();
}

// "065 - EXITO UNICENTRO ARMENIA" + O/C -> "Picking_065_EXITO_UNICENTRO_ARMENIA_0138604724.pdf"
function nombreArchivoPicking(array $entrega) {
    $tienda = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $entrega['punto_venta']);
    $tienda = trim($tienda, '_');
    $orden  = preg_replace('/[^A-Za-z0-9]+/', '', (string) $entrega['orden_compra']);

    return 'Picking_' . ($tienda !== '' ? $tienda : 'entrega') . '_' . $orden . '.pdf';
}
