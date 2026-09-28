<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// El sistema se sirve desde la carpeta del proyecto (http://localhost/monterojo_laravel/) y el
// .htaccess de esa carpeta pasa todo a public/. Sin este ajuste Laravel creería que su dirección
// base es .../public y no reconocería ninguna ruta. Se corrige SCRIPT_NAME para que la base sea la
// carpeta del proyecto, salvo cuando la dirección pedida ya incluye /public/ (p. ej. el QR de los
// rótulos, .../public/rotulo.php): ahí se deja tal cual y la ruta se resuelve como "rotulo.php".
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if (str_ends_with($scriptName, '/public/index.php')
    && !str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/public/')) {
    $_SERVER['SCRIPT_NAME'] = substr($scriptName, 0, -strlen('/public/index.php')) . '/index.php';
    $_SERVER['PHP_SELF']    = $_SERVER['SCRIPT_NAME'];
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
