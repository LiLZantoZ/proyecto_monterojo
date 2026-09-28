<?php
// modules/consolidados/model_consolidados_import.php
// Lectura de los dos Excel que alimentan el sistema: el Consolidado que manda la cadena y el
// maestro de productos.
//
// SIEMPRE con setReadDataOnly(true): sin eso PhpSpreadsheet carga también estilos, formatos y
// fórmulas de cada celda, y un archivo de 350 filas pasa de tardar un segundo a comerse el
// max_execution_time.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/model_consolidados.php';   // mapaMaestro(), decorarConMaestro()

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;

// Lee la PRIMERA hoja del archivo sin importar cómo se llame. El Consolidado la trae como
// "Sheet1", pero ese nombre depende de con qué la exportaron y no se puede dar por seguro.
//
// SOLO ESA HOJA, NO EL LIBRO ENTERO (2026-09-16)
// load() a secas carga TODAS las hojas y recién después se descartan las que no se usan. Con un
// export de la cadena —una hoja y nada más— da lo mismo, pero acá se suben también planillas de
// trabajo de la bodega: "CONSOLIDADOS PLANILLA LOCALES (15 AGOSTO).xlsx" trae 24 hojas y 9,4
// millones de celdas, de las cuales la primera —la única que interesa— es el 3,2%. El resto se
// lo lleva una hoja llamada "UB" con 1.048.576 filas: el máximo de Excel, que es lo que queda
// cuando alguien le aplica un formato a una columna entera. Cargar todo eso agotaba los 512 MB
// de memory_limit y el importador moría con un error fatal de PHP —pantalla en blanco, sin
// mensaje— en vez de decir "a este archivo le faltan estas columnas".
//
// listWorksheetNames() lee solo los metadatos del libro (Xlsx y Xls lo resuelven sin abrir las
// celdas), así que preguntar el nombre de la primera hoja para pedir únicamente esa es barato.
//
// PERO NO SIEMPRE ALCANZA CON LA PRIMERA HOJA: hay planillas cuya primera hoja resuelve datos con
// un VLOOKUP contra OTRA hoja del mismo libro. El Consolidado de Farmatodo es así: sus 310 celdas
// de SKU y descripción son "=VLOOKUP(Q2,Hoja2!A:C,2,0)". Si se carga solo la primera, esas
// fórmulas se quedan sin el rango al que apuntan y getCalculatedValue() —en resolverFormulas()—
// agota la memoria intentando resolverlas. Por eso, cuando se detecta una fórmula que nombra otra
// hoja, se descarta esa lectura y se vuelve a leer el libro entero, como se hacía siempre.
//
// UNA SOLA LECTURA POR ARCHIVO (2026-09-16)
// El mismo .xlsx se lee TRES veces en una sola importación: el controlador calcula la huella para
// avisar si el archivo ya estaba cargado, importarConsolidado() la vuelve a calcular —a propósito,
// para guardar siempre la del archivo que acaba de importar— y después lee las filas. Eso se
// escribió cuando el Consolidado pesaba 60 KB y la tercera lectura no costaba nada. Con el export
// de facturación de SAP ya no: medido el 2026-09-16 sobre un archivo de 2,9 MB y 12.395 filas, las
// tres lecturas llevaban la petición a 496 MB de los 512 de memory_limit y a 74 s de los 120 de
// max_execution_time —pasaba, pero por nada—. PhpSpreadsheet además no le devuelve al sistema la
// memoria de cada lectura, así que las tres se acumulan.
//
// Se guarda el resultado de la ÚLTIMA lectura y nada más: en una petición se importa un archivo,
// no diez, y quedarse con todos los leídos cambiaría un problema de memoria por otro.
function leerPrimeraHoja($rutaArchivo) {
    static $ultima = ['clave' => null, 'filas' => null];

    // Tamaño y fecha en la clave: si el archivo cambia dentro de la misma petición —o si dos
    // archivos distintos caen en la misma ruta temporal— se vuelve a leer en vez de servir lo
    // que había.
    $clave = realpath($rutaArchivo) . '|' . filesize($rutaArchivo) . '|' . filemtime($rutaArchivo);
    if ($ultima['clave'] === $clave) {
        return $ultima['filas'];
    }

    // Se suelta lo anterior ANTES de leer lo nuevo, para no tener dos archivos en memoria a la vez.
    $ultima = ['clave' => null, 'filas' => null];

    // Primero el intento barato: solo la primera hoja. Devuelve null si esa hoja tiene fórmulas
    // que miran a otra, y ahí no queda más que leer el libro completo.
    $filas = leerHojaCero($rutaArchivo, true);
    if ($filas === null) {
        $filas = leerHojaCero($rutaArchivo, false);
    }

    $ultima = ['clave' => $clave, 'filas' => $filas];

    return $filas;
}

/**
 * Lee la hoja 0 y devuelve sus filas con las fórmulas ya resueltas.
 *
 * Con $soloLaPrimera en true carga únicamente esa hoja —mucho más barato— y devuelve null si
 * descubre que no alcanzaba, es decir, si alguna celda es una fórmula que nombra otra hoja
 * ("=VLOOKUP(Q2,Hoja2!A:C,2,0)"). Se comprueba ANTES de resolver ninguna fórmula, porque
 * intentar calcular una referencia a una hoja que no se cargó es justamente lo que agota la
 * memoria.
 */
function leerHojaCero($rutaArchivo, $soloLaPrimera) {
    $lector = IOFactory::createReaderForFile($rutaArchivo);
    $lector->setReadDataOnly(true);

    if ($soloLaPrimera) {
        // Si el lector no supiera enumerarlas, se carga el libro completo como antes: es más
        // lento, pero es el comportamiento que ya funcionaba y no vale la pena fallar por esto.
        $nombres = $lector->listWorksheetNames($rutaArchivo);
        if (!$nombres) {
            return null;
        }
        $lector->setLoadSheetsOnly($nombres[0]);
    }

    $libro  = $lector->load($rutaArchivo);
    $hoja   = $libro->getSheet(0);
    $crudas = $hoja->toArray(null, false, false, false);

    if ($soloLaPrimera && hayFormulaHaciaOtraHoja($crudas)) {
        $libro->disconnectWorksheets();
        unset($libro, $hoja, $crudas);
        return null;
    }

    $filas = resolverFormulas($hoja, $crudas);

    // Libera la memoria del libro antes de seguir: con archivos grandes, no hacerlo deja todo
    // el contenido retenido durante el resto de la importación.
    $libro->disconnectWorksheets();
    unset($libro);

    return $filas;
}

