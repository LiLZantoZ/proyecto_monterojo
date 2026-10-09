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
            // El número de la tienda, su SKU y su EAN. Los tres son nuevos del 2026-09-12: el
            // número va impreso en la etiqueta, y el SKU y el EAN viajan al QR —el EAN NO se
            // imprime, se ve al escanear (ver public/rotulo.php).
            'numero_pv'=> trim(mb_substr((string) ($r['numero_pv'] ?? ''), 0, 20)),
            'sku'      => trim(mb_substr((string) ($r['sku'] ?? ''), 0, 30)),
            'ean'      => trim(mb_substr((string) ($r['ean'] ?? ''), 0, 20)),
            // El número del CEDI, solo dígitos. Lo manda únicamente Cajas por punto de venta, y su
            // presencia es lo que activa el formato Éxito del rótulo (ver cediVaAlLadoDelNumero).
            'numero_cedi'=> substr(preg_replace('/\D+/', '', (string) ($r['numero_cedi'] ?? '')), 0, 6),
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

// -------------------------------------------------------------------------------------------------
// FORMATO ÉXITO (2026-09-14)
//
// Los rótulos que salen de Cajas por punto de venta llevan, además, el número del CEDI en grande a
// la derecha ("CEDI: 149") y la etiqueta "N° PUNTO DE VENTA" del tamaño del nombre de la tienda.
// Es el formato que pidió la cadena para esa pantalla; las demás siguen con el rótulo común.
//
// No hay un "tipo de rótulo" aparte: el formato se activa cuando el rótulo trae numero_cedi, y ese
// dato solo lo manda Cajas por punto de venta. Así las tres versiones del rótulo —la vista previa
// (assets/js/rotulo.js), el PDF y la etiquetadora— deciden lo mismo con lo mismo que reciben.
// -------------------------------------------------------------------------------------------------

/**
 * El número de un CEDI a partir de su nombre: "149 CEDI BUCARAMANGA" → "149",
 * "138 - CEDI EJE CAFETERO" → "138". Cadena vacía si el nombre no empieza con un número.
 *
 * Se exige una letra después del separador por la misma razón que en numeroYNombreDePunto: para no
 * partir un nombre que empiece con dos números sueltos.
 */
function numeroDeCedi($cedi) {
    return preg_match('/^\s*([0-9]{1,6})\s*(?:[-–]\s*|\s+)\p{L}/u', (string) $cedi, $m) ? $m[1] : '';
}

/**
 * Si el bloque "CEDI: 149" entra en el mismo renglón que el número del punto de venta, o tiene que
 * ir al lado de "Cajas total".
 *
 * La cuenta se hace en PUNTOS DE LA ETIQUETADORA porque es el más estricto de los tres rótulos: sus
 * letras tienen ancho fijo y no se achican solas. El número va con la fuente 4 (24 puntos de ancho)
 * al doble (al triple hasta el rollo de 100x80, 2026-09-29); el CEDI con la fuente 3 (16 puntos) al doble;
 * entre los dos, 4mm de aire; y el renglón útil mide 680 puntos (95mm menos 5mm de margen por lado).
 *
 * Con los datos reales: una tienda de 3 o 4 dígitos entra al lado del número; una identificada por
 * su EAN de 13 dígitos no, y el CEDI baja al renglón de "Cajas total", que tiene espacio libre.
 *
 * La MISMA cuenta está en assets/js/rotulo.js. Si se cambia acá, se cambia allá.
 */
function cediVaAlLadoDelNumero($numeroPv, $numeroCedi) {
    $largoNumero = mb_strlen((string) $numeroPv);
    $anchoNumero = $largoNumero * 24 * 2;
    $anchoCedi   = mb_strlen('CEDI: ' . $numeroCedi) * 16 * 2;

    return $anchoNumero + 4 * 8 + $anchoCedi <= 680;
}

// numeroYNombreDePunto() vivía en modules/cajas_punto_venta/model_cajas_punto_venta.php. Se movió
// acá el 2026-09-14 porque el número del punto de venta va impreso en TODOS los rótulos, y
// Picking e Historial también lo necesitan para armarlos.

/**
 * Parte el punto de venta en su número y su nombre.
 *
 * Las cadenas lo mandan como "071 - EXITO BUCARAMANGA": el número es el código con el que la
 * tienda se identifica a sí misma, y es lo que el transportista busca primero en la planilla.
 *
 * Cuando no viene con esa forma se cae hacia atrás con cuidado, porque el archivo puede traer la
 * tienda identificada solo por su código de barras: primero el EAN del punto de venta, y si
 * tampoco está, el propio texto. Nunca se inventa un número.
 */
function numeroYNombreDePunto($puntoVenta, $eanPuntoVenta = null, $direccion = null) {
    $texto = trim((string) $puntoVenta);

    // Las cadenas escriben el código de la tienda de tres formas distintas, a veces en el mismo
    // archivo (comprobado en la carga del 2026-09-08):
    //     '062 - EXITO PARQUE ARBOLEDA'      guion con espacios
    //     '4096-CARULLA VILLA VERDE'         guion pegado
    //     '4035 EXITO NUESTRO CARTAGO'       solo un espacio
    // El separador es cualquiera de los tres. Se exige que después venga una LETRA para no
    // partir un nombre que empiece con dos números sueltos.
    $numeroPegado = '/^\s*([0-9]{1,6})\s*(?:[-–]\s*|\s+)(\p{L}.*)$/u';

    if (preg_match($numeroPegado, $texto, $m)) {
        return ['numero' => $m[1], 'nombre' => trim($m[2])];
    }

    // Respaldo: el número en la dirección. Pasa cuando el archivo viene con la fila de títulos
    // corrida y el nombre de la tienda cae en la columna de dirección (ver la validación de
    // importarConsolidado). Se mira acá para que esos archivos igual muestren algo útil.
    $dir = trim((string) $direccion);
    if ($dir !== '' && preg_match($numeroPegado, $dir, $m)) {
        return ['numero' => $m[1], 'nombre' => trim($m[2])];
    }

    $ean = trim((string) $eanPuntoVenta);

    // Un EAN de tienda son 12 a 14 dígitos. Se comprueba el largo en vez de aceptar cualquier
    // número porque en archivos con las columnas corridas ese campo llega con cantidades sueltas
    // ("18", "24"), y mostrarlas como si fueran el código de la tienda sería peor que no mostrar
    // nada.
    if (preg_match('/^\d{12,14}$/', $ean)) {
        return ['numero' => $ean, 'nombre' => $texto];
    }

    // El propio nombre es un EAN: es la tienda identificada solo por su código.
    if (preg_match('/^\d{12,14}$/', $texto)) {
        return ['numero' => $texto, 'nombre' => ''];
    }

    return ['numero' => '', 'nombre' => $texto];
}
