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

// Deja un encabezado comparable: sin tildes, en minúsculas y con los espacios colapsados.
function normalizarEncabezado($texto) {
    $texto = mb_strtolower(trim((string) $texto), 'UTF-8');
    $texto = strtr($texto, ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n', 'ü'=>'u']);
    return preg_replace('/\s+/', ' ', $texto);
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
 * Importa el archivo Consolidado. Reemplaza por completo lo que había: el archivo del día es la
 * foto entera del pedido, no un agregado, así que mezclarlo con el anterior dejaría alistando
 * pedidos que ya salieron.
 *
 * Devuelve ['exito' => bool, 'mensaje' => string, 'filas' => int, 'id_carga' => int|null].
 */
function importarConsolidado($pdo, $rutaArchivo, $nombreArchivo, $idUsuario) {
    $filas = leerPrimeraHoja($rutaArchivo);

    if (count($filas) < 2) {
        return ['exito' => false, 'mensaje' => 'El archivo no tiene filas de datos.', 'filas' => 0, 'id_carga' => null];
    }

    $mapa = mapearColumnas(array_shift($filas), columnasConsolidado());

    // Sin estas cuatro no hay nada que guardar. Se avisa cuál falta en vez de un "formato
    // incorrecto" genérico, que obliga a adivinar qué tiene de malo el archivo.
    $obligatorias = ['cedi', 'orden_compra', 'plu', 'punto_venta', 'unidades'];
    $faltan = array_diff($obligatorias, array_keys($mapa));
    if ($faltan) {
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
        // Se borran las cargas anteriores; consolidado_lineas cae con ellas por la clave foránea
        // ON DELETE CASCADE, así que no hay que acordarse de borrarla aparte.
        $pdo->exec("DELETE FROM consolidado_cargas");

        $insertarCarga = $pdo->prepare(
            "INSERT INTO consolidado_cargas (nombre_archivo, filas, id_usuario) VALUES (?, 0, ?)"
        );
        $insertarCarga->execute([mb_substr($nombreArchivo, 0, 255), $idUsuario]);
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

    $mapa = mapearColumnas(array_shift($filas), columnasSap());

    if (!isset($mapa['sku'])) {
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
