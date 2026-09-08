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
$host   = getenv('DB_HOST') ?: 'localhost';
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
        `sku` varchar(30) NOT NULL COMMENT 'Material de SAP; el código propio del producto',
        `ean` varchar(20) DEFAULT NULL COMMENT 'Puente con el Consolidado de la cadena',
        `plu` varchar(30) DEFAULT NULL COMMENT 'Código del producto en la cadena',
        `descripcion` varchar(255) DEFAULT NULL,
        `unidades_por_caja` int(11) DEFAULT NULL COMMENT 'Sin esto no se pueden calcular cajas ni saldos',
        `presentacion` varchar(30) DEFAULT NULL COMMENT 'Como viene en el nombre: PX20, BX6x16...',
        `peso_unidad_kg` decimal(10,4) DEFAULT NULL COMMENT 'Peso bruto de UNA unidad',
        `linea` varchar(60) DEFAULT NULL COMMENT 'Línea o negocio; permite filtrar el consolidado',
        `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`sku`),
        UNIQUE KEY `ean` (`ean`),
        UNIQUE KEY `plu` (`plu`),
        KEY `linea` (`linea`)
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

// ==============================================================================================
// ROLES
// El id va explícito y no lo elige el AUTO_INCREMENT: así una instalación nueva y una que ya
// estaba andando tienen los MISMOS números, y un id_rol copiado de una base sirve en la otra.
// ==============================================================================================

$roles = [
    1 => ['Administrador', 'Acceso completo al sistema.'],
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
];

$permisosPorRol = [
    1 => ['modulo_consolidados', 'modulo_maestro', 'modulo_picking', 'modulo_personal', 'modulo_historial'],
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
