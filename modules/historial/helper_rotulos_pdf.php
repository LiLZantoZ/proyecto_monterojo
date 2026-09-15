<?php
// modules/historial/helper_rotulos_pdf.php
// Descarga en PDF los rótulos de una o varias entregas, uno por caja —el mismo rótulo que se ve
// en el modal de la pantalla (ver assets/js/rotulo.js), pero como archivo en vez
// de mandarlo directo a la impresora del navegador. Sirve para guardarlo, mandarlo por fuera del
// sistema, o imprimirlo desde otro equipo.
//
// La numeración y el producto de cada caja salen de agruparPorEntregaHistorial() (cajas_rotulo,
// caja_desde, cajas_pedido): son los mismos datos que arma el botón "Rótulos" de la pantalla, así
// que el PDF no puede discrepar de lo que ya se imprimió el día del despacho.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/helper_rotulos_lista.php';
require_once __DIR__ . '/helper_rotulos_enlace.php';

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
    // respeta de forma confiable para el alto: un <div> con box-sizing:border-box en una página de
    // su mismo alto igual se pasaba a una segunda página —como si el padding y el borde se sumaran
    // ENCIMA en vez de repartirse adentro—. La única combinación que da exactamente 1 página es
    // calcular el contenido a mano y dejar que padding y borde se sumen por fuera.
    $cont = ROTULO_DIBUJO_ANCHO_MM - 2 * 4 - 2 * 0.8;   // menos el padding y el borde de cada lado

    // Todos los valores van en una sola línea (nowrap). Es deliberado: si el texto se parte, el
    // rótulo crece de alto, se pasa de la página y dompdf lo manda a una SEGUNDA hoja —que en una
    // impresora de etiquetas significa una etiqueta en blanco—. Por eso el cuerpo de letra se
    // elige según el largo del texto (ver cuerpoValorPdf), igual que hace la etiquetadora con sus
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

/* 0,8mm y no 1,4 (2026-09-14): medido con dompdf, con 1,4mm un rótulo de nombres CORTOS —que se
   imprimen con la letra más grande— no entraba en la página y el pie se iba a una segunda hoja, que
   en la etiquetadora es una etiqueta en blanco. Pasaba en el rótulo común y en el de Cajas por punto
   de venta. El límite medido está entre 1,1 (entra) y 1,2 (no entra); 0,8 deja 1,5mm de holgura. */
.rotulo-campo { margin-bottom: 0.8mm; }
.rotulo-etiqueta {
    display: block; font-size: 2.4mm; line-height: 1.1; letter-spacing: 0.3mm;
    text-transform: uppercase; color: #444;
}

/* La etiqueta del campo DESTACADO: más grande, en negrita y en negro. Junto con el valor enorme
   es lo que hace que el número del punto de venta se encuentre de un vistazo entre los cinco
   renglones — es el dato que se busca cuando las cajas ya están estibadas y solo se ve el canto
   de la etiqueta. */
.rotulo-etiqueta-fuerte {
    font-size: 3.2mm;
    font-weight: bold;
    color: #000;
}

.rotulo-valor {
    display: block; font-weight: bold; line-height: 1.05;
    white-space: nowrap; overflow: hidden;
}

/* FORMATO ÉXITO (Cajas por punto de venta). La etiqueta del número, del tamaño del nombre de la
   tienda; y el bloque "CEDI: 149" a la derecha. Las medidas son las mismas de 04-rotulo.css. */
.rotulo-con-cedi .rotulo-etiqueta-fuerte { font-size: 4.5mm; letter-spacing: 0.1mm; }
table.rotulo-campo-cedi { width: 100%; border-collapse: collapse; }
table.rotulo-campo-cedi td { border: none; padding: 0; vertical-align: middle; }
td.rotulo-cedi {
    width: 1%; text-align: right; white-space: nowrap; padding-left: 3mm;
    font-size: 7.5mm; font-weight: bold; line-height: 1;
}

