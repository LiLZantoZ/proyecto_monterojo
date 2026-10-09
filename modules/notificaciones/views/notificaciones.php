<?php
// modules/notificaciones/views/notificaciones.php
// NOTIFICACIONES (2026-10-06): los avisos personales, los productos nuevos (y si ya pasaron por el
// Formato Conciliador) y los vencimientos de la bodega. Ver model_notificaciones.php.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_notificaciones.php';

requierePermiso('modulo_notificaciones', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mil = fn($n) => number_format((float) $n, 0, ',', '.');

$usuario      = (int) ($_SESSION['usuario_id'] ?? 0);
$personales   = notificacionesPersonales($pdo, $usuario, false, 50);
$sinLeer      = count(array_filter($personales, fn($n) => !$n['leido']));
$nuevos       = productosNuevosNotificaciones($pdo);
$pendientes   = count(array_filter($nuevos, fn($p) => !$p['en_conciliador']));
$verVenc      = tienePermiso('notificaciones_vencimiento');
$vencimientos = $verVenc ? vencimientosNotificaciones($pdo) : [];
$verPosiciones = tienePermiso('modulo_posiciones');
$claseEstado  = ['Disponible' => 'estado-verde', 'Bloqueado' => 'estado-gris', 'En Control De Calidad' => 'estado-azul', 'Defectuoso' => 'estado-rojo'];
$posicion = function ($u) use ($esc, $verPosiciones) {
    if (!$u) { return '<span class="dato-faltante">Sin ubicar</span>'; }
    return $verPosiciones ? '<a class="enlace-posicion" href="' . $esc(BASE_URL . '/posiciones?buscar=' . urlencode($u)) . '">' . $esc($u) . '</a>' : $esc($u);
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificaciones · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Notificaciones</h2>
            </header>

            <!-- MIS AVISOS -->
            <section class="seccion-bodega">
                <div class="seccion-bodega-titulo">
                    <h3><i class="fa-solid fa-bell"></i> Mis avisos</h3>
                    <span class="pastilla-bodega <?php echo $sinLeer ? 'semaforo-naranja' : 'pastilla-dato'; ?>">Sin leer <strong><?php echo $sinLeer; ?></strong></span>
                    <?php if ($sinLeer): ?>
                        <form method="POST" action="<?php echo BASE_URL; ?>/notificaciones/acciones" class="form-en-linea">
                            <?php campoCSRF(); ?>
                            <input type="hidden" name="accion" value="marcar_todas">
                            <button type="submit" class="btn btn-chico"><i class="fa-solid fa-check-double"></i> Marcar todos como leídos</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if (!$personales): ?>
                    <p class="texto-suave">No tenés avisos.</p>
                <?php else: ?>
                    <ul class="lista-avisos">
                        <?php foreach ($personales as $n): ?>
                            <li class="<?php echo $n['leido'] ? 'aviso-leido' : ''; ?>">
                                <i class="fa-solid <?php echo $n['tipo'] === 'producto_nuevo' ? 'fa-box' : 'fa-bell'; ?>"></i>
                                <div>
                                    <div><?php echo $esc($n['mensaje']); ?></div>
                                    <small><?php echo date('d/m/Y H:i', strtotime($n['fecha'])); ?><?php echo $n['emisor'] ? ' · de ' . $esc($n['emisor']) : ''; ?></small>
                                </div>
                                <?php if (!$n['leido']): ?>
                                    <form method="POST" action="<?php echo BASE_URL; ?>/notificaciones/acciones" class="form-en-linea">
                                        <?php campoCSRF(); ?>
                                        <input type="hidden" name="accion" value="marcar_leida">
                                        <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                        <button type="submit" class="btn btn-chico" title="Marcar como leído"><i class="fa-solid fa-check"></i></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <!-- PRODUCTOS NUEVOS: los registrados a mano los últimos 30 días, y si ya pasaron por el conciliador. -->
            <section class="seccion-bodega">
                <div class="seccion-bodega-titulo">
                    <h3><i class="fa-solid fa-box-open"></i> Productos nuevos <small>(últimos <?php echo NOTIFICACIONES_DIAS_PRODUCTOS_NUEVOS; ?> días)</small></h3>
                    <span class="pastilla-bodega <?php echo $pendientes ? 'semaforo-naranja' : 'semaforo-verde'; ?>">Sin pasar por el Formato Conciliador <strong><?php echo $pendientes; ?></strong></span>
                </div>
                <?php if (!$nuevos): ?>
                    <p class="texto-suave">No se registraron productos a mano en Administrar Productos estos días.</p>
                <?php else: ?>
                    <div class="tabla-caja">
                        <table class="tabla tabla-seguimiento tabla-bodega">
                            <thead><tr><th>SKU</th><th>Producto</th><th>Lote</th><th>Vence</th><th>Registrado</th><th>Formato Conciliador</th><th>Posición</th></tr></thead>
                            <tbody>
                                <?php foreach ($nuevos as $p): ?>
                                    <tr>
                                        <td><strong><?php echo $esc($p['sku']); ?></strong></td>
                                        <td><?php echo $esc($p['producto']); ?></td>
                                        <td><?php echo $esc($p['lote']); ?></td>
                                        <td><?php echo $p['fecha_vencimiento'] ? date('d/m/Y', strtotime($p['fecha_vencimiento'])) : '<span class="dato-faltante">—</span>'; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($p['creado_el'])); ?><?php echo $p['registrado_por'] ? '<span class="sub-dato">' . $esc($p['registrado_por']) . '</span>' : ''; ?></td>
                                        <td>
                                            <?php if ($p['en_conciliador']): ?>
                                                <span class="chip-estado estado-verde">Registrado</span>
                                                <span class="sub-dato"><?php echo $esc($p['responsable_conciliador']); ?> · <?php echo date('d/m/Y', strtotime($p['fecha_conciliador'])); ?></span>
                                            <?php else: ?>
                                                <span class="chip-estado estado-ambar">Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $posicion($p['ubicacion']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($verVenc): ?>
            <!-- VENCIMIENTOS: lo vencido y lo que vence en 90 días o menos. -->
            <section class="seccion-bodega">
                <div class="seccion-bodega-titulo">
                    <h3><i class="fa-solid fa-hourglass-half"></i> Vencimientos</h3>
                    <?php foreach (['vencido', 'por_vencer', 'proximo'] as $s):
                        $n = count(array_filter($vencimientos, fn($v) => semaforoVencimiento($v['dias_restantes']) === $s)); ?>
                        <span class="pastilla-bodega <?php echo PRODUCTOS_SEMAFORO[$s][2]; ?>"><span class="punto-semaforo"></span><?php echo $esc(PRODUCTOS_SEMAFORO[$s][0]); ?> <strong><?php echo $n; ?></strong></span>
                    <?php endforeach; ?>
                </div>
                <?php if (!$vencimientos): ?>
                    <p class="texto-suave">No hay productos vencidos ni que venzan en los próximos <?php echo NOTIFICACIONES_DIAS_AVISO; ?> días.</p>
                <?php else: ?>
                    <div class="tabla-caja">
                        <table class="tabla tabla-seguimiento tabla-bodega">
                            <thead><tr><th>SKU</th><th>Producto</th><th>Lote</th><th>Vence</th><th>Estado</th><th>Posición</th></tr></thead>
                            <tbody>
                                <?php foreach ($vencimientos as $v):
                                    $s = semaforoVencimiento($v['dias_restantes']); $d = (int) $v['dias_restantes']; ?>
                                    <tr>
                                        <td><strong><?php echo $esc($v['sku']); ?></strong></td>
                                        <td><?php echo $esc($v['producto']); ?></td>
                                        <td><?php echo $esc($v['lote']); ?></td>
                                        <td>
                                            <span class="chip-semaforo <?php echo PRODUCTOS_SEMAFORO[$s][2]; ?>"><span class="punto-semaforo"></span><?php echo date('d/m/Y', strtotime($v['fecha_vencimiento'])); ?></span>
                                            <span class="sub-dato"><?php echo $d < 0 ? 'Venció hace ' . $mil(-$d) . ' día(s)' : ($d === 0 ? 'Vence hoy' : 'Faltan ' . $mil($d) . ' día(s)'); ?></span>
                                        </td>
                                        <td><span class="chip-estado <?php echo $claseEstado[$v['estado']] ?? 'estado-gris'; ?>"><?php echo $esc($v['estado']); ?></span></td>
                                        <td><?php echo $posicion($v['ubicacion']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

        </div>
    </div>
</div>
<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
</body>
</html>
