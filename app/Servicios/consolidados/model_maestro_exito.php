<?php
// modules/consolidados/model_maestro_exito.php
// EXCEPCIONES DE ÉXITO del maestro (2026-09-23).
//
// POR QUÉ EXISTE
// El mismo producto (mismo SKU) a veces se empaca distinto y ocupa otro volumen según para quién
// sea el pedido: los del Éxito llevan un empaque/cubicaje y los demás clientes, otro. El maestro
// base (maestro_productos + cubicajes) guarda UN valor por SKU, así que no puede representar los
// dos a la vez. Acá se guardan SOLO las EXCEPCIONES del Éxito: los SKU cuyo empaque o cubicaje
// cambian para el Éxito. Todo lo que no tenga excepción usa el valor base.
//
// CÓMO SE USA
// Al resolver una línea de un pedido del Éxito (ver condicionPedidoExito()), primero se busca acá;
// si hay excepción para ese SKU se usan sus unidades por caja / cubicaje, y si no, el base. Las
// líneas de otros clientes usan SIEMPRE el base.

require_once __DIR__ . '/model_consolidados_import.php';   // leerPrimeraHoja(), mapearColumnas(), textoLimpio(), codigoLimpio(), numeroDesdeExcel(), normalizarEncabezado()

/**
 * Las excepciones de Éxito en memoria, indexadas por SKU: [sku => ['unidades_por_caja'=>?int,
 * 'cubicaje_m3'=>?float]]. Un campo en null significa "para este SKU, ese dato NO se pisa" (se usa
 * el base): así una excepción puede cambiar solo el empaque, solo el cubicaje, o los dos.
 */
function mapaMaestroExito($pdo) {
    $mapa = [];
    foreach ($pdo->query("SELECT sku, unidades_por_caja, cubicaje_m3 FROM maestro_exito") as $f) {
        $mapa[(string) $f['sku']] = [
            'unidades_por_caja' => $f['unidades_por_caja'] !== null ? (int) $f['unidades_por_caja'] : null,
            'cubicaje_m3'       => $f['cubicaje_m3'] !== null ? (float) $f['cubicaje_m3'] : null,
        ];
    }
    return $mapa;
}

/** Cuántas excepciones hay y cuándo se tocaron por última vez, para las pastillas de la pantalla. */
function resumenMaestroExito($pdo) {
    return $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(unidades_por_caja IS NOT NULL) AS con_unidades,
                SUM(cubicaje_m3 IS NOT NULL) AS con_cubicaje,
                MAX(fecha_actualizacion) AS actualizado
         FROM maestro_exito"
    )->fetch();
}

/**
 * La lista de excepciones para mostrar, con el valor BASE al lado (para ver la diferencia). Se cruza
 * con maestro_productos (empaque base) y cubicajes (cubicaje base) por SKU.
 */
