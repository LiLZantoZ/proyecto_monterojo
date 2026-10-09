<?php
// modules/pedidos/controller_pedidos.php
// Acciones del módulo Pedidos (todas POST, con CSRF y el permiso pedidos_editar):
//   · subir_pedidos    — el Excel PEDIDOS MONTEROJO (los pedidos de C1 y, en el mismo archivo, BD Clientes,
//                        CIUDADES, OBSERVACIONES e INVENTARIO) o el archivo PEDIDOS solo. Reemplaza los pedidos.
//   · subir_inventario — INVENTARIO BOGOTA o INVENTARIO COPA (reemplaza el de esa sede)
//   · vaciar           — vacía lo elegido: los pedidos, un inventario o los clientes y ciudades

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_pedidos.php';

$vista = BASE_URL . '/pedidos';

requierePermiso('modulo_pedidos', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));
requierePermiso('pedidos_editar', $vista);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vista}");
    exit();
}

validarCSRF();

// El archivo subido, ya revisado: [ruta temporal, nombre original], o null (con el mensaje puesto).
$archivoSubido = function () {
    $archivo = $_FILES['archivo'] ?? null;
    if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
        return null;
    }
    if (!in_array(strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
        guardarMensajeFlashTexto('error', 'El archivo tiene que ser un Excel (.xlsx o .xls).');
        return null;
    }
    return [$archivo['tmp_name'], $archivo['name']];
};

$usuario = $_SESSION['usuario_id'] ?? null;
$r = null;

switch ($_POST['accion'] ?? '') {
    case 'subir_pedidos':
        if ($a = $archivoSubido()) {
            $r = importarArchivoPedidos($pdo, $a[0], $usuario, $a[1]);
        }
        break;

    case 'subir_inventario':
        if ($a = $archivoSubido()) {
            $r = importarInventarioPedidos($pdo, $a[0], $_POST['sede'] ?? '', $usuario, $a[1]);
        }
        break;

    case 'vaciar':
        $r = vaciarPedidos($pdo, (array) ($_POST['partes'] ?? []));
        break;
}

if ($r !== null) {
    guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
}
header("Location: {$vista}");
exit();
