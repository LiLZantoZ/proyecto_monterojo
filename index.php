<?php
// index.php
// EL ENRUTADOR. Toda dirección del sistema entra por acá (ver .htaccess) y se resuelve con la tabla
// de config/rutas.php:
//
//   /proyecto_monterojo/              → al login (que rebota al panel si ya hay sesión)
//   /proyecto_monterojo/picking       → modules/picking/views/picking.php
//   /proyecto_monterojo/modules/...   → las direcciones VIEJAS: ver más abajo
//   cualquier otra cosa               → 404
//
// El archivo de la ruta se incluye acá mismo, y no dentro de una función, A PROPÓSITO: los archivos
// del sistema usan variables globales —$pdo sobre todo, que las funciones viejas buscan con
// `global $pdo`—, y un include hecho adentro de una función las volvería locales y las perdería.
// Por eso también las variables de este archivo llevan el prefijo $_enrutador: quedan visibles para
// el archivo incluido y no pueden chocar con las suyas.

require_once __DIR__ . '/config/config.php';

$_enrutadorTabla = require __DIR__ . '/config/rutas.php';

// La ruta pedida: sin la carpeta del proyecto, sin la query y sin barras en los bordes.
$_enrutadorCamino = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if (BASE_URL !== '' && stripos($_enrutadorCamino, BASE_URL) === 0) {
    $_enrutadorCamino = substr($_enrutadorCamino, strlen(BASE_URL));
}
$_enrutadorCamino = trim($_enrutadorCamino, '/');

// ---------------- La raíz ----------------
if ($_enrutadorCamino === '' || strcasecmp($_enrutadorCamino, 'index.php') === 0) {
    header('Location: ' . URL_LOGIN);
    exit();
}

// ---------------- Una ruta de la tabla ----------------
// Sin distinguir mayúsculas: quien tipea /Picking quiere ir a /picking.
$_enrutadorRuta = strtolower($_enrutadorCamino);

// TRAZABILIDAD (2026-10-06): las acciones (POST y descargas) quedan anotadas solas al terminar.
// Ver config/actividad.php.
require_once __DIR__ . '/config/actividad.php';

if (isset($_enrutadorTabla[$_enrutadorRuta])) {
    iniciarRegistroDeActividad($_enrutadorRuta);
    require __DIR__ . '/' . $_enrutadorTabla[$_enrutadorRuta];
    exit();
}

// ---------------- Una dirección VIEJA (/modules/.../algo.php) ----------------
// Quedan los marcadores guardados, los enlaces copiados y las pantallas que alguien tenía abiertas
// en el momento del cambio. No se les da un 404:
//
//   · pedida con GET: se redirige a la dirección nueva, con la misma query. El usuario llega a donde
//     iba y la barra ya muestra la dirección nueva.
//   · pedida con POST (un formulario de una pantalla abierta antes del cambio): se atiende tal cual.
//     Un POST no se puede redirigir sin perder lo que se estaba enviando —el navegador lo repetiría
//     como GET, vacío—, y rechazarlo haría perder lo que la persona acababa de cargar.
//
// Solo valen los archivos que están en la tabla. Un modelo, un helper o un pedazo de pantalla no
// tienen dirección nueva y caen en el 404 de abajo.
$_enrutadorViejas = array_change_key_case(array_flip($_enrutadorTabla), CASE_LOWER);

if (isset($_enrutadorViejas[$_enrutadorRuta])) {
    $_enrutadorMetodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($_enrutadorMetodo === 'GET' || $_enrutadorMetodo === 'HEAD') {
        $_enrutadorQuery = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . BASE_URL . '/' . $_enrutadorViejas[$_enrutadorRuta]
             . ($_enrutadorQuery !== '' ? '?' . $_enrutadorQuery : ''), true, 301);
        exit();
    }

    iniciarRegistroDeActividad($_enrutadorViejas[$_enrutadorRuta]);
    require __DIR__ . '/' . $_enrutadorTabla[$_enrutadorViejas[$_enrutadorRuta]];
    exit();
}

// ---------------- Todo lo demás ----------------
// Un 404 seco, igual para una ruta mal escrita que para un archivo interno que existe: la respuesta
// no tiene que dejar adivinar qué archivos hay en el servidor.
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página no encontrada · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: Arial, Helvetica, sans-serif; background: #1a0f0f; color: #f5efe9; }
        .caja { text-align: center; padding: 32px; }
        h1 { font-size: 64px; margin: 0; color: #c0392b; }
        p { margin: 12px 0 24px; }
        a { color: #1a0f0f; background: #f5efe9; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="caja">
        <h1>404</h1>
        <p>La página que buscás no existe.</p>
        <a href="<?php echo htmlspecialchars(URL_LOGIN); ?>">Ir al sistema</a>
    </div>
</body>
</html>
