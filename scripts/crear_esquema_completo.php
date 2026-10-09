<?php
// scripts/crear_esquema_completo.php
// ÚNICO punto de entrada para levantar la base de datos desde cero.
//
//   C:\xampp\php\php.exe scripts\crear_esquema_completo.php
//
// Qué hace:
//   1. Crea la base si no existe (proyecto_monterojo, o lo que diga DB_NAME).
//   2. Crea las tablas del núcleo: roles, permisos, rolespermisos, usuarios y las dos de
//      control de intentos de login.
//   3. Siembra los roles.
//
// POR QUÉ EXISTE
// Para que una copia recién clonada del proyecto pueda levantar la base sin que nadie tenga que
// pasar un volcado .sql por fuera. Los volcados no van al repositorio (son datos reales), así que
// sin este archivo el esquema viviría únicamente en el MySQL de una máquina.
//
// Es idempotente: CREATE TABLE IF NOT EXISTS e INSERT IGNORE en todo. Correrlo sobre una base que
// ya existe no toca ni un dato — es la forma de verificar que no falta nada.
//
// SI SE AGREGA UNA TABLA O UNA COLUMNA, VA ACÁ TAMBIÉN. Este archivo es la definición de la base,
// no la foto de un día.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo puede ejecutarse desde la línea de comandos.');
}

// A diferencia del resto del proyecto, este script NO carga config/config.php: ese archivo se
// conecta directo a DB_NAME y hace die() si no existe, que es justamente la situación que este
// script viene a resolver. Por eso lee las mismas variables de entorno por su cuenta, con los
// mismos valores por defecto — si se cambian allá, hay que cambiarlas acá.
$host   = getenv('DB_HOST') ?: '127.0.0.1';   // no 'localhost': ver el comentario de DB_HOST en config/config.php
$nombre = getenv('DB_NAME') ?: 'proyecto_monterojo';
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: '';

$opciones = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    // Primera conexión SIN nombre de base: es la única forma de poder crearla.
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, $opciones);
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo conectar a MySQL en {$host}: " . $e->getMessage() . "\n");
    fwrite(STDERR, "¿Está encendido MySQL en XAMPP?\n");
    exit(1);
}

