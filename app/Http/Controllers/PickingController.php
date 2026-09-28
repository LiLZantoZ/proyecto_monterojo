<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Picking: lo pendiente por punto de venta, para alistar, asignar personal, imprimir hojas y
 * rótulos, y despachar. La lógica está en app/Servicios/picking/model_picking.php.
 *
 * Acciones:
 *   · codigo_barras     (GET,  SVG)  — el código de barras de un rótulo
 *   · pdf               (GET,  PDF)  — la hoja de alistamiento de UNA entrega
 *   · pdf_masivo        (POST, PDF)  — las hojas de varias entregas en un solo archivo
 *   · rotulos_pdf       (POST, PDF)  — los rótulos del modal, en PDF (respaldo de la etiquetadora)
 *   · imprimir_rotulos / asignar_personal / despachar_pedidos / guardar_pedido_sap (POST, JSON)
 */
class PickingController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/picking/model_picking.php');
    }

    public function index(Request $request)
    {
        return view('picking.picking', $this->datosDeLaVista($request));
    }

    public function acciones(Request $request)
    {
        $pdo    = DB::connection()->getPdo();
        $accion = $request->isJson() ? $request->json('accion', '') : $request->input('accion', '');

        if ($request->isMethod('get')) {
            if ($accion === 'codigo_barras') {
                return respuestaCodigoBarras($request->query('texto'));
            }

            // La hoja de alistamiento de una entrega: por GET, es un enlace normal.
            if ($accion === 'pdf') {
                $idCarga = (int) $request->query('carga', 0);
                $entrega = $idCarga > 0
                    ? entregaPicking($pdo, $idCarga, trim((string) $request->query('cedi', '')),
                        trim((string) $request->query('oc', '')), trim((string) $request->query('pv', '')))
                    : null;

                // Si los cuatro valores no coinciden con ninguna entrega, se vuelve al listado en
                // vez de generar una hoja vacía que alguien podría llevarse a la bodega.
                if ($entrega === null) {
                    return redirect()->route('picking', ['error' => 'invalid_id']);
                }

                require_once app_path('Servicios/picking/helper_picking_pdf.php');
                return descargarPickingPdf($entrega, []);
            }

            return response()->json(['exito' => false, 'error' => 'Método no permitido.'], 405);
        }

        // Las hojas de varias entregas en un solo PDF (formulario: la lista no entra en una URL).
        if ($accion === 'pdf_masivo') {
            $cargas = (array) $request->input('carga', []);
            $cedis  = (array) $request->input('cedi', []);
            $ocs    = (array) $request->input('oc', []);
            $pvs    = (array) $request->input('pv', []);

            $claves = [];
            foreach ($cedis as $i => $cedi) {
                // Solo las posiciones con los cuatro valores: un POST recortado no arma claves a medias.
                if (isset($cargas[$i], $ocs[$i], $pvs[$i])) {
                    $claves[] = ['carga' => $cargas[$i], 'cedi' => $cedi, 'oc' => $ocs[$i], 'pv' => $pvs[$i]];
                }
            }

            $entregas = entregasPicking($pdo, $claves);
            if (!$entregas) {
                return redirect()->route('picking', ['error' => 'invalid_id']);
            }

            require_once app_path('Servicios/picking/helper_picking_pdf.php');
            return descargarPickingPdf($entregas, []);
        }

        // Los rótulos del modal en PDF: la lista viene armada desde la pantalla (el rótulo es
        // EDITABLE), no se recalcula acá.
        if ($accion === 'rotulos_pdf') {
            $rotulos = json_decode((string) $request->input('rotulos', ''), true);
            if (!is_array($rotulos) || !$rotulos) {
                return redirect()->route('picking', ['error' => 'invalid_id']);
            }
            require_once app_path('Servicios/historial/helper_rotulos_pdf.php');
            return descargarRotulosPdfDeLista($rotulos, 'Rotulos_' . date('Ymd_His') . '.pdf');
        }

        // Lo demás llega como JSON desde scripts_picking.js.
        $cuerpo = $request->json()->all();

        if (!in_array($accion, ['guardar_pedido_sap', 'asignar_personal', 'despachar_pedidos', 'imprimir_rotulos'], true)) {
            return response()->json(['exito' => false, 'error' => 'Acción desconocida.'], 400);
        }

        // Imprimir directo en la etiquetadora: la MISMA lista que el botón "Descargar PDF".
        if ($accion === 'imprimir_rotulos') {
            require_once app_path('Servicios/historial/helper_rotulos_tspl.php');
            return responderImpresionDeRotulos($cuerpo);
        }

        if ($accion === 'asignar_personal') {
            return $this->asignarPersonal($pdo, $cuerpo);
        }

        // DESPACHAR: a partir de acá las entregas desaparecen de Picking y de Consolidados. La
        // comprobación de que TODAS tengan personal la hace despacharEntregas() releyendo la base.
        if ($accion === 'despachar_pedidos') {
            $pedidos = $cuerpo['pedidos'] ?? [];
            if (!is_array($pedidos) || empty($pedidos)) {
                return response()->json(['exito' => false, 'error' => 'No se enviaron pedidos válidos.'], 400);
            }

            $resultado = despacharEntregas($pdo, $pedidos, auth()->id());

            if (!$resultado['exito']) {
                // 409: no es una falla del servidor, es que falta personal asignado.
                return response()->json([
                    'exito'        => false,
                    'error'        => $resultado['mensaje'],
                    'sin_personal' => $resultado['sin_personal'] ?? [],
                ], 409);
            }

            return response()->json(['exito' => true, 'despachadas' => $resultado['despachadas']]);
        }

        // El pedido SAP de UNA entrega (hoy no se usa desde ninguna pantalla).
        $idCarga     = (int) ($cuerpo['carga'] ?? 0);
        $cedi        = trim((string) ($cuerpo['cedi'] ?? ''));
        $ordenCompra = trim((string) ($cuerpo['orden_compra'] ?? ''));
        $puntoVenta  = trim((string) ($cuerpo['punto_venta'] ?? ''));

        if ($idCarga <= 0 || $cedi === '' || $ordenCompra === '' || $puntoVenta === '') {
            return response()->json(['exito' => false, 'error' => 'Faltan datos para identificar la entrega.'], 400);
        }

        $pedidoSap = trim((string) ($cuerpo['pedido_sap'] ?? ''));
        if (!guardarPedidoSap($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta, $pedidoSap)) {
            return response()->json(['exito' => false, 'error' => 'No se pudo guardar. Inténtalo de nuevo.'], 500);
        }

        return response()->json([
            'exito'      => true,
            'pedido_sap' => $pedidoSap,
            'grupo'      => $cedi . '|' . $ordenCompra . '|' . $puntoVenta,
        ]);
    }

    /**
     * Asigna (o quita) el alistador de una o varias entregas. Siempre una LISTA, aunque venga una
     * sola: el botón de la fila manda una y la barra de selección, las tildadas.
     */
    private function asignarPersonal($pdo, array $cuerpo)
    {
        require_once app_path('Servicios/personal/model_personal.php');

        $entregas = $cuerpo['entregas'] ?? null;
        if (!is_array($entregas) || !$entregas) {
            return response()->json(['exito' => false, 'error' => 'No se recibió ninguna entrega para asignar.'], 400);
        }

        // Vacío o 0 = quitar la asignación (una acción explícita, no un dato que no llegó).
        $idPersonal = trim((string) ($cuerpo['id_personal'] ?? ''));
        $idPersonal = ($idPersonal === '' || $idPersonal === '0') ? null : (int) $idPersonal;

        $nombre = null;
        if ($idPersonal !== null) {
            // Contra la base: si la persona se eliminó con la pantalla abierta, se dice qué pasó.
            $persona = obtenerPersona($pdo, $idPersonal);
            if (!$persona) {
                return response()->json(['exito' => false, 'error' => 'Esa persona ya no está en el personal. Recargá la pantalla.'], 409);
            }
            $nombre = $persona['nombre'];
        }

        $guardadas = [];
        foreach ($entregas as $entrega) {
            $idCarga = (int) ($entrega['carga'] ?? 0);
            $cedi    = trim((string) ($entrega['cedi'] ?? ''));
            $oc      = trim((string) ($entrega['orden_compra'] ?? ''));
            $pv      = trim((string) ($entrega['punto_venta'] ?? ''));

            if ($idCarga <= 0 || $cedi === '' || $oc === '' || $pv === '') {
                continue;
            }
            if (asignarPersonalAEntrega($pdo, $idCarga, $cedi, $oc, $pv, $idPersonal)) {
                // La clave lleva la carga adelante, igual que data-entrega en la vista.
                $guardadas[] = $idCarga . '|' . $cedi . '|' . $oc . '|' . $pv;
            }
        }

        if (!$guardadas) {
            return response()->json(['exito' => false, 'error' => 'No se pudo guardar la asignación. Inténtalo de nuevo.'], 500);
        }

        return response()->json([
            'exito'       => true,
            'id_personal' => $idPersonal,
            'nombre'      => $nombre,
            'grupos'      => $guardadas,
        ]);
    }

    /** Lo que necesita la pantalla (ver resources/views/picking/picking.blade.php). */
    private function datosDeLaVista(Request $request): array
    {
        require_once app_path('Servicios/personal/model_personal.php');
        $pdo = DB::connection()->getPdo();

        // Lo pendiente de TODAS las cargas activas a la vez, cada entrega con su propia fecha.
        $filtros = [
            'cedi'        => trim((string) $request->query('cedi', '')),
            'punto_venta' => trim((string) $request->query('punto_venta', '')),
            'busqueda'    => trim((string) $request->query('q', '')),
        ];

        $filas    = filasPicking($pdo, $filtros);
        $entregas = agruparPorEntrega($filas);

        // Sin filtrar: decide si hay ALGO pendiente en el sistema, no si el filtro encontró algo.
        $cedisDisponibles = cedisPendientes($pdo);

        return [
            // Solo los ACTIVOS: a alguien que ya no está no se le asigna una entrega nueva.
            'personalActivo'    => listarPersonal($pdo, true),
            'filtros'           => $filtros,
            'resumen'           => resumenPicking($filas),
            'entregas'          => $entregas,
            // La zona de despacho es el CEDI.
            'porCedi'           => agruparPorCedi($entregas),
            'cedisDisponibles'  => $cedisDisponibles,
            'hayPendientes'     => !empty($cedisDisponibles),
            'puntosDisponibles' => puntosDeVenta($pdo, $filtros['cedi']),
            // De qué cadena es cada CEDI, para decirlo en su cabecera.
            'cadenasDeCedi'     => cadenasPorCedi($pdo),
        ];
    }
}
