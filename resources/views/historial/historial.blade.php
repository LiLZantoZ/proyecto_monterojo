{{-- El historial de lo que ya se despachó, agrupado por CEDI igual que Picking —mismo agrupador,
     mismos rótulos y la misma hoja de alistamiento—, solo que mirando hacia atrás. Lo único que
     Picking no tiene es "Restaurar": deshace un despacho hecho por error. --}}
@extends('layouts.app')

@section('titulo', 'Historial de Pedidos')

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Historial de Pedidos</h2>
            <div class="modulo-acciones">
                @if ($resumen['pedidos'] > 0)
                    <a class="btn" href="{{ route('historial.acciones', ['accion' => 'pdf_reporte'] + $enUrl) }}">
                        <i class="fa-solid fa-file-pdf"></i> PDF de todos los pedidos
                    </a>
                    <a class="btn" href="{{ route('historial.acciones', ['accion' => 'rotulos_pdf'] + $enUrl) }}"
                       title="Descarga en un solo PDF los rótulos de todos los pedidos que se ven acá (uno por caja).">
                        <i class="fa-solid fa-tags"></i> Rótulos de todos los pedidos
                    </a>
                @endif
                @if (tienePermiso('modulo_picking'))
                    <a class="btn" href="{{ route('picking') }}">
                        <i class="fa-solid fa-cart-flatbed"></i> Ir a Picking
                    </a>
                @endif
            </div>
        </header>

        <div class="pastillas">
            <span class="pastilla">Pedidos despachados <strong>{{ fmtMil($resumen['pedidos']) }}</strong></span>
            <span class="pastilla">Cajas <strong>{{ fmtMil($resumen['cajas']) }}</strong></span>
            @if ($resumen['peso_kg'] > 0)
                <span class="pastilla" title="Peso bruto despachado, según el peso por unidad del maestro">
                    Peso <strong>{{ fmtMil($resumen['peso_kg']) }} kg</strong>
                </span>
            @endif
        </div>

        <form class="filtros" method="GET">
            <div class="filtro" style="flex: 1;">
                <label for="f-q">Buscar</label>
                <input type="text" name="q" id="f-q" value="{{ $filtros['busqueda'] }}"
                       placeholder="CEDI, punto de venta, O/C, alistador o quien despachó">
            </div>

            <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
            @if (array_filter($filtros))
                <a class="btn" href="{{ route('historial') }}">Limpiar</a>
            @endif
        </form>

        @if (empty($entregas))
            <div class="tabla-caja">
                <p class="tabla-vacia">
                    {{ $filtros['busqueda'] !== ''
                        ? 'Ningún pedido despachado coincide con la búsqueda.'
                        : 'Todavía no se ha despachado ningún pedido.' }}
                </p>
            </div>
        @else
            @foreach ($porCedi as $cedi => $grupo)
                @php $tc = $grupo['totales']; @endphp
                {{-- Un desplegable por CEDI, cerrado por omisión: la cabecera ya dice cuánto hay. --}}
                <details class="grupo-desplegable">
                    <summary class="grupo-cabecera">
                        <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>

                        <div class="grupo-titulo">
                            <div class="grupo-nombre">
                                {{ $cedi }}
                                <span class="grupo-conteo">{{ $tc['pedidos'] }} pedidos</span>
                            </div>
                            <div class="grupo-resumen">
                                {{ fmtMil($tc['lineas']) }} líneas ·
                                {{ fmtMil($tc['unidades']) }} unidades ·
                                {{ fmtMil($tc['cajas_rotulo']) }} cajas
                                @if ($tc['peso_kg'] > 0)
                                    · {{ fmtMil($tc['peso_kg']) }} kg
                                @endif
                            </div>
                        </div>
                    </summary>

                    <div class="tabla-caja">
                        <table class="tabla tabla-pedidos tabla-accion-fija">
                            <thead>
                                <tr>
                                    <th style="width: 34px;" class="centro">
                                        <input type="checkbox" class="chk-todos"
                                               title="Seleccionar todos los pedidos de este CEDI"
                                               aria-label="Seleccionar todos los pedidos de este CEDI">
                                    </th>
                                    <th style="width: 34px;"><span class="sr-solo">Detalle</span></th>
                                    <th>Punto de venta</th>
                                    <th>O/C</th>
                                    <th>Alistado por</th>
                                    <th>Despachado por</th>
                                    <th>Fecha</th>
                                    <th class="num">Cajas</th>
                                    <th class="num">Peso</th>
                                    <th class="centro">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($grupo['entregas'] as $entrega)
                                    @php
                                        $t     = $entrega['totales'];
                                        $clave = $entrega['clave'];

                                        // Los tramos de cajas de cada producto, para el rótulo del pedido entero.
                                        $segmentos = [];
                                        foreach ($entrega['lineas'] as $l) {
                                            if ((int) $l['cajas_rotulo'] < 1) { continue; }
                                            $segmentos[] = [
                                                'n'        => (int) $l['cajas_rotulo'],
                                                'producto' => $l['descripcion'] ?? ($l['sku'] ?? $l['plu']),
                                                // SKU y EAN viajan al QR.
                                                'sku'      => (string) ($l['sku'] ?? ''),
                                                'ean'      => (string) ($l['ean_item'] ?? ''),
                                            ];
                                        }
                                        $totalRotulos = (int) $t['cajas_rotulo'];
                                    @endphp
                                    <tr class="fila-pedido" data-entrega="{{ $clave }}">
                                        <td class="centro">
                                            <input type="checkbox" class="chk-pedido"
                                                   aria-label="Seleccionar {{ $entrega['punto_venta'] }}"
                                                   data-carga="{{ (int) $entrega['id_carga'] }}"
                                                   data-cedi="{{ $entrega['cedi'] }}"
                                                   data-oc="{{ $entrega['orden_compra'] }}"
                                                   data-pv="{{ $entrega['punto_venta'] }}">
                                        </td>
                                        <td class="centro">
                                            <button type="button" class="btn-detalle"
                                                    aria-expanded="false"
                                                    title="Ver los productos de este pedido">
                                                <i class="fa-solid fa-chevron-right"></i>
                                            </button>
                                        </td>
                                        <td>{{ $entrega['punto_venta'] }}</td>
                                        <td>{{ $entrega['orden_compra'] !== '' ? $entrega['orden_compra'] : oGuion(null, 'sin-dato') }}</td>
                                        <td>{{ $entrega['personal_nombre'] !== null ? $entrega['personal_nombre'] : oGuion(null, 'sin-dato') }}</td>
                                        <td>{{ $entrega['usuario_nombre'] !== null ? $entrega['usuario_nombre'] : oGuion(null, 'sin-dato') }}</td>
                                        <td>{{ $entrega['fecha_despacho'] ? date('d/m/Y H:i', strtotime($entrega['fecha_despacho'])) : oGuion(null, 'sin-dato') }}</td>
                                        <td class="num"><strong>{{ fmtMil($t['cajas_rotulo']) }}</strong></td>
                                        <td class="num">
                                            {{ $t['peso_kg'] > 0 ? number_format($t['peso_kg'], 1, ',', '.') . ' kg' : oGuion(null, 'sin-dato') }}
                                        </td>
                                        <td class="centro">
                                            <div class="acciones-pedido">
                                                <button type="button" class="btn btn-chico btn-rotulo"
                                                        title="{{ $totalRotulos > 0
                                                            ? 'Reimprime los ' . $totalRotulos . ' rótulos del pedido.'
                                                            : 'Este pedido no tiene unidades que rotular. Se puede abrir el rótulo igual y ajustarlo a mano.' }}"
                                                        data-entrega="{{ $clave }}"
                                                        data-pv="{{ $entrega['punto_venta'] }}"
                                                        data-numero-pv="{{ $entrega['numero_pv'] }}" data-ean-pv="{{ $entrega['ean_punto_venta'] ?? '' }}"
                                                        data-oc="{{ $entrega['orden_compra'] }}"
                                                        data-cedi="{{ $entrega['cedi'] }}"
                                                        data-desde="1"
                                                        data-cajas="{{ $totalRotulos }}"
                                                        data-total="{{ $totalRotulos }}"
                                                        data-segmentos="{{ json_encode($segmentos, JSON_UNESCAPED_UNICODE) }}">
                                                    <i class="fa-solid fa-tags"></i>
                                                    Rótulos{{ $totalRotulos > 0 ? ' (' . $totalRotulos . ')' : '' }}
                                                </button>

                                                <a class="btn btn-chico"
                                                   href="{{ route('historial.acciones', ['accion' => 'pdf', 'carga' => (int) $entrega['id_carga'], 'cedi' => $entrega['cedi'], 'oc' => $entrega['orden_compra'], 'pv' => $entrega['punto_venta']]) }}">
                                                    <i class="fa-solid fa-print"></i> Imprimir
                                                </a>

                                                <button type="button" class="btn btn-chico btn-restaurar"
                                                        title="Vuelve a dejar este pedido pendiente en Picking."
                                                        data-id-carga="{{ (int) $entrega['id_carga'] }}"
                                                        data-cedi="{{ $entrega['cedi'] }}"
                                                        data-oc="{{ $entrega['orden_compra'] }}"
                                                        data-pv="{{ $entrega['punto_venta'] }}">
                                                    <i class="fa-solid fa-rotate-left"></i> Restaurar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    {{-- El detalle del pedido: sus productos, con el rótulo de cada uno. --}}
                                    <tr class="fila-detalle" hidden data-entrega="{{ $clave }}">
                                        <td colspan="10">
                                            <table class="tabla tabla-productos">
                                                <thead>
                                                    <tr>
                                                        <th>SKU</th>
                                                        <th>Descripción</th>
                                                        <th class="centro">Empaque</th>
                                                        <th class="num">Unidades</th>
                                                        <th class="num">Cajas</th>
                                                        <th class="num">Saldos</th>
                                                        <th class="centro">Rótulo</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($entrega['lineas'] as $f)
                                                        @php
                                                            $cajas       = $f['sin_maestro'] ? null : (int) $f['cajas'];
                                                            $cajasRotulo = (int) $f['cajas_rotulo'];
                                                            $desde       = (int) $f['caja_desde'];
                                                            $hasta       = $desde + max($cajasRotulo, 1) - 1;
                                                            $producto    = $f['descripcion'] ?? ($f['sku'] ?? $f['plu']);
                                                        @endphp
                                                        <tr class="{{ $f['sin_maestro'] ? 'fila-sin-maestro' : '' }}">
                                                            <td>
                                                                @if ($f['sku'] !== null)
                                                                    {{ $f['sku'] }}
                                                                @else
                                                                    {{ $f['plu'] }} <span class="sin-dato">(PLU)</span>
                                                                @endif
                                                            </td>
                                                            <td>
                                                                @if ($f['descripcion'] !== null)
                                                                    {{ $f['descripcion'] }}
                                                                @else
                                                                    <span class="sin-dato">sin descripción en el maestro</span>
                                                                @endif
                                                            </td>
                                                            <td class="centro">{{ $f['presentacion'] !== null ? $f['presentacion'] : oGuion(null, 'sin-dato') }}</td>
                                                            <td class="num">{{ fmtMil((int) $f['unidades']) }}</td>
                                                            @if ($f['sin_maestro'])
                                                                <td class="num sin-dato" title="Falta cargar las unidades por caja de este PLU">—</td>
                                                                <td class="num sin-dato">—</td>
                                                            @else
                                                                <td class="num"><strong>{{ $cajas }}</strong></td>
                                                                <td class="num">
                                                                    @if ((int) $f['saldos'] > 0)
                                                                        {{ (int) $f['saldos'] }}
                                                                    @else
                                                                        <span class="sin-dato">0</span>
                                                                    @endif
                                                                </td>
                                                            @endif

                                                            <td class="centro">
                                                                <button type="button" class="btn btn-chico btn-rotulo"
                                                                        title="{{ $cajasRotulo > 0
                                                                            ? 'Reimprime las cajas ' . $desde . ' a ' . $hasta . ' de ' . (int) $f['cajas_pedido'] . ' del pedido.'
                                                                            : 'Esta línea no tiene unidades que rotular. Se puede abrir el rótulo igual y ajustarlo a mano.' }}"
                                                                        data-entrega="{{ $clave }}"
                                                                        data-pv="{{ $entrega['punto_venta'] }}"
                                                                        data-numero-pv="{{ $entrega['numero_pv'] }}" data-ean-pv="{{ $entrega['ean_punto_venta'] ?? '' }}"
                                                                        data-oc="{{ $entrega['orden_compra'] }}"
                                                                        data-cedi="{{ $entrega['cedi'] }}"
                                                                        data-desde="{{ $desde }}"
                                                                        data-cajas="{{ $cajasRotulo }}"
                                                                        data-total="{{ (int) $f['cajas_pedido'] }}"
                                                                        data-saldos="{{ (int) $f['saldos'] }}"
                                                                        data-producto="{{ $producto }}" data-sku="{{ (string) ($f['sku'] ?? '') }}" data-ean="{{ (string) ($f['ean_item'] ?? '') }}"
                                                                        data-descripcion="{{ $producto }}">
                                                                    <i class="fa-solid fa-tag"></i>
                                                                    Rótulo{{ $cajasRotulo > 0 ? ' (' . $cajasRotulo . ')' : '' }}
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endforeach

            @if ($resultado['paginas'] > 1)
                <div class="paginacion">
                    @if ($pagina > 1)
                        <a class="btn btn-chico" href="{{ route('historial', $enUrl + ['pagina' => $pagina - 1]) }}">
                            <i class="fa-solid fa-chevron-left"></i> Anterior
                        </a>
                    @endif
                    <span>Página {{ $pagina }} de {{ $resultado['paginas'] }}</span>
                    @if ($pagina < $resultado['paginas'])
                        <a class="btn btn-chico" href="{{ route('historial', $enUrl + ['pagina' => $pagina + 1]) }}">
                            Siguiente <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    @endif
                </div>
            @endif
        @endif

    </div>
