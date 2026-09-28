<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * "Generar rótulos": etiquetas SUELTAS, para cuando hace falta imprimir una que no viene de ningún
 * pedido del sistema (una caja rearmada a mano, un reemplazo, una muestra). Misma maqueta y mismo
 * cálculo que el botón "Rótulos" de Picking y del Historial, pero con el formulario a mano.
 *
 * Acciones:
 *   · codigo_barras    (GET,  SVG)  — el código de barras de la vista previa
 *   · pdf              (POST, PDF)  — descarga los rótulos armados en la pantalla
 *   · imprimir_rotulos (POST, JSON) — los manda directo a la etiquetadora
 */
class RotulosController extends Controller
{
    public function index()
    {
        return view('rotulos.rotulos');
    }

    public function acciones(Request $request)
    {
        // Imprimir directo en la etiquetadora: va por JSON y no por el formulario del PDF para no
        // recargar la pantalla y perder lo que la persona acaba de escribir a mano.
        if ($request->isMethod('post') && $request->isJson()) {
            if ($request->json('accion') !== 'imprimir_rotulos') {
                return response()->json(['exito' => false, 'error' => 'Acción desconocida.'], 400);
            }
            require_once app_path('Servicios/historial/helper_rotulos_tspl.php');
            return responderImpresionDeRotulos($request->json()->all());
        }

        if ($request->isMethod('get') && $request->query('accion') === 'codigo_barras') {
            return respuestaCodigoBarras($request->query('texto'));
        }

        // Descargar en PDF: la lista llega armada desde la pantalla (la misma que se ve y que se
        // manda a la etiquetadora) y no se recalcula acá.
        if ($request->isMethod('post') && $request->input('accion') === 'pdf') {
            require_once app_path('Servicios/historial/helper_rotulos_lista.php');
            $rotulos = json_decode((string) $request->input('rotulos', ''), true);
            $rotulos = is_array($rotulos) ? normalizarListaDeRotulos($rotulos) : [];

            if (!$rotulos) {
                // Sin punto de venta el rótulo no identifica ninguna caja: es el único campo que
                // de verdad hace falta.
                return redirect()->route('rotulos', ['error' => 'falta_punto_venta']);
            }

            require_once app_path('Servicios/historial/helper_rotulos_pdf.php');
            return descargarRotulosPdfDeLista($rotulos, 'Rotulo_manual_' . date('Ymd_His') . '.pdf');
        }

        return redirect()->route('rotulos');
    }
}
