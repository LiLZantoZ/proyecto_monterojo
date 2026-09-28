<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Un usuario del sistema (tabla `usuarios`, la misma del sistema anterior).
 *
 * Se entra con la cédula y la contraseña; la contraseña se guarda con password_hash() (bcrypt),
 * que Laravel verifica igual, así que las cuentas existentes siguen funcionando sin cambios.
 */
class Usuario extends Authenticatable
{
    protected $table = 'usuarios';
    protected $primaryKey = 'id_usuario';

    // La tabla trae fecha_creacion con valor por defecto en MySQL y no tiene updated_at.
    public $timestamps = false;

    // La tabla no tiene remember_token: no hay "recordarme" en este sistema.
    protected $rememberTokenName = '';

    protected $fillable = [
        'nombre_usuario', 'cedula_usuario', 'contrasena_usuario', 'estado',
        'id_rol', 'telefono_usuario', 'imagen_url_Usuario',
    ];

    protected $hidden = ['contrasena_usuario'];

    public function getAuthPassword()
    {
        return $this->contrasena_usuario;
    }

    public function getAuthPasswordName()
    {
        return 'contrasena_usuario';
    }

    /** El nombre del rol (roles.nombre_rol), o null si no tiene. */
    public function nombreRol(): ?string
    {
        if (empty($this->id_rol)) {
            return null;
        }
        return once(fn () => \Illuminate\Support\Facades\DB::table('roles')
            ->where('id_rol', $this->id_rol)->value('nombre_rol'));
    }
}
