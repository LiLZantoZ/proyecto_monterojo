<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Estado de pedidos: el tablero del estado de los despachos en las transportadoras, más la lista
 * aparte de la facturación de SAP. Se alimenta a mano o subiendo el Excel que exporta cada
 * transportadora (o el Consolidado de facturación de SAP). La lógica está en
 * app/Servicios/seguimiento/model_seguimiento.php.
 */
class SeguimientoController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/seguimiento/model_seguimiento.php');
    }

    public function index(Request $request)
    {
        $pdo = DB::connection()->getPdo();

        $filtros = [
            'transportadora' => trim((string) $request->query('transportadora', '')),
            'estado'         => trim((string) $request->query('estado', '')),
            'grupo'          => trim((string) $request->query('grupo', '')),   // el semáforo: al hacer clic en una pastilla
            'origen'         => trim((string) $request->query('origen', '')),  // la pestaña: transportadoras / sap / todos
            'buscar'         => trim((string) $request->query('buscar', '')),
            'desde'          => trim((string) $request->query('desde', '')),
            'hasta'          => trim((string) $request->query('hasta', '')),
        ];
        // Solo los cuatro grupos conocidos; cualquier otra cosa no filtra.
        if (!in_array($filtros['grupo'], ['entregado', 'en_camino', 'pendiente', 'otro'], true)) {
            $filtros['grupo'] = '';
        }
        // La pestaña por defecto es "transportadoras": los de facturación de SAP —muchos y que no
        // cruzan— van en su propia pestaña.
        if (!in_array($filtros['origen'], ['transportadoras', 'sap', 'todos'], true)) {
            $filtros['origen'] = 'transportadoras';
        }

        $totalPedidos = contarPedidos($pdo, $filtros);
        $totalPaginas = max(1, (int) ceil($totalPedidos / SEGUIMIENTO_POR_PAGINA));
        $pagina       = min(max(1, (int) $request->query('pagina', 1)), $totalPaginas);

        // El semáforo se cuenta sobre TODO el conjunto filtrado, pero SIN el filtro de grupo: al
        // elegir una pastilla, las demás siguen mostrando su total y se puede saltar de una a otra.
        $filtrosSemaforo = $filtros;
        $filtrosSemaforo['grupo'] = '';

        $esSap = $filtros['origen'] === 'sap';

        // La URL con los filtros actuales, cambiando lo que se pase (y volviendo a la página 1).
        $urlCon = fn (array $cambios) => route('seguimiento', array_filter(array_merge($filtros, $cambios)));

        $conteoTransp = contarPedidos($pdo, ['origen' => 'transportadoras'] + $filtros);
        $conteoSap    = contarPedidos($pdo, ['origen' => 'sap'] + $filtros);

        return view('seguimiento.seguimiento', [
            'filtros'         => $filtros,
            'pagina'          => $pagina,
            'totalPaginas'    => $totalPaginas,
            'totalPedidos'    => $totalPedidos,
            'pedidos'         => pedidosSeguidos($pdo, $filtros, $pagina),
            'resumen'         => resumenDeEstados(estadosDelFiltro($pdo, $filtrosSemaforo)),
            'esSap'           => $esSap,
            'totalValorNeto'  => $esSap ? sumaValorNeto($pdo, $filtros) : 0,
            'transportadoras' => transportadorasDePedidos($pdo),
            'estadosCargados' => estadosDePedidos($pdo),
            // La pestaña de origen no cuenta como "filtro" (siempre tiene un valor).
            'filtrosEnUrl'    => http_build_query(array_filter(array_diff_key($filtros, ['origen' => 1]))),
            'desdeFila'       => $totalPedidos === 0 ? 0 : ($pagina - 1) * SEGUIMIENTO_POR_PAGINA + 1,
            'hastaFila'       => min($pagina * SEGUIMIENTO_POR_PAGINA, $totalPedidos),
            'urlPagina'       => fn ($p) => route('seguimiento', array_filter($filtros) + ['pagina' => $p]),
            // Una pastilla del semáforo funciona como interruptor: si ya está activa, la quita.
            'urlPastilla'     => fn ($grupo) => $urlCon(['grupo' => $filtros['grupo'] === $grupo ? '' : $grupo]),
            'urlPestana'      => fn ($origen) => $urlCon(['origen' => $origen]),
            'conteoTransp'    => $conteoTransp,
            'conteoSap'       => $conteoSap,
            'conteoTodos'     => $conteoTransp + $conteoSap,
        ]);
    }

    public function acciones(Request $request)
    {
        $pdo       = DB::connection()->getPdo();
        $idUsuario = auth()->id();

        switch ($request->input('accion', '')) {
            case 'guardar_pedido':
                $r = guardarPedidoManual($pdo, $request->except(['_token', 'csrf_token']), $idUsuario);
                guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
                break;

            case 'importar_excel':
                $archivo = $request->file('archivo');
                if (!$archivo || !$archivo->isValid()) {
                    guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
                    break;
                }
                if (!in_array(strtolower($archivo->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
                    guardarMensajeFlashTexto('error', 'El listado tiene que ser un Excel (.xlsx o .xls).');
                    break;
                }
                $r = importarPedidosExcel($pdo, $archivo->getRealPath(), $idUsuario,
                    $request->input('transportadora'));   // la del formulario, por si el Excel no trae columna
                guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
                break;

            case 'eliminar_pedido':
                $ok = eliminarPedido($pdo, $request->input('id_pedido', 0));
                guardarMensajeFlashTexto($ok ? 'exito' : 'error',
                    $ok ? 'Pedido eliminado.' : 'No se encontró el pedido que se quería eliminar.');
                break;

            case 'eliminar_masivo':
                $ids = $request->input('ids');
                $n = eliminarPedidos($pdo, is_array($ids) ? $ids : []);
                guardarMensajeFlashTexto($n > 0 ? 'exito' : 'error',
                    $n > 0 ? "{$n} pedido(s) eliminado(s)." : 'No se eliminó ninguno: no llegó ninguna fila seleccionada.');
                break;
        }

        return redirect()->route('seguimiento');
    }
}
