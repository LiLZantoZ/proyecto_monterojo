<?php
// modules/personal/controller_personal.php
// Alta, edición y baja del personal de alistamiento.
//
// Los tres terminan en una redirección a la pantalla con un aviso: son formularios normales, no
// AJAX, así que un refresco después de guardar no reenvía nada.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_personal.php';

$vistaPersonal = BASE_URL . '/modules/personal/views/personal.php';

requierePermiso('modulo_personal', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// Todas las acciones cambian datos, así que todas son POST y todas validan el token.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vistaPersonal}");
    exit();
}

validarCSRF();

$accion = $_POST['accion'] ?? '';

switch ($accion) {

    case 'crear':
        $resultado = crearPersona($pdo, $_POST['nombre'] ?? '', $_POST['documento'] ?? '', $_POST['cargo'] ?? '');
        break;

    case 'editar':
        $resultado = editarPersona(
            $pdo,
            $_POST['id_personal'] ?? 0,
            $_POST['nombre'] ?? '',
            $_POST['documento'] ?? '',
            $_POST['cargo'] ?? '',
            $_POST['estado'] ?? 'Activo'
        );
        break;

    case 'eliminar':
        $resultado = eliminarPersona($pdo, $_POST['id_personal'] ?? 0);
        break;

    default:
        header("Location: {$vistaPersonal}");
        exit();
}

// El mensaje trae el nombre de la persona, así que va como texto y no como código del catálogo
// (ver guardarMensajeFlashTexto en config/mensajes.php).
guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);

header("Location: {$vistaPersonal}");
exit();
