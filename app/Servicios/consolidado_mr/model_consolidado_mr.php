<?php
// modules/consolidado_mr/model_consolidado_mr.php
// El "Consolidado MR": el export de facturación de SAP renglón por renglón —una fila por producto
// facturado—, para consultarlo en el sistema en vez de abrir un Excel de 19.000 filas.
//
// POR QUÉ ES UN MÓDULO APARTE (2026-09-24)
// Estado de pedidos ya lee el export de SAP, pero lo RESUME: una fila por factura con el Valor
// neto sumado. Acá se quiere lo contrario, el detalle: qué material, cuántas unidades y por cuánto
// en cada factura. Son dos lecturas distintas del mismo tipo de archivo, y mezclarlas en una
// pantalla obligaba a elegir entre ver facturas o ver productos.
//
// QUÉ SE GUARDA
// Las 11 columnas resaltadas del Excel (Fecha factura, Solic., Nombre 1, Población, Material, Texto
// breve de material, Ctd.facturada, Valor neto, Referencia, Doc.ventas, Pedido Cliente) más la
// Factura, que no se muestra pero es la llave para reemplazar una factura al volver a subirla.
//
// QUÉ SE DESCARTA AL IMPORTAR
//  · Los renglones en CERO (Ctd.facturada 0 y Valor neto 0). SAP parte cada posición por lote y
//    deja el renglón "padre" vacío: en el archivo del 2026-09-24, 9.337 de las 18.934 filas eran
//    eso, siempre con su gemelo con la cantidad real al lado. Mostrarlos duplicaba cada producto
//    con una fila en blanco.
//  · Las facturas ANULADAS (columna "An." con X), igual que en Estado de pedidos.

require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/../consolidados/model_consolidados_import.php';   // mapearColumnas(), textoLimpio(), codigoLimpio(), fechaDesdeExcel()

const CONSOLIDADO_MR_POR_PAGINA = 50;

/**
 * Filtro de lectura para PhpSpreadsheet: solo ciertas columnas (por letra) y/o hasta cierta fila.
 * Cargar solo las 13 columnas que se usan —de 56— es lo que hace que el archivo entre en memoria.
 */
class FiltroColumnasConsolidadoMr implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
    private array $cols;
    private int $filaMax;
    public function __construct(array $cols = [], int $filaMax = 0) {
        $this->cols = $cols;
        $this->filaMax = $filaMax;
    }
    public function readCell($col, $row, $ws = ''): bool {
        if ($this->filaMax > 0 && (int) $row > $this->filaMax) { return false; }
        if ($this->cols && !in_array($col, $this->cols, true)) { return false; }
        return true;
    }
}

/** Encabezado del archivo (normalizado) => campo interno. */
function columnasConsolidadoMr() {
    return [
        'factura'                 => 'factura',
        'fecha factura'           => 'fecha_factura',
        'solic.'                  => 'solicitante',
        'solicitante'             => 'solicitante',
        'nombre 1'                => 'nombre_cliente',
        'poblacion'               => 'poblacion',
        'material'                => 'material',
        'texto breve de material' => 'texto_material',
        'ctd.facturada'           => 'cantidad_facturada',
        'valor neto'              => 'valor_neto',
        'referencia'              => 'referencia',
        'doc.ventas'              => 'doc_ventas',
        'pedido cliente'          => 'pedido_cliente',
        'an.'                     => 'an',
    ];
}

/** Un número (con signo) del Excel, o null. Las notas crédito pueden traer negativos. */
function numeroConsolidadoMr($valor, $decimales) {
    if ($valor === null || $valor === '') { return null; }
    if (is_int($valor) || is_float($valor)) { return round((float) $valor, $decimales); }
    $texto = trim((string) $valor);
    $negativo = strpos($texto, '-') !== false;
    $n = numeroDesdeExcel(str_replace('-', '', $texto), $decimales);
    return $n === null ? null : ($negativo ? -$n : $n);
}

/**
 * Importa el Excel "CONSOLIDADO MR". Devuelve ['exito'=>bool, 'mensaje'=>string].
 *
 * Volver a subir un archivo REEMPLAZA las facturas que trae (se borran sus renglones y se cargan
 * los nuevos) y deja intactas las demás: así se pueden subir exports de semanas distintas, y uno
 * que se solape con otro no duplica nada.
 */
