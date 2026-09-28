<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cuántas cajas le corresponden a cada punto de venta, CEDI por CEDI: la planilla del camión.
 * Solo salen los puntos de venta anexados a un CEDI de cadena. La lógica está en
 * app/Servicios/cajas_punto_venta/.
 */
class CajasPuntoVentaController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/cajas_punto_venta/model_cajas_punto_venta.php');
        require_once app_path('Servicios/consolidados/model_almacenes_exito.php');
    }

    public function index(Request $request)
    {
        $pdo = DB::connection()->getPdo();

        $filtros = [
            'cedi'  => trim((string) $request->query('cedi', '')),
            'punto' => trim((string) $request->query('punto', '')),
        ];

        $porCedi = cajasPorPuntoDeVenta($pdo, $filtros);

        $totalGeneral = ['puntos' => 0, 'cajas' => 0, 'unidades' => 0, 'saldos' => 0, 'sin_maestro' => 0, 'total' => 0];
        foreach ($porCedi as $datos) {
            foreach ($totalGeneral as $clave => $_) {
                $totalGeneral[$clave] += $datos['totales'][$clave];
            }
        }

        return view('cajas_punto_venta.cajas_punto_venta', [
            'filtros'          => $filtros,
            'porCedi'          => $porCedi,
            'cedisDisponibles' => cedisConPuntosDeVenta($pdo),
            'almacenes'        => resumenAlmacenesExito($pdo),
            'totalGeneral'     => $totalGeneral,
            // Los filtros activos, para que los enlaces de descarga arrastren lo que hay en pantalla.
            'filtrosActivos'   => array_filter($filtros, fn ($v) => $v !== ''),
            'error'            => $request->query('error'),
        ]);
    }

    public function acciones(Request $request)
    {
        $pdo    = DB::connection()->getPdo();
        $accion = $request->input('accion', '');

        // El código de barras de la vista previa del rótulo (lectura, sin CSRF).
        if ($request->isMethod('get') && $accion === 'codigo_barras') {
            return respuestaCodigoBarras($request->query('texto'));
        }

        // Subir la lista oficial de almacenes del Éxito (Dependencia → Nombre).
        if ($request->isMethod('post') && $accion === 'subir_almacenes') {
            $archivo = $request->file('archivo');
            if (!$archivo || !$archivo->isValid()) {
                guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
            } elseif (!in_array(strtolower($archivo->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
                guardarMensajeFlashTexto('error', 'La lista de almacenes tiene que ser un Excel (.xlsx o .xls).');
            } else {
                $resultado = importarAlmacenesExito($pdo, $archivo->getRealPath());
                guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
            }
            return redirect()->route('cajas_punto_venta');
        }

        require_once app_path('Servicios/cajas_punto_venta/helper_cajas_punto_venta_pdf.php');

        // El PDF SOLO de los puntos de venta tildados (uno o varios CEDI mezclados). Por POST: la
        // lista de puntos puede ser larga para una URL.
        if ($request->isMethod('post') && $accion === 'pdf_seleccion') {
            // Arreglos paralelos (cedi[] / punto[]): así el HTML no se preocupa por el separador '|'.
            $cedis  = (array) $request->input('cedi', []);
            $puntos = (array) $request->input('punto', []);
            $claves = [];
            foreach ($cedis as $i => $c) {
                if (is_string($c) && isset($puntos[$i]) && is_string($puntos[$i]) && $c !== '' && $puntos[$i] !== '') {
                    $claves[] = $c . '|' . $puntos[$i];
                }
            }

            if (!$claves) {
                guardarMensajeFlashTexto('error', 'Seleccioná al menos un punto de venta para descargar.');
                return redirect()->route('cajas_punto_venta');
            }

            $porCedi = cajasPorPuntoDeVenta($pdo, ['puntos' => $claves]);
            if (empty($porCedi)) {
                return redirect()->route('cajas_punto_venta', ['error' => 'sin_datos']);
            }

            return descargarCajasPorPuntoPdf($porCedi, count($claves) . '_puntos_de_venta_' . date('Ymd') . '.pdf');
        }

        if ($accion !== 'pdf') {
            return redirect()->route('cajas_punto_venta');
        }

        // El PDF de todo lo filtrado (o de un CEDI): una hoja por CEDI.
        $filtros = [
            'cedi'  => trim((string) $request->query('cedi', '')),
            'punto' => trim((string) $request->query('punto', '')),
        ];

        $porCedi = cajasPorPuntoDeVenta($pdo, $filtros);
        if (empty($porCedi)) {
            return redirect()->route('cajas_punto_venta', ['error' => 'sin_datos']);
        }

        $nombre = $filtros['cedi'] !== ''
            ? 'Cajas_por_punto_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', $filtros['cedi']), '_') . '_' . date('Ymd') . '.pdf'
            : 'Cajas_por_punto_de_venta_' . date('Ymd') . '.pdf';

        return descargarCajasPorPuntoPdf($porCedi, $nombre);
    }
}
