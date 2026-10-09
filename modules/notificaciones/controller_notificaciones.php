<?php
// modules/notificaciones/controller_notificaciones.php
// Acciones de Notificaciones. Las usa la campana de todas las pantallas, así que basta con tener
// sesión (cada quien solo toca SUS avisos):
//   · obtener_campana (GET, JSON) — lo que muestra la campana (la refresca cada minuto)
//   · marcar_leida (POST)         — un aviso, por id (desde la campana, JSON, o desde la pantalla)
//   · marcar_todas (POST)         — todos los avisos del usuario

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_notificaciones.php';

$usuario = (int) ($_SESSION['usuario_id'] ?? 0);
$accion  = $_GET['accion'] ?? $_POST['accion'] ?? '';
$esJson  = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'obtener_campana') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(datosCampanaNotificaciones($pdo, $usuario, tienePermiso('notificaciones_vencimiento')), JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($accion, ['marcar_leida', 'marcar_todas'], true)) {
    header('Location: ' . BASE_URL . '/notificaciones');
    exit();
}

validarCSRF();

if ($accion === 'marcar_leida') {
    $ok = marcarNotificacionLeida($pdo, (int) ($_POST['id'] ?? 0), $usuario);
    $r = ['exito' => $ok, 'mensaje' => $ok ? 'Aviso marcado como leído.' : 'Ese aviso ya no está.'];
} else {
    $n = marcarTodasNotificacionesLeidas($pdo, $usuario);
    $r = ['exito' => true, 'mensaje' => $n ? "Se marcaron {$n} aviso(s) como leídos." : 'No tenías avisos sin leer.'];
}

if ($esJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit();
}
guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
header('Location: ' . BASE_URL . '/notificaciones');
exit();
