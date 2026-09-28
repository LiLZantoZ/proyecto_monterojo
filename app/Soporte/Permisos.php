<?php

namespace App\Soporte;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Los permisos del rol de la sesión, leídos de las tablas `permisos` / `rolespermisos`.
 *
 * Igual que en el sistema anterior, NO se guardan en la sesión: se leen de la base una vez por
 * request. Así un permiso nuevo asignado a un rol se ve al instante, sin volver a entrar.
 */
class Permisos
{
    /** @var array<string>|null */
    private static ?array $cache = null;

    public static function delUsuario(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $usuario = auth()->user();
        if (!$usuario || empty($usuario->id_rol)) {
            return self::$cache = [];
        }

        try {
            return self::$cache = DB::table('rolespermisos as rp')
                ->join('permisos as p', 'rp.id_permiso', '=', 'p.id_permiso')
                ->where('rp.id_rol', $usuario->id_rol)
                ->pluck('p.nombre_permiso')
                ->all();
        } catch (Throwable $e) {
            // Ante la duda, la pantalla se cierra en vez de abrirse.
            report($e);
            return self::$cache = [];
        }
    }

    public static function tiene(string $permiso): bool
    {
        return in_array($permiso, self::delUsuario(), true);
    }

    /** Olvida lo leído (al entrar o salir, que cambia el usuario de la sesión). */
    public static function olvidar(): void
    {
        self::$cache = null;
    }
}
