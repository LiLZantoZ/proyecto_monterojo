<?php
// modules/seguimiento/controller_seguimiento.php
// Acciones del seguimiento de pedidos (todas POST, con CSRF):
//   · guardar_pedido   — crea o actualiza un pedido a mano
//   · importar_excel   — sube el listado que exporta una transportadora
//   · eliminar_pedido  — borra un pedido
//
// La tabla no pasa por acá: la arma la vista, que es solo lectura.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_seguimiento.php';

$vista = BASE_URL . '/seguimiento';

requierePermiso('modulo_seguimiento', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vista}");
    exit();
}

validarCSRF();

$idUsuario = $_SESSION['usuario_id'] ?? null;

switch ($_POST['accion'] ?? '') {

    case 'guardar_pedido':
        $r = guardarPedidoManual($pdo, $_POST, $idUsuario);
        guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
        break;

    case 'importar_excel':
        $archivo = $_FILES['archivo'] ?? null;

        if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
            guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
            break;
        }
        if (!in_array(strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            guardarMensajeFlashTexto('error', 'El listado tiene que ser un Excel (.xlsx o .xls).');
            break;
        }
        // Sin esto, un POST armado a mano podría pasar una ruta del servidor en tmp_name.
        if (!is_uploaded_file($archivo['tmp_name'])) {
            guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
            break;
        }

        $r = importarPedidosExcel(
            $pdo,
            $archivo['tmp_name'],
            $idUsuario,
            $_POST['transportadora'] ?? null   // la del formulario, por si el Excel no trae columna
        );
        guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
        break;

    case 'eliminar_pedido':
        $ok = eliminarPedido($pdo, $_POST['id_pedido'] ?? 0);
        guardarMensajeFlashTexto($ok ? 'exito' : 'error',
            $ok ? 'Pedido eliminado.' : 'No se encontró el pedido que se quería eliminar.');
        break;

    case 'eliminar_masivo':
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $n = eliminarPedidos($pdo, $ids);
        guardarMensajeFlashTexto($n > 0 ? 'exito' : 'error',
            $n > 0 ? "{$n} pedido(s) eliminado(s)." : 'No se eliminó ninguno: no llegó ninguna fila seleccionada.');
        break;
}

header("Location: {$vista}");
exit();
