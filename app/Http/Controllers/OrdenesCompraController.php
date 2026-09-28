<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Las órdenes de compra del Éxito: la planilla para armar el transporte a los CEDI (cajas,
 * unidades, peso, m³, valor y el carro que hace falta), con sus ajustes (cubicajes y vehículos).
 * La lógica está en app/Servicios/ordenes_compra/.
 */
class OrdenesCompraController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/ordenes_compra/model_ordenes_compra.php');
    }

    public function index(Request $request)
    {
        $pdo     = DB::connection()->getPdo();
        $filtros = ['oc' => trim((string) $request->query('oc', ''))];
        $datos   = ordenesDeCompraExito($pdo, $filtros);

        return view('ordenes_compra.ordenes_compra', [
            'filtros'     => $filtros,
            'datos'       => $datos,
            'ordenes'     => $datos['ordenes'],
            't'           => $datos['totales'],
            'disponibles' => ordenesDisponibles($pdo),
            'cubicajes'   => resumenCubicajes($pdo),
            'flota'       => vehiculos($pdo, false),   // todos, también los que no están en uso, para editarlos
        ]);
    }

    public function acciones(Request $request)
    {
        $pdo = DB::connection()->getPdo();

        // La tabla en PDF, con el mismo filtro que hay en pantalla. Es de lectura: va por GET.
        if ($request->isMethod('get')) {
            if ($request->query('accion') !== 'pdf') {
                return redirect()->route('ordenes_compra');
            }
            $filtros = ['oc' => trim((string) $request->query('oc', ''))];
            $datos   = ordenesDeCompraExito($pdo, $filtros);

            if (empty($datos['ordenes'])) {
                guardarMensajeFlashTexto('error', 'No hay órdenes que descargar con ese filtro.');
                return redirect()->route('ordenes_compra', array_filter($filtros));
            }

            require_once app_path('Servicios/ordenes_compra/helper_ordenes_compra_pdf.php');
            return descargarOrdenesCompraPdf($datos, $filtros);
        }

        switch ($request->input('accion', '')) {
            case 'subir_cubicajes':
                $archivo = $request->file('archivo');
                if (!$archivo || !$archivo->isValid()) {
                    guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
                    break;
                }
                if (!in_array(strtolower($archivo->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
                    guardarMensajeFlashTexto('error', 'El archivo de cubicajes tiene que ser un Excel (.xlsx o .xls).');
                    break;
                }
                $resultado = importarCubicajes($pdo, $archivo->getRealPath());
                guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
                break;

            case 'guardar_vehiculos':
                $filas = $request->input('vehiculos');
                $filas = is_array($filas) ? $filas : [];

                // Las casillas "en uso" desmarcadas no viajan en el POST: sin esto, desactivar un
                // vehículo no tendría efecto.
                foreach ($filas as $id => $datos) {
                    $filas[$id]['activo'] = isset($datos['activo']) ? 1 : 0;
                }

                $invalidos = guardarVehiculos($pdo, $filas);

                if ($invalidos === false) {
                    guardarMensajeFlashTexto('error', 'No se pudieron guardar los vehículos. Intentalo de nuevo.');
                } elseif ($invalidos > 0) {
                    guardarMensajeFlashTexto('error', "Se guardaron los vehículos, salvo {$invalidos} con datos inválidos, que quedaron como estaban. "
                                           . 'Un vehículo en uso tiene que tener peso y m³ mayores que 0.');
                } else {
                    guardarMensajeFlashTexto('exito', 'Vehículos guardados.');
                }
                break;
        }

        return redirect()->route('ordenes_compra');
    }
}
