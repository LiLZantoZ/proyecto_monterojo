<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las pantallas privadas: exige sesión iniciada y la cierra si pasó demasiado tiempo sin actividad.
 *
 * Es el equivalente de config/auth_guard.php del sistema anterior: sin sesión → al login con
 * ?error=no_session; con la sesión vencida por inactividad → se cierra y al login con
 * ?error=inactividad. El tiempo se controla acá, en el servidor; el temporizador del menú lateral
 * es solo una comodidad para que la pantalla no quede abierta.
 */
class SesionActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $this->alLogin($request, 'no_session');
        }

        $limite = config('monterojo.inactividad_minutos') * 60;
        $ultima = (int) $request->session()->get('ultima_actividad', 0);

        if ($ultima > 0 && (time() - $ultima) > $limite) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return $this->alLogin($request, 'inactividad');
        }

        $request->session()->put('ultima_actividad', time());

        $respuesta = $next($request);

        // Que el botón "atrás" no muestre una pantalla privada después de cerrar sesión.
        $respuesta->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $respuesta->headers->set('Pragma', 'no-cache');
        $respuesta->headers->set('Expires', '0');

        return $respuesta;
    }

    private function alLogin(Request $request, string $motivo): Response
    {
        // Un pedido de un script (fetch) no puede seguir una redirección al login: se le contesta
        // con JSON para que el script muestre el error en vez de romperse.
        if ($request->expectsJson() || $request->isJson()) {
            return response()->json(['exito' => false, 'error' => textoMensajeSistema('error', $motivo)], 401);
        }
        return redirect()->to(route('login') . '?error=' . $motivo);
    }
}