function importarConsolidadoMr($pdo, $rutaArchivo, $idUsuario) {
    @set_time_limit(300);
    $cur = trim((string) ini_get('memory_limit'));
    if ($cur !== '-1' && (int) $cur > 0 && (int) $cur < 1024 && strtoupper(substr($cur, -1)) !== 'G') {
        @ini_set('memory_limit', '1024M');
    }

    try {
        $lector = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($rutaArchivo);
        $lector->setReadDataOnly(true);
        $nombres = $lector->listWorksheetNames($rutaArchivo);
        if ($nombres) { $lector->setLoadSheetsOnly($nombres[0]); }

        // 1) Solo el encabezado, para ubicar las columnas por NOMBRE.
        $lector->setReadFilter(new FiltroColumnasConsolidadoMr([], 1));
        $libro = $lector->load($rutaArchivo);
        $encabezado = $libro->getSheet(0)->toArray(null, false, false, false)[0] ?? [];
        $libro->disconnectWorksheets();
        unset($libro);
    } catch (Throwable $e) {
        error_log('Error leyendo Consolidado MR: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo leer el archivo. Revisá que sea el Excel del Consolidado MR.'];
    }

    // En "Ctd.facturada" y "Valor neto" gana la PRIMERA columna homónima (el número; la segunda es
    // la unidad "PZA"/"COP"): mapearColumnas ya prefiere la primera.
    $mapa = mapearColumnas($encabezado, columnasConsolidadoMr());
    $faltan = array_diff(['factura', 'material', 'cantidad_facturada', 'valor_neto'], array_keys($mapa));
    if ($faltan) {
        $nombresCol = ['factura' => 'Factura', 'material' => 'Material',
                       'cantidad_facturada' => 'Ctd.facturada', 'valor_neto' => 'Valor neto'];
        return ['exito' => false, 'mensaje' => 'Este archivo no parece el Consolidado MR: '
            . (count($faltan) === 1 ? 'le falta la columna ' : 'le faltan las columnas ')
            . implode(', ', array_map(fn($c) => '"' . $nombresCol[$c] . '"', $faltan)) . '.'];
    }

    $col = [];
    foreach ($mapa as $campo => $idx) {
        $col[$campo] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    }

    // 2) Todas las filas, pero solo las columnas mapeadas.
    try {
        $lector->setReadFilter(new FiltroColumnasConsolidadoMr(array_values($col)));
        $libro = $lector->load($rutaArchivo);
    } catch (Throwable $e) {
        error_log('Error leyendo Consolidado MR: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo leer el archivo completo. Probá de nuevo.'];
    }
    $hoja    = $libro->getSheet(0);
    $maxFila = $hoja->getHighestRow();
    $leer    = fn($campo, $r) => isset($col[$campo]) ? $hoja->getCell($col[$campo] . $r)->getValue() : null;

    $lineas = []; $facturas = []; $anuladas = []; $enCero = 0;
    for ($r = 2; $r <= $maxFila; $r++) {
        $factura = codigoLimpio($leer('factura', $r), 30);
        if ($factura === null) { continue; }

        if (strtoupper(trim((string) $leer('an', $r))) === 'X') {
            $anuladas[$factura] = true;
            continue;
        }

        $cantidad = numeroConsolidadoMr($leer('cantidad_facturada', $r), 3);
        $valor    = numeroConsolidadoMr($leer('valor_neto', $r), 2);
        if ((float) $cantidad == 0 && (float) $valor == 0) {   // renglón "padre" del lote: vacío
            $enCero++;
            continue;
        }

        $facturas[$factura] = true;
        $lineas[] = [
            $factura,
            fechaDesdeExcel($leer('fecha_factura', $r)),
            codigoLimpio($leer('solicitante', $r), 30),
            textoLimpio($leer('nombre_cliente', $r), 160),
            textoLimpio($leer('poblacion', $r), 80),
            textoLimpio($leer('material', $r), 30),
            textoLimpio($leer('texto_material', $r), 255),
            $cantidad,
            $valor,
            textoLimpio($leer('referencia', $r), 60),
            codigoLimpio($leer('doc_ventas', $r), 30),
            textoLimpio($leer('pedido_cliente', $r), 80),
        ];
    }
    $libro->disconnectWorksheets();
    unset($libro, $hoja);

    // Una factura anulada no se carga aunque tenga algún renglón sin X.
    if ($anuladas) {
        $lineas   = array_values(array_filter($lineas, fn($l) => !isset($anuladas[$l[0]])));
        $facturas = array_diff_key($facturas, $anuladas);
    }
    if (!$lineas && !$anuladas) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae ningún renglón facturado.'];
    }

    $pdo->beginTransaction();
    try {
        // Borrar lo que ya había de esas facturas (y de las que ahora vienen anuladas), por tandas.
        $borradas = 0;
        foreach (array_chunk(array_keys($facturas + $anuladas), 500) as $tanda) {
            $marcas = implode(',', array_fill(0, count($tanda), '?'));
            $stmt = $pdo->prepare("DELETE FROM consolidado_mr WHERE factura IN ($marcas)");
            $stmt->execute(array_map('strval', $tanda));
            $borradas += $stmt->rowCount();
        }

        // Insertar en tandas de 500 filas: un INSERT por fila con ~10.000 renglones tarda demasiado.
        $campos = '(factura, fecha_factura, solicitante, nombre_cliente, poblacion, material, texto_material,
                    cantidad_facturada, valor_neto, referencia, doc_ventas, pedido_cliente, id_usuario)';
        foreach (array_chunk($lineas, 500) as $tanda) {
            $fila   = '(' . implode(',', array_fill(0, 13, '?')) . ')';
            $sql    = "INSERT INTO consolidado_mr {$campos} VALUES " . implode(',', array_fill(0, count($tanda), $fila));
            $params = [];
            foreach ($tanda as $l) {
                $l[0] = (string) $l[0];
                array_push($params, ...$l);
                $params[] = $idUsuario;
            }
            $pdo->prepare($sql)->execute($params);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error guardando Consolidado MR: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error guardando el archivo. No se cambió nada.'];
    }

    $mil = fn($n) => number_format($n, 0, ',', '.');
    $msg = 'Consolidado MR cargado: ' . $mil(count($lineas)) . ' renglón(es) de ' . $mil(count($facturas)) . ' factura(s).';
    if ($borradas) { $msg .= ' Se reemplazaron ' . $mil($borradas) . ' renglón(es) de facturas que ya estaban cargadas.'; }
    if ($enCero)   { $msg .= ' Se omitieron ' . $mil($enCero) . ' renglón(es) en cero (partición de lote de SAP).'; }
    if ($anuladas) { $msg .= ' Se omitieron ' . $mil(count($anuladas)) . ' factura(s) anulada(s) (An. = X).'; }

    return ['exito' => true, 'mensaje' => $msg];
}

