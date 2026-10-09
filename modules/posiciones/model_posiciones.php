<?php
// modules/posiciones/model_posiciones.php
// POSICIONES DE BODEGA (2026-10-06): el módulo de posiciones del sistema de bodega, traído a
// Monterojo con la estructura de sus racks.
//
// CÓMO SE NOMBRA CADA POSICIÓN: R1M1N1A1 = Rack 1, Módulo 1, Nivel 1, posición A1.
//  · 8 racks; cada rack tiene 12 módulos.
//  · Los módulos 1 a 11 tienen 5 niveles (N1 a N5). El módulo 12 tiene solo 3, y son los de
//    ARRIBA: N3, N4 y N5 (así está en la hoja que pasó el usuario: 1M12N3A1 … 1M12N5B2).
//  · Cada nivel tiene 4 posiciones: A1, A2, B1 y B2.
//  · Total: (11 × 5 + 3) × 4 = 232 por rack, 1.856 en los 8 racks.
//
// CADA POSICIÓN guarda a lo sumo UNA estiba (como en bodega): las cajas o "estiba completa" y
// observaciones. El SKU, el lote, el vencimiento y el estado son de su PRODUCTO (Administrar
// Productos, 2026-10-06): la estiba apunta a uno, y editarlo en cualquiera de los dos lados cambia
// el mismo dato.
//
// COMO EN BODEGA (desde que existen Productos, Formato Conciliador y Kardex, 2026-10-06):
//  · SIN el cupo de bodega (2026-10-06, pedido del usuario): una estiba se ubica aunque no esté en
//    el Formato Conciliador; lo registrado y lo ubicado se muestra en el modal solo como información.
//  · Al ubicarla se aprovecha el producto que alguien ya registró a mano en Administrar Productos
//    y todavía no está en ninguna posición; si no hay, se crea uno.
//  · Cada entrada y salida queda en el Kardex (movimientos).
//  · "Sacar estiba" deja el producto "Sin ubicar"; "Llevar a picking" lo saca también de Productos.
//  · El SKU tiene que estar en el maestro de productos o en la lista de Monterojo (la pestaña
//    INVENTARIO del Excel PEDIDOS MONTEROJO); de ahí sale el nombre del producto.
//  · "Producto Vencido" se calcula solo, con la fecha de vencimiento contra hoy.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados_import.php';   // leerXlsxRapido(), leerPrimeraHoja(), textoLimpio()
require_once __DIR__ . '/../formato_conciliador/model_formato_conciliador.php';   // cupoConciliador() (informativo) y, con él, Productos

const POSICIONES_POR_PAGINA = 48;   // 12 filas de 4 tarjetas

// La estructura de los racks.
const POSICIONES_RACKS   = 8;
const POSICIONES_MODULOS = 12;
const POSICIONES_NIVELES = 5;
const POSICIONES_LUGARES = ['A1', 'A2', 'B1', 'B2'];
// Los módulos que no tienen los 5 niveles: [módulo => sus niveles].
const POSICIONES_NIVELES_ESPECIALES = [12 => [3, 4, 5]];

// Los estados que se eligen (son los del producto), en orden de gravedad creciente.
const POSICIONES_ESTADOS = PRODUCTOS_ESTADOS;
// El que se calcula solo (vencimiento < hoy) y pisa a todos.
const POSICIONES_VENCIDO = 'Producto Vencido';

/** Los niveles de un módulo. */
function nivelesDelModuloPosiciones($modulo) {
    return POSICIONES_NIVELES_ESPECIALES[(int) $modulo] ?? range(1, POSICIONES_NIVELES);
}

/** El código de una posición: R1M1N1A1. */
function codigoPosicion($rack, $modulo, $nivel, $lugar) {
    return 'R' . (int) $rack . 'M' . (int) $modulo . 'N' . (int) $nivel . strtoupper($lugar);
}

