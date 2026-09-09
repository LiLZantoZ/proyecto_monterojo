<?php
// modules/historial/helper_rotulos_tspl.php
// Imprime los rótulos DIRECTO en la etiquetadora, sin PDF y sin diálogo del navegador.
//
// Por qué existe además del PDF: la TSC TE200 no es una impresora de hojas. Cuando se le manda una
// página —sea el PDF o el "Imprimir" del navegador— hay tres capas que pueden arruinar la etiqueta
// antes de que llegue al papel: el diálogo del navegador (escala, márgenes, encabezados), el
// driver de Windows (tamaño de material, orientación) y el renderizado en sí. Cualquiera de las
// tres mal puesta saca el rótulo corrido, cortado, girado o directamente en blanco, y como son
// tres capas distintas el error nunca se arregla en un solo lugar. Eso fue exactamente lo que pasó
// con esta impresora: etiquetas al revés primero, etiquetas en blanco después.
//
// TSPL es el idioma propio de la impresora: se le manda "SIZE 100 mm, 40 mm", "TEXT ...",
// "BARCODE ...", "PRINT 1,1" y ella dibuja la etiqueta con su firmware. No hay nada en el medio
// que pueda reescalar ni reacomodar, la medida es exacta por definición, y el código de barras
// sale a los 203 dpi nativos del cabezal en vez de ser un PNG estirado.
//
// El trabajo se manda al spooler de Windows con el tipo de datos "RAW" (ver
// scripts/imprimir_raw.ps1): es la única forma de que el driver pase los bytes tal cual en vez de
// tratarlos como texto e imprimir las LETRAS de los comandos en la etiqueta.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/helper_rotulos_lista.php';

// La TE200 es de 203 dpi = 8 puntos por milímetro. Toda la maqueta de abajo está en PUNTOS, que es
// la única unidad en la que TSPL posiciona texto y códigos de barras.
const TSPL_PUNTOS_POR_MM = 8;
const TSPL_ANCHO_MM = 100;
const TSPL_ALTO_MM  = 40;
const TSPL_ANCHO  = TSPL_ANCHO_MM * TSPL_PUNTOS_POR_MM;   // 800
const TSPL_ALTO   = TSPL_ALTO_MM  * TSPL_PUNTOS_POR_MM;   // 320
const TSPL_MARGEN = 18;                                    // aire entre el recuadro y el texto

// Fuentes internas de la impresora, con el ancho y alto de celda de cada una en puntos. Se usan
// las de la impresora y no una imagen porque el firmware las dibuja al instante y salen nítidas;
// el precio es que hay que elegir el tamaño a mano según cuánto texto entra (ver textoQueEntre).
const TSPL_FUENTES = [
    '2' => ['ancho' => 12, 'alto' => 20],
    '3' => ['ancho' => 16, 'alto' => 24],
    '4' => ['ancho' => 24, 'alto' => 32],
];

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
 * automático como en CSS. Un punto de venta como "EXITO WOW CALLE 80 BOGOTA" tiene que bajar de
 * tamaño solo, o se sale de la etiqueta sin que nadie se entere hasta ver el papel impreso.
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
 * Los comandos TSPL de UNA etiqueta.
 *
 * La maqueta es la misma que la del PDF y la de la pantalla (ver cssRotulosPdf en
 * helper_rotulos_pdf.php y assets/css/partes/04-rotulo.css): marca y contador de cajas arriba
 * compartiendo renglón, punto de venta grande, orden de compra y CEDI en un renglón, producto, y
 * el código de barras abajo. Se mantiene igual a propósito: el mismo rótulo tiene que verse igual
 * salga por donde salga.
 */
