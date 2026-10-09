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
//  (La X de la columna "Anulado."/"An." marca las facturas ANULADAS —lo aclaró el usuario el
//  2026-10-05—. Se cargan, con la X guardada en cada renglón, y se ven solo en la pestaña "Facturas
//  anuladas"; ver FACTURAS ANULADAS.)

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados_import.php';   // mapearColumnas(), textoLimpio(), codigoLimpio(), fechaDesdeExcel()
require_once __DIR__ . '/../seguimiento/model_seguimiento.php';           // grupoDeEstado(), importarPedidosExcel(), encabezadoDeExcel()

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
        // La X de "Anulado." ("An." en los export viejos) marca las facturas ANULADAS (2026-10-05).
        'an.'                     => 'an',
        'anulado.'                => 'an',
        'anulado'                 => 'an',
        // La condición de pago (2026-10-06): 0010 = DE CONTADO. Ver FACTURAS DE CONTADO.
        'cpag'                    => 'cpag',
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

    // 1) Solo el encabezado, para ubicar las columnas por NOMBRE. Con el lector rápido (2026-10-05)
    // si se puede; si no (un .xls, por ejemplo), con PhpSpreadsheet como antes.
    $encabezado = encabezadoDeExcel($rutaArchivo);
    try {
        $lector = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($rutaArchivo);
        $lector->setReadDataOnly(true);
        $nombres = $lector->listWorksheetNames($rutaArchivo);
        if ($nombres) { $lector->setLoadSheetsOnly($nombres[0]); }
        if (!$encabezado) {
            $lector->setReadFilter(new FiltroColumnasConsolidadoMr([], 1));
            $libro = $lector->load($rutaArchivo);
            $encabezado = $libro->getSheet(0)->toArray(null, false, false, false)[0] ?? [];
            $libro->disconnectWorksheets();
            unset($libro);
        }
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

    // 2) Todas las filas, pero solo las columnas mapeadas: [fila => [índice de columna => valor]].
    // Con el lector rápido, ~5 s en vez de ~60 s con el archivo de 11 MB (medido el 2026-10-05).
    $filas = leerXlsxRapido($rutaArchivo, array_values($mapa));
    if ($filas === null) {
        try {
            $lector->setReadFilter(new FiltroColumnasConsolidadoMr(array_values($col)));
            $libro = $lector->load($rutaArchivo);
        } catch (Throwable $e) {
            error_log('Error leyendo Consolidado MR: ' . $e->getMessage());
            return ['exito' => false, 'mensaje' => 'No se pudo leer el archivo completo. Probá de nuevo.'];
        }
        $hoja  = $libro->getSheet(0);
        $filas = [];
        for ($r = 2, $maxFila = $hoja->getHighestRow(); $r <= $maxFila; $r++) {
            foreach ($mapa as $idx) {
                $filas[$r][$idx] = $hoja->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1) . $r)->getValue();
            }
        }
        $libro->disconnectWorksheets();
        unset($libro, $hoja);
    }
    $fila = [];
    // Por referencia: $fila es la del foreach de abajo (una fn => se quedaría con la vacía).
    $leer = function ($campo, $r) use (&$fila, $mapa) {
        return isset($mapa[$campo]) ? ($fila[$mapa[$campo]] ?? null) : null;
    };

    $lineas = []; $facturas = []; $anuladas = []; $cpag = []; $enCero = 0;
    foreach ($filas as $r => $fila) {
        if ($r < 2) { continue; }   // la fila 1 es el encabezado
        $factura = codigoLimpio($leer('factura', $r), 30);
        if ($factura === null) { continue; }

        // ANULADA (2026-10-05): la X de "Anulado.". Se carga igual, con la X en el renglón.
        $conX = strtoupper(trim((string) $leer('an', $r))) === 'X';

        $cantidad = numeroConsolidadoMr($leer('cantidad_facturada', $r), 3);
        $valor    = numeroConsolidadoMr($leer('valor_neto', $r), 2);
        if ((float) $cantidad == 0 && (float) $valor == 0) {   // renglón "padre" del lote: vacío
            $enCero++;
            continue;
        }

        $facturas[$factura] = true;
        // CPAG (2026-10-06): la condición de pago de la factura (todos sus renglones traen la misma).
        if (($cp = condicionPagoMr($leer('cpag', $r))) !== null) {
            $cpag[$factura] = $cp;
        }
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
            $conX ? 1 : 0,
        ];
        if ($conX) {
            $anuladas[$factura] = true;
        }
    }
    unset($filas, $fila);

    // Solo las facturas que quedan cargadas (una con todos sus renglones en cero no entra).
    $anuladas = array_intersect_key($anuladas, $facturas);
    if (!$lineas) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae ningún renglón facturado.'];
    }

    // LO QUE YA ESTÁ IGUAL NO SE TOCA (2026-10-05). Cada export de SAP repite las semanas anteriores,
    // y borrar y volver a insertar miles de renglones idénticos era lo más lento de la carga (~3 s de
    // 5 con el archivo de 11 MB). Se compara cada factura con lo guardado —renglón por renglón, sin
    // importar el orden— y solo se reemplazan las que cambiaron o son nuevas.
    $firma = fn(array $l) => implode('|', [
        (string) $l[0], (string) $l[1], (string) $l[2], (string) $l[3], (string) $l[4], (string) $l[5], (string) $l[6],
        $l[7] === null ? '' : number_format((float) $l[7], 3, '.', ''),
        $l[8] === null ? '' : number_format((float) $l[8], 2, '.', ''),
        (string) $l[9], (string) $l[10], (string) $l[11], (int) $l[12],
    ]);
    $nuevas = [];
    foreach ($lineas as $l) {
        $nuevas[(string) $l[0]][] = $firma($l);
    }
    $guardadas = [];
    foreach (array_chunk(array_map('strval', array_keys($facturas)), 1000) as $tanda) {
        $stmt = $pdo->prepare("SELECT factura, fecha_factura, solicitante, nombre_cliente, poblacion, material, texto_material,
                                      cantidad_facturada, valor_neto, referencia, doc_ventas, pedido_cliente, anulado
                                 FROM consolidado_mr WHERE factura IN (" . implode(',', array_fill(0, count($tanda), '?')) . ")");
        $stmt->execute($tanda);
        while ($g = $stmt->fetch(PDO::FETCH_NUM)) {
            $guardadas[(string) $g[0]][] = $firma($g);
        }
    }
    $iguales = [];
    foreach ($nuevas as $f => $firmas) {
        if (isset($guardadas[$f]) && count($guardadas[$f]) === count($firmas)) {
            $antes = $guardadas[$f];
            sort($antes);
            sort($firmas);
            if ($antes === $firmas) {
                $iguales[$f] = true;
            }
        }
    }
    unset($nuevas, $guardadas);
    $aCargar = $iguales ? array_values(array_filter($lineas, fn($l) => !isset($iguales[(string) $l[0]]))) : $lineas;
    $aBorrar = array_diff_key($facturas, $iguales);

    $pdo->beginTransaction();
    try {
        // Borrar lo que ya había de esas facturas, por tandas.
        $borradas = 0;
        foreach (array_chunk(array_keys($aBorrar), 500) as $tanda) {
            $marcas = implode(',', array_fill(0, count($tanda), '?'));
            $stmt = $pdo->prepare("DELETE FROM consolidado_mr WHERE factura IN ($marcas)");
            $stmt->execute(array_map('strval', $tanda));
            $borradas += $stmt->rowCount();
        }

        // Insertar en tandas de 500 filas: un INSERT por fila con ~10.000 renglones tarda demasiado.
        $campos = '(factura, fecha_factura, solicitante, nombre_cliente, poblacion, material, texto_material,
                    cantidad_facturada, valor_neto, referencia, doc_ventas, pedido_cliente, anulado, id_usuario)';
        foreach (array_chunk($aCargar, 500) as $tanda) {
            $fila   = '(' . implode(',', array_fill(0, 14, '?')) . ')';
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
    if ($iguales)  { $msg .= ' ' . $mil(count($iguales)) . ' factura(s) ya estaban cargadas igual: no se tocaron.'; }
    if ($borradas) { $msg .= ' Se reemplazaron ' . $mil($borradas) . ' renglón(es) de facturas que cambiaron.'; }
    if ($enCero)   { $msg .= ' Se omitieron ' . $mil($enCero) . ' renglón(es) en cero (partición de lote de SAP).'; }
    if ($anuladas) { $msg .= ' ' . $mil(count($anuladas)) . ' factura(s) anulada(s) (X en "Anulado."): se ven solo en la pestaña Facturas anuladas.'; }
    // DE CONTADO (2026-10-06): las de CPag 0010, en la misma carga. Ver contadoDesdeCpagMr().
    if (isset($mapa['cpag'])) {
        $c = contadoDesdeCpagMr($pdo, array_intersect_key($cpag, $facturas), $idUsuario);
        $msg .= ' De contado (CPag ' . MR_CPAG_CONTADO . '): ' . $mil($c['de_contado']) . ' factura(s)'
              . ($c['nuevas'] ? ', ' . $mil($c['nuevas']) . ' marcada(s) ahora' : '')
              . ($c['a_no'] ? '; ' . $mil($c['a_no']) . ' dejaron de serlo y pasaron a "No"' : '')
              . ($c['a_mano'] ? '; ' . $mil($c['a_mano']) . ' marcada(s) a mano se dejaron como estaban' : '') . '.';
    } else {
        $msg .= ' OJO: el archivo no trae la columna "CPag", así que no se marcaron las facturas de contado.';
    }

    return ['exito' => true, 'mensaje' => $msg, 'facturas' => array_map('strval', array_keys($facturas))];
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

// =================================================================================================
// LA TABLA POR FACTURA, LA GUÍA Y EL TRANSPORTADOR (2026-10-02)
//
// Pedido del usuario: la tabla deja de mostrar el detalle por producto (SKU, texto del material y
// cantidad) y pasa a UNA FILA POR FACTURA, con la Referencia —el número de factura que se usa,
// "NU04169821"— adelante y llamada "Factura", y al lado el Pedido y la Orden de compra.
//
// Para cada factura se busca su guía en el consolidado de las transportadoras (el módulo Estado de
// pedidos, sin su pestaña de facturación de SAP). Si no tiene guía, se le puede poner el transportador
// a mano (consolidado_mr_envios): casi siempre sale con transporte INTERNO.
//
// OJO (medido el 2026-10-02): lo que hoy hay cargado en Estado de pedidos es el reporte de Vector
// Foods, cuyas facturas son de Vector Foods ("S-293014") y no las de este archivo ("NU04169821"):
// no cruzó ninguna, ni por número ni por orden de compra. La búsqueda queda hecha para cuando se
// suban reportes de transportadoras que traigan este número de factura.
// =================================================================================================

/**
 * El número de una factura de forma comparable: sin espacios ni signos, en mayúsculas y sin los
 * ceros de relleno ("NU 04169821" y "nu4169821" son la misma; "S-293014" queda "S293014").
 */
function claveFacturaMr($valor) {
    $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $valor));
    if ($t === '') {
        return '';
    }
    return preg_match('/^([A-Z]*)0*(\d+)$/', $t, $m) ? $m[1] . $m[2] : $t;
}

