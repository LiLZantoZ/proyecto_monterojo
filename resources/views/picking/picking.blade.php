{{-- El alistamiento, agrupado por ENTREGA (CEDI + orden de compra + punto de venta) y las entregas
     agrupadas por CEDI, que es la zona de despacho. Cada fila es un pedido; se despliega para ver
     sus productos, cada uno con su botón de rótulo. --}}
@extends('layouts.app')

@section('titulo', 'Picking')

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Picking</h2>
            <div class="modulo-acciones">
                @if (tienePermiso('modulo_consolidados'))
                    <a class="btn" href="{{ route('consolidados') }}">
                        <i class="fa-solid fa-boxes-stacked"></i> Ir a Consolidados
                    </a>
                @endif
                @if (tienePermiso('modulo_personal'))
                    <a class="btn" href="{{ route('personal') }}">
                        <i class="fa-solid fa-users-gear"></i> Gestionar personal
                    </a>
                @endif
            </div>
        </header>

        @if ($hayPendientes)
            <div class="pastillas">
                <span class="pastilla">Entregas <strong>{{ fmtMil(count($entregas)) }}</strong></span>
                <span class="pastilla">Líneas <strong>{{ fmtMil($resumen['lineas']) }}</strong></span>
                <span class="pastilla">Unidades <strong>{{ fmtMil($resumen['unidades']) }}</strong></span>
                <span class="pastilla">Cajas <strong>{{ fmtMil($resumen['cajas']) }}</strong></span>
                <span class="pastilla">Saldos <strong>{{ fmtMil($resumen['saldos']) }}</strong></span>
                @if ($resumen['peso_kg'] > 0)
                    <span class="pastilla" title="Peso bruto de lo que hay que alistar">
                        Peso <strong>{{ fmtMil($resumen['peso_kg']) }} kg</strong>
                    </span>
                @endif
                @if ($resumen['sin_maestro'] > 0)
                    <span class="pastilla pastilla-alerta">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Sin maestro <strong>{{ $resumen['sin_maestro'] }}</strong>
                    </span>
                @endif
            </div>

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

                <div class="filtro">
                    <label for="f-pv">Punto de venta</label>
                    <select name="punto_venta" id="f-pv">
                        <option value="">Todos</option>
                        @foreach ($puntosDisponibles as $pv)
                            <option value="{{ $pv }}" @selected($filtros['punto_venta'] === $pv)>
                                {{ $pv }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="filtro" style="flex: 1;">
                    <label for="f-q">Buscar</label>
                    <input type="text" name="q" id="f-q" value="{{ $filtros['busqueda'] }}"
                           placeholder="PLU, SKU, descripción o punto de venta">
                </div>

                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                @if (array_filter($filtros))
                    <a class="btn" href="{{ route('picking') }}">Limpiar</a>
                @endif
            </form>
        @endif

        @if (!$hayPendientes)
            <div class="tabla-caja">
                <p class="tabla-vacia">
                    No hay ningún pedido pendiente.<br>
                    Picking se arma con esos archivos: súbelos primero en <strong>Consolidados</strong>.
                </p>
            </div>

        @elseif (empty($entregas))
            <div class="tabla-caja">
                <p class="tabla-vacia">Ninguna entrega coincide con el filtro.</p>
            </div>

        @else
            @foreach ($porCedi as $cedi => $grupo)
                @php $tc = $grupo['totales']; @endphp
                {{-- Un desplegable por CEDI (la zona de despacho), cerrado por omisión: la cabecera ya
                     dice el nombre y el conteo, y se abre la zona que se va a despachar. --}}
                <details class="grupo-desplegable">
                    <summary class="grupo-cabecera">
                        <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>

                        <div class="grupo-titulo">
                            <div class="grupo-nombre">
                                {{ $cedi }}
                                @if (!empty($cadenasDeCedi[$cedi]))
                                    <span class="cadena-etiqueta">{{ $cadenasDeCedi[$cedi] }}</span>
                                @endif
                                <span class="grupo-conteo">{{ $tc['pedidos'] }} pedidos</span>
                            </div>
                            <div class="grupo-resumen">
                                {{ fmtMil($tc['lineas']) }} líneas ·
                                {{ fmtMil($tc['unidades']) }} unidades ·
                                {{ fmtMil($tc['cajas']) }} cajas ·
                                {{ fmtMil($tc['saldos']) }} saldos
                                @if ($tc['peso_kg'] > 0)
                                    · {{ fmtMil($tc['peso_kg']) }} kg
                                @endif
                                @if ($tc['sin_maestro'] > 0)
                                    · {{ $tc['sin_maestro'] }} sin maestro
                                @endif
                            </div>
                        </div>
                    </summary>

                    <div class="tabla-caja">
                        <table class="tabla tabla-pedidos tabla-accion-fija">
                            <thead>
                                <tr>
                                    {{-- Tilda o destilda todos los pedidos de ESTE CEDI: se trabaja un CEDI a la vez. --}}
                                    <th style="width: 34px;" class="centro">
                                        <input type="checkbox" class="chk-todos"
                                               title="Seleccionar todos los pedidos de este CEDI"
                                               aria-label="Seleccionar todos los pedidos de este CEDI">
                                    </th>
                                    <th style="width: 34px;"><span class="sr-solo">Detalle</span></th>
                                    <th>Punto de venta</th>
                                    <th>O/C</th>
                                    {{-- De qué archivo vino cada pedido (los archivos se acumulan). --}}
                                    <th>Fecha</th>
                                    <th class="num">Productos</th>
                                    <th class="num">Unidades</th>
                                    <th class="num">Cajas</th>
                                    <th class="num">Saldos</th>
                                    <th class="num">Peso</th>
                                    <th class="centro">Asignar personal</th>
                                    <th class="centro">Hoja</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($grupo['entregas'] as $entrega)
                                    @php
                                        $t     = $entrega['totales'];
                                        $clave = $entrega['clave'];

                                        // Qué producto va en cada caja del pedido, en el orden en que se
                                        // numeran: al imprimir el pedido entero cada etiqueta lleva el suyo.
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
                                    <tr class="fila-pedido{{ $t['sin_maestro'] > 0 ? ' fila-sin-maestro' : '' }}"
                                        data-entrega="{{ $clave }}">
                                        <td class="centro">
                                            {{-- Los datos de la entrega van en la casilla: la barra de
                                                 acciones identifica cada pedido tildado con ellos. --}}
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
                                        <td>{{ $entrega['orden_compra'] }}</td>
                                        <td>
                                            @if ($entrega['fecha_carga'])
                                                <span title="{{ $entrega['nombre_archivo'] ?? '' }}">
                                                    {{ date('d/m', strtotime($entrega['fecha_carga'])) }}
                                                </span>
                                            @else
                                                <span class="sin-dato">—</span>
                                            @endif
                                        </td>
                                        <td class="num">{{ $t['lineas'] }}</td>
                                        <td class="num">{{ fmtMil($t['unidades']) }}</td>
                                        <td class="num"><strong>{{ fmtMil($t['cajas']) }}</strong></td>
                                        <td class="num">
                                            @if ($t['saldos'] > 0)
                                                {{ fmtMil($t['saldos']) }}
                                            @else
                                                <span class="sin-dato">0</span>
                                            @endif
                                        </td>
                                        <td class="num">
                                            {{ $t['peso_kg'] > 0 ? number_format($t['peso_kg'], 1, ',', '.') . ' kg' : oGuion(null, 'sin-dato') }}
                                        </td>
                                        <td class="centro">
                                            {{-- La asignación es de la ENTREGA: quien alista arma la tienda completa. --}}
                                            <button type="button"
                                                    class="btn btn-chico btn-asignar{{ empty($entrega['id_personal']) ? ' btn-sin-asignar' : '' }}"
                                                    data-entrega="{{ $clave }}"
                                                    data-carga="{{ (int) $entrega['id_carga'] }}"
                                                    data-cedi="{{ $entrega['cedi'] }}"
                                                    data-oc="{{ $entrega['orden_compra'] }}"
                                                    data-pv="{{ $entrega['punto_venta'] }}"
                                                    data-id-personal="{{ (int) ($entrega['id_personal'] ?? 0) }}">
                                                <i class="fa-solid {{ empty($entrega['id_personal']) ? 'fa-user-plus' : 'fa-user-check' }}"></i>
                                                <span class="texto-asignado">
                                                    {{ !empty($entrega['personal_nombre']) ? $entrega['personal_nombre'] : 'Sin asignar' }}
                                                </span>
                                            </button>
                                        </td>
                                        <td class="centro">
                                            <div class="acciones-pedido">
                                                {{-- Los rótulos de TODO el pedido, numerados corrido (CAJ 1 DE 8 … CAJ 8 DE 8). --}}
                                                <button type="button" class="btn btn-chico btn-rotulo"
                                                        title="{{ $totalRotulos > 0
                                                            ? 'Genera los ' . $totalRotulos . ' rótulos del pedido, numerados 1 de ' . $totalRotulos . ' en adelante.'
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
                                                   href="{{ route('picking.acciones', ['accion' => 'pdf', 'carga' => (int) $entrega['id_carga'], 'cedi' => $entrega['cedi'], 'oc' => $entrega['orden_compra'], 'pv' => $entrega['punto_venta']]) }}">
                                                    <i class="fa-solid fa-print"></i> Imprimir
                                                </a>

                                                {{-- Sin data-id-personal propio: el script mira el botón
                                                     "Asignar personal" de esta fila, la única fuente de verdad. --}}
                                                <button type="button" class="btn btn-chico btn-despachar"
                                                        title="Marca este pedido como despachado: desaparece de Picking y de Consolidados."
                                                        data-entrega="{{ $clave }}"
                                                        data-carga="{{ (int) $entrega['id_carga'] }}"
                                                        data-cedi="{{ $entrega['cedi'] }}"
                                                        data-oc="{{ $entrega['orden_compra'] }}"
                                                        data-pv="{{ $entrega['punto_venta'] }}">
                                                    <i class="fa-solid fa-truck-fast"></i> Despachar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    {{-- El detalle del pedido: sus productos, cada uno con su botón de rótulo. --}}
                                    <tr class="fila-detalle" hidden data-entrega="{{ $clave }}">
                                        <td colspan="12">
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
                                                            $cajas = $f['sin_maestro'] ? null : (int) $f['cajas'];
                                                            // De qué caja a qué caja del PEDIDO son los rótulos de este
                                                            // producto: la numeración es corrida sobre el pedido entero.
                                                            // Son cajas FÍSICAS (cajas_rotulo), no cajas completas.
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
                                                                {{-- NUNCA se deshabilita, aunque no se hayan podido calcular las
                                                                     cajas: el rótulo es editable. data-cajas va en 0 en ese caso. --}}
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
        @endif

    </div>
@endsection

@section('modales')
    {{-- BARRA DE ACCIONES MASIVAS: pegada abajo cuando hay al menos un pedido tildado (con decenas
         de entregas hay que scrollear, y arriba obligaría a volver hasta el principio). --}}
    <div class="barra-seleccion" id="barra-seleccion" hidden>
        <div class="barra-seleccion-info">
            <strong id="barra-conteo">0</strong> pedido(s) seleccionado(s)
            <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
        </div>

        <div class="barra-seleccion-acciones">
            @if (tienePermiso('modulo_personal'))
                <button type="button" class="btn btn-chico" id="btn-asignar-masivo">
                    <i class="fa-solid fa-user-plus"></i> Asignar personal
                </button>
            @endif

            <button type="button" class="btn btn-chico" id="btn-rotulos-masivo">
                <i class="fa-solid fa-tags"></i> Rótulos
            </button>

            {{-- Por formulario: el navegador la trata como descarga normal. Los campos ocultos los
                 rellena scripts_picking.js justo antes de enviarlo. --}}
            <form action="{{ route('picking.acciones') }}" method="POST" id="form-pdf-masivo">
                @csrf
                <input type="hidden" name="accion" value="pdf_masivo">
                <div id="campos-pdf-masivo"></div>
                <button type="submit" class="btn btn-chico btn-primario">
                    <i class="fa-solid fa-print"></i> Imprimir hojas
                </button>
            </form>

            <button type="button" class="btn btn-chico btn-despachar-masivo" id="btn-despachar-masivo">
                <i class="fa-solid fa-truck-fast"></i> Despachar seleccionados
            </button>

        </div>
    </div>

    @include('partes.modal_rotulo_editable', ['conPdf' => true])

    {{-- MODAL: ASIGNAR PERSONAL A UNA ENTREGA --}}
    <div class="modal-fondo" id="modal-asignar">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2>Asignar personal</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>

            <div class="modal-cuerpo">
                <p class="asignar-entrega" id="asignar-entrega"></p>

                @if (empty($personalActivo))
                    <div class="aviso aviso-atencion" style="margin-bottom: 0;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <strong>Todavía no hay personal cargado.</strong>
                            @if (tienePermiso('modulo_personal'))
                                Agregalo en <a href="{{ route('personal') }}">Gestionar personal</a>
                                y después vas a poder asignar entregas.
                            @endif
                        </div>
                    </div>
                @else
                    <div class="campo" style="margin-bottom: 0;">
                        <label for="asignar-persona">¿Quién alista este pedido?</label>
                        <select id="asignar-persona">
                            {{-- El vacío es "quitar la asignación", primero para no buscarlo entre cincuenta. --}}
                            <option value="">— Sin asignar —</option>
                            @foreach ($personalActivo as $p)
                                <option value="{{ (int) $p['id_personal'] }}">
                                    {{ $p['nombre'] }}{{ $p['cargo'] !== null ? ' · ' . $p['cargo'] : '' }}
                                    @if ((int) $p['entregas_asignadas'] > 0)
                                        ({{ (int) $p['entregas_asignadas'] }} asignadas)
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        <span class="ayuda">
                            El número entre paréntesis es cuántas entregas tiene ya asignadas, para poder
                            repartir la carga sin tener que ir a contarlas a otra pantalla.
                        </span>
                    </div>
                @endif
            </div>

            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                @if (!empty($personalActivo))
                    <button type="button" class="btn btn-primario" id="btn-guardar-asignacion">Guardar</button>
                @endif
            </div>
        </div>
    </div>

    {{-- MODAL: DESPACHAR (confirmación, o el aviso de que falta personal). El título, el cuerpo y
         los botones los arma scripts_picking.js según el pedido del clic. --}}
    <div class="modal-fondo" id="modal-despachar">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2 id="despachar-titulo">Despachar</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <div class="modal-cuerpo" id="despachar-cuerpo"></div>
            <div class="modal-pie" id="despachar-pie"></div>
        </div>
    </div>
@endsection

@push('scripts')
    @include('partes.constantes_js')
    <script src="{{ assetV('assets/js/desplegables.js') }}"></script>
    <script src="{{ assetV('assets/js/modales.js') }}"></script>
    <script src="{{ assetV('assets/js/rotulo.js') }}"></script>
    <script src="{{ assetV('modulos/picking/scripts_picking.js') }}"></script>
@endpush
