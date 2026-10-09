<?php
// modules/formato_conciliador/controller_formato_conciliador.php
// Acciones del Formato Conciliador (como en el sistema de bodega):
//   · buscar_producto (GET, JSON) — al escribir SKU y lote: descripción, unidades por caja y vencimiento
//   · exportar (GET)              — el Excel de los registros (con los filtros de la pantalla)
//   · crear (POST)                — formato_conciliador_registrar. Una fila por estiba.
//   · eliminar (POST)             — formato_conciliador_registrar, si no deja estibas de más ubicadas

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_formato_conciliador.php';

$vista = BASE_URL . '/formato-conciliador';

requierePermiso('modulo_formato_conciliador', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'buscar_producto') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(buscarProductoConciliador($pdo, $_GET['sku'] ?? '', $_GET['lote'] ?? ''), JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'exportar') {
    $filtros = array_map(fn($v) => trim((string) $v), array_intersect_key($_GET, array_flip(['fecha', 'sku', 'lote', 'responsable', 'general'])));
    $ruta = tempnam(sys_get_temp_dir(), 'fc');
    try {
        escribirExcelConciliador($pdo, $filtros, $ruta);
    } catch (Throwable $e) {
        error_log('Error exportando el Formato Conciliador: ' . $e->getMessage());
        @unlink($ruta);
        guardarMensajeFlashTexto('error', 'No se pudo generar el Excel del Formato Conciliador.');
        header("Location: {$vista}");
        exit();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="formato_conciliador_' . date('Y-m-d_His') . '.xlsx"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    @unlink($ruta);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($accion, ['crear', 'eliminar'], true)) {
    header("Location: {$vista}");
    exit();
}

validarCSRF();
requierePermiso('formato_conciliador_registrar', $vista);

$volver = $vista;
if (($_POST['volver'] ?? '') !== '' && preg_match('/^[\w=&%.\-+,]*$/', $_POST['volver'])) {
    $volver .= '?' . $_POST['volver'];
}

$r = $accion === 'crear'
    ? crearRegistrosConciliador($pdo, $_POST, ['id' => $_SESSION['usuario_id'] ?? null, 'nombre' => $_SESSION['usuario_nombre'] ?? '', 'rol' => $_SESSION['nombre_rol'] ?? ''])
    : eliminarRegistroConciliador($pdo, (int) ($_POST['id'] ?? 0));

guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
header("Location: {$volver}");
exit();
