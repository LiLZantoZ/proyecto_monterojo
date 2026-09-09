<?php
// modules/historial/helper_rotulos_lista.php
// Lo que comparten las DOS formas de sacar un rótulo: el PDF (helper_rotulos_pdf.php) y la
// impresión directa en la etiquetadora (helper_rotulos_tspl.php).
//
// Las dos reciben lo mismo —una lista ya resuelta de cajas, armada por el navegador a partir de
// lo que hay en pantalla— y las dos tienen que entenderla EXACTAMENTE igual: si el PDF recortara
// el nombre del producto en 255 caracteres y la etiquetadora en 200, el papel que sale por la
// impresora no diría lo mismo que el archivo que se guarda del mismo pedido. Por eso el recorte,
// los topes y el identificador del código de barras viven acá y no duplicados en cada helper.

// Tope de seguridad de etiquetas por trabajo. Un lote grande de verdad (los 46 pedidos del CEDI
// más cargado) ronda las 300, así que 500 deja lugar de sobra para el uso real y a la vez evita
// que un POST armado a mano tenga al servidor generando páginas —o a la impresora escupiendo
// etiquetas— durante minutos.
const ROTULOS_MAXIMO_POR_TRABAJO = 500;

/**
 * El identificador de UNA caja que va dentro del código de barras:
 * orden de compra - EAN de la tienda (o su nombre, si no hay EAN) - número de caja.
 *
 * Se le sacan los caracteres que no son letras ni números porque el lector de la cadena espera un
 * código limpio. Igual que identificadorDeCaja() en scripts_historial.js y scripts_picking.js.
 */
function identificadorDeCajaRotulo($oc, $tienda, $numero) {
    $limpiar = fn($v) => preg_replace('/[^A-Za-z0-9]/', '', (string) $v);
    return $limpiar($oc) . '-' . $limpiar($tienda) . '-' . $limpiar($numero);
}

/**
 * Deja una lista de rótulos lista para imprimir: descarta lo que no sirve, recorta los largos y
 * acota los números.
 *
 * NO se valida contra la base a propósito. En el modal de Picking el rótulo es EDITABLE —el
 * picker corrige el nombre de la tienda, la cantidad de cajas o el producto antes de imprimir— y
 * comparar contra lo que dice el archivo original sería descartar justamente la corrección que
 * acaba de hacer. Lo único que se hace es que un POST armado por fuera del sistema no pueda pedir
 * diez mil etiquetas ni meter texto sin límite.
 *
 * Devuelve una lista de arreglos con claves fijas: pv, oc, cedi, ean_pv, numero, total, producto.
 */
function normalizarListaDeRotulos(array $rotulos) {
    $limpios = [];

    foreach (array_slice($rotulos, 0, ROTULOS_MAXIMO_POR_TRABAJO) as $r) {
        if (!is_array($r)) {
            continue;
        }

        $pv = trim(mb_substr((string) ($r['pv'] ?? ''), 0, 180));
        if ($pv === '') {
            // Sin punto de venta el rótulo no identifica ninguna caja: se salta en silencio en
            // vez de imprimir una etiqueta que no le sirve a nadie.
            continue;
        }

        $numero = max(1, min(999, (int) ($r['numero'] ?? 1)));

        $limpios[] = [
            'pv'       => $pv,
            'oc'       => trim(mb_substr((string) ($r['oc'] ?? ''), 0, 40)),
            'cedi'     => trim(mb_substr((string) ($r['cedi'] ?? ''), 0, 120)),
            'ean_pv'   => trim(mb_substr((string) ($r['ean_pv'] ?? ''), 0, 40)),
            'numero'   => $numero,
            // El total nunca puede ser menor que el número de la caja: "CAJ 5 DE 3" no existe.
            'total'    => max($numero, min(999, (int) ($r['total'] ?? 1))),
            'producto' => trim(mb_substr((string) ($r['producto'] ?? ''), 0, 255)),
        ];
    }

    return $limpios;
}
