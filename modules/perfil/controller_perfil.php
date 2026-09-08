<?php
// modules/perfil/controller_perfil.php
// Tres acciones, todas sobre la cuenta de QUIEN ESTÁ LOGUEADO (nunca sobre un id que venga del
// formulario): actualizar_datos, cambiar_contrasena, subir_imagen.
//
// Son formularios normales con redirección, como personal.php, y no fetch/JSON: es una sola
// pantalla de configuración personal, no una tabla con muchas filas que necesiten actualizarse
// sin recargar.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_perfil.php';

$vistaPerfil = BASE_URL . '/modules/perfil/views/perfil.php';

// El id sale de la SESIÓN, no de $_POST: así nadie puede mandar el id de otro usuario y editar
// una cuenta que no es la suya.
$idUsuario = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vistaPerfil}");
    exit();
}

validarCSRF();

$accion = $_POST['accion'] ?? '';

switch ($accion) {

    case 'actualizar_datos':
        $resultado = actualizarDatosPerfil($pdo, $idUsuario, $_POST['nombre'] ?? '', $_POST['cedula'] ?? '', $_POST['telefono'] ?? '');
        if ($resultado['exito']) {
            // Para que el sidebar (y cualquier otro lugar que lea $_SESSION) muestre el nombre
            // nuevo YA, sin tener que cerrar sesión y volver a entrar.
            $_SESSION['usuario_nombre'] = $resultado['nombre'];
        }
        break;

    case 'cambiar_contrasena':
        $resultado = cambiarContrasenaPerfil(
            $pdo,
            $idUsuario,
            $_POST['contrasena_actual'] ?? '',
            $_POST['contrasena_nueva'] ?? '',
            $_POST['contrasena_confirmar'] ?? ''
        );
        break;

    case 'subir_imagen':
        $resultado = subirImagenPerfil($pdo, $idUsuario);
        if ($resultado['exito']) {
            $_SESSION['usuario_imagen'] = $resultado['ruta'];
        }
        break;

    default:
        header("Location: {$vistaPerfil}");
        exit();
}

guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);

header("Location: {$vistaPerfil}");
exit();

// -------------------------------------------------------------------------------------------
// SUBIR LA FOTO DE PERFIL
//
// Va acá y no en el modelo porque valida un $_FILES —una entrada HTTP, no un dato de negocio—,
// que es exactamente la frontera que separa al controlador del modelo en el resto del proyecto
// (ver archivoExcelSubidoOSalir en controller_consolidados.php, el mismo criterio).
// -------------------------------------------------------------------------------------------
function subirImagenPerfil($pdo, $idUsuario) {
    $archivo = $_FILES['imagen'] ?? null;

    if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
        $mensaje = ($archivo['error'] ?? null) === UPLOAD_ERR_INI_SIZE || ($archivo['error'] ?? null) === UPLOAD_ERR_FORM_SIZE
            ? 'Esa imagen pesa demasiado.'
            : 'No se pudo recibir la imagen. Probá de nuevo.';
        return ['exito' => false, 'mensaje' => $mensaje];
    }

    // is_uploaded_file: sin esto, un POST armado a mano podría pasar una ruta del servidor en
    // tmp_name y hacer que el sistema "suba" un archivo que nadie subió.
    if (!is_uploaded_file($archivo['tmp_name'])) {
        return ['exito' => false, 'mensaje' => 'No se pudo recibir la imagen. Probá de nuevo.'];
    }

    $limiteBytes = 3 * 1024 * 1024;   // 3 MB: de sobra para una foto de perfil
    if ($archivo['size'] > $limiteBytes) {
        return ['exito' => false, 'mensaje' => 'La imagen no puede pesar más de 3 MB.'];
    }

    // getimagesize() y no solo la extensión del nombre: un .jpg puede ser cualquier cosa por
    // dentro, y esto abre el archivo y comprueba que sea de verdad una imagen antes de aceptarlo.
    $info = @getimagesize($archivo['tmp_name']);
    $extensionesPermitidas = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF  => 'gif',
    ];

    if ($info === false || !isset($extensionesPermitidas[$info[2]])) {
        return ['exito' => false, 'mensaje' => 'El archivo tiene que ser una imagen (JPG, PNG, WEBP o GIF).'];
    }

    $carpeta = ROOT_PATH . '/assets/img/perfiles';
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0775, true);
    }

    // Nombre propio y no el original: evita que dos personas subiendo "foto.jpg" se pisen el
    // archivo, y evita también tener que sanitizar un nombre que vino del cliente.
    $nombreArchivo = 'usuario_' . $idUsuario . '_' . bin2hex(random_bytes(4)) . '.' . $extensionesPermitidas[$info[2]];
    $destino = $carpeta . '/' . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
        return ['exito' => false, 'mensaje' => 'No se pudo guardar la imagen. Intentalo de nuevo.'];
    }

    // La ruta VIEJA se guarda antes de pisarla en la base, para poder borrar ese archivo después
    // —y solo si es uno que subió este mismo mecanismo (vive en esta carpeta): así nunca se toca
    // la imagen de marca ni la de otro usuario por una coincidencia de ruta.
    $anterior = obtenerUsuarioPerfil($pdo, $idUsuario)['imagen_url_Usuario'] ?? null;

    $rutaRelativa = 'assets/img/perfiles/' . $nombreArchivo;
    $resultado = actualizarImagenPerfil($pdo, $idUsuario, $rutaRelativa);

    if ($resultado['exito']) {
        if ($anterior && str_starts_with($anterior, 'assets/img/perfiles/')) {
            @unlink(ROOT_PATH . '/' . $anterior);
        }
        $resultado['ruta'] = $rutaRelativa;
    } else {
        // La base no se pudo actualizar: el archivo recién subido queda huérfano si se deja, así
        // que se borra en vez de acumular fotos que ningún usuario terminó usando.
        @unlink($destino);
    }

    return $resultado;
}
