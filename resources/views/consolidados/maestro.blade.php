{{-- El maestro de productos: SKU, EAN, PLU, descripción, empaque, unidades por caja y peso.

     Existe porque el Consolidado que manda la cadena trae el PLU y el EAN, pero no la descripción
     ni las unidades por caja: sin ese dato no se puede convertir a cajas. Tiene dos pestañas: el
     maestro base (todos los clientes) y las excepciones propias del Éxito. --}}
@extends('layouts.app')

@section('titulo', 'Maestro de productos')

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Maestro de productos</h2>
            <div class="modulo-acciones">
                <a class="btn" href="{{ route('consolidados') }}">
                    <i class="fa-solid fa-arrow-left"></i> Volver a Consolidados
                </a>
                @if ($verExito)
                    <button type="button" class="btn btn-primario" data-abrir="modal-exito">
                        <i class="fa-solid fa-file-arrow-up"></i> Cargar excepciones de Éxito
                    </button>
                @else
                    <button type="button" class="btn" data-abrir="modal-maestro">
                        <i class="fa-solid fa-table-list"></i> Cargar planilla
                    </button>
                    <button type="button" class="btn btn-primario" data-abrir="modal-sap">
                        <i class="fa-solid fa-file-arrow-up"></i> Cargar desde SAP
                    </button>
                @endif
            </div>
        </header>

        <div class="pestanas-maestro">
            <a class="pestana-maestro{{ !$verExito ? ' activa' : '' }}" href="{{ route('maestro') }}">
                <i class="fa-solid fa-boxes-stacked"></i> Maestro base <span class="pestana-nota">(otros clientes)</span>
            </a>
            <a class="pestana-maestro{{ $verExito ? ' activa' : '' }}" href="{{ route('maestro', ['ver' => 'exito']) }}">
                <i class="fa-solid fa-store"></i> Excepciones de Éxito <span class="pestana-conteo">{{ (int) $resumenExito['total'] }}</span>
            </a>
        </div>

        @if ($verExito)
            <div class="pastillas">
                <span class="pastilla">Excepciones <strong>{{ fmtMil((int) $resumenExito['total']) }}</strong></span>
                <span class="pastilla">Con empaque propio <strong>{{ fmtMil((int) $resumenExito['con_unidades']) }}</strong></span>
                <span class="pastilla">Con cubicaje propio <strong>{{ fmtMil((int) $resumenExito['con_cubicaje']) }}</strong></span>
            </div>

            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Solo van acá los productos cuyo <strong>empaque</strong> (unidades por caja) o
                    <strong>cubicaje</strong> cambian para el <strong>Éxito</strong>. El resto usa el
                    maestro base. Cada línea de un pedido del Éxito toma la excepción si existe para su
                    SKU; los pedidos de otros clientes usan siempre el base.
                </div>
            </div>

            <div class="filtros">
                <form class="filtro" method="GET" style="flex: 1;">
                    <input type="hidden" name="ver" value="exito">
                    <label for="q">Buscar por SKU o descripción</label>
                    <input type="text" name="q" id="q" value="{{ $busqueda }}"
                           placeholder="Ej. 36373 o «lima limón»">
                </form>
            </div>

            <div class="tabla-caja">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Descripción</th>
                            <th class="num">Uds. por caja (Éxito)</th>
                            <th class="num">Cubicaje m³ (Éxito)</th>
                            <th class="centro">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($excepciones as $e)
                            @php
                                $desc  = $e['descripcion'] ?? $e['descripcion_base'];
                                $uBase = $e['unidades_base'] !== null ? (int) $e['unidades_base'] : null;
                                $cBase = $e['cubicaje_base'] !== null ? (float) $e['cubicaje_base'] : null;
                            @endphp
                            <tr>
                                <td><strong>{{ $e['sku'] }}</strong></td>
                                <td>{{ $desc !== null ? $desc : oGuion(null, 'sin-dato') }}</td>
                                <td class="num">
                                    @if ($e['unidades_por_caja'] !== null)
                                        <strong>{{ (int) $e['unidades_por_caja'] }}</strong>
                                        @if ($uBase !== null && $uBase !== (int) $e['unidades_por_caja'])
                                            <div class="sub-dato">base: {{ $uBase }}</div>
                                        @endif
                                    @else
                                        <span class="sin-dato">usa base{{ $uBase !== null ? ' (' . $uBase . ')' : '' }}</span>
                                    @endif
                                </td>
                                <td class="num">
                                    @if ($e['cubicaje_m3'] !== null)
                                        <strong>{{ number_format((float) $e['cubicaje_m3'], 5, ',', '.') }}</strong>
                                        @if ($cBase !== null && abs($cBase - (float) $e['cubicaje_m3']) > 0.0000001)
                                            <div class="sub-dato">base: {{ number_format($cBase, 5, ',', '.') }}</div>
                                        @endif
                                    @else
                                        <span class="sin-dato">usa base{{ $cBase !== null ? ' (' . number_format($cBase, 5, ',', '.') . ')' : '' }}</span>
                                    @endif
                                </td>
                                <td class="centro">
                                    <form action="{{ route('consolidados.acciones') }}" method="POST"
                                          onsubmit="return confirm(@js('¿Quitar la excepción de Éxito del SKU ' . $e['sku'] . '?'));" style="display:inline;">
                                        @csrf
                                        <input type="hidden" name="accion" value="eliminar_excepcion_exito">
                                        <input type="hidden" name="sku" value="{{ $e['sku'] }}">
                                        <button type="submit" class="btn btn-chico" title="Quitar excepción">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><p class="tabla-vacia">
                                {{ $busqueda !== ''
                                    ? 'Ninguna excepción coincide con «' . $busqueda . '».'
                                    : 'Todavía no hay excepciones de Éxito. Cargá el Excel con «Cargar excepciones de Éxito».' }}
                            </p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @else

            <div class="pastillas">
                <span class="pastilla">Productos <strong>{{ fmtMil($totalMaestro) }}</strong></span>
                <span class="pastilla">Con unidades por caja <strong>{{ fmtMil($conUnidades) }}</strong></span>
                <span class="pastilla">Cruzados con la cadena <strong>{{ fmtMil($conPlu) }}</strong></span>
                @if ($faltantes > 0)
                    <span class="pastilla pastilla-alerta">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Faltan para el Consolidado <strong>{{ $faltantes }}</strong>
                    </span>
                @endif
            </div>

            @if ($totalMaestro === 0)
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong>El maestro está vacío.</strong>
                        Hasta que se cargue, el Consolidado y Picking muestran las unidades pero no las
                        cajas ni los saldos, porque no hay con qué convertirlas.
                        Empezá por <strong>Cargar desde SAP</strong>.
                    </div>
                </div>
            @endif

            <div class="filtros">
                @if ($faltantes > 0 || $soloFaltantes)
                    @if ($soloFaltantes)
                        <a class="btn" href="{{ route('maestro') }}">
                            <i class="fa-solid fa-list"></i> Ver todo el maestro
                        </a>
                    @else
                        <a class="btn btn-acento" href="{{ route('maestro', ['ver' => 'faltantes']) }}">
                            <i class="fa-solid fa-triangle-exclamation"></i> Ver solo los que faltan ({{ $faltantes }})
                        </a>
                    @endif
                @endif

                @if (!$soloFaltantes)
                    <form class="filtro" method="GET" style="flex: 1;">
                        <label for="q">Buscar por SKU, PLU, EAN o descripción</label>
                        <input type="text" name="q" id="q" value="{{ $busqueda }}"
                               placeholder="Ej. 36373, 3577953 o «lima limón»">
                    </form>
                @endif
            </div>

            @if ($soloFaltantes)
                <div class="aviso aviso-info">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Estos son los productos del Consolidado cargado que todavía no se pueden convertir
                        a cajas, ordenados por cuántas unidades se pidieron. Si el <strong>SKU</strong> sale
                        vacío es que ese producto no vino en el export de SAP; si sale con SKU pero sin
                        unidades por caja, es que su nombre no traía el empaque (<code>PX20</code>,
                        <code>BX6x16</code>) y hay que completarlo con una planilla.
                    </div>
                </div>
            @endif

            <div class="tabla-caja">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>EAN</th>
                            <th>PLU</th>
                            <th>Descripción</th>
                            <th class="centro">Empaque</th>
                            <th class="num">Uds. por caja</th>
                            <th class="num">Peso unidad</th>
                            <th>Línea</th>
                            @if ($soloFaltantes)
                                <th class="num">Unidades pedidas</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($productos as $p)
                            @php $falta = empty($p['unidades_por_caja']); @endphp
                            <tr class="{{ $falta ? 'fila-sin-maestro' : '' }}">
                                <td>{{ $p['sku'] !== null ? $p['sku'] : oGuion(null, 'sin-dato') }}</td>
                                <td>{{ $p['ean'] !== null ? $p['ean'] : oGuion(null, 'sin-dato') }}</td>
                                <td>{{ $p['plu'] !== null ? $p['plu'] : oGuion(null, 'sin-dato') }}</td>
                                <td>{{ $p['descripcion'] !== null ? $p['descripcion'] : oGuion(null, 'sin-dato') }}</td>
                                <td class="centro">{{ $p['presentacion'] !== null ? $p['presentacion'] : oGuion(null, 'sin-dato') }}</td>
                                <td class="num">
                                    @if ($falta)
                                        <span class="sin-dato">falta</span>
                                    @else
                                        <strong>{{ (int) $p['unidades_por_caja'] }}</strong>
                                    @endif
                                </td>
                                <td class="num">
                                    {{ $p['peso_unidad_kg'] !== null
                                        ? number_format((float) $p['peso_unidad_kg'], 3, ',', '.') . ' kg'
                                        : oGuion(null, 'sin-dato') }}
                                </td>
                                <td>{{ $p['linea'] !== null ? $p['linea'] : oGuion(null, 'sin-dato') }}</td>
                                @if ($soloFaltantes)
                                    <td class="num">{{ fmtMil((int) $p['unidades_pedidas']) }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $soloFaltantes ? 9 : 8 }}">
                                    <p class="tabla-vacia">
                                        @if ($soloFaltantes)
                                            No falta ninguno: el maestro cubre todo el Consolidado cargado.
                                        @elseif ($busqueda !== '')
                                            Ningún producto coincide con «{{ $busqueda }}».
                                        @else
                                            El maestro todavía está vacío. Usá <strong>Cargar desde SAP</strong>.
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @endif

    </div>
