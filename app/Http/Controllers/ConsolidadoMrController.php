<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El Consolidado MR de SAP renglón por renglón: qué se facturó, a quién, cuántas unidades y por
 * cuánto, con el Valor neto sumado por factura. La importación y las consultas están en
 * app/Servicios/consolidado_mr/model_consolidado_mr.php.
 */
class ConsolidadoMrController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/consolidado_mr/model_consolidado_mr.php');
    }

    public function index(Request $request)
    {
        $pdo = DB::connection()->getPdo();

        $filtros = [
            'cliente'   => trim((string) $request->query('cliente', '')),
            'poblacion' => trim((string) $request->query('poblacion', '')),
            'buscar'    => trim((string) $request->query('buscar', '')),
            'desde'     => trim((string) $request->query('desde', '')),
            'hasta'     => trim((string) $request->query('hasta', '')),
        ];

        $totales      = totalesConsolidadoMr($pdo, $filtros);
        $totalLineas  = $totales['lineas'];
        $totalPaginas = max(1, (int) ceil($totalLineas / CONSOLIDADO_MR_POR_PAGINA));
        $pagina       = min(max(1, (int) $request->query('pagina', 1)), $totalPaginas);
        $lineas       = lineasConsolidadoMr($pdo, $filtros, $pagina);

        // VALOR NETO SUMADO POR FACTURA: después del último renglón de cada factura va una fila de
        // subtotal. Si la última factura de la página sigue en la siguiente, su subtotal va allá
        // (donde termina) y acá solo se avisa; igual con la primera, si arrancó en la anterior.
        $offsetPagina = ($pagina - 1) * CONSOLIDADO_MR_POR_PAGINA;
        $filtrosEnUrl = http_build_query(array_filter($filtros));

        return view('consolidado_mr.consolidado_mr', [
            'filtros'          => $filtros,
            'totales'          => $totales,
            'totalLineas'      => $totalLineas,
            'totalPaginas'     => $totalPaginas,
            'pagina'           => $pagina,
            'lineas'           => $lineas,
            'subtotales'       => subtotalesPorFacturaConsolidadoMr($pdo, $filtros, array_column($lineas, 'factura')),
            'facturaSiguiente' => $lineas ? facturaEnPosicionConsolidadoMr($pdo, $filtros, $offsetPagina + count($lineas)) : null,
            'facturaAnterior'  => $offsetPagina > 0 ? facturaEnPosicionConsolidadoMr($pdo, $filtros, $offsetPagina - 1) : null,
            'hayFiltro'        => count(array_filter($filtros)) > 0,
            'clientes'         => valoresConsolidadoMr($pdo, 'nombre_cliente'),
            'poblaciones'      => valoresConsolidadoMr($pdo, 'poblacion'),
            'filtrosEnUrl'     => $filtrosEnUrl,
            'hayDatos'         => $filtrosEnUrl !== '' || $totalLineas > 0,
            'desdeFila'        => $totalLineas === 0 ? 0 : $offsetPagina + 1,
            'hastaFila'        => min($pagina * CONSOLIDADO_MR_POR_PAGINA, $totalLineas),
            'urlPagina'        => fn ($p) => route('consolidado_mr', array_filter($filtros) + ['pagina' => $p]),
            // Importar y vaciar solo con este permiso: el rol Visitante solo consulta la tabla.
            'puedeEditar'      => tienePermiso('consolidado_mr_editar'),
        ]);
    }

    public function acciones(Request $request)
    {
        $pdo = DB::connection()->getPdo();

        switch ($request->input('accion', '')) {
            case 'importar':
                $archivo = $request->file('archivo');
                if (!$archivo || !$archivo->isValid()) {
                    guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
                    break;
                }
                if (!in_array(strtolower($archivo->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
                    guardarMensajeFlashTexto('error', 'El archivo tiene que ser un Excel (.xlsx o .xls).');
                    break;
                }
                $r = importarConsolidadoMr($pdo, $archivo->getRealPath(), auth()->id());
                guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
                break;

            case 'vaciar':
                $n = vaciarConsolidadoMr($pdo);
                guardarMensajeFlashTexto('exito', $n > 0
                    ? 'Se borraron ' . fmtMil($n) . ' renglón(es) del Consolidado MR.'
                    : 'No había nada cargado.');
                break;
        }

        return redirect()->route('consolidado_mr');
    }
}