function listaMaestroExito($pdo, $busqueda = '') {
    $sql = "SELECT e.sku, e.descripcion, e.unidades_por_caja, e.cubicaje_m3, e.fecha_actualizacion,
                   m.descripcion AS descripcion_base, m.unidades_por_caja AS unidades_base,
                   c.cubicaje_m3 AS cubicaje_base
            FROM maestro_exito e
            LEFT JOIN maestro_productos m ON m.sku = e.sku
            LEFT JOIN cubicajes c        ON c.sku = e.sku";
    $params = [];
    if (trim($busqueda) !== '') {
        $sql .= " WHERE e.sku LIKE :q_sku OR e.descripcion LIKE :q_desc OR m.descripcion LIKE :q_descm";
        $patron = '%' . trim($busqueda) . '%';
        $params = [':q_sku' => $patron, ':q_desc' => $patron, ':q_descm' => $patron];
    }
    $sql .= " ORDER BY COALESCE(e.descripcion, m.descripcion) IS NULL, COALESCE(e.descripcion, m.descripcion), e.sku";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Importa el Excel de excepciones de Éxito. Columnas: SKU (obligatoria) y, al menos una de,
 * "Unidades por caja" y "Cubicaje m³". Una fila sin ninguno de los dos valores no aporta nada y se
 * salta. Devuelve ['exito'=>bool, 'mensaje'=>string].
 */
function importarMaestroExito($pdo, $rutaArchivo) {
    $filas = leerPrimeraHoja($rutaArchivo);
    if (count($filas) < 2) {
        return ['exito' => false, 'mensaje' => 'El archivo no tiene filas de datos.'];
    }
    $encabezado = array_shift($filas);

    $mapa = mapearColumnas($encabezado, [
        'sku'                 => 'sku',
        'material'            => 'sku',
        'descripcion'         => 'descripcion',
        'denominacion'        => 'descripcion',
        'unidades por caja'   => 'unidades_por_caja',
        'unidades_por_caja'   => 'unidades_por_caja',
        'und por caja'        => 'unidades_por_caja',
        'empaque'             => 'unidades_por_caja',
        'unidades'            => 'unidades_por_caja',
        'cubicaje m3'         => 'cubicaje',
        'cubicaje m³'         => 'cubicaje',
        'cubicaje por caja'   => 'cubicaje',
        'cubicaje'            => 'cubicaje',
        'volumen'             => 'cubicaje',
    ]);

    if (!isset($mapa['sku'])) {
        return ['exito' => false, 'mensaje' => 'Al archivo le falta la columna "SKU": sin ella no se sabe a qué producto es la excepción.'];
    }
    if (!isset($mapa['unidades_por_caja']) && !isset($mapa['cubicaje'])) {
        return ['exito' => false, 'mensaje' => 'El archivo tiene que traer al menos una de "Unidades por caja" o "Cubicaje m³".'];
    }

    $valor = fn(array $fila, $campo) => isset($mapa[$campo]) ? ($fila[$mapa[$campo]] ?? null) : null;

    // Guardián del cubicaje (mismo criterio que importarCubicajes): si la columna de cubicaje trae
    // 1 m³ o más POR CAJA en la mayoría de las filas, no es un volumen (suele ser las unidades por
    // caja puestas en la columna equivocada). Se RECHAZA el archivo entero: un cubicaje malo no se
    // nota en pantalla pero descuadra los vehículos.
    if (isset($mapa['cubicaje'])) {
        $conDato = 0; $grandes = 0; $ejemplo = null;
        foreach ($filas as $fila) {
            $c = numeroDesdeExcel($valor($fila, 'cubicaje'), 7);
            if ($c === null || $c <= 0) { continue; }
            $conDato++;
            if ($c >= 1) { $grandes++; if ($ejemplo === null) { $ejemplo = ['sku' => textoLimpio($valor($fila, 'sku'), 30), 'valor' => $c]; } }
        }
        if ($conDato > 0 && $grandes > $conDato / 2) {
            return [
                'exito'   => false,
                'mensaje' => "La columna del cubicaje no trae metros cúbicos: {$grandes} de {$conDato} filas dicen 1 m³ o más POR CAJA"
                           . ($ejemplo ? " (por ejemplo, el SKU {$ejemplo['sku']} dice {$ejemplo['valor']})" : '')
                           . '. Una caja mide entre 0,006 y 0,08 m³. No se cargó nada: revisá el archivo.',
            ];
        }
    }

    $guardar = $pdo->prepare(
        "INSERT INTO maestro_exito (sku, descripcion, unidades_por_caja, cubicaje_m3)
         VALUES (:sku, :descripcion, :unidades, :cubicaje)
         ON DUPLICATE KEY UPDATE
             descripcion       = COALESCE(VALUES(descripcion), descripcion),
             unidades_por_caja = COALESCE(VALUES(unidades_por_caja), unidades_por_caja),
             cubicaje_m3       = COALESCE(VALUES(cubicaje_m3), cubicaje_m3)"
    );

    $nuevas = 0; $actualizadas = 0; $saltadas = 0;
    $pdo->beginTransaction();
    try {
        foreach ($filas as $fila) {
            $sku = codigoLimpio($valor($fila, 'sku'), 30);
            if ($sku === null) { continue; }

            $u = numeroDesdeExcel($valor($fila, 'unidades_por_caja'), 0);
            $unidades = ($u !== null && $u > 0) ? (int) round($u) : null;
            $c = numeroDesdeExcel($valor($fila, 'cubicaje'), 7);
            $cubicaje = ($c !== null && $c > 0) ? $c : null;

            if ($unidades === null && $cubicaje === null) { $saltadas++; continue; }   // fila sin dato útil

            $guardar->execute([
                ':sku'         => $sku,
                ':descripcion' => textoLimpio($valor($fila, 'descripcion'), 255),
                ':unidades'    => $unidades,
                ':cubicaje'    => $cubicaje,
            ]);
            match ($guardar->rowCount()) {
                1       => $nuevas++,
                2       => $actualizadas++,
                default => null,
            };
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error importando excepciones de Éxito: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error guardando las excepciones. No se cambió nada.'];
    }

    $partes = [];
    if ($nuevas)       { $partes[] = "{$nuevas} nueva(s)"; }
    if ($actualizadas) { $partes[] = "{$actualizadas} actualizada(s)"; }
    if ($saltadas)     { $partes[] = "{$saltadas} sin datos (saltada/s)"; }

    return [
        'exito'   => true,
        'mensaje' => 'Excepciones de Éxito: ' . (implode(', ', $partes) ?: 'ninguna') . '.',
    ];
}

/** Borra una excepción por SKU. Devuelve true si borró algo. */
function eliminarExcepcionExito($pdo, $sku) {
    $stmt = $pdo->prepare("DELETE FROM maestro_exito WHERE sku = ?");
    $stmt->execute([(string) $sku]);
    return $stmt->rowCount() > 0;
}

/** Borra varias excepciones por SKU. Devuelve cuántas borró. */
function eliminarExcepcionesExito($pdo, array $skus) {
    $skus = array_values(array_unique(array_filter(array_map(fn($s) => trim((string) $s), $skus), fn($s) => $s !== '')));
    if (!$skus) { return 0; }
    $marcas = implode(',', array_fill(0, count($skus), '?'));
    $stmt = $pdo->prepare("DELETE FROM maestro_exito WHERE sku IN ($marcas)");
    $stmt->execute($skus);
    return $stmt->rowCount();
}