/**
 * El filtro de la tabla aplicado a FACTURAS: una factura entra si alguno de sus renglones cumple el
 * filtro (buscar un SKU trae las facturas que lo tienen), y entra ENTERA, con su valor completo.
 */
function filtroFacturasMr(array $filtros) {
    [$whereSql, $params] = filtroConsolidadoMr($filtros);
    $sub = $whereSql === '' ? '' : " WHERE factura IN (SELECT factura FROM consolidado_mr{$whereSql})";
    return [$sub, $params];
}

/** Una página de facturas (o todas, con $todas), las más recientes primero. */
function facturasConsolidadoMr($pdo, array $filtros = [], $pagina = 1, $todas = false) {
    [$sub, $params] = filtroFacturasMr($filtros);
    $limite = $todas ? '' : ' LIMIT ' . (int) CONSOLIDADO_MR_POR_PAGINA
                          . ' OFFSET ' . (int) ((max(1, (int) $pagina) - 1) * CONSOLIDADO_MR_POR_PAGINA);
    $stmt = $pdo->prepare(
        "SELECT factura, MAX(referencia) AS referencia, MAX(doc_ventas) AS doc_ventas,
                MAX(pedido_cliente) AS pedido_cliente, MAX(fecha_factura) AS fecha_factura,
                MAX(solicitante) AS solicitante, MAX(nombre_cliente) AS nombre_cliente,
                MAX(poblacion) AS poblacion, SUM(valor_neto) AS valor_neto, MAX(anulado) AS anulada
           FROM consolidado_mr{$sub}
          GROUP BY factura
          ORDER BY MAX(fecha_factura) DESC, factura DESC{$limite}"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Totales de las facturas del filtro: cuántas, de cuántos clientes y su valor neto completo. */
function totalesFacturasMr($pdo, array $filtros = []) {
    [$sub, $params] = filtroFacturasMr($filtros);
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT factura) AS facturas, COUNT(DISTINCT nombre_cliente) AS clientes,
                COALESCE(SUM(valor_neto), 0) AS valor
           FROM consolidado_mr{$sub}"
    );
    $stmt->execute($params);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    return ['facturas' => (int) $t['facturas'], 'clientes' => (int) $t['clientes'], 'valor' => (float) $t['valor']];
}

/**
 * Las facturas del consolidado de las transportadoras: [clave de la factura => guía, transportadora
 * y estado]. Sale de Estado de pedidos sin la pestaña de facturación de SAP, que es este mismo
 * archivo y no trae guías. Si una factura aparece varias veces, gana la fila que tiene guía.
 */
function guiasDeTransportadorasMr($pdo) {
    static $mapa = null;
    if ($mapa !== null) {
        return $mapa;
    }

    $mapa = [];
    $stmt = $pdo->query(
        "SELECT numero_factura, numero_guia, transportadora, estado, fecha_entrega, fecha_estado, ciudad_destino,
                COALESCE(fecha_estado, fecha_entrega, fecha_despacho, fecha_guia) AS momento
           FROM seguimiento_pedidos
          WHERE fuente <> 'facturacion' AND numero_factura IS NOT NULL AND numero_factura <> ''"
    );
    foreach ($stmt as $s) {
        $clave = claveFacturaMr($s['numero_factura']);
        if ($clave === '') {
            continue;
        }
        $guia = trim((string) $s['numero_guia']);
        // Una factura puede salir varias veces (reintentos de entrega, 2026-10-05): gana la que tiene
        // guía y, entre esas, la de estado más reciente.
        $actual = $mapa[$clave] ?? null;
        $gana   = $actual === null
            || ($guia !== '' && $actual['guia'] === null)
            || (($guia !== '') === ($actual['guia'] !== null) && (string) $s['momento'] > (string) $actual['momento']);
        if ($gana) {
            $mapa[$clave] = [
                'guia'           => $guia !== '' ? $guia : null,
                'transportadora' => trim((string) $s['transportadora']) !== '' ? trim((string) $s['transportadora']) : null,
                'estado'         => $s['estado'],
                'fecha_entrega'  => $s['fecha_entrega'],
                'fecha_estado'   => $s['fecha_estado'],
                'ciudad_destino' => trim((string) $s['ciudad_destino']) !== '' ? trim((string) $s['ciudad_destino']) : null,
                'momento'        => $s['momento'],
            ];
        }
    }
    return $mapa;
}

/** El transportador puesto a mano a cada factura: [factura => transportadora]. */
function transportadorasManualesMr($pdo, array $facturas) {
    $facturas = array_values(array_unique(array_map('strval', $facturas)));
    $salida = [];
    foreach (array_chunk($facturas, 500) as $tanda) {
        $stmt = $pdo->prepare("SELECT factura, transportadora FROM consolidado_mr_envios WHERE factura IN ("
                              . implode(',', array_fill(0, count($tanda), '?')) . ")");
        $stmt->execute($tanda);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $salida[(string) $r['factura']] = $r['transportadora'];
        }
    }
    return $salida;
}

// =================================================================================================
// ESTADO Y FECHA DE ENTREGA A MANO (2026-10-07, pedido del usuario)
//
// Igual que el transportador: lo que se pone a mano MANDA sobre lo que dice el reporte de la
// transportadora, y vive en su propia tabla (consolidado_mr_estados_manuales) para que volver a subir
// el Consolidado MR o un reporte no lo borre. Dejarlo vacío vuelve a mostrar lo del reporte. El grupo
// del semáforo (entregado, en camino…) sale del estado puesto a mano, con grupoDeEstado().
// =================================================================================================

// Los estados del desplegable (además, "Otro…" deja escribir cualquiera).
const MR_ESTADOS_MANUALES = ['ENTREGADO', 'ENTREGA PARCIAL', 'EN TRÁNSITO', 'EN REPARTO', 'PENDIENTE',
                             'CON NOVEDAD', 'DEVOLUCIÓN PARCIAL', 'DEVOLUCIÓN TOTAL'];

// El color de cada grupo del semáforo (el mismo de las pastillas y los chips de la pantalla).
const MR_COLOR_GRUPOS = ['entregado' => 'estado-verde', 'en_camino' => 'estado-azul', 'pendiente' => 'estado-ambar',
                         'otro' => 'estado-gris', 'sin_estado' => 'estado-gris'];

