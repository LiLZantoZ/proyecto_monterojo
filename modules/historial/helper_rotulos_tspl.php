<?php
// modules/historial/helper_rotulos_tspl.php
// Imprime los rótulos DIRECTO en la etiquetadora, sin PDF y sin diálogo del navegador.
//
// Por qué existe además del PDF: una etiquetadora no es una impresora de hojas. Cuando se le manda una
// página —sea el PDF o el "Imprimir" del navegador— hay tres capas que pueden arruinar la etiqueta
// antes de que llegue al papel: el diálogo del navegador (escala, márgenes, encabezados), el
// driver de Windows (tamaño de material, orientación) y el renderizado en sí. Cualquiera de las
// tres mal puesta saca el rótulo corrido, cortado, girado o directamente en blanco, y como son
// tres capas distintas el error nunca se arregla en un solo lugar. Eso fue exactamente lo que pasó
// con esta impresora: etiquetas al revés primero, etiquetas en blanco después.
//
// TSPL es el idioma propio de la impresora: se le manda "SIZE 100 mm, 100 mm", "TEXT ...",
// "BARCODE ...", "PRINT 1,1" y ella dibuja la etiqueta con su firmware. No hay nada en el medio
// que pueda reescalar ni reacomodar, la medida es exacta por definición, y el código de barras
// sale a los 203 dpi nativos del cabezal en vez de ser un PNG estirado.
//
// El trabajo se manda al spooler de Windows con el tipo de datos "RAW" (ver
// scripts/imprimir_raw.ps1): es la única forma de que el driver pase los bytes tal cual en vez de
// tratarlos como texto e imprimir las LETRAS de los comandos en la etiqueta.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/helper_rotulos_lista.php';
require_once __DIR__ . '/helper_rotulos_enlace.php';

// 203 dpi = 8 puntos por milímetro. Toda la maqueta de abajo está en PUNTOS, que es la única
// unidad en la que TSPL posiciona texto, líneas y códigos de barras.
//
// Esto ata el diseño a una impresora de 203 dpi (la TE200 y la TA210 lo son). Si algún día se
// pasa a una de 300, NO alcanza con cambiar este número: hay que revisar TSPL_FUENTES, porque
// las fuentes internas de la impresora miden lo mismo en PUNTOS pero menos en milímetros, así
// que todos los textos saldrían más chicos aunque las posiciones quedaran bien.
const TSPL_PUNTOS_POR_MM = 8;

// El sticker físico. Solo se usa para el comando SIZE y para centrar el dibujo: es la medida que
// la impresora necesita para avanzar bien el rollo.
const TSPL_ANCHO = ROTULO_ANCHO_MM * TSPL_PUNTOS_POR_MM;
const TSPL_ALTO  = ROTULO_ALTO_MM  * TSPL_PUNTOS_POR_MM;

// El área que se dibuja de verdad, centrada dentro del sticker (ver config/config.php). Toda la
// maqueta de abajo vive acá adentro; TSPL_X0/TSPL_Y0 son su esquina superior izquierda, y por eso
// ninguna coordenada arranca en cero.
const TSPL_DIB_ANCHO = ROTULO_DIBUJO_ANCHO_MM * TSPL_PUNTOS_POR_MM;
const TSPL_DIB_ALTO  = ROTULO_DIBUJO_ALTO_MM  * TSPL_PUNTOS_POR_MM;
const TSPL_X0 = (TSPL_ANCHO - TSPL_DIB_ANCHO) >> 1;
const TSPL_Y0 = (TSPL_ALTO  - TSPL_DIB_ALTO)  >> 1;

// Aire entre el marco dibujado y el contenido, hacia adentro del área de dibujo.
const TSPL_MARGEN = 5 * TSPL_PUNTOS_POR_MM;