/**
 * WHERE y parámetros a partir de los filtros. En un solo lugar porque lo usan la página, el conteo
 * y los totales, que tienen que filtrar exactamente igual.
 *
 * Filtros: 'cliente' (exacto, Nombre 1), 'poblacion' (exacto), 'desde'/'hasta' (fecha de factura),
 * 'buscar' (varios términos separados por coma; con que uno coincida alcanza).
 */
function filtroConsolidadoMr(array $filtros) {
    $where = []; $params = [];

    if (!empty($filtros['cliente'])) {
        $where[] = 'nombre_cliente = :cliente';
        $params[':cliente'] = $filtros['cliente'];
    }
    if (!empty($filtros['poblacion'])) {
        $where[] = 'poblacion = :poblacion';
        $params[':poblacion'] = $filtros['poblacion'];
    }
    if (!empty($filtros['desde'])) {
        $where[] = 'fecha_factura >= :desde';
        $params[':desde'] = $filtros['desde'];
    }
    if (!empty($filtros['hasta'])) {
        $where[] = 'fecha_factura <= :hasta';
        $params[':hasta'] = $filtros['hasta'];
    }
    if (!empty($filtros['buscar'])) {
        $terminos = array_filter(array_map('trim', explode(',', $filtros['buscar'])), fn($t) => $t !== '');
        $ors = []; $i = 0;
        foreach ($terminos as $t) {
            $campos = [];
            // Un placeholder distinto por columna (EMULATE_PREPARES en false no deja repetirlos).
            foreach (['factura', 'solicitante', 'nombre_cliente', 'poblacion', 'material', 'texto_material',
                      'referencia', 'doc_ventas', 'pedido_cliente'] as $c) {
                $p = ":busca{$i}";
                $campos[] = "{$c} LIKE {$p}";
                $params[$p] = '%' . $t . '%';
                $i++;
            }
            $ors[] = '(' . implode(' OR ', $campos) . ')';
        }
        if ($ors) { $where[] = '(' . implode(' OR ', $ors) . ')'; }
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

/** Una página de renglones, los más recientes primero y agrupados por factura. */
function lineasConsolidadoMr($pdo, array $filtros = [], $pagina = 1) {
    [$whereSql, $params] = filtroConsolidadoMr($filtros);
    $offset = (max(1, (int) $pagina) - 1) * CONSOLIDADO_MR_POR_PAGINA;
    $stmt = $pdo->prepare(
        "SELECT * FROM consolidado_mr{$whereSql}
          ORDER BY fecha_factura DESC, factura DESC, id_linea
          LIMIT " . (int) CONSOLIDADO_MR_POR_PAGINA . " OFFSET " . (int) $offset
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * La factura del renglón que está en la posición $offset (0 = el primero) con el mismo filtro y el
 * mismo orden que la tabla, o null si no hay renglón ahí. Sirve para saber si la última factura de
 * una página sigue en la página siguiente (y entonces su subtotal va allá, no acá).
 */
function facturaEnPosicionConsolidadoMr($pdo, array $filtros, $offset) {
    if ($offset < 0) { return null; }
    [$whereSql, $params] = filtroConsolidadoMr($filtros);
    $stmt = $pdo->prepare(
        "SELECT factura FROM consolidado_mr{$whereSql}
          ORDER BY fecha_factura DESC, factura DESC, id_linea
          LIMIT 1 OFFSET " . (int) $offset
    );
    $stmt->execute($params);
    $f = $stmt->fetchColumn();
    return $f === false ? null : (string) $f;
}

/**
 * Subtotales por factura de las facturas indicadas: [factura => [...]].
 *
 * Se calculan sobre TODOS los renglones de la factura, no solo los de la página: una factura larga
 * puede quedar partida entre dos páginas y su subtotal tiene que ser el de la factura entera.
 *  · lineas / cantidad / valor           → los renglones que cumplen el filtro (lo que se ve)
 *  · lineas_total / valor_total          → la factura completa, sin filtro
 * Sin filtro son iguales; con un filtro (p. ej. buscar un material) la vista muestra los dos, para
 * que no se confunda "lo filtrado" con "lo que vale la factura".
 */
function subtotalesPorFacturaConsolidadoMr($pdo, array $filtros, array $facturas) {
    $facturas = array_values(array_unique(array_map('strval', $facturas)));
    if (!$facturas) { return []; }

    // Marcadores con nombre (no "?"): el filtro ya usa nombres y PDO no deja mezclar los dos tipos.
    $marcas = []; $paramsIn = [];
    foreach ($facturas as $i => $f) { $marcas[] = ":fac{$i}"; $paramsIn[":fac{$i}"] = $f; }
    $in = 'factura IN (' . implode(',', $marcas) . ')';

    $salida = [];
    $stmt = $pdo->prepare("SELECT factura, COUNT(*) AS lineas, SUM(valor_neto) AS valor
                             FROM consolidado_mr WHERE {$in} GROUP BY factura");
    $stmt->execute($paramsIn);
    foreach ($stmt->fetchAll() as $r) {
        $salida[(string) $r['factura']] = [
            'lineas' => 0, 'cantidad' => 0.0, 'valor' => 0.0,
            'lineas_total' => (int) $r['lineas'], 'valor_total' => (float) $r['valor'],
        ];
    }

    [$whereSql, $params] = filtroConsolidadoMr($filtros);
    $whereSql = $whereSql === '' ? " WHERE {$in}" : "{$whereSql} AND {$in}";
    $stmt = $pdo->prepare("SELECT factura, COUNT(*) AS lineas, SUM(cantidad_facturada) AS cantidad, SUM(valor_neto) AS valor
                             FROM consolidado_mr{$whereSql} GROUP BY factura");
    $stmt->execute($params + $paramsIn);
    foreach ($stmt->fetchAll() as $r) {
        $f = (string) $r['factura'];
        if (!isset($salida[$f])) { continue; }
        $salida[$f]['lineas']   = (int) $r['lineas'];
        $salida[$f]['cantidad'] = (float) $r['cantidad'];
        $salida[$f]['valor']    = (float) $r['valor'];
    }
    return $salida;
}

/** Totales de TODO el filtro: renglones, facturas, clientes, unidades y valor neto. */
function totalesConsolidadoMr($pdo, array $filtros = []) {
    [$whereSql, $params] = filtroConsolidadoMr($filtros);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS lineas, COUNT(DISTINCT factura) AS facturas,
                COUNT(DISTINCT nombre_cliente) AS clientes,
                COALESCE(SUM(cantidad_facturada), 0) AS cantidad, COALESCE(SUM(valor_neto), 0) AS valor
           FROM consolidado_mr{$whereSql}"
    );
    $stmt->execute($params);
    $t = $stmt->fetch();
    return [
        'lineas'   => (int) $t['lineas'],
        'facturas' => (int) $t['facturas'],
        'clientes' => (int) $t['clientes'],
        'cantidad' => (float) $t['cantidad'],
        'valor'    => (float) $t['valor'],
    ];
}

/** Valores distintos de una columna, para los desplegables del filtro. */
function valoresConsolidadoMr($pdo, $columna) {
    if (!in_array($columna, ['nombre_cliente', 'poblacion'], true)) { return []; }
    return $pdo->query(
        "SELECT DISTINCT {$columna} FROM consolidado_mr
          WHERE {$columna} IS NOT NULL AND {$columna} <> '' ORDER BY {$columna}"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** Vacía el módulo entero. Devuelve cuántos renglones borró. */
function vaciarConsolidadoMr($pdo) {
    return $pdo->exec("DELETE FROM consolidado_mr");
}
