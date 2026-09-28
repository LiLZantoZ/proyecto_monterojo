<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Consolidados (qué hay que bajar de bodega para cada CEDI) y el Maestro de productos.
 *
 * Comparten la dirección de acciones (/consolidados/acciones), como en el sistema anterior; cada
 * acción exige su propio permiso: las del Consolidado piden modulo_consolidados y las del maestro,
 * modulo_maestro. La lógica está en app/Servicios/consolidados/.
 */
class ConsolidadosController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/consolidados/model_consolidados.php');
    }

    // =============================================================================================
    // PANTALLAS
    // =============================================================================================

    public function index(Request $request)
    {
        $pdo = DB::connection()->getPdo();

        // Los archivos se acumulan: se juntan los pendientes de TODAS las cargas activas.
        $cargasActivas = cargasActivas($pdo);
        $hayPendientes = !empty($cargasActivas);

        $filtros = [
            'cedi'  => trim((string) $request->query('cedi', '')),
            'linea' => trim((string) $request->query('linea', '')),
            'plu'   => trim((string) $request->query('plu', '')),
        ];

        $porCedi = $hayPendientes ? consolidadoPorCedi($pdo, $filtros) : [];

        // Totales generales: se suman los de cada CEDI ya calculados.
        $totalGeneral   = ['unidades' => 0, 'cajas' => 0, 'saldos' => 0, 'peso_kg' => 0, 'productos' => 0];
        $totalesPorCedi = [];
        foreach ($porCedi as $cedi => $filas) {
            $t = totalesDelGrupo($filas);
            $totalesPorCedi[$cedi] = $t;
            foreach ($totalGeneral as $clave => $_) {
                $totalGeneral[$clave] += $t[$clave];
            }
        }

        // Importación a la espera de que se conteste "¿ya estaba cargado, lo subo igual?".
        $pendiente = $request->session()->get('importacion_pendiente');

        return view('consolidados.consolidados', [
            'cargasActivas'     => $cargasActivas,
            'hayPendientes'     => $hayPendientes,
            'filtros'           => $filtros,
            'filtrosActivos'    => array_filter($filtros),
            'porCedi'           => $porCedi,
            'cedisDisponibles'  => $hayPendientes ? cedisPendientes($pdo) : [],
            // De qué cadena es cada CEDI: los del Éxito y los de Cencosud conviven en la lista.
            'cadenasDeCedi'     => $hayPendientes ? cadenasPorCedi($pdo) : [],
            'lineasDisponibles' => lineasDelMaestro($pdo),
            'sinMaestro'        => $hayPendientes ? pluSinMaestro($pdo) : 0,
            'totalGeneral'      => $totalGeneral,
            'totalesPorCedi'    => $totalesPorCedi,
            'pendiente'         => $pendiente,
            'hayQueConfirmar'   => $pendiente && $request->query('confirmar') === 'duplicado',
        ]);
    }

    public function maestro(Request $request)
    {
        require_once app_path('Servicios/consolidados/model_maestro_exito.php');
        $pdo = DB::connection()->getPdo();

        $busqueda = trim((string) $request->query('q', ''));
        // 'faltantes': solo los productos de lo pendiente que no se pueden convertir a cajas (la
        // lista de lo que hay que conseguir). 'exito': las EXCEPCIONES de Éxito.
        $soloFaltantes = $request->query('ver') === 'faltantes';
        $verExito      = $request->query('ver') === 'exito';

        // El resumen de las excepciones se lee SIEMPRE, no solo en su pestaña: el número de la
        // pestaña "Excepciones de Éxito" tiene que verse también desde la del maestro base (en el
        // sistema anterior ahí salía siempre 0).
        $resumenExito = resumenMaestroExito($pdo)
            ?: ['total' => 0, 'con_unidades' => 0, 'con_cubicaje' => 0, 'actualizado' => null];

        if ($verExito) {
            $productos = [];
        } elseif ($soloFaltantes) {
            $productos = $this->productosFaltantes($pdo);
        } else {
            $sql = "SELECT sku, ean, plu, descripcion, unidades_por_caja, presentacion, peso_unidad_kg,
                           linea, NULL AS unidades_pedidas
                    FROM maestro_productos";
            $params = [];
            if ($busqueda !== '') {
                // Un marcador por columna: con sentencias preparadas reales un nombre no se repite.
                $sql .= " WHERE sku LIKE :q_sku OR plu LIKE :q_plu OR ean LIKE :q_ean OR descripcion LIKE :q_desc";
                $patron = '%' . $busqueda . '%';
                $params = [':q_sku' => $patron, ':q_plu' => $patron, ':q_ean' => $patron, ':q_desc' => $patron];
            }
            $sql .= " ORDER BY descripcion IS NULL, descripcion, sku";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $productos = $stmt->fetchAll();
        }

        return view('consolidados.maestro', [
            'busqueda'      => $busqueda,
            'soloFaltantes' => $soloFaltantes,
            'verExito'      => $verExito,
            'excepciones'   => $verExito ? listaMaestroExito($pdo, $busqueda) : [],
            'resumenExito'  => $resumenExito,
            'productos'     => $productos,
            'totalMaestro'  => (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos")->fetchColumn(),
            'conUnidades'   => (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos WHERE unidades_por_caja > 0")->fetchColumn(),
            'conPlu'        => (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos WHERE plu IS NOT NULL")->fetchColumn(),
            'faltantes'     => pluSinMaestro($pdo),
        ]);
    }

    /**
     * Los productos de lo pendiente que no se pueden convertir a cajas, ordenados por cuántas
     * unidades se pidieron. Se resuelven con la MISMA función que Consolidados y Picking
     * (productoDelMaestro, que mira EAN y PLU), no con un LEFT JOIN por PLU que daría otro resultado.
     */
    private function productosFaltantes($pdo): array
    {
        $stmt = $pdo->query(
            "SELECT plu, ean_item, sku_item, descripcion_item, SUM(unidades) AS unidades_pedidas
             FROM consolidado_lineas WHERE despachado = 0
             GROUP BY plu, ean_item, sku_item, descripcion_item ORDER BY unidades_pedidas DESC"
        );

        $mapa = mapaMaestro($pdo);
        $productos = [];
        foreach ($stmt as $fila) {
            $p = productoDelMaestro($mapa, $fila['ean_item'], $fila['plu'], $fila['sku_item'], $fila['descripcion_item']);
            if ($p !== null && !empty($p['unidades_por_caja'])) {
                continue;
            }
            $productos[] = [
                'sku'               => $p['sku'] ?? null,
                'ean'               => $fila['ean_item'],
                'plu'               => $fila['plu'],
                'descripcion'       => $p['descripcion'] ?? null,
                'unidades_por_caja' => $p['unidades_por_caja'] ?? null,
                'presentacion'      => $p['presentacion'] ?? null,
                'peso_unidad_kg'    => $p['peso_unidad_kg'] ?? null,
                'linea'             => $p['linea'] ?? null,
                'unidades_pedidas'  => (int) $fila['unidades_pedidas'],
            ];
        }
        return $productos;
    }

    // =============================================================================================
    // ACCIONES
    // =============================================================================================

    public function acciones(Request $request)
    {
        $pdo    = DB::connection()->getPdo();
        $accion = $request->input('accion', $request->query('accion', ''));
        $post   = $request->isMethod('post');

        // Las acciones del maestro piden su permiso; todas las demás, el de Consolidados.
        $delMaestro = in_array($accion, ['importar_maestro_sap', 'importar_maestro', 'importar_maestro_exito', 'eliminar_excepcion_exito'], true);
        $permiso    = $delMaestro ? 'modulo_maestro' : 'modulo_consolidados';
        if (!tienePermiso($permiso)) {
            return redirect()->to(route($delMaestro ? 'maestro' : 'consolidados') . '?error=acceso_denegado');
        }

        switch ($accion) {
            case 'importar_consolidado':
                return $post ? $this->importarConsolidado($request, $pdo) : redirect()->route('consolidados');

            case 'resolver_duplicado':
                return $post ? $this->resolverDuplicado($request, $pdo) : redirect()->route('consolidados');

            // El maestro desde el export de facturación de SAP: la vía principal (trae SKU,
            // descripción, EAN, empaque y peso de una vez).
            case 'importar_maestro_sap':
            case 'importar_maestro':
                if (!$post) {
                    return redirect()->route('maestro');
                }
                require_once app_path('Servicios/consolidados/model_consolidados_import.php');
                $archivo = $this->archivoExcel($request);
                if (is_string($archivo)) {
                    return redirect()->route('maestro', ['error' => $archivo]);
                }
                $resultado = $accion === 'importar_maestro_sap'
                    ? importarMaestroDesdeSap($pdo, $archivo->getRealPath())
                    : importarMaestro($pdo, $archivo->getRealPath());
                guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
                return redirect()->route('maestro');

            // Las excepciones de Éxito: SKU con empaque/cubicaje propios del Éxito.
            case 'importar_maestro_exito':
                if (!$post) {
                    return redirect()->route('maestro', ['ver' => 'exito']);
                }
                require_once app_path('Servicios/consolidados/model_maestro_exito.php');
                $archivo = $this->archivoExcel($request);
                if (is_string($archivo)) {
                    return redirect()->route('maestro', ['ver' => 'exito', 'error' => $archivo]);
                }
                $resultado = importarMaestroExito($pdo, $archivo->getRealPath());
                guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
                return redirect()->route('maestro', ['ver' => 'exito']);

            case 'eliminar_excepcion_exito':
                if ($post) {
                    require_once app_path('Servicios/consolidados/model_maestro_exito.php');
                    $skus = $request->input('skus');
                    $skus = is_array($skus) ? $skus : array_filter([(string) $request->input('sku', '')]);
                    $n = eliminarExcepcionesExito($pdo, $skus);
                    guardarMensajeFlashTexto($n > 0 ? 'exito' : 'error',
                        $n > 0 ? "{$n} excepción(es) eliminada(s)." : 'No se eliminó ninguna excepción.');
                }
                return redirect()->route('maestro', ['ver' => 'exito']);

            case 'pdf_seleccion':
                return $post ? $this->pdfSeleccion($request, $pdo) : redirect()->route('consolidados');

            // El consolidado para RÓTULOS: el mismo pedido abierto por punto de venta (lo que se le
            // entrega o se le muestra a la cadena).
            case 'pdf_externo':
                $filtros = ['cedi' => trim((string) $request->query('cedi', '')), 'linea' => trim((string) $request->query('linea', ''))];
                $porCedi = consolidadoExternoPorCedi($pdo, $filtros);
                if (empty($porCedi)) {
                    return redirect()->route('consolidados', ['error' => 'sin_datos']);
                }
                require_once app_path('Servicios/consolidados/helper_consolidado_externo_pdf.php');
                $nombre = $filtros['cedi'] !== ''
                    ? nombreArchivoCediExterno($filtros['cedi'])
                    : 'Consolidado_rotulos_todos_los_CEDI_' . date('Ymd') . '.pdf';
                return descargarConsolidadoExternoPdf($porCedi, cargaVigente($pdo) ?? [], $nombre);

            // El consolidado para ALISTAMIENTO (el papel del elevador). Sin ?cedi= sale completo,
            // con una hoja por CEDI.
            case 'pdf':
                $filtros = ['cedi' => trim((string) $request->query('cedi', '')), 'linea' => trim((string) $request->query('linea', ''))];
                $porCedi = consolidadoPorCedi($pdo, $filtros);
                if (empty($porCedi)) {
                    return redirect()->route('consolidados', ['error' => 'sin_datos']);
                }
                require_once app_path('Servicios/consolidados/helper_consolidado_pdf.php');
                $nombre = $filtros['cedi'] !== ''
                    ? nombreArchivoCedi($filtros['cedi'])
                    : 'Consolidado_alistamiento_todos_los_CEDI_' . date('Ymd') . '.pdf';
                // $meta: la última carga importada, solo como referencia de "emitido".
                return descargarConsolidadoPdf($porCedi, cargaVigente($pdo) ?? [], $nombre);
        }

        return redirect()->route('consolidados');
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * El Excel subido, o el código de error para el aviso ('archivo' / 'formato').
     */
    private function archivoExcel(Request $request): UploadedFile|string
    {
        $archivo = $request->file('archivo');
        if (!$archivo || !$archivo->isValid()) {
            return 'archivo';
        }
        if (!in_array(strtolower($archivo->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
            return 'formato';
        }
        return $archivo;
    }

    /** Dónde esperan los archivos cuya importación quedó pendiente de un Sí o un No. */
    private function carpetaImportacionesPendientes(): string
    {
        // Dentro de storage/, que no se sirve por la web: son datos de pedidos.
        $carpeta = storage_path('app/importaciones_pendientes');
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }
        return $carpeta;
    }

    /** Borra el archivo que hubiera quedado esperando y limpia la sesión. */
    private function descartarImportacionPendiente(Request $request): void
    {
        $pendiente = $request->session()->pull('importacion_pendiente');
        if (!empty($pendiente['ruta'])) {
            @unlink($pendiente['ruta']);
        }
    }

    private function importarConsolidado(Request $request, $pdo)
    {
        require_once app_path('Servicios/consolidados/model_consolidados_import.php');

        $archivo = $this->archivoExcel($request);
        if (is_string($archivo)) {
            return redirect()->route('consolidados', ['error' => $archivo]);
        }
        $ruta = $archivo->getRealPath();

        // ¿Es exactamente lo mismo que ya está cargado? Si lo es, se pregunta antes: reimportar
        // duplicaría los pedidos.
        $cargaPrevia = cargaConLaMismaHuella($pdo, huellaDelConsolidado($ruta));

        // Si no es el mismo archivo, todavía puede ser un PEDAZO de uno ya cargado (un Consolidado
        // por zona con parte de las órdenes del completo): lo delatan las órdenes repetidas.
        $ordenesRepetidas = null;
        if (!$cargaPrevia) {
            $yaPendientes = ordenesYaPendientes($pdo, $ruta);
            if ($yaPendientes !== null && $yaPendientes['repetidas']) {
                $ordenesRepetidas = $yaPendientes;
            }
        }

        if ($cargaPrevia || $ordenesRepetidas) {
            $this->descartarImportacionPendiente($request);   // por si había otro esperando

            $nombreGuardado = 'consolidado_' . bin2hex(random_bytes(8)) . '.xlsx';
            try {
                $archivo->move($this->carpetaImportacionesPendientes(), $nombreGuardado);
            } catch (\Throwable $e) {
                guardarMensajeFlashTexto('error', 'No se pudo preparar el archivo para confirmarlo. Volvé a subirlo.');
                return redirect()->route('consolidados');
            }

            $request->session()->put('importacion_pendiente', [
                'ruta'              => $this->carpetaImportacionesPendientes() . '/' . $nombreGuardado,
                'nombre'            => $archivo->getClientOriginalName(),
                'carga_previa'      => $cargaPrevia,
                'ordenes_repetidas' => $ordenesRepetidas,
            ]);

            return redirect()->route('consolidados', ['confirmar' => 'duplicado']);
        }

        $resultado = importarConsolidado($pdo, $ruta, $archivo->getClientOriginalName(), auth()->id());

        // El detalle ("se importaron 348 líneas") trae un número: va como texto, no por código.
        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
        return redirect()->route('consolidados');
    }

    /** La respuesta a "este archivo ya está cargado, ¿lo subo igual?". */
    private function resolverDuplicado(Request $request, $pdo)
    {
        $pendiente = $request->session()->get('importacion_pendiente');

        // Sin nada esperando (pantalla vieja o POST recargado): no se importa nada ni se avisa.
        if (!$pendiente || !is_file($pendiente['ruta'])) {
            $this->descartarImportacionPendiente($request);
            return redirect()->route('consolidados');
        }

        if ($request->input('respuesta') !== 'si') {
            $this->descartarImportacionPendiente($request);
            guardarMensajeFlashTexto('exito', 'No se importó nada: el Consolidado cargado quedó como estaba.');
            return redirect()->route('consolidados');
        }

        require_once app_path('Servicios/consolidados/model_consolidados_import.php');
        $resultado = importarConsolidado($pdo, $pendiente['ruta'], $pendiente['nombre'], auth()->id());
        $this->descartarImportacionPendiente($request);

        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
        return redirect()->route('consolidados');
    }

    /**
     * Las descargas de los CEDI tildados (barra de abajo). 'formato' lo pone el botón:
     *   · interno   → consolidado para alistamiento, una hoja por CEDI;
     *   · externo   → consolidado para rótulos, una hoja por CEDI;
     *   · productos → todos los productos de esos CEDI sumados en UNA tabla.
     */
    private function pdfSeleccion(Request $request, $pdo)
    {
        // Solo textos: un cedi[][] armado a mano llegaría como arreglo y no es un nombre.
        $cedis = array_values(array_unique(array_filter(
            array_map(fn ($c) => is_string($c) ? trim($c) : '', (array) $request->input('cedi', [])),
            fn ($c) => $c !== ''
        )));
        $formato = $request->input('formato', '');

        if (!$cedis || !in_array($formato, ['interno', 'externo', 'productos'], true)) {
            guardarMensajeFlashTexto('error', 'Seleccioná al menos un CEDI para descargar.');
            return redirect()->route('consolidados');
        }

        $linea   = $request->input('linea');
        $filtros = ['cedis' => $cedis, 'linea' => is_string($linea) ? trim($linea) : ''];
        $meta    = cargaVigente($pdo) ?? [];
        $varios  = count($cedis) . '_CEDI_' . date('Ymd') . '.pdf';
        $sinDatos = fn () => redirect()->route('consolidados', ['error' => 'sin_datos']);

        if ($formato === 'productos') {
            $datos = productosDelConsolidado($pdo, $filtros);
            if (empty($datos['filas'])) {
                return $sinDatos();
            }
            require_once app_path('Servicios/consolidados/helper_consolidado_productos_pdf.php');
            $nombre = count($cedis) === 1 ? 'Productos_' . nombreArchivoCedi($cedis[0]) : 'Productos_consolidado_' . $varios;
            return descargarProductosConsolidadoPdf($datos, $meta, $nombre);
        }

        if ($formato === 'externo') {
            $porCedi = consolidadoExternoPorCedi($pdo, $filtros);
            if (empty($porCedi)) {
                return $sinDatos();
            }
            require_once app_path('Servicios/consolidados/helper_consolidado_externo_pdf.php');
            $nombre = count($cedis) === 1 ? nombreArchivoCediExterno($cedis[0]) : 'Consolidado_rotulos_' . $varios;
            return descargarConsolidadoExternoPdf($porCedi, $meta, $nombre);
        }

        $porCedi = consolidadoPorCedi($pdo, $filtros);
        if (empty($porCedi)) {
            return $sinDatos();
        }
        require_once app_path('Servicios/consolidados/helper_consolidado_pdf.php');
        $nombre = count($cedis) === 1 ? nombreArchivoCedi($cedis[0]) : 'Consolidado_alistamiento_' . $varios;
        return descargarConsolidadoPdf($porCedi, $meta, $nombre);
    }
}