// ¿Alguna celda es una fórmula que nombra otra hoja? El '!' es lo que separa la hoja del rango
// en una referencia de Excel ("Hoja2!A:C"), y no aparece en una fórmula que solo mire su propia
// hoja ("=A2*B2").
function hayFormulaHaciaOtraHoja(array $filas) {
    foreach ($filas as $fila) {
        foreach ($fila as $valor) {
            if (is_string($valor) && isset($valor[0]) && $valor[0] === '=' && strpos($valor, '!') !== false) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Reemplaza las celdas que son una FÓRMULA por su resultado.
 *
 * Por qué hace falta: toArray() devuelve el texto de la fórmula, no el número. Un maestro armado
 * en Excel suele traer las unidades por caja resueltas con un VLOOKUP contra otra hoja, y esas
 * celdas llegaban como la cadena "=+VLOOKUP(B2,Hoja2!$A:$D,4,FALSE)". Como eso no es numérico, el
 * campo quedaba en null, el COALESCE de la consulta dejaba el valor viejo, y la importación
 * terminaba diciendo "se cargaron 318 productos" sin haber cambiado NADA. Ese fue exactamente el
 * síntoma reportado el 2026-09-11: el archivo se sube, avisa que salió bien, y los datos siguen
 * igual.
 *
 * Se recorre celda por celda en vez de pedirle a toArray() que calcule todo, porque así un archivo
 * SIN fórmulas —que es el caso normal— no paga nada: solo se comprueba si el texto empieza con "=".
 *
 * Los errores de Excel (#N/A, #REF!, #VALOR!) se convierten en null: son "este dato no se pudo
 * resolver", y guardar la cadena "#N/A" como descripción de un producto sería peor que no tocarla.
 */
function resolverFormulas($hoja, array $filas) {
    foreach ($filas as $y => $fila) {
        foreach ($fila as $x => $valor) {
            if (!is_string($valor) || $valor === '' || $valor[0] !== '=') {
                continue;
            }

            try {
                // +1 en las dos coordenadas: toArray() indexa desde 0 y la hoja desde 1/A.
                $calculado = $hoja->getCell(Coordinate::stringFromColumnIndex($x + 1) . ($y + 1))
                                  ->getCalculatedValue();
            } catch (Throwable $e) {
                // Una función que PhpSpreadsheet no sabe calcular no puede voltear la importación
                // entera: se deja la celda como está y ese campo se tratará como "no vino".
                continue;
            }

            if (is_string($calculado) && ($calculado === '' || $calculado[0] === '#')) {
                $calculado = null;
            }

            $filas[$y][$x] = $calculado;
        }
    }

    return $filas;
}

// Las fechas pueden llegar como número de serie de Excel (46245) o como texto ("04/09/2026 00:00").
// Devuelve 'Y-m-d' o null.
function fechaDesdeExcel($valor) {
    if ($valor === null || $valor === '') {
        return null;
    }
    if (is_numeric($valor)) {
        try {
            return FechaExcel::excelToDateTimeObject((float) $valor)->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }
    // El formato del archivo es d/m/Y: strtotime leería 04/09/2026 como 9 de abril (formato de
    // Estados Unidos), así que se parsea explícitamente y strtotime queda solo de respaldo.
    $fecha = DateTime::createFromFormat('d/m/Y H:i', trim((string) $valor))
          ?: DateTime::createFromFormat('d/m/Y', trim((string) $valor));
    if ($fecha) {
        return $fecha->format('Y-m-d');
    }
    $tiempo = strtotime((string) $valor);
    return $tiempo ? date('Y-m-d', $tiempo) : null;
}

/**
 * Un precio leído del Excel, o null si la celda no trae un número válido.
 *
 * El archivo de la cadena lo trae como texto con punto decimal ("11857.14"), pero una planilla
 * retocada a mano en un Excel en español puede traerlo con coma ("11857,14") o con separador de
 * miles ("11.857,14", "11,857.14"). La regla: si aparecen los dos signos, el ÚLTIMO es el
 * decimal; si aparece uno solo, repetido ("1.234.567") es de miles y una sola vez es el decimal.
 *
 * Nunca devuelve 0 por no entender la celda: un precio en cero sumaría una orden de valor cero sin
 * que nada avise, y null sí se ve ("sin precio") en la pantalla.
 */
function precioDesdeExcel($valor) {
    return numeroDesdeExcel($valor, 2);
}

/**
 * Un número no negativo leído del Excel, redondeado a $decimales, o null si la celda no trae un
 * número válido. Es el lector de precioDesdeExcel(), generalizado para los cubicajes, que
 * necesitan 7 decimales (0,8936165 m³) y no 2. Las reglas de separadores están en el comentario
 * de precioDesdeExcel().
 */
function numeroDesdeExcel($valor, $decimales = 2) {
    if ($valor === null) {
        return null;
    }
    if (is_int($valor) || is_float($valor)) {
        return $valor >= 0 ? round((float) $valor, $decimales) : null;
    }

    $texto = preg_replace('/[\s$]/u', '', (string) $valor);
    if ($texto === '' || !preg_match('/^\d[\d.,]*$/', $texto)) {
        return null;
    }

    $ultimoPunto = strrpos($texto, '.');
    $ultimaComa  = strrpos($texto, ',');

    if ($ultimoPunto !== false && $ultimaComa !== false) {
        $decimal = $ultimoPunto > $ultimaComa ? '.' : ',';
        $miles   = $decimal === '.' ? ',' : '.';
        $texto   = str_replace([$miles, $decimal], ['', '.'], $texto);
    } elseif ($ultimaComa !== false || $ultimoPunto !== false) {
        $signo = $ultimaComa !== false ? ',' : '.';
        // Varios signos iguales ("1.234.567") solo pueden ser separadores de miles.
        $texto = substr_count($texto, $signo) > 1
            ? str_replace($signo, '', $texto)
            : str_replace($signo, '.', $texto);
    }

    return is_numeric($texto) ? round((float) $texto, $decimales) : null;
}

function textoLimpio($valor, $maximo = 255) {
    $texto = trim((string) ($valor ?? ''));
    return $texto === '' ? null : mb_substr($texto, 0, $maximo);
}

/**
 * Un código de producto (PLU o SKU) que sirva para identificar algo, o null.
 *
 * Igual que textoLimpio(), pero además descarta los CEROS. La matriz de productos que exporta SAP
 * trae la columna PLU llena de "0" cuando ese dato todavía no está asignado (las 41 filas del
 * archivo del 2026-09-19 venían así), y un cero no identifica a ningún producto: guardarlo dejaba
 * decenas de productos con plu = '0', que después se cruzan entre sí al resolver el Consolidado
 * —productoDelMaestro() busca por PLU— y devuelven cualquier cosa.
 */
function codigoLimpio($valor, $maximo = 30) {
    $texto = textoLimpio($valor, $maximo);

    return ($texto === null || trim($texto, '0') === '') ? null : $texto;
}

// ---------------------------------------------------------------------------------------------
// CONSOLIDADO
//
// Las columnas se buscan POR NOMBRE en la fila de encabezado, no por posición fija. El archivo
// tiene 37 columnas y basta que la cadena agregue una para que todos los índices escritos a mano
// se corran uno y la importación empiece a guardar el precio en el campo de la cantidad, sin
// fallar y sin avisar.
// ---------------------------------------------------------------------------------------------

// Nombre de columna del archivo => campo interno. La comparación es laxa (sin tildes, sin
// mayúsculas, sin espacios de más) porque estos encabezados varían entre exportaciones.
function columnasConsolidado() {
    return [
        'nombre lugar entrega factura'   => 'cedi',
        'numero de la orden de compra'   => 'orden_compra',
        'tipo orden de compra'           => 'tipo_orden',
        'plu / sku'                      => 'plu',
        // El SKU es lo ÚNICO que identifica un producto sin ambigüedad: hay productos que
        // comparten PLU y EAN y solo se distinguen por él (ver productoDelMaestro). No todos
        // los archivos lo traen —el de Éxito sí, en una columna calculada— y por eso es
        // opcional, pero cuando viene manda sobre todo lo demás.
        'sku'                            => 'sku_item',
        'descripcion del item'           => 'descripcion_item',
        'ean del item'                   => 'ean_item',
        'ean punto de venta'             => 'ean_punto_venta',
        'nombre punto de venta'          => 'punto_venta',
        'direccion punto de venta'       => 'direccion_punto_venta',
        'cantidad pto vta'               => 'unidades',
        'cantidad total'                 => 'cantidad_total',
        'f. documento o/c'               => 'fecha_documento',
        'f. minima entrega'              => 'fecha_minima_entrega',
        'fecha maxima de entrega'        => 'fecha_maxima_entrega',
        // El precio unitario (2026-09-14). Lo usa Órdenes de compra para el VALOR de cada orden,
        // que va por el bruto. Opcionales: el export de SAP no los trae y no por eso es inválido.
        'precio bruto'                   => 'precio_bruto',
        'precio neto'                    => 'precio_neto',
        // Quién hizo el pedido (2026-09-14). Separa los pedidos del Éxito de los de otras cadenas
        // del mismo portal, como Farmatodo. Ver condicionPedidoExito().
        'razon social empresa compradora' => 'empresa_compradora',
        // El nombre del producto en la planilla de Farmatodo, calculado con un BUSCARV. No se guarda
        // aparte: reemplaza a "Descripcion del item" cuando esa viene vacía (ver importarConsolidado).
        'denominacion'                   => 'denominacion',
        // Otra columna donde puede venir el nombre del producto. El Consolidado del Éxito del
        // 2026-09-21 trae "Descripcion del item" VACÍA y el nombre acá, con el empaque incluido
        // ("CHICHARR CARNUDO LIMA LIMÓN MR 90G PX18"). Se trata igual que DENOMINACIÓN: solo se
        // usa si la columna principal no trae nada (ver importarConsolidado).
        'codigo item proveedor'          => 'codigo_proveedor',
    ];
}

// ---------------------------------------------------------------------------------------------
// CONSOLIDADO, DESDE EL MISMO EXPORT DE FACTURACIÓN DE SAP QUE USA EL MAESTRO
//
// Además del Consolidado "prolijo" (columnas en español, un archivo pensado para esta pantalla),
// Monterojo también despacha directo a distribuidores y clientes propios facturados desde SAP —
// sin pasar por un CEDI de cadena— y ESE pedido sale del mismo export de facturación que ya se
// usa para el Maestro (ver columnasSap()). Confirmado con un archivo real (2026-09-08):
//
//   'Nombre 1'          -> el cliente/tienda que recibe la factura (20 distintos en un archivo de
//                          prueba, desde FARMATODO hasta personas naturales) = punto de venta.
//   'Factura'            -> identifica el despacho. Es MÁS confiable que 'Pedido Cliente' para
//                          "orden de compra": la mayoría de los clientes chicos no mandan un
//                          número propio y esa columna les queda con el texto fijo "MONTEROJO" —
//                          dos entregas del MISMO cliente en facturas distintas compartirían ese
//                          "MONTEROJO" y se mezclarían en una sola entrega. La Factura, en cambio,
//                          es única por despacho SIEMPRE (se comprobó con un cliente que tenía dos
//                          facturas el mismo día: dos números de Factura distintos, un solo
//                          "Pedido Cliente" = "MONTEROJO" para las dos).
//   'Población'          -> la ciudad del cliente. No es un CEDI con nombre propio (este canal no
//                          pasa por los CEDI de cadena), pero es la zona de despacho más parecida
//                          que trae el archivo, y agrupa igual que un CEDI: todos los clientes de
//                          una misma ciudad quedan juntos.
//   'Material'            -> PLU/SKU, 'Código EAN/UPC' -> EAN, 'Ctd.facturada' -> unidades,
//                          'Calle' -> dirección, 'Fecha factura' -> fecha del documento — estas
//                          cuatro son la misma idea que ya usa columnasSap() para el Maestro.
//
// No trae EAN del punto de venta (ese dato es de las cadenas grandes, no de un cliente directo):
// el rótulo, cuando no lo tiene, usa el nombre de la tienda en el código de barras en su lugar —
// ya está resuelto así en identificadorDeCaja() (scripts_historial.js) y su par en PHP.
// ---------------------------------------------------------------------------------------------
function columnasConsolidadoSap() {
    return [
        'poblacion'       => 'cedi',
        'factura'         => 'orden_compra',
        'material'        => 'plu',
        'texto breve de material' => 'descripcion_item',
        'codigo ean/upc'  => 'ean_item',
        'nombre 1'        => 'punto_venta',
        'calle'           => 'direccion_punto_venta',
        'ctd.facturada'   => 'unidades',
        'fecha factura'   => 'fecha_documento',
    ];
}

// Las cinco columnas sin las que un Consolidado —de cualquiera de los dos formatos— no tiene
// nada que guardar.
function columnasObligatoriasConsolidado() {
    return ['cedi', 'orden_compra', 'plu', 'punto_venta', 'unidades'];
}

// Prueba el encabezado contra los DOS formatos válidos de Consolidado —el prolijo primero, el de
// SAP si ese no alcanza— y devuelve el primer mapeo que tenga las cinco columnas obligatorias, o
// el del prolijo (aunque le falten) si ninguno las tiene: entre dos mapeos igual de incompletos,
// da lo mismo cuál se devuelva, porque lo único que hace después quien llama es mirar qué falta.
//
// En $formato queda CUÁL de los dos ganó ('consolidado' o 'sap'). No alcanza con mirar el mapa
// devuelto: los dos formatos llenan los mismos campos internos, y quien importa necesita saber de
// qué archivo vinieron para tratar sus rarezas (ver filasFacturadasDeSap).
function mapaDeConsolidado(array $encabezado, &$formato = null) {
    $obligatorias = columnasObligatoriasConsolidado();
    $formato = 'consolidado';

    $mapa = mapearColumnas($encabezado, columnasConsolidado());
    if (array_diff($obligatorias, array_keys($mapa))) {
        $mapaSap = mapearColumnas($encabezado, columnasConsolidadoSap());
        if (!array_diff($obligatorias, array_keys($mapaSap))) {
            // En SAP la columna 'Material' ES el SKU, y acá se la usa además como PLU
            // porque es lo que el resto del sistema espera encontrar. Se apunta la MISMA
            // columna a los dos campos: mapearColumnas() no puede hacerlo solo, porque
            // asigna cada encabezado a un único campo.
            $mapaSap['sku_item'] = $mapaSap['plu'];
            $formato = 'sap';
            return $mapaSap;
        }
    }

    return $mapa;
}

// ---------------------------------------------------------------------------------------------
// LAS FILAS GEMELAS EN CERO DEL EXPORT DE SAP
//
// En el export de facturación cada línea facturada viene DOS veces, una por cada lote del que
// salió la mercadería: la fila del lote que despachó trae la cantidad, y su gemela —la de 'Lote'
// vacío— viene en CERO con todo lo demás idéntico. Comprobado sobre un archivo real
// (Consolidado.xlsx, 2026-09-16): de 6.220 líneas facturadas, 6.174 tenían su gemela en cero;
// ningún grupo traía dos filas con cantidad ni una sola que fuera únicamente cero, y la suma de
// unidades da 261.739 con ellas o sin ellas.
//
// Guardarlas no rompe ningún total —las consultas agrupan por producto, y sumar cero no cambia
// nada—, pero DISPARA POR ERROR el guardián de "la fila de títulos está corrida", que rechaza un
// archivo cuando más de la mitad de sus líneas quedan en cero unidades. Ese archivo real pasó por
// 23 filas de 12.394 (6.174 contra un umbral de 6.197): con un puñado más de lotes vacíos, el
// próximo export se rechazaría con un mensaje que manda a revisar los títulos del Excel, que es
// justo donde NO está el problema.
//
// Solo se aplica al formato de SAP. En el Consolidado prolijo una línea en cero es un producto
// cancelado —información real— y además es la señal con la que ese guardián hace su trabajo.
// ---------------------------------------------------------------------------------------------
function filasFacturadasDeSap(array $filas, array $mapa) {
    if (!isset($mapa['unidades'])) {
        return $filas;
    }

    return array_values(array_filter($filas, function ($fila) use ($mapa) {
        return (int) ($fila[$mapa['unidades']] ?? 0) !== 0;
    }));
}

// Deja un encabezado comparable: sin tildes, en minúsculas y con los espacios colapsados.
function normalizarEncabezado($texto) {
    $texto = mb_strtolower(trim((string) $texto), 'UTF-8');
    $texto = strtr($texto, ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n', 'ü'=>'u']);
    return preg_replace('/\s+/', ' ', $texto);
}

// ---------------------------------------------------------------------------------------------
// "SUBISTE EL ARCHIVO EN LA PANTALLA QUE NO ES"
//
// El Consolidado (el pedido de la cadena, con CEDI/punto de venta/orden de compra) y el export de
// facturación de SAP (con lo que factura la fábrica a UN distribuidor, sin desglose por tienda)
// son dos archivos completamente distintos que además se llaman parecido —los dos dicen
// "Consolidado" en el nombre—, así que es fácil subir uno a la pantalla del otro. Cuando pasa,
// "al archivo le faltan estas columnas: cedi, orden_compra..." no dice NADA de qué hacer distinto;
// esto reconoce el otro formato y dice exactamente adónde va.
// ---------------------------------------------------------------------------------------------

// El encabezado, ¿tiene pinta de ser el export de facturación de SAP? 'material', 'cantidad' y
// 'cajas' juntos no aparecen en un Consolidado real —ahí la cantidad se llama "Cantidad Pto Vta"
// y no hay ninguna columna de cajas—, así que alcanzan para no confundirlo con uno.
function pareceExportSap(array $encabezado) {
    $mapa = mapearColumnas($encabezado, columnasSap());
    return isset($mapa['sku'], $mapa['cantidad'], $mapa['cajas']);
}

// Al revés: ¿tiene pinta de ser un Consolidado real? cedi + punto de venta + orden de compra
// juntos no aparecen en el export de SAP, que factura a un distribuidor entero y no desglosa por
// tienda ni trae ninguna columna de "orden de compra" de ese tipo.
function pareceConsolidado(array $encabezado) {
    $mapa = mapearColumnas($encabezado, columnasConsolidado());
    return isset($mapa['cedi'], $mapa['punto_venta'], $mapa['orden_compra']);
}

// Devuelve ['campo' => índice de columna] a partir de la fila de encabezado.
//
// Gana la PRIMERA columna que coincida, no la última. En los exports de SAP cada importe viene en
// dos columnas con el MISMO encabezado: la primera trae el número y la segunda la unidad
// ("Ctd.facturada" = 280 y "Ctd.facturada" = "PZA"; "Peso bruto" = 29.96 y "Peso bruto" = "KG").
// Si ganara la última, el sistema leería "PZA" como cantidad, lo convertiría a 0 y el peso y las
// cajas quedarían en cero sin que nada fallara de forma visible — que es exactamente lo que pasó
// la primera vez que se corrió esto.
function mapearColumnas(array $encabezado, array $esperadas) {
    $mapa = [];
    foreach ($encabezado as $indice => $titulo) {
        $clave = normalizarEncabezado($titulo);
        if (isset($esperadas[$clave]) && !isset($mapa[$esperadas[$clave]])) {
            $mapa[$esperadas[$clave]] = $indice;
        }
    }
    return $mapa;
}

// ---------------------------------------------------------------------------------------------
// A CUÁL DE DOS PRODUCTOS GEMELOS APUNTA CADA LÍNEA (2026-09-19)
//
// Monterojo vende el mismo producto en DOS presentaciones: "PLÁTANOS VERDES SAL MARINA MR 100G"
// existe como SKU 36350 de a 18 por caja y como 36351 de a 24. Son dos materiales distintos en
// SAP, con el mismo nombre y —acá está el problema— el MISMO código de barras, porque el EAN
// identifica la bolsa que compra el cliente, no la caja en que viene. En el maestro hay 22 EAN así.
//
// El Consolidado del Éxito trae una columna SKU que NO viene de la cadena: es un BUSCARV por EAN
// que se arma en la planilla. Como el EAN es el mismo para los dos gemelos, ese BUSCARV devuelve
// siempre el mismo, y cuando le toca el que no es, el sistema calcula las cajas con el empaque
// equivocado. Se ve como SALDOS donde no puede haberlos: un CEDI pidió 1530 unidades de un
// producto que va de a 24 —63 cajas y 18 sueltas—, cuando de a 18 son 85 cajas justas.
//
// LA REGLA: un CEDI pide cajas COMPLETAS (confirmado con el usuario el 2026-09-19). Así que entre
// dos gemelos, el bueno es aquel cuyo empaque divide exacto lo que se pidió. Sobre el Consolidado
// del 19-09 eso resolvió los 8 casos, sin ninguno ambiguo.
//
// Se decide LÍNEA POR LÍNEA y no por CEDI: dos tiendas del mismo CEDI pueden pedir presentaciones
// distintas —una pidió 18 y la otra 24 del mismo producto, una caja entera cada una—, y sumadas
// dan 42, que no es múltiplo de ninguno de los dos empaques. Por CEDI ese caso quedaba sin
// resolver; por línea, las dos se resuelven bien.
//
// Es conservador a propósito: solo toca la línea si el SKU que tiene NO da cajas exactas y hay
// UN ÚNICO gemelo que sí. Si ninguno da exacto —o si dan varios— se deja como vino y se avisa,
// porque ahí el empaque no alcanza para decidir y adivinar sería peor que no hacer nada.
//
// Devuelve la lista de correcciones hechas, para poder decirlas en el mensaje de la importación.
// ---------------------------------------------------------------------------------------------
// ---------------------------------------------------------------------------------------------
// EL EAN Y EL PLU QUE LA CADENA USA PARA CADA PRODUCTO (2026-09-21)
//
// El Consolidado trae, por línea, el EAN y el PLU con los que la cadena identifica el producto.
// Esos dos datos son el PUENTE entre su archivo y el maestro: sin ellos, una línea que no traiga
// SKU no se puede resolver y el producto aparece como "falta para el Consolidado" aunque esté
// cargado. Pasó el 2026-09-21 con las mini galletas: el producto existía (SKU 36488) pero sin EAN
// ni PLU, así que el pedido no lo encontraba.
//
// Acá se COMPLETA lo que falte, nunca se pisa lo que ya está. Se anota el EAN y el PLU en el
// producto al que apunta el SKU de la línea, y solo si ese campo está vacío. No crea productos:
// el Consolidado no trae las unidades por caja, así que un producto creado desde acá nacería sin
// el dato que hace falta para convertir a cajas — seguiría figurando como faltante, pero ahora con
// nombre, que es peor porque parece resuelto (decidido con el usuario).
//
// CORRE DESPUÉS de corregirGemelosPorEmpaque() a propósito. La columna SKU de estos archivos es un
// BUSCARV por EAN armado en la planilla, y cuando un EAN lo comparten dos presentaciones trae la
// que no es. Esa función ya reescribió esos SKU usando la regla de las cajas completas, así que
// acá se parte de un SKU verificado. Al revés, se le colgaría el EAN de la cadena al producto
// equivocado —exactamente el error que estamos arreglando hoy, pero automatizado—.
//
// Devuelve lo que completó, para poder decirlo en el mensaje de la importación.
// ---------------------------------------------------------------------------------------------
function completarIdentificadoresDelMaestro($pdo, $idCarga) {
    // El producto al que apunta cada línea se busca primero por SKU. Los Consolidados del Éxito no
    // traen esa columna —el nombre viene en "Codigo item proveedor" y el SKU no viene—, así que el
    // otro camino es el NOMBRE COMPLETO, que sí incluye el empaque ("...MR 100G PX20") y por eso
    // distingue entre las dos presentaciones de un mismo producto. Se exige que haya UN SOLO
    // producto del maestro con ese nombre: si hay dos, no se toca nada.
    $porNombre = [];
    foreach ($pdo->query("SELECT sku, descripcion, unidades_por_caja FROM maestro_productos
                           WHERE descripcion IS NOT NULL AND descripcion <> ''") as $p) {
        $porNombre[normalizarDescripcionProducto($p['descripcion'])][] = $p;
    }

    $lineas = $pdo->prepare(
        "SELECT l.sku_item, l.descripcion_item, l.ean_item, l.plu, SUM(l.unidades) unidades
           FROM consolidado_lineas l
          WHERE l.id_carga = ?
          GROUP BY l.sku_item, l.descripcion_item, l.ean_item, l.plu"
    );
    $lineas->execute([(int) $idCarga]);

    $producto = $pdo->prepare("SELECT sku, descripcion, unidades_por_caja, ean, plu
                                 FROM maestro_productos WHERE sku = ?");

    // COALESCE + NULLIF: completa el que esté vacío y deja intacto el que ya tenga valor.
    $completar = $pdo->prepare(
        "UPDATE maestro_productos
            SET ean = COALESCE(NULLIF(ean, ''), :ean),
                plu = COALESCE(NULLIF(plu, ''), :plu)
          WHERE sku = :sku"
    );

    $completados = [];

    foreach ($lineas as $l) {
        $ean = textoLimpio($l['ean_item'], 20);
        $plu = codigoLimpio($l['plu']);

        // En el Consolidado que sale del export de SAP no hay PLU: ese formato usa la columna
        // 'Material' para las dos cosas, el PLU y el SKU (ver mapaDeConsolidado). Un PLU igual al
        // SKU es el número de material de SAP, no el código con el que la cadena pide el producto,
        // y guardarlo como PLU llenaría el maestro de códigos que ninguna cadena va a mandar.
        if ($plu !== null && $plu === textoLimpio($l['sku_item'], 30)) {
            $plu = null;
        }

        if ($ean === null && $plu === null) {
            continue;
        }

        // 1. Por SKU, si la línea lo trae.
        $destino = null;
        if (textoLimpio($l['sku_item'], 30) !== null) {
            $producto->execute([$l['sku_item']]);
            $destino = $producto->fetch() ?: null;
        }

        // 2. Si no, por el nombre completo, y solo si es de uno solo.
        if ($destino === null) {
            $clave = normalizarDescripcionProducto($l['descripcion_item'] ?? '');
            if ($clave === '' || count($porNombre[$clave] ?? []) !== 1) {
                continue;
            }
            $producto->execute([$porNombre[$clave][0]['sku']]);
            $destino = $producto->fetch() ?: null;
        }

        if ($destino === null) {
            continue;
        }

        // Ya los tiene: no hay nada que completar y no se pisa nada.
        $faltaEan = ($destino['ean'] === null || $destino['ean'] === '') && $ean !== null;
        $faltaPlu = ($destino['plu'] === null || $destino['plu'] === '') && $plu !== null;
        if (!$faltaEan && !$faltaPlu) {
            continue;
        }

        // Último control, el mismo de siempre: si lo pedido no es un múltiplo del empaque de ese
        // producto, es que la línea no es suya y estaríamos colgándole el código de la cadena al
        // producto equivocado —el error que este archivo ya provocó una vez—.
        $uxc = (int) ($destino['unidades_por_caja'] ?? 0);
        if ($uxc > 0 && (int) $l['unidades'] % $uxc !== 0) {
            continue;
        }

        $completar->execute([
            ':ean' => $faltaEan ? $ean : null,
            ':plu' => $faltaPlu ? $plu : null,
            ':sku' => $destino['sku'],
        ]);

        if ($completar->rowCount() > 0) {
            $completados[] = [
                'sku' => $destino['sku'],
                'ean' => $faltaEan ? $ean : null,
                'plu' => $faltaPlu ? $plu : null,
            ];
        }
    }

    return $completados;
}

function corregirGemelosPorEmpaque($pdo, $idCarga) {
    // Los EAN que tienen más de un producto y con empaques DISTINTOS: los que no se distinguen.
    $gemelos = [];
    foreach ($pdo->query(
        "SELECT ean, sku, unidades_por_caja FROM maestro_productos
          WHERE ean IS NOT NULL AND ean <> '' AND unidades_por_caja > 0"
    ) as $f) {
        $gemelos[$f['ean']][] = ['sku' => $f['sku'], 'uxc' => (int) $f['unidades_por_caja']];
    }

    $gemelos = array_filter(
        $gemelos,
        fn($g) => count($g) > 1 && count(array_unique(array_column($g, 'uxc'))) > 1
    );

    if (!$gemelos) {
        return [];
    }

    $lineas = $pdo->prepare(
        "SELECT id_linea, cedi, punto_venta, ean_item, sku_item, unidades
           FROM consolidado_lineas
          WHERE id_carga = ? AND despachado = 0 AND unidades > 0
            AND ean_item IS NOT NULL AND ean_item <> ''"
    );
    $lineas->execute([(int) $idCarga]);

    $reescribir = $pdo->prepare("UPDATE consolidado_lineas SET sku_item = ? WHERE id_linea = ?");
    $corregidas = [];

    foreach ($lineas as $l) {
        if (!isset($gemelos[$l['ean_item']])) {
            continue;
        }

        $unidades = (int) $l['unidades'];
        $candidatos = $gemelos[$l['ean_item']];

        // ¿El SKU que ya trae da cajas exactas? Entonces no hay nada que decidir.
        foreach ($candidatos as $c) {
            if ($c['sku'] === $l['sku_item'] && $unidades % $c['uxc'] === 0) {
                continue 2;
            }
        }

        $sirven = array_values(array_filter($candidatos, fn($c) => $unidades % $c['uxc'] === 0));
        if (count($sirven) !== 1 || $sirven[0]['sku'] === $l['sku_item']) {
            continue;   // ninguno o más de uno: el empaque no alcanza para decidir
        }

        $reescribir->execute([$sirven[0]['sku'], $l['id_linea']]);

        $corregidas[] = [
            'cedi'     => $l['cedi'],
            'punto'    => $l['punto_venta'],
            'unidades' => $unidades,
            'de'       => $l['sku_item'],
            'a'        => $sirven[0]['sku'],
            'uxc'      => $sirven[0]['uxc'],
        ];
    }

    return $corregidas;
}

/**
 * Huella del CONTENIDO de un archivo Consolidado: un SHA-256 que identifica lo que trae, no el
 * archivo.
 *
 * Se calcula sobre los valores que realmente se importan, no sobre los bytes del .xlsx. Dos
 * exportaciones del mismo pedido tienen bytes distintos —cambia la fecha interna, el orden de las
 * hojas, la versión de Excel— pero la misma información, y para quien la sube son el mismo
 * archivo. Comparar bytes no detectaría nada.
 *
 * Las filas se ORDENAN antes de encadenarlas, así que un export con las líneas en otro orden
 * también da la misma huella. Es lo que se espera de "la misma información".
 *
 * Devuelve el hash, o null si el archivo no se pudo leer o no tiene las columnas necesarias.
 */
function huellaDelConsolidado($rutaArchivo) {
    $filas = leerPrimeraHoja($rutaArchivo);
    if (count($filas) < 2) {
        return null;
    }

    // mapaDeConsolidado(): si el formato prolijo no alcanza, prueba el del export de SAP. Sin
    // esto, un Consolidado de ese formato nunca calcularía huella y el aviso de "esto ya está
    // cargado" jamás dispararía para él.
    $mapa = mapaDeConsolidado(array_shift($filas), $formato);
    if (array_diff(columnasObligatoriasConsolidado(), array_keys($mapa))) {
        return null;
    }

    // Las gemelas en cero del export de SAP no se importan (ver filasFacturadasDeSap), así que
    // tampoco cuentan acá: esta función promete el hash de lo que REALMENTE se guarda.
    if ($formato === 'sap') {
        $filas = filasFacturadasDeSap($filas, $mapa);
    }

    $lineas = [];
    foreach ($filas as $fila) {
        $valores = [];
        // Se recorre columnasConsolidado() y no el $mapa para que el orden de los campos dentro
        // de cada línea sea siempre el mismo, sin importar en qué orden vengan en el Excel.
        foreach (array_unique(array_values(columnasConsolidado())) as $campo) {
            $indice = $mapa[$campo] ?? null;
            $valores[] = $indice === null ? '' : trim((string) ($fila[$indice] ?? ''));
        }

        $linea = implode("\x1f", $valores);      // separador de unidad: no aparece en los datos
        if (trim($linea, "\x1f") === '') {
            continue;                            // fila vacía del final del archivo
        }
        $lineas[] = $linea;
    }

    if (!$lineas) {
        return null;
    }

    sort($lineas);
    return hash('sha256', implode("\x1e", $lineas));
}

/**
 * Una carga con esta misma huella que TODAVÍA tenga líneas pendientes. Sirve para avisar antes de
 * importar algo que ya está pendiente ahora mismo.
 *
 * Antes esto comparaba solo contra "la vigente", porque importar reemplazaba lo pendiente y solo
 * podía haber UNA carga activa a la vez. Ahora importar ya no reemplaza nada (ver
 * importarConsolidado): los pendientes de varias cargas conviven, así que el archivo de hoy puede
 * coincidir con el de anteayer si ese todavía no se despachó. Por eso se busca entre TODAS las
 * cargas con algo pendiente, no solo la última.
 *
 * despachado = 0 en la subconsulta: si una carga vieja con esta huella ya se despachó por
 * completo, volver a importarla no es un duplicado de nada activo — es simplemente un pedido
 * nuevo que por casualidad coincide con uno que ya se completó, y no hay nada que avisar.
 */
function cargaConLaMismaHuella($pdo, $huella) {
    if (empty($huella)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT c.id_carga, c.nombre_archivo, c.filas, c.fecha_carga, u.nombre_usuario
         FROM consolidado_cargas c
         LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario
         WHERE c.huella = :huella
           AND EXISTS (
               SELECT 1 FROM consolidado_lineas l
               WHERE l.id_carga = c.id_carga AND l.despachado = 0
           )
         ORDER BY c.id_carga DESC LIMIT 1"
    );
    $stmt->execute([':huella' => $huella]);

    return $stmt->fetch() ?: null;
}

/**
 * Las órdenes de compra que trae el archivo y que YA están cargadas y sin despachar.
 *
 * POR QUÉ NO ALCANZA CON LA HUELLA (2026-09-18)
 * cargaConLaMismaHuella() solo reconoce el MISMO archivo, entero. No reconoce un archivo que sea un
 * PEDAZO de otro ya cargado, y eso es exactamente lo que pasó: se subió el Consolidado completo de
 * una cadena y después "bogota crosdoking.xlsx", que traía tres de sus quince órdenes. Las huellas
 * eran distintas —una tiene 15 órdenes y la otra 3—, no saltó ningún aviso, y esos tres pedidos
 * quedaron cargados dos veces. Picking los mostró por duplicado, pidiendo el doble de cajas.
 *
 * Se compara por ORDEN DE COMPRA y no por línea: es el número con el que la cadena identifica el
 * pedido, y si ese número ya está pendiente es el mismo pedido aunque el archivo traiga las líneas
 * partidas, en otro orden o con alguna cantidad corregida.
 *
 * Solo mira lo PENDIENTE: una orden ya despachada que vuelve a aparecer es un pedido nuevo que
 * reusa el número, no un duplicado de nada vivo (el mismo criterio que cargaConLaMismaHuella).
 *
 * Devuelve ['ordenes' => cuántas trae el archivo, 'repetidas' => [...]] o null si el archivo no se
 * puede leer como Consolidado —de eso ya avisa la importación, con mejor detalle que acá—.
 */
function ordenesYaPendientes($pdo, $rutaArchivo) {
    $filas = leerPrimeraHoja($rutaArchivo);
    if (count($filas) < 2) {
        return null;
    }

    $mapa = mapaDeConsolidado(array_shift($filas));
    if (!isset($mapa['orden_compra'])) {
        return null;
    }

    $ordenes = [];
    foreach ($filas as $fila) {
        $oc = textoLimpio($fila[$mapa['orden_compra']] ?? null, 40);
        if ($oc !== null) {
            $ordenes[$oc] = true;
        }
    }

    if (!$ordenes) {
        return null;
    }

    $marcas = implode(',', array_fill(0, count($ordenes), '?'));
    $stmt = $pdo->prepare(
        "SELECT l.orden_compra, COUNT(*) AS lineas, SUM(l.unidades) AS unidades,
                MIN(c.nombre_archivo) AS nombre_archivo, MIN(c.fecha_carga) AS fecha_carga
           FROM consolidado_lineas l
           JOIN consolidado_cargas c ON c.id_carga = l.id_carga
          WHERE l.despachado = 0 AND l.orden_compra IN ($marcas)
          GROUP BY l.orden_compra
          ORDER BY l.orden_compra"
    );
    $stmt->execute(array_keys($ordenes));

    return ['ordenes' => count($ordenes), 'repetidas' => $stmt->fetchAll()];
}

/**
 * Importa el archivo Consolidado. Se AGREGA a lo que ya está pendiente —no lo reemplaza—: cada
 * carga queda con su propio id_carga y su propia fecha, y Picking/Consolidados muestran los
 * pendientes de TODAS las cargas juntos, diferenciados por esa fecha (decidido con el usuario el
 * 2026-09-08, después de que un archivo nuevo se comiera de un plumazo los pedidos de otro canal
 * que todavía no se habían despachado).
 *
 * Antes esto reemplazaba lo pendiente (DELETE de las líneas sin despachar) porque se asumía que
 * "el archivo del día es la foto completa de todo lo que falta". Eso vale para el Consolidado de
 * una cadena que manda su pedido completo cada vez, pero no para los despachos directos (ver
 * columnasConsolidadoSap): ese canal factura de a poco durante el día, y cada archivo es apenas
 * una PARTE de lo pendiente, no el todo. Reemplazar con ese archivo borraba lo que llegó en el
 * anterior sin que nadie lo hubiera despachado.
 *
 * Devuelve ['exito' => bool, 'mensaje' => string, 'filas' => int, 'id_carga' => int|null].
 */
function importarConsolidado($pdo, $rutaArchivo, $nombreArchivo, $idUsuario) {
    $filas = leerPrimeraHoja($rutaArchivo);

    if (count($filas) < 2) {
        return ['exito' => false, 'mensaje' => 'El archivo no tiene filas de datos.', 'filas' => 0, 'id_carga' => null];
    }

    // Se recalcula desde el archivo en vez de recibirla como parámetro: así importarConsolidado()
    // guarda SIEMPRE la huella de lo que acaba de importar, la llame quien la llame. Cuesta una
    // segunda lectura de un archivo de 60 KB.
    $huella = huellaDelConsolidado($rutaArchivo);

    $encabezado = array_shift($filas);
    $obligatorias = columnasObligatoriasConsolidado();

    // mapaDeConsolidado() prueba el formato prolijo y, si no alcanza, el del export de SAP (ver
    // columnasConsolidadoSap()): es el otro formato válido de Consolidado, para los despachos
    // directos que no pasan por un CEDI de cadena.
    $mapa = mapaDeConsolidado($encabezado, $formato);

    // Sin estas cinco no hay nada que guardar, en NINGUNO de los dos formatos. Se avisa cuál
    // falta en vez de un "formato incorrecto" genérico, que obliga a adivinar qué tiene de malo
    // el archivo.
    $faltan = array_diff($obligatorias, array_keys($mapa));
    if ($faltan) {
        // Ni el Consolidado prolijo ni su variante de SAP tienen lo necesario: ¿es en realidad el
        // export de facturación completo, para el Maestro, y no para acá? (por ejemplo, si le
        // falta 'Nombre 1' o 'Factura' pero sí tiene 'Material' y 'Cajas Físicas', que acá no se
        // usan pero sí identifican el archivo).
        if (pareceExportSap($encabezado)) {
            return [
                'exito'   => false,
                'mensaje' => 'Este archivo es el export de facturación de SAP (trae "Material", '
                           . '"Ctd.facturada", "Cajas Físicas"), no un Consolidado. Va en '
                           . '"Maestro de productos → Cargar desde SAP", no acá.',
                'filas'   => 0,
                'id_carga' => null,
            ];
        }

        return [
            'exito'   => false,
            'mensaje' => 'Al archivo le faltan estas columnas: ' . implode(', ', $faltan) . '.',
            'filas'   => 0,
            'id_carga' => null,
        ];
    }

    // Las filas gemelas en cero del export de SAP se descartan ANTES que cualquier otra cosa:
    // si no, el guardián de "títulos corridos" de más abajo las cuenta como si el archivo
    // estuviera mal armado y lo rechaza. Ver filasFacturadasDeSap().
    $gemelasEnCero = 0;
    if ($formato === 'sap') {
        $antesDeFiltrar = count($filas);
        $filas = filasFacturadasDeSap($filas, $mapa);
        $gemelasEnCero = $antesDeFiltrar - count($filas);
    }

    // ------------------------------------------------------------------------------------------
    // COLUMNAS QUE VIENEN VACÍAS Y SE PUEDEN SUPLIR CON OTRA (formato Farmatodo, 2026-09-14)
    //
    // El Consolidado de Farmatodo sale del mismo portal que el del Éxito, con los mismos títulos,
    // pero lo llena distinto: cada orden va directo a UNA tienda, así que deja "Cantidad Pto Vta"
    // vacía y pone la cantidad en "Cantidad Total"; y deja vacía "Descripcion del item" y trae el
    // nombre en una columna "DENOMINACIÓN". Sin esto, las 155 líneas quedaban en 0 unidades y el
    // archivo se rechazaba como si tuviera los títulos corridos.
    //
    // Se decide por el ARCHIVO ENTERO y no fila por fila, a propósito. En el del Éxito, "Cantidad
    // Total" es el total de la orden repartido entre VARIAS tiendas: si una fila suelta viniera sin
    // "Cantidad Pto Vta" y se completara con el total, esa tienda recibiría lo de todas.
    // ------------------------------------------------------------------------------------------
    $columnaVacia = function ($campo) use ($mapa, $filas) {
        if (!isset($mapa[$campo])) {
            return true;
        }
        foreach ($filas as $fila) {
            $v = $fila[$mapa[$campo]] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                return false;
            }
        }
        return true;
    };

    $ajustes = [];

    if ($gemelasEnCero) {
        $ajustes[] = "se descartaron {$gemelasEnCero} fila(s) en 0 unidades que el export de SAP "
                   . 'repite por lote (la cantidad va en su fila gemela)';
    }

    if ($columnaVacia('unidades') && !$columnaVacia('cantidad_total')) {
        // Solo si cada orden va a una única tienda: es lo que hace que "Cantidad Total" sea la de
        // esa tienda y no la suma de varias.
        $tiendasPorOrden = [];
        foreach ($filas as $fila) {
            $oc = textoLimpio($fila[$mapa['orden_compra']] ?? null, 40);
            $pv = textoLimpio($fila[$mapa['punto_venta']] ?? null, 180);
            if ($oc !== null && $pv !== null) {
                $tiendasPorOrden[$oc][$pv] = true;
            }
        }
        $repartidas = array_filter($tiendasPorOrden, fn($t) => count($t) > 1);

        if ($repartidas) {
            return [
                'exito'   => false,
                'mensaje' => 'La columna "Cantidad Pto Vta" viene vacía y ' . count($repartidas) . ' orden(es) van a más de '
                           . 'una tienda, así que no se puede saber cuántas unidades le tocan a cada una. Revisá el archivo: '
                           . 'esa columna tiene que traer la cantidad de cada punto de venta.',
                'filas'   => 0,
                'id_carga' => null,
            ];
        }

        $mapa['unidades'] = $mapa['cantidad_total'];
        $ajustes[] = 'las unidades se tomaron de "Cantidad Total", porque "Cantidad Pto Vta" venía vacía y cada orden va a una sola tienda';
    }

    // El nombre del producto puede venir en tres columnas según quién exporte el archivo, y en
    // cada una de ellas la principal llega vacía. Se prueban en orden y se usa la primera que
    // traiga algo; si "Descripcion del item" viene llena, no se toca nada.
    if ($columnaVacia('descripcion_item')) {
        foreach (['denominacion' => 'DENOMINACIÓN', 'codigo_proveedor' => 'Codigo item proveedor'] as $campo => $titulo) {
            if (!$columnaVacia($campo)) {
                $mapa['descripcion_item'] = $mapa[$campo];
                $ajustes[] = 'la descripción se tomó de "' . $titulo . '"';
                break;
            }
        }
    }

    $valor = function (array $fila, $campo) use ($mapa) {
        return isset($mapa[$campo]) ? ($fila[$mapa[$campo]] ?? null) : null;
    };

    // ------------------------------------------------------------------------------------------
    // ¿EL ENCABEZADO ESTÁ CORRIDO RESPECTO DE LOS DATOS?
    //
    // Las columnas se buscan por nombre, lo que protege de que la cadena agregue o mueva una. No
    // protege del caso contrario: que alguien inserte columnas en los DATOS y no en la fila de
    // títulos. Ahí cada nombre sigue encontrándose, pero apunta a la columna de al lado.
    //
    // Pasó de verdad (Consolidado Éxito.xlsx, 2026-09-11): se agregaron dos columnas de SKU con
    // un VLOOKUP y el encabezado solo recibió una etiqueta, así que de ahí en adelante todo quedó
    // corrido un lugar. El sistema leyó las unidades de donde estaba la dirección —texto, que al
    // convertirse a entero da cero— y guardó 318 líneas con 0 unidades y el EAN de la tienda en
    // el campo del nombre. Nada falló: el archivo entró, dijo "318 líneas importadas", y el pedido
    // quedó en la nada hasta que alguien mirara los totales.
    //
    // Estas dos comprobaciones lo detectan sin tener que adivinar cuántas columnas se movieron:
    // un Consolidado real no tiene casi todas sus líneas en cero unidades, y el nombre de un punto
    // de venta no es un EAN de 13 dígitos.
    // ------------------------------------------------------------------------------------------
    $conDatos = 0;
    $enCero   = 0;
    $pvEsEan  = 0;

    foreach ($filas as $fila) {
        if (textoLimpio($valor($fila, 'plu'), 30) === null) {
            continue;
        }
        $conDatos++;

        if ((int) $valor($fila, 'unidades') <= 0) { $enCero++; }

        $pv = textoLimpio($valor($fila, 'punto_venta'), 180);
        if ($pv !== null && preg_match('/^\d{12,14}$/', $pv)) { $pvEsEan++; }
    }

    // La mitad es un umbral holgado a propósito: un Consolidado puede traer alguna línea en cero
    // —un producto que se canceló— o alguna tienda identificada solo por su EAN. Lo que no puede
    // es que sea la norma.
    if ($conDatos > 0 && ($enCero > $conDatos / 2 || $pvEsEan > $conDatos / 2)) {
        $pista = $enCero > $conDatos / 2
            ? "{$enCero} de {$conDatos} líneas quedaron en 0 unidades"
            : "{$pvEsEan} de {$conDatos} puntos de venta quedaron con un código de barras en vez de un nombre";

        return [
            'exito'   => false,
            'mensaje' => "El archivo tiene la fila de títulos CORRIDA respecto de los datos: {$pista}. "
                       . 'Suele pasar cuando se insertan columnas nuevas en la planilla y la fila de '
                       . 'títulos no se corre igual —por ejemplo, agregar una columna de SKU con una '
                       . 'fórmula y dejar su título vacío—. Revisá que cada título esté justo encima '
                       . 'de su columna y volvé a subirlo.',
            'filas'   => 0,
            'id_carga' => null,
        ];
    }

    // "Cantidad Total" viene SOLO en la primera fila de cada grupo (orden + PLU) y en blanco en
    // las demás. Se arrastra el último valor visto para que todas las filas del grupo lo tengan:
    // guardar doscientos nulos obligaría a recalcularlo en cada consulta.
    $totalesPorGrupo = [];
    foreach ($filas as $fila) {
        $plu = textoLimpio($valor($fila, 'plu'), 30);
        if ($plu === null) { continue; }
        $total = $valor($fila, 'cantidad_total');
        if ($total !== null && trim((string) $total) !== '') {
            $totalesPorGrupo[textoLimpio($valor($fila, 'orden_compra'), 40) . '|' . $plu] = (int) $total;
        }
    }

    // ------------------------------------------------------------------------------------------
    // ¿LAS CANTIDADES DE LAS TIENDAS SUMAN LA CANTIDAD TOTAL DE LA ORDEN? (2026-09-18)
    //
    // El archivo trae las dos cosas: cuánto le toca a cada tienda ("Cantidad Pto Vta") y cuánto
    // lleva la orden entera de ese producto ("Cantidad Total"). Si las dos están, tienen que dar
    // lo mismo, y cuando no dan es que una celda está mal.
    //
    // Vino de un caso real (Consolidado Cencosud 18-09-2026.xlsx): una tienda pedía 11.112
    // unidades de un producto cuya orden entera eran 736. Nada falló —el archivo entró, dijo "518
    // líneas importadas"— y Picking mandó a preparar 11.064 unidades que no existían. Se descubrió
    // de casualidad, comparando contra otro archivo que traía la misma orden bien.
    //
    // AVISA, NO RECHAZA: los archivos reales traen diferencias chicas —en el mismo Cencosud había
    // seis, de -12 a +20 unidades— que son del pedido y no errores de carga. Rechazar por eso
    // dejaría a la bodega sin poder trabajar. Lo que hace falta es que la diferencia se VEA, sobre
    // todo la grande, y que quien importa decida.
    //
    // No corre cuando las unidades SE TOMARON de "Cantidad Total" (el caso Farmatodo de más
    // arriba): ahí las dos columnas son la misma y la comprobación se cumpliría sola sin mirar nada.
    // ------------------------------------------------------------------------------------------
    $descuadres = [];

    if (isset($mapa['cantidad_total'], $mapa['unidades']) && $mapa['unidades'] !== $mapa['cantidad_total']) {
        $sumaPorGrupo = [];
        foreach ($filas as $fila) {
            $plu = textoLimpio($valor($fila, 'plu'), 30);
            $oc  = textoLimpio($valor($fila, 'orden_compra'), 40);
            if ($plu === null || $oc === null) { continue; }
            $clave = $oc . '|' . $plu;
            $sumaPorGrupo[$clave] = ($sumaPorGrupo[$clave] ?? 0) + (int) $valor($fila, 'unidades');
        }

        foreach ($totalesPorGrupo as $clave => $total) {
            $suma = $sumaPorGrupo[$clave] ?? 0;
            if ($total > 0 && $suma !== $total) {
                [$oc, $plu] = explode('|', $clave, 2);
                $descuadres[] = [
                    'orden' => $oc, 'plu' => $plu, 'suma' => $suma,
                    'total' => $total, 'diferencia' => $suma - $total,
                ];
            }
        }

        // El peor primero: si hay que mirar uno solo, que sea el que más unidades mueve.
        usort($descuadres, fn($a, $b) => abs($b['diferencia']) <=> abs($a['diferencia']));
    }

    $pdo->beginTransaction();
    try {
        // NO se borra nada de lo que ya había —ni pendiente ni despachado—. Esta carga se suma
        // como una fila nueva de consolidado_cargas, con sus propias líneas propias; las de
        // cargas anteriores (pendientes o ya despachadas) se quedan exactamente como estaban.

        $insertarCarga = $pdo->prepare(
            "INSERT INTO consolidado_cargas (nombre_archivo, filas, huella, id_usuario) VALUES (?, 0, ?, ?)"
        );
        $insertarCarga->execute([mb_substr($nombreArchivo, 0, 255), $huella, $idUsuario]);
        $idCarga = (int) $pdo->lastInsertId();

        $insertar = $pdo->prepare(
            "INSERT INTO consolidado_lineas
                (id_carga, cedi, orden_compra, tipo_orden, plu, sku_item, descripcion_item,
                 ean_item, ean_punto_venta, punto_venta, direccion_punto_venta, unidades,
                 cantidad_total, fecha_documento, fecha_minima_entrega, fecha_maxima_entrega,
                 precio_bruto, precio_neto, empresa_compradora)
             VALUES (:carga, :cedi, :oc, :tipo, :plu, :sku_item, :desc_item, :ean, :ean_pv, :pv,
                     :dir, :unidades, :total, :f_doc, :f_min, :f_max, :p_bruto, :p_neto, :comprador)"
        );

        $guardadas = 0;
        foreach ($filas as $fila) {
            $plu  = textoLimpio($valor($fila, 'plu'), 30);
            $cedi = textoLimpio($valor($fila, 'cedi'), 120);
            $pv   = textoLimpio($valor($fila, 'punto_venta'), 180);
            $oc   = textoLimpio($valor($fila, 'orden_compra'), 40);

            // Fila sin producto, sin destino o sin CEDI: es una fila vacía del final del archivo
            // o un subtotal, no un pedido. Se salta en silencio.
            if ($plu === null || $cedi === null || $pv === null || $oc === null) {
                continue;
            }

            $insertar->execute([
                ':carga'    => $idCarga,
                ':cedi'     => $cedi,
                ':oc'       => $oc,
                ':tipo'     => textoLimpio($valor($fila, 'tipo_orden'), 120),
                ':plu'      => $plu,
                ':sku_item' => textoLimpio($valor($fila, 'sku_item'), 30),
                ':desc_item'=> textoLimpio($valor($fila, 'descripcion_item'), 255),
                ':ean'      => textoLimpio($valor($fila, 'ean_item'), 20),
                ':ean_pv'   => textoLimpio($valor($fila, 'ean_punto_venta'), 20),
                ':pv'       => $pv,
                ':dir'      => textoLimpio($valor($fila, 'direccion_punto_venta'), 255),
                ':unidades' => (int) $valor($fila, 'unidades'),
                ':total'    => $totalesPorGrupo[$oc . '|' . $plu] ?? null,
                ':f_doc'    => fechaDesdeExcel($valor($fila, 'fecha_documento')),
                ':f_min'    => fechaDesdeExcel($valor($fila, 'fecha_minima_entrega')),
                ':f_max'    => fechaDesdeExcel($valor($fila, 'fecha_maxima_entrega')),
                ':p_bruto'  => precioDesdeExcel($valor($fila, 'precio_bruto')),
                ':p_neto'   => precioDesdeExcel($valor($fila, 'precio_neto')),
                ':comprador'=> textoLimpio($valor($fila, 'empresa_compradora'), 120),
            ]);
            $guardadas++;
        }

        if ($guardadas === 0) {
            $pdo->rollBack();
            return ['exito' => false, 'mensaje' => 'El archivo no traía ninguna fila con datos.', 'filas' => 0, 'id_carga' => null];
        }

        $pdo->prepare("UPDATE consolidado_cargas SET filas = ? WHERE id_carga = ?")->execute([$guardadas, $idCarga]);
        $pdo->commit();

        // ANTES de completarPluDelMaestro(): si una línea apunta al gemelo equivocado, ese cruce
        // le colgaría el PLU de la cadena al producto que no es, y el error quedaría guardado en
        // el maestro en vez de solo en esta carga. Ver corregirGemelosPorEmpaque().
        $gemelosCorregidos = corregirGemelosPorEmpaque($pdo, $idCarga);

        // Con los SKU ya verificados, se le anotan al maestro el EAN y el PLU que usa la cadena,
        // donde falten. Ver completarIdentificadoresDelMaestro() para por qué va en este orden.
        $identificadores = completarIdentificadoresDelMaestro($pdo, $idCarga);

        // El Consolidado nuevo puede traer productos que el maestro todavía no tenía asociados a
        // un PLU. Se cruza acá, apenas termina la importación, para que nadie tenga que acordarse
        // de hacerlo desde la pantalla del maestro.
        completarPluDelMaestro($pdo);

        // Los puntos de venta del Éxito toman el número y el nombre de la lista oficial de almacenes
        // (ver model_almacenes_exito.php). Los de otras cadenas no se tocan.
        require_once __DIR__ . '/model_almacenes_exito.php';
        $almacenes = aplicarAlmacenesExito($pdo, $idCarga);

        // Si hubo que suplir alguna columna, se dice: quien importa tiene que poder saber que el
        // archivo no venía como los otros, y de dónde salieron las unidades.
        $mensaje = "Se importaron {$guardadas} líneas."
                 . ($ajustes ? ' Del archivo: ' . implode('; ', $ajustes) . '.' : '');

        if ($almacenes['renombrados']) {
            $mensaje .= " {$almacenes['renombrados']} punto(s) de venta del Éxito tomaron el nombre y la dependencia de la lista de almacenes.";
        }
        if ($almacenes['sin_lista']) {
            $mensaje .= ' ' . count($almacenes['sin_lista']) . ' no están en la lista y quedaron como venían: '
                      . implode(', ', array_slice($almacenes['sin_lista'], 0, 5))
                      . (count($almacenes['sin_lista']) > 5 ? '…' : '') . '.';
        }

        // Lo que el archivo le enseñó al maestro. Se dice porque es un cambio en el catálogo, no
        // en esta carga: a partir de ahora ese producto se va a encontrar por su EAN y su PLU.
        if ($identificadores) {
            $ej = $identificadores[0];
            $mensaje .= ' ' . count($identificadores) . ' producto(s) del maestro tomaron del archivo '
                      . 'el EAN o el PLU que les faltaba (por ejemplo, el SKU ' . $ej['sku']
                      . ($ej['ean'] !== null ? ' ← EAN ' . $ej['ean'] : '')
                      . ($ej['plu'] !== null ? ' ← PLU ' . $ej['plu'] : '') . ').';
        }

        // Las líneas que quedaron apuntando al otro gemelo: se dice cuántas y con un ejemplo, para
        // que quien importa sepa que el sistema tomó una decisión y cuál fue.
        if ($gemelosCorregidos) {
            $ej = $gemelosCorregidos[0];
            $mensaje .= ' ' . count($gemelosCorregidos) . ' línea(s) apuntaban a la presentación '
                      . 'equivocada del producto y se corrigieron para que den cajas completas '
                      . "(por ejemplo, {$ej['unidades']} unidades en {$ej['cedi']}: del SKU {$ej['de']} "
                      . "al {$ej['a']}, que va de a {$ej['uxc']} por caja).";
        }

        // El descuadre se dice con el caso PEOR adentro, con orden, producto y los dos números:
        // "hay 7 diferencias" sin decir cuál obliga a abrir el Excel y buscarlas a mano, que es
        // justo lo que nadie va a hacer con el camión esperando.
        if ($descuadres) {
            $peor = $descuadres[0];
            $mensaje .= ' OJO: en ' . count($descuadres) . ' orden(es) la suma de las tiendas no da la '
                      . '"Cantidad Total" del pedido. La mayor diferencia es de '
                      . sprintf('%+d', $peor['diferencia']) . ' unidades en la orden ' . $peor['orden']
                      . ', producto ' . $peor['plu'] . ' (las tiendas suman ' . number_format($peor['suma'], 0, ',', '.')
                      . ' y el pedido dice ' . number_format($peor['total'], 0, ',', '.') . ').';
        }

        return [
            'exito'      => true,
            'mensaje'    => $mensaje,
            'filas'      => $guardadas,
            'id_carga'   => $idCarga,
            'descuadres' => $descuadres,
        ];

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error importando el consolidado: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar el archivo en la base.', 'filas' => 0, 'id_carga' => null];
    }
}

// ---------------------------------------------------------------------------------------------
// MAESTRO DE PRODUCTOS
//
// A diferencia del Consolidado, este NO se reemplaza: se hace UPSERT. El maestro se completa de a
// poco (hoy llegan veinte productos, mañana los que faltaban), y reemplazarlo entero borraría lo
// que se cargó antes cada vez que alguien sube un archivo parcial.
// ---------------------------------------------------------------------------------------------

// ---------------------------------------------------------------------------------------------
// MAESTRO DESDE EL EXPORT DE FACTURACIÓN DE SAP
//
// Es la vía principal para llenar el maestro, porque ese archivo ya trae todo lo que falta:
// el Material (SKU), la descripción, el EAN —que es el puente con el Consolidado de la cadena— y
// el peso. Es un export de FACTURAS, así que trae una fila por línea facturada y el mismo
// material aparece decenas de veces; acá se consolida a una fila por material.
// ---------------------------------------------------------------------------------------------

function columnasSap() {
    return [
        'material'                  => 'sku',
        'texto breve de material'   => 'descripcion',
        'denominacion'              => 'descripcion_alt',
        'codigo ean/upc'            => 'ean',
        'nomaterial antiguo'        => 'material_antiguo',
        'no material antiguo'       => 'material_antiguo',
        'ctd.facturada'             => 'cantidad',
        'cajas fisicas'             => 'cajas',
        'peso bruto'                => 'peso',
    ];
}

/**
 * Unidades por caja leídas del final del nombre del material.
 *
 * SAP escribe el empaque en el propio nombre: "PAPAS SAL ROSADA MR 100G PX20" son 20 unidades por
 * caja, y "PAPAS LIMA LIMÓN MR 25G BX6x16" son 16 (el segundo número: 6 displays de 16).
 *
 * ESTA es la fuente buena, no la división Ctd.facturada / Cajas Físicas. SAP redondea las cajas
 * físicas a dos decimales, así que esa división devuelve 95 donde el empaque real es 96 —
 * suficiente para que la conversión a cajas salga corrida en los pedidos grandes. Sobre el
 * archivo de prueba, el nombre y la división coincidieron en 50 de 56 materiales y las 6
 * diferencias eran justamente de ±1 por ese redondeo.
 *
 * Devuelve ['presentacion' => 'PX20', 'unidades_por_caja' => 20] o null.
 */
function empaqueDelNombre($descripcion) {
    $texto = strtoupper(trim((string) $descripcion));

    // BX6x16 -> displays de 16. La caja que se mueve en bodega es la de 16.
    if (preg_match('/\bBX\s*(\d+)\s*X\s*(\d+)\s*$/', $texto, $m)) {
        return ['presentacion' => 'BX' . $m[1] . 'x' . $m[2], 'unidades_por_caja' => (int) $m[2]];
    }
    // PX20 -> paca por 20
    if (preg_match('/\bPX\s*(\d+)\s*$/', $texto, $m)) {
        return ['presentacion' => 'PX' . $m[1], 'unidades_por_caja' => (int) $m[1]];
    }

    // ------------------------------------------------------------------------------------------
    // EL MISMO EMPAQUE, ESCRITO COMO LO TRAE "Texto breve de material" (2026-09-18)
    //
    // El export de SAP nombra cada material de DOS formas: "Denominación" usa PX20 / BX6x16, y
    // "Texto breve de material" usa CAJ X20 / BOL X6X18. Son el mismo empaque escrito distinto:
    // comprobado material por material sobre un export real (107 materiales), los dos formatos dan
    // el MISMO número de unidades en 102, y en los 5 restantes uno de los dos simplemente no traía
    // empaque. La presentación se guarda normalizada a PX/BX para que el maestro tenga un solo
    // vocabulario, venga el nombre de donde venga.
    //
    // Van DESPUÉS de PX/BX a propósito: si el nombre trae los dos, manda el de siempre. Eso además
    // evita la única trampa encontrada —dos materiales cuyo texto breve viene abreviado y perdió un
    // espacio: "MINI GALL FRUT ROJO UAU 30G BT CAJ X1108", que se leería como 1108 cuando su
    // Denominación dice PX108, o sea 108—. Por eso el número se acota a tres dígitos: los empaques
    // reales del catálogo van de 12 a 120, y el único valor de cuatro cifras visto es ese error.
    // ------------------------------------------------------------------------------------------

    // BOL X6X18 == BX6x18: el segundo número, igual que arriba.
    if (preg_match('/\bBOL\s*X\s*(\d{1,3})\s*X\s*(\d{1,3})\s*$/', $texto, $m)) {
        return ['presentacion' => 'BX' . $m[1] . 'x' . $m[2], 'unidades_por_caja' => (int) $m[2]];
    }
    // CAJ X20 == PX20
    if (preg_match('/\bCAJ\s*X\s*(\d{1,3})\s*$/', $texto, $m)) {
        return ['presentacion' => 'PX' . $m[1], 'unidades_por_caja' => (int) $m[1]];
    }

    return null;
}

/**
 * Peso por unidad a partir de los que se calcularon en cada línea facturada.
 *
 * Se toma la MEDIANA y no el promedio: basta una línea con el peso mal cargado en SAP para correr
 * el promedio de todas las demás, y ese peso después se multiplica por miles de unidades en el
 * total del CEDI. La mediana ignora esa línea suelta.
 *
 * OJO: la mediana protege de UNA línea equivocada, no de un material que esté mal cargado en SAP
 * de forma consistente. Si todas sus líneas dicen lo mismo y ese valor es incorrecto, acá entra
 * incorrecto — se corrige en SAP, no acá.
 */
function medianaDePesos(array $pesos) {
    if (!$pesos) {
        return null;
    }

    sort($pesos);
    $n = count($pesos);
    $mediana = $n % 2
        ? $pesos[intdiv($n, 2)]
        : ($pesos[$n / 2 - 1] + $pesos[$n / 2]) / 2;

    return round($mediana, 4);
}

/**
 * Importa el maestro desde el export de facturación de SAP.
 *
 * Devuelve ['exito' => bool, 'mensaje' => string, 'filas' => int, 'sin_empaque' => int].
 */
function importarMaestroDesdeSap($pdo, $rutaArchivo) {
    $filas = leerPrimeraHoja($rutaArchivo);

    if (count($filas) < 2) {
        return ['exito' => false, 'mensaje' => 'El archivo no tiene filas de datos.', 'filas' => 0, 'sin_empaque' => 0];
    }

    $encabezado = array_shift($filas);
    $mapa = mapearColumnas($encabezado, columnasSap());

    if (!isset($mapa['sku'])) {
        // Al revés que en importarConsolidado(): ¿es en realidad el Consolidado de la cadena,
        // subido acá por error?
        if (pareceConsolidado($encabezado)) {
            return [
                'exito'   => false,
                'mensaje' => 'Este archivo es un Consolidado (trae CEDI, punto de venta y orden '
                           . 'de compra), no el export de SAP. Va en "Consolidados → Importar '
                           . 'Consolidado", no acá.',
                'filas'   => 0,
                'sin_empaque' => 0,
            ];
        }

        return [
            'exito'   => false,
            'mensaje' => 'El archivo no tiene la columna "Material". ¿Es el export de facturación de SAP?',
            'filas'   => 0,
            'sin_empaque' => 0,
        ];
    }

    $valor = function (array $fila, $campo) use ($mapa) {
        return isset($mapa[$campo]) ? ($fila[$mapa[$campo]] ?? null) : null;
    };

    // ------------------------------------------------------------------------------------------
    // ¿EL ENCABEZADO ESTÁ CORRIDO RESPECTO DE LOS DATOS?
    //
    // Las columnas se buscan por nombre, lo que protege de que la cadena agregue o mueva una. No
    // protege del caso contrario: que alguien inserte columnas en los DATOS y no en la fila de
    // títulos. Ahí cada nombre sigue encontrándose, pero apunta a la columna de al lado.
    //
    // Pasó de verdad (Consolidado Éxito.xlsx, 2026-09-11): se agregaron dos columnas de SKU con
    // un VLOOKUP y el encabezado solo recibió una etiqueta, así que de ahí en adelante todo quedó
    // corrido un lugar. El sistema leyó las unidades de donde estaba la dirección —texto, que al
    // convertirse a entero da cero— y guardó 318 líneas con 0 unidades y el EAN de la tienda en
    // el campo del nombre. Nada falló: el archivo entró, dijo "318 líneas importadas", y el pedido
    // quedó en la nada hasta que alguien mirara los totales.
    //
    // Estas dos comprobaciones lo detectan sin tener que adivinar cuántas columnas se movieron:
    // un Consolidado real no tiene casi todas sus líneas en cero unidades, y el nombre de un punto
    // de venta no es un EAN de 13 dígitos.
    // ------------------------------------------------------------------------------------------
    $conDatos = 0;
    $enCero   = 0;
    $pvEsEan  = 0;

    foreach ($filas as $fila) {
        if (textoLimpio($valor($fila, 'plu'), 30) === null) {
            continue;
        }
        $conDatos++;

        if ((int) $valor($fila, 'unidades') <= 0) { $enCero++; }

        $pv = textoLimpio($valor($fila, 'punto_venta'), 180);
        if ($pv !== null && preg_match('/^\d{12,14}$/', $pv)) { $pvEsEan++; }
    }

    // La mitad es un umbral holgado a propósito: un Consolidado puede traer alguna línea en cero
    // —un producto que se canceló— o alguna tienda identificada solo por su EAN. Lo que no puede
    // es que sea la norma.
    if ($conDatos > 0 && ($enCero > $conDatos / 2 || $pvEsEan > $conDatos / 2)) {
        $pista = $enCero > $conDatos / 2
            ? "{$enCero} de {$conDatos} líneas quedaron en 0 unidades"
            : "{$pvEsEan} de {$conDatos} puntos de venta quedaron con un código de barras en vez de un nombre";

        return [
            'exito'   => false,
            'mensaje' => "El archivo tiene la fila de títulos CORRIDA respecto de los datos: {$pista}. "
                       . 'Suele pasar cuando se insertan columnas nuevas en la planilla y la fila de '
                       . 'títulos no se corre igual —por ejemplo, agregar una columna de SKU con una '
                       . 'fórmula y dejar su título vacío—. Revisá que cada título esté justo encima '
                       . 'de su columna y volvé a subirlo.',
            'filas'   => 0,
            'id_carga' => null,
        ];
    }

    // Una fila por material. El archivo trae el mismo material muchas veces (una por línea de
    // factura), así que se consolida acá antes de tocar la base: 697 filas se vuelven 56 productos.
    $productos = [];
    foreach ($filas as $fila) {
        $sku = textoLimpio($valor($fila, 'sku'), 30);
        if ($sku === null) {
            continue;
        }

        if (!isset($productos[$sku])) {
            $productos[$sku] = ['ean' => null, 'descripcion' => null, 'pesos' => []];
        }

        // Se queda con el primer valor no vacío de cada campo: no todas las líneas del mismo
        // material traen el EAN cargado.
        $ean = textoLimpio($valor($fila, 'ean'), 20);
        if ($ean !== null && $productos[$sku]['ean'] === null) {
            $productos[$sku]['ean'] = $ean;
        }

        $desc = textoLimpio($valor($fila, 'descripcion'), 255) ?? textoLimpio($valor($fila, 'descripcion_alt'), 255);
        if ($desc !== null && $productos[$sku]['descripcion'] === null) {
            $productos[$sku]['descripcion'] = $desc;
        }

        // El peso de UNA unidad, sacado de cada línea facturada. Se juntan todas y después se
        // toma la MEDIANA (ver más abajo), no la primera ni el promedio.
        $cantidad = (float) $valor($fila, 'cantidad');
        $peso     = (float) $valor($fila, 'peso');
        if ($cantidad > 0 && $peso > 0) {
            $productos[$sku]['pesos'][] = $peso / $cantidad;
        }
    }

    if (!$productos) {
        return ['exito' => false, 'mensaje' => 'El archivo no traía ningún material.', 'filas' => 0, 'sin_empaque' => 0];
    }

    // COALESCE en el UPDATE: un archivo que no traiga el EAN de un material no borra el que ya
    // estaba cargado. La excepción es unidades_por_caja, que sí se pisa — si el nombre del
    // material cambió de PX20 a PX24 es porque cambió el empaque, y el valor nuevo es el bueno.
    $sql = "INSERT INTO maestro_productos
                (sku, ean, descripcion, unidades_por_caja, presentacion, peso_unidad_kg)
            VALUES (:sku, :ean, :descripcion, :uxc, :presentacion, :peso)
            ON DUPLICATE KEY UPDATE
                ean               = COALESCE(VALUES(ean), ean),
                descripcion       = COALESCE(VALUES(descripcion), descripcion),
                unidades_por_caja = COALESCE(VALUES(unidades_por_caja), unidades_por_caja),
                presentacion      = COALESCE(VALUES(presentacion), presentacion),
                peso_unidad_kg    = COALESCE(VALUES(peso_unidad_kg), peso_unidad_kg)";

    $pdo->beginTransaction();
    try {
        $insertar = $pdo->prepare($sql);
        $guardados = 0;
        $sinEmpaque = 0;

        foreach ($productos as $sku => $p) {
            $empaque = empaqueDelNombre($p['descripcion'] ?? '');
            if ($empaque === null) {
                $sinEmpaque++;
            }

            $insertar->execute([
                ':sku'          => $sku,
                // NULL y no cadena vacía: la columna tiene índice único y varias cadenas vacías
                // chocarían entre sí, mientras que varios NULL conviven sin problema.
                ':ean'          => $p['ean'],
                ':descripcion'  => $p['descripcion'],
                ':uxc'          => $empaque['unidades_por_caja'] ?? null,
                ':presentacion' => $empaque['presentacion'] ?? null,
                ':peso'         => medianaDePesos($p['pesos']),
            ]);
            $guardados++;
        }

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error importando el maestro desde SAP: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar el maestro en la base.', 'filas' => 0, 'sin_empaque' => 0];
    }

    $cruzados = completarPluDelMaestro($pdo);

    $mensaje = "Se cargaron {$guardados} productos desde SAP";
    if ($cruzados > 0) {
        $mensaje .= ", {$cruzados} cruzaron por EAN con el Consolidado";
    }
    if ($sinEmpaque > 0) {
        $mensaje .= ". {$sinEmpaque} sin el empaque en el nombre: hay que completarles las unidades por caja a mano";
    }

    return ['exito' => true, 'mensaje' => $mensaje . '.', 'filas' => $guardados, 'sin_empaque' => $sinEmpaque];
}

/**
 * Completa el PLU de los productos del maestro cruzando el EAN con el Consolidado cargado.
 *
 * El export de SAP no sabe qué PLU le puso la cadena a cada producto, y el Consolidado no sabe el
 * SKU. Lo único que aparece en los dos es el EAN, así que es por ahí que se unen. Se corre solo
 * después de cada importación, para que nadie tenga que acordarse de hacerlo.
 *
 * Devuelve cuántos productos quedaron con PLU.
 */
function completarPluDelMaestro($pdo) {
    try {
        // IGNORE porque un dato inconsistente del archivo del cliente no puede tirar abajo toda
        // la operación. (Hasta el 2026-09-11 el PLU tenía índice único y esto además evitaba que
        // el UPDATE fallara entero; ya no lo tiene, porque en Monterojo el PLU se repite entre
        // productos distintos.)
        $stmt = $pdo->prepare(
            "UPDATE IGNORE maestro_productos m
             JOIN (SELECT DISTINCT ean_item, plu FROM consolidado_lineas
                   WHERE ean_item IS NOT NULL AND ean_item <> ''
                     -- Solo el PLU del Éxito, que es el que guarda el maestro. El de Farmatodo
                     -- (y el de cualquier otra cadena) es un código suyo: copiarlo acá haría que
                     -- el producto dejara de encontrarse en los pedidos del Éxito. Lo importado
                     -- antes de guardar el comprador (NULL) se trata como hasta ahora.
                     AND (empresa_compradora IS NULL OR empresa_compradora LIKE '%exito%')) c
               ON c.ean_item = m.ean
             SET m.plu = c.plu
             WHERE m.plu IS NULL"
        );
        $stmt->execute();

        return (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos WHERE plu IS NOT NULL")->fetchColumn();

    } catch (PDOException $e) {
        error_log('Error completando el PLU del maestro: ' . $e->getMessage());
        return 0;
    }
}

function columnasMaestro() {
    return [
        'plu'               => 'plu',
        'plu / sku'         => 'plu',
        'sku'               => 'sku',
        'descripcion'       => 'descripcion',
        'descripcion del item' => 'descripcion',
        'nombre producto'   => 'descripcion',
        // Como lo titula la matriz de productos que exporta SAP, y también el Consolidado de
        // Farmatodo. Sin esta línea el nombre se ignoraba EN SILENCIO: el archivo entraba, decía
        // "N productos", y los que eran nuevos quedaban creados sin descripción —en el maestro se
        // veían como una fila en blanco y parecía que el sistema los hubiera borrado— (2026-09-19).
        'denominacion'      => 'descripcion',
        'denominacion del item' => 'descripcion',
        'unidades por caja' => 'unidades_por_caja',
        'und por caja'      => 'unidades_por_caja',
        'unidades x caja'   => 'unidades_por_caja',
        'linea'             => 'linea',
        'negocio'           => 'linea',
        'ean'               => 'ean',
        'ean13'             => 'ean',
        // Como lo titula el maestro que se arma a mano y también el Consolidado de la cadena. Sin
        // esta línea el EAN del archivo se ignoraba en silencio (encontrado el 2026-09-11).
        'ean del item'      => 'ean',
        'ean del producto'  => 'ean',
        'codigo ean'        => 'ean',
        'codigo ean/upc'    => 'ean',
    ];
}

/**
 * Importa un maestro armado a mano (no el export de SAP).
 *
 * Es la vía para completar lo que SAP no da: la línea de cada producto, o las unidades por caja
 * de los materiales cuyo nombre no trae el empaque. Acepta dos formas de identificar el producto:
 *
 *   · con SKU  → crea el producto si no existe, o lo actualiza;
 *   · solo PLU → actualiza un producto que YA esté en el maestro y tenga ese PLU.
 *
 * Un archivo con solo PLU no puede crear productos: el SKU es la clave del maestro y no se puede
 * inventar. Esas filas se cuentan aparte y se avisa, en vez de guardarlas con un código falso que
 * después nadie podría cruzar con SAP.
 *
 * Devuelve ['exito' => bool, 'mensaje' => string, 'filas' => int].
 */
function importarMaestro($pdo, $rutaArchivo) {
    $filas = leerPrimeraHoja($rutaArchivo);

    if (count($filas) < 2) {
        return ['exito' => false, 'mensaje' => 'El archivo no tiene filas de datos.', 'filas' => 0];
    }

    $mapa = mapearColumnas(array_shift($filas), columnasMaestro());

    if (!isset($mapa['sku']) && !isset($mapa['plu'])) {
        return [
            'exito'   => false,
            'mensaje' => 'El archivo no tiene columna SKU ni PLU: no hay con qué identificar el producto.',
            'filas'   => 0,
        ];
    }

    $valor = function (array $fila, $campo) use ($mapa) {
        return isset($mapa[$campo]) ? ($fila[$mapa[$campo]] ?? null) : null;
    };

    // ------------------------------------------------------------------------------------------
    // ¿EL ENCABEZADO ESTÁ CORRIDO RESPECTO DE LOS DATOS?
    //
    // El mismo riesgo que en el Consolidado (ver importarConsolidado): alguien inserta una columna
    // en los datos y no en la fila de títulos, cada nombre se sigue encontrando pero apunta a la
    // columna de al lado, y el archivo entra "bien" con los datos cambiados de lugar.
    //
    // Hasta el 2026-09-14 acá había una COPIA de la comprobación del Consolidado, que mira las
    // unidades del pedido y el punto de venta. Un maestro no tiene ninguna de esas dos columnas: las
    // unidades daban siempre cero y todo maestro con columna PLU se rechazaba como corrido, aunque
    // estuviera perfecto. Se reemplazó por lo que sí delata un maestro corrido:
    //
    //   · unidades por caja que no son un entero razonable (una descripción o un EAN caídos ahí);
    //   · EAN que no son de 8 a 14 dígitos (una descripción o unas unidades caídas ahí).
    //
    // Solo cuentan las celdas CON dato: un maestro que únicamente completa la línea no trae unidades
    // por caja, y eso no es un error.
    // ------------------------------------------------------------------------------------------
    $uxcConDato = 0;
    $uxcRaras   = 0;
    $eanConDato = 0;
    $eanRaros   = 0;

    foreach ($filas as $fila) {
        // codigoLimpio() y no textoLimpio(): un PLU en cero es "todavía no tiene PLU", no un PLU.
        if (codigoLimpio($valor($fila, 'sku')) === null && codigoLimpio($valor($fila, 'plu')) === null) {
            continue;
        }

        $uxc = textoLimpio($valor($fila, 'unidades_por_caja'), 30);
        if ($uxc !== null) {
            $uxcConDato++;
            if (!ctype_digit($uxc) || (int) $uxc < 1 || (int) $uxc > 10000) { $uxcRaras++; }
        }

        $ean = textoLimpio($valor($fila, 'ean'), 30);
        if ($ean !== null) {
            $eanConDato++;
            if (!preg_match('/^\d{8,14}$/', $ean)) { $eanRaros++; }
        }
    }

    // La mitad, como en el Consolidado: algún valor raro suelto es un error de tipeo de esa fila y
    // se ignora más abajo; que sea la mayoría es la columna entera fuera de lugar.
    if ($uxcRaras > $uxcConDato / 2 || $eanRaros > $eanConDato / 2) {
        $pista = $uxcRaras > $uxcConDato / 2
            ? "{$uxcRaras} de {$uxcConDato} valores de \"Unidades por caja\" no son un número de unidades"
            : "{$eanRaros} de {$eanConDato} valores de \"EAN\" no son un código de barras";

        return [
            'exito'   => false,
            'mensaje' => "El archivo tiene la fila de títulos CORRIDA respecto de los datos: {$pista}. "
                       . 'Suele pasar cuando se insertan columnas nuevas en la planilla y la fila de '
                       . 'títulos no se corre igual. Revisá que cada título esté justo encima de su '
                       . 'columna y volvé a subirlo.',
            'filas'   => 0,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // Una entrada por PRODUCTO, no por fila.
    //
    // Estos archivos repiten el mismo producto muchas veces: el maestro del 2026-09-11 traía 318
    // filas para 32 productos. Sin consolidar, la base recibiría 318 escrituras para 32 productos
    // y el conteo final diría "318 productos", que no es cierto.
    //
    // La clave es el SKU (o el PLU si no hay SKU), NUNCA la descripción: hay productos con el
    // mismo nombre y distinto gramaje —"CHICHARR CARNUDO SAL MARINA MR 90G" y el de 150G— que son
    // productos distintos, con SKU y PLU distintos, y agruparlos por nombre los fusionaría en uno
    // solo, mezclando las unidades por caja de dos empaques que no tienen nada que ver.
    // ---------------------------------------------------------------------------------------
    $productos = [];

    foreach ($filas as $fila) {
        $sku = codigoLimpio($valor($fila, 'sku'));
        $plu = codigoLimpio($valor($fila, 'plu'));

        if ($sku === null && $plu === null) {
            continue;
        }

        $clave = $sku !== null ? 'S:' . $sku : 'P:' . $plu;

        if (!isset($productos[$clave])) {
            $productos[$clave] = [
                'sku' => $sku, 'plu' => null, 'ean' => null,
                'descripcion' => null, 'uxc' => null, 'linea' => null,
            ];
        }

        $uxc = $valor($fila, 'unidades_por_caja');
        $ean = textoLimpio($valor($fila, 'ean'), 20);
        $campos = [
            'plu'         => $plu,
            // Un EAN que no es un código de barras ("SIN EAN", "N/A") se ignora igual que unas
            // unidades por caja que no son un número: guardarlo pisaría el EAN bueno, que es el
            // puente con el Consolidado de la cadena.
            'ean'         => ($ean !== null && preg_match('/^\d{8,14}$/', $ean)) ? $ean : null,
            'descripcion' => textoLimpio($valor($fila, 'descripcion'), 255),
            'uxc'         => (is_numeric($uxc) && (int) $uxc > 0) ? (int) $uxc : null,
            'linea'       => textoLimpio($valor($fila, 'linea'), 60),
        ];

        // Se queda con el primer valor no vacío de cada campo: no todas las repeticiones del
        // mismo producto traen todas las columnas cargadas.
        foreach ($campos as $campo => $v) {
            if ($v !== null && $productos[$clave][$campo] === null) {
                $productos[$clave][$campo] = $v;
            }
        }
    }

    if (!$productos) {
        return ['exito' => false, 'mensaje' => 'El archivo no traía ningún producto.', 'filas' => 0];
    }

    // ------------------------------------------------------------------------------------------
    // UN NOMBRE NUEVO QUE CONTRADICE EL EMPAQUE YA CARGADO (2026-09-19)
    //
    // El nombre del producto LLEVA ADENTRO su empaque ("...MR 100G PX24" son 24 por caja, ver
    // empaqueDelNombre). Este importador acepta el nombre pero NO toca unidades_por_caja, así que
    // un nombre que diga otro empaque deja el maestro contradiciéndose: la etiqueta diría "CAJ
    // X18" y el sistema seguiría calculando de a 24. Con eso, las cajas de un pedido salen mal.
    //
    // Pasó de verdad: una matriz de productos traía "PLÁTAN VERDES SAL MARINA MR 100G CAJ X18"
    // para un material que en el export de facturación de SAP es CAJ X24, y otra fila le ponía a
    // un SKU el nombre de un producto completamente distinto. Ninguna de las dos se notaba: el
    // archivo entraba diciendo "9 actualizados".
    //
    // Ante la duda NO se pisa el nombre y se avisa cuál es. Cambiar el empaque de un producto es
    // una decisión de catálogo —hay que corregir el archivo o el empaque, según cuál esté mal— y
    // no algo que deba pasar de callado al subir una planilla. El resto del archivo entra normal.
    // ------------------------------------------------------------------------------------------
    $contradicen = [];
    $empaqueGuardado = $pdo->prepare("SELECT unidades_por_caja FROM maestro_productos WHERE sku = ?");

    foreach ($productos as $clave => $prod) {
        if ($prod['sku'] === null || $prod['descripcion'] === null) {
            continue;
        }

        // Si el archivo trae unidades por caja, manda ese valor y no hay contradicción posible:
        // los dos datos vienen del mismo archivo y se guardan juntos.
        if ($prod['uxc'] !== null) {
            continue;
        }

        $delNombre = empaqueDelNombre($prod['descripcion']);
        if ($delNombre === null) {
            continue;
        }

        $empaqueGuardado->execute([$prod['sku']]);
        $actual = $empaqueGuardado->fetch(PDO::FETCH_COLUMN);

        if ($actual !== false && $actual !== null && (int) $actual !== $delNombre['unidades_por_caja']) {
            $contradicen[] = [
                'sku'      => $prod['sku'],
                'nombre'   => $prod['descripcion'],
                'del_nombre' => $delNombre['unidades_por_caja'],
                'guardado' => (int) $actual,
            ];
            $productos[$clave]['descripcion'] = null;   // se deja el nombre que ya estaba
        }
    }

    // COALESCE con VALUES(): una columna que el archivo no traiga deja el valor que ya había. Sin
    // esto, subir una planilla con solo las unidades por caja borraría las descripciones.
    $porSku = $pdo->prepare(
        "INSERT INTO maestro_productos (sku, plu, ean, descripcion, unidades_por_caja, linea)
         VALUES (:sku, :plu, :ean, :descripcion, :uxc, :linea)
         ON DUPLICATE KEY UPDATE
             plu               = COALESCE(VALUES(plu), plu),
             ean               = COALESCE(VALUES(ean), ean),
             descripcion       = COALESCE(VALUES(descripcion), descripcion),
             unidades_por_caja = COALESCE(VALUES(unidades_por_caja), unidades_por_caja),
             linea             = COALESCE(VALUES(linea), linea)"
    );

    // Cuántos productos tienen ese PLU. Desde que el PLU dejó de ser único (2026-09-11) puede
    // haber más de uno, y entonces una fila que solo trae PLU no alcanza para saber a cuál se
    // refiere: escribirle a los dos les pondría las mismas unidades por caja a dos empaques
    // distintos, que es justo el error que este cambio vino a evitar.
    $cuantosConPlu = $pdo->prepare("SELECT COUNT(*) FROM maestro_productos WHERE plu = ?");

    $porPlu = $pdo->prepare(
        "UPDATE maestro_productos SET
             ean               = COALESCE(:ean, ean),
             descripcion       = COALESCE(:descripcion, descripcion),
             unidades_por_caja = COALESCE(:uxc, unidades_por_caja),
             linea             = COALESCE(:linea, linea)
         WHERE plu = :plu"
    );

    $pdo->beginTransaction();
    try {
        $creados = 0;
        $actualizados = 0;
        $sinCambios = 0;
        $noEncontrados = 0;
        $ambiguos = [];

        foreach ($productos as $prod) {
            if ($prod['sku'] !== null) {
                $porSku->execute([
                    ':sku'         => $prod['sku'],
                    ':plu'         => $prod['plu'],
                    ':ean'         => $prod['ean'],
                    ':descripcion' => $prod['descripcion'],
                    ':uxc'         => $prod['uxc'],
                    ':linea'       => $prod['linea'],
                ]);

                // MySQL devuelve 1 si insertó, 2 si actualizó y 0 si la fila ya decía lo mismo.
                // Distinguirlos es lo que permite avisar "el archivo no cambió nada" en vez de dar
                // por buena una importación que no hizo nada.
                $n = $porSku->rowCount();
                if ($n === 1) {
                    $creados++;
                } elseif ($n === 0) {
                    $sinCambios++;
                } else {
                    $actualizados++;
                }

            } else {
                $cuantosConPlu->execute([$prod['plu']]);
                $cuantos = (int) $cuantosConPlu->fetchColumn();

                if ($cuantos > 1) {
                    $ambiguos[] = $prod['plu'];
                    continue;
                }

                $porPlu->execute([
                    ':plu'         => $prod['plu'],
                    ':ean'         => $prod['ean'],
                    ':descripcion' => $prod['descripcion'],
                    ':uxc'         => $prod['uxc'],
                    ':linea'       => $prod['linea'],
                ]);

                // "No encontrado" sale de la cuenta de arriba y NO de rowCount(): MySQL devuelve 0
                // también cuando el producto existe y el archivo trae lo mismo que ya tenía, y eso
                // se avisaba como "ese producto no está en el maestro" (encontrado el 2026-09-14).
                if ($cuantos === 0) {
                    $noEncontrados++;
                } elseif ($porPlu->rowCount() > 0) {
                    $actualizados++;
                } else {
                    $sinCambios++;
                }
            }
        }

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error importando el maestro: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar el maestro en la base.', 'filas' => 0];
    }

    completarPluDelMaestro($pdo);

    // ---------------------------------------------------------------------------------------
    // El mensaje dice qué pasó DE VERDAD.
    //
    // Antes decía "se cargaron N productos" contando las filas leídas, aunque la base no hubiera
    // cambiado ni un dato. Con un archivo cuyas celdas eran fórmulas sin resolver, eso significaba
    // ver un cartel de éxito mientras el maestro seguía exactamente igual, y sin ninguna pista de
    // qué revisar.
    // ---------------------------------------------------------------------------------------
    if (!$creados && !$actualizados) {
        // Si lo único que traía de nuevo eran nombres que contradicen el empaque guardado, el
        // motivo es ESE y no "ya estaba todo igual": sin decirlo, quien sube el archivo lo vuelve
        // a subir pensando que no se leyó.
        $mensaje = $contradicen
            ? 'El archivo se leyó bien (' . count($productos) . ' producto(s)) y no se cambió nada porque '
              . count($contradicen) . ' de sus nombres dicen un empaque DISTINTO del que ya está cargado.'
            : 'El archivo se leyó bien (' . count($productos) . ' producto(s)) pero no cambió '
              . 'ningún dato: lo que trae ya es lo que estaba guardado.';

        foreach (array_slice($contradicen, 0, 4) as $c) {
            $mensaje .= " SKU {$c['sku']}: el archivo dice {$c['del_nombre']} por caja y el maestro"
                      . " tiene {$c['guardado']}.";
        }

        if ($noEncontrados > 0) {
            $mensaje .= " Además, {$noEncontrados} fila(s) traían solo PLU y ese producto no está"
                      . ' en el maestro.';
        }
        if ($ambiguos) {
            $mensaje .= ' ' . count($ambiguos) . ' fila(s) traían un PLU que pertenece a MÁS DE UN producto ('
                      . implode(', ', array_slice(array_unique($ambiguos), 0, 5))
                      . '): se saltearon. Agregales la columna SKU.';
        }
        return ['exito' => false, 'mensaje' => $mensaje, 'filas' => 0];
    }

    $partes = [];
    if ($creados > 0)      { $partes[] = "{$creados} producto(s) nuevo(s)"; }
    if ($actualizados > 0) { $partes[] = "{$actualizados} actualizado(s)"; }
    if ($sinCambios > 0)   { $partes[] = "{$sinCambios} ya estaba(n) igual"; }

    $mensaje = 'Maestro actualizado: ' . implode(', ', $partes);

    // Los nombres que decían otro empaque que el guardado: no se pisaron, y hay que decir cuáles
    // son y en qué se contradicen, o nadie va a saber qué revisar. Ver el comentario de arriba.
    if ($contradicen) {
        $detalle = [];
        foreach (array_slice($contradicen, 0, 4) as $c) {
            $detalle[] = "SKU {$c['sku']} (el nombre nuevo dice {$c['del_nombre']} por caja y el "
                       . "maestro tiene {$c['guardado']})";
        }

        $mensaje .= '. OJO: ' . count($contradicen) . ' nombre(s) del archivo dicen un empaque '
                  . 'DISTINTO del que ya está cargado, así que se dejaron como estaban: '
                  . implode('; ', $detalle) . (count($contradicen) > 4 ? '; …' : '')
                  . '. Revisá cuál de los dos está bien y corregí el archivo o el empaque';
    }
    if ($noEncontrados > 0) {
        $mensaje .= ". {$noEncontrados} fila(s) traían solo PLU y ese producto todavía no está en el"
                  . ' maestro: cargá primero el export de SAP, o agregales la columna SKU';
    }
    if ($ambiguos) {
        $mensaje .= '. ' . count($ambiguos) . ' fila(s) traían un PLU que pertenece a MÁS DE UN'
                  . ' producto (' . implode(', ', array_slice(array_unique($ambiguos), 0, 5))
                  . '): se saltearon porque no hay con qué saber a cuál de los dos empaques se'
                  . ' referían. Agregales la columna SKU';
    }

    return ['exito' => true, 'mensaje' => $mensaje . '.', 'filas' => $creados + $actualizados];
}
