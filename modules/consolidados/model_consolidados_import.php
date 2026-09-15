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
function leerPrimeraHoja($rutaArchivo) {
    $lector = IOFactory::createReaderForFile($rutaArchivo);
    $lector->setReadDataOnly(true);
    $libro = $lector->load($rutaArchivo);
    $hoja  = $libro->getSheet(0);

    $filas = resolverFormulas($hoja, $hoja->toArray(null, false, false, false));

    // Libera la memoria del libro antes de seguir: con archivos grandes, no hacerlo deja todo
    // el contenido retenido durante el resto de la importación.
    $libro->disconnectWorksheets();
    unset($libro);

    return $filas;
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
function mapaDeConsolidado(array $encabezado) {
    $obligatorias = columnasObligatoriasConsolidado();

    $mapa = mapearColumnas($encabezado, columnasConsolidado());
    if (array_diff($obligatorias, array_keys($mapa))) {
        $mapaSap = mapearColumnas($encabezado, columnasConsolidadoSap());
        if (!array_diff($obligatorias, array_keys($mapaSap))) {
            // En SAP la columna 'Material' ES el SKU, y acá se la usa además como PLU
            // porque es lo que el resto del sistema espera encontrar. Se apunta la MISMA
            // columna a los dos campos: mapearColumnas() no puede hacerlo solo, porque
            // asigna cada encabezado a un único campo.
            $mapaSap['sku_item'] = $mapaSap['plu'];
            return $mapaSap;
        }
    }

    return $mapa;
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
    $mapa = mapaDeConsolidado(array_shift($filas));
    if (array_diff(columnasObligatoriasConsolidado(), array_keys($mapa))) {
        return null;
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
    $mapa = mapaDeConsolidado($encabezado);

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

    if ($columnaVacia('descripcion_item') && !$columnaVacia('denominacion')) {
        $mapa['descripcion_item'] = $mapa['denominacion'];
        $ajustes[] = 'la descripción se tomó de "DENOMINACIÓN"';
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

        return ['exito' => true, 'mensaje' => $mensaje, 'filas' => $guardadas, 'id_carga' => $idCarga];

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
        if (textoLimpio($valor($fila, 'sku'), 30) === null && textoLimpio($valor($fila, 'plu'), 30) === null) {
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
        $sku = textoLimpio($valor($fila, 'sku'), 30);
        $plu = textoLimpio($valor($fila, 'plu'), 30);

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
        $mensaje = 'El archivo se leyó bien (' . count($productos) . ' producto(s)) pero no cambió '
                 . 'ningún dato: lo que trae ya es lo que estaba guardado.';
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
