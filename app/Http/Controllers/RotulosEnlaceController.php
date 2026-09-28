<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los enlaces de los QR de los rótulos, que comparten las cuatro pantallas que muestran rótulos
 * (Picking, Historial, Cajas por punto de venta y Generar rótulos):
 *   · qr      (GET,  PNG)  — la imagen del QR de un token
 *   · enlaces (POST, JSON) — los tokens de un lote de rótulos, en UNA sola petición
 */
class RotulosEnlaceController extends Controller
{
    public function __invoke(Request $request)
    {
        require_once app_path('Servicios/historial/helper_rotulos_lista.php');
        require_once app_path('Servicios/historial/helper_rotulos_enlace.php');

        $puedeVerRotulos = tienePermiso('modulo_picking')
            || tienePermiso('modulo_historial')
            || tienePermiso('modulo_rotulos')
            || tienePermiso('modulo_cajas_punto_venta');

        if (!$puedeVerRotulos) {
            // 403 y no una redirección: lo piden un <img> y un fetch().
            return response('', 403);
        }

        // EL QR: recibe el TOKEN y no la URL entera, para que nadie pueda hacer que el sistema
        // genere un QR que apunte a donde quiera. De lectura y sin efectos: sin CSRF.
        //
        // (En el sistema anterior acá se soltaba la sesión antes de dibujar la imagen, porque la
        // sesión de PHP bloquea las peticiones en paralelo del mismo usuario y la vista previa de
        // un lote pide un QR por rótulo. Las sesiones de Laravel no bloquean, así que ya no hace
        // falta.)
        if ($request->isMethod('get') && $request->query('accion') === 'qr') {
            $token = trim((string) $request->query('t', ''));

            if (!preg_match('/^[0-9A-Za-z]{1,16}$/', $token)) {
                return response('', 400);
            }

            $base = rtrim((string) URL_PUBLICA_ROTULOS, '/');
            if ($base === '') {
                return response('', 404);
            }

            $qr = new \Endroid\QrCode\QrCode(
                data: $base . '/public/rotulo.php?r=' . $token,
                size: 240,
                margin: 0,
                errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Low
            );

            return response((new \Endroid\QrCode\Writer\PngWriter())->write($qr)->getString(), 200, [
                'Content-Type'  => 'image/png',
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        // LOS TOKENS DE UN LOTE. El token depende de los datos del rótulo: reimprimir la misma caja
        // devuelve el mismo token, así que pedirlo para la vista previa y después imprimir no crea dos.
        if ($request->isMethod('post')) {
            if ($request->json('accion') !== 'enlaces') {
                return response()->json(['exito' => false, 'error' => 'Acción desconocida.'], 400);
            }

            $pdo = DB::connection()->getPdo();

            // Se normaliza de a UN rótulo: la lista entera DESCARTA los que no tienen punto de
            // venta y la respuesta quedaría más corta que el lote —el JavaScript empareja por
            // posición y le pondría a cada caja el QR de la siguiente—. Un rótulo descartado
            // devuelve null en su lugar y sale sin QR.
            $lote = $request->json('rotulos');
            $lote = is_array($lote) ? $lote : [];

            $tokens = [];
            foreach (array_slice($lote, 0, ROTULOS_MAXIMO_POR_TRABAJO) as $crudo) {
                $limpio   = normalizarListaDeRotulos([$crudo]);
                $tokens[] = $limpio ? tokenDeRotulo($pdo, $limpio[0]) : null;
            }

            return response()->json(['exito' => true, 'tokens' => $tokens]);
        }

        return response('', 400);
    }
}
