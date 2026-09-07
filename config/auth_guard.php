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