// Fuentes internas de la impresora, con el ancho y alto de celda de cada una en puntos. Se usan
// las de la impresora y no una imagen porque el firmware las dibuja al instante y salen nítidas;
// el precio es que hay que elegir el tamaño a mano según cuánto texto entra (ver textoQueEntre).
const TSPL_FUENTES = [
    '1' => ['ancho' =>  8, 'alto' => 12],
    '2' => ['ancho' => 12, 'alto' => 20],
    '3' => ['ancho' => 16, 'alto' => 24],
    '4' => ['ancho' => 24, 'alto' => 32],
    '5' => ['ancho' => 32, 'alto' => 48],
];

// El logo es un círculo negro con el texto en blanco: a 1 bit queda idéntico, sin medios tonos que
// se pierdan. Se dibuja a 14mm, la misma medida que tenía en el rótulo original.
const TSPL_LOGO_MM = 14;

// Alto del código de barras. 14mm era la medida del rótulo original; el estirado vertical no
// afecta la lectura —lo que codifica un Code 128 son los ANCHOS— pero un código alto es mucho más
// fácil de enganchar con la pistola sin tener que apuntar fino.
const TSPL_CODIGO_MM = 12;

// Ancho de cada celda del QR, en puntos. El enlace da un código de 33x33 celdas (medido con uno
// real, ver helper_rotulos_enlace), así que con 4 puntos por celda el QR queda de 132 puntos =
// 12,4mm de lado, que es lo mínimo que un celular engancha sin tener que acercarse mucho. Se
// probó con 4 (16,5mm) y le quitaba demasiado protagonismo al número del punto de venta, que es
// lo que hay que leer primero.
const TSPL_QR_CELDA = 3;

// En BITMAP, un bit en 0 imprime punto (negro) y un bit en 1 lo deja en blanco. Si alguna vez el
// logo saliera en negativo —círculo blanco sobre fondo negro— es este valor el que hay que dar
// vuelta, y no hay que tocar nada más.
const TSPL_BITMAP_CERO_ES_NEGRO = true;

/**
 * Deja un texto listo para meterlo entre comillas en un comando TSPL.
 *
 * Dos cosas: una comilla doble cortaría el comando a la mitad y la impresora leería el resto como
 * parámetros basura, y los acentos y la ñ tienen que ir en Windows-1252, que es la página de
 * códigos que se declara al principio del trabajo (ver tsplDeRotulos). Lo que no exista en 1252 se
 * translitera —"ó" pasa a "o"— en vez de convertirse en un carácter ilegible.
 */
function textoTspl($texto) {
    $texto = str_replace(['"', '\\'], ["'", '/'], (string) $texto);
    $texto = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $texto);

    $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
    return $convertido === false ? preg_replace('/[^\x20-\x7E]/', '', $texto) : $convertido;
}

/**
 * Elige la fuente más grande de $candidatas con la que $texto entra en $anchoDisponible, y lo
 * recorta si no entra ni con la más chica. Devuelve [fuente, texto].
 *
 * Hace falta porque las fuentes internas de la impresora son de ancho FIJO: no hay ajuste
 * automático como en CSS. Un punto de venta como "4212 - SUPER INTER EXPRESS Av SEXTA" tiene que
 * bajar de tamaño solo, o se sale de la etiqueta sin que nadie se entere hasta ver el papel.
 */
function textoQueEntre($texto, $anchoDisponible, array $candidatas) {
    $texto = trim((string) $texto);

    foreach ($candidatas as $fuente) {
        $caben = intdiv($anchoDisponible, TSPL_FUENTES[$fuente]['ancho']);
        if (mb_strlen($texto) <= $caben) {
            return [$fuente, $texto];
        }
    }

    $ultima = end($candidatas);
    $caben  = intdiv($anchoDisponible, TSPL_FUENTES[$ultima]['ancho']);
    return [$ultima, mb_substr($texto, 0, $caben)];
}

