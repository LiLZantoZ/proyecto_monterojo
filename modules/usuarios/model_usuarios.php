<?php
// modules/usuarios/model_usuarios.php
// ADMINISTRAR USUARIOS (2026-10-06, traído del sistema de bodega): las cuentas que entran al
// sistema —crear, editar, eliminar, foto— y el backup de la base.
//
// En Monterojo la cuenta es más simple que en bodega: el nombre que se ve es nombre_usuario (no hay
// "nombre completo" aparte), se entra con la CÉDULA y no hay tipo de despacho.
//
// LO QUE ESTE MÓDULO NO DEJA HACER, para que nadie se quede afuera del sistema:
//   · desactivarse, eliminarse o quitarse a uno mismo el acceso a este módulo;
//   · dejar el sistema sin ninguna cuenta activa que pueda administrar usuarios.
// Y lo que pasa con lo que ya hizo alguien: sus acciones siguen en Trazabilidad (aparecen como
// "Usuario eliminado"). Si solo dejó de trabajar, lo indicado es ponerlo Inactivo.

require_once __DIR__ . '/../../config/config.php';

const USUARIOS_POR_PAGINA = 25;
const USUARIOS_CONTRASENA_MINIMA = 8;
const USUARIOS_FOTO_MAXIMA = 3 * 1024 * 1024;       // 3 MB, lo mismo que Mi perfil
const USUARIOS_CARPETA_FOTOS = 'assets/img/perfiles/';
const USUARIOS_PERMISO_ADMINISTRAR = 'modulo_usuarios';
const USUARIOS_BACKUP_RETENCION_DIAS = 30;
const USUARIOS_BACKUP_FILAS_POR_INSERT = 200;
// Y nunca más de ~512 KB por INSERT: al restaurar, MySQL rechaza una sentencia más grande que
// max_allowed_packet (1 MB en XAMPP) con "MySQL server has gone away", y trabajos_impresion_remota
// guarda filas de casi 1 MB cada una. Una fila más grande que eso va sola en su INSERT.
const USUARIOS_BACKUP_BYTES_POR_INSERT = 512 * 1024;

/** Los roles para el desplegable: [id_rol => ['nombre_rol', 'descripcion']]. */
function rolesUsuarios($pdo) {
    $roles = [];
    foreach ($pdo->query("SELECT id_rol, nombre_rol, descripcion FROM roles ORDER BY id_rol")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $roles[(int) $r['id_rol']] = $r;
    }
    return $roles;
}

/** Los números de cabecera: cuántas cuentas hay, activas e inactivas. */
function resumenUsuarios($pdo) {
    $f = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(estado = 'Activo'), 0) AS activos,
                             COALESCE(SUM(estado = 'Inactivo'), 0) AS inactivos FROM usuarios")->fetch(PDO::FETCH_ASSOC);
    return array_map('intval', $f);
}

/**
 * Una página de la tabla, con la búsqueda (nombre, cédula, teléfono o rol). Cada fila trae cuántas
 * acciones tiene registradas, para el aviso de eliminar. Nunca trae la contraseña.
 */
function paginaUsuarios($pdo, $buscar, $pagina) {
    $buscar = trim((string) $buscar);
    $w = '';
    $p = [];
    if ($buscar !== '') {
        $w = "WHERE (u.nombre_usuario LIKE ? OR u.cedula_usuario LIKE ? OR u.telefono_usuario LIKE ? OR r.nombre_rol LIKE ?)";
        $p = array_fill(0, 4, '%' . addcslashes($buscar, '%_\\') . '%');
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u LEFT JOIN roles r ON r.id_rol = u.id_rol {$w}");
    $stmt->execute($p);
    $total = (int) $stmt->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / USUARIOS_POR_PAGINA));
    $pagina = min(max(1, (int) $pagina), $totalPaginas);
    $desde = ($pagina - 1) * USUARIOS_POR_PAGINA;

    $stmt = $pdo->prepare(
        "SELECT u.id_usuario, u.nombre_usuario, u.cedula_usuario, u.telefono_usuario, u.estado, u.id_rol,
                u.imagen_url_Usuario, u.fecha_creacion, u.fecha_ultimo_acceso, r.nombre_rol,
                (SELECT COUNT(*) FROM historial_actividades ha WHERE ha.id_usuario = u.id_usuario) AS acciones
         FROM usuarios u
         LEFT JOIN roles r ON r.id_rol = u.id_rol
         {$w}
         ORDER BY u.estado, u.nombre_usuario
         LIMIT " . USUARIOS_POR_PAGINA . " OFFSET {$desde}"
    );
    $stmt->execute($p);
    return ['filas' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pagina' => $pagina, 'total_paginas' => $totalPaginas];
}

