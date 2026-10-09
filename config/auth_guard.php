<?php
// config/auth_guard.php
// Guardián de las pantallas privadas. Se incluye al principio de TODA vista o controlador que no
// sea el login: si no hay sesión válida, corta la ejecución antes de imprimir un solo byte.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cargar la configuración para tener acceso a URL_LOGIN y sesionUsuarioActiva()
require_once __DIR__ . '/config.php';

// 1. Evita que el navegador guarde en caché las páginas protegidas (si no, el botón Atrás
// muestra la pantalla de un usuario que ya cerró sesión)
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache");                                   // HTTP 1.0
header("Expires: 0");                                         // Proxies

// 2. Si NO existe la variable de sesión, expulsa al usuario al login
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . URL_LOGIN . "?error=no_session");
    exit();
}

// 3. Si la sesión existe pero lleva más de TIEMPO_INACTIVIDAD_SEGUNDOS sin actividad, se destruye
// por completo y se expulsa al login con un mensaje explicativo
if (!sesionUsuarioActiva()) {
    header("Location: " . URL_LOGIN . "?error=inactividad");
    exit();
}

// 4. La cuenta se vuelve a leer en cada pantalla (2026-10-06, Administrar usuarios): si un
// administrador la desactivó, la eliminó o le quitó el rol, la sesión se cierra YA —no cuando
// venza—; si le cambió el rol, el nombre o la foto, se ve desde la próxima pantalla. Es una
// consulta por la clave primaria: no se nota.
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmtCuenta = $pdo->prepare("SELECT u.estado, u.id_rol, u.nombre_usuario, u.imagen_url_Usuario, r.nombre_rol
                                     FROM usuarios u LEFT JOIN roles r ON r.id_rol = u.id_rol WHERE u.id_usuario = ?");
        $stmtCuenta->execute([$_SESSION['usuario_id']]);
        $cuentaSesion = $stmtCuenta->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cuentaSesion = null;   // si la consulta falla, se sigue como antes (no se expulsa a nadie por eso)
        error_log('auth_guard: no se pudo releer la cuenta: ' . $e->getMessage());
    }
    if ($cuentaSesion === false || ($cuentaSesion && ($cuentaSesion['estado'] !== 'Activo' || empty($cuentaSesion['id_rol'])))) {
        destruirSesionActual();
        header("Location: " . URL_LOGIN . "?error=" . ($cuentaSesion === false ? 'no_session' : ($cuentaSesion['estado'] !== 'Activo' ? 'usuario_inactivo' : 'rol_no_permitido')));
        exit();
    }
    if ($cuentaSesion) {
        $_SESSION['usuario_rol']    = $cuentaSesion['id_rol'];
        $_SESSION['nombre_rol']     = $cuentaSesion['nombre_rol'];
        $_SESSION['usuario_nombre'] = $cuentaSesion['nombre_usuario'];
        $_SESSION['usuario_imagen'] = $cuentaSesion['imagen_url_Usuario'];
    }
}
