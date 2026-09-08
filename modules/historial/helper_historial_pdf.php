<?php
// modules/historial/helper_historial_pdf.php
// El reporte general del Historial: todos los pedidos despachados que cumplen el filtro, en un
// solo PDF, una hoja por CEDI. No es la hoja de alistamiento de un pedido (esa la genera
// helper_picking_pdf.php, reutilizada para el reimpreso individual) — esto es el LISTADO completo
// que se ve en pantalla, para archivar o mandar por fuera del sistema.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function logoHistorialDataUri() {
    $ruta = ROOT_PATH . '/assets/img/monterojo.png';
    return is_file($ruta)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($ruta))
        : '';
}

function cssHistorialPdf() {
    return <<<CSS
@page { margin: 12mm 10mm; }
body  { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; font-size: 9pt; }

.encabezado { border-bottom: 2pt solid #000; padding-bottom: 6pt; margin-bottom: 10pt; }
.encabezado td { vertical-align: middle; border: none; padding: 0; }
.logo   { width: 46pt; }
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

.num    { text-align: right; }
.centro { text-align: center; }
.falta  { color: #8e1b1b; font-style: italic; }

tr.totales td {
    background: #111; color: #fff; font-weight: bold;
    border: 0.5pt solid #111; padding: 5pt 4pt;
}

.sin-resultados { padding: 20pt 0; text-align: center; color: #555; }
CSS;
}

// Una hoja del PDF: el encabezado del CEDI y su tabla de PEDIDOS (no de productos: esto es el
// listado del Historial, no una hoja de alistamiento).
function seccionCediHistorialPdf($cedi, array $grupo) {
    $t    = $grupo['totales'];
    $logo = logoHistorialDataUri();
    $esc  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $html = '<table class="encabezado" width="100%"><tr>';
    if ($logo !== '') {
        $html .= '<td width="60"><img src="' . $logo . '" class="logo"></td>';
    }
    $html .= '<td><div class="titulo">Historial de Pedidos</div>'
           . '<div class="cedi">' . $esc($cedi) . '</div></td>'
           . '<td class="meta">Emitido: ' . date('d/m/Y H:i') . '<br>'
           . 'Pedidos: ' . $t['pedidos'] . '</td>'
           . '</tr></table>';

    $html .= '<table class="datos">'
           . '<thead><tr>'
           . '<th>Punto de venta</th><th width="12%">O/C</th>'
           . '<th width="15%">Alistado por</th><th width="15%">Despachado por</th>'
           . '<th width="12%">Fecha</th>'
           . '<th width="8%" class="num">Cajas</th>'
           . '<th width="8%" class="num">Peso</th>'
           . '</tr></thead><tbody>';

    $i = 0;
    foreach ($grupo['entregas'] as $entrega) {
        $et = $entrega['totales'];
        $clase = (++$i % 2 === 0) ? ' class="par"' : '';

        $html .= '<tr' . $clase . '>'
              . '<td>' . $esc($entrega['punto_venta']) . '</td>'
              . '<td>' . ($entrega['orden_compra'] !== '' ? $esc($entrega['orden_compra']) : '<span class="falta">—</span>') . '</td>'
              . '<td>' . ($entrega['personal_nombre'] !== null ? $esc($entrega['personal_nombre']) : '<span class="falta">—</span>') . '</td>'
              . '<td>' . ($entrega['usuario_nombre'] !== null ? $esc($entrega['usuario_nombre']) : '<span class="falta">—</span>') . '</td>'
              . '<td>' . ($entrega['fecha_despacho'] ? date('d/m/Y H:i', strtotime($entrega['fecha_despacho'])) : '<span class="falta">—</span>') . '</td>'
              . '<td class="num">' . number_format($et['cajas_rotulo'], 0, ',', '.') . '</td>'
              . '<td class="num">' . ($et['peso_kg'] > 0 ? number_format($et['peso_kg'], 1, ',', '.') . ' kg' : '') . '</td>'
              . '</tr>';
    }

    $html .= '</tbody><tr class="totales">'
           . '<td colspan="5">TOTAL ' . $esc($cedi) . ' · ' . $t['pedidos'] . ' pedido(s)</td>'
           . '<td class="num">' . number_format($t['cajas_rotulo'], 0, ',', '.') . '</td>'
           . '<td class="num">' . ($t['peso_kg'] > 0 ? number_format($t['peso_kg'], 1, ',', '.') . ' kg' : '') . '</td>'
           . '</tr></table>';

    return $html;
}

/**
 * Genera el reporte del Historial completo (todos los CEDI que cumplen el filtro, uno por hoja) y
 * lo manda al navegador como descarga. No devuelve: termina la ejecución.
 *
 * $porCedi es la salida de agruparPorCediHistorial(). Igual que el PDF de Consolidados, cada CEDI
 * va en su propia página.
 */
function descargarHistorialPdf(array $porCedi, $nombreArchivo) {
    $opciones = new Options();
    $opciones->set('isRemoteEnabled', false);
    $opciones->set('defaultFont', 'Helvetica');

    $paginas = [];
    foreach ($porCedi as $cedi => $grupo) {
        $paginas[] = seccionCediHistorialPdf($cedi, $grupo);
    }

    if (!$paginas) {
        $logo = logoHistorialDataUri();
        $paginas[] = ($logo !== '' ? '<img src="' . $logo . '" style="width:46pt">' : '')
                   . '<div class="sin-resultados">No hay pedidos despachados que coincidan con el filtro.</div>';
    }

    // El salto va ENTRE hojas y no al final de cada una: con un page-break después de la última,
    // el PDF termina con una página en blanco.
    $cuerpo = implode('<div style="page-break-before: always;"></div>', $paginas);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
          . cssHistorialPdf() . '</style></head><body>' . $cuerpo . '</body></html>';

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    if (ob_get_length()) {
        ob_end_clean();
    }

    $dompdf->stream($nombreArchivo, ['Attachment' => true]);
    exit();
}
