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
        // Un rótulo sin NINGÚN dato no identifica ninguna caja: se salta en silencio en vez de
        // imprimir una etiqueta que no le sirve a nadie. Desde el 2026-10-09 basta con uno (la
        // orden de compra sola, por ejemplo): los campos vacíos no se imprimen.
        $algunDato = false;
        foreach (['pv', 'numero_pv', 'oc', 'producto', 'sku', 'cedi'] as $campo) {
            if (trim((string) ($r[$campo] ?? '')) !== '') { $algunDato = true; break; }
        }
        if (!$algunDato) {
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

// -------------------------------------------------------------------------------------------------
// LA LETRA CRECE CON EL ESPACIO QUE QUEDA (2026-10-09, pedido del usuario)
//
// Desde que los campos vacíos no se imprimen, un rótulo con pocos datos dejaba media etiqueta en
// blanco y los datos chiquitos arriba. Ahora la letra se agranda según cuántos campos hay: con los
// cinco queda como siempre, y con menos crece hasta llenar el alto, sin pasarse nunca del ancho.
//
// La cuenta se hace en MILÍMETROS, una sola vez, y la usan las tres versiones del rótulo: la vista
// previa (tamanos() en assets/js/rotulo.js, la copia exacta de esta función), el PDF y la
// etiquetadora, que traduce los milímetros a la fuente de la impresora más cercana que entre.
// -------------------------------------------------------------------------------------------------

// El alto que tienen los campos entre la marca y el pie, y el ancho útil, en mm. El alto está medido
// con dompdf: con más, un rótulo cargado se pasa a una segunda página (una etiqueta en blanco).
const ROTULO_CAMPOS_ALTO_MM  = 40;
const ROTULO_CAMPOS_ANCHO_MM = 85;
// Hasta cuánto puede crecer la letra: las cajas solas llegan a 15mm, lo que entra con holgura.
const ROTULO_ESCALA_MAXIMA   = 4;

/**
 * El cuerpo de letra normal de un valor, en mm, según cuán largo sea el texto (ver cuerpoValorPdf
 * en helper_rotulos_pdf.php, que llama a esta, y cuerpoValor() en assets/js/rotulo.js).
 */
function cuerpoValorRotulo($texto, $escala = 1.0) {
    $largo = mb_strlen(trim((string) $texto));

    // Un 10% más chicos desde el rollo de 100x80 (2026-09-29): eran 5,5 / 4,5 / 3,6 / 3,0.
    if ($largo <= 22) { return round(5.0 * $escala, 2); }
    if ($largo <= 30) { return round(4.1 * $escala, 2); }
    if ($largo <= 40) { return round(3.3 * $escala, 2); }
    return round(2.7 * $escala, 2);
}

/**
 * Qué campos lleva el rótulo y de qué tamaño va cada uno.
 *
 * $r es un rótulo ya normalizado (ver normalizarListaDeRotulos). Devuelve:
 *   'campos'         => [clave => ['etiqueta' => mm, 'oc' => mm, 'valor' => mm, 'cedi' => mm]], en el
 *                       orden en que se dibujan; las claves son pv, numero, cajas, producto y cedi.
 *   'campo_del_cedi' => la clave del campo al lado del cual va "CEDI: 149" (formato Éxito), o null.
 *   'aire'           => los mm que sobran a lo alto, para repartir entre los campos.
 */
function tamanosDelRotulo(array $r) {
    $lleno = fn($v) => trim((string) ($v ?? '')) !== '';
    $largo = fn($v) => mb_strlen(trim((string) ($v ?? '')));

    $numeroCedi = preg_replace('/\D+/', '', (string) ($r['numero_cedi'] ?? ''));
    $conCedi    = $numeroCedi !== '';
    $campoDelCedi = !$conCedi ? null
        : ($lleno($r['numero_pv'] ?? '') && cediVaAlLadoDelNumero(trim((string) $r['numero_pv']), $numeroCedi) ? 'numero' : 'cajas');

    // Por campo: largo de la etiqueta, largo de la O/C (0 si no va), largo del valor, cuerpo normal
    // del valor y si es el destacado. Los largos de las etiquetas son los de los textos que se imprimen.
    $campos = [];
    if ($lleno($r['pv'] ?? '')) {
        $campos['pv'] = [14, 0, $largo($r['pv']), cuerpoValorRotulo($r['pv']), false];
    }
    if ($lleno($r['numero_pv'] ?? '')) {
        $campos['numero'] = [17, 0, $largo($r['numero_pv']), cuerpoValorRotulo($r['numero_pv'], 1.6), true];
    }
    $conOc = $lleno($r['oc'] ?? '');
    $campos['cajas'] = [$conOc ? 16 : 11, $conOc ? 4 + $largo($r['oc']) : 0, $largo($r['total']),
                        cuerpoValorRotulo((string) $r['total'], 0.75), false];
    if ($lleno($r['producto'] ?? '')) {
        $campos['producto'] = [$lleno($r['sku'] ?? '') ? 17 + $largo($r['sku']) : 8, 0, $largo($r['producto']),
                               cuerpoValorRotulo($r['producto'], 0.70), false];
    } elseif ($lleno($r['sku'] ?? '')) {
        $campos['producto'] = [3, 0, $largo($r['sku']), cuerpoValorRotulo($r['sku'], 0.70), false];
    }
    if ($lleno($r['cedi'] ?? '')) {
        $campos['cedi'] = [4, 0, $largo($r['cedi']), cuerpoValorRotulo($r['cedi'], 0.75), false];
    }

    // Lo que mide a lo alto un campo: el renglón de la etiqueta (o el de la O/C, si es más alta), el
    // valor y el margen de abajo. Son los line-height de 04-rotulo.css y de cssRotulosPdf.
    $alto = fn($c, $et, $oc, $valor) => max($et, $c[1] ? $oc : 0) * 1.1 + $valor + 0.5;
    $etiquetaBase = fn($c) => $c[4] ? ($conCedi ? 4.0 : 3.0) : 2.2;

    $natural = 0;
    foreach ($campos as $c) {
        $natural += $alto($c, $etiquetaBase($c), 2.8, $c[3]);
    }

    // La escala que llena el alto. Las etiquetas crecen la mitad que los valores: lo que se lee de
    // lejos es el dato, no su nombre.
    $escala = min(ROTULO_ESCALA_MAXIMA, max(1, ROTULO_CAMPOS_ALTO_MM / $natural));
    $escalaEtiqueta = 1 + ($escala - 1) * 0.5;

    $salida = [];
    $usado  = 0;
    foreach ($campos as $clave => $c) {
        // "CEDI: 149" crece solo si al lado sigue entrando la etiqueta del campo en un renglón.
        $cedi  = $clave === $campoDelCedi ? round(6.5 * min($escalaEtiqueta, 1.4), 2) : 0;
        if ($cedi && $c[0] * $etiquetaBase($c) * 0.8 + mb_strlen('CEDI: ' . $numeroCedi) * $cedi * 0.68 + 3 > ROTULO_CAMPOS_ANCHO_MM) {
            $cedi = 6.5;
        }
        $ancho = ROTULO_CAMPOS_ANCHO_MM - ($cedi ? mb_strlen('CEDI: ' . $numeroCedi) * $cedi * 0.68 + 3 : 0);

        // El valor crece con la escala, pero nunca más de lo que entra a lo ancho (0,72 es lo que
        // ocupa una letra mayúscula en negrita, en proporción a su alto). Nunca baja del normal:
        // ese ya lo achica cuerpoValorRotulo para los textos largos.
        $valor = $c[3] * $escala;
        if ($c[2] > 0) {
            $valor = min($valor, $ancho / ($c[2] * 0.72));
        }
        $valor = max($c[3], $valor);

        // La etiqueta y la O/C crecen juntas y, si a lo ancho no entran, se achican juntas (las
        // etiquetas van en mayúsculas y con separación: ocupan más que el valor por letra).
        $et = $etiquetaBase($c) * $escalaEtiqueta;
        $oc = 2.8 * $escalaEtiqueta;
        // Y nunca más grandes que el dato que nombran: un nombre largo que no pudo crecer deja su
        // etiqueta en el tamaño de siempre.
        $et = min($et, max($etiquetaBase($c), $valor * 0.6));
        $oc = min($oc, max(2.8, $valor * 0.9));
        $anchoEtiqueta = $c[0] * $et * 0.8 + $c[1] * $oc * 0.72;
        if ($anchoEtiqueta > $ancho) {
            $f  = max(1 / $escalaEtiqueta, $ancho / $anchoEtiqueta);
            $et *= $f;
            $oc *= $f;
        }

        $salida[$clave] = ['etiqueta' => round($et, 2), 'oc' => round($oc, 2), 'valor' => round($valor, 2), 'cedi' => $cedi];
        $usado += $alto($c, $et, $oc, $valor);
    }

    return [
        'campos'         => $salida,
        'campo_del_cedi' => $campoDelCedi,
        'aire'           => round(max(0, ROTULO_CAMPOS_ALTO_MM - $usado), 2),
    ];
}

// -------------------------------------------------------------------------------------------------
// EL ORDEN DE LOS CEDI Y DE LAS TIENDAS (2026-10-09, pedido del usuario)
//
// Cajas por punto de venta y el Consolidado para rótulos (consolidadoExternoPorCedi) se miran y se
// imprimen juntos, así que van en el MISMO orden: los dos llaman a estas dos funciones. Antes el
// Consolidado para rótulos ordenaba las tiendas por nombre y no coincidía con la otra planilla.
// -------------------------------------------------------------------------------------------------

/**
 * Las tiendas de un CEDI por su NÚMERO, de menor a mayor y comparando como número (en orden
 * alfabético '4319' iría antes que '071'); las que no tienen número, al final; a igual número, por
 * nombre. $a y $b traen 'numero' y 'nombre' (ver numeroYNombreDePunto).
 */
function compararPuntosDeVenta(array $a, array $b) {
    if (($a['numero'] === '') !== ($b['numero'] === '')) {
        return $a['numero'] === '' ? 1 : -1;
    }
    return [(int) $a['numero'], $a['nombre']] <=> [(int) $b['numero'], $b['nombre']];
}

/**
 * Los CEDI, el que tiene menos tiendas primero (es el que se resuelve rápido); a igual cantidad, por
 * nombre, para que el orden no dependa de en qué orden devolvió las filas la base.
 */
function ordenarCedisPorTiendas(array &$porCedi) {
    $tiendas = array_map(fn($d) => count($d['puntos'] ?? []), $porCedi);
    uksort($porCedi, fn($x, $y) => [$tiendas[$x], (string) $x] <=> [$tiendas[$y], (string) $y]);
}