/**
 * Ancho aproximado en puntos de un Code 128, para poder centrarlo.
 *
 * Es una estimación AL ALZA: cuenta 11 módulos por carácter (el juego B) sin descontar la
 * compresión que hace el juego C con las tiradas de dígitos, más 35 de arranque, verificación y
 * cierre. Se prefiere que sobre antes que falte: si la cuenta da de más, el código queda apenas
 * corrido a la izquierda; si diera de menos, se saldría del rótulo, que es el error que sí se ve.
 */
function anchoCodigo128($texto, $modulo) {
    return (11 * mb_strlen($texto) + 35) * $modulo;
}

/**
 * El logo convertido a mapa de bits de 1 bit, listo para el comando BITMAP.
 * Devuelve [bytesPorFila, alto, datosBinarios] o null si no se pudo.
 *
 * Se calcula UNA sola vez por request (el static): un lote de 300 etiquetas usa el mismo logo 300
 * veces, y rehacerlo cada vez sería redimensionar el PNG 300 veces para nada.
 *
 * Si GD no está disponible se devuelve null y el rótulo sale sin logo, solo con el texto de la
 * marca. Es a propósito: que falte una imagen no puede dejar a nadie sin poder despachar.
 */
function bitmapLogoTspl($lado) {
    static $cache = [];
    if (array_key_exists($lado, $cache)) {
        return $cache[$lado];
    }

    $ruta = ROOT_PATH . '/assets/img/monterojo.png';
    if (!function_exists('imagecreatefrompng') || !is_file($ruta)) {
        return $cache[$lado] = null;
    }

    $origen = @imagecreatefrompng($ruta);
    if (!$origen) {
        return $cache[$lado] = null;
    }

    // Fondo blanco explícito: el PNG tiene el fondo transparente, y sin esto las zonas
    // transparentes quedarían negras al aplastarlas a 1 bit —o sea, un cuadrado negro.
    $destino = imagecreatetruecolor($lado, $lado);
    imagefilledrectangle($destino, 0, 0, $lado, $lado, imagecolorallocate($destino, 255, 255, 255));
    imagealphablending($destino, true);
    imagecopyresampled($destino, $origen, 0, 0, 0, 0, $lado, $lado, imagesx($origen), imagesy($origen));
    imagedestroy($origen);

    // Cada fila de la imagen se empaqueta en bytes, 8 píxeles por byte, el bit más significativo a
    // la izquierda. Es el formato que espera BITMAP, y por eso el ancho se declara en BYTES.
    $bytesPorFila = intdiv($lado + 7, 8);
    $datos = '';

    for ($y = 0; $y < $lado; $y++) {
        for ($b = 0; $b < $bytesPorFila; $b++) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $x = $b * 8 + $bit;

                // Fuera de la imagen (el relleno del último byte) se considera blanco, para no
                // imprimir una franja negra al costado del logo.
                $esClaro = true;
                if ($x < $lado) {
                    $c = imagecolorat($destino, $x, $y);
                    $luz = (($c >> 16) & 255) * 0.299 + (($c >> 8) & 255) * 0.587 + ($c & 255) * 0.114;
                    $esClaro = $luz >= 128;
                }

                $valor = TSPL_BITMAP_CERO_ES_NEGRO ? ($esClaro ? 1 : 0) : ($esClaro ? 0 : 1);
                $byte |= $valor << (7 - $bit);
            }
            $datos .= chr($byte);
        }
    }

    imagedestroy($destino);
    return $cache[$lado] = [$bytesPorFila, $lado, $datos];
}