/**
 * Lee un código ("R1M1N1A1", también "r1 m1 n1 a1" o "1M1N1A1", como venía escrito a mano) y lo
 * devuelve como [rack, modulo, nivel, lugar], o null si no es una posición que exista en la bodega.
 */
function leerCodigoPosicion($texto) {
    $t = strtoupper(preg_replace('/[\s\-_.]+/', '', (string) $texto));
    if (!preg_match('/^R?(\d{1,2})M(\d{1,2})N(\d)([AB][12])$/', $t, $m)) {
        return null;
    }
    [$rack, $modulo, $nivel, $lugar] = [(int) $m[1], (int) $m[2], (int) $m[3], $m[4]];
    if ($rack < 1 || $rack > POSICIONES_RACKS || $modulo < 1 || $modulo > POSICIONES_MODULOS
        || !in_array($nivel, nivelesDelModuloPosiciones($modulo), true)) {
        return null;
    }
    return [$rack, $modulo, $nivel, $lugar];
}

/** Todas las posiciones de la bodega, en orden: [[rack, modulo, nivel, lugar, codigo], …] (1.856). */
function estructuraPosiciones() {
    $todas = [];
    for ($r = 1; $r <= POSICIONES_RACKS; $r++) {
        for ($m = 1; $m <= POSICIONES_MODULOS; $m++) {
            foreach (nivelesDelModuloPosiciones($m) as $n) {
                foreach (POSICIONES_LUGARES as $l) {
                    $todas[] = [$r, $m, $n, $l, codigoPosicion($r, $m, $n, $l)];
                }
            }
        }
    }
    return $todas;
}

/**
 * Crea las posiciones de la estructura que falten (las que ya están no se tocan). La usan el
 * script del esquema y la primera carga. Devuelve cuántas creó.
 */
