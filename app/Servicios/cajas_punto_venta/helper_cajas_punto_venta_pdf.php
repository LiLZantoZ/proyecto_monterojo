<?php
// app/Servicios/cajas_punto_venta/helper_cajas_punto_venta_pdf.php
// El PDF de la planilla de cajas por punto de venta.
//
// En el sistema anterior estas dos funciones vivían dentro del controlador
// (modules/cajas_punto_venta/controller_cajas_punto_venta.php); se mudaron acá sin cambios de
// contenido, salvo que ahora DEVUELVEN la descarga en vez de cortar el pedido con exit().
//
// El PDF se arma con la misma hoja de estilos que los consolidados (cssConsolidadoPdf) porque es
// el mismo juego de papeles: quien recibe la entrega en el CEDI va a tener los dos en la mano.

require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/../consolidados/helper_consolidado_pdf.php';   // css y logo compartidos

use Dompdf\Dompdf;
use Dompdf\Options;

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

// Arma el PDF de $porCedi (la salida de cajasPorPuntoDeVenta) y devuelve la descarga. La usan la
// acción 'pdf' (todo lo filtrado, o un CEDI) y 'pdf_seleccion' (solo los puntos tildados): es la
// MISMA plantilla en los dos casos.
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

    return descargaPdf($dompdf, $nombreArchivo);
}
