<?php
// app/Servicios/compat.php
// Lo que la lógica de negocio traída del sistema anterior esperaba de config/config.php:
//   · las constantes (BASE_URL, ROOT_PATH, ROTULO_*...), que define App\Soporte\Constantes;
//   · la conexión $pdo como variable global (la buscan con `global $pdo`), que acá es el MISMO PDO
//     de Laravel —una sola conexión, con los mismos ajustes: ver config/database.php—.
// Las funciones globales (tienePermiso, guardarMensajeFlashTexto, assetVersion...) las carga
// Composer desde app/Soporte/helpers.php.

\App\Soporte\Constantes::definir();
\App\Soporte\Constantes::definirUrlPublicaRotulos();

if (!isset($GLOBALS['pdo'])) {
    $GLOBALS['pdo'] = \Illuminate\Support\Facades\DB::connection()->getPdo();
}
