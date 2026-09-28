{{-- El consolidado para el elevador: qué hay que bajar de bodega para cada CEDI, en cajas y saldos. --}}
@extends('layouts.app')

@section('titulo', 'Consolidados')

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Consolidados</h2>
            <div class="modulo-acciones">
                @if (tienePermiso('modulo_maestro'))
                    <a class="btn" href="{{ route('maestro') }}">
                        <i class="fa-solid fa-list-check"></i> Maestro de productos
                    </a>
                @endif
                @if (!empty($porCedi))
                    {{-- Los dos consolidados son el MISMO pedido visto de dos formas: el de
                         ALISTAMIENTO junta todo lo del CEDI por producto (el papel del elevador), y el
                         de RÓTULOS lo abre por punto de venta (lo que se le entrega a la cadena).
                         Las acciones siguen diciendo interno/externo: son identificadores. --}}
                    <a class="btn" href="{{ route('consolidados.acciones', ['accion' => 'pdf'] + $filtrosActivos) }}">
                        <i class="fa-solid fa-file-pdf"></i> Consolidado para alistamiento
                    </a>
                    <a class="btn" href="{{ route('consolidados.acciones', ['accion' => 'pdf_externo'] + $filtrosActivos) }}">
                        <i class="fa-solid fa-shop"></i> Consolidado para rótulos
                    </a>
                @endif
                <button type="button" class="btn btn-primario" data-abrir="modal-importar-exito">
                    <i class="fa-solid fa-store"></i> Consolidado de Éxito
                </button>
                <button type="button" class="btn" data-abrir="modal-importar-otros">
                    <i class="fa-solid fa-users"></i> Consolidado de otros clientes
                </button>
            </div>
        </header>

        @if ($hayPendientes)
            @php
                // El tooltip lista cada archivo activo con su fecha: la pastilla sola dice "3 archivos".
                $tituloArchivos = implode("\n", array_map(
                    fn ($c) => $c['nombre_archivo'] . ' · ' . date('d/m/Y H:i', strtotime($c['fecha_carga'])),
                    $cargasActivas
                ));
            @endphp
            <div class="pastillas">
                <span class="pastilla" title="{{ $tituloArchivos }}">
                    {{ count($cargasActivas) === 1 ? 'Archivo' : 'Archivos activos' }}
                    <strong>{{ count($cargasActivas) === 1 ? $cargasActivas[0]['nombre_archivo'] : count($cargasActivas) }}</strong>
                </span>
                <span class="pastilla">CEDI <strong>{{ count($cedisDisponibles) }}</strong></span>
                <span class="pastilla">Unidades <strong>{{ fmtMil($totalGeneral['unidades']) }}</strong></span>
                <span class="pastilla">Cajas <strong>{{ fmtMil($totalGeneral['cajas']) }}</strong></span>
                <span class="pastilla">Saldos <strong>{{ fmtMil($totalGeneral['saldos']) }}</strong></span>
                @if ($totalGeneral['peso_kg'] > 0)
                    <span class="pastilla" title="Peso bruto de lo pedido, según el peso por unidad del maestro">
                        Peso <strong>{{ fmtMil($totalGeneral['peso_kg']) }} kg</strong>
                    </span>
                @endif
                @if ($sinMaestro > 0)
                    <span class="pastilla pastilla-alerta">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        PLU sin maestro <strong>{{ $sinMaestro }}</strong>
                    </span>
                @endif
            </div>
        @endif

        @if ($sinMaestro > 0)
            <div class="aviso aviso-atencion">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>{{ $sinMaestro }} PLU de este archivo no están en el maestro de productos.</strong>
                    El Consolidado que manda la cadena trae el PLU y las unidades, pero no la descripción
                    ni las unidades por caja, así que esas filas se muestran con una raya en vez de cajas
                    y <strong>no suman en los totales</strong>.
                    @if (tienePermiso('modulo_maestro'))
                        Cárgalos en <a href="{{ route('maestro') }}">Maestro de productos</a>
                        y las cajas aparecen solas, sin volver a importar el Consolidado.
                    @endif
                </div>
            </div>
        @endif

        @if ($hayPendientes)
            <form class="filtros" method="GET">
                <div class="filtro">
                    <label for="f-cedi">CEDI</label>
                    <select name="cedi" id="f-cedi">
                        <option value="">Todos</option>
                        @foreach ($cedisDisponibles as $c)
                            <option value="{{ $c['cedi'] }}" @selected($filtros['cedi'] === $c['cedi'])>
                                {{ $c['cedi'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if (!empty($lineasDisponibles))
                    <div class="filtro">
                        <label for="f-linea">Línea</label>
                        <select name="linea" id="f-linea">
                            <option value="">Todas</option>
                            @foreach ($lineasDisponibles as $l)
                                <option value="{{ $l }}" @selected($filtros['linea'] === $l)>
                                    {{ $l }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="filtro" style="flex: 1;">
                    <label for="f-plu">Buscar por PLU, SKU o descripción</label>
                    <input type="text" name="plu" id="f-plu" value="{{ $filtros['plu'] }}"
                           placeholder="Ej. 3577953">
                </div>

                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                @if ($filtrosActivos)
                    <a class="btn" href="{{ route('consolidados') }}">Limpiar</a>
                @endif
            </form>
        @endif

        @if (!$hayPendientes)
            <div class="tabla-caja">
                <p class="tabla-vacia">
                    No hay ningún pedido pendiente.<br>
                    Usa <strong>Importar Consolidado</strong> para subir un archivo.
                </p>
            </div>

        @elseif (empty($porCedi))
            <div class="tabla-caja">
                <p class="tabla-vacia">Ningún producto coincide con el filtro.</p>
            </div>

        @else
            {{-- Tilda todos los CEDI que se están viendo; lo tildado alimenta la barra de descargas. --}}
            <label class="seleccion-general">
                <input type="checkbox" id="chk-todos-cedi">
                Seleccionar todos <span>({{ count($porCedi) }} CEDI)</span>
            </label>

            @foreach ($porCedi as $cedi => $filas)
                @php
                    $t = $totalesPorCedi[$cedi];
                    $conLinea = $filtros['linea'] !== '' ? ['linea' => $filtros['linea']] : [];
                @endphp
                {{-- <details>: el desplegar lo hace el navegador; cerrado es simplemente no poner open.
                     El resumen de la cabecera sigue a la vista con el grupo cerrado. --}}
                <details class="grupo-desplegable">
                    <summary class="grupo-cabecera">
                        {{-- Tildarla no abre ni cierra el grupo: desplegables.js corta ese clic. --}}
                        <input type="checkbox" class="chk-cedi"
                               data-cedi="{{ $cedi }}"
                               title="Seleccionar este CEDI"
                               aria-label="Seleccionar {{ $cedi }}">
                        <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>
                        <div class="grupo-titulo">
                            <div class="grupo-nombre">
                                {{ $cedi }}
                                @if (!empty($cadenasDeCedi[$cedi]))
                                    <span class="cadena-etiqueta">{{ $cadenasDeCedi[$cedi] }}</span>
                                @endif
                            </div>
                            <div class="grupo-resumen">
                                {{ $t['productos'] }} productos ·
                                {{ fmtMil($t['unidades']) }} unidades ·
                                {{ fmtMil($t['cajas']) }} cajas ·
                                {{ fmtMil($t['saldos']) }} saldos
                                @if ($t['peso_kg'] > 0)
                                    · {{ fmtMil($t['peso_kg']) }} kg
                                @endif
                                @if ($t['sin_maestro'] > 0)
                                    · {{ $t['sin_maestro'] }} sin maestro
                                @endif
                            </div>
                        </div>
                        <a class="btn btn-chico" href="{{ route('consolidados.acciones', ['accion' => 'pdf', 'cedi' => $cedi] + $conLinea) }}">
                            <i class="fa-solid fa-file-pdf"></i> Alistamiento
                        </a>
                        <a class="btn btn-chico" href="{{ route('consolidados.acciones', ['accion' => 'pdf_externo', 'cedi' => $cedi] + $conLinea) }}">
                            <i class="fa-solid fa-shop"></i> Rótulos
                        </a>
                    </summary>

                    <div class="tabla-caja">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>PLU</th>
                                    <th>SKU</th>
                                    <th>Descripción</th>
                                    <th class="num">Unidades</th>
                                    <th class="num">Cajas</th>
                                    <th class="num">Saldos</th>
                                    <th class="centro">Ptos. venta</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($filas as $f)
                                    <tr class="{{ $f['sin_maestro'] ? 'fila-sin-maestro' : '' }}">
                                        <td>{{ $f['plu'] }}</td>
                                        <td>{{ $f['sku'] !== null ? $f['sku'] : oGuion(null, 'sin-dato') }}</td>
                                        <td>
                                            @if ($f['descripcion'] !== null)
                                                {{ $f['descripcion'] }}
                                            @else
                                                <span class="sin-dato">sin descripción en el maestro</span>
                                            @endif
                                        </td>
                                        <td class="num">{{ fmtMil((int) $f['unidades']) }}</td>
                                        @if ($f['sin_maestro'])
                                            <td class="num sin-dato" title="Falta cargar las unidades por caja de este PLU">—</td>
                                            <td class="num sin-dato">—</td>
                                        @else
                                            <td class="num"><strong>{{ fmtMil((int) $f['cajas']) }}</strong></td>
                                            <td class="num">
                                                @if ((int) $f['saldos'] > 0)
                                                    {{ fmtMil((int) $f['saldos']) }}
                                                @else
                                                    <span class="sin-dato">0</span>
                                                @endif
                                            </td>
                                        @endif
                                        <td class="centro">{{ (int) $f['puntos_venta'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endforeach
        @endif

    </div>
@endsection

@section('modales')
    @if (!empty($porCedi))
        {{-- BARRA DE DESCARGAS DE LOS CEDI TILDADOS: un solo formulario para los tres botones, cada
             uno manda su 'formato'. Los CEDI tildados los rellena scripts_consolidados.js. --}}
        <div class="barra-seleccion" id="barra-seleccion" hidden>
            <div class="barra-seleccion-info">
                <strong id="barra-conteo">0</strong> CEDI seleccionado(s)
                <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
            </div>

            <div class="barra-seleccion-acciones">
                <form action="{{ route('consolidados.acciones') }}" method="POST" id="form-seleccion">
                    @csrf
                    <input type="hidden" name="accion" value="pdf_seleccion">
                    <input type="hidden" name="linea" value="{{ $filtros['linea'] }}">
                    <div id="campos-seleccion"></div>

                    <button type="submit" class="btn btn-chico" name="formato" value="interno">
                        <i class="fa-solid fa-file-pdf"></i> Consolidado para alistamiento
                    </button>
                    <button type="submit" class="btn btn-chico" name="formato" value="externo">
                        <i class="fa-solid fa-shop"></i> Consolidado para rótulos
                    </button>
                    <button type="submit" class="btn btn-chico" name="formato" value="productos"
                            title="Todos los productos de los CEDI seleccionados, sumados en una sola tabla">
                        <i class="fa-solid fa-table-list"></i> Todos los productos
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- Dos entradas para importar: la del Éxito y la de otros clientes. Las dos usan el MISMO
         import (reconoce el formato solo y clasifica cada línea por su empresa compradora). --}}
    @include('consolidados.modal_importar', [
        'id'     => 'modal-importar-exito',
        'titulo' => 'Consolidado de Éxito',
        'icono'  => 'fa-store',
        'ayuda'  => 'El Consolidado que manda el Éxito (o el export de SAP de despachos directos del Éxito). Las columnas se buscan por su nombre, en cualquier orden.',
        'canal'  => 'exito',
    ])
    @include('consolidados.modal_importar', [
        'id'     => 'modal-importar-otros',
        'titulo' => 'Consolidado de otros clientes',
        'icono'  => 'fa-users',
        'ayuda'  => 'El Consolidado de otras cadenas (Farmatodo, Cencosud…) o el export de facturación de SAP de clientes directos. Se reconoce solo.',
        'canal'  => 'otros',
    ])

    @if ($hayQueConfirmar)
        @include('consolidados.modal_duplicado')
    @endif
@endsection

@push('scripts')
    <script src="{{ assetV('assets/js/desplegables.js') }}"></script>
    <script src="{{ assetV('assets/js/modales.js') }}"></script>
    <script src="{{ assetV('modulos/consolidados/scripts_consolidados.js') }}"></script>
@endpush