function obtenerUsuario($pdo, $idUsuario) {
    $stmt = $pdo->prepare(
        "SELECT u.id_usuario, u.nombre_usuario, u.cedula_usuario, u.telefono_usuario, u.estado, u.id_rol,
                u.imagen_url_Usuario, r.nombre_rol
         FROM usuarios u LEFT JOIN roles r ON r.id_rol = u.id_rol WHERE u.id_usuario = ?"
    );
    $stmt->execute([(int) $idUsuario]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** ¿Ese rol puede entrar a Administrar usuarios? */
function rolAdministraUsuarios($pdo, $idRol) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rolespermisos rp JOIN permisos p ON p.id_permiso = rp.id_permiso
                           WHERE rp.id_rol = ? AND p.nombre_permiso = ?");
    $stmt->execute([(int) $idRol, USUARIOS_PERMISO_ADMINISTRAR]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Las cuentas ACTIVAS que pueden administrar usuarios: si se queda en cero, nadie puede arreglarlo. */
function idsAdministradoresActivos($pdo) {
    $stmt = $pdo->prepare("SELECT u.id_usuario FROM usuarios u
                           JOIN rolespermisos rp ON rp.id_rol = u.id_rol
                           JOIN permisos p ON p.id_permiso = rp.id_permiso AND p.nombre_permiso = ?
                           WHERE u.estado = 'Activo'");
    $stmt->execute([USUARIOS_PERMISO_ADMINISTRAR]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Revisa y limpia los datos del formulario. Devuelve [mensaje de error o null, datos limpios].
 * Las mismas reglas que Mi perfil: la cédula es solo números (con ella se entra) y no se repite.
 */
function validarDatosUsuario($pdo, array $entrada, $idUsuario = 0) {
    $d = [
        'nombre'   => trim((string) ($entrada['nombre'] ?? '')),
        'cedula'   => trim((string) ($entrada['cedula'] ?? '')),
        'telefono' => trim((string) ($entrada['telefono'] ?? '')),
        'estado'   => (string) ($entrada['estado'] ?? 'Activo'),
        'id_rol'   => (int) ($entrada['id_rol'] ?? 0),
    ];
    if ($d['nombre'] === '') {
        return ['Escribí el nombre de la persona.', $d];
    }
    if (mb_strlen($d['nombre']) > 255) {
        return ['El nombre es demasiado largo.', $d];
    }
    if ($d['cedula'] === '' || !ctype_digit($d['cedula'])) {
        return ['La cédula tiene que ser un número, sin puntos ni espacios: es con lo que la persona entra al sistema.', $d];
    }
    if (strlen($d['cedula']) > 20) {
        return ['Esa cédula es demasiado larga.', $d];
    }
    if ($d['telefono'] !== '' && !preg_match('/^[0-9+\-\s]{6,15}$/', $d['telefono'])) {
        return ['Ese teléfono no parece válido.', $d];
    }
    if (!in_array($d['estado'], ['Activo', 'Inactivo'], true)) {
        return ['Elegí si la cuenta queda activa o inactiva.', $d];
    }
    $roles = rolesUsuarios($pdo);
    if (!isset($roles[$d['id_rol']])) {
        return ['Elegí el rol de la cuenta.', $d];
    }
    $d['nombre_rol'] = $roles[$d['id_rol']]['nombre_rol'];

    $stmt = $pdo->prepare("SELECT nombre_usuario FROM usuarios WHERE cedula_usuario = ? AND id_usuario != ?");
    $stmt->execute([$d['cedula'], (int) $idUsuario]);
    if (($otro = $stmt->fetchColumn()) !== false) {
        return ["Ya hay otra cuenta con esa cédula ({$otro}).", $d];
    }
    return [null, $d];
}

/** La contraseña nueva: al crear es obligatoria; al editar, vacía = no se cambia. */
function validarContrasenaUsuario($contrasena, $confirmacion, $obligatoria) {
    $contrasena = (string) $contrasena;
    if ($contrasena === '' && !$obligatoria) {
        return null;
    }
    if (mb_strlen($contrasena) < USUARIOS_CONTRASENA_MINIMA) {
        return 'La contraseña tiene que tener al menos ' . USUARIOS_CONTRASENA_MINIMA . ' caracteres.';
    }
    if ($contrasena !== (string) $confirmacion) {
        return 'La confirmación no coincide con la contraseña.';
    }
    return null;
}

/** Crea la cuenta. Devuelve ['exito', 'mensaje', 'id']. */
function crearUsuario($pdo, array $entrada, $contrasena, $confirmacion) {
    [$error, $d] = validarDatosUsuario($pdo, $entrada);
    $error = $error ?? validarContrasenaUsuario($contrasena, $confirmacion, true);
    if ($error) {
        return ['exito' => false, 'mensaje' => $error, 'id' => null];
    }
    try {
        $pdo->prepare("INSERT INTO usuarios (nombre_usuario, cedula_usuario, contrasena_usuario, estado, id_rol, telefono_usuario)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$d['nombre'], $d['cedula'], password_hash((string) $contrasena, PASSWORD_DEFAULT),
                       $d['estado'], $d['id_rol'], $d['telefono'] === '' ? null : $d['telefono']]);
        $mensaje = "Se creó la cuenta de {$d['nombre']} (cédula {$d['cedula']}, rol {$d['nombre_rol']}).";
        $mensaje .= $d['estado'] === 'Activo'
            ? ' Ya puede entrar con su cédula y la contraseña que le pusiste.'
            : ' Quedó inactiva: no va a poder entrar hasta que la actives.';
        return ['exito' => true, 'mensaje' => $mensaje, 'id' => (int) $pdo->lastInsertId()];
    } catch (PDOException $e) {
        error_log('Error creando el usuario: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo crear la cuenta. Intentalo de nuevo.', 'id' => null];
    }
}

/**
 * Guarda los cambios de una cuenta. $idQuienEdita es el de la sesión: con él se impide que alguien
 * se desactive o se quite el acceso a sí mismo, y que el sistema quede sin administradores.
 * El mensaje dice qué cambió (queda así en Trazabilidad).
 */
function editarUsuario($pdo, $idUsuario, array $entrada, $contrasena, $confirmacion, $idQuienEdita) {
    $actual = obtenerUsuario($pdo, $idUsuario);
    if (!$actual) {
        return ['exito' => false, 'mensaje' => 'No se encontró esa cuenta (puede que la hayan eliminado).'];
    }
    [$error, $d] = validarDatosUsuario($pdo, $entrada, $actual['id_usuario']);
    $error = $error ?? validarContrasenaUsuario($contrasena, $confirmacion, false);
    if ($error) {
        return ['exito' => false, 'mensaje' => $error];
    }

    $esUnoMismo = (int) $actual['id_usuario'] === (int) $idQuienEdita;
    $seguiraAdministrando = $d['estado'] === 'Activo' && rolAdministraUsuarios($pdo, $d['id_rol']);
    if ($esUnoMismo && $d['estado'] !== 'Activo') {
        return ['exito' => false, 'mensaje' => 'No podés desactivar tu propia cuenta: te quedarías sin poder entrar.'];
    }
    if ($esUnoMismo && !$seguiraAdministrando) {
        return ['exito' => false, 'mensaje' => "No podés quitarte a vos mismo el acceso a Administrar usuarios (el rol {$d['nombre_rol']} no lo tiene). Pedile a otro administrador que lo haga."];
    }
    $administradores = idsAdministradoresActivos($pdo);
    if (!$seguiraAdministrando && $administradores === [(int) $actual['id_usuario']]) {
        return ['exito' => false, 'mensaje' => "{$actual['nombre_usuario']} es la única cuenta activa que puede administrar usuarios: si se desactiva o cambia de rol, nadie podría volver a dar accesos."];
    }

    $cambios = [];
    if ($actual['nombre_usuario'] !== $d['nombre'])  { $cambios[] = "nombre: {$actual['nombre_usuario']} → {$d['nombre']}"; }
    if ($actual['cedula_usuario'] !== $d['cedula'])  { $cambios[] = "cédula: {$actual['cedula_usuario']} → {$d['cedula']}"; }
    if ((string) $actual['telefono_usuario'] !== $d['telefono']) { $cambios[] = 'teléfono: ' . ($actual['telefono_usuario'] ?: '—') . ' → ' . ($d['telefono'] ?: '—'); }
    if ($actual['estado'] !== $d['estado'])          { $cambios[] = "estado: {$actual['estado']} → {$d['estado']}"; }
    if ((int) $actual['id_rol'] !== $d['id_rol'])    { $cambios[] = 'rol: ' . ($actual['nombre_rol'] ?: 'sin rol') . " → {$d['nombre_rol']}"; }
    if ((string) $contrasena !== '')                 { $cambios[] = 'contraseña nueva'; }

    try {
        $sql = "UPDATE usuarios SET nombre_usuario = ?, cedula_usuario = ?, telefono_usuario = ?, estado = ?, id_rol = ?";
        $p = [$d['nombre'], $d['cedula'], $d['telefono'] === '' ? null : $d['telefono'], $d['estado'], $d['id_rol']];
        if ((string) $contrasena !== '') {
            $sql .= ", contrasena_usuario = ?";
            $p[] = password_hash((string) $contrasena, PASSWORD_DEFAULT);
        }
        $p[] = (int) $actual['id_usuario'];
        $pdo->prepare($sql . " WHERE id_usuario = ?")->execute($p);
    } catch (PDOException $e) {
        error_log('Error editando el usuario: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudieron guardar los cambios. Intentalo de nuevo.'];
    }

    $mensaje = "Se guardaron los cambios de {$d['nombre']}" . ($cambios ? ' (' . implode('; ', $cambios) . ').' : '.');
    if (!$esUnoMismo && $actual['estado'] === 'Activo' && $d['estado'] === 'Inactivo') {
        $mensaje .= ' Si tenía el sistema abierto, se le cierra la sesión.';
    }
    return ['exito' => true, 'mensaje' => $mensaje, 'cambios' => $cambios];
}

/** Elimina una cuenta (y su foto). Sus acciones quedan en Trazabilidad como "Usuario eliminado". */
function eliminarUsuario($pdo, $idUsuario, $idQuienElimina) {
    $actual = obtenerUsuario($pdo, $idUsuario);
    if (!$actual) {
        return ['exito' => false, 'mensaje' => 'No se encontró esa cuenta (puede que ya la hayan eliminado).'];
    }
    if ((int) $actual['id_usuario'] === (int) $idQuienElimina) {
        return ['exito' => false, 'mensaje' => 'No podés eliminar tu propia cuenta.'];
    }
    if (idsAdministradoresActivos($pdo) === [(int) $actual['id_usuario']]) {
        return ['exito' => false, 'mensaje' => "{$actual['nombre_usuario']} es la única cuenta activa que puede administrar usuarios: no se puede eliminar."];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM historial_actividades WHERE id_usuario = ?");
    $stmt->execute([(int) $actual['id_usuario']]);
    $acciones = (int) $stmt->fetchColumn();

    try {
        $pdo->prepare("DELETE FROM usuarios WHERE id_usuario = ?")->execute([(int) $actual['id_usuario']]);
    } catch (PDOException $e) {
        error_log('Error eliminando el usuario: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo eliminar la cuenta. Intentalo de nuevo.'];
    }
    borrarFotoUsuario($actual['imagen_url_Usuario']);

    $mensaje = "Se eliminó la cuenta de {$actual['nombre_usuario']} (cédula {$actual['cedula_usuario']}, rol " . ($actual['nombre_rol'] ?: 'sin rol') . ').';
    if ($acciones > 0) {
        $mensaje .= ' Sus ' . number_format($acciones, 0, ',', '.') . ' acción(es) siguen en Trazabilidad como «Usuario eliminado».';
    }
    return ['exito' => true, 'mensaje' => $mensaje];
}

/** Borra una foto SOLO si la subió el sistema (vive en perfiles/): nunca la de marca ni otra. */
function borrarFotoUsuario($ruta) {
    if ($ruta && str_starts_with($ruta, USUARIOS_CARPETA_FOTOS) && !str_contains($ruta, '..')) {
        @unlink(ROOT_PATH . '/' . $ruta);
    }
}

/**
 * Guarda la foto de una cuenta. $archivo = ['error', 'size', 'tmp'] y $mover(destino) la mueve
 * (move_uploaded_file en un sistema, el UploadedFile de Laravel en el otro). Sin archivo devuelve
 * null: la foto es opcional. Mismas reglas que Mi perfil: hasta 3 MB y que sea una imagen DE VERDAD.
 */
function guardarFotoUsuario($pdo, $idUsuario, ?array $archivo, callable $mover) {
    if (!$archivo || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return ['exito' => false, 'mensaje' => in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'La foto pesa demasiado.' : 'No se pudo recibir la foto. Probá de nuevo.'];
    }
    if ($archivo['size'] > USUARIOS_FOTO_MAXIMA) {
        return ['exito' => false, 'mensaje' => 'La foto no puede pesar más de 3 MB.'];
    }
    $info = @getimagesize($archivo['tmp']);
    $extensiones = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if ($info === false || !isset($extensiones[$info[2]])) {
        return ['exito' => false, 'mensaje' => 'La foto tiene que ser una imagen (JPG, PNG, WEBP o GIF).'];
    }
    $carpeta = ROOT_PATH . '/' . rtrim(USUARIOS_CARPETA_FOTOS, '/');
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0775, true);
    }
    $nombre = 'usuario_' . (int) $idUsuario . '_' . bin2hex(random_bytes(4)) . '.' . $extensiones[$info[2]];
    if (!$mover($carpeta . '/' . $nombre)) {
        return ['exito' => false, 'mensaje' => 'No se pudo guardar la foto. Intentalo de nuevo.'];
    }
    $anterior = obtenerUsuario($pdo, $idUsuario)['imagen_url_Usuario'] ?? null;
    try {
        $pdo->prepare("UPDATE usuarios SET imagen_url_Usuario = ? WHERE id_usuario = ?")
            ->execute([USUARIOS_CARPETA_FOTOS . $nombre, (int) $idUsuario]);
    } catch (PDOException $e) {
        @unlink($carpeta . '/' . $nombre);
        error_log('Error guardando la foto del usuario: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar la foto. Intentalo de nuevo.'];
    }
    borrarFotoUsuario($anterior);
    return ['exito' => true, 'mensaje' => 'foto nueva', 'ruta' => USUARIOS_CARPETA_FOTOS . $nombre];
}

// -------------------------------------------------------------------------------------------------
// EL BACKUP DE LA BASE (el mismo del sistema de bodega, config/backup_db.php)
//
// Un .sql con la estructura y los datos de todas las tablas, armado con PDO (sin mysqldump ni
// shell, que algunos servidores no permiten). Se escribe a medida que se lee: la memoria no crece
// con el tamaño de la base. Lleva SET NAMES utf8mb4 para que las tildes no se rompan al restaurar.
// Los backups se guardan en $carpeta y se borran solos a los 30 días.
// -------------------------------------------------------------------------------------------------
function generarBackupUsuarios($pdo, $carpeta) {
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0750, true);
    }
    // Nunca se pide por la web (además de la regla del .htaccess de la raíz).
    if (!is_file($carpeta . '/.htaccess')) {
        file_put_contents($carpeta . '/.htaccess', "Require all denied\n");
    }
    $base = (string) $pdo->query("SELECT DATABASE()")->fetchColumn();
    $ruta = rtrim($carpeta, '/\\') . '/backup_' . $base . '_' . date('Y-m-d_His') . '.sql';
    $f = fopen($ruta, 'w');
    if ($f === false) {
        throw new RuntimeException('No se pudo crear el archivo del backup.');
    }
    try {
        fwrite($f, "-- Backup de {$base} (" . NOMBRE_SISTEMA . ") del " . date('d/m/Y H:i:s') . "\n");
        fwrite($f, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $tabla) {
            $t = '`' . str_replace('`', '``', $tabla) . '`';
            $crear = $pdo->query("SHOW CREATE TABLE {$t}")->fetch(PDO::FETCH_NUM);
            fwrite($f, "-- {$tabla}\nDROP TABLE IF EXISTS {$t};\n{$crear[1]};\n\n");
            escribirDatosBackupUsuarios($pdo, $f, $t);
        }
        fwrite($f, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($f);
    }
    foreach (glob(rtrim($carpeta, '/\\') . '/backup_*.sql') as $viejo) {
        if (filemtime($viejo) < time() - USUARIOS_BACKUP_RETENCION_DIAS * 86400) {
            @unlink($viejo);
        }
    }
    return $ruta;
}

/** Las filas de una tabla, de a una (consulta sin búfer) y escritas en INSERT de a 200 filas (o de a 512 KB). */
function escribirDatosBackupUsuarios($pdo, $f, $t) {
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $stmt = $pdo->query("SELECT * FROM {$t}");
        $columnas = null;
        $lote = [];
        $bytes = 0;
        while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columnas ??= implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($fila)));
            $valores = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $fila)) . ')';
            if ($lote && (count($lote) >= USUARIOS_BACKUP_FILAS_POR_INSERT || $bytes + strlen($valores) > USUARIOS_BACKUP_BYTES_POR_INSERT)) {
                fwrite($f, "INSERT INTO {$t} ({$columnas}) VALUES\n" . implode(",\n", $lote) . ";\n");
                $lote = [];
                $bytes = 0;
            }
            $lote[] = $valores;
            $bytes += strlen($valores);
        }
        if ($lote) {
            fwrite($f, "INSERT INTO {$t} ({$columnas}) VALUES\n" . implode(",\n", $lote) . ";\n");
        }
        fwrite($f, "\n");
        $stmt->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
}
