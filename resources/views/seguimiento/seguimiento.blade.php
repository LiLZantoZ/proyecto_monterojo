{{-- Estado de pedidos: una fila por guía/factura, con sus fechas y su estado. Se alimenta a mano o
     subiendo el Excel que exporta cada transportadora; la facturación de SAP va en su pestaña. --}}
@extends('layouts.app')

@section('titulo', 'Estado de pedidos')

@push('estilos')
    <link rel="stylesheet" href="{{ assetV('modulos/seguimiento/seguimiento.css') }}">
@endpush

@php
    // El semáforo de cada estado: verde entregado, azul en camino, ámbar pendiente, gris lo demás.
    $colorEstado = function ($estado) {
        switch (grupoDeEstado($estado)) {
            case 'entregado': return 'estado-verde';
            case 'en_camino': return 'estado-azul';
            case 'pendiente': return 'estado-ambar';
            default:          return 'estado-gris';
        }
    };

    // Fecha para mostrar: 'dd/mm/aaaa hh:mm' si trae hora, 'dd/mm/aaaa' si es solo día, raya si no hay.
    $fecha = function ($valor, $conHora = true) {
        if ($valor === null || $valor === '' || str_starts_with((string) $valor, '0000-00-00')) {
            return oGuion(null);
        }
        $t = strtotime($valor);
        if (!$t) {
            return oGuion(null);
        }
        return date($conHora && date('H:i', $t) !== '00:00' ? 'd/m/Y H:i' : 'd/m/Y', $t);
    };
    // Lo mismo pero en texto plano, para el JSON del detalle.
    $fechaTexto = fn ($valor) => strip_tags((string) $fecha($valor));
