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
require_once __DIR__ . '/helper_rotulos_lista.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Picqer\Barcode\BarcodeGeneratorPNG;

function logoRotuloDataUri() {
    $ruta = ROOT_PATH . '/assets/img/monterojo.png';
    return is_file($ruta)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($ruta))
        : '';
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
    // La página es el sticker COMPLETO y el margen de página deja el mismo aire al filo que la
    // etiquetadora: (100 - 95) / 2 = 2,5mm por lado. Se hace con @page y no con un margin en el
    // rótulo porque dompdf colapsa los márgenes de forma impredecible al cambiar de página.
    $pagAncho = ROTULO_ANCHO_MM;
    $pagAlto  = ROTULO_ALTO_MM;
    $margen   = ($pagAncho - ROTULO_DIBUJO_ANCHO_MM) / 2;

    // Ancho y alto en "content-box" a mano, y NO con box-sizing:border-box, aunque border-box es
    // lo que se usa en pantalla (ver 04-rotulo.css) y ahí SÍ funciona perfecto. dompdf no lo
    // respeta de forma confiable para el alto: probado en este mismo archivo, un <div> vacío con
    // box-sizing:border-box en una página de su mismo alto igual se pasaba a una segunda página
    // —como si el padding y el borde se sumaran ENCIMA en vez de repartirse adentro—. La única
    // combinación que da exactamente 1 página es calcular el contenido a mano y dejar que padding
    // y borde se sumen por fuera, que es como dompdf los aplica de verdad.
    $cont = ROTULO_DIBUJO_ANCHO_MM - 2 * 4 - 2 * 0.8;   // menos el padding y el borde de cada lado

    // Todos los valores van en una sola línea (nowrap). Es deliberado: si el texto se parte, el
    // rótulo crece de alto, se pasa de la página y dompdf lo manda a una SEGUNDA hoja —que en una
    // impresora de etiquetas significa una etiqueta en blanco—. Por eso el cuerpo de letra se
    // elige según el largo del texto (ver htmlRotuloPdf), igual que hace la etiquetadora con sus
    // fuentes de ancho fijo: primero se achica la letra, y recién si no alcanza se recorta.
    return <<<CSS
@page { size: {$pagAncho}mm {$pagAlto}mm; margin: {$margen}mm; }
body  { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }

.rotulo {
    width: {$cont}mm;
    height: {$cont}mm;
    padding: 4mm;
    border: 0.8mm solid #000;
    overflow: hidden;
}

.rotulo-marca {
    border-bottom: 0.6mm solid #000;
    padding-bottom: 1.5mm;
    margin-bottom: 1.5mm;
}
.rotulo-marca img { width: 12mm; height: 12mm; vertical-align: middle; }
.rotulo-marca span {
    display: inline-block; vertical-align: middle; margin-left: 3mm;
    font-size: 4mm; font-weight: bold; letter-spacing: 0.5mm; text-transform: uppercase;
}

.rotulo-campo { margin-bottom: 1.4mm; }
.rotulo-etiqueta {
    display: block; font-size: 2.4mm; line-height: 1.1; letter-spacing: 0.3mm;
    text-transform: uppercase; color: #444;
}
.rotulo-valor {
    display: block; font-weight: bold; line-height: 1.05;
    white-space: nowrap; overflow: hidden;
}

.rotulo-conteo {
    border-top: 0.6mm solid #000; padding-top: 1.5mm;
    text-align: center; font-size: 7.5mm; line-height: 1.05;
    font-weight: bold; letter-spacing: 0.4mm;
}

.rotulo-codigo { margin-top: 1.5mm; text-align: center; }
/* Ancho FIJO en mm y no en 100%: dompdf calcula el porcentaje sobre el tamaño NATURAL de la
   imagen en algunos casos, y un Code 128 de 20+ caracteres genera un PNG de cientos de píxeles de
   ancho —bastante más que el rótulo—, así que el "100%" terminaba desbordando la página. */
.rotulo-codigo img { display: block; width: {$cont}mm; height: 12mm; margin: 0 auto; }
.rotulo-codigo-texto {
    display: block; margin-top: 0.6mm; font-family: 'Courier New', Courier, monospace;
    font-size: 2.8mm; line-height: 1.1; letter-spacing: 0.2mm;
    white-space: nowrap; overflow: hidden;
}
CSS;
}

/**
 * El cuerpo de letra de un valor, en milímetros, según cuán largo sea el texto.
 *
 * Es el equivalente de textoQueEntre() de la etiquetadora (ver helper_rotulos_tspl.php): allá se
 * elige entre las fuentes de ancho fijo de la impresora, y acá entre tres tamaños. Sin esto, un
 * punto de venta largo se partiría en dos renglones, el rótulo crecería de alto y se iría a una
 * segunda página —o sea, una etiqueta en blanco.
 *
 * $escala permite usar la misma progresión para valores que arrancan más chicos, como el producto.
 */
