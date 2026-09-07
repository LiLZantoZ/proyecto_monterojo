<?php
// index.php
// La raíz del proyecto no tiene pantalla propia: manda al login (que a su vez rebota al panel si
// ya hay una sesión abierta). Existe además para que entrar a la carpeta no muestre el listado de
// archivos del servidor.

require_once __DIR__ . '/config/config.php';

header("Location: " . URL_LOGIN);
exit();
