<?php
// modules/perfil/model_perfil.php
// Lo que cualquier usuario puede cambiar de SU PROPIA cuenta: nombre, teléfono, foto y
// contraseña. No hace falta ningún permiso especial —a diferencia del resto de los módulos—
// porque no es administrar a otros, es editarse a uno mismo; lo único que hace falta es estar
// logueado (ver auth_guard.php en el controlador y la vista).

require_once __DIR__ . '/../../config/config.php';

const PERFIL_CONTRASENA_LARGO_MINIMO = 8;

function obtenerUsuarioPerfil($pdo, $idUsuario) {
    $stmt = $pdo->prepare(
        "SELECT u.id_usuario, u.nombre_usuario, u.cedula_usuario, u.telefono_usuario,
                u.imagen_url_Usuario, u.fecha_creacion, u.fecha_ultimo_acceso, r.nombre_rol
         FROM usuarios u
         LEFT JOIN roles r ON r.id_rol = u.id_rol
         WHERE u.id_usuario = :id"
    );
    $stmt->execute([':id' => $idUsuario]);
    return $stmt->fetch() ?: null;
}

/**
 * Nombre y teléfono. La cédula no se toca desde acá: es lo que se usa para iniciar sesión, y
 * cambiarla es una operación distinta —con más consecuencias— que "actualizar mis datos".
 * La cédula SÍ se puede cambiar desde acá (a diferencia de lo que se pensó al principio): es un
 * dato de la persona, no un secreto, y no hay ninguna razón para que solo un administrador pueda
 * corregir una mal escrita. Eso sí, tiene que seguir siendo solo números —es con lo que se
 * inicia sesión— y no puede repetir la de otro usuario (ver la comprobación de abajo).
 */
function actualizarDatosPerfil($pdo, $idUsuario, $nombre, $cedula, $telefono) {
    $nombre   = trim((string) $nombre);
    $cedula   = trim((string) $cedula);
    $telefono = trim((string) $telefono);

    if ($nombre === '') {
        return ['exito' => false, 'mensaje' => 'El nombre no puede quedar vacío.'];
    }
    if (mb_strlen($nombre) > 255) {
        return ['exito' => false, 'mensaje' => 'El nombre es demasiado largo.'];
    }
    if ($cedula === '' || !ctype_digit($cedula)) {
        return ['exito' => false, 'mensaje' => 'La cédula tiene que ser un número, sin puntos ni espacios.'];
    }
    if (strlen($cedula) > 20) {
        return ['exito' => false, 'mensaje' => 'Esa cédula es demasiado larga.'];
    }
    if ($telefono !== '' && !preg_match('/^[0-9+\-\s]{6,15}$/', $telefono)) {
        return ['exito' => false, 'mensaje' => 'Ese teléfono no parece válido.'];
    }

    // Se comprueba a mano en vez de confiar solo en la UNIQUE KEY de la tabla: así el mensaje
    // dice CLARAMENTE que la cédula ya está en uso, en vez de que password_hash y compañía
    // revienten con un error genérico de MySQL que nadie sabría interpretar en pantalla.
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE cedula_usuario = :cedula AND id_usuario != :id");
    $stmt->execute([':cedula' => $cedula, ':id' => $idUsuario]);
    if ($stmt->fetch()) {
        return ['exito' => false, 'mensaje' => 'Ya hay otra cuenta con esa cédula.'];
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET nombre_usuario = :nombre, cedula_usuario = :cedula, telefono_usuario = :telefono
             WHERE id_usuario = :id"
        );
        $stmt->execute([
            ':nombre'   => $nombre,
            ':cedula'   => $cedula,
            ':telefono' => $telefono === '' ? null : $telefono,
            ':id'       => $idUsuario,
        ]);
        return ['exito' => true, 'mensaje' => 'Tus datos se actualizaron.', 'nombre' => $nombre];
    } catch (PDOException $e) {
        error_log('Error actualizando el perfil: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar. Intentalo de nuevo.'];
    }
}

// Guarda la ruta (relativa a la raíz del proyecto) de la nueva foto de perfil.
function actualizarImagenPerfil($pdo, $idUsuario, $rutaRelativa) {
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET imagen_url_Usuario = :ruta WHERE id_usuario = :id");
        $stmt->execute([':ruta' => $rutaRelativa, ':id' => $idUsuario]);
        return ['exito' => true, 'mensaje' => 'Tu foto de perfil se actualizó.'];
    } catch (PDOException $e) {
        error_log('Error guardando la foto de perfil: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar la foto. Intentalo de nuevo.'];
    }
}

/**
 * Cambia la contraseña, pidiendo la actual para confirmar que quien está en el teclado es el
 * dueño de la cuenta y no alguien que encontró la sesión abierta.
 */
function cambiarContrasenaPerfil($pdo, $idUsuario, $actual, $nueva, $confirmacion) {
    $actual       = (string) $actual;
    $nueva        = (string) $nueva;
    $confirmacion = (string) $confirmacion;

    if ($actual === '' || $nueva === '' || $confirmacion === '') {
        return ['exito' => false, 'mensaje' => 'Completá los tres campos.'];
    }

    $stmt = $pdo->prepare("SELECT contrasena_usuario FROM usuarios WHERE id_usuario = :id");
    $stmt->execute([':id' => $idUsuario]);
    $hashActual = $stmt->fetchColumn();

    if ($hashActual === false || !password_verify($actual, $hashActual)) {
        return ['exito' => false, 'mensaje' => 'La contraseña actual no es correcta.'];
    }

    if (mb_strlen($nueva) < PERFIL_CONTRASENA_LARGO_MINIMO) {
        return ['exito' => false, 'mensaje' => 'La contraseña nueva tiene que tener al menos ' . PERFIL_CONTRASENA_LARGO_MINIMO . ' caracteres.'];
    }
    if ($nueva !== $confirmacion) {
        return ['exito' => false, 'mensaje' => 'La confirmación no coincide con la contraseña nueva.'];
    }
    if ($nueva === $actual) {
        return ['exito' => false, 'mensaje' => 'La contraseña nueva tiene que ser distinta de la actual.'];
    }

    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET contrasena_usuario = :hash WHERE id_usuario = :id");
        $stmt->execute([':hash' => password_hash($nueva, PASSWORD_DEFAULT), ':id' => $idUsuario]);
        return ['exito' => true, 'mensaje' => 'Tu contraseña se cambió.'];
    } catch (PDOException $e) {
        error_log('Error cambiando la contraseña: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo cambiar la contraseña. Intentalo de nuevo.'];
    }
}