function tsplDeUnRotulo(array $r) {
    $anchoUtil = TSPL_ANCHO - 2 * TSPL_MARGEN;
    $lineas = [];

    // El recuadro que encierra el rótulo, dibujado 4 puntos adentro del borde físico: pegado al
    // filo, la tolerancia mecánica del avance del rollo se lo come de a ratos y el marco sale
    // cortado de un lado sí y otro no.
    $lineas[] = 'BOX 4,4,' . (TSPL_ANCHO - 5) . ',' . (TSPL_ALTO - 5) . ',3';

    // Encabezado: la marca a la izquierda y el contador de cajas a la derecha. TSPL no sabe alinear
    // a la derecha, así que la x se calcula restando el ancho del texto al ancho de la etiqueta.
    $conteo  = 'CAJ ' . $r['numero'] . ' DE ' . $r['total'];
    $xConteo = TSPL_ANCHO - TSPL_MARGEN - mb_strlen($conteo) * TSPL_FUENTES['3']['ancho'];
    $lineas[] = 'TEXT ' . TSPL_MARGEN . ',16,"2",0,1,1,"MONTEROJO GOURMET"';
    $lineas[] = 'TEXT ' . max(TSPL_MARGEN, $xConteo) . ',14,"3",0,1,1,"' . textoTspl($conteo) . '"';

    // Punto de venta: el dato más importante del rótulo —es lo que mira quien recibe la caja— así
    // que se lleva la fuente más grande que su propio largo le permita.
    [$fuentePv, $textoPv] = textoQueEntre($r['pv'], $anchoUtil, ['4', '3', '2']);
    $lineas[] = 'TEXT ' . TSPL_MARGEN . ',54,"' . $fuentePv . '",0,1,1,"' . textoTspl($textoPv) . '"';

    $meta = 'O/C ' . ($r['oc'] !== '' ? $r['oc'] : '-') . '  ·  CEDI ' . $r['cedi'];
    [$fuenteMeta, $textoMeta] = textoQueEntre($meta, $anchoUtil, ['2']);
    $lineas[] = 'TEXT ' . TSPL_MARGEN . ',98,"' . $fuenteMeta . '",0,1,1,"' . textoTspl($textoMeta) . '"';

    // El producto puede venir vacío cuando el rótulo se genera a mano desde "Generar rótulos": en
    // ese caso va una raya para completarlo con lapicero, igual que en el PDF.
    $producto = $r['producto'] !== '' ? $r['producto'] : '________________________';
    [$fuenteProd, $textoProd] = textoQueEntre($producto, $anchoUtil, ['3', '2']);
    $lineas[] = 'TEXT ' . TSPL_MARGEN . ',126,"' . $fuenteProd . '",0,1,1,"' . textoTspl($textoProd) . '"';

    // El código de barras. El módulo —el ancho de la barra más fina— baja a 1 solo si con 2 no
    // entra: 2 puntos son 0,25mm, que es el mínimo que piden los lectores de las cadenas; con 1 el
    // código es más exigente de leer, pero es preferible a que salga cortado y no lea nada.
    $tienda  = $r['ean_pv'] !== '' ? $r['ean_pv'] : $r['pv'];
    $idCaja  = identificadorDeCajaRotulo($r['oc'], $tienda, $r['numero']);
    $modulo  = anchoCodigo128($idCaja, 2) <= $anchoUtil ? 2 : 1;
    $xCodigo = max(TSPL_MARGEN, intdiv(TSPL_ANCHO - anchoCodigo128($idCaja, $modulo), 2));

    // Parámetros de BARCODE: x, y, tipo, alto, texto legible (2 = debajo y centrado), rotación,
    // ancho de la barra fina, ancho de la barra gruesa, contenido.
    $lineas[] = 'BARCODE ' . $xCodigo . ',158,"128",76,2,0,' . $modulo . ',' . ($modulo * 2)
              . ',"' . textoTspl($idCaja) . '"';

    return implode("\r\n", $lineas);
}

/**
 * El trabajo TSPL completo: la configuración del rollo una sola vez, y después cada etiqueta.
 * Se devuelve como texto para poder verlo y probarlo sin tener la impresora conectada.
 */
function tsplDeRotulos(array $rotulos) {
    $cabecera = [
        'SIZE ' . TSPL_ANCHO_MM . ' mm,' . TSPL_ALTO_MM . ' mm',
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
    // caracteres con comillas y saltos de línea, y el largo máximo de un comando en Windows
    // (~8.000 caracteres) lo cortaría en la mitad de una etiqueta.
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
