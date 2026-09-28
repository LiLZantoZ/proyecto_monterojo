<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El otro extremo del modo remoto de impresión: a esto le habla el agente que corre en la PC donde
 * está conectada la etiquetadora por USB (scripts/agente_impresion_remota.ps1), no un navegador.
 *
 * Por eso NO lleva sesión ni CSRF: se autentica con un token fijo (TOKEN_AGENTE_IMPRESION, en el
 * .env) que viaja en cada pedido. Acciones:
 *   · siguiente  (GET)  — el trabajo pendiente más viejo, como bytes crudos. 204 si no hay nada.
 *   · confirmar  (POST) — el agente avisa si pudo imprimirlo o no.
 */
class AgenteImpresionController extends Controller
{
    public function __invoke(Request $request)
    {
        require_once app_path('Servicios/historial/helper_rotulos_tspl.php');

        $recibido = (string) ($request->query('token') ?? $request->input('token') ?? '');
        // hash_equals: compara en tiempo constante, para no dejar adivinar el token letra por letra.
        if ($recibido === '' || !hash_equals((string) TOKEN_AGENTE_IMPRESION, $recibido)) {
            return response('Token inválido.', 403);
        }

        $pdo    = DB::connection()->getPdo();
        $accion = $request->query('accion') ?? $request->input('accion') ?? '';

        if ($accion === 'siguiente' && $request->isMethod('get')) {
            $trabajo = reclamarSiguienteTrabajoImpresion($pdo);

            if (!$trabajo) {
                return response('', 204);   // nada pendiente; el agente vuelve a preguntar en un rato
            }

            // El cuerpo es EXACTAMENTE lo que hay que mandarle a la impresora (trae el logo como
            // bitmap, bytes binarios); el id y la cantidad van en cabeceras propias.
            return response($trabajo['tspl'], 200, [
                'Content-Type'   => 'application/octet-stream',
                'X-Trabajo-Id'   => (int) $trabajo['id_trabajo'],
                'X-Etiquetas'    => (int) $trabajo['etiquetas'],
                'Content-Length' => strlen($trabajo['tspl']),
            ]);
        }

        if ($accion === 'confirmar' && $request->isMethod('post')) {
            $idTrabajo = (int) $request->input('id_trabajo', 0);
            $resultado = $request->input('resultado', '');
            $mensaje   = $request->has('mensaje') ? mb_substr((string) $request->input('mensaje'), 0, 255) : null;

            if ($idTrabajo <= 0 || !in_array($resultado, ['ok', 'error'], true)) {
                return response('Faltan datos.', 400);
            }

            confirmarTrabajoImpresion($pdo, $idTrabajo, $resultado === 'ok', $mensaje);

            return response()->json(['exito' => true]);
        }

        return response('', 404);
    }
}
