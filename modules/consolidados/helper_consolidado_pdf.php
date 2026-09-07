<?php
// modules/consolidados/helper_consolidado_pdf.php
// El consolidado que se le entrega al elevador, en PDF: una hoja por CEDI con lo que hay que
// bajar de bodega, en cajas y saldos.
//
// Se arma como HTML propio y se pasa por dompdf, en vez de convertir una hoja de Excel: un .xlsx
// pensado para pantalla trae anchos y cuerpos de letra que en papel desbordan la hoja. Acá las
// medidas son de impresión desde el principio.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/model_consolidados.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// dompdf no resuelve rutas http de forma fiable (y con la CSP de este proyecto tampoco debería
// salir a buscarlas): el logo va incrustado como data URI.
function logoConsolidadoDataUri() {
    $ruta = ROOT_PATH . '/assets/img/monterojo.png';
    return is_file($ruta)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($ruta))
        : '';
}

function cssConsolidadoPdf() {
    return <<<CSS
@page { margin: 12mm 10mm; }
body  { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; font-size: 9pt; }

.encabezado { border-bottom: 2pt solid #000; padding-bottom: 6pt; margin-bottom: 10pt; }
.encabezado td { vertical-align: middle; border: none; padding: 0; }
.logo { width: 46pt; }
.titulo { font-size: 15pt; font-weight: bold; }
.cedi   { font-size: 12pt; margin-top: 2pt; }
.meta   { font-size: 8pt; color: #444; text-align: right; line-height: 1.5; }

table.datos { width: 100%; border-collapse: collapse; margin-top: 4pt; }
table.datos th {
    background: #111; color: #fff; font-size: 7.5pt; text-transform: uppercase;
    letter-spacing: 0.3pt; padding: 5pt 4pt; text-align: left; border: 0.5pt solid #111;
}
table.datos td { padding: 4pt; border: 0.5pt solid #bbb; }
table.datos tr.par td { background: #f6f4f1; }

.num { text-align: right; }
.centro { text-align: center; }
.falta { color: #8e1b1b; font-style: italic; }

tr.totales td {
    background: #111; color: #fff; font-weight: bold;
    border: 0.5pt solid #111; padding: 5pt 4pt;
}

.nota {
    margin-top: 10pt; padding: 6pt 8pt; font-size: 7.5pt;
    background: #fdf3e3; border-left: 2pt solid #8e1b1b; color: #7c4a03;
}

.firmas { margin-top: 22pt; width: 100%; font-size: 8pt; }
.firmas td { border: none; padding-top: 22pt; }
.linea-firma { border-top: 0.5pt solid #000; padding-top: 3pt; width: 65%; }
CSS;
}

// Una hoja del PDF: el encabezado del CEDI y su tabla.
function seccionCediPdf($cedi, array $filas, $meta) {
    $totales = totalesDelGrupo($filas);
    $logo    = logoConsolidadoDataUri();
    $esc     = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');

    $html = '<table class="encabezado" width="100%"><tr>';
    if ($logo !== '') {
        $html .= '<td width="60"><img src="' . $logo . '" class="logo"></td>';
    }
    $html .= '<td><div class="titulo">Consolidado para alistamiento</div>'
           . '<div class="cedi">' . $esc($cedi) . '</div></td>'
           . '<td class="meta">Emitido: ' . date('d/m/Y H:i') . '<br>'
           . 'Archivo: ' . $esc($meta['nombre_archivo'] ?? '') . '<br>'
           . 'Productos: ' . $totales['productos'] . '</td>'
           . '</tr></table>';

    $html .= '<table class="datos">'
           . '<thead><tr>'
           . '<th width="9%">PLU</th><th width="9%">SKU</th><th>Descripción</th>'
           . '<th width="9%" class="num">Unidades</th>'
           . '<th width="8%" class="num">Cajas</th>'
           . '<th width="8%" class="num">Saldos</th>'
           . '<th width="7%" class="centro">Ptos</th>'
           . '</tr></thead><tbody>';

    // Las filas se pintan alternadas desde PHP porque dompdf no soporta :nth-child.
    $i = 0;
    foreach ($filas as $f) {
        $clase = (++$i % 2 === 0) ? ' class="par"' : '';
        $html .= '<tr' . $clase . '>'
              . '<td>' . $esc($f['plu']) . '</td>'
              . '<td>' . ($f['sku'] !== null ? $esc($f['sku']) : '<span class="falta">—</span>') . '</td>'
              . '<td>' . ($f['descripcion'] !== null ? $esc($f['descripcion']) : '<span class="falta">sin descripción en el maestro</span>') . '</td>'
              . '<td class="num">' . number_format((int) $f['unidades'], 0, ',', '.') . '</td>';

        if ($f['sin_maestro']) {
            // Dos celdas con una raya y no con un cero: un cero diría que no lleva ninguna caja,
            // y lo que pasa es que falta el dato para calcularlas.
            $html .= '<td class="num falta">—</td><td class="num falta">—</td>';
        } else {
            $html .= '<td class="num">' . number_format((int) $f['cajas'], 0, ',', '.') . '</td>'
                  .  '<td class="num">' . ((int) $f['saldos'] > 0 ? number_format((int) $f['saldos'], 0, ',', '.') : '') . '</td>';
        }

        $html .= '<td class="centro">' . (int) $f['puntos_venta'] . '</td></tr>';
    }

    // El peso va en la celda de "Ptos" del renglón de totales: sumar puntos de venta no significa
    // nada (la misma tienda aparece en varios productos), y el peso total sí decide en qué
    // vehículo entra lo que se está bajando.
    $peso = $totales['peso_kg'] > 0
        ? number_format($totales['peso_kg'], 0, ',', '.') . ' kg'
        : '';

    $html .= '</tbody><tr class="totales">'
           . '<td colspan="3">TOTAL ' . $esc($cedi) . '</td>'
           . '<td class="num">' . number_format($totales['unidades'], 0, ',', '.') . '</td>'
           . '<td class="num">' . number_format($totales['cajas'], 0, ',', '.') . '</td>'
           . '<td class="num">' . number_format($totales['saldos'], 0, ',', '.') . '</td>'
           . '<td class="num">' . $peso . '</td></tr></table>';

    if ($totales['sin_maestro'] > 0) {
        $html .= '<div class="nota"><strong>Atención:</strong> ' . $totales['sin_maestro']
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
 * Genera el PDF y lo manda al navegador como descarga. No devuelve: termina la ejecución.
 *
 * $porCedi es la salida de consolidadoPorCedi(). Si trae varios CEDI, cada uno va en su propia
 * hoja: así el mismo archivo sirve para imprimir todo de una y repartir una hoja a cada elevador.
 */
function descargarConsolidadoPdf(array $porCedi, $meta, $nombreArchivo) {
    $opciones = new Options();
    $opciones->set('isRemoteEnabled', false);   // el logo va como data URI; nada sale a la red
    $opciones->set('defaultFont', 'Helvetica');

    $paginas = [];
    foreach ($porCedi as $cedi => $filas) {
        $paginas[] = seccionCediPdf($cedi, $filas, $meta);
    }

    // El salto va ENTRE hojas y no al final de cada una: con un page-break después de la última,
    // el PDF termina con una página en blanco.
    $cuerpo = implode('<div style="page-break-before: always;"></div>', $paginas);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
          . cssConsolidadoPdf() . '</style></head><body>' . $cuerpo . '</body></html>';

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

// Deja el nombre del CEDI usable como nombre de archivo: "CEDI YUMBO - 09" -> "CEDI_YUMBO_09".
function nombreArchivoCedi($cedi) {
    $limpio = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $cedi);
    $limpio = trim($limpio, '_');
    return 'Consolidado_' . ($limpio !== '' ? $limpio : 'CEDI') . '_' . date('Ymd') . '.pdf';
}