@endphp

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Estado de pedidos</h2>
            <div class="modulo-acciones">
                <button type="button" class="btn" data-abrir="modal-nuevo">
                    <i class="fa-solid fa-plus"></i> Registrar pedido
                </button>
                <button type="button" class="btn btn-primario" data-abrir="modal-importar">
                    <i class="fa-solid fa-file-arrow-up"></i> Importar listado
                </button>
            </div>
        </header>

        {{-- Pestañas de origen: separan los pedidos de las transportadoras de los que se adjuntan del
             Consolidado de facturación de SAP (que usan otra numeración y no cruzan). --}}
        @php
            $tabs = [
                'transportadoras' => ['Transportadoras',   'fa-truck-fast',          $conteoTransp],
                'sap'             => ['Facturación (SAP)', 'fa-file-invoice-dollar', $conteoSap],
                'todos'           => ['Todos',             'fa-layer-group',         $conteoTodos],
            ];
        @endphp
        <nav class="pestanas-seg" aria-label="Origen de los pedidos">
            @foreach ($tabs as $clave => $t)
                <a class="pestana-seg{{ $filtros['origen'] === $clave ? ' activa' : '' }}" href="{{ $urlPestana($clave) }}">
                    <i class="fa-solid {{ $t[1] }}"></i>
                    <span>{{ $t[0] }}</span>
                    <span class="pestana-conteo">{{ fmtMil($t[2]) }}</span>
                </a>
            @endforeach
        </nav>

        {{-- Por qué hoy es manual/Excel: no se automatiza sobre las claves privadas de los portales de
             las transportadoras. Cuando haya acceso oficial, entra como una fuente más. --}}
        <div class="aviso aviso-info">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                Cargá acá el estado de los despachos a mano o subiendo el <strong>Excel que baja
                de cada transportadora</strong> (AGV, Proeslog, Solística, entregas propias).
                Cuando cada transportadora entregue un acceso oficial para consultar sus guías,
                se conecta y este tablero se actualiza solo.
            </div>
        </div>

        {{-- El semáforo, clicable: cada pastilla filtra la tabla por su grupo; volver a tocarla (o
             tocar "Pedidos") quita el filtro. --}}
        @php
            $grupoActivo = $filtros['grupo'];
            $claseAct = fn ($g) => $grupoActivo === $g ? ' activa' : '';
        @endphp
        <div class="pastillas pastillas-filtro">
            <a class="pastilla{{ $grupoActivo === '' ? ' activa' : '' }}" href="{{ $urlPastilla('') }}">Pedidos <strong>{{ fmtMil($resumen['total']) }}</strong></a>
            <a class="pastilla pastilla-estado estado-verde{{ $claseAct('entregado') }}" href="{{ $urlPastilla('entregado') }}">Entregados <strong>{{ $resumen['entregado'] }}</strong></a>
            <a class="pastilla pastilla-estado estado-azul{{ $claseAct('en_camino') }}" href="{{ $urlPastilla('en_camino') }}">En camino <strong>{{ $resumen['en_camino'] }}</strong></a>
            <a class="pastilla pastilla-estado estado-ambar{{ $claseAct('pendiente') }}" href="{{ $urlPastilla('pendiente') }}">Pendientes <strong>{{ $resumen['pendiente'] }}</strong></a>
            @if ($resumen['otro'] > 0 || $grupoActivo === 'otro')
                <a class="pastilla pastilla-estado estado-gris{{ $claseAct('otro') }}" href="{{ $urlPastilla('otro') }}">Otros <strong>{{ $resumen['otro'] }}</strong></a>
            @endif
        </div>

        <form method="GET" class="filtros">
            {{-- El grupo del semáforo y la pestaña viajan escondidos para no perderlos al filtrar. --}}
            @if ($filtros['grupo'] !== '')
                <input type="hidden" name="grupo" value="{{ $filtros['grupo'] }}">
            @endif
            <input type="hidden" name="origen" value="{{ $filtros['origen'] }}">
            <div class="filtro">
                <label for="f-transportadora">Transportadora</label>
                <select name="transportadora" id="f-transportadora">
                    <option value="">Todas</option>
                    @foreach ($transportadoras as $t)
                        <option value="{{ $t }}" @selected($filtros['transportadora'] === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filtro">
                <label for="f-estado">Estado</label>
                <select name="estado" id="f-estado">
                    <option value="">Todos</option>
                    @foreach ($estadosCargados as $e)
                        <option value="{{ $e }}" @selected($filtros['estado'] === $e)>{{ $e }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filtro">
                <label for="f-desde">Desde</label>
                <input type="date" name="desde" id="f-desde" value="{{ $filtros['desde'] }}">
            </div>
            <div class="filtro">
                <label for="f-hasta">Hasta</label>
                <input type="date" name="hasta" id="f-hasta" value="{{ $filtros['hasta'] }}">
            </div>
            <div class="filtro" style="flex: 1;">
                <label for="f-buscar">Buscar</label>
                <input type="text" name="buscar" id="f-buscar" value="{{ $filtros['buscar'] }}"
                       placeholder="Factura, guía, cliente, identificación… Para varios: 001, 002"
                       title="Se puede buscar más de uno a la vez, separados por coma. Busca en factura, guía, cliente, detalle, identificación, referencia, pedido y ubicación.">
            </div>
            <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
            @if ($filtrosEnUrl !== '')
                <a class="btn" href="{{ route('seguimiento', ['origen' => $filtros['origen']]) }}">Limpiar</a>
            @endif
        </form>

        @if (!$pedidos)
            <div class="aviso aviso-atencion" style="margin-top: 14px;">
                <i class="fa-solid fa-inbox"></i>
                <div>
                    {{ $filtrosEnUrl !== ''
                        ? 'No hay pedidos con esos filtros. Probá con otros o limpialos.'
                        : 'Todavía no hay pedidos cargados. Registrá uno o subí el listado de una transportadora.' }}
                </div>
            </div>
        @else
            <div class="tabla-caja" style="margin-top: 14px;">
                <table class="tabla tabla-seguimiento tabla-accion-fija{{ $esSap ? ' tabla-sap' : '' }}">
                    <thead>
                        <tr>
                            <th class="col-check">
                                <input type="checkbox" class="chk-todos" id="chk-todos"
                                       title="Seleccionar todos" aria-label="Seleccionar todos los pedidos">
                            </th>
                            @if ($esSap)
                                <th class="num">Fecha factura</th>
                                <th>Factura</th>
                                <th>Cliente</th>
                                <th>Ubicación</th>
                                <th>Numero De Identificación</th>
                                <th class="num">Valor neto</th>
                                <th>Referencia</th>
                                <th>Pedido</th>
                            @else
                                <th>Estado</th>
                                <th>Transportadora</th>
                                <th>Factura / Guía</th>
                                <th>Destino</th>
                                <th>Detalle</th>
                                <th class="num">Despacho</th>
                                <th class="num">Entrega</th>
                            @endif
                            <th class="col-acciones">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pedidos as $p)
                            @php
                                // Todo el pedido viaja en un data-* como JSON, con las fechas ya
                                // formateadas: la fila se abre en un modal con el detalle COMPLETO
                                // (en la tabla se recorta) sin otra consulta.
                                $datosPedido = [
                                    'estado'         => (string) $p['estado'],
                                    'grupo'          => $colorEstado($p['estado']),
                                    'transportadora' => (string) $p['transportadora'],
                                    'factura'        => (string) $p['numero_factura'],
                                    'guia'           => (string) $p['numero_guia'],
                                    'destino'        => (string) $p['destino'],
                                    'bodega'         => (string) $p['bodega'],
                                    'detalle'        => (string) $p['detalle'],
                                    'fecha_guia'     => $fechaTexto($p['fecha_guia']),
                                    'fecha_despacho' => $fechaTexto($p['fecha_despacho']),
                                    'fecha_entrega'  => $fechaTexto($p['fecha_entrega']),
                                    'fuente'         => (string) $p['fuente'],
                                    // Datos de facturación (SAP): vacíos en los de transportadora.
                                    'direccion'      => (string) ($p['direccion'] ?? ''),
                                    'nit'            => (string) ($p['nit'] ?? ''),
                                    'valor_neto'     => ($p['valor_neto'] ?? null) !== null ? fmtPlata($p['valor_neto']) : '',
                                    'referencia'     => (string) ($p['referencia'] ?? ''),
                                    'pedido_cliente' => (string) ($p['pedido_cliente'] ?? ''),
                                ];
                            @endphp
                            <tr class="fila-pedido" tabindex="0"
                                data-pedido="{{ json_encode($datosPedido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) }}">
                                <td class="col-check">
                                    <input type="checkbox" class="chk-fila" value="{{ (int) $p['id_pedido'] }}"
                                           aria-label="Seleccionar pedido {{ $p['numero_factura'] ?: $p['numero_guia'] }}">
                                </td>
                                @if ($esSap)
                                    <td class="num">{{ $fecha($p['fecha_guia'], false) }}</td>
                                    <td><strong>{{ $p['numero_factura'] }}</strong></td>
                                    <td>{{ $p['destino'] ?: oGuion(null) }}</td>
                                    <td>{{ $p['direccion'] ?: oGuion(null) }}</td>
                                    <td>{{ $p['nit'] ?: oGuion(null) }}</td>
                                    <td class="num celda-valor">{{ $p['valor_neto'] !== null ? fmtPlata($p['valor_neto']) : oGuion(null) }}</td>
                                    <td>{{ $p['referencia'] ?: oGuion(null) }}</td>
                                    <td>{{ $p['pedido_cliente'] ?: oGuion(null) }}</td>
                                @else
                                    <td>
                                        <span class="chip-estado {{ $colorEstado($p['estado']) }}">
                                            @if ($p['estado'] !== null && $p['estado'] !== '')
                                                {{ $p['estado'] }}
                                            @else
                                                <span class="dato-faltante">sin estado</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td>{{ $p['transportadora'] }}</td>
                                    <td>
                                        @if ($p['numero_factura'])
                                            <strong>{{ $p['numero_factura'] }}</strong>
                                        @endif
                                        @if ($p['numero_guia'])
                                            <div class="sub-dato">Guía {{ $p['numero_guia'] }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $p['destino'] ?: oGuion(null) }}</td>
                                    <td class="celda-detalle">{{ $p['detalle'] ?: oGuion(null) }}</td>
                                    <td class="num">{{ $fecha($p['fecha_despacho']) }}</td>
                                    <td class="num">{{ $fecha($p['fecha_entrega']) }}</td>
                                @endif
                                <td class="num celda-acciones">
                                    <button type="button" class="btn btn-chico btn-icono btn-ver-pedido"
                                            title="Ver detalle" aria-label="Ver detalle del pedido">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <form action="{{ route('seguimiento.acciones') }}" method="POST"
                                          onsubmit="return confirm('¿Eliminar este pedido del seguimiento?');" style="display:inline;">
                                        @csrf
                                        <input type="hidden" name="accion" value="eliminar_pedido">
                                        <input type="hidden" name="id_pedido" value="{{ (int) $p['id_pedido'] }}">
                                        <button type="submit" class="btn btn-chico btn-icono" title="Eliminar" aria-label="Eliminar pedido">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($esSap)
                        <tfoot>
                            <tr class="fila-total">
                                <td colspan="6" class="total-etiqueta">
                                    Total Valor neto
                                    <span class="total-nota">({{ fmtMil($totalPedidos) }} factura{{ $totalPedidos === 1 ? '' : 's' }}, todo el filtro)</span>
                                </td>
                                <td class="num total-monto">{{ fmtPlata($totalValorNeto) }}</td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- La tabla se pagina en el servidor (50 por página); los filtros se conservan. --}}
            @include('partes.paginacion', ['total' => $totalPedidos, 'etiqueta' => 'pedidos'])

            {{-- LA BARRA DE SELECCIÓN: aparece con filas tildadas y borra todas de una. --}}
            <div class="barra-seleccion" id="barra-seleccion" hidden>
                <div class="barra-seleccion-info">
                    <strong id="barra-conteo">0</strong> seleccionado(s)
                    <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">limpiar</button>
                </div>
                <div class="barra-seleccion-acciones">
                    <form action="{{ route('seguimiento.acciones') }}" method="POST" id="form-eliminar-masivo">
                        @csrf
                        <input type="hidden" name="accion" value="eliminar_masivo">
                        <div id="campos-eliminar-masivo"></div>
                        <button type="submit" class="btn btn-chico btn-peligro">
                            <i class="fa-solid fa-trash-can"></i> Eliminar seleccionados
                        </button>
                    </form>
                </div>
            </div>
        @endif

    </div>