// El nombre de la base no puede ir como parámetro preparado (PDO los escapa como valores, y acá
// es un identificador), así que se valida a mano antes de interpolarlo.
if (!preg_match('/^[A-Za-z0-9_]+$/', $nombre)) {
    fwrite(STDERR, "El nombre de base '{$nombre}' tiene caracteres no permitidos.\n");
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE `{$nombre}`");
echo "Base '{$nombre}' lista.\n\n";

// ==============================================================================================
// TABLAS
// El orden importa: una tabla no puede referenciar por clave foránea a otra que todavía no
// existe. Por eso primero van las que otras necesitan (roles, permisos) y después las que
// dependen de ellas (rolespermisos, usuarios).
// ==============================================================================================

$tablas = [];

$tablas['roles'] = "
    CREATE TABLE IF NOT EXISTS roles (
        `id_rol` int(11) NOT NULL AUTO_INCREMENT,
        `nombre_rol` varchar(100) NOT NULL,
        `descripcion` text DEFAULT NULL,
        PRIMARY KEY (`id_rol`),
        UNIQUE KEY `nombre_rol` (`nombre_rol`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Un permiso por PANTALLA o por acción sensible. El nombre es el que se le pasa a tienePermiso()
// en el código; por convención los que dibujan una entrada del menú empiezan con 'modulo_'.
$tablas['permisos'] = "
    CREATE TABLE IF NOT EXISTS permisos (
        `id_permiso` int(11) NOT NULL AUTO_INCREMENT,
        `nombre_permiso` varchar(100) NOT NULL,
        `descripcion` text DEFAULT NULL,
        PRIMARY KEY (`id_permiso`),
        UNIQUE KEY `nombre_permiso` (`nombre_permiso`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// ON DELETE CASCADE en las dos claves: si se borra un rol o un permiso, sus asignaciones se van
// con él. Sin eso quedarían filas apuntando a nada, y tienePermiso() se volvería impredecible.
$tablas['rolespermisos'] = "
    CREATE TABLE IF NOT EXISTS rolespermisos (
        `id_rol` int(11) NOT NULL,
        `id_permiso` int(11) NOT NULL,
        PRIMARY KEY (`id_rol`,`id_permiso`),
        KEY `id_permiso` (`id_permiso`),
        CONSTRAINT `rolespermisos_ibfk_1` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`) ON DELETE CASCADE,
        CONSTRAINT `rolespermisos_ibfk_2` FOREIGN KEY (`id_permiso`) REFERENCES `permisos` (`id_permiso`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// La cédula es varchar y no un entero: es un identificador, no una cantidad. Como número, un cero
// a la izquierda se perdería y una cédula larga podría desbordar.
//
// ON DELETE SET NULL en el rol: borrar un rol no puede borrar a las personas que lo tenían.
// Quedan sin rol, y el login las rechaza con 'rol_no_permitido' hasta que se les asigne otro.
$tablas['usuarios'] = "
    CREATE TABLE IF NOT EXISTS usuarios (
        `id_usuario` int(11) NOT NULL AUTO_INCREMENT,
        `nombre_usuario` varchar(255) NOT NULL,
        `cedula_usuario` varchar(20) NOT NULL,
        `contrasena_usuario` varchar(255) NOT NULL COMMENT 'hash de password_hash(), nunca texto plano',
        `estado` enum('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
        `id_rol` int(11) DEFAULT NULL,
        `telefono_usuario` varchar(15) DEFAULT NULL,
        `imagen_url_Usuario` varchar(255) DEFAULT NULL,
        `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
        `fecha_ultimo_acceso` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id_usuario`),
        UNIQUE KEY `cedula_usuario` (`cedula_usuario`),
        KEY `id_rol` (`id_rol`),
        CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Freno a la fuerza bruta, por IP. Ver config/login_rate_limit.php.
$tablas['intentos_login'] = "
    CREATE TABLE IF NOT EXISTS intentos_login (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ip_address` varchar(45) NOT NULL,
        `intentos` int(11) NOT NULL DEFAULT 1,
        `primer_intento` timestamp NOT NULL DEFAULT current_timestamp(),
        `ultimo_intento` timestamp NOT NULL DEFAULT current_timestamp(),
        `bloqueado_hasta` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ip` (`ip_address`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Segunda capa del mismo freno, por cuenta: sin ella, un atacante con IPs rotativas podría
// probar contraseñas contra UNA cédula sin activar nunca el bloqueo por IP.
$tablas['intentos_login_cuenta'] = "
    CREATE TABLE IF NOT EXISTS intentos_login_cuenta (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `cedula_usuario` varchar(45) NOT NULL,
        `intentos` int(11) NOT NULL DEFAULT 1,
        `primer_intento` timestamp NOT NULL DEFAULT current_timestamp(),
        `ultimo_intento` timestamp NOT NULL DEFAULT current_timestamp(),
        `bloqueado_hasta` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_cedula` (`cedula_usuario`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// ----------------------------------------------------------------------------------------------
// MAESTRO DE PRODUCTOS
//
// El Consolidado que manda la cadena trae el PLU y el EAN, pero NO la descripción (esa columna
// viene vacía en todas las filas) ni las unidades por caja. Sin unidades por caja no hay forma de
// convertir las unidades del pedido a cajas y saldos, que es lo que se le entrega al elevador y
// lo que va en el rótulo.
//
// La clave es el SKU (el Material de SAP) porque es el código propio, el único que no depende de
// con qué cadena se esté trabajando: el mismo producto tiene un PLU distinto en cada cliente.
//
// El EAN es el PUENTE: es lo único que aparece tanto en el export de SAP como en el Consolidado
// de la cadena, y es lo que permite decir que el Material 36373 y el PLU 3577953 son el mismo
// producto. Por eso va con índice único y no como un dato más.
//
// El PLU se guarda igual, y se completa solo con el del Consolidado cuando el EAN cruza. Sirve
// para el otro camino de carga: un Excel armado a mano que solo tenga PLU y unidades por caja.
// ----------------------------------------------------------------------------------------------
$tablas['maestro_productos'] = "
    CREATE TABLE IF NOT EXISTS maestro_productos (
        `sku` varchar(30) NOT NULL COMMENT 'Material de SAP. El ÚNICO identificador único de un producto',
        `ean` varchar(20) DEFAULT NULL COMMENT 'Puente con el Consolidado. SE REPITE entre productos',
        `plu` varchar(30) DEFAULT NULL COMMENT 'Código en la cadena. SE REPITE entre productos',
        `sku_item` varchar(30) DEFAULT NULL COMMENT 'SKU que trae el archivo, cuando lo trae. Es lo único que identifica un producto sin ambigüedad',
        `descripcion_item` varchar(255) DEFAULT NULL COMMENT 'Descripción del archivo. Desempata cuando dos productos comparten PLU y EAN',
        `descripcion` varchar(255) DEFAULT NULL,
        `unidades_por_caja` int(11) DEFAULT NULL COMMENT 'Sin esto no se pueden calcular cajas ni saldos',
        `presentacion` varchar(30) DEFAULT NULL COMMENT 'Como viene en el nombre: PX20, BX6x16...',
        `peso_unidad_kg` decimal(10,4) DEFAULT NULL COMMENT 'Peso bruto de UNA unidad',
        `linea` varchar(60) DEFAULT NULL COMMENT 'Línea o negocio; permite filtrar el consolidado',
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`sku`),
        -- ean y plu NO son únicos, aunque lo parezcan. Monterojo tiene productos que
        -- comparten los dos y solo se distinguen por el SKU y la descripción: el mismo
        -- item empacado de a 12 y de a 20 por caja lleva el mismo EAN y el mismo PLU,
        -- pero es otro material de SAP y otras unidades por caja (9 pares confirmados el
        -- 2026-09-11). Con UNIQUE acá, importar el maestro funde los dos productos en uno
        -- EN SILENCIO: MySQL resuelve el ON DUPLICATE KEY contra esa clave y pisa la fila
        -- equivocada, dejando unidades por caja de un empaque en el otro y, con eso,
        -- cajas mal contadas en el despacho.
        KEY `ean` (`ean`),
        KEY `plu` (`plu`),
        KEY `linea` (`linea`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// ----------------------------------------------------------------------------------------------
// ENLACES DE LOS RÓTULOS
//
// Cada rótulo impreso lleva un QR que, escaneado con el celular, abre una página con los datos de
// esa caja —incluido el EAN, que NO se imprime en la etiqueta—. Esta tabla es lo que traduce el
// token corto del QR a esos datos.
//
// POR QUÉ UN TOKEN Y NO LOS DATOS ADENTRO DEL QR
// Metiendo los datos en el propio código, el QR queda de 61x61 módulos = 23mm de lado. Con un
// token de 12 caracteres baja a 33x33 = 12,4mm: casi la mitad, que en una etiqueta donde el QR
// convive con el código de barras es la diferencia entre que entre o no.
//
// El token son 12 caracteres al azar (62^12 combinaciones): es lo que hace que el enlace sea
// secreto. La página se abre sin iniciar sesión —quien escanea en el muelle no va a tipear una
// contraseña— así que lo único que la protege es que nadie pueda adivinar la dirección.
//
// La huella es un SHA-256 del contenido del rótulo: si se reimprime la misma caja, se reusa el
// token que ya tenía en vez de crear uno nuevo cada vez.
// ----------------------------------------------------------------------------------------------
$tablas['rotulos_enlace'] = "
    CREATE TABLE IF NOT EXISTS rotulos_enlace (
        `token` varchar(16) NOT NULL COMMENT 'Lo que viaja en el QR; 12 caracteres al azar',
        `huella` char(64) NOT NULL COMMENT 'SHA-256 del contenido, para reusar el token al reimprimir',
        `datos` text NOT NULL COMMENT 'El rótulo en JSON: punto de venta, CEDI, producto, SKU, EAN, caja',
        `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`token`),
        UNIQUE KEY `huella` (`huella`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// ----------------------------------------------------------------------------------------------
// PERSONAL DE ALISTAMIENTO
//
// Quiénes arman los pedidos. Es una tabla APARTE de `usuarios` a propósito: el que alista no
// entra al sistema —no tiene contraseña, ni rol, ni permisos—, y darle un usuario solo para poder
// asignarle un pedido significaría crear cuentas que nadie usa y que hay que mantener activas o
// inactivas sin motivo.
//
// El documento es opcional pero único cuando está: sirve para distinguir dos personas que se
// llaman igual, que en una bodega pasa.
// ----------------------------------------------------------------------------------------------
$tablas['personal'] = "
    CREATE TABLE IF NOT EXISTS personal (
        `id_personal` int(11) NOT NULL AUTO_INCREMENT,
        `nombre` varchar(120) NOT NULL,
        `documento` varchar(20) DEFAULT NULL,
        `cargo` varchar(60) DEFAULT NULL,
        `estado` enum('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
        `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id_personal`),
        UNIQUE KEY `documento` (`documento`),
        KEY `estado` (`estado`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// ----------------------------------------------------------------------------------------------
// CONSOLIDADO
//
// Una fila por importación. Sirve para saber de qué archivo salió lo que se está mirando: sin
// esto, dos cargas del mismo día se vuelven indistinguibles y nadie puede decir si la pantalla
// muestra el archivo viejo o el nuevo.
// ----------------------------------------------------------------------------------------------
// `huella` es un SHA-256 del CONTENIDO importado, no del archivo. Sirve para avisar cuando se
// vuelve a subir un consolidado que ya se había cargado: la cadena reenvía el mismo pedido más de
// una vez, y volver a importarlo borra en silencio las asignaciones de personal que ya se habían
// hecho sobre él. Ver huellaDelConsolidado() en model_consolidados_import.php.
$tablas['consolidado_cargas'] = "
    CREATE TABLE IF NOT EXISTS consolidado_cargas (
        `id_carga` int(11) NOT NULL AUTO_INCREMENT,
        `nombre_archivo` varchar(255) NOT NULL,
        `filas` int(11) NOT NULL DEFAULT 0,
        `huella` char(64) DEFAULT NULL COMMENT 'SHA-256 del contenido importado, para detectar recargas',
        `id_usuario` int(11) DEFAULT NULL,
        `fecha_carga` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id_carga`),
        KEY `id_usuario` (`id_usuario`),
        KEY `huella` (`huella`),
        CONSTRAINT `consolidado_cargas_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// Las líneas del archivo, una por (orden de compra, PLU, punto de venta).
//
// NO se guardan las cajas ni los saldos: se calculan al consultar, cruzando con
// maestro_productos. Si se guardaran, cargar el maestro después de importar dejaría las cajas en
// cero para siempre y habría que reimportar el archivo para verlas — que es justo lo que pasa
// hoy, porque el maestro casi nunca está listo antes que el pedido.
//
// `cantidad_total` es el total del grupo (orden + PLU) y viene solo en la PRIMERA fila de cada
// grupo en el Excel; se copia a todas las filas del grupo al importar, que es más útil que
// guardar 200 nulos.
$tablas['consolidado_lineas'] = "
    CREATE TABLE IF NOT EXISTS consolidado_lineas (
        `id_linea` int(11) NOT NULL AUTO_INCREMENT,
        `id_carga` int(11) NOT NULL,
        `cedi` varchar(120) NOT NULL COMMENT 'Nombre Lugar Entrega Factura, ej. CEDI YUMBO - 09',
        `orden_compra` varchar(40) NOT NULL,
        `tipo_orden` varchar(120) DEFAULT NULL,
        `plu` varchar(30) NOT NULL,
        `ean_item` varchar(20) DEFAULT NULL,
        `ean_punto_venta` varchar(20) DEFAULT NULL,
        `punto_venta` varchar(180) NOT NULL,
        `direccion_punto_venta` varchar(255) DEFAULT NULL,
        `unidades` int(11) NOT NULL DEFAULT 0 COMMENT 'Cantidad Pto Vta',
        `cantidad_total` int(11) DEFAULT NULL COMMENT 'Total del grupo orden+PLU',
        `fecha_documento` date DEFAULT NULL,
        `fecha_minima_entrega` date DEFAULT NULL,
        `fecha_maxima_entrega` date DEFAULT NULL,
        `pedido_sap` varchar(40) DEFAULT NULL COMMENT 'No viene en el archivo: se digita en Picking',
        `id_personal` int(11) DEFAULT NULL COMMENT 'Quién alista esta entrega; se asigna en Picking',
        `despachado` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'La entrega ya salió: desaparece de Picking y Consolidados',
        `fecha_despacho` timestamp NULL DEFAULT NULL,
        `despachado_por` int(11) DEFAULT NULL,
        PRIMARY KEY (`id_linea`),
        KEY `id_carga` (`id_carga`),
        KEY `cedi` (`cedi`),
        KEY `plu` (`plu`),
        KEY `punto_venta` (`punto_venta`),
        KEY `id_personal` (`id_personal`),
        KEY `despachado` (`despachado`),
        CONSTRAINT `consolidado_lineas_ibfk_1` FOREIGN KEY (`id_carga`) REFERENCES `consolidado_cargas` (`id_carga`) ON DELETE CASCADE,
        -- ON DELETE SET NULL: borrar a alguien del personal no puede borrar líneas del pedido.
        -- La entrega queda sin asignar, que es lo correcto.
        CONSTRAINT `consolidado_lineas_ibfk_2` FOREIGN KEY (`id_personal`) REFERENCES `personal` (`id_personal`) ON DELETE SET NULL,
        -- Igual con quién despachó: si se borra ese usuario, el registro de que esta entrega
        -- salió no debe desaparecer, solo pierde de quién fue.
        CONSTRAINT `consolidado_lineas_ibfk_3` FOREIGN KEY (`despachado_por`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// ----------------------------------------------------------------------------------------------
// MIGRACIÓN: maestro_productos pasó de estar clavado por PLU a estarlo por SKU.
//
// CREATE TABLE IF NOT EXISTS no toca una tabla que ya existe, así que sobre una instalación
// anterior el cambio de clave no se aplicaría nunca y las consultas nuevas fallarían buscando
// columnas que no están. Se detecta por la clave primaria y se reconstruye conservando las filas.
// ----------------------------------------------------------------------------------------------
$claveVieja = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maestro_productos'
       AND COLUMN_NAME = 'plu' AND COLUMN_KEY = 'PRI'"
)->fetchColumn();

if ($claveVieja) {
    echo "== Migración: maestro_productos pasa de clave PLU a clave SKU ==\n";
    $pdo->exec("RENAME TABLE maestro_productos TO maestro_productos_viejo");
    $pdo->exec($tablas['maestro_productos']);
    // Solo las filas que traen SKU: sin SKU no hay clave primaria posible. Las que se pierdan se
    // recuperan volviendo a cargar el archivo, que es de donde salieron.
    $pdo->exec(
        "INSERT IGNORE INTO maestro_productos (sku, ean, plu, descripcion, unidades_por_caja, linea)
         SELECT sku, NULLIF(ean, ''), plu, descripcion, unidades_por_caja, linea
         FROM maestro_productos_viejo WHERE sku IS NOT NULL AND sku <> ''"
    );
    $migradas = (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos")->fetchColumn();
    $tenia    = (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos_viejo")->fetchColumn();
    $pdo->exec("DROP TABLE maestro_productos_viejo");
    echo "   {$migradas} de {$tenia} filas conservadas (las que tenían SKU).\n\n";
}

echo "== Tablas ==\n";
foreach ($tablas as $nombreTabla => $sql) {
    $pdo->exec($sql);
    echo "  · {$nombreTabla}\n";
}

// ----------------------------------------------------------------------------------------------
// MIGRACIÓN: consolidado_lineas.id_personal (asignación de quién alista cada entrega).
//
// CREATE TABLE IF NOT EXISTS no agrega columnas a una tabla que ya existe, así que sobre una
// instalación anterior hay que añadirla a mano. Se comprueba antes para que el script se pueda
// correr las veces que haga falta.
// ----------------------------------------------------------------------------------------------
$tieneColumna = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consolidado_lineas'
       AND COLUMN_NAME = 'id_personal'"
)->fetchColumn();

// Misma situación con consolidado_cargas.huella, que se agregó después.
$tieneHuella = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consolidado_cargas'
       AND COLUMN_NAME = 'huella'"
)->fetchColumn();

if (!$tieneHuella) {
    echo "\n== Migración: consolidado_cargas.huella ==\n";
    $pdo->exec(
        "ALTER TABLE consolidado_cargas
         ADD COLUMN `huella` char(64) DEFAULT NULL COMMENT 'SHA-256 del contenido importado, para detectar recargas',
         ADD KEY `huella` (`huella`)"
    );
    // Las cargas anteriores quedan con huella NULL: no se puede calcular sin el archivo original.
    // No molesta — solo significa que a esas no se las va a reconocer como repetidas.
    echo "   Columna agregada.\n";
}

if (!$tieneColumna) {
    echo "\n== Migración: consolidado_lineas.id_personal ==\n";
    $pdo->exec(
        "ALTER TABLE consolidado_lineas
         ADD COLUMN `id_personal` int(11) DEFAULT NULL COMMENT 'Quién alista esta entrega; se asigna en Picking',
         ADD KEY `id_personal` (`id_personal`),
         ADD CONSTRAINT `consolidado_lineas_ibfk_2` FOREIGN KEY (`id_personal`)
             REFERENCES `personal` (`id_personal`) ON DELETE SET NULL"
    );
    echo "   Columna agregada.\n";
}

// Misma situación con las tres columnas del despacho.
$tieneDespachado = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consolidado_lineas'
       AND COLUMN_NAME = 'despachado'"
)->fetchColumn();

if (!$tieneDespachado) {
    echo "\n== Migración: consolidado_lineas.despachado ==\n";
    $pdo->exec(
        "ALTER TABLE consolidado_lineas
         ADD COLUMN `despachado` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'La entrega ya salió: desaparece de Picking y Consolidados',
         ADD COLUMN `fecha_despacho` timestamp NULL DEFAULT NULL,
         ADD COLUMN `despachado_por` int(11) DEFAULT NULL,
         ADD KEY `despachado` (`despachado`),
         ADD CONSTRAINT `consolidado_lineas_ibfk_3` FOREIGN KEY (`despachado_por`)
             REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL"
    );
    echo "   Columnas agregadas.\n";
}

// ----------------------------------------------------------------------------------------------
// MIGRACIÓN (2026-09-11): ean y plu dejan de ser únicos en el maestro.
//
// El esquema afirmaba que un EAN y un PLU identifican un producto. No es cierto en Monterojo: el
// mismo item empacado de a 12 y de a 20 por caja comparte EAN y PLU, y solo se distingue por el
// SKU y la descripción. Con el UNIQUE puesto, subir el maestro fundía los dos productos sin avisar
// —MySQL resolvía el ON DUPLICATE KEY contra esa clave y pisaba la fila de al lado—, mezclando las
// unidades por caja de dos empaques distintos y, con eso, las cajas del despacho.
// ----------------------------------------------------------------------------------------------
$unicosMaestro = $pdo->query(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maestro_productos'
       AND INDEX_NAME IN ('ean', 'plu') AND NON_UNIQUE = 0"
)->fetchAll(PDO::FETCH_COLUMN);

if ($unicosMaestro) {
    echo "\n== Migración: maestro_productos, ean y plu dejan de ser únicos ==\n";
    foreach ($unicosMaestro as $indice) {
        // Se rehace como índice normal: sigue haciendo falta para buscar por EAN o PLU rápido,
        // lo único que cambia es que deja de exigir que no se repitan.
        $pdo->exec("ALTER TABLE maestro_productos DROP INDEX `{$indice}`, ADD KEY `{$indice}` (`{$indice}`)");
        echo "   {$indice}: UNIQUE -> KEY\n";
    }
}

// ----------------------------------------------------------------------------------------------
// MIGRACIÓN (2026-09-11): el Consolidado guarda el SKU y la descripción que trae el archivo.
//
// Sin esto no hay forma de saber cuál de dos productos que comparten PLU y EAN es el que la cadena
// pidió, y Picking tendría que elegir uno al azar. Los dos archivos que se importan traen con qué:
// el de la cadena tiene "Descripcion del item" (y el de Éxito además una columna "SKU"), y el
// export de SAP tiene "Material" y "Texto breve de material".
// ----------------------------------------------------------------------------------------------
$tieneDescripcionItem = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consolidado_lineas'
       AND COLUMN_NAME = 'descripcion_item'"
)->fetchColumn();

if (!$tieneDescripcionItem) {
    echo "\n== Migración: consolidado_lineas.sku_item y descripcion_item ==\n";
    $pdo->exec(
        "ALTER TABLE consolidado_lineas
         ADD COLUMN `sku_item` varchar(30) DEFAULT NULL COMMENT 'SKU que trae el archivo, cuando lo trae. Es lo único que identifica un producto sin ambigüedad',
         ADD COLUMN `descripcion_item` varchar(255) DEFAULT NULL COMMENT 'Descripción del archivo. Desempata cuando dos productos comparten PLU y EAN',
         ADD KEY `sku_item` (`sku_item`)"
    );
    // Las líneas ya importadas quedan sin estos datos: no se pueden recuperar sin el archivo
    // original. Solo significa que esas siguen resolviéndose por PLU/EAN como hasta ahora.
    echo "   Columnas agregadas.\n";
}

// ----------------------------------------------------------------------------------------------
// MIGRACIÓN (2026-09-14): el precio de cada línea del Consolidado
//
// Lo necesita el módulo Órdenes de compra, que suma el VALOR de cada orden. El archivo de la cadena
// trae "Precio Bruto" y "Precio Neto" y hasta ahora el importador los ignoraba. El valor de una
// orden se calcula con el BRUTO: comparado contra la planilla que arma el usuario, el bruto dio
// exacto en 8 de 8 órdenes y el neto solo en 5 (el neto ya viene con el descuento aplicado).
// ----------------------------------------------------------------------------------------------
$tienePrecio = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consolidado_lineas'
       AND COLUMN_NAME = 'precio_bruto'"
)->fetchColumn();

if (!$tienePrecio) {
    echo "\n== Migración: consolidado_lineas.precio_bruto y precio_neto ==\n";
    $pdo->exec(
        "ALTER TABLE consolidado_lineas
         ADD COLUMN `precio_bruto` decimal(14,2) DEFAULT NULL COMMENT 'Precio unitario bruto que trae el archivo de la cadena. Con este se calcula el valor de la orden',
         ADD COLUMN `precio_neto` decimal(14,2) DEFAULT NULL COMMENT 'Precio unitario neto (con descuento). Se guarda, pero el valor de la orden va por el bruto'"
    );
    // Las líneas ya importadas quedan sin precio: el archivo no se guarda, así que no hay de dónde
    // sacarlo. Hay que completarlas con el archivo original o volver a importar.
    echo "   Columnas agregadas.\n";
}

// ----------------------------------------------------------------------------------------------
// MIGRACIÓN (2026-09-14): quién compró cada línea del Consolidado
//
// Hasta ahora todos los consolidados de portal eran del Éxito, y "pedido del Éxito" se deducía de
// que sus puntos de venta traen EAN. Con Farmatodo eso dejó de alcanzar: su archivo es del mismo
// portal, sus tiendas también traen EAN, y sin esta columna aparecerían mezcladas en Cajas por punto
// de venta y en Órdenes de compra, que son solo del Éxito. Además, el PLU que trae Farmatodo es un
// código suyo, y no puede terminar copiado en el maestro como si fuera el PLU del Éxito.
//
// Las líneas ya importadas quedan en NULL: se siguen reconociendo con la regla de antes (ver
// condicionPedidoExito en model_consolidados.php), así que no cambia nada de lo que ya estaba.
// ----------------------------------------------------------------------------------------------
$tieneComprador = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consolidado_lineas'
       AND COLUMN_NAME = 'empresa_compradora'"
)->fetchColumn();

if (!$tieneComprador) {
    echo "\n== Migración: consolidado_lineas.empresa_compradora ==\n";
    $pdo->exec(
        "ALTER TABLE consolidado_lineas
         ADD COLUMN `empresa_compradora` varchar(120) DEFAULT NULL COMMENT 'Razón social de quien hizo el pedido (ALMACENES EXITO S.A, Farmatodo Colombia S.A...). NULL en lo importado antes del 2026-09-14',
         ADD KEY `empresa_compradora` (`empresa_compradora`)"
    );
    echo "   Columna agregada.\n";
}

// ----------------------------------------------------------------------------------------------
// CUBICAJES (2026-09-14): cuánto volumen ocupa UNA caja de cada producto
//
// Es lo que convierte cajas en metros cúbicos en el módulo Órdenes de compra. La clave es el SKU y
// no el EAN a propósito: hay productos que comparten EAN y son de distinta presentación —el 36353
// (PX18) y el 36354 (PX24) tienen el mismo—, y buscar por EAN le daría a uno el volumen de la caja
// del otro. Se carga desde un Excel en la misma pantalla del módulo.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS cubicajes (
        `sku` varchar(30) NOT NULL,
        `ean` varchar(20) DEFAULT NULL,
        `denominacion` varchar(255) DEFAULT NULL,
        `tipo_caja` varchar(40) DEFAULT NULL COMMENT 'Como lo nombra el archivo: PEQUEÑA, INTERMEDIA, GRANDE, ALTA, SALSA',
        `cubicaje_m3` decimal(12,7) NOT NULL COMMENT 'Metros cúbicos que ocupa UNA caja',
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`sku`),
        KEY `ean` (`ean`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ----------------------------------------------------------------------------------------------
// EXCEPCIONES DE ÉXITO DEL MAESTRO (2026-09-23)
//
// Un mismo producto (mismo SKU) puede empacarse distinto y ocupar otro volumen según sea para el
// Éxito o para los demás clientes. El maestro base guarda UN valor por SKU; acá van SOLO las
// EXCEPCIONES del Éxito: los SKU cuyo empaque (unidades por caja) o cubicaje cambian para el Éxito.
// Lo que no tenga excepción usa el valor base. Ver model_maestro_exito.php.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS maestro_exito (
        `sku` varchar(30) NOT NULL,
        `descripcion` varchar(255) DEFAULT NULL COMMENT 'Solo para mostrar en la pantalla',
        `unidades_por_caja` int(11) DEFAULT NULL COMMENT 'Empaque para Éxito; null = usar el del maestro base',
        `cubicaje_m3` decimal(12,7) DEFAULT NULL COMMENT 'Volumen por caja para Éxito; null = usar el de cubicajes',
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`sku`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ----------------------------------------------------------------------------------------------
// LA HOJA DE PRODUCTOS DEL EXCEL MANDA (2026-10-02)
//
// Pedido del usuario: la hoja de productos que trae el Excel del Consolidado (EAN, SKU,
// DENOMINACIÓN) reescribe la maestra y completa lo que le falte. Como las cadenas nombran distinto
// el mismo SKU (el Éxito pide el 36366 de a 8 y Cencosud lo nombra de a 16), cada línea guarda
// además las unidades por caja que le dio SU archivo: así, subir el Excel de otra cadena cambia la
// maestra pero no le cambia las cajas a lo que ya estaba cargado. NULL = la línea no trajo hoja y
// usa la maestra, como siempre.
//
// maestro_cambios es el registro de lo que cada archivo le cambió a la maestra, para poder ver
// cuándo cambió un producto y volverlo atrás.
// ----------------------------------------------------------------------------------------------
$tieneEmpaqueHoja = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consolidado_lineas'
       AND COLUMN_NAME = 'unidades_por_caja_hoja'"
)->fetchColumn();

if (!$tieneEmpaqueHoja) {
    echo "\n== Migración: consolidado_lineas.unidades_por_caja_hoja ==\n";
    $pdo->exec(
        "ALTER TABLE consolidado_lineas
         ADD COLUMN `unidades_por_caja_hoja` int(11) DEFAULT NULL COMMENT 'Unidades por caja que dice el nombre del producto en la hoja de productos del Excel de esta carga. NULL = usar la maestra'"
    );
    echo "   Columna agregada.\n";
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS maestro_cambios (
        `id_cambio` int(11) NOT NULL AUTO_INCREMENT,
        `sku` varchar(30) NOT NULL,
        `campo` varchar(30) NOT NULL COMMENT 'descripcion, unidades_por_caja, ean o producto nuevo',
        `antes` varchar(255) DEFAULT NULL,
        `despues` varchar(255) DEFAULT NULL,
        `origen` varchar(255) DEFAULT NULL COMMENT 'Archivo que hizo el cambio',
        `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id_cambio`),
        KEY `sku` (`sku`),
        KEY `fecha` (`fecha`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
// ----------------------------------------------------------------------------------------------
// ALMACENES DEL ÉXITO (2026-09-14): la lista oficial Dependencia → Nombre
//
// Con ella cada punto de venta del Éxito se guarda como "DEPENDENCIA - NOMBRE OFICIAL", aunque el
// Consolidado lo haya escrito sin número o con otro nombre (ver model_almacenes_exito.php). La carga
// inicial sale de scripts/datos/almacenes_exito.csv —la lista "Dep exito.xlsx" que pasó el usuario—
// con INSERT IGNORE: si la lista ya se actualizó desde la pantalla, el CSV no pisa esos nombres.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS almacenes_exito (
        `dependencia` int(10) UNSIGNED NOT NULL COMMENT 'Número de punto de venta del Éxito',
        `nombre` varchar(150) NOT NULL COMMENT 'Nombre oficial del almacén',
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`dependencia`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$csvAlmacenes = __DIR__ . '/datos/almacenes_exito.csv';
if (is_file($csvAlmacenes) && ($archivoCsv = fopen($csvAlmacenes, 'r'))) {
    fgetcsv($archivoCsv, 0, ';');   // títulos
    $sembrar = $pdo->prepare("INSERT IGNORE INTO almacenes_exito (dependencia, nombre) VALUES (?, ?)");
    $sembrados = 0;
    while (($filaCsv = fgetcsv($archivoCsv, 0, ';')) !== false) {
        if (count($filaCsv) >= 2 && ctype_digit(trim($filaCsv[0])) && trim($filaCsv[1]) !== '') {
            $sembrar->execute([(int) $filaCsv[0], trim($filaCsv[1])]);
            $sembrados += $sembrar->rowCount();
        }
    }
    fclose($archivoCsv);
    echo "== Almacenes del Éxito: {$sembrados} agregado(s) desde el CSV ==\n";
}

// ----------------------------------------------------------------------------------------------
// AJUSTES (2026-09-14): valores sueltos que se cambian desde la pantalla y no desde el código
//
// El primero es el límite de volumen que decide el carro (turbo o minimula) en Órdenes de compra.
// Es una tabla clave → valor y no una columna en otra tabla porque no pertenece a ningún registro:
// es una regla del negocio, y la próxima (por ejemplo, cuántos m³ entran en una estiba) va acá sin
// migrar nada.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ajustes (
        `clave` varchar(60) NOT NULL,
        `valor` varchar(255) DEFAULT NULL,
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`clave`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ----------------------------------------------------------------------------------------------
// VEHÍCULOS (2026-09-14): la flota con la que se elige el carro de cada orden de compra
//
// Reemplaza al "límite de m³" que se había dejado provisorio: el usuario pasó la tabla de su
// plataforma de transporte con la capacidad de cada vehículo. Órdenes de compra elige, para cada
// orden, el vehículo de MENOR capacidad en el que entran su peso y su volumen.
//
// Solo van los vehículos SECOS: los refrigerados no aplican (instrucción del usuario). El
// MOTOCARRO se carga desactivado: la tabla lo trae con 0 m³ y el usuario indicó que no se usa.
//
// TURBO 6 SECA aparece DOS veces en la tabla del usuario, con datos distintos (7.500 kg · 20 m³ y
// 2.800 kg · 25 m³). Son dos vehículos y quedan los dos, para que se pueda elegir cualquiera según
// el peso de la orden (decisión del usuario, 2026-09-14). Llevan el peso en el nombre porque el
// nombre es único y es lo que se muestra en la columna Carro: sin eso no se sabría cuál de los dos
// se eligió.
//
// INSERT IGNORE por nombre: volver a correr este script no pisa lo que el usuario haya corregido
// desde la pantalla.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS vehiculos (
        `id_vehiculo` int(11) NOT NULL AUTO_INCREMENT,
        `nombre` varchar(60) NOT NULL,
        `peso_kg` int(11) NOT NULL COMMENT 'Carga útil máxima',
        `estibas` int(11) NOT NULL DEFAULT 0,
        `m3` decimal(8,2) NOT NULL COMMENT 'Volumen máximo de carga',
        `activo` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Los inactivos no se eligen como carro',
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id_vehiculo`),
        UNIQUE KEY `nombre` (`nombre`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$flota = [
    // nombre,               peso kg, estibas, m³,  activo
    ['CAMIONETA',              600,  1,  6,    1],
    ['LUV SECA',              1500,  2,  5,    1],
    ['TURBO 4 SECA',          2500,  4, 10,    1],
    ['TURBO 6 SECA · 2.800 kg', 2800,  6, 25,  1],   // los dos turbos 6: ver arriba
    ['TURBO 6 SECA · 7.500 kg', 7500,  6, 20,  1],
    ['TURBO 8 SECA',          4500,  8, 25,    1],
    ['TURBO 10 SECA',         5000, 10, 32,    1],
    ['TURBO 12 SECA',         5500, 12, 35,    1],
    ['SENCILLO SECO',         8000, 12, 35,    1],
    ['DOBLETROQUE SECO',     13000, 14, 45,    1],
    ['MINIMULA SECA',        18000, 20, 60,    1],
    ['TRACTOMULA SECA',      34000, 22, 77,    1],
    ['DOBLEPISO',            35000, 48, 90,    1],
    ['MOTOCARRO',              375,  0,  0,    0],   // 0 m³ en la tabla; el usuario no lo usa
];
// Migración de la primera versión de la flota (2026-09-14, el mismo día): tenía UN solo
// "TURBO 6 SECA" con 2.800 kg y 20 m³, lo menor de las dos filas en conflicto. Si todavía está, se
// convierte en el turbo de 2.800 kg con sus 25 m³ reales; el de 7.500 kg lo agrega el INSERT de
// abajo. Sin esto, una base que ya había corrido el script terminaría con tres turbos 6.
$turboViejo = $pdo->query("SELECT COUNT(*) FROM vehiculos WHERE nombre = 'TURBO 6 SECA'")->fetchColumn();
$turboNuevo = $pdo->query("SELECT COUNT(*) FROM vehiculos WHERE nombre = 'TURBO 6 SECA · 2.800 kg'")->fetchColumn();
if ($turboViejo && !$turboNuevo) {
    echo "\n== Migración: TURBO 6 SECA pasa a ser dos vehículos ==\n";
    $pdo->exec("UPDATE vehiculos SET nombre = 'TURBO 6 SECA · 2.800 kg', peso_kg = 2800, estibas = 6, m3 = 25
                WHERE nombre = 'TURBO 6 SECA'");
    echo "   Listo.\n";
}

$insertarVehiculo = $pdo->prepare("INSERT IGNORE INTO vehiculos (nombre, peso_kg, estibas, m3, activo) VALUES (?, ?, ?, ?, ?)");
foreach ($flota as $v) {
    $insertarVehiculo->execute($v);
}

// ----------------------------------------------------------------------------------------------
// TRABAJOS DE IMPRESIÓN REMOTA (2026-09-15): la cola para el modo IMPRESION_ROTULOS_MODO=remota
//
// Cuando la etiquetadora está conectada a OTRA PC (ver config/config.php), el servidor no le
// imprime directo: deja acá el trabajo TSPL y un agente que corre en esa PC
// (scripts/agente_impresion_remota.ps1) lo reclama y lo imprime ahí. `tspl` es MEDIUMBLOB y no
// TEXT a propósito: el trabajo trae el logo como bitmap, bytes binarios de verdad, y una columna
// de texto podría alterarlos al pasar por el charset de la conexión.
//
// pendiente  → recién encolado, nadie lo pidió todavía.
// reclamado  → un agente lo pidió y se lo llevó; está esperando que confirme si pudo imprimir.
// entregado  → el agente confirmó que imprimió bien.
// error      → el agente lo intentó y falló (impresora apagada, sin papel, etc: mensaje_error).
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS trabajos_impresion_remota (
        `id_trabajo` int(11) NOT NULL AUTO_INCREMENT,
        `tspl` mediumblob NOT NULL,
        `etiquetas` int(11) NOT NULL,
        `estado` enum('pendiente','reclamado','entregado','error') NOT NULL DEFAULT 'pendiente',
        `mensaje_error` varchar(255) DEFAULT NULL,
        `id_usuario` int(11) DEFAULT NULL,
        `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
        `fecha_entrega` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id_trabajo`),
        KEY `estado` (`estado`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ----------------------------------------------------------------------------------------------
// SEGUIMIENTO DE PEDIDOS (2026-09-22): el estado de cada factura/guía en la transportadora.
//
// Un tablero que junta en un solo lugar el estado de los despachos, sin importar por qué
// transportadora salgan (AGV, Proeslog, Solística, entregas propias). Cada fila es una guía o
// factura con sus fechas y su estado; de dónde salió el dato queda en `fuente` ('manual', 'excel'
// y, cuando haya acceso oficial, el nombre de la integración).
//
// La `clave` es lo que evita duplicados al reimportar un Excel o al sincronizar: se arma con la
// transportadora, la factura y la guía normalizadas (ver seguimiento_pedidos en el modelo), y es
// UNIQUE, así que subir dos veces el mismo listado actualiza en vez de duplicar.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS seguimiento_pedidos (
        `id_pedido` int(11) NOT NULL AUTO_INCREMENT,
        `clave` varchar(255) NOT NULL COMMENT 'transportadora|factura|guia normalizadas: identifica la fila para no duplicar',
        `transportadora` varchar(80) NOT NULL,
        `numero_factura` varchar(60) DEFAULT NULL,
        `numero_guia` varchar(60) DEFAULT NULL,
        `estado` varchar(80) DEFAULT NULL COMMENT 'Texto libre: cada transportadora tiene sus propios estados',
        `detalle` varchar(500) DEFAULT NULL COMMENT 'Descripción de la factura / detalles del pedido',
        `bodega` varchar(80) DEFAULT NULL,
        `destino` varchar(160) DEFAULT NULL COMMENT 'Cliente o punto de venta que recibe',
        `ciudad_destino` varchar(80) DEFAULT NULL COMMENT 'Ciudad a la que va el envío, según la transportadora',
        `fecha_guia` date DEFAULT NULL,
        `fecha_despacho` datetime DEFAULT NULL,
        `fecha_entrega` datetime DEFAULT NULL,
        `fecha_estado` datetime DEFAULT NULL COMMENT 'Desde cuándo está en ese estado (Proeslog: fechaestadoguia)',
        `direccion` varchar(255) DEFAULT NULL COMMENT 'SAP facturación: Población - Calle',
        `nit` varchar(40) DEFAULT NULL COMMENT 'SAP facturación: Nº ident.fis.1',
        `valor_neto` decimal(16,2) DEFAULT NULL COMMENT 'SAP facturación: suma de Valor neto de la factura',
        `referencia` varchar(60) DEFAULT NULL COMMENT 'SAP facturación: Referencia',
        `pedido_cliente` varchar(80) DEFAULT NULL COMMENT 'SAP facturación: Pedido Cliente',
        `fuente` varchar(40) NOT NULL DEFAULT 'manual',
        `id_usuario` int(11) DEFAULT NULL,
        `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id_pedido`),
        UNIQUE KEY `clave` (`clave`),
        KEY `transportadora` (`transportadora`),
        KEY `estado` (`estado`),
        KEY `fecha_despacho` (`fecha_despacho`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// La ciudad de destino (2026-10-05), para las instalaciones que ya tenían la tabla.
$pdo->exec("ALTER TABLE seguimiento_pedidos ADD COLUMN IF NOT EXISTS `ciudad_destino` varchar(80) DEFAULT NULL COMMENT 'Ciudad a la que va el envío, según la transportadora' AFTER `destino`");
// La fecha del estado (2026-10-02), para las instalaciones que ya tenían la tabla.
$pdo->exec("ALTER TABLE seguimiento_pedidos ADD COLUMN IF NOT EXISTS `fecha_estado` datetime DEFAULT NULL COMMENT 'Desde cuándo está en ese estado (Proeslog: fechaestadoguia)' AFTER `fecha_entrega`");

// ----------------------------------------------------------------------------------------------
// CONSOLIDADO MR (2026-09-24): el export de facturación de SAP renglón por renglón (una fila por
// producto facturado). Se carga subiendo el Excel "CONSOLIDADO MR"; `factura` no se muestra en la
// tabla pero es la llave para reemplazar una factura al volver a subirla. Ver model_consolidado_mr.php.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS consolidado_mr (
        `id_linea` int(11) NOT NULL AUTO_INCREMENT,
        `factura` varchar(30) NOT NULL,
        `fecha_factura` date DEFAULT NULL,
        `solicitante` varchar(30) DEFAULT NULL COMMENT 'Solic.',
        `nombre_cliente` varchar(160) DEFAULT NULL COMMENT 'Nombre 1',
        `poblacion` varchar(80) DEFAULT NULL,
        `material` varchar(30) DEFAULT NULL,
        `texto_material` varchar(255) DEFAULT NULL COMMENT 'Texto breve de material',
        `cantidad_facturada` decimal(14,3) DEFAULT NULL COMMENT 'Ctd.facturada',
        `valor_neto` decimal(16,2) DEFAULT NULL,
        `referencia` varchar(60) DEFAULT NULL,
        `doc_ventas` varchar(30) DEFAULT NULL COMMENT 'Doc.ventas',
        `pedido_cliente` varchar(80) DEFAULT NULL,
        `anulado` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = el renglón trae X en Anulado./An.: la factura está anulada',
        `id_usuario` int(11) DEFAULT NULL,
        `fecha_carga` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id_linea`),
        KEY `factura` (`factura`),
        KEY `fecha_factura` (`fecha_factura`),
        KEY `material` (`material`),
        KEY `nombre_cliente` (`nombre_cliente`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ----------------------------------------------------------------------------------------------
// CONSOLIDADO MR: TRANSPORTADOR Y FACTURAS NO PAGADAS (2026-10-02)
//
// consolidado_mr_envios: el transportador que se le pone a mano a una factura que no tiene guía en
// el consolidado de las transportadoras (casi siempre INTERNO). Va en tabla aparte y no en
// consolidado_mr porque esa se reemplaza cada vez que se vuelve a subir la factura.
//
// consolidado_mr_cartera: el listado de facturas no pagadas que se sube en su propia pestaña, y si
// ya se pagaron. `clave` es el número normalizado (ver claveFacturaMr) para no duplicar al volver
// a subir el listado; `factura_sap` es el cruce con el Consolidado MR, cuando se encuentra.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS consolidado_mr_envios (
        `factura` varchar(30) NOT NULL COMMENT 'Factura de SAP, la llave del Consolidado MR',
        `transportadora` varchar(80) NOT NULL,
        `id_usuario` int(11) DEFAULT NULL,
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`factura`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// consolidado_mr_estados_manuales (2026-10-07): el estado y la fecha de entrega puestos a mano a una
// factura. Mandan sobre el reporte de la transportadora; vacíos, la fila se borra.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS consolidado_mr_estados_manuales (
        `factura` varchar(30) NOT NULL COMMENT 'Factura de SAP, la llave del Consolidado MR',
        `estado` varchar(80) DEFAULT NULL,
        `fecha_entrega` date DEFAULT NULL,
        `id_usuario` int(11) DEFAULT NULL,
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`factura`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS consolidado_mr_cartera (
        `id_cartera` int(11) NOT NULL AUTO_INCREMENT,
        `clave` varchar(60) NOT NULL COMMENT 'Número de factura normalizado, para no duplicar',
        `factura` varchar(60) NOT NULL COMMENT 'Como se muestra: la Referencia del Consolidado MR si cruzó',
        `factura_sap` varchar(30) DEFAULT NULL COMMENT 'Cruce con consolidado_mr.factura',
        `cliente` varchar(160) DEFAULT NULL,
        `valor` decimal(16,2) DEFAULT NULL COMMENT 'Valor o saldo que trae el archivo',
        `fecha_factura` date DEFAULT NULL,
        `fecha_vencimiento` date DEFAULT NULL,
        `pagada` tinyint(1) NOT NULL DEFAULT 0,
        `fecha_pago` datetime DEFAULT NULL,
        `pagada_por` int(11) DEFAULT NULL,
        `id_usuario` int(11) DEFAULT NULL COMMENT 'Quién la subió',
        `fecha_carga` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id_cartera`),
        UNIQUE KEY `clave` (`clave`),
        KEY `factura_sap` (`factura_sap`),
        KEY `pagada` (`pagada`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
// ----------------------------------------------------------------------------------------------
// RESPONSABLE DE EMPAQUE (2026-10-05): quién alistó y despachó cada factura del Consolidado MR. Lo
// enlaza la bodega en "Enlazar facturas" (factura + cédula del operario). Una fila por factura de
// SAP; el nombre y la cédula se guardan como estaban al enlazar, aunque después cambien en Personal.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS consolidado_mr_responsables (
        `factura` varchar(30) NOT NULL COMMENT 'Factura de SAP, la llave del Consolidado MR',
        `referencia` varchar(60) DEFAULT NULL COMMENT 'NU… al momento de enlazar, para mostrar',
        `id_personal` int(11) DEFAULT NULL,
        `documento` varchar(20) NOT NULL,
        `nombre` varchar(120) NOT NULL,
        `cajas` smallint(5) unsigned DEFAULT NULL COMMENT 'Número de cajas del pedido, escrito al enlazar',
        `id_usuario` int(11) DEFAULT NULL COMMENT 'Quién registró el enlace',
        `fecha_enlace` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`factura`),
        KEY `id_personal` (`id_personal`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
// El número de cajas del pedido (2026-10-05), para las bases que ya tenían la tabla.
$pdo->exec("ALTER TABLE consolidado_mr_responsables ADD COLUMN IF NOT EXISTS `cajas` smallint(5) unsigned DEFAULT NULL COMMENT 'Número de cajas del pedido, escrito al enlazar' AFTER `nombre`");

// ----------------------------------------------------------------------------------------------
// FACTURAS DE CONTADO (2026-10-05): la X de "Anulado." del Consolidado MR marca las facturas de
// contado (no son anuladas). consolidado_mr_contado guarda el Sí/No actual de cada factura que alguna
// vez fue de contado (y si vino del archivo o lo marcó alguien); el historial, cada cambio.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS consolidado_mr_contado (
        `factura` varchar(30) NOT NULL COMMENT 'Factura de SAP, la llave del Consolidado MR',
        `referencia` varchar(60) DEFAULT NULL COMMENT 'NU… para mostrar',
        `de_contado` tinyint(1) NOT NULL COMMENT '1 = de contado, 0 = no (se conserva para la trazabilidad)',
        `origen` enum('archivo','manual','comparativa') NOT NULL COMMENT 'manual = lo eligió alguien; comparativa = el archivo de Subir comparativa; archivo = (ya no se usa)',
        `id_usuario` int(11) DEFAULT NULL COMMENT 'Quién hizo el último cambio',
        `fecha_cambio` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'La primera vez que se marcó',
        PRIMARY KEY (`factura`),
        KEY `de_contado` (`de_contado`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS consolidado_mr_contado_historial (
        `id_cambio` int(11) NOT NULL AUTO_INCREMENT,
        `factura` varchar(30) NOT NULL,
        `de_contado` tinyint(1) NOT NULL,
        `origen` enum('archivo','manual','comparativa') NOT NULL,
        `id_usuario` int(11) DEFAULT NULL,
        `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id_cambio`),
        KEY `factura` (`factura`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// Para las bases que ya tenían estas tablas (2026-10-05): la X de anulada en cada renglón y la
// comparativa como origen del "de contado".
$pdo->exec("ALTER TABLE consolidado_mr ADD COLUMN IF NOT EXISTS `anulado` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = el renglón trae X en Anulado./An.: la factura está anulada' AFTER `pedido_cliente`");
$pdo->exec("ALTER TABLE consolidado_mr_contado MODIFY `origen` enum('archivo','manual','comparativa') NOT NULL");
$pdo->exec("ALTER TABLE consolidado_mr_contado_historial MODIFY `origen` enum('archivo','manual','comparativa') NOT NULL");

// ----------------------------------------------------------------------------------------------
// PEDIDOS (2026-10-06): los pedidos de los clientes contra el inventario de cada sede. La base (BD
// Clientes, CIUDADES, OBSERVACIONES e INVENTARIO del Excel PEDIDOS MONTEROJO), los renglones del
// archivo PEDIDOS, los inventarios de Bogotá y Copacabana y la última carga de cada archivo.
// ----------------------------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS pedidos_clientes (
        `deudor` varchar(20) NOT NULL COMMENT 'Código del cliente en SAP (Solicitante del pedido)',
        `nombre` varchar(160) DEFAULT NULL,
        `poblacion` varchar(80) DEFAULT NULL COMMENT 'La ciudad: sale la columna CIUDAD del pedido',
        PRIMARY KEY (`deudor`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Hoja BD Clientes del archivo PEDIDOS MONTEROJO'"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS pedidos_ciudades (
        `ciudad` varchar(80) NOT NULL COMMENT 'En mayúsculas y sin tildes, para comparar',
        `sede` varchar(20) NOT NULL COMMENT 'Quién la atiende: BOGOTA o COPACABANA',
        PRIMARY KEY (`ciudad`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Hoja CIUDADES del archivo PEDIDOS MONTEROJO'"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS pedidos_observaciones (
        `deudor` varchar(20) NOT NULL,
        `observacion` varchar(255) NOT NULL COMMENT 'Ej. SALE DESDE COPACABANA',
        PRIMARY KEY (`deudor`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Hoja OBSERVACIONES del archivo PEDIDOS MONTEROJO'"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS pedidos_productos (
        `material` varchar(30) NOT NULL,
        `texto` varchar(255) DEFAULT NULL,
        `centro` varchar(60) DEFAULT NULL COMMENT 'La columna Monterojo del pedido',
        PRIMARY KEY (`material`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Hoja INVENTARIO (columnas I:K) del archivo PEDIDOS MONTEROJO'"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS pedidos_lineas (
        `id_linea` int(11) NOT NULL AUTO_INCREMENT,
        `numero_pedido` varchar(80) DEFAULT NULL COMMENT 'Nº de pedido (la orden de compra del cliente)',
        `fecha_documento` date DEFAULT NULL,
        `clase_doc` varchar(10) DEFAULT NULL,
        `documento` varchar(30) DEFAULT NULL COMMENT 'Documento comercial de SAP',
        `creado_por` varchar(40) DEFAULT NULL,
        `solicitante` varchar(20) DEFAULT NULL COMMENT 'Código del cliente',
        `nombre` varchar(160) DEFAULT NULL,
        `moneda` varchar(5) DEFAULT NULL,
        `material` varchar(30) DEFAULT NULL,
        `denominacion` varchar(255) DEFAULT NULL,
        `cantidad` decimal(14,3) DEFAULT NULL,
        `precio_neto` decimal(16,2) DEFAULT NULL,
        `total` decimal(16,2) DEFAULT NULL,
        `motivo_rechazo` varchar(80) DEFAULT NULL,
        `creado_el` date DEFAULT NULL,
        `hora` time DEFAULT NULL,
        `observaciones` varchar(255) DEFAULT NULL,
        `condicion_pago` varchar(80) DEFAULT NULL,
        PRIMARY KEY (`id_linea`),
        KEY `documento` (`documento`),
        KEY `material` (`material`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='El archivo PEDIDOS: lo que piden los clientes'"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS pedidos_inventario (
        `sede` varchar(20) NOT NULL COMMENT 'BOGOTA o COPACABANA',
        `material` varchar(30) NOT NULL,
        `texto` varchar(255) DEFAULT NULL,
        `libre` decimal(14,3) NOT NULL DEFAULT 0 COMMENT 'Libre utilización (sumada entre lotes)',
        `bloqueado` decimal(14,3) NOT NULL DEFAULT 0,
        `calidad` decimal(14,3) NOT NULL DEFAULT 0 COMMENT 'En control de calidad',
        PRIMARY KEY (`sede`, `material`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Los archivos INVENTARIO BOGOTA e INVENTARIO COPA'"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS pedidos_cargas (
        `tipo` varchar(30) NOT NULL COMMENT 'base, pedidos, inventario_BOGOTA, inventario_COPACABANA',
        `archivo` varchar(255) DEFAULT NULL,
        `detalle` varchar(255) DEFAULT NULL,
        `id_usuario` int(11) DEFAULT NULL,
        `fecha` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`tipo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='La última carga de cada archivo del módulo Pedidos'"
);

// EL ASISTENTE (MonteBot, 2026-10-07, traído de Nutrium): la conversación de cada persona y lo que cuesta
// cada llamada a Claude (de ahí sale el freno de presupuesto). El costo se guarda ya calculado: los
// precios cambian, y recalcular el histórico con la tarifa de hoy daría un número que nunca se pagó.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS chatbot_mensajes (
        `id_mensaje` int(11) NOT NULL AUTO_INCREMENT,
        `id_usuario` int(11) NOT NULL,
        `rol` enum('user','assistant') NOT NULL,
        `mensaje` text NOT NULL,
        `fecha` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id_mensaje`),
        KEY `idx_usuario_fecha` (`id_usuario`, `fecha`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Asistente: la conversación de cada persona'"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS chatbot_uso (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `id_usuario` int(11) DEFAULT NULL,
        `modelo` varchar(60) NOT NULL,
        `tokens_entrada` int(11) NOT NULL DEFAULT 0,
        `tokens_salida` int(11) NOT NULL DEFAULT 0,
        `costo_usd` decimal(12,6) NOT NULL DEFAULT 0,
        `fecha` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_fecha` (`fecha`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Asistente: tokens y costo de cada llamada'"
);

// TRAZABILIDAD Y RENDIMIENTO (2026-10-06, del sistema de bodega): cada acción del sistema, con quién
// la hizo y lo que el sistema le contestó. La llena sola el enrutador (config/actividad.php).
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS historial_actividades (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `id_usuario` int(11) DEFAULT NULL COMMENT 'NULL en un intento fallido de inicio de sesión',
        `modulo` varchar(60) NOT NULL,
        `accion` varchar(150) NOT NULL,
        `detalle` text DEFAULT NULL COMMENT 'Lo que el sistema le contestó: el mensaje de la pantalla, el error o el archivo',
        `resultado` varchar(10) NOT NULL DEFAULT '' COMMENT 'exito, error o vacío',
        `ruta` varchar(80) DEFAULT NULL,
        `ip` varchar(45) DEFAULT NULL,
        `fecha_hora` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_fecha_hora` (`fecha_hora`),
        KEY `idx_usuario_fecha` (`id_usuario`, `fecha_hora`),
        KEY `idx_modulo` (`modulo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Trazabilidad: cada acción del sistema'"
);

// ADMINISTRAR PRODUCTOS (2026-10-06, del sistema de bodega): cada producto físico de la bodega
// (SKU + lote + vencimiento, con su estado). Una estiba de Posiciones apunta a uno; los que no
// están en ninguna posición salen "Sin ubicar".
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS productos (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `sku` varchar(30) NOT NULL,
        `producto` varchar(255) DEFAULT NULL COMMENT 'La descripción del SKU (maestro o lista de Monterojo)',
        `lote` varchar(60) NOT NULL,
        `fecha_vencimiento` date DEFAULT NULL,
        `estado` varchar(30) NOT NULL DEFAULT 'Disponible' COMMENT 'Disponible, Bloqueado, En Control De Calidad o Defectuoso',
        `origen` varchar(30) NOT NULL DEFAULT 'administrar_productos' COMMENT 'administrar_productos o posiciones',
        `id_usuario_registro` int(11) DEFAULT NULL,
        `creado_el` timestamp NOT NULL DEFAULT current_timestamp(),
        `actualizado_el` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `sku_lote_vence` (`sku`, `lote`, `fecha_vencimiento`),
        KEY `fecha_vencimiento` (`fecha_vencimiento`),
        KEY `creado_el` (`creado_el`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Administrar Productos: los productos físicos de la bodega'"
);

// KARDEX (2026-10-06): las entradas y salidas de estibas de las posiciones, por SKU.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS movimientos (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `fecha_hora` datetime NOT NULL DEFAULT current_timestamp(),
        `id_usuario` int(11) DEFAULT NULL,
        `tipo` varchar(20) NOT NULL COMMENT 'Ingreso, Salida o Picking',
        `id_posicion` int(11) DEFAULT NULL,
        `ubicacion` varchar(20) DEFAULT NULL,
        `id_producto` int(11) DEFAULT NULL,
        `sku` varchar(30) NOT NULL,
        `lote` varchar(60) DEFAULT NULL,
        `fecha_vencimiento` date DEFAULT NULL,
        `cantidad_cajas` int(11) DEFAULT NULL,
        `estiba_completa` tinyint(1) NOT NULL DEFAULT 0,
        `observaciones` varchar(500) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `sku_fecha` (`sku`, `fecha_hora`),
        KEY `fecha_hora` (`fecha_hora`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kardex: entradas y salidas de estibas'"
);

// FORMATO CONCILIADOR (2026-10-06, del sistema de bodega): lo que sale de producción, estiba por
// estiba. Cada registro da cupo para ubicar UNA estiba de ese SKU + lote + vencimiento en Posiciones.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS formato_conciliador (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `fecha` date NOT NULL,
        `sku` varchar(30) NOT NULL,
        `descripcion` varchar(255) DEFAULT NULL,
        `lote` varchar(60) NOT NULL,
        `fecha_vencimiento` date DEFAULT NULL,
        `cajas` int(11) NOT NULL DEFAULT 0,
        `saldos` int(11) NOT NULL DEFAULT 0 COMMENT 'Unidades que no completan una caja',
        `unidades_por_caja` int(11) DEFAULT NULL COMMENT 'Del maestro de productos cuando se registró',
        `cantidad` int(11) DEFAULT NULL COMMENT 'Total de unidades: cajas × unidades por caja + saldos',
        `responsable` varchar(120) DEFAULT NULL,
        `codigo_responsable` int(11) DEFAULT NULL,
        `turno` tinyint(3) unsigned DEFAULT NULL COMMENT '1: 05:30-12:30, 2: 12:30-21:00, 3: 21:00-05:30',
        `novedades` varchar(255) DEFAULT NULL,
        `estado` varchar(30) DEFAULT NULL COMMENT 'Disponible, En Control De Calidad, Fecha Corta o Defectuoso',
        `id_usuario_registro` int(11) DEFAULT NULL,
        `creado_el` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `sku_lote_vence` (`sku`, `lote`, `fecha_vencimiento`),
        KEY `fecha` (`fecha`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Formato Conciliador: lo que sale de producción'"
);

// NOTIFICACIONES (2026-10-06, del sistema de bodega): los avisos personales de la campana.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notificaciones (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `id_usuario_emisor` int(11) DEFAULT NULL,
        `id_usuario_receptor` int(11) NOT NULL,
        `mensaje` varchar(500) NOT NULL,
        `tipo` varchar(30) NOT NULL DEFAULT 'aviso' COMMENT 'producto_nuevo, conciliador…',
        `enlace` varchar(255) DEFAULT NULL,
        `leido` tinyint(1) NOT NULL DEFAULT 0,
        `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `receptor_leido` (`id_usuario_receptor`, `leido`, `fecha`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Las notificaciones personales'"
);

// POSICIONES DE BODEGA (2026-10-06): el módulo de posiciones del sistema de bodega, con los racks
// de Monterojo. Cada posición se llama R1M1N1A1 (rack, módulo, nivel, posición): 8 racks × 12
// módulos; los módulos 1 a 11 tienen 5 niveles y el 12 solo los niveles 3, 4 y 5; 4 posiciones
// por nivel (A1, A2, B1, B2) = 1.856. Cada posición guarda a lo sumo una estiba.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS posiciones (
        `id_posicion` int(11) NOT NULL AUTO_INCREMENT,
        `ubicacion` varchar(20) NOT NULL COMMENT 'R1M1N1A1',
        `rack` tinyint(3) unsigned NOT NULL,
        `modulo` tinyint(3) unsigned NOT NULL,
        `nivel` tinyint(3) unsigned NOT NULL,
        `lugar` char(2) NOT NULL COMMENT 'A1, A2, B1 o B2',
        `creado_el` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id_posicion`),
        UNIQUE KEY `ubicacion` (`ubicacion`),
        KEY `rack_modulo_nivel` (`rack`, `modulo`, `nivel`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Las posiciones de los racks de la bodega'"
);
// La estiba de antes de Administrar Productos (con su propio SKU, lote y estado) pasa a apuntar a
// su producto. Solo se cambia vacía: con estibas cargadas habría que pasarlas a mano.
$columnasEstiba = $pdo->query("SHOW COLUMNS FROM posiciones_estibas")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('sku', $columnasEstiba, true)) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM posiciones_estibas")->fetchColumn() === 0) {
        $pdo->exec("DROP TABLE posiciones_estibas");
        echo "  · posiciones_estibas: pasada a la versión con producto (estaba vacía)\n";
    } else {
        echo "  · OJO: posiciones_estibas tiene estibas con el formato viejo; no se cambió.\n";
    }
}
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS posiciones_estibas (
        `id_estiba` int(11) NOT NULL AUTO_INCREMENT,
        `id_posicion` int(11) NOT NULL,
        `id_producto` int(11) NOT NULL COMMENT 'El producto (SKU, lote, vencimiento, estado) de Administrar Productos',
        `cantidad_cajas` int(11) DEFAULT NULL COMMENT 'NULL si es estiba completa',
        `estiba_completa` tinyint(1) NOT NULL DEFAULT 0,
        `observaciones` varchar(500) DEFAULT NULL,
        `id_usuario_registro` int(11) DEFAULT NULL,
        `id_usuario_edicion` int(11) DEFAULT NULL,
        `creado_el` timestamp NOT NULL DEFAULT current_timestamp(),
        `actualizado_el` datetime DEFAULT NULL,
        PRIMARY KEY (`id_estiba`),
        UNIQUE KEY `id_posicion` (`id_posicion`),
        UNIQUE KEY `id_producto` (`id_producto`),
        CONSTRAINT `posiciones_estibas_ibfk_1` FOREIGN KEY (`id_posicion`) REFERENCES `posiciones` (`id_posicion`) ON DELETE CASCADE,
        CONSTRAINT `posiciones_estibas_ibfk_2` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='La estiba que hay en cada posición'"
);

// Las 1.856 posiciones de la estructura (las que ya están no se tocan).
$crearPosicion = $pdo->prepare("INSERT IGNORE INTO posiciones (ubicacion, rack, modulo, nivel, lugar) VALUES (?, ?, ?, ?, ?)");
$posicionesCreadas = 0;
for ($rack = 1; $rack <= 8; $rack++) {
    for ($modulo = 1; $modulo <= 12; $modulo++) {
        foreach ($modulo === 12 ? [3, 4, 5] : [1, 2, 3, 4, 5] as $nivel) {
            foreach (['A1', 'A2', 'B1', 'B2'] as $lugar) {
                $crearPosicion->execute(["R{$rack}M{$modulo}N{$nivel}{$lugar}", $rack, $modulo, $nivel, $lugar]);
                $posicionesCreadas += $crearPosicion->rowCount();
            }
        }
    }
}
echo "  · posiciones: {$posicionesCreadas} creada(s)\n";

// ==============================================================================================
// ROLES
// El id va explícito y no lo elige el AUTO_INCREMENT: así una instalación nueva y una que ya
// estaba andando tienen los MISMOS números, y un id_rol copiado de una base sirve en la otra.
// ==============================================================================================

$roles = [
    1 => ['Administrador', 'Acceso completo al sistema.'],
    // Solo mira: el Inicio y el Consolidado MR, sin cargar ni borrar nada y sin editar su perfil.
    2 => ['Visitante', 'Solo consulta el Inicio y el Consolidado MR.'],
];

echo "\n== Roles ==\n";
$insertarRol = $pdo->prepare("INSERT IGNORE INTO roles (id_rol, nombre_rol, descripcion) VALUES (?, ?, ?)");
foreach ($roles as $id => $datos) {
    $insertarRol->execute([$id, $datos[0], $datos[1]]);
    echo "  · {$id} — {$datos[0]}\n";
}

// ==============================================================================================
// PERMISOS
// Un permiso por pantalla. El nombre es el que se le pasa a tienePermiso() en el código.
// Este es el ÚNICO lugar donde está escrito qué ve cada rol.
// ==============================================================================================

$permisos = [
    'modulo_consolidados' => 'Cargar el Consolidado y sacar el listado por CEDI para el elevador.',
    'modulo_maestro'      => 'Cargar y consultar el maestro de productos (PLU, SKU, unidades por caja).',
    'modulo_picking'      => 'Ver el alistamiento por punto de venta y generar los rótulos.',
    'modulo_personal'     => 'Dar de alta, editar y eliminar el personal de alistamiento.',
    'modulo_historial'    => 'Consultar los pedidos ya despachados y restaurarlos si hizo falta.',
    'modulo_rotulos'      => 'Generar rótulos sueltos, sin que vengan de ningún pedido del sistema.',
    'modulo_cajas_punto_venta' => 'Ver cuántas cajas le corresponden a cada punto de venta de un CEDI.',
    'modulo_ordenes_compra'    => 'Ver las órdenes de compra del Éxito con cajas, volumen, valor y carro, y cargar los cubicajes.',
    'modulo_seguimiento'       => 'Ver el estado de los pedidos en las transportadoras y registrar entregas.',
    'modulo_consolidado_mr'    => 'Consultar el Consolidado MR de SAP (facturación por producto).',
    // Los permisos de ESCRIBIR, aparte de los de ver, para que un rol pueda mirar sin modificar
    // (el Visitante, 2026-09-28).
    'consolidado_mr_editar'    => 'Importar y vaciar el Consolidado MR.',
    'perfil_editar'            => 'Editar el perfil propio (nombre, foto y contraseña).',
    'consolidados_eliminar_todo' => 'Borrar todos los consolidados y el historial de pedidos (pide la contraseña).',
    'seguimiento_eliminar_todo'  => 'Borrar los datos de las transportadoras (pide la contraseña).',
    'modulo_enlazar_facturas'    => 'Enlazar cada factura del Consolidado MR con el operario que la alistó y despachó.',
    'modulo_pedidos'             => 'Ver los pedidos de los clientes contra el inventario de cada sede (qué se puede despachar).',
    'pedidos_editar'             => 'Subir los archivos del módulo Pedidos (clientes y ciudades, pedidos e inventarios).',
    'modulo_posiciones'          => 'Ver las posiciones de los racks de la bodega y la estiba de cada una.',
    'posiciones_crear'           => 'Crear, editar, eliminar e importar posiciones.',
    'posiciones_mover_estibas'   => 'Agregar y sacar estibas de las posiciones.',
    'posiciones_editar_detalle'  => 'Corregir los datos de una estiba ya ubicada.',
    'posiciones_llevar_a_picking' => 'Llevar una estiba a picking: sale de la posición y de Administrar Productos.',
    'modulo_productos'           => 'Ver Administrar Productos (cada producto con su lote, vencimiento, estado y posición) y su Kardex.',
    'productos_editar'           => 'Registrar, editar y eliminar productos en Administrar Productos.',
    'modulo_formato_conciliador' => 'Ver el Formato Conciliador (lo que sale de producción) e imprimir sus rótulos.',
    'formato_conciliador_registrar' => 'Registrar en el Formato Conciliador.',
    'modulo_notificaciones'      => 'Ver la pantalla de Notificaciones (vencimientos y productos nuevos).',
    'notificaciones_vencimiento' => 'Recibir en la campana los avisos de productos vencidos o por vencer.',
    'notificaciones_productos_nuevos' => 'Recibir un aviso cuando se registra un producto nuevo.',
    'modulo_trazabilidad'        => 'Ver la trazabilidad: cada acción del sistema, quién la hizo y cuándo.',
    'modulo_rendimiento'         => 'Ver el rendimiento: la actividad de cada usuario por día, hora y módulo.',
    'modulo_usuarios'            => 'Administrar usuarios: crear, editar y eliminar las cuentas que entran al sistema.',
    'usuarios_backup'            => 'Descargar el backup de la base de datos desde Administrar usuarios.',
    'modulo_chatbot'             => 'Usar el asistente MonteBot (consultas con IA; cada pregunta cuesta dinero).',
];

$permisosPorRol = [
    1 => ['modulo_consolidados', 'modulo_maestro', 'modulo_picking', 'modulo_personal', 'modulo_historial', 'modulo_rotulos', 'modulo_cajas_punto_venta', 'modulo_ordenes_compra', 'modulo_seguimiento', 'modulo_consolidado_mr', 'consolidado_mr_editar', 'perfil_editar', 'consolidados_eliminar_todo', 'seguimiento_eliminar_todo', 'modulo_enlazar_facturas', 'modulo_pedidos', 'pedidos_editar', 'modulo_posiciones', 'posiciones_crear', 'posiciones_mover_estibas', 'posiciones_editar_detalle', 'posiciones_llevar_a_picking', 'modulo_productos', 'productos_editar', 'modulo_formato_conciliador', 'formato_conciliador_registrar', 'modulo_notificaciones', 'notificaciones_vencimiento', 'notificaciones_productos_nuevos', 'modulo_trazabilidad', 'modulo_rendimiento', 'modulo_usuarios', 'usuarios_backup', 'modulo_chatbot'],
    2 => ['modulo_consolidado_mr'],
];

if ($permisos) {
    echo "\n== Permisos ==\n";
    $insertarPermiso = $pdo->prepare("INSERT IGNORE INTO permisos (nombre_permiso, descripcion) VALUES (?, ?)");
    foreach ($permisos as $nombrePermiso => $descripcion) {
        $insertarPermiso->execute([$nombrePermiso, $descripcion]);
        echo "  · {$nombrePermiso}\n";
    }

    echo "\n== Asignación de permisos por rol ==\n";
    // La subconsulta busca el id del permiso por su nombre en vez de usar lastInsertId(): con
    // INSERT IGNORE, un permiso que ya existía no devuelve id nuevo y la asignación se perdería.
    $asignar = $pdo->prepare(
        "INSERT IGNORE INTO rolespermisos (id_rol, id_permiso)
         SELECT ?, id_permiso FROM permisos WHERE nombre_permiso = ?"
    );
    foreach ($permisosPorRol as $idRol => $lista) {
        foreach ($lista as $nombrePermiso) {
            $asignar->execute([$idRol, $nombrePermiso]);
        }
        echo "  · rol {$idRol}: " . count($lista) . " permiso(s)\n";
    }
}

echo "\nListo. Ahora creá el primer usuario con:\n";
echo "  php scripts/crear_usuario_admin.php\n";