/**
 * Los comandos TSPL de UNA etiqueta.
 *
 * La maqueta es el diseño original del rótulo (el que estuvo hasta el 2026-09-08, cuando hubo que
 * comprimirlo para que entrara en un rollo de 4cm de alto), devuelto tal cual ahora que el rollo es
 * de 10x10cm: logo y marca arriba con su línea divisoria, cada dato con su etiqueta y en su propio
 * renglón, el contador de cajas abajo solo y grande, y el código de barras al pie.
 *
 * La posición vertical se lleva con un cursor ($y) que va bajando, en vez de con coordenadas
 * escritas a mano. Con coordenadas fijas, mover un campo obliga a recalcular a mano todos los de
 * abajo —y ese fue justamente el tipo de error que costó varias etiquetas la vez pasada.
 */
function tsplDeUnRotulo(array $r) {
    $izq       = TSPL_X0 + TSPL_MARGEN;
    $anchoUtil = TSPL_DIB_ANCHO - 2 * TSPL_MARGEN;
    $lineas    = [];

    // El marco, dibujado 6 puntos adentro del borde físico: pegado al filo, la tolerancia mecánica
    // del avance del rollo se lo come de a ratos y el marco sale cortado de un lado sí y otro no.
    $lineas[] = 'BOX ' . TSPL_X0 . ',' . TSPL_Y0 . ','
              . (TSPL_X0 + TSPL_DIB_ANCHO - 1) . ',' . (TSPL_Y0 + TSPL_DIB_ALTO - 1) . ',8';

    // ---------- Marca: logo + nombre, con línea divisoria debajo ----------
    $y    = TSPL_Y0 + TSPL_MARGEN;
    $lado = TSPL_LOGO_MM * TSPL_PUNTOS_POR_MM;
    $logo = bitmapLogoTspl($lado);
    $xMarca = $izq;

    if ($logo !== null) {
        [$bytesPorFila, $alto, $datos] = $logo;
        // El binario va pegado a la coma final, sin salto de línea: BITMAP lee exactamente
        // bytesPorFila * alto bytes a partir de ahí.
        $lineas[] = 'BITMAP ' . $izq . ',' . $y . ',' . $bytesPorFila . ',' . $alto . ',0,' . $datos;
        $xMarca = $izq + $lado + 3 * TSPL_PUNTOS_POR_MM;
    }

    // El nombre se centra verticalmente contra el logo, que es más alto que la línea de texto.
    $lineas[] = 'TEXT ' . $xMarca . ',' . ($y + intdiv($lado - TSPL_FUENTES['4']['alto'], 2))
              . ',"4",0,1,1,"MONTEROJO GOURMET"';

    $y += $lado + 2 * TSPL_PUNTOS_POR_MM;
    $lineas[] = 'BAR ' . $izq . ',' . $y . ',' . $anchoUtil . ',5';
    $y += 5 + 2 * TSPL_PUNTOS_POR_MM;

    // ---------- Los campos, cada uno con su etiqueta arriba ----------
    // El punto de venta va en la fuente más grande: es lo que mira quien recibe la caja. Los demás
    // comparten tamaño para que el rótulo se lea como una ficha y no como cinco cosas sueltas.
    // La orden de compra salió de la etiqueta el 2026-09-12: ya va en el código de barras y en la
    // planilla, y en la caja lo que se mira es a qué tienda va. En su lugar entró el NÚMERO del
    // punto de venta, que es por donde la cadena identifica sus locales.
    //
    // El SKU acompaña a la etiqueta del producto en vez de ocupar su propio renglón: hay
    // productos que comparten nombre y solo se distinguen por el SKU (ver productoDelMaestro),
    // así que tiene que estar; pero un renglón entero para cinco dígitos sería robarle altura al
    // nombre, que es lo que de verdad se lee.
    $etiquetaProducto = $r['sku'] !== '' ? 'PRODUCTO  -  SKU ' . $r['sku'] : 'PRODUCTO';

    // El cuarto elemento es el multiplicador de tamaño de la fuente. El NÚMERO del punto de venta
    // va al doble: es el dato que se busca de lejos cuando las cajas ya están estibadas y solo se
    // ve el canto de la etiqueta, y en cuerpo normal quedaba perdido entre los demás renglones.
    // El número del punto de venta va al TRIPLE cuando es corto, que es el caso normal (3 o 4
    // dígitos). Si la tienda viene identificada por su EAN de 13 dígitos, al triple no entraría y
    // textoQueEntre lo recortaría, así que ahí se queda en el doble: más vale un número completo
    // y algo más chico que uno enorme y cortado por la mitad.
    $multNumero = mb_strlen($r['numero_pv']) <= 6 ? 3 : 2;

    // FORMATO ÉXITO: con número de CEDI, la etiqueta "N. PUNTO DE VENTA" pasa a la fuente 4 —la
    // misma del nombre de la tienda— y a la derecha va "CEDI: 149" en grande. $campoDelCedi es el
    // índice del campo al lado del cual va: 1 (el número) si entra, 2 (cajas total) si no. Ver
    // cediVaAlLadoDelNumero() en helper_rotulos_lista.php.
    $numeroPvImpreso = $r['numero_pv'] !== '' ? $r['numero_pv'] : '-';
    $textoCedi       = $r['numero_cedi'] !== '' ? 'CEDI: ' . $r['numero_cedi'] : '';
    $fuenteFuerte    = $textoCedi !== '' ? '4' : '3';
    $campoDelCedi    = $textoCedi === ''
        ? null
        : (cediVaAlLadoDelNumero($numeroPvImpreso, $r['numero_cedi']) ? 1 : 2);

    // El quinto elemento marca el campo como DESTACADO: su etiqueta se imprime en negrita.
    $campos = [
        ['PUNTO DE VENTA',    $r['pv'],                        ['5', '4', '3'], 1, false],
        ['N. PUNTO DE VENTA', $numeroPvImpreso,                ['4'], $multNumero, true],
        ['CAJAS TOTAL',       (string) $r['total'],             ['4'], 1, false],
        [$etiquetaProducto,   $r['producto'] !== '' ? $r['producto'] : '________________', ['4', '3'], 1, false],
        ['CEDI',              $r['cedi'] !== '' ? $r['cedi'] : '-', ['4', '3'], 1, false],
    ];

    // La fuente de cada valor se resuelve ANTES de empezar a dibujar, porque de ella depende
    // cuánto miden los campos y, por lo tanto, cuánto aire sobra para repartir entre ellos.
    foreach ($campos as $i => [$etiqueta, $valor, $candidatas, $mult, $fuerte]) {
        // El ancho disponible se divide por el multiplicador: una letra al doble ocupa el doble,
        // así que en el mismo renglón entra la mitad de texto.
        [$fuente, $texto] = textoQueEntre($valor, intdiv($anchoUtil, $mult), $candidatas);
        $campos[$i][] = $fuente;
        $campos[$i][] = $texto;
    }

    // El bloque de abajo —línea, contador y QR— se ANCLA al pie en vez de dibujarse a continuación
    // de los campos. Con el flujo al revés, un punto de venta largo que se lleva un renglón de más
    // empujaba el pie fuera de la etiqueta; anclándolo, lo que se ajusta es el aire entre campos,
    // que es lo que no le importa a nadie. El contador y el QR comparten renglón.
    $altoConteo  = TSPL_FUENTES['4']['alto'] * 2;
    $ladoQr      = TSPL_QR_CELDA * 33;
    $altoAbajo   = 5 + 2 * TSPL_PUNTOS_POR_MM          // línea divisoria + su aire
                 + max($altoConteo, $ladoQr);
    $pieArranca  = TSPL_Y0 + TSPL_DIB_ALTO - TSPL_MARGEN - $altoAbajo;

    // Alto "natural" de los campos, sin aire entre uno y otro. El bloque del CEDI no suma: va al
    // costado de un campo que ya es más alto que él.
    $altoCampos = 0;
    foreach ($campos as [, , , $mult, $fuerte, $fuente]) {
        $altoCampos += TSPL_FUENTES[$fuerte ? $fuenteFuerte : '2']['alto'] + 2
                     + TSPL_FUENTES[$fuente]['alto'] * $mult;
    }

    // El sobrante se reparte en partes iguales. El tope de 4 puntos evita que dos campos se toquen
    // cuando el rótulo va muy cargado, y el de 24 que queden flotando separadísimos cuando va
    // vacío; entre medio, el rótulo se estira o se comprime solo según el tamaño de la etiqueta.
    $aire = intdiv($pieArranca - $y - $altoCampos, count($campos));
    $aire = max(4, min(3 * TSPL_PUNTOS_POR_MM, $aire));

    foreach ($campos as $i => [$etiqueta, , , $mult, $fuerte, $fuente, $texto]) {
        $yCampo = $y;

        // Las fuentes internas de la impresora no tienen negrita. Se simula imprimiendo el mismo
        // texto dos veces, corrido un punto: los trazos se solapan y quedan un punto más gruesos.
        // Es el truco estándar en TSPL, y a 203 dpi la diferencia se nota sin verse sucio.
        $fuenteEtiqueta = $fuerte ? $fuenteFuerte : '2';
        $lineas[] = 'TEXT ' . $izq . ',' . $y . ',"' . $fuenteEtiqueta . '",0,1,1,"'
                  . textoTspl($etiqueta) . '"';
        if ($fuerte) {
            $lineas[] = 'TEXT ' . ($izq + 1) . ',' . $y . ',"' . $fuenteEtiqueta . '",0,1,1,"'
                      . textoTspl($etiqueta) . '"';
        }
        $y += TSPL_FUENTES[$fuenteEtiqueta]['alto'] + 2;

        $lineas[] = 'TEXT ' . $izq . ',' . $y . ',"' . $fuente . '",0,' . $mult . ',' . $mult
                  . ',"' . textoTspl($texto) . '"';
        $y += TSPL_FUENTES[$fuente]['alto'] * $mult;

        // El bloque "CEDI: 149", pegado al margen derecho y centrado contra la altura del campo
        // entero (etiqueta + valor). Al doble y con negrita doble —corrido 2 puntos y no 1, porque
        // al doble cada trazo mide el doble—: tiene que leerse de lejos, igual que el número.
        if ($i === $campoDelCedi) {
            $anchoCedi = mb_strlen($textoCedi) * TSPL_FUENTES['3']['ancho'] * 2;
            $altoCedi  = TSPL_FUENTES['3']['alto'] * 2;
            // Menos 2: la segunda pasada de la negrita va corrida 2 puntos a la derecha, y tiene
            // que terminar en el margen, no pasarse.
            $xCedi     = $izq + $anchoUtil - $anchoCedi - 2;
            $yCedi     = $yCampo + intdiv(($y - $yCampo) - $altoCedi, 2);

            foreach ([0, 2] as $corrimiento) {
                $lineas[] = 'TEXT ' . ($xCedi + $corrimiento) . ',' . $yCedi . ',"3",0,2,2,"'
                          . textoTspl($textoCedi) . '"';
            }
        }

        $y += $aire;
    }
    // ---------- El contador de cajas: abajo, solo, y lo más grande del rótulo ----------
    // Es lo que mira el que descarga el camión para saber si llegó todo, así que se lo deja
    // separado del resto por una línea y se lo imprime al doble de tamaño.
    $y = $pieArranca;
    $lineas[] = 'BAR ' . $izq . ',' . $y . ',' . $anchoUtil . ',5';
    $y += 5 + 2 * TSPL_PUNTOS_POR_MM;

    $conteo = 'CAJ ' . $r['numero'] . ' DE ' . $r['total'];

    // ---------- El QR, al lado del contador ----------
    //
    // Un solo código, y es el QR (el de barras salió el 2026-09-12). El QR lleva un enlace a una
    // página con el rótulo completo —incluido el EAN, que no se imprime— y es lo único que un
    // celular sabe abrir al escanear. Ver public/rotulo.php y helper_rotulos_enlace.php.
    //
    // Si no se puede armar el enlace, el rótulo sale sin QR y el contador se centra solo: una
    // etiqueta sin código es molesta, pero una con un código que no abre nada hace perder tiempo
    // en el muelle averiguando por qué no funciona.
    $enlace = enlaceDeRotulo($GLOBALS['pdo'] ?? null, $r);

    $derecha     = TSPL_X0 + TSPL_DIB_ANCHO - TSPL_MARGEN;
    $anchoConteo = mb_strlen($conteo) * TSPL_FUENTES['4']['ancho'] * 2;

    if ($enlace !== null) {
        // Parámetros de QRCODE: x, y, corrección de errores, ancho de celda, modo, rotación,
        // contenido. Corrección L porque el enlace es corto y la etiqueta se pega en una caja
        // limpia; subirla agrandaría el código sin ganar nada acá.
        $lineas[] = 'QRCODE ' . ($derecha - $ladoQr) . ',' . $y
                  . ',L,' . TSPL_QR_CELDA . ',A,0,"' . textoTspl($enlace) . '"';

        // El contador se centra en lo que queda a la izquierda del QR, y se alinea al medio de su
        // altura para que los dos se lean como un solo bloque.
        $xConteo = $izq + intdiv(($derecha - $ladoQr - 4 * TSPL_PUNTOS_POR_MM) - $izq - $anchoConteo, 2);
        $yConteo = $y + intdiv($ladoQr - $altoConteo, 2);
    } else {
        $xConteo = $izq + intdiv($derecha - $izq - $anchoConteo, 2);
        $yConteo = $y;
    }

    $lineas[] = 'TEXT ' . max($izq, $xConteo) . ',' . $yConteo
              . ',"4",0,2,2,"' . textoTspl($conteo) . '"';
    return implode("\r\n", $lineas);
}

