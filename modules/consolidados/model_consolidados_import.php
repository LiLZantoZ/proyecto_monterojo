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

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;

// Lee la PRIMERA hoja del archivo sin importar cómo se llame. El Consolidado la trae como
// "Sheet1", pero ese nombre depende de con qué la exportaron y no se puede dar por seguro.
function leerPrimeraHoja($rutaArchivo) {
    $lector = IOFactory::createReaderForFile($rutaArchivo);
    $lector->setReadDataOnly(true);
    $libro = $lector->load($rutaArchivo);

    $filas = $libro->getSheet(0)->toArray(null, false, false, false);

    // Libera la memoria del libro antes de seguir: con archivos grandes, no hacerlo deja todo
    // el contenido retenido durante el resto de la importación.
    $libro->disconnectWorksheets();
    unset($libro);

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
        'ean del item'                   => 'ean_item',
        'ean punto de venta'             => 'ean_punto_venta',
        'nombre punto de venta'          => 'punto_venta',
        'direccion punto de venta'       => 'direccion_punto_venta',
        'cantidad pto vta'               => 'unidades',
        'cantidad total'                 => 'cantidad_total',
        'f. documento o/c'               => 'fecha_documento',
        'f. minima entrega'              => 'fecha_minima_entrega',
        'fecha maxima de entrega'        => 'fecha_maxima_entrega',
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

    $valor = function (array $fila, $campo) use ($mapa) {
        return isset($mapa[$campo]) ? ($fila[$mapa[$campo]] ?? null) : null;
    };

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
                (id_carga, cedi, orden_compra, tipo_orden, plu, ean_item, ean_punto_venta,
                 punto_venta, direccion_punto_venta, unidades, cantidad_total,
                 fecha_documento, fecha_minima_entrega, fecha_maxima_entrega)
             VALUES (:carga, :cedi, :oc, :tipo, :plu, :ean, :ean_pv, :pv, :dir, :unidades, :total,
                     :f_doc, :f_min, :f_max)"
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
                ':ean'      => textoLimpio($valor($fila, 'ean_item'), 20),
                ':ean_pv'   => textoLimpio($valor($fila, 'ean_punto_venta'), 20),
                ':pv'       => $pv,
                ':dir'      => textoLimpio($valor($fila, 'direccion_punto_venta'), 255),
                ':unidades' => (int) $valor($fila, 'unidades'),
                ':total'    => $totalesPorGrupo[$oc . '|' . $plu] ?? null,
                ':f_doc'    => fechaDesdeExcel($valor($fila, 'fecha_documento')),
                ':f_min'    => fechaDesdeExcel($valor($fila, 'fecha_minima_entrega')),
                ':f_max'    => fechaDesdeExcel($valor($fila, 'fecha_maxima_entrega')),
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

        return ['exito' => true, 'mensaje' => "Se importaron {$guardadas} líneas.", 'filas' => $guardadas, 'id_carga' => $idCarga];

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
        // El PLU tiene índice único: si dos EAN distintos apuntaran al mismo PLU, el UPDATE
        // fallaría entero. IGNORE deja pasar el resto en vez de perder toda la operación por un
        // dato inconsistente del archivo del cliente.
        $stmt = $pdo->prepare(
            "UPDATE IGNORE maestro_productos m
             JOIN (SELECT DISTINCT ean_item, plu FROM consolidado_lineas
                   WHERE ean_item IS NOT NULL AND ean_item <> '') c ON c.ean_item = m.ean
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

    $porPlu = $pdo->prepare(
        "UPDATE maestro_productos SET
             descripcion       = COALESCE(:descripcion, descripcion),
             unidades_por_caja = COALESCE(:uxc, unidades_por_caja),
             linea             = COALESCE(:linea, linea)
         WHERE plu = :plu"
    );

    $pdo->beginTransaction();
    try {
        $creados = 0;
        $actualizados = 0;
        $noEncontrados = 0;

        foreach ($filas as $fila) {
            $sku = textoLimpio($valor($fila, 'sku'), 30);
            $plu = textoLimpio($valor($fila, 'plu'), 30);

            if ($sku === null && $plu === null) {
                continue;
            }

            $uxc = $valor($fila, 'unidades_por_caja');
            $uxc = (is_numeric($uxc) && (int) $uxc > 0) ? (int) $uxc : null;

            $descripcion = textoLimpio($valor($fila, 'descripcion'), 255);
            $linea       = textoLimpio($valor($fila, 'linea'), 60);

            if ($sku !== null) {
                $porSku->execute([
                    ':sku'         => $sku,
                    ':plu'         => $plu,
                    ':ean'         => textoLimpio($valor($fila, 'ean'), 20),
                    ':descripcion' => $descripcion,
                    ':uxc'         => $uxc,
                    ':linea'       => $linea,
                ]);
                $creados++;
            } else {
                $porPlu->execute([
                    ':plu'         => $plu,
                    ':descripcion' => $descripcion,
                    ':uxc'         => $uxc,
                    ':linea'       => $linea,
                ]);
                if ($porPlu->rowCount() > 0) {
                    $actualizados++;
                } else {
                    $noEncontrados++;
                }
            }
        }

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error importando el maestro: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar el maestro en la base.', 'filas' => 0];
    }

    $total = $creados + $actualizados;
    if ($total === 0 && $noEncontrados === 0) {
        return ['exito' => false, 'mensaje' => 'El archivo no traía ningún producto.', 'filas' => 0];
    }

    completarPluDelMaestro($pdo);

    $mensaje = "Se cargaron {$total} productos al maestro";
    if ($noEncontrados > 0) {
        $mensaje .= ". {$noEncontrados} fila(s) traían solo PLU y ese producto todavía no está en el"
                  . " maestro: cargá primero el export de SAP, o agregales la columna SKU";
    }

    return ['exito' => true, 'mensaje' => $mensaje . '.', 'filas' => $total];
}