/* El pie: el contador a la izquierda y el QR a la derecha, en el mismo renglón. Se arma con una
   tabla y no con flex porque dompdf no soporta flexbox de forma confiable. */
.rotulo-pie {
    width: 100%;
    border-top: 0.6mm solid #000;
    padding-top: 1.5mm;
    margin-top: 1mm;
}
.rotulo-pie td { border: none; padding: 0; vertical-align: middle; }

.rotulo-conteo {
    text-align: center; font-size: 8mm; line-height: 1.05;
    font-weight: bold; letter-spacing: 0.4mm;
}

/* El cuadrado del QR no se deforma nunca: un QR estirado no lo lee ningún celular. */
.rotulo-qr { width: 13mm; height: 13mm; display: block; }
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
/**
 * El QR de un rótulo como data URI, o cadena vacía si no se puede armar.
 *
 * Va incrustado y no como URL porque dompdf no resuelve rutas http de forma fiable, y con la CSP
 * de este proyecto tampoco debería salir a buscarlas.
 */
function qrRotuloDataUri($enlace) {
    if ($enlace === null || $enlace === '') {
        return '';
    }

    $qr = new Endroid\QrCode\QrCode(
        data: $enlace,
        size: 320,
        margin: 0,
        errorCorrectionLevel: Endroid\QrCode\ErrorCorrectionLevel::Low
    );

    return 'data:image/png;base64,'
         . base64_encode((new Endroid\QrCode\Writer\PngWriter())->write($qr)->getString());
}

/**
 * Un rótulo en HTML, con el MISMO diseño y el mismo orden de campos que imprime la etiquetadora
 * (ver tsplDeUnRotulo en helper_rotulos_tspl.php). Si acá se agrega o se mueve un campo, allá
 * también: el PDF es el respaldo del papel, y dos rótulos que no dicen lo mismo son peor que no
 * tener respaldo.
 *
 * $logo se mantiene por compatibilidad con quien ya llamaba a esta función.
 */
