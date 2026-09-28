<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La página que se abre al escanear el QR de un rótulo con el celular.
 *
 * NO pide iniciar sesión, a diferencia de todo el resto del sistema: quien la usa está parado en
 * el muelle con una caja en la mano. Lo que la protege es el token (12 caracteres al azar de un
 * alfabeto de 62). Y muestra SOLO esa caja: un token filtrado expone una etiqueta, no el despacho.
 */
class RotuloPublicoController extends Controller
{
    public function __invoke(Request $request)
    {
        require_once app_path('Servicios/historial/helper_rotulos_enlace.php');

        $rotulo = rotuloDesdeToken(DB::connection()->getPdo(), (string) $request->query('r', ''));

        return response()
            ->view('rotulos.publico', ['rotulo' => $rotulo], $rotulo === null ? 404 : 200)
            // Sin sesión: que no entre a ningún buscador ni quede en un proxy compartido.
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }
}
