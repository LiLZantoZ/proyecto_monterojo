<?php
// modules/notificaciones/layouts/campana.php
// LA CAMPANA (2026-10-06, del sistema de bodega): flotante en todas las pantallas (la incluye el
// menú lateral). Muestra los vencimientos de la bodega —a quien tiene notificaciones_vencimiento—
// y los avisos personales sin leer.
//
// Se llena al cargar la pantalla y NO se refresca sola: cada pedido al servidor cuenta como
// actividad, y un refresco periódico mantendría la sesión abierta para siempre, salteando el
// cierre por inactividad.
require_once __DIR__ . '/../model_notificaciones.php';

$_campana = datosCampanaNotificaciones($pdo, (int) ($_SESSION['usuario_id'] ?? 0), tienePermiso('notificaciones_vencimiento'));
?>
<div class="campana-flotante" id="campana-flotante">
    <button type="button" class="campana-btn" id="campana-btn" aria-label="Notificaciones" aria-expanded="false" aria-controls="campana-panel">
        <i class="fa-solid fa-bell"></i>
        <span class="campana-numero" id="campana-numero"<?php echo $_campana['total'] ? '' : ' hidden'; ?>><?php echo $_campana['total'] > 99 ? '99+' : $_campana['total']; ?></span>
    </button>
    <div class="campana-panel" id="campana-panel" hidden>
        <div class="campana-panel-cabecera">
            <strong>Notificaciones</strong>
            <?php if (tienePermiso('modulo_notificaciones')): ?>
                <a href="<?php echo BASE_URL; ?>/notificaciones">Ver todo</a>
            <?php endif; ?>
        </div>
        <div class="campana-panel-cuerpo" id="campana-cuerpo"></div>
    </div>
</div>
<script>
    window.CAMPANA = {
        datos: <?php echo json_encode($_campana, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>,
        urlAcciones: <?php echo json_encode(BASE_URL . '/notificaciones/acciones'); ?>,
        urlPosiciones: <?php echo json_encode(tienePermiso('modulo_posiciones') ? BASE_URL . '/posiciones?buscar=' : ''); ?>,
        csrf: <?php echo json_encode(generarTokenCSRF()); ?>
    };
</script>
<script src="<?php echo BASE_URL; ?>/modules/notificaciones/layouts/scripts_campana.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/notificaciones/layouts/scripts_campana.js'); ?>"></script>