/** El estado y la fecha puestos a mano: [factura => ['estado', 'fecha_entrega']]. */
function estadosManualesMr($pdo, array $facturas) {
    $facturas = array_values(array_unique(array_map('strval', $facturas)));
    $salida = [];
    foreach (array_chunk($facturas, 500) as $tanda) {
        $stmt = $pdo->prepare("SELECT factura, estado, fecha_entrega FROM consolidado_mr_estados_manuales WHERE factura IN ("
                              . implode(',', array_fill(0, count($tanda), '?')) . ")");
        $stmt->execute($tanda);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $salida[(string) $r['factura']] = $r;
        }
    }
    return $salida;
}

/** El envío de una factura (de envioDeFacturaMr) con lo puesto a mano encima. */
function aplicarEstadoManualMr(array $envio, ?array $manual) {
    $envio['estado_reporte'] = $envio['estado'] ?? null;
    $envio['fecha_entrega_reporte'] = $envio['fecha_entrega'] ?? null;
    if (!$manual) {
        return $envio;
    }
    if (($manual['estado'] ?? null) !== null && $manual['estado'] !== '') {
        $envio['estado'] = $manual['estado'];
        $envio['grupo'] = grupoDeEstado($manual['estado']);
        $envio['estado_manual'] = true;
    }
    if (!empty($manual['fecha_entrega'])) {
        $envio['fecha_entrega'] = $manual['fecha_entrega'];
        $envio['fecha_manual'] = true;
    }
    return $envio;
}

/**
 * Pone (o quita, con el valor vacío) el estado o la fecha de entrega de UNA factura.
 * $campo: 'estado' o 'fecha_entrega' (AAAA-MM-DD). Devuelve lo que la pantalla necesita para
 * repintar la celda: ['exito', 'manual', 'grupo', 'color'] o ['exito' => false, 'error'].
 */
function guardarEstadoManualMr($pdo, $factura, $campo, $valor, $idUsuario) {
    $factura = codigoLimpio($factura, 30);
    if (!in_array($campo, ['estado', 'fecha_entrega'], true) || $factura === null) {
        return ['exito' => false, 'error' => 'Dato no válido.'];
    }
    $stmt = $pdo->prepare("SELECT 1 FROM consolidado_mr WHERE factura = ? LIMIT 1");
    $stmt->execute([$factura]);
    if (!$stmt->fetchColumn()) {
        return ['exito' => false, 'error' => 'Esa factura ya no está en el Consolidado MR. Recargá la página.'];
    }
    if ($campo === 'estado') {
        $valor = textoLimpio($valor, 80);
        $valor = $valor === null ? null : mb_strtoupper($valor, 'UTF-8');
    } else {
        $valor = trim((string) $valor);
        if ($valor === '') {
            $valor = null;
        } else {
            $d = DateTime::createFromFormat('!Y-m-d', $valor);
            if (!$d || $d->format('Y-m-d') !== $valor) {
                return ['exito' => false, 'error' => 'La fecha de entrega no es válida.'];
            }
        }
    }
    try {
        $pdo->prepare("INSERT INTO consolidado_mr_estados_manuales (factura, {$campo}, id_usuario) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE {$campo} = VALUES({$campo}), id_usuario = VALUES(id_usuario)")
            ->execute([$factura, $valor, $idUsuario]);
        // Sin estado ni fecha, la fila sobra: la factura vuelve a mostrar solo lo del reporte.
        $pdo->prepare("DELETE FROM consolidado_mr_estados_manuales WHERE factura = ? AND estado IS NULL AND fecha_entrega IS NULL")
            ->execute([$factura]);
    } catch (PDOException $e) {
        error_log('Error guardando el estado a mano del Consolidado MR: ' . $e->getMessage());
        return ['exito' => false, 'error' => 'No se pudo guardar. Recargá la página y probá de nuevo.'];
    }
    $fila = buscarFacturaMr($pdo, $factura);
    $envio = $fila['envio'] ?? ['grupo' => 'sin_estado'];
    // El mensaje queda en Trazabilidad: qué factura, qué se puso (o que volvió a lo del reporte).
    $numero = ($fila['referencia'] ?? '') !== '' ? $fila['referencia'] : $factura;
    $mensaje = $campo === 'estado'
        ? ($valor !== null ? "Factura {$numero}: estado puesto a mano en {$valor}." : "Factura {$numero}: el estado vuelve a ser el del reporte de la transportadora.")
        : ($valor !== null ? "Factura {$numero}: fecha de entrega puesta a mano en " . date('d/m/Y', strtotime($valor)) . '.' : "Factura {$numero}: la fecha de entrega vuelve a ser la del reporte.");
    return ['exito' => true, 'mensaje' => $mensaje, 'manual' => $valor !== null, 'grupo' => $envio['grupo'],
            'color' => MR_COLOR_GRUPOS[$envio['grupo']] ?? 'estado-gris',
            'reporte' => $campo === 'estado' ? ($envio['estado_reporte'] ?? null) : ($envio['fecha_entrega_reporte'] ?? null)];
}

/**
 * Cómo sale una factura: ['guia', 'transportadora', 'estado', 'origen'].
 *  · 'guia'    — está en el consolidado de las transportadoras CON guía: se muestra y no se edita.
 *                Si el reporte no traía transportadora (la columna no es obligatoria, 2026-10-05),
 *                sale vacía con 'editable' => true y se le pone a mano como a las que no tienen guía.
 *  · 'manual'  — no tiene guía y se le puso el transportador a mano.
 *  · 'reporte' — está en el consolidado de las transportadoras sin guía (p. ej. INTERNO).
 *  · null      — no aparece en ningún lado: falta ponerle el transportador.
 */
function envioDeFacturaMr(array $f, array $guias, array $manuales) {
    $reporte = null;
    $manual  = $manuales[(string) ($f['factura'] ?? '')] ?? null;
    foreach ([$f['referencia'] ?? null, $f['factura'] ?? null] as $numero) {
        $clave = claveFacturaMr($numero);
        if ($clave !== '' && isset($guias[$clave])) {
            if ($guias[$clave]['guia'] !== null) {
                $conGuia = $guias[$clave] + ['origen' => 'guia', 'grupo' => grupoDeEstado($guias[$clave]['estado'])];
                if ($conGuia['transportadora'] === null) {
                    $conGuia = ['transportadora' => $manual, 'editable' => true] + $conGuia;
                }
                return $conGuia;
            }
            $reporte = $reporte ?? $guias[$clave];
        }
    }

    $sinDatos = ['guia' => null, 'transportadora' => null, 'estado' => null, 'fecha_entrega' => null,
                 'fecha_estado' => null, 'ciudad_destino' => null];
    if ($manual !== null) {
        // El estado, si la transportadora lo informa aunque no traiga guía, se conserva.
        $base = $reporte ?? $sinDatos;
        return ['transportadora' => $manual, 'origen' => 'manual',
                'grupo' => $reporte !== null ? grupoDeEstado($reporte['estado']) : 'sin_estado'] + $base;
    }
    if ($reporte !== null) {
        return $reporte + ['origen' => 'reporte', 'grupo' => grupoDeEstado($reporte['estado'])];
    }
    return $sinDatos + ['origen' => null, 'grupo' => 'sin_estado'];
}

/**
 * TODAS las facturas del filtro con su envío ya resuelto (guía, transportador, estado y grupo del
 * semáforo). La pestaña Facturas trabaja sobre esta lista: el semáforo y "sin transportador" cuentan
 * todas, y el filtro por grupo y la página se aplican después, en PHP.
 */
function facturasConEnvioMr($pdo, array $filtros = []) {
    $todas    = facturasConsolidadoMr($pdo, $filtros, 1, true);
    $guias    = guiasDeTransportadorasMr($pdo);
    $manuales = transportadorasManualesMr($pdo, array_column($todas, 'factura'));
    $estadosManuales = estadosManualesMr($pdo, array_column($todas, 'factura'));   // 2026-10-07
    $responsables = responsablesMr($pdo);
    $contado      = contadoMr($pdo);
    foreach ($todas as &$f) {
        $f['envio'] = aplicarEstadoManualMr(envioDeFacturaMr($f, $guias, $manuales), $estadosManuales[(string) $f['factura']] ?? null);
        $f['responsable'] = $responsables[(string) $f['factura']] ?? null;
        $f['contado'] = $contado[(string) $f['factura']] ?? null;
    }
    unset($f);
    return $todas;
}

/** Cuántas facturas hay en cada grupo del semáforo (como las pastillas de Estado de pedidos). */
function resumenEstadosMr(array $facturas) {
    $r = ['total' => count($facturas), 'entregado' => 0, 'en_camino' => 0, 'pendiente' => 0, 'otro' => 0, 'sin_estado' => 0];
    foreach ($facturas as $f) {
        $r[$f['envio']['grupo']]++;
    }
    return $r;
}

// FILTRO POR TRANSPORTADOR (2026-10-05). El valor del filtro: '' = todas, MR_SIN_TRANSPORTADOR = las
// que no tienen ninguno, o el nombre de uno (sin distinguir mayúsculas).
const MR_SIN_TRANSPORTADOR = 'sin_asignar';