@endsection

@section('modales')
    {{-- REGISTRAR UN PEDIDO A MANO --}}
    <div class="modal-fondo" id="modal-nuevo">
        <div class="modal-caja modal-con-panel">
            <div class="modal-cabecera">
                <h2><i class="fa-solid fa-plus"></i> Registrar pedido</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <form action="{{ route('seguimiento.acciones') }}" method="POST">
                @csrf
                <input type="hidden" name="accion" value="guardar_pedido">
                <div class="modal-panel-grid">
                    <aside class="modal-panel-lado">
                        <div class="panel-icono"><i class="fa-solid fa-truck-fast"></i></div>
                        <h3>Un pedido para seguir</h3>
                        <p>Cargá un despacho suelto para verlo en el tablero junto con los demás.</p>
                        <ul class="panel-tips">
                            <li><i class="fa-solid fa-check"></i> Con la <strong>transportadora</strong> y la factura o la guía alcanza.</li>
                            <li><i class="fa-solid fa-check"></i> Si repetís la misma factura/guía, se <strong>actualiza</strong>, no se duplica.</li>
                            <li><i class="fa-solid fa-check"></i> El estado pinta el color solo según lo que escribas.</li>
                        </ul>
                        <div class="panel-leyenda">
                            <span class="lg-verde">Entregado</span>
                            <span class="lg-azul">En camino</span>
                            <span class="lg-ambar">Pendiente / novedad</span>
                        </div>
                    </aside>

                    <div class="modal-panel-form">
                        <div class="rejilla-campos">
                            <div class="campo">
                                <label for="n-transportadora"><i class="fa-solid fa-truck"></i> Transportadora *</label>
                                <input type="text" id="n-transportadora" name="transportadora" list="lista-transportadoras" required
                                       placeholder="AGV, Proeslog, propia…">
                                <datalist id="lista-transportadoras">
                                    @foreach ($transportadoras as $t)<option value="{{ $t }}">@endforeach
                                </datalist>
                            </div>
                            <div class="campo">
                                <label for="n-estado"><i class="fa-solid fa-circle-half-stroke"></i> Estado</label>
                                <input type="text" id="n-estado" name="estado" list="lista-estados" placeholder="En tránsito, Entregado…">
                                <datalist id="lista-estados">
                                    @foreach ($estadosCargados as $e)<option value="{{ $e }}">@endforeach
                                </datalist>
                            </div>
                            <div class="campo">
                                <label for="n-factura"><i class="fa-solid fa-file-invoice"></i> Factura</label>
                                <input type="text" id="n-factura" name="numero_factura" placeholder="N° de factura">
                            </div>
                            <div class="campo">
                                <label for="n-guia"><i class="fa-solid fa-barcode"></i> Guía</label>
                                <input type="text" id="n-guia" name="numero_guia" placeholder="N° de guía / remesa">
                            </div>
                            <div class="campo campo-ancho">
                                <label for="n-destino"><i class="fa-solid fa-location-dot"></i> Destino</label>
                                <input type="text" id="n-destino" name="destino" placeholder="Cliente o punto de venta">
                            </div>
                            <div class="campo campo-ancho">
                                <label for="n-detalle"><i class="fa-solid fa-align-left"></i> Detalle</label>
                                <input type="text" id="n-detalle" name="detalle" placeholder="Descripción de la factura / del pedido">
                            </div>
                            <div class="campo">
                                <label for="n-bodega"><i class="fa-solid fa-warehouse"></i> Bodega</label>
                                <input type="text" id="n-bodega" name="bodega">
                            </div>
                            <div class="campo">{{-- vacío para alinear la reja en dos columnas --}}</div>
                            <div class="campo">
                                <label for="n-f-despacho"><i class="fa-solid fa-truck-ramp-box"></i> Fecha de despacho</label>
                                <input type="datetime-local" id="n-f-despacho" name="fecha_despacho">
                            </div>
                            <div class="campo">
                                <label for="n-f-entrega"><i class="fa-solid fa-flag-checkered"></i> Fecha de entrega</label>
                                <input type="datetime-local" id="n-f-entrega" name="fecha_entrega">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-pie">
                    <button type="button" class="btn" data-cerrar>Cancelar</button>
                    <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- IMPORTAR EL LISTADO DE UNA TRANSPORTADORA --}}
    <div class="modal-fondo" id="modal-importar">
        <div class="modal-caja modal-con-panel">
            <div class="modal-cabecera">
                <h2><i class="fa-solid fa-file-arrow-up"></i> Importar listado</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <form action="{{ route('seguimiento.acciones') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="accion" value="importar_excel">
                <div class="modal-panel-grid">
                    <aside class="modal-panel-lado">
                        <div class="panel-icono"><i class="fa-solid fa-file-excel"></i></div>
                        <h3>El Excel de la transportadora</h3>
                        <p>Subí el reporte que baja de cada portal. El sistema reconoce solo de qué transportadora es.</p>
                        <ul class="panel-tips">
                            <li><i class="fa-solid fa-check"></i> Identifica <strong>Proeslog</strong>, <strong>AGV</strong> y <strong>Vector Foods</strong> por sus columnas, sin elegir nada.</li>
                            <li><i class="fa-solid fa-check"></i> Reconoce factura, guía, estado, fechas, destino y detalle en cualquier orden.</li>
                            <li><i class="fa-solid fa-check"></i> También lee el <strong>Consolidado completo de facturación (SAP)</strong>: agrega sus pedidos como <strong>“Facturado”</strong> (lista aparte).</li>
                            <li><i class="fa-solid fa-check"></i> Subirlo otra vez <strong>actualiza</strong>, no duplica.</li>
                        </ul>
                    </aside>

                    <div class="modal-panel-form">
                        <div class="campo">
                            <label for="i-archivo"><i class="fa-solid fa-file-excel"></i> Excel de la transportadora (.xlsx)</label>
                            <input type="file" id="i-archivo" name="archivo" accept=".xlsx,.xls" required>
                            <span class="ayuda">Los archivos de Proeslog, AGV, Vector Foods y el Consolidado de facturación (SAP) se reconocen solos.</span>
                        </div>
                        <div class="campo" style="margin-top: 14px;">
                            <label for="i-transportadora"><i class="fa-solid fa-truck"></i> Transportadora</label>
                            <input type="text" id="i-transportadora" name="transportadora" list="lista-transportadoras"
                                   placeholder="Solo si no se reconoce sola…">
                            <span class="ayuda">Déjalo vacío para los formatos conocidos. Solo hace falta para un archivo que el sistema no reconozca.</span>
                        </div>
                    </div>
                </div>
                <div class="modal-pie">
                    <button type="button" class="btn" data-cerrar>Cancelar</button>
                    <button type="submit" class="btn btn-primario"><i class="fa-solid fa-file-arrow-up"></i> Importar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- DETALLE COMPLETO DE UN PEDIDO --}}
    <div class="modal-fondo" id="modal-detalle">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2><i class="fa-solid fa-eye"></i> Detalle del pedido</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <div class="modal-cuerpo">
                <div class="detalle-estado">
                    <span class="chip-estado" id="det-estado"></span>
                    <span class="detalle-fuente" id="det-fuente"></span>
                </div>

                <dl class="detalle-lista">
                    <div><dt><i class="fa-solid fa-truck"></i> Transportadora</dt><dd id="det-transportadora"></dd></div>
                    <div><dt><i class="fa-solid fa-file-invoice"></i> Factura</dt><dd id="det-factura"></dd></div>
                    <div><dt><i class="fa-solid fa-barcode"></i> Guía</dt><dd id="det-guia"></dd></div>
                    <div><dt><i class="fa-solid fa-location-dot"></i> Destino</dt><dd id="det-destino"></dd></div>
                    <div><dt><i class="fa-solid fa-map-location-dot"></i> Ubicación</dt><dd id="det-direccion"></dd></div>
                    <div><dt><i class="fa-solid fa-id-card"></i> Numero De Identificación</dt><dd id="det-nit"></dd></div>
                    <div><dt><i class="fa-solid fa-money-bill-wave"></i> Valor neto</dt><dd id="det-valor"></dd></div>
                    <div><dt><i class="fa-solid fa-hashtag"></i> Referencia</dt><dd id="det-referencia"></dd></div>
                    <div><dt><i class="fa-solid fa-clipboard-list"></i> Pedido</dt><dd id="det-pedido"></dd></div>
                    <div><dt><i class="fa-solid fa-warehouse"></i> Bodega</dt><dd id="det-bodega"></dd></div>
                    <div><dt><i class="fa-solid fa-file-invoice-dollar"></i> Fecha de la guía</dt><dd id="det-fecha-guia"></dd></div>
                    <div><dt><i class="fa-solid fa-truck-ramp-box"></i> Despacho</dt><dd id="det-despacho"></dd></div>
                    <div><dt><i class="fa-solid fa-flag-checkered"></i> Entrega</dt><dd id="det-entrega"></dd></div>
                </dl>

                <div class="detalle-bloque">
                    <div class="detalle-bloque-titulo"><i class="fa-solid fa-align-left"></i> Detalle</div>
                    <div class="detalle-texto" id="det-detalle"></div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn btn-primario" data-cerrar>Cerrar</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ assetV('assets/js/modales.js') }}"></script>
    <script src="{{ assetV('modulos/seguimiento/scripts_seguimiento.js') }}"></script>
@endpush
