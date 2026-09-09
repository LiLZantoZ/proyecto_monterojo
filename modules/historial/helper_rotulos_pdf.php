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
    return <<<CSS
/* 100mm x 40mm exactos: el tamaño real de la etiqueta (decidido con el usuario el 2026-09-08),
   no una hoja con márgenes. Margen 0 en la página porque el rótulo ya trae su propio padding de
   2mm por dentro. Mismo diseño compacto que la vista en pantalla (ver assets/css/partes/04-rotulo.css
   para la explicación completa de qué se sacrificó para que entre en 4cm de alto): sin logo,
   sin etiquetas de campo, orden de compra y CEDI comparten renglón, y el contador de cajas subió
   a compartir el encabezado con la marca en vez de tener su propio renglón abajo. */
@page { size: 100mm 40mm; margin: 0; }
body  { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }

/* Ancho y alto en "content-box" a mano —94.4mm y 34.4mm— y NO 100mm/40mm con
   box-sizing:border-box, aunque border-box es lo que se usa en pantalla (ver 04-rotulo.css) y
   ahí SÍ funciona perfecto. dompdf no lo respeta de forma confiable para el alto: probado en
   este mismo archivo, un <div> vacío de height:40mm + box-sizing:border-box en una página de
   40mm de alto igual se pasaba a una segunda página —como si el padding y el borde se sumaran
   ENCIMA de los 40mm en vez de repartirse adentro—, y encogiendo la altura declarada hasta 38mm
   el problema seguía. La única combinación que dio exactamente 1 página fue calcular el ancho y
   el alto de CONTENIDO a mano (100mm y 40mm menos el padding y el borde de cada lado) y dejar
   que padding + borde se sumen por fuera, que es como dompdf los aplica de verdad.
   94.4mm = 100mm - 2mm de padding a cada lado - 0.8mm de borde a cada lado.
   34.4mm = 40mm  - 2mm de padding a cada lado - 0.8mm de borde a cada lado. */
.rotulo {
    width: 94.4mm;
    height: 34.4mm;
    padding: 2mm;
    border: 0.8mm solid #000;
    overflow: hidden;
}

.rotulo-marca {
    display: table;
    width: 100%;
    margin-bottom: 1mm;
}
.rotulo-marca span {
    font-size: 2.6mm; font-weight: bold; letter-spacing: 0.3mm; text-transform: uppercase;
}
.rotulo-marca .rotulo-conteo {
    display: table-cell;
    text-align: right;
    font-size: 4.2mm; font-weight: bold; letter-spacing: 0.2mm;
    white-space: nowrap;
}
.rotulo-marca .rotulo-marca-texto {
    display: table-cell;
}

.rotulo-valor {
    display: block; font-size: 3.2mm; font-weight: bold; line-height: 1.15;
    max-height: 7.4mm; overflow: hidden; margin-bottom: 0.8mm;
}
.rotulo-meta {
    display: block; font-size: 2.5mm; line-height: 1.3;
    white-space: nowrap; overflow: hidden; margin-bottom: 0.8mm;
}
.rotulo-valor-producto {
    display: block; font-size: 2.8mm; line-height: 1.3;
    white-space: nowrap; overflow: hidden;
}

.rotulo-codigo { margin-top: 2mm; text-align: center; }
/* Ancho FIJO en mm y no en 100%: dompdf calcula el porcentaje sobre el tamaño NATURAL de la
   imagen en algunos casos, y un Code 128 de 20+ caracteres genera un PNG de cientos de píxeles
   de ancho —bastante más que el rótulo—, así que el "100%" terminaba desbordando la página en
   vez de encogerlo. El ancho fijo obliga el tamaño sin importar cuán ancho salga el PNG.
   94.4mm: como .rotulo-codigo no tiene padding propio, su ancho de contenido es el mismo que el
   de .rotulo —94.4mm, ver la nota ahí arriba—, así que el código lo usa entero. */
.rotulo-codigo img { display: block; width: 94.4mm; height: 10mm; margin: 0 auto; }
.rotulo-codigo-texto {
    display: block; margin-top: 0.4mm; font-family: 'Courier New', Courier, monospace;
    font-size: 2mm; letter-spacing: 0.1mm; white-space: nowrap; overflow: hidden;
}
CSS;
}

// $tiendaParaCodigo es lo que entra al código de barras en vez del punto de venta —el EAN de la
// tienda, cuando hay uno— y por defecto es el propio $pv: así un llamador que no lo pase (o pase
// null) se comporta igual que antes de agregar este parámetro.
//
// $logo ya no se usa (el rótulo de 100x40mm no tiene lugar para la imagen, ver cssRotulosPdf) —
// se deja el parámetro para no romper a quien ya llama a esta función pasándolo.
function htmlRotuloPdf($logo, $pv, $oc, $cedi, $numero, $total, $producto, $tiendaParaCodigo = null) {
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $idCaja  = identificadorDeCajaRotulo($oc, $tiendaParaCodigo ?? $pv, $numero);
    $codigo  = codigoBarrasDataUri($idCaja);
    $nombreProducto = trim((string) $producto) !== ''
        ? $esc($producto)
        : '<span style="color:#888">_______________</span>';

    $html = '<div class="rotulo">';

    $html .= '<div class="rotulo-marca">'
           . '<span class="rotulo-marca-texto">Monterojo Gourmet</span>'
           . '<span class="rotulo-conteo">CAJ ' . (int) $numero . ' DE ' . (int) $total . '</span>'
           . '</div>';

    $html .= '<span class="rotulo-valor">' . $esc($pv) . '</span>';
    $html .= '<span class="rotulo-meta">O/C ' . $esc($oc) . ' · CEDI ' . $esc($cedi) . '</span>';
    $html .= '<span class="rotulo-valor-producto">' . $nombreProducto . '</span>';

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
    $dompdf->setPaper([0, 0, 283.465, 113.386], 'portrait');   // 100mm x 40mm, en puntos
    $dompdf->render();

    if (ob_get_length()) {
        ob_end_clean();
    }

    $dompdf->stream($nombreArchivo, ['Attachment' => true]);
    exit();
}