/** ¿La factura tiene transportador? El del reporte de la transportadora o el puesto a mano. */
function tieneTransportadorMr(array $f) {
    return trim((string) ($f['envio']['transportadora'] ?? '')) !== '';
}

/** ¿La factura pasa el filtro de transportador? */
function cumpleTransportadorMr(array $f, $filtro) {
    $filtro = trim((string) $filtro);
    if ($filtro === '') {
        return true;
    }
    if ($filtro === MR_SIN_TRANSPORTADOR) {
        return !tieneTransportadorMr($f);
    }
    return mb_strtoupper(trim((string) ($f['envio']['transportadora'] ?? '')), 'UTF-8') === mb_strtoupper($filtro, 'UTF-8');
}

/** Los transportadores que aparecen en una lista de facturas, para el desplegable del filtro. */
function transportadoresDeListaMr(array $facturas) {
    $lista = [];
    foreach ($facturas as $f) {
        $t = trim((string) ($f['envio']['transportadora'] ?? ''));
        if ($t !== '' && !isset($lista[mb_strtoupper($t, 'UTF-8')])) {
            $lista[mb_strtoupper($t, 'UTF-8')] = $t;
        }
    }
    ksort($lista);
    return array_values($lista);
}

/** Totales de una lista de facturas: cuántas, de cuántos clientes, su valor y cuántas sin transportador. */
function totalesDeListaMr(array $facturas) {
    $clientes = [];
    $valor = 0.0;
    $sinTransportador = 0;
    $sinResponsable = 0;
    foreach ($facturas as $f) {
        $clientes[(string) $f['nombre_cliente']] = true;
        if (empty($f['responsable'])) {
            $sinResponsable++;
        }
        $valor += (float) $f['valor_neto'];
        if (!tieneTransportadorMr($f)) {
            $sinTransportador++;
        }
    }
    return ['facturas' => count($facturas), 'clientes' => count($clientes), 'valor' => $valor,
            'sin_transportador' => $sinTransportador, 'sin_responsable' => $sinResponsable];
}

/**
 * EL BOTÓN DE IMPORTAR RECIBE LOS DOS TIPOS DE ARCHIVO (2026-10-05): el Consolidado MR de SAP y los
 * reportes de las transportadoras que antes solo se subían en Estado de pedidos (Proeslog, Vector
 * Foods, AGV). Se decide por los títulos de la primera fila: si trae Factura, Material,
 * Ctd.facturada y Valor neto es el Consolidado MR; si no, va al importador de Estado de pedidos, que
 * reconoce solo de qué transportadora es. Devuelve ['exito', 'mensaje'].
 */
function importarArchivoMr($pdo, $rutaArchivo, $idUsuario, $transportadora = null) {
    // LA TRANSPORTADORA ELEGIDA AL IMPORTAR (2026-10-05): opcional; se elige de la lista (Proeslog,
    // AGV, SAP…) o se escribe. Vacía = como siempre: el reporte dice la suya y las demás quedan "Sin
    // asignar".
    $transportadora = textoLimpio($transportadora, 80);

    $mapa = mapearColumnas(encabezadoDeExcel($rutaArchivo), columnasConsolidadoMr());
    if (isset($mapa['factura'], $mapa['material'], $mapa['cantidad_facturada'], $mapa['valor_neto'])) {
        $r = importarConsolidadoMr($pdo, $rutaArchivo, $idUsuario);
        // A las facturas del archivo que todavía no tienen transportador se les pone el elegido; las
        // que ya tenían uno puesto a mano lo conservan.
        if ($r['exito'] && $transportadora !== null && !empty($r['facturas'])) {
            $conUno = transportadorasManualesMr($pdo, $r['facturas']);
            $sinUno = array_values(array_filter($r['facturas'], fn($f) => !isset($conUno[(string) $f])));
            $n = $sinUno ? guardarTransportadoraMr($pdo, $sinUno, $transportadora, $idUsuario) : 0;
            $r['mensaje'] .= ' Transportador ' . $transportadora . ' puesto a ' . number_format($n, 0, ',', '.') . ' factura(s)'
                . ($conUno ? '; ' . number_format(count($conUno), 0, ',', '.') . ' ya tenían uno y lo conservan.' : '.');
        }
        if ($r['exito']) {
            $sin = totalesDeListaMr(array_filter(facturasConEnvioMr($pdo), fn($f) => !esAnuladaMr($f)))['sin_responsable'];
            if ($sin > 0) {
                $r['mensaje'] .= ' OJO: ' . number_format($sin, 0, ',', '.') . ' factura(s) no tienen responsable de empaque: '
                              . 'salen en rojo. Enlazalas en "Enlazar facturas".';
            }
        }
        return $r;
    }

    $r = importarPedidosExcel($pdo, $rutaArchivo, $idUsuario, $transportadora);
    $r['mensaje'] = 'Reporte de transportadora cargado (guías, estados y entregas). ' . $r['mensaje'];
    if ($r['exito']) {
        $r['mensaje'] = cruceDelReporteMr($pdo, $r['facturas'] ?? [], $r['mensaje']);
    }
    return $r;
}

/**
 * El mensaje del reporte, con lo que se va a ver en la tabla (2026-10-05). La tabla muestra las
 * facturas del Consolidado MR y les pega la guía del reporte: si el Consolidado MR está vacío, el
 * reporte se guarda pero no se ve nada, y eso tiene que quedar dicho ANTES que todo lo demás.
 */
function cruceDelReporteMr($pdo, array $facturasDelReporte, $mensaje) {
    $enMr = [];
    foreach ($pdo->query("SELECT DISTINCT factura, referencia FROM consolidado_mr") as $m) {
        $enMr[claveFacturaMr($m['referencia'])] = true;
        $enMr[claveFacturaMr($m['factura'])] = true;
    }
    unset($enMr['']);
    $mil = fn($n) => number_format($n, 0, ',', '.');
    if (!$enMr) {
        return 'Se guardaron los envíos, pero la tabla todavía no muestra nada: el Consolidado MR está vacío. '
             . 'Subí el Consolidado MR de SAP y cada factura va a tomar de este reporte su guía, su estado y su '
             . 'fecha de entrega. — ' . $mensaje;
    }
    $cruzan = 0;
    foreach ($facturasDelReporte as $f) {
        if (isset($enMr[claveFacturaMr($f)])) {
            $cruzan++;
        }
    }
    $total = count($facturasDelReporte);
    return $mensaje . ' ' . $mil($cruzan) . ' de ' . $mil($total) . ' factura(s) del reporte están en el Consolidado MR'
         . ($cruzan < $total ? '; las demás se van a ver cuando subas un Consolidado MR que las traiga.' : '.');
}

// =================================================================================================
// RESPONSABLE DE EMPAQUE (2026-10-05)
//
// Quién alistó y despachó cada factura. Lo registra la bodega en el apartado "Enlazar facturas":
// escribe el número de la factura y la cédula del operario, ve un resumen de las dos cosas y la
// enlaza. Queda en consolidado_mr_responsables (una fila por factura de SAP) y se muestra en la
// columna "Responsable de empaque" del Consolidado MR; las facturas sin responsable salen en rojo.
//
// Reemplaza lo de sacar el nombre de las observaciones de la guía de Proeslog (decisión del usuario):
// ese texto lo escribe cada quien como quiere y no es un registro confiable.
// =================================================================================================

/** Todos los responsables enlazados: [factura de SAP => nombre, documento, fecha_enlace, id_personal, cajas]. */
function responsablesMr($pdo) {
    $mapa = [];
    foreach ($pdo->query("SELECT factura, nombre, documento, fecha_enlace, id_personal, cajas FROM consolidado_mr_responsables") as $r) {
        $mapa[(string) $r['factura']] = $r;
    }
    return $mapa;
}

/**
 * Una factura del Consolidado MR a partir de lo que se escriba: la Referencia ("NU04169821", con o
 * sin espacios o ceros) o la factura de SAP ("7963309278"). Devuelve la factura entera —con su
 * envío y su responsable, si tiene— o null si no está cargada.
 */