function generarPosicionesBodega($pdo) {
    $st = $pdo->prepare("INSERT IGNORE INTO posiciones (ubicacion, rack, modulo, nivel, lugar) VALUES (?, ?, ?, ?, ?)");
    $creadas = 0;
    $pdo->beginTransaction();
    try {
        foreach (estructuraPosiciones() as [$r, $m, $n, $l, $codigo]) {
            $st->execute([$codigo, $r, $m, $n, $l]);
            $creadas += $st->rowCount();
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error generando las posiciones: ' . $e->getMessage());
        return 0;
    }
    return $creadas;
}

/** El slug de un estado ("En Control De Calidad" → "en-control-de-calidad"): clase CSS y valor del filtro. */
function slugEstadoPosicion($estado) {
    return strtolower(str_replace(' ', '-', $estado));
}

/** Los estados que puede mostrar una tarjeta: [slug => etiqueta]. */
function estadosGrillaPosiciones() {
    $mapa = [];
    foreach (array_merge(POSICIONES_ESTADOS, [POSICIONES_VENCIDO]) as $e) {
        $mapa[slugEstadoPosicion($e)] = $e;
    }
    return $mapa;
}

// El estado que se ve: vencido si la fecha ya pasó; si no, el del producto.
const POSICIONES_SQL_ESTADO = "CASE WHEN e.id_estiba IS NULL THEN NULL
                                    WHEN p.fecha_vencimiento IS NOT NULL AND p.fecha_vencimiento < CURDATE() THEN 'Producto Vencido'
                                    ELSE p.estado END";

/**
 * El WHERE de los filtros: 'rack', 'modulo', 'nivel' (números o ''), 'buscar' (ubicación, SKU,
 * producto o lote; varios con coma) y 'estado' ('', 'libre', 'ocupada' o el slug de un estado).
 * Devuelve [sql, parámetros].
 */
function condicionesPosiciones(array $f, $conEstado = true) {
    $w = []; $p = [];
    foreach (['rack', 'modulo', 'nivel'] as $c) {
        if (($f[$c] ?? '') !== '') {
            $w[] = "pos.{$c} = ?";
            $p[] = (int) $f[$c];
        }
    }
    if (($f['buscar'] ?? '') !== '') {
        $o = [];
        foreach (array_filter(array_map('trim', explode(',', $f['buscar']))) as $t) {
            $o[] = "(pos.ubicacion LIKE ? OR p.sku LIKE ? OR p.producto LIKE ? OR p.lote LIKE ?)";
            array_push($p, "%{$t}%", "%{$t}%", "%{$t}%", "%{$t}%");
        }
        if ($o) {
            $w[] = '(' . implode(' OR ', $o) . ')';
        }
    }
    $estado = $conEstado ? ($f['estado'] ?? '') : '';
    $estados = estadosGrillaPosiciones();
    if ($estado === 'libre') {
        $w[] = 'e.id_estiba IS NULL';
    } elseif ($estado === 'ocupada') {
        $w[] = 'e.id_estiba IS NOT NULL';
    } elseif (isset($estados[$estado])) {
        $w[] = '(' . POSICIONES_SQL_ESTADO . ') = ?';
        $p[] = $estados[$estado];
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

const POSICIONES_SQL_BASE = "FROM posiciones pos
                             LEFT JOIN posiciones_estibas e ON e.id_posicion = pos.id_posicion
                             LEFT JOIN productos p ON p.id = e.id_producto
                             LEFT JOIN usuarios ur ON ur.id_usuario = e.id_usuario_registro
                             LEFT JOIN usuarios ue ON ue.id_usuario = e.id_usuario_edicion";

const POSICIONES_SQL_CAMPOS = "pos.*, e.id_estiba, e.id_producto, p.sku, p.producto, e.cantidad_cajas, e.estiba_completa, p.lote,
                               p.fecha_vencimiento, p.estado AS estado_elegido, e.observaciones, e.creado_el AS estiba_creada,
                               e.actualizado_el AS estiba_editada, ur.nombre_usuario AS registrado_por, ue.nombre_usuario AS editado_por";

/** Una página de posiciones con su estiba: ['filas', 'total', 'pagina', 'total_paginas']. */
function paginaPosiciones($pdo, array $filtros, $pagina = 1, $porPagina = POSICIONES_POR_PAGINA) {
    [$where, $p] = condicionesPosiciones($filtros);
    $st = $pdo->prepare("SELECT COUNT(*) " . POSICIONES_SQL_BASE . " {$where}");
    $st->execute($p);
    $total = (int) $st->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, (int) $pagina), $totalPaginas);
    $st = $pdo->prepare("SELECT " . POSICIONES_SQL_CAMPOS . ", " . POSICIONES_SQL_ESTADO . " AS estado_producto "
        . POSICIONES_SQL_BASE . " {$where} ORDER BY pos.rack, pos.modulo, pos.nivel, pos.lugar, pos.id_posicion
        LIMIT " . (int) $porPagina . " OFFSET " . (($pagina - 1) * $porPagina));
    $st->execute($p);
    return ['filas' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pagina' => $pagina, 'total_paginas' => $totalPaginas];
}

/**
 * Los números de las pastillas: total, libres, ocupadas, por estado y las cajas/estibas, todo con
 * los filtros de rack, módulo, nivel y búsqueda (no con el de estado: las pastillas son ese filtro).
 */
function estadisticasPosiciones($pdo, array $filtros = []) {
    [$where, $p] = condicionesPosiciones($filtros, false);
    $st = $pdo->prepare("SELECT " . POSICIONES_SQL_ESTADO . " AS estado, COUNT(*) AS n,
                                SUM(CASE WHEN e.estiba_completa = 0 THEN COALESCE(e.cantidad_cajas, 0) ELSE 0 END) AS cajas,
                                SUM(CASE WHEN e.estiba_completa = 1 THEN 1 ELSE 0 END) AS estibas
                         " . POSICIONES_SQL_BASE . " {$where} GROUP BY 1");
    $st->execute($p);
    $r = ['total' => 0, 'libres' => 0, 'ocupadas' => 0, 'cajas' => 0, 'estibas_completas' => 0,
          'por_estado' => array_fill_keys(array_keys(estadosGrillaPosiciones()), 0)];
    foreach ($st as $f) {
        $n = (int) $f['n'];
        $r['total'] += $n;
        if ($f['estado'] === null) {
            $r['libres'] += $n;
            continue;
        }
        $r['ocupadas'] += $n;
        $r['cajas'] += (int) $f['cajas'];
        $r['estibas_completas'] += (int) $f['estibas'];
        $slug = slugEstadoPosicion($f['estado']);
        if (isset($r['por_estado'][$slug])) {
            $r['por_estado'][$slug] += $n;
        }
    }
    $r['pct_libres']   = $r['total'] ? $r['libres'] / $r['total'] * 100 : 0;
    $r['pct_ocupadas'] = $r['total'] ? $r['ocupadas'] / $r['total'] * 100 : 0;
    return $r;
}

/** Una posición con su estiba, o null. */
function posicionPorId($pdo, $id) {
    $st = $pdo->prepare("SELECT " . POSICIONES_SQL_CAMPOS . ", " . POSICIONES_SQL_ESTADO . " AS estado_producto "
        . POSICIONES_SQL_BASE . " WHERE pos.id_posicion = ?");
    $st->execute([(int) $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** El catálogo de SKU (el de Administrar Productos: maestro de productos + lista de Monterojo). */
function catalogoSkuPosiciones($pdo) {
    return catalogoSkuProductos($pdo);
}

/** ¿Existe otra posición con ese código? */
function existePosicion($pdo, $ubicacion, $excluir = null) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM posiciones WHERE ubicacion = ? AND id_posicion <> ?");
    $st->execute([$ubicacion, (int) $excluir]);
    return (int) $st->fetchColumn() > 0;
}

/** Los datos de una posición del formulario: [rack, modulo, nivel, lugar, codigo] o un mensaje de error. */
function posicionDelFormulario(array $d) {
    $rack = (int) ($d['rack'] ?? 0); $modulo = (int) ($d['modulo'] ?? 0); $nivel = (int) ($d['nivel'] ?? 0);
    $lugar = strtoupper(trim((string) ($d['lugar'] ?? '')));
    if ($rack < 1 || $rack > POSICIONES_RACKS || $modulo < 1 || $modulo > POSICIONES_MODULOS
        || $nivel < 1 || $nivel > POSICIONES_NIVELES || !in_array($lugar, POSICIONES_LUGARES, true)) {
        return 'Elegí el rack, el módulo, el nivel y la posición.';
    }
    if (!in_array($nivel, nivelesDelModuloPosiciones($modulo), true)) {
        return 'El módulo ' . $modulo . ' solo tiene los niveles ' . implode(', ', nivelesDelModuloPosiciones($modulo)) . '.';
    }
    return [$rack, $modulo, $nivel, $lugar, codigoPosicion($rack, $modulo, $nivel, $lugar)];
}

/** Crea una posición. Devuelve ['exito', 'mensaje']. */
function crearPosicion($pdo, array $d) {
    $p = posicionDelFormulario($d);
    if (is_string($p)) {
        return ['exito' => false, 'mensaje' => $p];
    }
    if (existePosicion($pdo, $p[4])) {
        return ['exito' => false, 'mensaje' => "La posición {$p[4]} ya existe."];
    }
    $pdo->prepare("INSERT INTO posiciones (ubicacion, rack, modulo, nivel, lugar) VALUES (?, ?, ?, ?, ?)")
        ->execute([$p[4], $p[0], $p[1], $p[2], $p[3]]);
    return ['exito' => true, 'mensaje' => "Se creó la posición {$p[4]}."];
}

/** Cambia el rack, módulo, nivel o lugar de una posición (la estiba, si tiene, se queda con ella). */
function actualizarPosicion($pdo, $id, array $d) {
    $actual = posicionPorId($pdo, $id);
    if (!$actual) {
        return ['exito' => false, 'mensaje' => 'Esa posición ya no existe.'];
    }
    $p = posicionDelFormulario($d);
    if (is_string($p)) {
        return ['exito' => false, 'mensaje' => $p];
    }
    if (existePosicion($pdo, $p[4], $id)) {
        return ['exito' => false, 'mensaje' => "Ya existe otra posición {$p[4]}."];
    }
    $pdo->prepare("UPDATE posiciones SET ubicacion = ?, rack = ?, modulo = ?, nivel = ?, lugar = ? WHERE id_posicion = ?")
        ->execute([$p[4], $p[0], $p[1], $p[2], $p[3], (int) $id]);
    return ['exito' => true, 'mensaje' => $actual['ubicacion'] === $p[4]
        ? "La posición {$p[4]} quedó igual." : "La posición {$actual['ubicacion']} ahora es {$p[4]}."];
}

/** Elimina una posición vacía (con una estiba encima no se puede: primero hay que sacarla). */
function eliminarPosicion($pdo, $id) {
    $actual = posicionPorId($pdo, $id);
    if (!$actual) {
        return ['exito' => false, 'mensaje' => 'Esa posición ya no existe.'];
    }
    if ($actual['id_estiba']) {
        return ['exito' => false, 'mensaje' => "La posición {$actual['ubicacion']} tiene una estiba: sacala primero."];
    }
    $pdo->prepare("DELETE FROM posiciones WHERE id_posicion = ?")->execute([(int) $id]);
    return ['exito' => true, 'mensaje' => "Se eliminó la posición {$actual['ubicacion']}."];
}

/**
 * Los datos de la estiba del formulario, revisados: ['producto' => [sku, producto, lote,
 * fecha_vencimiento, estado], 'cantidad_cajas', 'estiba_completa', 'observaciones'] o un mensaje
 * de error. Como en bodega: SKU, lote y vencimiento obligatorios; cajas O estiba completa.
 */
function estibaDelFormulario($pdo, array $d) {
    $producto = productoDelFormulario($pdo, $d, true);
    if (is_string($producto)) {
        return 'Para guardar una estiba: ' . lcfirst($producto);
    }
    $completa = !empty($d['estiba_completa']) ? 1 : 0;
    $cajas = $completa ? null : ((int) ($d['cantidad_cajas'] ?? 0) ?: null);
    if ($cajas !== null && ($cajas < 1 || $cajas > 99999)) {
        return 'La cantidad de cajas tiene que estar entre 1 y 99.999.';
    }
    return ['producto' => $producto, 'cantidad_cajas' => $cajas, 'estiba_completa' => $completa,
            'observaciones' => textoLimpio($d['observaciones'] ?? null, 500)];
}

/**
 * Pone una estiba en una posición libre. Aprovecha el producto sin ubicar que ya exista (SKU +
 * lote + vencimiento); si no hay, lo crea.
 */
function agregarEstibaPosicion($pdo, $id, array $d, $idUsuario) {
    $pos = posicionPorId($pdo, $id);
    if (!$pos) {
        return ['exito' => false, 'mensaje' => 'Esa posición ya no existe.'];
    }
    if ($pos['id_estiba']) {
        return ['exito' => false, 'mensaje' => "La posición {$pos['ubicacion']} ya tiene una estiba (SKU {$pos['sku']})."];
    }
    $e = estibaDelFormulario($pdo, $d);
    if (is_string($e)) {
        return ['exito' => false, 'mensaje' => $e];
    }
    $p = $e['producto'];
    $pdo->beginTransaction();
    try {
        // El producto que ya se registró a mano y no está en ninguna posición (el más viejo).
        // FOR UPDATE: dos personas ubicando el mismo producto a la vez no se quedan con la misma fila.
        $st = $pdo->prepare("SELECT p.id FROM productos p
                             WHERE p.sku = ? AND TRIM(p.lote) = TRIM(?) AND p.fecha_vencimiento <=> ?
                               AND NOT EXISTS (SELECT 1 FROM posiciones_estibas e WHERE e.id_producto = p.id)
                             ORDER BY p.id LIMIT 1 FOR UPDATE");
        $st->execute([$p['sku'], $p['lote'], $p['fecha_vencimiento']]);
        $idProducto = $st->fetchColumn();
        if ($idProducto) {
            $pdo->prepare("UPDATE productos SET producto = ?, estado = ?, actualizado_el = NOW() WHERE id = ?")
                ->execute([$p['producto'], $p['estado'], $idProducto]);
        } else {
            $pdo->prepare("INSERT INTO productos (sku, producto, lote, fecha_vencimiento, estado, origen, id_usuario_registro)
                           VALUES (?, ?, ?, ?, ?, 'posiciones', ?)")
                ->execute([$p['sku'], $p['producto'], $p['lote'], $p['fecha_vencimiento'], $p['estado'], $idUsuario]);
            $idProducto = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO posiciones_estibas (id_posicion, id_producto, cantidad_cajas, estiba_completa, observaciones, id_usuario_registro)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([(int) $id, $idProducto, $e['cantidad_cajas'], $e['estiba_completa'], $e['observaciones'], $idUsuario]);
        $pdo->commit();
    } catch (PDOException $ex) {
        $pdo->rollBack();
        // La clave única de id_posicion: dos personas cargando la misma posición a la vez.
        error_log('Error agregando una estiba: ' . $ex->getMessage());
        return ['exito' => false, 'mensaje' => "No se pudo guardar: la posición {$pos['ubicacion']} ya tiene una estiba."];
    }
    registrarMovimiento($pdo, 'Ingreso', $pos, $p + ['id' => $idProducto], $e['cantidad_cajas'], $e['estiba_completa'], $idUsuario, $e['observaciones']);
    return ['exito' => true, 'mensaje' => "Estiba agregada en {$pos['ubicacion']}: " . describirEstibaPosicion($p + $e) . '.'];
}

/**
 * Corrige la estiba de una posición y su producto (no cambia quién la registró).
 */
function actualizarEstibaPosicion($pdo, $id, array $d, $idUsuario) {
    $pos = posicionPorId($pdo, $id);
    if (!$pos || !$pos['id_estiba']) {
        return ['exito' => false, 'mensaje' => 'Esa posición ya no tiene estiba.'];
    }
    $e = estibaDelFormulario($pdo, $d);
    if (is_string($e)) {
        return ['exito' => false, 'mensaje' => $e];
    }
    $p = $e['producto'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE productos SET sku = ?, producto = ?, lote = ?, fecha_vencimiento = ?, estado = ?, actualizado_el = NOW() WHERE id = ?")
            ->execute([$p['sku'], $p['producto'], $p['lote'], $p['fecha_vencimiento'], $p['estado'], $pos['id_producto']]);
        $pdo->prepare("UPDATE posiciones_estibas SET cantidad_cajas = ?, estiba_completa = ?, observaciones = ?, id_usuario_edicion = ?, actualizado_el = NOW()
                        WHERE id_estiba = ?")
            ->execute([$e['cantidad_cajas'], $e['estiba_completa'], $e['observaciones'], $idUsuario, $pos['id_estiba']]);
        $pdo->commit();
    } catch (PDOException $ex) {
        $pdo->rollBack();
        error_log('Error editando una estiba: ' . $ex->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error guardando los cambios. No se cambió nada.'];
    }
    return ['exito' => true, 'mensaje' => "Estiba de {$pos['ubicacion']} actualizada: " . describirEstibaPosicion($p + $e) . '.'];
}

/** Saca la estiba: la posición queda libre y el producto, "Sin ubicar" en Administrar Productos. */
function sacarEstibaPosicion($pdo, $id, $idUsuario = null) {
    $pos = posicionPorId($pdo, $id);
    if (!$pos || !$pos['id_estiba']) {
        return ['exito' => false, 'mensaje' => 'Esa posición ya está libre.'];
    }
    $pdo->prepare("DELETE FROM posiciones_estibas WHERE id_estiba = ?")->execute([$pos['id_estiba']]);
    registrarMovimiento($pdo, 'Salida', $pos, ['id' => $pos['id_producto']] + $pos, $pos['cantidad_cajas'], $pos['estiba_completa'], $idUsuario);
    return ['exito' => true, 'mensaje' => "Se sacó la estiba de {$pos['ubicacion']} (" . describirEstibaPosicion($pos)
        . '). La posición quedó libre y el producto sigue en Administrar Productos, sin ubicar.'];
}

/**
 * LLEVAR A PICKING (como en bodega): la estiba sale de la posición Y de la bodega, porque se va al
 * alistamiento. Se borra la estiba y su producto (uno solo: el de esta estiba), todo junto.
 */
function llevarEstibaAPicking($pdo, $id, $idUsuario = null) {
    $pos = posicionPorId($pdo, $id);
    if (!$pos || !$pos['id_estiba']) {
        return ['exito' => false, 'mensaje' => 'Esa posición no tiene ninguna estiba.'];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM posiciones_estibas WHERE id_estiba = ?")->execute([$pos['id_estiba']]);
        $pdo->prepare("DELETE FROM productos WHERE id = ?")->execute([$pos['id_producto']]);
        $pdo->commit();
    } catch (PDOException $ex) {
        $pdo->rollBack();
        error_log('Error llevando una estiba a picking: ' . $ex->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo llevar la estiba a picking. No se cambió nada.'];
    }
    registrarMovimiento($pdo, 'Picking', $pos, ['id' => null] + $pos, $pos['cantidad_cajas'], $pos['estiba_completa'], $idUsuario, 'A picking');
    return ['exito' => true, 'mensaje' => "Estiba de {$pos['ubicacion']} llevada a picking (" . describirEstibaPosicion($pos)
        . '). La posición quedó libre y el producto salió de Administrar Productos.'];
}

/** "SKU 26370, lote 3025626, estiba completa, vence 24/08/2027". */
function describirEstibaPosicion(array $e) {
    $partes = ['SKU ' . $e['sku']];
    if (!empty($e['lote'])) {
        $partes[] = 'lote ' . $e['lote'];
    }
    $partes[] = $e['estiba_completa'] ? 'estiba completa' : (!empty($e['cantidad_cajas']) ? $e['cantidad_cajas'] . ' cajas' : 'sin cantidad');
    if (!empty($e['fecha_vencimiento'])) {
        $partes[] = 'vence ' . date('d/m/Y', strtotime($e['fecha_vencimiento']));
    }
    return implode(', ', $partes);
}

/**
 * IMPORTAR (como el "Importar Excel" de bodega): toma de cualquier celda del Excel los códigos
 * de posición (R1M1N1A1) y crea los que falten. No borra ni cambia ninguna. Devuelve ['exito', 'mensaje'].
 */
function importarPosiciones($pdo, $rutaArchivo) {
    $filas = leerXlsxRapido($rutaArchivo) ?? leerPrimeraHoja($rutaArchivo);
    if (!$filas) {
        return ['exito' => false, 'mensaje' => 'No se pudo leer el archivo, o está vacío.'];
    }
    $codigos = []; $invalidos = 0;
    foreach ($filas as $fila) {
        foreach ((array) $fila as $celda) {
            $t = trim((string) $celda);
            if ($t === '' || !preg_match('/^R?\s*\d{1,2}\s*M/i', $t)) {
                continue;
            }
            if ($p = leerCodigoPosicion($t)) {
                $codigos[codigoPosicion(...$p)] = $p;
            } else {
                $invalidos++;
            }
        }
    }
    if (!$codigos) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae ningún código de posición (como R1M1N1A1). No se cargó nada.'];
    }
    $st = $pdo->prepare("INSERT IGNORE INTO posiciones (ubicacion, rack, modulo, nivel, lugar) VALUES (?, ?, ?, ?, ?)");
    $creadas = 0;
    foreach ($codigos as $codigo => [$r, $m, $n, $l]) {
        $st->execute([$codigo, $r, $m, $n, $l]);
        $creadas += $st->rowCount();
    }
    $mil = fn($n) => number_format($n, 0, ',', '.');
    return ['exito' => true, 'mensaje' => 'Importación lista: ' . $mil(count($codigos)) . ' posición(es) en el archivo, '
        . $mil($creadas) . ' creada(s) y ' . $mil(count($codigos) - $creadas) . ' que ya existían.'
        . ($invalidos ? ' ' . $mil($invalidos) . ' código(s) no corresponden a ninguna posición de los racks y se saltaron.' : '')];
}

/** Todas las posiciones con su estiba, para "Exportar Excel" (siempre todas, sin filtros). */
function posicionesParaExportar($pdo) {
    return $pdo->query("SELECT " . POSICIONES_SQL_CAMPOS . ", " . POSICIONES_SQL_ESTADO . " AS estado_producto "
        . POSICIONES_SQL_BASE . " ORDER BY pos.rack, pos.modulo, pos.nivel, pos.lugar, pos.id_posicion")->fetchAll(PDO::FETCH_ASSOC);
}

/** Escribe el Excel de todas las posiciones en $ruta. */
function escribirExcelPosiciones($pdo, $ruta) {
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Posiciones');
    $enc = ['Rack', 'Módulo', 'Nivel', 'Posición', 'Ubicación', 'Estado', 'SKU', 'Producto', 'Cajas', 'Lote', 'Vence', 'Observaciones', 'Registrado por', 'Registrado el'];
    $hoja->fromArray($enc, null, 'A1');
    $filas = [];
    foreach (posicionesParaExportar($pdo) as $p) {
        $filas[] = [
            (int) $p['rack'], (int) $p['modulo'], (int) $p['nivel'], $p['lugar'], $p['ubicacion'],
            $p['id_estiba'] ? $p['estado_producto'] : 'Libre',
            (string) $p['sku'], (string) $p['producto'],
            $p['id_estiba'] ? ($p['estiba_completa'] ? 'Estiba completa' : ($p['cantidad_cajas'] ?: '')) : '',
            (string) $p['lote'],
            $p['fecha_vencimiento'] ? date('d/m/Y', strtotime($p['fecha_vencimiento'])) : '',
            (string) $p['observaciones'], (string) $p['registrado_por'],
            $p['estiba_creada'] ? date('d/m/Y H:i', strtotime($p['estiba_creada'])) : '',
        ];
    }
    if ($filas) {
        $hoja->fromArray($filas, null, 'A2', true);
        // El SKU y el lote como texto, para que Excel no les quite ceros ni los pase a número.
        foreach ($filas as $i => $f) {
            foreach (['G' => 6, 'J' => 9] as $col => $k) {
                if ($f[$k] !== '') {
                    $hoja->setCellValueExplicit($col . ($i + 2), $f[$k], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
    }
    $hoja->getStyle('A1:N1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $hoja->getStyle('A1:N1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
    foreach ([7, 8, 8, 9, 13, 20, 11, 42, 15, 14, 12, 32, 16, 16] as $i => $ancho) {
        $hoja->getColumnDimensionByColumn($i + 1)->setWidth($ancho);
    }
    $hoja->freezePane('A2');
    $hoja->setAutoFilter('A1:N' . max(1, count($filas) + 1));
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);
}