/**
 * El trabajo TSPL completo: la configuración del rollo una sola vez, y después cada etiqueta.
 * Se devuelve como texto para poder verlo y probarlo sin tener la impresora conectada.
 */
function tsplDeRotulos(array $rotulos) {
    $cabecera = [
        'SIZE ' . ROTULO_ANCHO_MM . ' mm,' . ROTULO_ALTO_MM . ' mm',
        'GAP ' . (int) ROTULO_TSPL_GAP_MM . ' mm,0',
        'DIRECTION ' . (int) ROTULO_TSPL_DIRECCION,
        'REFERENCE 0,0',
        'DENSITY ' . (int) ROTULO_TSPL_DENSIDAD,
        'SPEED ' . (int) ROTULO_TSPL_VELOCIDAD,
        'CODEPAGE 1252',          // para que la ñ y los acentos salgan bien (ver textoTspl)
    ];

    $trabajo = implode("\r\n", $cabecera) . "\r\n";

    foreach ($rotulos as $r) {
        // CLS antes de CADA etiqueta limpia el buffer de imagen de la impresora. Sin esto la
        // segunda etiqueta sale con la primera todavía dibujada encima.
        $trabajo .= "CLS\r\n" . tsplDeUnRotulo($r) . "\r\nPRINT 1,1\r\n";
    }

    return $trabajo;
}

