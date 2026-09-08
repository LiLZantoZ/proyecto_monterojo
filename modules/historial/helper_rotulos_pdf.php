<?php
// modules/historial/helper_rotulos_pdf.php
// Descarga en PDF los rótulos de una o varias entregas, uno por caja —el mismo rótulo que se ve
// en el modal de la pantalla (ver htmlRotulo() en scripts_historial.js), pero como archivo en vez
// de mandarlo directo a la impresora del navegador. Sirve para guardarlo, mandarlo por fuera del
// sistema, o imprimirlo desde otro equipo.
//
// La numeración y el producto de cada caja salen de agruparPorEntregaHistorial() (cajas_rotulo,
// caja_desde, cajas_pedido): son los mismos datos que arma el botón "Rótulos" de la pantalla, así
// que el PDF no puede discrepar de lo que ya se imprimió el día del despacho.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Picqer\Barcode\BarcodeGeneratorPNG;

function logoRotuloDataUri() {
    $ruta = ROOT_PATH . '/assets/img/monterojo.png';
    return is_file($ruta)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($ruta))
        : '';
}

// El identificador de UNA caja: orden de compra - EAN de la tienda (o el nombre, si no hay EAN) -
// número de caja. Igual que identificadorDeCaja() en scripts_historial.js.
function identificadorDeCajaPdf($oc, $tienda, $numero) {
    $limpiar = fn($v) => preg_replace('/[^A-Za-z0-9]/', '', (string) $v);
    return $limpiar($oc) . '-' . $limpiar($tienda) . '-' . $limpiar($numero);
}

function codigoBarrasDataUri($texto) {
    static $generador = null;
    if ($generador === null) {
        $generador = new BarcodeGeneratorPNG();
    }
    // Factor 2, igual que el código de barras que se ve en pantalla (ver codigo_barras en
    // controller_historial.php): con el ancho FIJO de .rotulo-codigo img, un factor más alto no
    // suma nitidez —la imagen se encoge igual— y solo hace más angostas las barras individuales
    // dentro de esa misma medida final.
    $png = $generador->getBarcode($texto, $generador::TYPE_CODE_128, 2, 70);
    return 'data:image/png;base64,' . base64_encode($png);
}

function cssRotulosPdf() {
    return <<<CSS
@page { size: 100mm 175mm; margin: 5mm; }
body  { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }

.rotulo {
    width: 90mm;
    /* Alto MÍNIMO fijo y no "lo que ocupe el contenido": sin esto, un rótulo con un nombre de
       producto corto (una sola línea) queda con un recuadro visiblemente más chico y aplastado
       que uno con un nombre largo (dos líneas) — se nota comparando dos rótulos del mismo lote.
       Con el mínimo, todos quedan del mismo tamaño; el que necesite más espacio (un nombre aún
       más largo) sigue pudiendo crecer más allá del mínimo sin desbordar la página (ver la
       altura de @page, con margen de sobra para eso). */
    min-height: 145mm;
    padding: 5mm;
    border: 1mm solid #000;
    box-sizing: border-box;
}

.rotulo-marca {
    padding-bottom: 3mm;
    margin-bottom: 4mm;
    border-bottom: 0.6mm solid #000;
}
.rotulo-marca img { width: 14mm; vertical-align: middle; }
.rotulo-marca span {
    font-size: 3.4mm; font-weight: bold; letter-spacing: 0.5mm;
    text-transform: uppercase; margin-left: 3mm;
}

.rotulo-campo { margin-bottom: 3.2mm; }
.rotulo-etiqueta {
    display: block; font-size: 2.8mm; letter-spacing: 0.3mm;
    text-transform: uppercase; color: #444;
}
.rotulo-valor { display: block; font-size: 5mm; font-weight: bold; line-height: 1.25; }
.rotulo-valor-producto { font-size: 4.2mm; line-height: 1.3; }

.rotulo-conteo {
    margin-top: 4mm; padding-top: 4mm; border-top: 0.6mm solid #000;
    text-align: center; font-size: 9mm; font-weight: bold; letter-spacing: 0.4mm;
}

.rotulo-codigo { margin-top: 3mm; text-align: center; }
/* Ancho FIJO en mm y no en 100%: dompdf calcula el porcentaje sobre el tamaño NATURAL de la
   imagen en algunos casos, y un Code 128 de 20+ caracteres genera un PNG de cientos de píxeles
   de ancho —bastante más que el rótulo—, así que el "100%" terminaba desbordando la página en
   vez de encogerlo. El ancho fijo obliga el tamaño sin importar cuán ancho salga el PNG.
   78mm = 90mm del rótulo - 5mm de padding a cada lado - 1mm de borde a cada lado. */
.rotulo-codigo img { display: block; width: 78mm; height: 14mm; margin: 0 auto; }
.rotulo-codigo-texto {
    display: block; margin-top: 1mm; font-family: 'Courier New', Courier, monospace;
    font-size: 3.2mm; letter-spacing: 0.2mm;
}
CSS;
}

