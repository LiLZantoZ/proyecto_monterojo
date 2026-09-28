<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Historial de pedidos: los pedidos ya despachados, para consultarlos, reimprimir sus hojas y
 * rótulos, y restaurarlos (volver a dejarlos pendientes) si hizo falta.
 *
 * Las hojas y los rótulos de acá son un REIMPRESO: el cálculo de cajas es el mismo que en Picking
 * (agruparPorEntregaHistorial), así que la hoja no puede discrepar de la del día del despacho.
 *
 * Acciones:
 *   · codigo_barras    (GET,  SVG)  — el código de barras de un rótulo
 *   · pdf_reporte      (GET,  PDF)  — el listado completo del filtro, uno por CEDI
 *   · rotulos_pdf      (GET,  PDF)  — los rótulos de todo lo que cumple el filtro
 *   · pdf              (GET,  PDF)  — la hoja de alistamiento de UN pedido
 *   · pdf_masivo       (POST, PDF)  — las hojas de varios pedidos en un solo archivo
 *   · imprimir_rotulos (POST, JSON) — directo a la etiquetadora
 *   · restaurar / restaurar_masivo (POST, JSON)
 */
class HistorialController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/historial/model_historial.php');
    }

    public function index(Request $request)
    {
        return view('historial.historial', $this->datosDeLaVista($request));
    }

    public function acciones(Request $request)
    {
        $pdo    = DB::connection()->getPdo();
        $accion = $request->isJson() ? $request->json('accion', '') : $request->input('accion', '');

        if ($request->isMethod('get')) {
            switch ($accion) {
                case 'codigo_barras':
                    return respuestaCodigoBarras($request->query('texto'));

                // El LISTADO completo que se ve en pantalla: respeta el buscador, no la paginación.
                case 'pdf_reporte':
                    $filtros = ['busqueda' => trim((string) $request->query('q', ''))];
                    $porCedi = agruparPorCediHistorial(agruparPorEntregaHistorial(filasHistorial($pdo, $filtros)));
                    require_once app_path('Servicios/historial/helper_historial_pdf.php');
                    return descargarHistorialPdf($porCedi, 'Historial_de_pedidos_' . date('Ymd_His') . '.pdf');

                // Los rótulos de TODO lo que cumple el filtro (puede ser un archivo grande).
                case 'rotulos_pdf':
                    $filtros  = ['busqueda' => trim((string) $request->query('q', ''))];
                    $entregas = array_values(agruparPorEntregaHistorial(filasHistorial($pdo, $filtros)));
                    require_once app_path('Servicios/historial/helper_rotulos_pdf.php');
                    return descargarRotulosPdf($entregas, 'Rotulos_Historial_' . date('Ymd_His') . '.pdf');

                // La hoja de alistamiento de un pedido ya despachado.
                case 'pdf':
                    $idCarga = (int) $request->query('carga', 0);
                    $entrega = $idCarga > 0
                        ? entregaHistorial($pdo, $idCarga, trim((string) $request->query('cedi', '')),
                            trim((string) $request->query('oc', '')), trim((string) $request->query('pv', '')))
                        : null;

                    if ($entrega === null) {
                        return redirect()->route('historial', ['error' => 'invalid_id']);
                    }

                    require_once app_path('Servicios/picking/helper_picking_pdf.php');
                    return descargarPickingPdf($entrega, cargaPorId($pdo, $idCarga) ?? []);
            }

            return response()->json(['exito' => false, 'error' => 'Método no permitido.'], 405);
        }

        // Las hojas de varios pedidos despachados en un solo PDF (formulario, no JSON).
        if ($accion === 'pdf_masivo') {
            $cargas = (array) $request->input('carga', []);
            $cedis  = (array) $request->input('cedi', []);
            $ocs    = (array) $request->input('oc', []);
            $pvs    = (array) $request->input('pv', []);

            $claves = [];
            foreach ($cargas as $i => $idCarga) {
                if (isset($cedis[$i], $ocs[$i], $pvs[$i])) {
                    $claves[] = ['id_carga' => $idCarga, 'cedi' => $cedis[$i], 'oc' => $ocs[$i], 'pv' => $pvs[$i]];
                }
            }

            $entregas = entregasHistorial($pdo, $claves);
            if (!$entregas) {
                return redirect()->route('historial', ['error' => 'invalid_id']);
            }

            // Las entregas pueden ser de cargas distintas: no hay UN "meta" para el encabezado.
            require_once app_path('Servicios/picking/helper_picking_pdf.php');
            return descargarPickingPdf($entregas, [], 'Historial_' . count($entregas) . '_pedidos_' . date('Ymd_His') . '.pdf');
        }

        // Lo demás llega como JSON desde scripts_historial.js.
        $cuerpo = $request->json()->all();

        if ($accion === 'imprimir_rotulos') {
            require_once app_path('Servicios/historial/helper_rotulos_tspl.php');
            return responderImpresionDeRotulos($cuerpo);
        }

        // Restaurar EN LOTE los pedidos tildados: cada uno por separado; se devuelve cuántos se
        // restauraron y cuántos no (p. ej. si alguno ya no estaba despachado).
        if ($accion === 'restaurar_masivo') {
            $pedidos = is_array($cuerpo['pedidos'] ?? null) ? $cuerpo['pedidos'] : [];
            $limpios = [];
            foreach ($pedidos as $p) {
                $idc = (int) ($p['id_carga'] ?? 0);
                $cd  = trim((string) ($p['cedi'] ?? ''));
                $oc  = trim((string) ($p['orden_compra'] ?? ''));
                $pv  = trim((string) ($p['punto_venta'] ?? ''));
                if ($idc > 0 && $cd !== '' && $oc !== '' && $pv !== '') {
                    $limpios[] = ['id_carga' => $idc, 'cedi' => $cd, 'orden_compra' => $oc, 'punto_venta' => $pv];
                }
            }
            if (!$limpios) {
                return response()->json(['exito' => false, 'error' => 'No llegó ningún pedido válido para restaurar.'], 400);
            }
            $r = restaurarEntregasHistorial($pdo, $limpios);
            return response()->json(['exito' => true, 'restaurados' => $r['restaurados'], 'fallidos' => $r['fallidos']]);
        }

        if ($accion !== 'restaurar') {
            return response()->json(['exito' => false, 'error' => 'Acción desconocida.'], 400);
        }

        $idCarga     = (int) ($cuerpo['id_carga'] ?? 0);
        $cedi        = trim((string) ($cuerpo['cedi'] ?? ''));
        $ordenCompra = trim((string) ($cuerpo['orden_compra'] ?? ''));
        $puntoVenta  = trim((string) ($cuerpo['punto_venta'] ?? ''));

        if ($idCarga <= 0 || $cedi === '' || $ordenCompra === '' || $puntoVenta === '') {
            return response()->json(['exito' => false, 'error' => 'Faltan datos para identificar el pedido.'], 400);
        }

        $resultado = restaurarEntregaHistorial($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta);

        if (!$resultado['exito']) {
            // 409: no es una falla del servidor, es que la condición pedida no se cumple.
            return response()->json(['exito' => false, 'error' => $resultado['mensaje']], 409);
        }

        return response()->json(['exito' => true]);
    }

    /** Lo que necesita la pantalla (ver resources/views/historial/historial.blade.php). */
    private function datosDeLaVista(Request $request): array
    {
        $pdo = DB::connection()->getPdo();

        $filtros   = ['busqueda' => trim((string) $request->query('q', ''))];
        $pagina    = max(1, (int) $request->query('pagina', 1));
        $resultado = historialPaginado($pdo, $filtros, $pagina, 20);

        // Los filtros como parámetros de la dirección (el buscador viaja como "q").
        $enUrl = array_filter(['q' => $filtros['busqueda']]);

        return [
            'filtros'   => $filtros,
            'pagina'    => $pagina,
            'resultado' => $resultado,
            'entregas'  => $resultado['entregas'],
            'porCedi'   => agruparPorCediHistorial($resultado['entregas']),
            'resumen'   => resumenHistorialTotales($pdo, $filtros),
            'enUrl'     => $enUrl,
        ];
    }
}