@endsection

@section('modales')
    {{-- BARRA DE ACCIONES MASIVAS: aparece cuando hay al menos un pedido tildado. --}}
    <div class="barra-seleccion" id="barra-seleccion" hidden>
        <div class="barra-seleccion-info">
            <strong id="barra-conteo">0</strong> pedido(s) seleccionado(s)
            <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
        </div>

        <div class="barra-seleccion-acciones">
            <button type="button" class="btn btn-chico" id="btn-rotulos-masivo">
                <i class="fa-solid fa-tags"></i> Rótulos
            </button>

            <form action="{{ route('historial.acciones') }}" method="POST" id="form-pdf-masivo">
                @csrf
                <input type="hidden" name="accion" value="pdf_masivo">
                <div id="campos-pdf-masivo"></div>
                <button type="submit" class="btn btn-chico btn-primario">
                    <i class="fa-solid fa-print"></i> Imprimir hojas
                </button>
            </form>

            <button type="button" class="btn btn-chico" id="btn-restaurar-masivo">
                <i class="fa-solid fa-rotate-left"></i> Restaurar
            </button>
        </div>
    </div>

    @include('partes.modal_rotulo_editable', ['conPdf' => false])

    {{-- MODAL: RESTAURAR (confirmación con los estilos del sistema, no un confirm() nativo). --}}
    <div class="modal-fondo" id="modal-restaurar">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2>Restaurar pedido</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <div class="modal-cuerpo" id="restaurar-cuerpo"></div>
            <div class="modal-pie" id="restaurar-pie"></div>
        </div>
    </div>
@endsection

@push('scripts')
    @include('partes.constantes_js')
    <script src="{{ assetV('assets/js/desplegables.js') }}"></script>
    <script src="{{ assetV('assets/js/modales.js') }}"></script>
    <script src="{{ assetV('assets/js/rotulo.js') }}"></script>
    <script src="{{ assetV('modulos/historial/scripts_historial.js') }}"></script>
@endpush