function htmlRotuloPdf($logo, $pv, $oc, $cedi, $numero, $total, $producto) {
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $idCaja  = identificadorDeCajaPdf($oc, $pv, $numero);
    $codigo  = codigoBarrasDataUri($idCaja);
    $nombreProducto = trim((string) $producto) !== ''
        ? $esc($producto)
        : '<span style="color:#888">_______________</span>';

    $html = '<div class="rotulo">';
    $html .= '<div class="rotulo-marca">';
    if ($logo !== '') {
        $html .= '<img src="' . $logo . '">';
    }
    $html .= '<span>Monterojo Gourmet</span></div>';

    $html .= '<div class="rotulo-campo"><span class="rotulo-etiqueta">Punto de venta</span>'
           . '<span class="rotulo-valor">' . $esc($pv) . '</span></div>';
    $html .= '<div class="rotulo-campo"><span class="rotulo-etiqueta">Orden de compra</span>'
           . '<span class="rotulo-valor">' . $esc($oc) . '</span></div>';
    $html .= '<div class="rotulo-campo"><span class="rotulo-etiqueta">Cajas total</span>'
           . '<span class="rotulo-valor">' . (int) $total . '</span></div>';
    $html .= '<div class="rotulo-campo"><span class="rotulo-etiqueta">Producto</span>'
           . '<span class="rotulo-valor rotulo-valor-producto">' . $nombreProducto . '</span></div>';
    $html .= '<div class="rotulo-campo"><span class="rotulo-etiqueta">CEDI</span>'
           . '<span class="rotulo-valor">' . $esc($cedi) . '</span></div>';

    $html .= '<div class="rotulo-conteo">CAJ ' . (int) $numero . ' DE ' . (int) $total . '</div>';

    $html .= '<div class="rotulo-codigo">'
           . '<img src="' . $codigo . '">'
           . '<span class="rotulo-codigo-texto">' . $esc($idCaja) . '</span>'
           . '</div>';

    $html .= '</div>';

    return $html;
}

/**
 * Genera el PDF con los rótulos de UNA o VARIAS entregas —una caja por rótulo, un rótulo por
 * página— y lo manda al navegador como descarga. No devuelve: termina la ejecución.
 *
 * Las líneas sin cajas que rotular (cajas_rotulo = 0, ver agruparPorEntregaHistorial) se saltan:
 * no hay nada que pegar en una caja que no existe.
 */
function descargarRotulosPdf(array $entregas, $nombreArchivo) {
    if (isset($entregas['lineas'])) {
        $entregas = [$entregas];
    }

    $logo = logoRotuloDataUri();
    $paginas = [];

    foreach ($entregas as $entrega) {
        $totalPedido = (int) $entrega['totales']['cajas_rotulo'];
        if ($totalPedido < 1) {
            continue;
        }

        foreach ($entrega['lineas'] as $linea) {
            $cajas = (int) $linea['cajas_rotulo'];
            if ($cajas < 1) {
                continue;
            }

            $tienda   = $entrega['ean_punto_venta'] ?? $entrega['punto_venta'];
            $producto = $linea['descripcion'] ?? ($linea['sku'] ?? $linea['plu']);
            $desde    = (int) $linea['caja_desde'];

            for ($i = 0; $i < $cajas; $i++) {
                $paginas[] = htmlRotuloPdf(
                    $logo,
                    $entrega['punto_venta'],
                    $entrega['orden_compra'],
                    $entrega['cedi'],
                    $desde + $i,
                    $totalPedido,
                    $producto
                );
            }
        }
    }

    if (!$paginas) {
        // No debería llegar acá desde la pantalla (el botón se oculta sin cajas que rotular),
        // pero un enlace copiado a mano o una URL vieja sí podría pedirlo.
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(404);
        echo 'No hay rótulos que generar para lo seleccionado.';
        exit();
    }

    $opciones = new Options();
    $opciones->set('isRemoteEnabled', false);   // el logo y el código de barras van como data URI
    $opciones->set('defaultFont', 'Helvetica');

    $cuerpo = implode('<div style="page-break-before: always;"></div>', $paginas);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
          . cssRotulosPdf() . '</style></head><body>' . $cuerpo . '</body></html>';

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper([0, 0, 283.465, 496.063], 'portrait');   // 100mm x 175mm, en puntos
    $dompdf->render();

    if (ob_get_length()) {
        ob_end_clean();
    }

    $dompdf->stream($nombreArchivo, ['Attachment' => true]);
    exit();
}
