<?php
// modules/usuarios/controller_usuarios.php
// Las acciones de Administrar usuarios: crear, editar, eliminar y descargar el backup.
// Todas son POST con token, y las tres primeras terminan en la pantalla con el aviso verde o rojo
// (ese mismo texto queda en Trazabilidad). Las reglas están en model_usuarios.php.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_usuarios.php';

requierePermiso('modulo_usuarios', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// Vuelve a la misma página y búsqueda en la que estaba.
$vista = BASE_URL . '/usuarios' . (($_POST['volver'] ?? '') !== '' && str_starts_with((string) $_POST['volver'], '?') ? $_POST['volver'] : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vista}");
    exit();
}

validarCSRF();

$idSesion = (int) $_SESSION['usuario_id'];

// La foto que vino en el formulario, con lo que necesita el modelo para revisarla y moverla.
$fotoSubida = function () {
    $f = $_FILES['foto'] ?? null;
    if (!$f) {
        return [null, fn() => false];
    }
    return [['error' => $f['error'], 'size' => $f['size'], 'tmp' => $f['tmp_name']],
            fn($destino) => is_uploaded_file($f['tmp_name']) && move_uploaded_file($f['tmp_name'], $destino)];
};

switch ($_POST['accion'] ?? '') {

    case 'crear':
        $resultado = crearUsuario($pdo, $_POST, $_POST['contrasena'] ?? '', $_POST['contrasena_confirmar'] ?? '');
        if ($resultado['exito']) {
            [$archivo, $mover] = $fotoSubida();
            $foto = guardarFotoUsuario($pdo, $resultado['id'], $archivo, $mover);
            if ($foto && !$foto['exito']) {
                $resultado['mensaje'] .= ' La foto no se guardó: ' . $foto['mensaje'];
            }
        }
        break;

    case 'editar':
        $idUsuario = (int) ($_POST['id_usuario'] ?? 0);
        $resultado = editarUsuario($pdo, $idUsuario, $_POST, $_POST['contrasena'] ?? '', $_POST['contrasena_confirmar'] ?? '', $idSesion);
        if ($resultado['exito']) {
            [$archivo, $mover] = $fotoSubida();
            $foto = guardarFotoUsuario($pdo, $idUsuario, $archivo, $mover);
            if ($foto && !$foto['exito']) {
                $resultado['mensaje'] .= ' La foto no se guardó: ' . $foto['mensaje'];
            } elseif ($foto) {
                $resultado['mensaje'] .= ' Foto nueva guardada.';
            }
            // Si se editó a sí mismo, el menú lateral lo muestra ya (auth_guard lo refresca en
            // cada pantalla de todas formas).
            if ($idUsuario === $idSesion && ($cuenta = obtenerUsuario($pdo, $idSesion))) {
                $_SESSION['usuario_nombre'] = $cuenta['nombre_usuario'];
                $_SESSION['usuario_imagen'] = $cuenta['imagen_url_Usuario'];
            }
        }
        break;

    case 'eliminar':
        $resultado = eliminarUsuario($pdo, $_POST['id_usuario'] ?? 0, $idSesion);
        break;

    case 'backup':
        if (!tienePermiso('usuarios_backup')) {
            guardarMensajeFlash('error', 'acceso_denegado');
            header("Location: {$vista}");
            exit();
        }
        @set_time_limit(600);
        try {
            $ruta = generarBackupUsuarios($pdo, ROOT_PATH . '/backups');
        } catch (Throwable $e) {
            error_log('Error generando el backup: ' . $e->getMessage());
            guardarMensajeFlashTexto('error', 'No se pudo generar el backup. Intentalo de nuevo.');
            header("Location: {$vista}");
            exit();
        }
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . basename($ruta) . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit();

    default:
        header("Location: {$vista}");
        exit();
}

guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
header("Location: {$vista}");
exit();