function buscarFacturaMr($pdo, $numero) {
    $clave = claveFacturaMr($numero);
    if ($clave === '') {
        return null;
    }

    $factura = null;
    foreach ($pdo->query("SELECT DISTINCT factura, referencia FROM consolidado_mr") as $r) {
        if (claveFacturaMr($r['referencia']) === $clave || claveFacturaMr($r['factura']) === $clave) {
            $factura = (string) $r['factura'];
            break;
        }
    }
    if ($factura === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT factura, MAX(referencia) AS referencia, MAX(doc_ventas) AS doc_ventas,
                MAX(pedido_cliente) AS pedido_cliente, MAX(fecha_factura) AS fecha_factura,
                MAX(solicitante) AS solicitante, MAX(nombre_cliente) AS nombre_cliente,
                MAX(poblacion) AS poblacion, SUM(valor_neto) AS valor_neto,
                COUNT(*) AS renglones, COALESCE(SUM(cantidad_facturada), 0) AS unidades
           FROM consolidado_mr WHERE factura = ? GROUP BY factura"
    );
    $stmt->execute([$factura]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    $f['envio']       = aplicarEstadoManualMr(envioDeFacturaMr($f, guiasDeTransportadorasMr($pdo), transportadorasManualesMr($pdo, [$factura])),
                                           estadosManualesMr($pdo, [$factura])[$factura] ?? null);
    $f['responsable'] = responsablesMr($pdo)[$factura] ?? null;
    return $f;
}

/**
 * La persona del módulo Personal con esa cédula, o null. Se comparan solo los dígitos: "1.020.304"
 * y "1020304" son la misma cédula.
 */
function buscarPersonaPorCedulaMr($pdo, $cedula) {
    $digitos = preg_replace('/\D/', '', (string) $cedula);
    if ($digitos === '') {
        return null;
    }
    foreach ($pdo->query("SELECT id_personal, nombre, documento, cargo, estado FROM personal
                           WHERE documento IS NOT NULL AND documento <> ''") as $p) {
        if (preg_replace('/\D/', '', $p['documento']) === $digitos) {
            return $p;
        }
    }
    return null;
}

/**
 * Enlaza una factura (la de SAP) con la persona que la alistó y despachó y el número de cajas del
 * pedido (2026-10-05; por ahora solo se ve en la tabla de Enlazar facturas). Si ya tenía responsable,
 * lo reemplaza. Devuelve ['exito' => bool, 'mensaje' => string].
 */
function enlazarResponsableMr($pdo, $factura, $idPersonal, $idUsuario, $cajas = null) {
    $cajas = trim((string) $cajas);
    if (!preg_match('/^\d{1,4}$/', $cajas) || (int) $cajas < 1) {
        return ['exito' => false, 'mensaje' => 'Escribí el número de cajas del pedido (un número entero desde 1).'];
    }
    $cajas = (int) $cajas;

    $f = buscarFacturaMr($pdo, $factura);
    if ($f === null) {
        return ['exito' => false, 'mensaje' => 'Esa factura no está en el Consolidado MR.'];
    }

    $stmt = $pdo->prepare("SELECT id_personal, nombre, documento, estado FROM personal WHERE id_personal = ?");
    $stmt->execute([(int) $idPersonal]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return ['exito' => false, 'mensaje' => 'No se encontró a esa persona en Personal.'];
    }
    if ($p['estado'] !== 'Activo') {
        return ['exito' => false, 'mensaje' => $p['nombre'] . ' está inactivo en Personal: no se le pueden enlazar facturas.'];
    }

    $pdo->prepare(
        "INSERT INTO consolidado_mr_responsables (factura, referencia, id_personal, documento, nombre, cajas, id_usuario)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE referencia = VALUES(referencia), id_personal = VALUES(id_personal),
             documento = VALUES(documento), nombre = VALUES(nombre), cajas = VALUES(cajas),
             id_usuario = VALUES(id_usuario), fecha_enlace = CURRENT_TIMESTAMP"
    )->execute([$f['factura'], $f['referencia'], $p['id_personal'], $p['documento'], $p['nombre'], $cajas, $idUsuario]);

    $numero = $f['referencia'] ?: $f['factura'];
    $antes  = $f['responsable'] && (int) $f['responsable']['id_personal'] !== (int) $p['id_personal']
        ? ' (antes estaba enlazada a ' . $f['responsable']['nombre'] . ')' : '';
    $deCajas = $cajas === 1 ? '1 caja' : $cajas . ' cajas';
    return ['exito' => true, 'mensaje' => "Factura {$numero} enlazada a {$p['nombre']}{$antes}, con {$deCajas}."];
}

/** Quita el responsable de una factura. Devuelve true si había uno. */
function quitarResponsableMr($pdo, $factura) {
    $stmt = $pdo->prepare("DELETE FROM consolidado_mr_responsables WHERE factura = ?");
    $stmt->execute([(string) $factura]);
    return $stmt->rowCount() > 0;
}

/** Los últimos enlaces, del más nuevo al más viejo, con el cliente de la factura y quién lo registró. */
function ultimosEnlacesMr($pdo, $cuantos = 25) {
    return $pdo->query(
        "SELECT r.factura, r.referencia, r.nombre, r.documento, r.cajas, r.fecha_enlace,
                (SELECT MAX(m.nombre_cliente) FROM consolidado_mr m WHERE m.factura = r.factura) AS cliente,
                u.nombre_usuario AS registrado_por
           FROM consolidado_mr_responsables r
           LEFT JOIN usuarios u ON u.id_usuario = r.id_usuario
          ORDER BY r.fecha_enlace DESC
          LIMIT " . (int) $cuantos
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Los transportadores que se ofrecen para elegir: INTERNO primero (es el de casi siempre), después los
 * que aparecen en Estado de pedidos y los que ya se pusieron a mano. Sin repetir.
 */
function opcionesTransportadoraMr($pdo, array $primeras = ['INTERNO', 'Proeslog', 'AGV', 'SAP']) {
    $lista = $primeras;
    foreach ($pdo->query("SELECT DISTINCT transportadora FROM seguimiento_pedidos
                           WHERE transportadora IS NOT NULL AND transportadora <> '' ORDER BY transportadora") as $r) {
        $lista[] = $r['transportadora'];
    }
    foreach ($pdo->query("SELECT DISTINCT transportadora FROM consolidado_mr_envios ORDER BY transportadora") as $r) {
        $lista[] = $r['transportadora'];
    }

    $vistos = [];
    $salida = [];
    foreach ($lista as $t) {
        $t = trim((string) $t);
        $k = mb_strtoupper($t, 'UTF-8');
        if ($t === '' || isset($vistos[$k])) {
            continue;
        }
        $vistos[$k] = true;
        $salida[] = $t;
    }
    return $salida;
}

/**
 * Pone (o quita, con $transportadora vacío) el transportador de una o varias facturas. Solo toca
 * facturas que existen en el Consolidado MR. Devuelve cuántas cambió.
 */
function guardarTransportadoraMr($pdo, array $facturas, $transportadora, $idUsuario) {
    $facturas = array_values(array_unique(array_filter(array_map(fn($f) => codigoLimpio($f, 30), $facturas))));
    if (!$facturas) {
        return 0;
    }

    $existentes = [];
    foreach (array_chunk($facturas, 500) as $tanda) {
        $stmt = $pdo->prepare("SELECT DISTINCT factura FROM consolidado_mr WHERE factura IN ("
                              . implode(',', array_fill(0, count($tanda), '?')) . ")");
        $stmt->execute($tanda);
        $existentes = array_merge($existentes, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$existentes) {
        return 0;
    }

    $transportadora = textoLimpio($transportadora, 80);
    $pdo->beginTransaction();
    try {
        if ($transportadora === null) {
            $stmt = $pdo->prepare("DELETE FROM consolidado_mr_envios WHERE factura = ?");
            foreach ($existentes as $f) { $stmt->execute([(string) $f]); }
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO consolidado_mr_envios (factura, transportadora, id_usuario) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE transportadora = VALUES(transportadora), id_usuario = VALUES(id_usuario)"
            );
            foreach ($existentes as $f) { $stmt->execute([(string) $f, $transportadora, $idUsuario]); }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error guardando el transportador del Consolidado MR: ' . $e->getMessage());
        return 0;
    }
    return count($existentes);
}

// =================================================================================================
// FACTURAS DE CONTADO (2026-10-05; reemplaza la pestaña "No pagadas")
//
// DE DÓNDE SALEN (2026-10-06, pedido del usuario): de la columna CPag del mismo Consolidado MR. Las
// facturas con CPag 0010 son DE CONTADO, y se marcan solas al importarlo: con subir UNA vez el archivo
// en la pestaña Facturas salen las anuladas (X en "Anulado.") y las de contado. Las que dejaron de
// ser 0010 pasan a "No". Lo marcado A MANO no se toca (el modal lo promete: "un archivo nuevo no lo
// cambia"). Además se pueden marcar con la COMPARATIVA —un archivo con los números de las facturas de
// contado— o a mano, de a una o varias a la vez.
//
// TRAZABILIDAD: una factura que alguna vez fue de contado queda registrada aunque después pase a
// "No" —sigue en la pestaña, con "No"—, y cada cambio se anota en consolidado_mr_contado_historial
// (qué valor, si vino de la comparativa o fue a mano, quién y cuándo).
//
// La comparativa MANDA (decisión del usuario): cambia también lo que se marcó a mano.
// =================================================================================================

// La condición de pago (CPag) de las facturas de contado.
const MR_CPAG_CONTADO = '0010';

/** La condición de pago comparable: "0010", "10" o 10 → "0010". null si viene vacía. */
function condicionPagoMr($valor) {
    $t = trim(is_float($valor) && floor($valor) == $valor ? sprintf('%.0f', $valor) : (string) ($valor ?? ''));
    if ($t === '') {
        return null;
    }
    return ctype_digit($t) ? str_pad(ltrim($t, '0') === '' ? '0' : ltrim($t, '0'), 4, '0', STR_PAD_LEFT) : mb_substr(strtoupper($t), 0, 10);
}

/**
 * Las de contado según el CPag del archivo ([factura => CPag]): las de 0010 pasan a "Sí" y las que eran
 * "Sí" y ahora traen otra condición, a "No". Las marcadas A MANO quedan como están. Se anotan en el
 * historial con origen 'archivo'. Devuelve ['de_contado', 'nuevas', 'a_no', 'a_mano'].
 */
function contadoDesdeCpagMr($pdo, array $cpagPorFactura, $idUsuario) {
    $actuales = contadoMr($pdo);
    $aSi = []; $aNo = []; $aMano = 0; $deContado = 0;
    foreach ($cpagPorFactura as $f => $cp) {
        $f = (string) $f;
        $es = $cp === MR_CPAG_CONTADO;
        $deContado += $es ? 1 : 0;
        $antes = $actuales[$f] ?? null;
        $eraSi = $antes !== null && (int) $antes['de_contado'] === 1;
        if ($es === $eraSi) {
            continue;   // ya está como dice el archivo
        }
        if ($antes !== null && $antes['origen'] === 'manual') {
            $aMano++;   // alguien lo eligió a mano: se respeta
            continue;
        }
        if ($es) {
            $aSi[] = $f;
        } elseif ($eraSi) {
            $aNo[] = $f;   // solo las que eran "Sí": las demás no se registran (no ensucian la pestaña)
        }
    }
    $nuevas = $aSi ? marcarContadoMr($pdo, $aSi, true, $idUsuario, 'archivo') : 0;
    $pasaronANo = $aNo ? marcarContadoMr($pdo, $aNo, false, $idUsuario, 'archivo') : 0;
    return ['de_contado' => $deContado, 'nuevas' => $nuevas, 'a_no' => $pasaronANo, 'a_mano' => $aMano];
}

// Los valores del filtro "De contado": '' = todas las registradas, 'si', 'no' (registradas que pasaron a
// No) o 'fuera' (TODAS las que no son de contado, registradas o no, para marcarlas).
const MR_CONTADO_VALORES = ['si', 'no', 'fuera'];

/** Lo registrado de cada factura: [factura de SAP => de_contado, origen, fecha_cambio, usuario]. */
function contadoMr($pdo) {
    $mapa = [];
    foreach ($pdo->query("SELECT c.factura, c.de_contado, c.origen, c.fecha_cambio, c.fecha_registro,
                                 u.nombre_usuario AS usuario
                            FROM consolidado_mr_contado c
                            LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario") as $r) {
        $mapa[(string) $r['factura']] = $r;
    }
    return $mapa;
}

/** ¿La factura (de facturasConEnvioMr) está marcada de contado? */
function esDeContadoMr(array $f) {
    return !empty($f['contado']) && (int) $f['contado']['de_contado'] === 1;
}

/** ¿La factura pasa el filtro "De contado"? $soloRegistradas: la pestaña, que muestra solo esas. */
function cumpleContadoMr(array $f, $filtro, $soloRegistradas) {
    if ($soloRegistradas && empty($f['contado'])) {
        return false;
    }
    if ($filtro === 'si') {
        return esDeContadoMr($f);
    }
    if ($filtro === 'no' || $filtro === 'fuera') {
        return !esDeContadoMr($f);
    }
    return true;
}

/** Los números de la pestaña: de contado y que pasaron a "No", sin contar las facturas anuladas. */
function resumenContadoMr($pdo) {
    $r = $pdo->query(
        "SELECT COALESCE(SUM(c.de_contado = 1), 0) AS si, COALESCE(SUM(c.de_contado = 0), 0) AS no
           FROM consolidado_mr_contado c
          WHERE EXISTS (SELECT 1 FROM consolidado_mr m WHERE m.factura = c.factura)
            AND NOT EXISTS (SELECT 1 FROM consolidado_mr m WHERE m.factura = c.factura AND m.anulado = 1)"
    )->fetch(PDO::FETCH_ASSOC);
    return ['si' => (int) $r['si'], 'no' => (int) $r['no'], 'todas' => (int) $r['si'] + (int) $r['no']];
}

/** Cómo se muestra de dónde salió el último cambio: "Comparativa · 05/10/2026 14:43", "A mano · Jorge…". */
function origenContadoMr(array $c) {
    $como = ['comparativa' => 'Comparativa', 'archivo' => 'CPag del Consolidado MR'][$c['origen']] ?? ('A mano' . ($c['usuario'] ? ' · ' . $c['usuario'] : ''));
    return $como . ' · ' . date('d/m/Y H:i', strtotime($c['fecha_cambio']));
}

/**
 * Pone "De contado" en Sí o No a una o varias facturas. Solo toca las que están en el Consolidado MR
 * y anota en el historial las que cambian. $origen: 'manual' (alguien lo eligió), 'comparativa' o
 * 'archivo' (el CPag 0010 del Consolidado MR).
 * Devuelve cuántas cambiaron.
 */
function marcarContadoMr($pdo, array $facturas, $deContado, $idUsuario, $origen = 'manual') {
    $facturas = array_values(array_unique(array_filter(array_map(fn($f) => codigoLimpio($f, 30), $facturas))));
    if (!$facturas) {
        return 0;
    }
    $origen = in_array($origen, ['manual', 'comparativa', 'archivo'], true) ? $origen : 'manual';
    $valor  = $deContado ? 1 : 0;

    // La referencia (NU…) de cada una, que además confirma que está en el Consolidado MR.
    $referencias = [];
    foreach (array_chunk($facturas, 500) as $tanda) {
        $stmt = $pdo->prepare("SELECT factura, MAX(referencia) FROM consolidado_mr WHERE factura IN ("
                              . implode(',', array_fill(0, count($tanda), '?')) . ") GROUP BY factura");
        $stmt->execute($tanda);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$f, $ref]) {
            $referencias[(string) $f] = $ref;
        }
    }
    if (!$referencias) {
        return 0;
    }
    $actuales = contadoMr($pdo);

    $guardar = $pdo->prepare(
        "INSERT INTO consolidado_mr_contado (factura, referencia, de_contado, origen, id_usuario)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE referencia = VALUES(referencia), de_contado = VALUES(de_contado),
             origen = VALUES(origen), id_usuario = VALUES(id_usuario), fecha_cambio = CURRENT_TIMESTAMP"
    );
    $anotar = $pdo->prepare(
        "INSERT INTO consolidado_mr_contado_historial (factura, de_contado, origen, id_usuario) VALUES (?, ?, ?, ?)"
    );

    $cambiadas = 0;
    $pdo->beginTransaction();
    try {
        foreach ($referencias as $f => $ref) {
            $antes = $actuales[$f] ?? null;
            if ($antes !== null && (int) $antes['de_contado'] === $valor) {
                // Mismo valor: no es un cambio (no va al historial).
                continue;
            }
            $guardar->execute([$f, $ref, $valor, $origen, $idUsuario]);
            $anotar->execute([$f, $valor, $origen, $idUsuario]);
            $cambiadas++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error marcando facturas de contado: ' . $e->getMessage());
        return 0;
    }
    return $cambiadas;
}

/**
 * La pestaña "Facturas de contado" también sube el Consolidado MR de SAP (solo ese archivo): lo carga
 * igual que el botón de la pestaña Facturas, y con él las de contado (CPag 0010). Devuelve ['exito', 'mensaje'].
 */
function importarContadoMr($pdo, $rutaArchivo, $idUsuario) {
    $mapa = mapearColumnas(encabezadoDeExcel($rutaArchivo), columnasConsolidadoMr());
    if (!isset($mapa['factura'], $mapa['material'], $mapa['cantidad_facturada'], $mapa['valor_neto'])) {
        return ['exito' => false, 'mensaje' => 'Acá va el Excel del Consolidado MR de SAP. Los reportes de las transportadoras '
            . 'se suben en la pestaña Facturas, y el listado de facturas de contado con "Subir comparativa".'];
    }
    return importarArchivoMr($pdo, $rutaArchivo, $idUsuario);
}

/**
 * SUBIR COMPARATIVA (2026-10-05): un archivo con los números de las facturas DE CONTADO. Las que
 * están en el archivo pasan a "Sí"; las que eran "Sí" y no están, a "No" (y siguen en la pestaña).
 * Manda sobre lo marcado a mano.
 *
 * El archivo puede venir de cualquier lado y con cualquier título de columna, así que se miran TODAS
 * sus celdas y se toma cada una que sea un número de factura del Consolidado MR —la Referencia
 * "NU04169821" (con o sin espacios o ceros) o la factura de SAP "7963309278"—. Si no aparece ninguna,
 * no se cambia nada: es otro archivo, y pasar todo a "No" sería un desastre.
 *
 * Devuelve ['exito', 'mensaje'].
 */
function importarComparativaContadoMr($pdo, $rutaArchivo, $idUsuario) {
    $filas = leerXlsxRapido($rutaArchivo);
    $filas = $filas !== null ? $filas : leerPrimeraHoja($rutaArchivo);
    if (!$filas) {
        return ['exito' => false, 'mensaje' => 'No se pudo leer el archivo, o está vacío.'];
    }

    // Cada factura del Consolidado MR por su número comparable (la referencia NU… y la de SAP).
    $porClave = [];
    foreach ($pdo->query("SELECT DISTINCT factura, referencia FROM consolidado_mr") as $m) {
        foreach ([$m['referencia'], $m['factura']] as $n) {
            $k = claveFacturaMr($n);
            if ($k !== '') {
                $porClave[$k] = (string) $m['factura'];
            }
        }
    }
    if (!$porClave) {
        return ['exito' => false, 'mensaje' => 'El Consolidado MR está vacío: subilo primero, así hay con qué comparar.'];
    }

    $enArchivo = []; $noEstan = [];
    foreach ($filas as $fila) {
        foreach ((array) $fila as $celda) {
            if ($celda === null || $celda === '' || is_bool($celda)) {
                continue;
            }
            $texto = trim(is_float($celda) && floor($celda) == $celda ? sprintf('%.0f', $celda) : (string) $celda);
            $k = claveFacturaMr($texto);
            if ($k === '') {
                continue;
            }
            if (isset($porClave[$k])) {
                $enArchivo[$porClave[$k]] = true;
            } elseif (preg_match('/^NU\s*0*\d{5,}$/i', $texto)) {
                $noEstan[strtoupper(preg_replace('/\s+/', '', $texto))] = true;   // parece una factura, pero no está cargada
            }
        }
    }
    if (!$enArchivo) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae ningún número de factura del Consolidado MR'
            . ($noEstan ? ' (trae ' . count($noEstan) . ' que no están cargadas todavía)' : '')
            . '. No se cambió nada.'];
    }

    // Las que eran "Sí" y no están en el archivo pasan a "No".
    $aNo = [];
    foreach (contadoMr($pdo) as $f => $c) {
        if ((int) $c['de_contado'] === 1 && !isset($enArchivo[$f])) {
            $aNo[] = $f;
        }
    }
    $nuevasSi = marcarContadoMr($pdo, array_keys($enArchivo), true, $idUsuario, 'comparativa');
    $nuevasNo = $aNo ? marcarContadoMr($pdo, $aNo, false, $idUsuario, 'comparativa') : 0;

    $mil = fn($n) => number_format($n, 0, ',', '.');
    $msg = 'Comparativa: ' . $mil(count($enArchivo)) . ' factura(s) del archivo quedaron de contado'
         . ($nuevasSi < count($enArchivo) ? ' (' . $mil($nuevasSi) . ' nueva(s); las demás ya lo eran)' : '') . '.';
    $msg .= $nuevasNo ? ' ' . $mil($nuevasNo) . ' que eran de contado y no están en el archivo pasaron a "No".' : '';
    if ($noEstan) {
        $ej = array_slice(array_keys($noEstan), 0, 5);
        $msg .= ' OJO: ' . $mil(count($noEstan)) . ' número(s) del archivo no están en el Consolidado MR (' . implode(', ', $ej)
              . (count($noEstan) > 5 ? '…' : '') . '): subí un Consolidado MR que las traiga y volvé a subir la comparativa.';
    }
    return ['exito' => true, 'mensaje' => $msg];
}

// =================================================================================================
// FACTURAS ANULADAS (2026-10-05)
//
// La X en la columna "Anulado." (o "An." en los export viejos) marca las facturas ANULADAS. Se cargan
// igual —cada renglón guarda su X en consolidado_mr.anulado— y una factura está anulada si alguno de
// sus renglones la trae. Las anuladas se ven SOLO en la pestaña "Facturas anuladas"; esa pestaña
// muestra también las demás, con la columna "Anulada" Sí/No (decisión del usuario).
// =================================================================================================

// Los valores del filtro de la pestaña: '' = todas, 'si' = anuladas, 'no' = no anuladas.
const MR_ANULADA_VALORES = ['si', 'no'];

/** ¿La factura (de facturasConEnvioMr) está anulada? */
function esAnuladaMr(array $f) {
    return !empty($f['anulada']);
}

/** Los números de las pestañas: facturas no anuladas y anuladas. */
function conteosPestanasMr($pdo) {
    $r = $pdo->query(
        "SELECT COUNT(*) AS todas, COALESCE(SUM(anulada), 0) AS anuladas
           FROM (SELECT MAX(anulado) AS anulada FROM consolidado_mr GROUP BY factura) t"
    )->fetch(PDO::FETCH_ASSOC);
    return ['todas' => (int) $r['todas'], 'anuladas' => (int) $r['anuladas'], 'activas' => (int) $r['todas'] - (int) $r['anuladas']];
}

// =================================================================================================
// LOS DOS BOTONES DE SUBIDA (2026-10-05)
// "Importar Consolidado MR" recibe SOLO el Excel de SAP; los reportes de las transportadoras van por
// "Actualizar transportadoras", donde se elige (o se reconoce sola) la transportadora. Si un archivo
// llega al botón que no es, se dice cuál es el bueno y no se carga nada.
// =================================================================================================

/** ¿El archivo es el Consolidado MR de SAP? (trae Factura, Material, Ctd.facturada y Valor neto) */
function esArchivoConsolidadoMr($rutaArchivo) {
    $mapa = mapearColumnas(encabezadoDeExcel($rutaArchivo), columnasConsolidadoMr());
    return isset($mapa['factura'], $mapa['material'], $mapa['cantidad_facturada'], $mapa['valor_neto']);
}

/** "Importar Consolidado MR": solo el Excel de SAP. Devuelve ['exito', 'mensaje']. */
function importarSoloConsolidadoMr($pdo, $rutaArchivo, $idUsuario) {
    if (!esArchivoConsolidadoMr($rutaArchivo)) {
        return ['exito' => false, 'mensaje' => 'Este archivo no es el Consolidado MR de SAP. Si es el reporte de una transportadora '
            . '(Proeslog, AGV…), subilo con "Actualizar transportadoras". No se cargó nada.'];
    }
    return importarArchivoMr($pdo, $rutaArchivo, $idUsuario);
}

/**
 * "Actualizar transportadoras": el reporte de una transportadora (guías, estados y entregas). La
 * transportadora elegida en el desplegable manda; vacía, se reconoce sola por las columnas
 * (Proeslog, AGV, Vector Foods…). Devuelve ['exito', 'mensaje'].
 */
function actualizarTransportadorasMr($pdo, $rutaArchivo, $idUsuario, $transportadora = null) {
    if (esArchivoConsolidadoMr($rutaArchivo)) {
        return ['exito' => false, 'mensaje' => 'Este es el Consolidado MR de SAP: subilo con "Importar Consolidado MR". No se cargó nada.'];
    }
    return importarArchivoMr($pdo, $rutaArchivo, $idUsuario, $transportadora);
}

// =================================================================================================
// LA LISTA DE LA PANTALLA Y SU EXCEL (2026-10-06)
// =================================================================================================
// Hasta el 2026-10-06 los filtros y la lista se armaban dentro de la vista (y otra vez, igual, en el
// controlador de Laravel). Ahora están acá, para que la pantalla y "Exportar Excel" usen EXACTAMENTE
// la misma lista: lo que se descarga es lo que se ve, con todos los filtros, sin la paginación.

/** Los filtros de la pantalla, revisados, a partir de la dirección ($_GET o la query de Laravel). */
function filtrosConsolidadoMr(array $q) {
    $texto = fn($c) => trim((string) ($q[$c] ?? ''));
    return [
        'cliente'   => $texto('cliente'),
        'poblacion' => $texto('poblacion'),
        'buscar'    => $texto('buscar'),
        'desde'     => $texto('desde'),
        'hasta'     => $texto('hasta'),
        // El semáforo (2026-10-05): las mismas pastillas de Estado de pedidos, que filtran la tabla.
        'grupo'     => in_array($q['grupo'] ?? '', ['entregado', 'en_camino', 'pendiente', 'otro', 'sin_estado'], true) ? $q['grupo'] : '',
        // Solo las facturas sin responsable de empaque (el enlace "Ver solo estas" de la alerta).
        'responsable' => ($q['responsable'] ?? '') === 'sin' ? 'sin' : '',
        // Por transportador (2026-10-05): '', 'sin_asignar' (la pastilla "Sin transportador") o un nombre.
        'transportador' => mb_substr($texto('transportador'), 0, 80),
        // De contado (2026-10-05): '' = todas, 'si', 'no' o 'fuera'.
        'contado' => in_array($q['contado'] ?? '', MR_CONTADO_VALORES, true) ? $q['contado'] : '',
        // Anuladas (2026-10-05): '' = todas, 'si' o 'no' (solo en su pestaña).
        'anulada' => in_array($q['anulada'] ?? '', MR_ANULADA_VALORES, true) ? $q['anulada'] : '',
    ];
}

/** La pestaña pedida: 'facturas', 'contado' o 'anuladas' ('no_pagadas' era la de contado de antes). */
function pestanaConsolidadoMr($valor) {
    return $valor === 'anuladas' ? 'anuladas' : (in_array($valor, ['contado', 'no_pagadas'], true) ? 'contado' : 'facturas');
}

/**
 * La lista de una pestaña con sus filtros, y lo que cuenta la pantalla:
 *   'filtradas'        las facturas que se muestran (todas, sin paginar)
 *   'conteoAnuladas'   [todas, si, no]   · 'conteoContado' [todas, si, no, fuera]
 *   'resumen'          el semáforo       · 'sinTransportador', 'transportadores', 'sinResponsable'
 * Las tres pestañas arman la misma lista: Facturas y De contado sin las anuladas (De contado, además,
 * solo con las registradas); Anuladas, con todas.
 */
function listaConsolidadoMr($pdo, $pestana, array $filtros) {
    // TODAS las facturas del filtro con su guía, transportador, estado y responsable: el semáforo y la
    // alerta cuentan todas, y los filtros de grupo y de responsable se aplican después.
    $todas = facturasConEnvioMr($pdo, $filtros);
    // ANULADAS (2026-10-05): solo en su pestaña, que muestra también las demás con su "Anulada" Sí/No.
    $conteoAnuladas = ['todas' => count($todas), 'si' => count(array_filter($todas, 'esAnuladaMr'))];
    $conteoAnuladas['no'] = $conteoAnuladas['todas'] - $conteoAnuladas['si'];
    if ($pestana !== 'anuladas') {
        $todas = array_values(array_filter($todas, fn($f) => !esAnuladaMr($f)));
    } elseif ($filtros['anulada'] !== '') {
        $todas = array_values(array_filter($todas, fn($f) => esAnuladaMr($f) === ($filtros['anulada'] === 'si')));
    }
    // Las registradas de contado: las que son y las que pasaron a "No" (quedan por trazabilidad).
    $registradas   = array_values(array_filter($todas, fn($f) => !empty($f['contado'])));
    $conteoContado = ['todas' => count($registradas), 'si' => count(array_filter($registradas, 'esDeContadoMr')),
                      // "No de contado": TODAS las que no lo son, registradas o no (2026-10-05).
                      'fuera' => count(array_filter($todas, fn($f) => !esDeContadoMr($f)))];
    $conteoContado['no'] = $conteoContado['todas'] - $conteoContado['si'];
    // La pestaña muestra solo las registradas, salvo con "No de contado", que trae todas las demás.
    if ($pestana === 'contado' && $filtros['contado'] !== 'fuera') {
        $todas = $registradas;
    }
    unset($registradas);
    $resumen   = resumenEstadosMr($todas);
    $filtradas = array_values(array_filter($todas, fn($f) =>
        ($filtros['grupo'] === '' || $f['envio']['grupo'] === $filtros['grupo'])
        && ($filtros['responsable'] === '' || empty($f['responsable']))
        && cumpleContadoMr($f, $filtros['contado'], false)));
    // "Sin transportador" cuenta las de la lista con los demás filtros, sin el de transportador, para
    // que el número no se vuelva cero al apretar otra opción del desplegable.
    $sinTransportador = count(array_filter($filtradas, fn($f) => !tieneTransportadorMr($f)));
    $transportadores  = transportadoresDeListaMr($todas);
    if ($filtros['transportador'] !== '') {
        $filtradas = array_values(array_filter($filtradas, fn($f) => cumpleTransportadorMr($f, $filtros['transportador'])));
    }
    // LA ALERTA (2026-10-05): las facturas que no tienen responsable de empaque enlazado.
    $sinResponsable = array_values(array_filter($todas, fn($f) => empty($f['responsable'])));

    return compact('filtradas', 'conteoAnuladas', 'conteoContado', 'resumen', 'sinTransportador', 'transportadores', 'sinResponsable');
}

/**
 * EXPORTAR EXCEL (2026-10-06): la lista de la pestaña con sus filtros (la misma de la pantalla, sin
 * paginar), con las columnas de la tabla y una fila de total. Escribe el .xlsx en $ruta.
 */
function escribirExcelConsolidadoMr($pdo, $pestana, array $filtros, $ruta) {
    $facturas = listaConsolidadoMr($pdo, $pestana, $filtros)['filtradas'];
    $grupos = ['entregado' => 'Entregado', 'en_camino' => 'En camino', 'pendiente' => 'Pendiente', 'otro' => 'Otro', 'sin_estado' => 'Sin estado'];
    $fecha = fn($v) => $v && ($t = strtotime($v)) ? date('d/m/Y', $t) : '';
    $texto = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;

    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle(['facturas' => 'Facturas', 'contado' => 'Facturas de contado', 'anuladas' => 'Facturas anuladas'][$pestana]);
    $enc = ['Factura', 'Pedido', 'Orden de compra', 'Fecha factura', 'Solicitud', 'Cliente', 'Ciudad', 'Valor neto'];
    if ($pestana === 'anuladas') {
        $enc[] = 'Anulada';
    }
    $enc = array_merge($enc, ['De contado', 'Guía', 'Transportador', 'Estado', 'Semáforo', 'Fecha entrega', 'Responsable de empaque']);
    $hoja->fromArray($enc, null, 'A1');
    $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($enc));

    $i = 2;
    foreach ($facturas as $f) {
        $e = $f['envio'];
        $fila = [
            ($f['referencia'] ?? '') !== '' ? $f['referencia'] : $f['factura'], (string) $f['doc_ventas'], (string) $f['pedido_cliente'],
            $fecha($f['fecha_factura']), (string) $f['solicitante'], (string) $f['nombre_cliente'],
            (string) ($e['ciudad_destino'] ?? $f['poblacion']), $f['valor_neto'] !== null ? (float) $f['valor_neto'] : '',
        ];
        if ($pestana === 'anuladas') {
            $fila[] = esAnuladaMr($f) ? 'Sí' : 'No';
        }
        $fila = array_merge($fila, [
            esDeContadoMr($f) ? 'Sí' : 'No', $e['origen'] === 'guia' ? (string) $e['guia'] : '', (string) ($e['transportadora'] ?? ''),
            (string) ($e['estado'] ?? ''), $grupos[$e['grupo']] ?? '', $fecha($e['fecha_entrega'] ?? null),
            !empty($f['responsable']) ? $f['responsable']['nombre'] : 'Sin responsable',
        ]);
        $hoja->fromArray($fila, null, "A{$i}");
        // Los códigos como texto, para que Excel no les quite ceros ni los pase a notación científica.
        foreach ([0, 1, 2, 4] as $c) {
            $hoja->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . $i, (string) $fila[$c], $texto);
        }
        $colGuia = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('Guía', $enc, true) + 1);
        $hoja->setCellValueExplicit($colGuia . $i, (string) $fila[array_search('Guía', $enc, true)], $texto);
        $i++;
    }
    // El total del valor neto, como en el pie de la tabla.
    $hoja->setCellValue("A{$i}", 'Total (' . count($facturas) . ' facturas)');
    $hoja->setCellValue("H{$i}", count($facturas) ? "=SUM(H2:H" . ($i - 1) . ")" : 0);
    $hoja->getStyle("A{$i}:{$ultima}{$i}")->getFont()->setBold(true);

    $hoja->getStyle("H2:H{$i}")->getNumberFormat()->setFormatCode('"$"#,##0.00');
    $hoja->getStyle("A1:{$ultima}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $hoja->getStyle("A1:{$ultima}1")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
    $anchos = ['Factura' => 15, 'Pedido' => 13, 'Orden de compra' => 18, 'Fecha factura' => 13, 'Solicitud' => 11, 'Cliente' => 38, 'Ciudad' => 18,
               'Valor neto' => 16, 'Anulada' => 9, 'De contado' => 11, 'Guía' => 16, 'Transportador' => 16, 'Estado' => 24, 'Semáforo' => 12,
               'Fecha entrega' => 13, 'Responsable de empaque' => 26];
    foreach ($enc as $c => $nombre) {
        $hoja->getColumnDimensionByColumn($c + 1)->setWidth($anchos[$nombre] ?? 14);
    }
    $hoja->freezePane('A2');
    $hoja->setAutoFilter("A1:{$ultima}" . max(1, $i - 1));
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);
}
