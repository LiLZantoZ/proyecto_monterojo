<?php
// modules/trazabilidad/controller_trazabilidad.php
// Trazabilidad: solo una acción, exportar (GET) el Excel de las acciones con los filtros de la
// pantalla (o de todo el historial, sin filtros).

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_trazabilidad.php';

$vista = BASE_URL . '/trazabilidad';

requierePermiso('modulo_trazabilidad', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

if (($_GET['accion'] ?? '') !== 'exportar') {
    header("Location: {$vista}");
    exit();
}

@set_time_limit(300);
$filtros = array_map(fn($v) => trim((string) $v), array_intersect_key($_GET, array_flip(['buscar', 'mes', 'modulo', 'usuario', 'resultado'])));
$ruta = tempnam(sys_get_temp_dir(), 'traz');
try {
    escribirExcelTrazabilidad($pdo, $filtros, $ruta);
} catch (Throwable $e) {
    error_log('Error exportando la trazabilidad: ' . $e->getMessage());
    @unlink($ruta);
    guardarMensajeFlashTexto('error', 'No se pudo generar el Excel de la trazabilidad.');
    header("Location: {$vista}");
    exit();
}
$nombre = 'trazabilidad_' . (esMesTrazabilidad($filtros['mes'] ?? '') ? $filtros['mes'] : date('Y-m-d_His')) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
readfile($ruta);
@unlink($ruta);
exit();