function htmlRotuloPdf($logo, $pv, $oc, $cedi, $numero, $total, $producto, $tiendaParaCodigo = null,
                       $numeroPv = '', $sku = '', $enlace = null, $numeroCedi = '') {
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $nombreProducto = trim((string) $producto) !== ''
        ? $esc($producto)
        : '<span style="color:#888">________________</span>';

    // El SKU acompaña a la etiqueta del producto en vez de ocupar su propio renglón: hay productos
    // que comparten nombre y solo se distinguen por él (ver productoDelMaestro), así que tiene que
    // estar; pero un renglón entero para cinco dígitos le robaría altura al nombre.
    $etiquetaProducto = trim((string) $sku) !== ''
        ? 'Producto  ·  SKU ' . $esc($sku)
        : 'Producto';

    // La orden de compra salió de la etiqueta el 2026-09-12: ya va en la planilla, y en la caja lo
    // que se mira es a qué tienda va. En su lugar entró el NÚMERO del punto de venta, destacado.
    // El número del punto de venta va al 2,3 cuando es corto. Si la tienda viene identificada por
    // su EAN de 13 dígitos, a ese tamaño medía unos 91mm en un renglón de 85 y salía cortado; ahí va
    // a dos tercios, la misma proporción que usa la etiquetadora (el triple o el doble).
    $escalaNumero = mb_strlen(trim((string) $numeroPv)) <= 6 ? 2.3 : 1.53;

    $campos = [
        ['Punto de venta',    $esc($pv),                        cuerpoValorPdf($pv),        false],
        ['N° punto de venta', $esc($numeroPv !== '' ? $numeroPv : '—'),
                                                                cuerpoValorPdf($numeroPv, $escalaNumero), true],
        ['Cajas total',       (int) $total,                     cuerpoValorPdf((string) $total, 0.75), false],
        [$etiquetaProducto,   $nombreProducto,                  cuerpoValorPdf($producto, 0.70), false],
        ['CEDI',              $esc($cedi !== '' ? $cedi : '-'), cuerpoValorPdf($cedi, 0.75), false],
    ];

    // FORMATO ÉXITO: con número de CEDI va "CEDI: 149" a la derecha del número del punto de venta,
    // o del renglón de cajas total si al lado del número no entra. Ver cediVaAlLadoDelNumero().
    $numeroCedi   = trim((string) $numeroCedi);
    $textoCedi    = $numeroCedi !== '' ? 'CEDI: ' . $numeroCedi : '';
    $campoDelCedi = $textoCedi === ''
        ? null
        : (cediVaAlLadoDelNumero($numeroPv !== '' ? $numeroPv : '-', $numeroCedi) ? 1 : 2);

    $html = '<div class="rotulo' . ($textoCedi !== '' ? ' rotulo-con-cedi' : '') . '">';

    $html .= '<div class="rotulo-marca">';
    if ($logo !== '') {
        $html .= '<img src="' . $logo . '">';
    }
    $html .= '<span>Monterojo Gourmet</span></div>';

    foreach ($campos as $i => [$etiqueta, $valor, $cuerpo, $fuerte]) {
        $texto = '<span class="rotulo-etiqueta' . ($fuerte ? ' rotulo-etiqueta-fuerte' : '') . '">'
               . $etiqueta . '</span>'
               . '<span class="rotulo-valor" style="font-size: ' . $cuerpo . 'mm">' . $valor . '</span>';

        if ($i === $campoDelCedi) {
            // Tabla y no flex: dompdf no soporta flexbox de forma confiable (igual que el pie).
            $html .= '<table class="rotulo-campo rotulo-campo-cedi"><tr>'
                   . '<td>' . $texto . '</td>'
                   . '<td class="rotulo-cedi">' . $esc($textoCedi) . '</td>'
                   . '</tr></table>';
        } else {
            $html .= '<div class="rotulo-campo">' . $texto . '</div>';
        }
    }

    // El pie: contador y QR en el mismo renglón. El QR lleva el enlace a la página del rótulo —lo
    // único que un celular sabe abrir al escanear— y ahí se ve el EAN, que en la etiqueta no se
    // imprime. Sin enlace, el contador ocupa todo el ancho.
    $qr = qrRotuloDataUri($enlace);

    $html .= '<table class="rotulo-pie"><tr>'
           . '<td><div class="rotulo-conteo">CAJ ' . (int) $numero . ' DE ' . (int) $total . '</div></td>';
    if ($qr !== '') {
        $html .= '<td width="60"><img class="rotulo-qr" src="' . $qr . '"></td>';
    }
    $html .= '</tr></table>';

    return $html . '</div>';
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

            // El número de la tienda lo trae la entrega (ver agruparPorEntregaHistorial); si quien
            // llama armó la entrega a mano y no lo puso, se saca del nombre igual que allá.
            $numeroPv = $entrega['numero_pv']
                ?? numeroYNombreDePunto($entrega['punto_venta'], $entrega['ean_punto_venta'] ?? null)['numero'];

            for ($i = 0; $i < $cajas; $i++) {
                $rotulos[] = [
                    'pv'        => $entrega['punto_venta'],
                    'numero_pv' => $numeroPv,
                    'oc'        => $entrega['orden_compra'],
                    'cedi'      => $entrega['cedi'],
                    'ean_pv'    => $tienda,
                    'numero'    => $desde + $i,
                    'total'     => $totalPedido,
                    'producto'  => $producto,
                    'sku'       => (string) ($linea['sku'] ?? ''),
                    'ean'       => (string) ($linea['ean_item'] ?? ''),
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
            $r['ean_pv'] !== '' ? $r['ean_pv'] : null,
            $r['numero_pv'],
            $r['sku'],
            enlaceDeRotulo($GLOBALS['pdo'] ?? null, $r),
            $r['numero_cedi']
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
