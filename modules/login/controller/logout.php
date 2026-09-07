<?php
// modules/login/controller/logout.php
// Cierra la sesión y devuelve al login.

require_once __DIR__ . '/../../../config/config.php';

session_start();

// Destruye la sesión por completo: variables, cookie y datos en el servidor
destruirSesionActual();

// Cabeceras anti-caché: sin esto, el botón Atrás puede mostrar la última pantalla privada tal
// como quedó, ya sin sesión detrás
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Si el cierre lo disparó el temporizador de inactividad del navegador, se avisa por qué; de lo
// contrario fue un clic en "Cerrar sesión" y no hay nada que explicar.
if (($_GET['motivo'] ?? '') === 'inactividad') {
    header("Location: " . URL_LOGIN . "?error=inactividad");
} else {
    header("Location: " . URL_LOGIN);
}
exit();
