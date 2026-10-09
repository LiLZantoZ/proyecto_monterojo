<?php
// modules/rendimiento/controller_rendimiento.php
// Rendimiento: solo una acción, exportar (GET) el Excel de la ventana elegida: el ranking de todos
// o, si se eligió una persona, su jornada día por día.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_rendimiento.php';

$vista = BASE_URL . '/rendimiento';

requierePermiso('modulo_rendimiento', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

if (($_GET['accion'] ?? '') !== 'exportar') {
    header("Location: {$vista}");
    exit();
}

$dias = ventanaRendimiento($_GET['dias'] ?? 30);
$usuarios = usuariosRendimiento($pdo);
$idUsuario = isset($usuarios[(int) ($_GET['usuario'] ?? 0)]) ? (int) $_GET['usuario'] : null;
$ruta = tempnam(sys_get_temp_dir(), 'rend');
try {
    escribirExcelRendimiento($pdo, $dias, $idUsuario, $ruta);
} catch (Throwable $e) {
    error_log('Error exportando el rendimiento: ' . $e->getMessage());
    @unlink($ruta);
    guardarMensajeFlashTexto('error', 'No se pudo generar el Excel del rendimiento.');
    header("Location: {$vista}");
    exit();
}
$nombre = 'rendimiento_' . ($idUsuario ? preg_replace('/[^a-z0-9]+/i', '_', $usuarios[$idUsuario]) . '_' : '') . $dias . 'dias_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
readfile($ruta);
@unlink($ruta);
exit();