@endsection

@section('modales')
    {{-- MODAL: CARGAR EXCEPCIONES DE ÉXITO (SKU cuyo empaque o cubicaje cambian para el Éxito) --}}
    <div class="modal-fondo" id="modal-exito">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2>Cargar excepciones de Éxito</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <form action="{{ route('consolidados.acciones') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="accion" value="importar_maestro_exito">

                <div class="modal-cuerpo">
                    <div class="campo">
                        <label for="archivo-exito">Excel de excepciones (.xlsx)</label>
                        <input type="file" id="archivo-exito" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">
                            Columnas: <strong>SKU</strong> (obligatoria) y al menos una de
                            <strong>Unidades por caja</strong> y <strong>Cubicaje m³</strong>. Poné solo los
                            productos cuyo empaque o cubicaje cambian para el Éxito.
                        </span>
                    </div>

                    <div class="aviso aviso-info" style="margin-bottom: 0;">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>
                            Cada fila <strong>pisa</strong> el valor base solo para el Éxito y solo en la columna
                            que traiga: si dejás vacío el cubicaje, se sigue usando el del maestro base.
                            No se reemplaza nada: se agrega y se actualiza.
                        </div>
                    </div>
                </div>

                <div class="modal-pie">
                    <button type="button" class="btn" data-cerrar>Cancelar</button>
                    <button type="submit" class="btn btn-primario">Cargar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODAL: CARGAR DESDE SAP --}}
    <div class="modal-fondo" id="modal-sap">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2>Cargar maestro desde SAP</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <form action="{{ route('consolidados.acciones') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="accion" value="importar_maestro_sap">

                <div class="modal-cuerpo">
                    <div class="campo">
                        <label for="archivo-sap">Export de facturación de SAP (.xlsx)</label>
                        <input type="file" id="archivo-sap" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">
                            Es el archivo con una fila por línea facturada. Se leen las columnas
                            <strong>Material</strong>, <strong>Texto breve de material</strong>,
                            <strong>Código EAN/UPC</strong>, <strong>Ctd.facturada</strong> y
                            <strong>Peso bruto</strong>, buscadas por su nombre.
                        </span>
                    </div>

                    <div class="aviso aviso-info" style="margin-bottom: 0;">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>
                            Las <strong>unidades por caja</strong> salen del final del nombre del material
                            (<code>PX20</code> = 20 por caja, <code>BX6x16</code> = 16). El
                            <strong>EAN</strong> es lo que permite reconocer el producto en el Consolidado
                            de la cadena, que no trae el SKU.
                            <br>El maestro no se reemplaza: se agrega y se actualiza.
                        </div>
                    </div>
                </div>

                <div class="modal-pie">
                    <button type="button" class="btn" data-cerrar>Cancelar</button>
                    <button type="submit" class="btn btn-primario">Cargar</button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODAL: CARGAR UNA PLANILLA ARMADA A MANO --}}
    <div class="modal-fondo" id="modal-maestro">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2>Cargar planilla</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <form action="{{ route('consolidados.acciones') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="accion" value="importar_maestro">

                <div class="modal-cuerpo">
                    <div class="campo">
                        <label for="archivo-maestro">Archivo de Excel (.xlsx)</label>
                        <input type="file" id="archivo-maestro" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">
                            Para completar lo que SAP no da: la <strong>línea</strong> de cada producto, o
                            las <strong>unidades por caja</strong> de los materiales cuyo nombre no traía el
                            empaque. Columnas reconocidas por su nombre, en cualquier orden:
                            <strong>SKU</strong> · <strong>PLU</strong> · <strong>Descripción</strong>
                            · <strong>Unidades por caja</strong> · <strong>Línea</strong> · <strong>EAN</strong>.
                        </span>
                    </div>

                    <div class="aviso aviso-info" style="margin-bottom: 0;">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>
                            Una fila con <strong>SKU</strong> crea el producto o lo actualiza. Una fila con
                            <strong>solo PLU</strong> actualiza un producto que ya esté en el maestro: sin SKU
                            no se puede crear, porque es la clave y no se puede inventar.
                        </div>
                    </div>
                </div>

                <div class="modal-pie">
                    <button type="button" class="btn" data-cerrar>Cancelar</button>
                    <button type="submit" class="btn btn-primario">Cargar</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ assetV('assets/js/modales.js') }}"></script>
@endpush
