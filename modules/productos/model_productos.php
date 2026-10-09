<?php
// modules/productos/model_productos.php
// ADMINISTRAR PRODUCTOS (2026-10-06): el módulo del sistema de bodega, adaptado a Monterojo.
//
// Un PRODUCTO es algo físico de la bodega: un SKU con su lote, su fecha de vencimiento y su estado
// (Disponible, Bloqueado, En Control De Calidad, Defectuoso). Cada estiba de Posiciones apunta a
// uno; los que no están en ninguna posición salen "Sin ubicar".
//
// LO QUE CAMBIA RESPECTO DE BODEGA:
//  · El catálogo de SKU es el MAESTRO DE PRODUCTOS de Monterojo (más la lista de Monterojo de la
//    pestaña INVENTARIO del Excel PEDIDOS MONTEROJO). En bodega había un catálogo aparte con su
//    propio "Agregar SKU"; acá ya existía el maestro y se usa ese.
//  · El semáforo de vencimiento va con plazos de Monterojo (snacks, vida útil de meses): rojo
//    vencido, naranja hasta 30 días, amarillo hasta 90 días, verde más de 90. En bodega el
//    amarillo era de 6 meses a 1 año.
//  · No hay Tienda: la ubicación es solo la posición de la bodega.
//
// El KARDEX (tabla movimientos) son las entradas y salidas de estibas que registra Posiciones.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados_import.php';   // textoLimpio()

const PRODUCTOS_POR_PAGINA = 50;

// Los estados que se eligen a mano (en bodega: obtenerEstadosProducto()).
const PRODUCTOS_ESTADOS = ['Disponible', 'Bloqueado', 'En Control De Calidad', 'Defectuoso'];

// EL SEMÁFORO DE VENCIMIENTO: [clave => [texto, días hasta (incluido), clase]]. En orden.
const PRODUCTOS_SEMAFORO = [
    'vencido'    => ['Vencido', -1, 'semaforo-rojo'],
    'por_vencer' => ['Vence en 30 días o menos', 30, 'semaforo-naranja'],
    'proximo'    => ['Vence en 31 a 90 días', 90, 'semaforo-amarillo'],
    'vigente'    => ['Más de 90 días', PHP_INT_MAX, 'semaforo-verde'],
];

/** La clave del semáforo para los días que faltan (null si no tiene fecha). */
function semaforoVencimiento($dias) {
    if ($dias === null || $dias === '') {
        return null;
    }
    foreach (PRODUCTOS_SEMAFORO as $clave => [, $hasta]) {
        if ((int) $dias <= $hasta) {
            return $clave;
        }
    }
    return 'vigente';
}

/** El SQL del semáforo (para filtrar y contar), sobre la fecha de vencimiento de p. */
function sqlSemaforoProductos($alias = 'p') {
    return "CASE WHEN {$alias}.fecha_vencimiento IS NULL THEN NULL
                 WHEN {$alias}.fecha_vencimiento < CURDATE() THEN 'vencido'
                 WHEN DATEDIFF({$alias}.fecha_vencimiento, CURDATE()) <= 30 THEN 'por_vencer'
                 WHEN DATEDIFF({$alias}.fecha_vencimiento, CURDATE()) <= 90 THEN 'proximo'
                 ELSE 'vigente' END";
}

/**
 * El catálogo de SKU: el maestro de productos y la lista de Monterojo, [sku => descripción].
 * Los SKU se comparan siempre por código, nunca por el nombre (se repiten en distinto gramaje).
 */
function catalogoSkuProductos($pdo) {
    $c = [];
    foreach ($pdo->query("SELECT material, texto FROM pedidos_productos") as $f) {
        $c[(string) $f['material']] = $f['texto'];
    }
    foreach ($pdo->query("SELECT sku, descripcion FROM maestro_productos") as $f) {
        if (!isset($c[(string) $f['sku']]) || ($c[(string) $f['sku']] === null && $f['descripcion'] !== null)) {
            $c[(string) $f['sku']] = $f['descripcion'];
        }
    }
    ksort($c, SORT_NATURAL);
    return $c;
}

/** Las unidades por caja de un SKU según el maestro de productos, o null. */
function unidadesPorCajaSku($pdo, $sku) {
    $st = $pdo->prepare("SELECT unidades_por_caja FROM maestro_productos WHERE sku = ?");
    $st->execute([(string) $sku]);
    $u = (int) $st->fetchColumn();
    return $u > 0 ? $u : null;
}

