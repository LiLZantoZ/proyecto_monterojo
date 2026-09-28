<?php

namespace App\Providers;

use App\Soporte\Constantes;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Las constantes que usa la lógica de negocio traída del sistema anterior (BASE_URL...).
        Constantes::definir();

        // El menú lateral lee el usuario de la sesión por su cuenta, igual que antes: ninguna
        // pantalla tiene que acordarse de pasarle el nombre, el rol o la foto.
        View::composer('partes.menu', function ($vista) {
            $usuario = auth()->user();
            $vista->with([
                'nombreUsuario' => $usuario->nombre_usuario ?? 'Usuario',
                'rolUsuario'    => $usuario?->nombreRol() ?? 'Sin rol asignado',
                'imagenRuta'    => rutaImagenPerfil($usuario->imagen_url_Usuario ?? ''),
            ]);
        });
    }
}
