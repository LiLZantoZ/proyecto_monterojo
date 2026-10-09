<?php
// modules/posiciones/controller_posiciones.php
// Acciones del módulo Posiciones de bodega. Cada una con su permiso, como en el sistema de bodega:
//   · exportar (GET)             — el Excel de todas las posiciones (con ver el módulo alcanza)
//   · crear / actualizar /
//     eliminar / importar        — posiciones_crear
//   · agregar_estiba /
//     sacar_estiba               — posiciones_mover_estibas
//   · llevar_a_picking           — posiciones_mover_estibas y posiciones_llevar_a_picking
//   · consultar_cupo (GET, JSON) — lo registrado en el Formato Conciliador y lo ubicado (informativo)
//   · actualizar_estiba          — posiciones_editar_detalle
// Las de escritura van por POST con CSRF.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_posiciones.php';

$vista = BASE_URL . '/posiciones';

requierePermiso('modulo_posiciones', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// LO REGISTRADO EN EL FORMATO CONCILIADOR Y LO UBICADO (JSON, para el modal). Solo informa: desde el
// 2026-10-06 no frena ninguna estiba.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['accion'] ?? '') === 'consultar_cupo') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(cupoConciliador($pdo, trim($_GET['sku'] ?? ''), trim($_GET['lote'] ?? ''),
        ($_GET['fecha_vencimiento'] ?? '') !== '' ? $_GET['fecha_vencimiento'] : null, (int) ($_GET['id_posicion'] ?? 0)));
    exit();
}

// EXPORTAR EXCEL: todas las posiciones, sin filtros ni páginas (como en bodega).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['accion'] ?? '') === 'exportar') {
    @set_time_limit(120);
    $ruta = tempnam(sys_get_temp_dir(), 'pos');
    try {
        escribirExcelPosiciones($pdo, $ruta);
    } catch (Throwable $e) {
        error_log('Error exportando las posiciones: ' . $e->getMessage());
        @unlink($ruta);
        guardarMensajeFlashTexto('error', 'No se pudo generar el Excel de las posiciones.');
        header("Location: {$vista}");
        exit();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="posiciones_' . date('Y-m-d_His') . '.xlsx"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    @unlink($ruta);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vista}");
    exit();
}

validarCSRF();

$accion  = $_POST['accion'] ?? '';
$permiso = [
    'crear' => 'posiciones_crear', 'actualizar' => 'posiciones_crear', 'eliminar' => 'posiciones_crear', 'importar' => 'posiciones_crear',
    'agregar_estiba' => 'posiciones_mover_estibas', 'sacar_estiba' => 'posiciones_mover_estibas',
    'llevar_a_picking' => 'posiciones_mover_estibas', 'actualizar_estiba' => 'posiciones_editar_detalle',
][$accion] ?? null;
if ($permiso === null) {
    header("Location: {$vista}");
    exit();
}
requierePermiso($permiso, $vista);
// Llevar a picking borra además un producto: pide también su propio permiso (como en bodega).
if ($accion === 'llevar_a_picking') {
    requierePermiso('posiciones_llevar_a_picking', $vista);
}

// Volver a la misma página y filtros desde donde se hizo la acción.
$volver = $vista;
if (preg_match('/^[\w=&%.\-+,]*$/', $_POST['volver'] ?? '') && ($_POST['volver'] ?? '') !== '') {
    $volver .= '?' . $_POST['volver'];
}

$usuario = $_SESSION['usuario_id'] ?? null;
$id      = (int) ($_POST['id_posicion'] ?? 0);

switch ($accion) {
    case 'crear':
        $r = crearPosicion($pdo, $_POST);
        break;
    case 'actualizar':
        $r = actualizarPosicion($pdo, $id, $_POST);
        break;
    case 'eliminar':
        $r = eliminarPosicion($pdo, $id);
        break;
    case 'importar':
        $archivo = $_FILES['archivo'] ?? null;
        if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            $r = ['exito' => false, 'mensaje' => 'No se pudo recibir el archivo. Probá de nuevo.'];
        } elseif (!in_array(strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            $r = ['exito' => false, 'mensaje' => 'El archivo tiene que ser un Excel (.xlsx o .xls).'];
        } else {
            $r = importarPosiciones($pdo, $archivo['tmp_name']);
        }
        break;
    case 'agregar_estiba':
        $r = agregarEstibaPosicion($pdo, $id, $_POST, $usuario);
        break;
    case 'actualizar_estiba':
        $r = actualizarEstibaPosicion($pdo, $id, $_POST, $usuario);
        break;
    case 'sacar_estiba':
        $r = sacarEstibaPosicion($pdo, $id, $usuario);
        break;
    case 'llevar_a_picking':
        $r = llevarEstibaAPicking($pdo, $id, $usuario);
        break;
}

guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
header("Location: {$volver}");
exit();