/**
 * Manda los rótulos a la etiquetadora.
 * Devuelve ['ok' => bool, 'mensaje' => string, 'etiquetas' => int].
 *
 * No lanza excepciones ni corta la ejecución: el llamador decide qué mostrar. Los mensajes están
 * escritos para que los entienda quien está parado frente a la impresora, no para un log, y todos
 * los de error terminan ofreciendo el PDF como salida: que la impresora esté apagada no puede
 * dejar a nadie sin poder despachar.
 */
function imprimirRotulosEnEtiquetadora(array $rotulos) {
    $rotulos = normalizarListaDeRotulos($rotulos);

    if (!$rotulos) {
        return ['ok' => false, 'etiquetas' => 0, 'mensaje' => 'No hay rótulos que imprimir.'];
    }

    if (!function_exists('exec')) {
        return [
            'ok' => false,
            'etiquetas' => 0,
            'mensaje' => 'El servidor tiene deshabilitada la función exec() de PHP, que es la que '
                       . 'le habla a la impresora. Mientras tanto se puede usar "Descargar PDF".',
        ];
    }

    // El trabajo va por un archivo temporal y no por la línea de comandos: son varios miles de
    // caracteres con comillas, saltos de línea y el binario del logo, y el largo máximo de un
    // comando en Windows (~8.000 caracteres) lo cortaría en la mitad de una etiqueta.
    $archivo = tempnam(sys_get_temp_dir(), 'rotulos_');
    if ($archivo === false || file_put_contents($archivo, tsplDeRotulos($rotulos)) === false) {
        return ['ok' => false, 'etiquetas' => 0, 'mensaje' => 'No se pudo preparar el trabajo de impresión.'];
    }

    $comando = 'powershell -NoProfile -ExecutionPolicy Bypass -File '
             . escapeshellarg(ROOT_PATH . '/scripts/imprimir_raw.ps1')
             . ' -Impresora ' . escapeshellarg(IMPRESORA_ROTULOS)
             . ' -Archivo ' . escapeshellarg($archivo)
             . ' 2>&1';

    exec($comando, $salida, $codigo);
    @unlink($archivo);

    if ($codigo === 0) {
        $cantidad = count($rotulos);
        return [
            'ok' => true,
            'etiquetas' => $cantidad,
            'mensaje' => $cantidad . ($cantidad === 1 ? ' rótulo enviado' : ' rótulos enviados')
                       . ' a ' . IMPRESORA_ROTULOS . '.',
        ];
    }

    error_log('Impresión de rótulos falló (código ' . $codigo . '): ' . implode(' | ', $salida));

    return [
        'ok' => false,
        'etiquetas' => 0,
        'mensaje' => 'No se pudo hablar con la impresora "' . IMPRESORA_ROTULOS . '". Revisá que '
                   . 'esté encendida y que ese sea el nombre exacto con el que aparece en Windows. '
                   . 'Mientras tanto se puede usar "Descargar PDF".',
    ];
}

