<?php
// modules/productos/controller_productos.php
// Acciones de Administrar Productos (como en el sistema de bodega):
//   · kardex (GET, JSON)  — las entradas y salidas de un SKU (con ver el módulo alcanza)
//   · exportar (GET)      — el Excel de todos los productos
//   · crear / editar /
//     eliminar (POST)     — productos_editar. Al crear se avisa a quienes reciben "productos nuevos".

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_productos.php';
require_once __DIR__ . '/../notificaciones/model_notificaciones.php';

$vista = BASE_URL . '/productos';

requierePermiso('modulo_productos', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'kardex') {
    header('Content-Type: application/json; charset=utf-8');
    $fecha = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;
    $sku = trim($_GET['sku'] ?? '');
    $filas = $sku === '' ? [] : kardexPorSku($pdo, $sku, $fecha($_GET['desde'] ?? ''), $fecha($_GET['hasta'] ?? ''));
    echo json_encode(array_map(fn($m) => [
        'fecha' => date('d/m/Y H:i', strtotime($m['fecha_hora'])), 'tipo' => $m['tipo'], 'ubicacion' => $m['ubicacion'],
        'lote' => $m['lote'], 'vence' => $m['fecha_vencimiento'] ? date('d/m/Y', strtotime($m['fecha_vencimiento'])) : '',
        'cantidad' => $m['estiba_completa'] ? 'Estiba completa' : ($m['cantidad_cajas'] ? $m['cantidad_cajas'] . ' cajas' : '—'),
        'usuario' => $m['nombre_usuario'], 'observaciones' => $m['observaciones'],
    ], $filas), JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'exportar') {
    @set_time_limit(120);
    $ruta = tempnam(sys_get_temp_dir(), 'prod');
    try {
        escribirExcelProductos($pdo, $ruta);
    } catch (Throwable $e) {
        error_log('Error exportando los productos: ' . $e->getMessage());
        @unlink($ruta);
        guardarMensajeFlashTexto('error', 'No se pudo generar el Excel de los productos.');
        header("Location: {$vista}");
        exit();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="productos_' . date('Y-m-d_His') . '.xlsx"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    @unlink($ruta);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($accion, ['crear', 'editar', 'eliminar'], true)) {
    header("Location: {$vista}");
    exit();
}

validarCSRF();
requierePermiso('productos_editar', $vista);

$volver = $vista;
if (($_POST['volver'] ?? '') !== '' && preg_match('/^[\w=&%.\-+,]*$/', $_POST['volver'])) {
    $volver .= '?' . $_POST['volver'];
}
$usuario = $_SESSION['usuario_id'] ?? null;
$id = (int) ($_POST['id'] ?? 0);

switch ($accion) {
    case 'crear':
        $r = crearProducto($pdo, $_POST, $usuario);
        if ($r['exito'] && ($avisados = notificarProductoNuevo($pdo, $usuario, $r['datos']))) {
            $r['mensaje'] .= " Se le avisó a {$avisados} persona(s).";
        }
        break;
    case 'editar':
        $r = actualizarProducto($pdo, $id, $_POST);
        break;
    case 'eliminar':
        $r = eliminarProducto($pdo, $id);
        break;
}

guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
header("Location: {$volver}");
exit();