function cuerpoValorPdf($texto, $escala = 1.0) {
    $largo = mb_strlen(trim((string) $texto));

    if ($largo <= 22) { return round(5.5 * $escala, 2); }
    if ($largo <= 30) { return round(4.5 * $escala, 2); }
    if ($largo <= 40) { return round(3.6 * $escala, 2); }
    return round(3.0 * $escala, 2);
}
function htmlRotuloPdf($logo, $pv, $oc, $cedi, $numero, $total, $producto, $tiendaParaCodigo = null) {
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $idCaja  = identificadorDeCajaRotulo($oc, $tiendaParaCodigo ?? $pv, $numero);
    $codigo  = codigoBarrasDataUri($idCaja);
    $nombreProducto = trim((string) $producto) !== ''
        ? $esc($producto)
        : '<span style="color:#888">________________</span>';

    // El mismo orden y las mismas etiquetas que imprime la etiquetadora (ver tsplDeUnRotulo en
    // helper_rotulos_tspl.php). Si acá se agrega o se mueve un campo, allá también: el PDF es el
    // respaldo del papel, y dos rótulos que no dicen lo mismo son peor que no tener respaldo.
    $campos = [
        ['Punto de venta',  $esc($pv),                       cuerpoValorPdf($pv)],
        ['Orden de compra', $esc($oc !== '' ? $oc : '-'),     cuerpoValorPdf($oc, 0.75)],
        ['Cajas total',     (int) $total,                     cuerpoValorPdf((string) $total, 0.75)],
        ['Producto',        $nombreProducto,                  cuerpoValorPdf($producto, 0.70)],
        ['CEDI',            $esc($cedi !== '' ? $cedi : '-'), cuerpoValorPdf($cedi, 0.75)],
    ];

    $html = '<div class="rotulo">';

    $html .= '<div class="rotulo-marca">';
    if ($logo !== '') {
        $html .= '<img src="' . $logo . '">';
    }
    $html .= '<span>Monterojo Gourmet</span></div>';

    foreach ($campos as [$etiqueta, $valor, $cuerpo]) {
        $html .= '<div class="rotulo-campo">'
               . '<span class="rotulo-etiqueta">' . $etiqueta . '</span>'
               . '<span class="rotulo-valor" style="font-size: ' . $cuerpo . 'mm">' . $valor . '</span>'
               . '</div>';
    }

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
 *
 * Traduce las entregas a la lista plana que arma el PDF de verdad (ver más abajo).
 */
function descargarRotulosPdf(array $entregas, $nombreArchivo) {
    if (isset($entregas['lineas'])) {
        $entregas = [$entregas];
    }

    $rotulos = [];

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
                $rotulos[] = [
                    'pv'       => $entrega['punto_venta'],
                    'oc'       => $entrega['orden_compra'],
                    'cedi'     => $entrega['cedi'],
                    'ean_pv'   => $tienda,
                    'numero'   => $desde + $i,
                    'total'    => $totalPedido,
                    'producto' => $producto,
                ];
            }
        }
    }

    descargarRotulosPdfDeLista($rotulos, $nombreArchivo);
    // descargarRotulosPdfDeLista() termina la ejecución.
}

/**
 * Genera el PDF a partir de una LISTA YA RESUELTA de rótulos: cada elemento es una caja concreta
 * y trae todo lo que va impreso en ella (pv, oc, cedi, ean_pv, numero, total, producto).
 *
 * Existe para que Picking pueda pedir el PDF de lo que tiene en pantalla en ese momento. Ahí el
 * rótulo es EDITABLE —se puede cambiar la cantidad, desde qué caja arranca, el nombre de la
 * tienda o el producto antes de imprimir— y además, cuando se abre para un pedido entero, cada
 * caja lleva un producto distinto. Recalcular todo eso de nuevo en el servidor a partir del
 * pedido daría un PDF que NO es el que la persona está viendo, que es exactamente lo que no se
 * quiere de un botón que dice "descargar esto". Mandando la lista ya armada, el PDF y la vista
 * previa no pueden discrepar.
 *
 * Por eso mismo NO se valida contra la base: son datos que el usuario puede haber editado a mano
 * a propósito. Lo único que se hace es recortar los largos y acotar los números, para que un POST
 * armado por fuera no pueda pedir diez mil páginas ni meter texto sin límite.
 */
function descargarRotulosPdfDeLista(array $rotulos, $nombreArchivo) {
    // El recorte de largos, los topes y el descarte de rótulos sin punto de venta salen del helper
    // compartido, el mismo que usa la impresión directa en la etiquetadora (ver
    // helper_rotulos_lista.php): el PDF y el papel que sale de la TSC tienen que decir lo mismo.
    $logo = logoRotuloDataUri();
    $paginas = [];

    foreach (normalizarListaDeRotulos($rotulos) as $r) {
        $paginas[] = htmlRotuloPdf(
            $logo,
            $r['pv'],
            $r['oc'],
            $r['cedi'],
            $r['numero'],
            $r['total'],
            $r['producto'],
            $r['ean_pv'] !== '' ? $r['ean_pv'] : null
        );
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
    // Los milímetros se pasan a puntos PostScript (72 por pulgada, 25.4mm por pulgada), que es
    // la única unidad en la que dompdf acepta un tamaño de página a medida.
    $aPuntos = fn($mm) => $mm * 72 / 25.4;
    $dompdf->setPaper([0, 0, $aPuntos(ROTULO_ANCHO_MM), $aPuntos(ROTULO_ALTO_MM)], 'portrait');
    $dompdf->render();

    if (ob_get_length()) {
        ob_end_clean();
    }

    $dompdf->stream($nombreArchivo, ['Attachment' => true]);
    exit();
}
