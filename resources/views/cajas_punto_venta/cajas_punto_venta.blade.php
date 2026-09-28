{{-- Cuántas cajas le corresponden a cada punto de venta, CEDI por CEDI.

     Es la planilla del camión: el que carga y el que recibe en el CEDI cuentan BULTOS, no unidades.
     Solo salen los puntos de venta anexados a un CEDI de cadena; los clientes directos no tienen
     tiendas que listar. --}}
@extends('layouts.app')

@section('titulo', 'Cajas por punto de venta')

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Cajas por punto de venta</h2>
            <div class="modulo-acciones">
                <button type="button" class="btn" data-abrir="modal-almacenes">
                    <i class="fa-solid fa-store"></i> Almacenes Éxito
                </button>
                @if (!empty($porCedi))
                    <a class="btn" href="{{ route('cajas_punto_venta.acciones', ['accion' => 'pdf'] + $filtrosActivos) }}">
                        <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                    </a>
                @endif
            </div>
        </header>

        @if ($error !== null)
            <div class="aviso aviso-atencion">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>{{ textoMensajeSistema('error', preg_replace('/[^a-z0-9_]/', '', strtolower((string) $error))) }}</div>
            </div>
        @endif

        <div class="aviso aviso-info">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                Acá salen <strong>solo los puntos de venta anexados a un CEDI</strong>. Los
                despachos directos a clientes propios —distribuidores, hoteles, personas— no
                tienen tiendas que listar y se ven en <strong>Consolidados</strong>.
            </div>
        </div>

        @if ($totalGeneral['sin_maestro'] > 0)
            <div class="aviso aviso-atencion">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>{{ fmtMil($totalGeneral['sin_maestro']) }}</strong> línea(s)
                    corresponden a productos que no están en el maestro o no tienen unidades por
                    caja, así que <strong>no suman cajas</strong> en esta planilla. Cargalos en
                    <a href="{{ route('maestro') }}">Maestro
                    de productos</a> para que el conteo quede completo.
                </div>
            </div>
        @endif

        <form method="GET" class="filtros">
            <div class="filtro">
                <label for="f-cedi">CEDI</label>
                <select name="cedi" id="f-cedi">
                    <option value="">Todos</option>
                    @foreach ($cedisDisponibles as $c)
                        <option value="{{ $c }}" @selected($filtros['cedi'] === $c)>
                            {{ $c }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="filtro" style="flex: 1;">
                <label for="f-punto">Punto de venta</label>
                <input type="text" name="punto" id="f-punto" value="{{ $filtros['punto'] }}"
                       placeholder="Número o nombre. Para varios: 550, 4847, exito bello"
                       title="Se puede buscar más de un punto de venta a la vez, separados por coma.">
            </div>
            <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
            @if ($filtrosActivos)
                <a class="btn" href="{{ route('cajas_punto_venta') }}">Limpiar</a>
            @endif
        </form>

        @if (empty($porCedi))
            <div class="tabla-caja">
                <p class="tabla-vacia">
                    No hay puntos de venta que mostrar. O no hay pedidos pendientes de un CEDI,
                    o el filtro no coincide con ninguno.
                </p>
            </div>
        @else
            <div class="pastillas">
                <span class="pastilla">{{ fmtMil(count($porCedi)) }} CEDI</span>
                <span class="pastilla">{{ fmtMil($totalGeneral['puntos']) }} puntos de venta</span>
                <span class="pastilla pastilla-fuerte">{{ fmtMil($totalGeneral['cajas']) }} cajas</span>
                <span class="pastilla">{{ fmtMil($totalGeneral['unidades']) }} unidades</span>
            </div>

            @foreach ($porCedi as $cedi => $datos)
                @php $t = $datos['totales']; @endphp
                <details class="grupo-desplegable" open>
                    <summary class="grupo-cabecera">
                        <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>
                        <div class="grupo-titulo">
                            <div class="grupo-nombre">{{ $cedi }}</div>
                            <div class="grupo-resumen">
                                {{ fmtMil($t['puntos']) }} puntos de venta ·
                                {{ fmtMil($t['cajas']) }} cajas ·
                                {{ fmtMil($t['unidades']) }} unidades
                                @if ($t['sin_maestro'] > 0)
                                    · {{ fmtMil($t['sin_maestro']) }} sin maestro
                                @endif
                            </div>
                        </div>
                        <a class="btn btn-chico" href="{{ route('cajas_punto_venta.acciones', ['accion' => 'pdf', 'cedi' => $cedi]) }}">
                            <i class="fa-solid fa-file-pdf"></i> PDF
                        </a>
                    </summary>

                    {{-- Las columnas son las de la LISTA DE EMPAQUE que usan las cadenas, para que
                         esta pantalla se pueda comparar renglón a renglón con el papel que firma el
                         CEDI al recibir. "Refrigerado" y "Otros" van SIEMPRE vacías: el formato es de
                         ellos, y una columna de menos obliga a contar posiciones.

                         ANCHOS: en el colgroup y con table-layout:fixed, por el TOTAL GENERAL de más
                         abajo —otra tabla, sin encabezado—: así las dos parten las columnas igual.
                         Los dos colgroup tienen que seguir siendo IDÉNTICOS. --}}
                    <div class="tabla-caja">
                        <table class="tabla" style="table-layout: fixed;">
                            @include('cajas_punto_venta.colgroup')
                            <thead>
                                <tr>
                                    <th class="centro">
                                        <input type="checkbox" class="chk-todos"
                                               title="Seleccionar todos los puntos de venta de este CEDI"
                                               aria-label="Seleccionar todos los puntos de venta de este CEDI">
                                    </th>
                                    <th>Código</th>
                                    <th>Nombre del almacén</th>
                                    <th class="num">Cajas<br>producto seco</th>
                                    <th class="num">Cajas<br>producto refrigerado</th>
                                    <th class="num">Otros</th>
                                    <th class="num">Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($datos['puntos'] as $clave => $p)
                                    <tr class="fila-punto">
                                        <td class="centro">
                                            <input type="checkbox" class="chk-punto"
                                                   aria-label="Seleccionar {{ $p['nombre'] ?: $p['punto_venta'] }}"
                                                   data-cedi="{{ $cedi }}"
                                                   data-punto="{{ $p['punto_venta'] }}">
                                        </td>
                                        <td>
                                            @if ($p['numero'] !== '')
                                                <strong>{{ $p['numero'] }}</strong>
                                            @else
                                                <span class="dato-faltante">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($p['nombre'] !== '')
                                                {{ $p['nombre'] }}
                                            @else
                                                <span class="dato-faltante">sin nombre en el archivo</span>
                                            @endif
                                        </td>
                                        <td class="num"><strong>{{ fmtMil($p['cajas']) }}</strong></td>
                                        <td class="num"></td>
                                        <td class="num"></td>
                                        <td class="num"><strong>{{ fmtMil($p['total']) }}</strong></td>
                                        <td>
                                            {{-- data-filas: el detalle por producto, de acá sale qué producto va en cada caja. --}}
                                            <button type="button" class="btn btn-chico btn-rotulo-punto"
                                                    data-cedi="{{ $cedi }}"
                                                    data-numero-cedi="{{ numeroDeCedi($cedi) }}"
                                                    data-punto="{{ $p['punto_venta'] }}"
                                                    data-numero="{{ $p['numero'] }}"
                                                    data-nombre="{{ $p['nombre'] }}"
                                                    data-cajas="{{ (int) $p['cajas'] }}"
                                                    data-filas="{{ json_encode($p['filas'], JSON_UNESCAPED_UNICODE) }}">
                                                <i class="fa-solid fa-tags"></i> Rótulo
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                {{-- colspan="3": la etiqueta ocupa la casilla de selección, el código y el nombre. --}}
                                <tr class="fila-total">
                                    <td colspan="3">TOTAL {{ $cedi }}</td>
                                    <td class="num">{{ fmtMil($t['cajas']) }}</td>
                                    <td class="num"></td>
                                    <td class="num"></td>
                                    <td class="num">{{ fmtMil($t['total']) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </details>
            @endforeach

            @if (count($porCedi) > 1)
                {{-- La suma de todos los CEDI: lo que sale de la bodega en total. --}}
                <div class="tabla-caja">
                    <table class="tabla" style="table-layout: fixed;">
                        @include('cajas_punto_venta.colgroup')
                        <tfoot>
                            <tr class="fila-total fila-total-general">
                                <td colspan="3">TOTAL GENERAL · {{ fmtMil(count($porCedi)) }} CEDI ·
                                    {{ fmtMil($totalGeneral['puntos']) }} puntos de venta</td>
                                <td class="num">{{ fmtMil($totalGeneral['cajas']) }}</td>
                                <td class="num"></td>
                                <td class="num"></td>
                                <td class="num">{{ fmtMil($totalGeneral['total']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        @endif

    </div>
@endsection

@section('modales')
    {{-- BARRA DE SELECCIÓN MÚLTIPLE: acá lo que se tilda es un PUNTO DE VENTA (una fila). --}}
    <div class="barra-seleccion" id="barra-seleccion" hidden>
        <div class="barra-seleccion-info">
            <strong id="barra-conteo">0</strong> punto(s) de venta seleccionado(s)
            <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
        </div>

        <div class="barra-seleccion-acciones">
            <button type="button" class="btn btn-chico" id="btn-rotulos-masivo">
                <i class="fa-solid fa-tags"></i> Rótulos
            </button>

            {{-- Por formulario y no por fetch, para que el navegador la trate como una descarga. --}}
            <form action="{{ route('cajas_punto_venta.acciones') }}" method="POST" id="form-pdf-seleccion">
                @csrf
                <input type="hidden" name="accion" value="pdf_seleccion">
                <div id="campos-pdf-seleccion"></div>
                <button type="submit" class="btn btn-chico btn-primario">
                    <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                </button>
            </form>
        </div>
    </div>

    @include('partes.modal_rotulo', ['accionPdf' => route('picking.acciones')])

    {{-- MODAL: LA LISTA OFICIAL DE ALMACENES DEL ÉXITO --}}
    <div class="modal-fondo" id="modal-almacenes">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2>Almacenes del Éxito</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <form action="{{ route('cajas_punto_venta.acciones') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="accion" value="subir_almacenes">

                <div class="modal-cuerpo">
                    <div class="pastillas" style="margin-bottom: 14px;">
                        <span class="pastilla">Almacenes <strong>{{ fmtMil($almacenes['almacenes'] ?? 0) }}</strong></span>
                        @if (!empty($almacenes['actualizado']))
                            <span class="pastilla">Actualizada <strong>{{ date('d/m/Y H:i', strtotime($almacenes['actualizado'])) }}</strong></span>
                        @endif
                    </div>

                    <div class="campo">
                        <label for="archivo-almacenes">Archivo de Excel (.xlsx)</label>
                        <input type="file" id="archivo-almacenes" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">
                            Con las columnas <strong>Dependencia</strong> (el número de punto de venta) y
                            <strong>Nombre Almacen</strong> (el punto de venta). Agrega las tiendas nuevas y
                            corrige los nombres; no borra las que no vengan en el archivo.
                        </span>
                    </div>

                    <div class="aviso aviso-info" style="margin-bottom: 0;">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>
                            Cada punto de venta del Éxito se muestra como <strong>"dependencia - nombre"</strong>
                            de esta lista, aunque el Consolidado lo traiga sin número o con otro nombre. Se
                            aplica al subir la lista y a cada Consolidado que se importe.
                        </div>
                    </div>
                </div>

                <div class="modal-pie">
                    <button type="button" class="btn" data-cerrar>Cancelar</button>
                    <button type="submit" class="btn btn-primario"><i class="fa-solid fa-upload"></i> Subir lista</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    @include('partes.constantes_js')
    <script src="{{ assetV('assets/js/modales.js') }}"></script>
    <script src="{{ assetV('assets/js/rotulo.js') }}"></script>
    <script src="{{ assetV('modulos/cajas_punto_venta/scripts_cajas_punto_venta.js') }}"></script>
@endpush
