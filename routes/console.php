<?php

// routes/console.php
// Los comandos de mantenimiento del sistema (php artisan ...). Reemplazan a los scripts sueltos
// del sistema anterior: toman la conexión del .env igual que el resto de la aplicación.

use App\Models\Usuario;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Crea (o completa) la base: tablas, roles, permisos y la lista de almacenes del Éxito.
 *
 * Es idempotente —CREATE TABLE IF NOT EXISTS e INSERT IGNORE en todo—: correrlo sobre una base que
 * ya existe no toca ningún dato. El esquema está en scripts/crear_esquema_completo.php (el mismo
 * archivo del sistema anterior, que sigue siendo la definición de la base); este comando solo le
 * pasa la conexión del .env.
 */
Artisan::command('monterojo:esquema', function () {
    $c = config('database.connections.mysql');
    putenv('DB_HOST=' . $c['host']);
    putenv('DB_NAME=' . $c['database']);
    putenv('DB_USER=' . $c['username']);
    putenv('DB_PASS=' . $c['password']);

    $this->info("Base: {$c['database']} en {$c['host']}");
    require base_path('scripts/crear_esquema_completo.php');
})->purpose('Crea o completa las tablas, roles y permisos del sistema (no borra datos)');

/**
 * Crea un usuario Administrador. Hace falta para el primero: no hay pantalla de registro, los
 * usuarios los da de alta un administrador desde adentro.
 */
Artisan::command('monterojo:crear-admin', function () {
    $rolAdministrador = 1;
    $largoMinimo      = 8;

    $yaHay = DB::table('usuarios')->where('id_rol', $rolAdministrador)->where('estado', 'Activo')->count();
    if ($yaHay > 0 && !$this->confirm("Esta base YA tiene {$yaHay} administrador(es) activo(s). ¿Crear otro de todos modos?")) {
        $this->line('Cancelado.');
        return 0;
    }

    $cedula = trim((string) $this->ask('Cédula (es el usuario con el que se inicia sesión)'));
    if ($cedula === '' || !ctype_digit($cedula)) {
        $this->error('La cédula tiene que ser un número, sin puntos ni espacios.');
        return 1;
    }
    if ($existente = Usuario::where('cedula_usuario', $cedula)->value('nombre_usuario')) {
        $this->error("Ya hay un usuario con la cédula {$cedula} ({$existente}).");
        return 1;
    }

    $nombre = trim((string) $this->ask('Nombre completo'));
    if ($nombre === '') {
        $this->error('El nombre completo no puede quedar vacío.');
        return 1;
    }

    // secret(): la contraseña no se ve mientras se escribe.
    $contrasena = (string) $this->secret("Contraseña (mínimo {$largoMinimo} caracteres)");
    if (strlen($contrasena) < $largoMinimo) {
        $this->error("La contraseña tiene que tener al menos {$largoMinimo} caracteres.");
        return 1;
    }
    if ((string) $this->secret('Repetir la contraseña') !== $contrasena) {
        $this->error('Las dos contraseñas no coinciden.');
        return 1;
    }

    // password_hash y no Hash::make: el mismo formato que el sistema anterior (bcrypt), así la
    // cuenta sirve igual en los dos mientras convivan.
    $id = DB::table('usuarios')->insertGetId([
        'nombre_usuario'     => $nombre,
        'cedula_usuario'     => $cedula,
        'contrasena_usuario' => password_hash($contrasena, PASSWORD_DEFAULT),
        'id_rol'             => $rolAdministrador,
        'estado'             => 'Activo',
    ], 'id_usuario');

    $this->info("Listo. Administrador creado (id {$id}). Entrá con la cédula {$cedula} en " . route('login'));
    return 0;
})->purpose('Crea un usuario Administrador');