/**
 * Atiende la acción "imprimir_rotulos" y responde en JSON. Termina la ejecución.
 *
 * Vive acá y no en cada controlador porque las TRES pantallas que imprimen rótulos —Picking, el
 * Historial y "Generar rótulos"— mandan exactamente lo mismo y esperan exactamente lo mismo. Con
 * una copia en cada controlador, el día que cambie un mensaje de error quedarían dos pantallas
 * diciendo una cosa y una tercera diciendo otra.
 *
 * $cuerpo es el JSON ya decodificado del request; el CSRF lo valida el controlador antes de llamar.
 */
function responderImpresionDeRotulos(array $cuerpo) {
    $rotulos = $cuerpo['rotulos'] ?? null;

    if (!is_array($rotulos) || !$rotulos) {
        http_response_code(400);
        echo json_encode(['exito' => false, 'error' => 'No se recibió ningún rótulo para imprimir.']);
        exit();
    }

    $resultado = imprimirRotulosEnEtiquetadora($rotulos);

    echo json_encode([
        'exito'     => $resultado['ok'],
        'mensaje'   => $resultado['mensaje'],
        'etiquetas' => $resultado['etiquetas'],
        // Se manda el mismo texto en 'error' para que sirva a un llamador que solo mire ese campo,
        // que es como están escritos los demás fetch de estas pantallas.
        'error'     => $resultado['ok'] ? null : $resultado['mensaje'],
    ]);
    exit();
}
