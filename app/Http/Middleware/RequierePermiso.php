<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra una pantalla (y sus acciones) a quien no tiene el permiso. Uso en las rutas:
 *     ->middleware('permiso:modulo_picking')
 *
 * Ocultar el enlace del menú NO es control de acceso: esto es lo que impide entrar escribiendo la
 * dirección a mano. Igual que requierePermiso() del sistema anterior, devuelve al panel de inicio
 * con ?error=acceso_denegado.
 */
class RequierePermiso
{
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        if (!tienePermiso($permiso)) {
            if ($request->expectsJson() || $request->isJson()) {
                return response()->json(['exito' => false, 'error' => textoMensajeSistema('error', 'acceso_denegado')], 403);
            }
            return redirect()->to(route('inicio') . '?error=acceso_denegado');
        }
        return $next($request);
    }
}
