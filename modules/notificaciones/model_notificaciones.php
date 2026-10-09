<?php
// modules/notificaciones/model_notificaciones.php
// NOTIFICACIONES (2026-10-06): el módulo del sistema de bodega, adaptado a Monterojo.
//
// Tres cosas, como en bodega:
//  · VENCIMIENTOS: los productos de la bodega (Administrar Productos) vencidos o por vencer, con
//    su posición. Con los plazos de Monterojo (ver PRODUCTOS_SEMAFORO): se avisa de lo vencido y de
//    lo que vence en 90 días o menos. En bodega se avisaba de 6 meses a 1 año.
//  · PRODUCTOS NUEVOS: los que se registraron a mano en Administrar Productos los últimos 30 días,
//    y si ya pasaron por el Formato Conciliador.
//  · AVISOS PERSONALES: los mensajes de la campana (hoy, "se registró un producto nuevo").
// La CAMPANA flotante de todas las pantallas muestra los vencimientos y los avisos personales.

require_once __DIR__ . '/../productos/model_productos.php';

const NOTIFICACIONES_DIAS_PRODUCTOS_NUEVOS = 30;
const NOTIFICACIONES_DIAS_AVISO = 90;   // se avisa de lo que vence en estos días o menos

/** Los productos vencidos o que vencen en 90 días o menos, del que vence antes al que vence después. */
function vencimientosNotificaciones($pdo) {
    return $pdo->query("SELECT p.id, p.sku, p.producto, p.lote, p.fecha_vencimiento, p.estado,
                               DATEDIFF(p.fecha_vencimiento, CURDATE()) AS dias_restantes, pos.ubicacion
                          FROM productos p
                          LEFT JOIN posiciones_estibas e ON e.id_producto = p.id
                          LEFT JOIN posiciones pos ON pos.id_posicion = e.id_posicion
                         WHERE p.fecha_vencimiento IS NOT NULL
                           AND DATEDIFF(p.fecha_vencimiento, CURDATE()) <= " . NOTIFICACIONES_DIAS_AVISO . "
                         ORDER BY p.fecha_vencimiento, p.sku")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Los productos registrados a mano en Administrar Productos los últimos 30 días, primero los que
 * todavía no pasaron por el Formato Conciliador. Se toma el PRIMER registro del conciliador de ese
 * SKU + lote: dice quién lo pasó.
 */
function productosNuevosNotificaciones($pdo, $dias = NOTIFICACIONES_DIAS_PRODUCTOS_NUEVOS) {
    $dias = max(1, (int) $dias);
    return $pdo->query("SELECT p.id, p.sku, p.producto, p.lote, p.fecha_vencimiento, p.estado, p.creado_el,
                               u.nombre_usuario AS registrado_por, pos.ubicacion,
                               (fc.id IS NOT NULL) AS en_conciliador, fc.responsable AS responsable_conciliador, fc.creado_el AS fecha_conciliador
                          FROM productos p
                          LEFT JOIN usuarios u ON u.id_usuario = p.id_usuario_registro
                          LEFT JOIN posiciones_estibas e ON e.id_producto = p.id
                          LEFT JOIN posiciones pos ON pos.id_posicion = e.id_posicion
                          LEFT JOIN formato_conciliador fc ON fc.id = (
                                SELECT MIN(f2.id) FROM formato_conciliador f2 WHERE f2.sku = p.sku AND TRIM(f2.lote) = TRIM(p.lote))
                         WHERE p.origen = 'administrar_productos' AND p.creado_el >= (NOW() - INTERVAL {$dias} DAY)
                         ORDER BY en_conciliador, p.creado_el DESC")->fetchAll(PDO::FETCH_ASSOC);
}

/** Crea un aviso personal. */
function crearNotificacion($pdo, $idEmisor, $idReceptor, $mensaje, $tipo = 'aviso', $enlace = null) {
    try {
        $pdo->prepare("INSERT INTO notificaciones (id_usuario_emisor, id_usuario_receptor, mensaje, tipo, enlace) VALUES (?, ?, ?, ?, ?)")
            ->execute([$idEmisor ?: null, (int) $idReceptor, mb_substr((string) $mensaje, 0, 500), $tipo, $enlace]);
        return true;
    } catch (Throwable $e) {
        error_log('No se pudo crear la notificación: ' . $e->getMessage());
        return false;
    }
}

/** Los usuarios activos con un permiso (para avisarles). */
function usuariosConPermisoNotificaciones($pdo, $permiso) {
    $st = $pdo->prepare("SELECT u.id_usuario FROM usuarios u
                           JOIN rolespermisos rp ON rp.id_rol = u.id_rol
                           JOIN permisos pe ON pe.id_permiso = rp.id_permiso
                          WHERE pe.nombre_permiso = ? AND u.estado = 'Activo'");
    $st->execute([$permiso]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Avisa a quienes tienen notificaciones_productos_nuevos que se registró un producto (menos a quien lo registró). */
function notificarProductoNuevo($pdo, $idEmisor, array $producto) {
    $mensaje = 'Nuevo producto: SKU ' . $producto['sku'] . ($producto['producto'] ? ' — ' . $producto['producto'] : '')
             . (!empty($producto['lote']) ? ' (lote ' . $producto['lote'] . ')' : '') . '. Recordá pasarlo por el Formato Conciliador.';
    $n = 0;
    foreach (usuariosConPermisoNotificaciones($pdo, 'notificaciones_productos_nuevos') as $idReceptor) {
        if ($idReceptor !== (int) $idEmisor && crearNotificacion($pdo, $idEmisor, $idReceptor, $mensaje, 'producto_nuevo', BASE_URL . '/notificaciones')) {
            $n++;
        }
    }
    return $n;
}

/** Los avisos personales de un usuario (por defecto, solo los sin leer). */
function notificacionesPersonales($pdo, $idUsuario, $soloSinLeer = true, $limite = 50) {
    $st = $pdo->prepare("SELECT n.*, u.nombre_usuario AS emisor FROM notificaciones n
                           LEFT JOIN usuarios u ON u.id_usuario = n.id_usuario_emisor
                          WHERE n.id_usuario_receptor = ?" . ($soloSinLeer ? ' AND n.leido = 0' : '') . "
                          ORDER BY n.fecha DESC, n.id DESC LIMIT " . (int) $limite);
    $st->execute([(int) $idUsuario]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Marca un aviso como leído (solo si es de ese usuario). */
function marcarNotificacionLeida($pdo, $id, $idUsuario) {
    $st = $pdo->prepare("UPDATE notificaciones SET leido = 1 WHERE id = ? AND id_usuario_receptor = ?");
    $st->execute([(int) $id, (int) $idUsuario]);
    return $st->rowCount() > 0;
}

/** Marca todos los avisos de un usuario como leídos. Devuelve cuántos. */
function marcarTodasNotificacionesLeidas($pdo, $idUsuario) {
    $st = $pdo->prepare("UPDATE notificaciones SET leido = 1 WHERE id_usuario_receptor = ? AND leido = 0");
    $st->execute([(int) $idUsuario]);
    return $st->rowCount();
}

/**
 * Lo que muestra la campana: los vencimientos (si el usuario tiene notificaciones_vencimiento) y sus
 * avisos personales sin leer. Nunca rompe la pantalla: si algo falla, la campana sale vacía.
 */
function datosCampanaNotificaciones($pdo, $idUsuario, $verVencimientos) {
    try {
        $venc = $verVencimientos ? vencimientosNotificaciones($pdo) : [];
        $personales = $idUsuario ? notificacionesPersonales($pdo, $idUsuario, true, 20) : [];
    } catch (Throwable $e) {
        error_log('La campana de notificaciones no pudo leer: ' . $e->getMessage());
        return ['vencimientos' => [], 'personales' => [], 'total' => 0];
    }
    $vistas = array_map(fn($v) => [
        'sku' => $v['sku'], 'producto' => $v['producto'], 'lote' => $v['lote'], 'ubicacion' => $v['ubicacion'],
        'vence' => date('d/m/Y', strtotime($v['fecha_vencimiento'])), 'dias' => (int) $v['dias_restantes'],
        'semaforo' => semaforoVencimiento($v['dias_restantes']),
    ], $venc);
    $pers = array_map(fn($n) => ['id' => (int) $n['id'], 'mensaje' => $n['mensaje'], 'tipo' => $n['tipo'], 'enlace' => $n['enlace'],
        'fecha' => date('d/m/Y H:i', strtotime($n['fecha'])), 'emisor' => $n['emisor']], $personales);
    return ['vencimientos' => $vistas, 'personales' => $pers, 'total' => count($vistas) + count($pers)];
}