const PRODUCTOS_SQL_BASE = "FROM productos p
                            LEFT JOIN posiciones_estibas e ON e.id_producto = p.id
                            LEFT JOIN posiciones pos ON pos.id_posicion = e.id_posicion
                            LEFT JOIN usuarios u ON u.id_usuario = p.id_usuario_registro";

/** El WHERE de los filtros de la pantalla: [sql, parámetros]. */
function condicionesProductos(array $f, $conSemaforo = true) {
    $w = []; $p = [];
    if (($f['buscar'] ?? '') !== '') {
        $o = [];
        foreach (array_filter(array_map('trim', explode(',', $f['buscar']))) as $t) {
            $o[] = "(p.sku LIKE ? OR p.producto LIKE ? OR p.lote LIKE ? OR pos.ubicacion LIKE ?)";
            array_push($p, "%{$t}%", "%{$t}%", "%{$t}%", "%{$t}%");
        }
        if ($o) {
            $w[] = '(' . implode(' OR ', $o) . ')';
        }
    }
    if (in_array($f['estado'] ?? '', PRODUCTOS_ESTADOS, true)) {
        $w[] = 'p.estado = ?';
        $p[] = $f['estado'];
    }
    if (($f['ubicacion'] ?? '') === 'ubicado') {
        $w[] = 'e.id_estiba IS NOT NULL';
    } elseif (($f['ubicacion'] ?? '') === 'sin_ubicar') {
        $w[] = 'e.id_estiba IS NULL';
    }
    if ($conSemaforo && isset(PRODUCTOS_SEMAFORO[$f['semaforo'] ?? ''])) {
        $w[] = '(' . sqlSemaforoProductos() . ') = ?';
        $p[] = $f['semaforo'];
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

const PRODUCTOS_SQL_CAMPOS = "p.*, DATEDIFF(p.fecha_vencimiento, CURDATE()) AS dias_restantes, e.id_estiba, e.cantidad_cajas,
                              e.estiba_completa, pos.id_posicion, pos.ubicacion, u.nombre_usuario AS registrado_por";

/** Una página de productos: ['filas', 'total', 'pagina', 'total_paginas']. Primero los que vencen antes. */
function paginaProductos($pdo, array $filtros, $pagina = 1, $porPagina = PRODUCTOS_POR_PAGINA) {
    [$where, $p] = condicionesProductos($filtros);
    $st = $pdo->prepare("SELECT COUNT(*) " . PRODUCTOS_SQL_BASE . " {$where}");
    $st->execute($p);
    $total = (int) $st->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, (int) $pagina), $totalPaginas);
    $st = $pdo->prepare("SELECT " . PRODUCTOS_SQL_CAMPOS . " " . PRODUCTOS_SQL_BASE . " {$where}
        ORDER BY (p.fecha_vencimiento IS NULL), p.fecha_vencimiento, p.sku, p.id
        LIMIT " . (int) $porPagina . " OFFSET " . (($pagina - 1) * $porPagina));
    $st->execute($p);
    return ['filas' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pagina' => $pagina, 'total_paginas' => $totalPaginas];
}

/** Los números de las pastillas (con la búsqueda, el estado y la ubicación; sin el semáforo). */
function resumenProductos($pdo, array $filtros = []) {
    [$where, $p] = condicionesProductos($filtros, false);
    $st = $pdo->prepare("SELECT " . sqlSemaforoProductos() . " AS semaforo, (e.id_estiba IS NOT NULL) AS ubicado, COUNT(*) AS n
                         " . PRODUCTOS_SQL_BASE . " {$where} GROUP BY 1, 2");
    $st->execute($p);
    $r = ['total' => 0, 'ubicados' => 0, 'sin_ubicar' => 0, 'sin_fecha' => 0] + array_fill_keys(array_keys(PRODUCTOS_SEMAFORO), 0);
    foreach ($st as $f) {
        $n = (int) $f['n'];
        $r['total'] += $n;
        $r[$f['ubicado'] ? 'ubicados' : 'sin_ubicar'] += $n;
        $r[$f['semaforo'] ?? 'sin_fecha'] += $n;
    }
    return $r;
}

/** Un producto con su ubicación, o null. */
function productoPorId($pdo, $id) {
    $st = $pdo->prepare("SELECT " . PRODUCTOS_SQL_CAMPOS . " " . PRODUCTOS_SQL_BASE . " WHERE p.id = ?");
    $st->execute([(int) $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Los datos del formulario, revisados: [campos] o un mensaje de error. */
function productoDelFormulario($pdo, array $d, $venceObligatorio = false) {
    $sku = trim((string) ($d['sku'] ?? ''));
    $lote = textoLimpio($d['lote'] ?? null, 60);
    $vence = trim((string) ($d['fecha_vencimiento'] ?? ''));
    $estado = in_array($d['estado'] ?? '', PRODUCTOS_ESTADOS, true) ? $d['estado'] : 'Disponible';
    if ($sku === '' || $lote === null || ($venceObligatorio && $vence === '')) {
        return $venceObligatorio ? 'Hacen falta el SKU, el lote y la fecha de vencimiento.' : 'Hacen falta el SKU y el lote.';
    }
    if ($vence !== '') {
        $fecha = DateTime::createFromFormat('!Y-m-d', $vence);
        if (!$fecha || $fecha->format('Y-m-d') !== $vence) {
            return 'La fecha de vencimiento no es válida.';
        }
    }
    $catalogo = catalogoSkuProductos($pdo);
    if (!array_key_exists($sku, $catalogo)) {
        return "El SKU {$sku} no está en el maestro de productos ni en la lista de Monterojo. Revisá el código o cargalo en el Maestro de productos.";
    }
    return ['sku' => $sku, 'producto' => $catalogo[$sku], 'lote' => $lote, 'fecha_vencimiento' => $vence !== '' ? $vence : null, 'estado' => $estado];
}

/** "SKU 36305, lote 3025626, vence 24/08/2027". */
function describirProducto(array $p) {
    $partes = ['SKU ' . $p['sku']];
    if (!empty($p['lote'])) {
        $partes[] = 'lote ' . $p['lote'];
    }
    if (!empty($p['fecha_vencimiento'])) {
        $partes[] = 'vence ' . date('d/m/Y', strtotime($p['fecha_vencimiento']));
    }
    return implode(', ', $partes);
}

/** Registra un producto (sin ubicar). Devuelve ['exito', 'mensaje', 'id' y 'datos' si salió]. */
function crearProducto($pdo, array $d, $idUsuario) {
    $p = productoDelFormulario($pdo, $d);
    if (is_string($p)) {
        return ['exito' => false, 'mensaje' => $p];
    }
    $pdo->prepare("INSERT INTO productos (sku, producto, lote, fecha_vencimiento, estado, origen, id_usuario_registro)
                   VALUES (?, ?, ?, ?, ?, 'administrar_productos', ?)")
        ->execute([$p['sku'], $p['producto'], $p['lote'], $p['fecha_vencimiento'], $p['estado'], $idUsuario]);
    return ['exito' => true, 'mensaje' => 'Producto registrado: ' . describirProducto($p) . ' (' . $p['estado'] . ').',
            'id' => (int) $pdo->lastInsertId(), 'datos' => $p];
}

/** Edita un producto. Si está en una posición, la tarjeta de la posición cambia con él. */
function actualizarProducto($pdo, $id, array $d) {
    $actual = productoPorId($pdo, $id);
    if (!$actual) {
        return ['exito' => false, 'mensaje' => 'Ese producto ya no existe.'];
    }
    $p = productoDelFormulario($pdo, $d, (bool) $actual['id_estiba']);
    if (is_string($p)) {
        return ['exito' => false, 'mensaje' => $p];
    }
    $pdo->prepare("UPDATE productos SET sku = ?, producto = ?, lote = ?, fecha_vencimiento = ?, estado = ?, actualizado_el = NOW() WHERE id = ?")
        ->execute([$p['sku'], $p['producto'], $p['lote'], $p['fecha_vencimiento'], $p['estado'], (int) $id]);
    return ['exito' => true, 'mensaje' => 'Producto actualizado: ' . describirProducto($p) . ' (' . $p['estado'] . ')'
        . ($actual['ubicacion'] ? ', en la posición ' . $actual['ubicacion'] : '') . '.'];
}

/** Elimina un producto sin ubicar (si está en una posición, primero hay que sacar la estiba). */
function eliminarProducto($pdo, $id) {
    $actual = productoPorId($pdo, $id);
    if (!$actual) {
        return ['exito' => false, 'mensaje' => 'Ese producto ya no existe.'];
    }
    if ($actual['id_estiba']) {
        return ['exito' => false, 'mensaje' => "Ese producto está en la posición {$actual['ubicacion']}: sacá la estiba (o llevala a picking) desde Posiciones."];
    }
    $pdo->prepare("DELETE FROM productos WHERE id = ?")->execute([(int) $id]);
    return ['exito' => true, 'mensaje' => 'Producto eliminado: ' . describirProducto($actual) . '.'];
}

/** Anota un movimiento del Kardex (Ingreso, Salida o Picking). Nunca rompe la acción que lo genera. */
function registrarMovimiento($pdo, $tipo, array $pos, array $prod, $cajas, $completa, $idUsuario, $observaciones = null) {
    try {
        $pdo->prepare("INSERT INTO movimientos (id_usuario, tipo, id_posicion, ubicacion, id_producto, sku, lote, fecha_vencimiento,
                                                cantidad_cajas, estiba_completa, observaciones)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$idUsuario, $tipo, $pos['id_posicion'] ?? null, $pos['ubicacion'] ?? null, $prod['id'] ?? null,
                       $prod['sku'], $prod['lote'] ?? null, $prod['fecha_vencimiento'] ?? null,
                       $completa ? null : ($cajas ?: null), $completa ? 1 : 0, $observaciones]);
    } catch (Throwable $e) {
        error_log('No se pudo registrar el movimiento: ' . $e->getMessage());
    }
}

/** El Kardex de un SKU, del más nuevo al más viejo, entre dos fechas (Y-m-d, opcionales). */
function kardexPorSku($pdo, $sku, $desde = null, $hasta = null) {
    $w = ['m.sku = ?']; $p = [(string) $sku];
    if ($desde) { $w[] = 'm.fecha_hora >= ?'; $p[] = $desde . ' 00:00:00'; }
    if ($hasta) { $w[] = 'm.fecha_hora < ?'; $p[] = date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'; }
    $st = $pdo->prepare("SELECT m.*, u.nombre_usuario FROM movimientos m LEFT JOIN usuarios u ON u.id_usuario = m.id_usuario
                         WHERE " . implode(' AND ', $w) . " ORDER BY m.fecha_hora DESC, m.id DESC LIMIT 500");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Escribe el Excel de todos los productos en $ruta. */
function escribirExcelProductos($pdo, $ruta) {
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Productos');
    $enc = ['SKU', 'Producto', 'Lote', 'Vence', 'Días', 'Semáforo', 'Estado', 'Posición', 'Cajas', 'Registrado por', 'Registrado el'];
    $hoja->fromArray($enc, null, 'A1');
    $filas = $pdo->query("SELECT " . PRODUCTOS_SQL_CAMPOS . " " . PRODUCTOS_SQL_BASE . "
                          ORDER BY (p.fecha_vencimiento IS NULL), p.fecha_vencimiento, p.sku, p.id")->fetchAll(PDO::FETCH_ASSOC);
    $i = 2;
    foreach ($filas as $p) {
        $sem = semaforoVencimiento($p['dias_restantes']);
        $hoja->setCellValueExplicit("A{$i}", (string) $p['sku'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja->setCellValue("B{$i}", (string) $p['producto']);
        $hoja->setCellValueExplicit("C{$i}", (string) $p['lote'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja->setCellValue("D{$i}", $p['fecha_vencimiento'] ? date('d/m/Y', strtotime($p['fecha_vencimiento'])) : '');
        $hoja->setCellValue("E{$i}", $p['dias_restantes'] === null ? '' : (int) $p['dias_restantes']);
        $hoja->setCellValue("F{$i}", $sem ? PRODUCTOS_SEMAFORO[$sem][0] : 'Sin fecha');
        $hoja->setCellValue("G{$i}", $p['estado']);
        $hoja->setCellValue("H{$i}", $p['ubicacion'] ?: 'Sin ubicar');
        $hoja->setCellValue("I{$i}", $p['id_estiba'] ? ($p['estiba_completa'] ? 'Estiba completa' : ($p['cantidad_cajas'] ?: '')) : '');
        $hoja->setCellValue("J{$i}", (string) $p['registrado_por']);
        $hoja->setCellValue("K{$i}", $p['creado_el'] ? date('d/m/Y H:i', strtotime($p['creado_el'])) : '');
        $i++;
    }
    $hoja->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $hoja->getStyle('A1:K1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
    foreach ([12, 42, 14, 12, 7, 24, 20, 13, 15, 16, 16] as $c => $ancho) {
        $hoja->getColumnDimensionByColumn($c + 1)->setWidth($ancho);
    }
    $hoja->freezePane('A2');
    $hoja->setAutoFilter('A1:K' . max(1, $i - 1));
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);
}
