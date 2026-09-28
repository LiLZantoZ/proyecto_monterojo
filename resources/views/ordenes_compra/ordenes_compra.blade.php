{{-- Las órdenes de compra del Éxito: la planilla para armar el transporte a los CEDI.
     Las columnas son las de la planilla que el usuario armaba a mano: orden, cajas, unidades,
     estibas, peso, m³, valor y carro, más el CEDI. Cada fila se despliega para ver los productos. --}}
@extends('layouts.app')

@section('titulo', 'Órdenes de compra')

@php
    // Hasta 7 decimales y sin ceros de más, como en la planilla del usuario: 0,0288 · 0,8936165.
    $m3    = fn ($n) => rtrim(rtrim(number_format((float) $n, 7, ',', '.'), '0'), ',') ?: '0';
    $plata = fn ($n) => '$ ' . number_format((float) $n, 2, ',', '.');
    $falta = fn ($texto) => new \Illuminate\Support\HtmlString('<span class="faltante">' . e($texto) . '</span>');

    // La etiqueta del carro de una orden.
    $etiquetaCarro = function (array $o) {
        if ($o['carro'] === null) {
            return new \Illuminate\Support\HtmlString($o['carro_excede']
                ? '<span class="etiqueta-estado etiqueta-excede" title="Pesa o abulta más que el vehículo más grande: hay que partirla en varios viajes">No entra en un vehículo</span>'
                : '<span class="etiqueta-estado etiqueta-sin-definir" title="No hay vehículos en uso: activá alguno en Ajustes, abajo">Sin vehículos</span>');
        }
        $html = '<span class="etiqueta-estado etiqueta-carro">' . e($o['carro']) . '</span>';
        if ($o['carro_incompleto']) {
            // Le faltan productos al cálculo: su peso y su volumen reales son mayores.
            $html .= ' <i class="fa-solid fa-triangle-exclamation icono-faltante"
                          title="A esta orden le faltan cubicajes o datos del maestro: su peso y su volumen reales son mayores y el vehículo podría quedar chico."></i>';
        }
        return new \Illuminate\Support\HtmlString($html);
    };
@endphp

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Órdenes de compra (Éxito)</h2>
            <div class="modulo-acciones">
                @if (!empty($ordenes))
                    <a class="btn" href="{{ route('ordenes_compra.acciones', array_filter(['accion' => 'pdf', 'oc' => $filtros['oc']])) }}">
                        <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                    </a>
                @endif
            </div>
        </header>

        <div class="aviso aviso-info">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                Acá salen <strong>solo las órdenes del Éxito pendientes de despacho</strong>, las
                que van a un CEDI. Las cajas cuentan también la caja incompleta de cada producto,
                igual que los rótulos. El <strong>peso</strong> es cajas × {{ ORDENES_KG_POR_CAJA }} kg
                y el <strong>valor</strong>, unidades × precio bruto. El <strong>carro</strong> es el
                vehículo seco más chico en el que entran el peso y el volumen de la orden.
            </div>
        </div>

        @if (!empty($datos['sin_cubicaje']))
            <div class="aviso aviso-atencion">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>{{ fmtMil(count($datos['sin_cubicaje'])) }} producto(s) no tienen cubicaje</strong>
                    y su volumen <strong>no se suma</strong> a los m³:
                    {!! implode(', ', array_map(fn ($sku, $d) => '<strong>' . e($sku) . '</strong> ' . e($d),
                                               array_keys($datos['sin_cubicaje']), $datos['sin_cubicaje'])) !!}.
                    Agregalos al archivo de cubicajes y volvé a subirlo en <em>Ajustes</em>, abajo.
                </div>
            </div>
        @endif

        @if ($t['sin_maestro'] > 0)
            <div class="aviso aviso-atencion">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>{{ fmtMil($t['sin_maestro']) }} línea(s)</strong> son de productos que no están
                    en el maestro o no tienen unidades por caja: <strong>no suman cajas, peso ni volumen</strong>.
                    Cargalos en <a href="{{ route('maestro') }}">Maestro de productos</a>.
                    Desplegá las órdenes marcadas para ver cuáles son.
                </div>
            </div>
        @endif

        @if ($t['sin_precio'] > 0)
            <div class="aviso aviso-atencion">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>{{ fmtMil($t['sin_precio']) }} línea(s)</strong> no tienen precio y
                    <strong>no suman valor</strong>. Pasa con los consolidados importados antes de que el sistema
                    guardara el precio: se completan volviendo a importar el archivo del Éxito.
                </div>
            </div>
        @endif

        @if (!$datos['hay_flota'])
            <div class="aviso aviso-atencion">
                <i class="fa-solid fa-truck"></i>
                <div>
                    No hay ningún <strong>vehículo en uso</strong>, así que no se puede elegir el carro.
                    Activá los que correspondan en <em>Ajustes</em>, abajo.
                </div>
            </div>
        @endif

        <form method="GET" class="filtros">
            <div class="filtro">
                <label for="f-oc">Orden de compra</label>
                <input type="text" name="oc" id="f-oc" list="lista-ordenes" autocomplete="off"
                       value="{{ $filtros['oc'] }}" placeholder="Número o parte del número">
                <datalist id="lista-ordenes">
                    @foreach ($disponibles as $oc)
                        <option value="{{ $oc }}"></option>
                    @endforeach
                </datalist>
            </div>
            <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
            @if ($filtros['oc'] !== '')
                <a class="btn" href="{{ route('ordenes_compra') }}">Limpiar</a>
            @endif
        </form>

        @if (empty($ordenes))
            <div class="tabla-caja">
                <p class="tabla-vacia">
                    {{ $filtros['oc'] !== ''
                        ? 'Ninguna orden pendiente coincide con "' . $filtros['oc'] . '".'
                        : 'No hay órdenes del Éxito pendientes de despacho.' }}
                </p>
            </div>
        @else
            <div class="pastillas">
                <span class="pastilla"><strong>{{ fmtMil($t['ordenes']) }}</strong> órdenes</span>
                <span class="pastilla"><strong>{{ fmtMil($t['cajas']) }}</strong> cajas</span>
                <span class="pastilla"><strong>{{ fmtMil($t['peso_kg']) }}</strong> kg</span>
                <span class="pastilla"><strong>{{ $m3($t['m3']) }}</strong> m³</span>
                <span class="pastilla"><strong>{{ $plata($t['valor']) }}</strong></span>
            </div>

            {{-- EL CARRO PARA LLEVARLO TODO JUNTO: el de cada fila dice en qué mandar ESA orden; éste,
                 en qué sale el camión con el pedido entero (o con lo filtrado). --}}
            @if (!empty($t['carro']))
                <div class="aviso {{ $t['carro_viajes'] > 1 ? 'aviso-atencion' : 'aviso-info' }}">
                    <i class="fa-solid fa-truck"></i>
                    <div>
                        @if ($t['carro_viajes'] > 1)
                            Todo junto son <strong>{{ fmtMil($t['peso_kg']) }} kg</strong> y
                            <strong>{{ $m3($t['m3']) }} m³</strong>, y
                            <strong>no entra en un solo vehículo</strong>: harían falta
                            <strong>{{ fmtMil($t['carro_viajes']) }} viajes</strong> en
                            <strong>{{ $t['carro'] }}</strong>, el más grande de la flota.
                            La cuenta reparte peso y volumen sin tener en cuenta que una caja no se parte,
                            así que tomala como el mínimo.
                        @else
                            Para llevar <strong>todo junto</strong> —{{ fmtMil($t['cajas']) }} cajas,
                            <strong>{{ fmtMil($t['peso_kg']) }} kg</strong> y
                            <strong>{{ $m3($t['m3']) }} m³</strong>— alcanza con un
                            <strong>{{ $t['carro'] }}</strong>.
                        @endif

                        {{-- La capacidad del vehículo y cuánto se ocupa: dice si va holgado o al límite. --}}
                        @php $cap = $t['carro_capacidad'] ?? null; @endphp
                        @if ($cap)
                            <div class="carro-capacidad">
                                @foreach ([
                                    ['Peso',    fmtMil($t['peso_kg']) . ' de ' . fmtMil($cap['peso_kg']) . ' kg', $cap['uso_peso']],
                                    ['Volumen', $m3($t['m3']) . ' de ' . fmtMil($cap['m3']) . ' m³',              $cap['uso_volumen']],
                                ] as [$titulo, $texto, $uso])
                                    <div class="carro-medida">
                                        <div class="carro-medida-texto">
                                            <span>{{ $titulo }}</span>
                                            <strong>{{ $texto }}</strong>
                                            @if ($uso !== null)
                                                <span class="carro-porcentaje">{{ number_format($uso, 0, ',', '.') }}%</span>
                                            @endif
                                        </div>
                                        @if ($uso !== null)
                                            <div class="carro-barra">
                                                {{-- Se topa en 100 para que la barra no se salga con varios viajes. --}}
                                                <span style="width: {{ min(100, max(0, round($uso))) }}%"></span>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                                @if ($cap['estibas'] > 0)
                                    <div class="carro-medida">
                                        <div class="carro-medida-texto">
                                            <span>Estibas</span>
                                            <strong>hasta {{ fmtMil($cap['estibas']) }}</strong>
                                            <span class="carro-porcentaje">sin calcular</span>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                        @if (!empty($t['carro_incompleto']))
                            <br>Ojo: hay productos sin cubicaje o sin maestro, así que el peso y el
                            volumen reales son <strong>mayores</strong> que estos y el vehículo podría
                            quedar chico.
                        @endif
                    </div>
                </div>
            @endif

            <div class="tabla-caja">
                <table class="tabla tabla-ordenes">
                    <thead>
                        <tr>
                            <th style="width: 3%;"></th>
                            <th>Orden</th>
                            <th>CEDI</th>
                            <th class="num">Cajas</th>
                            <th class="num">Unidades</th>
                            <th class="num" title="Pendiente: todavía no hay regla para calcularlas">Estibas <span class="pendiente">pendiente</span></th>
                            <th class="num">Peso (kg)</th>
                            <th class="num">m³</th>
                            <th class="num">Valor</th>
                            <th class="centro">Carro</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ordenes as $i => $o)
                            @php
                                $faltantes = [];
                                if ($o['sin_maestro'])  { $faltantes[] = $o['sin_maestro'] . ' sin maestro'; }
                                if ($o['sin_cubicaje']) { $faltantes[] = $o['sin_cubicaje'] . ' sin cubicaje'; }
                                if ($o['sin_precio'])   { $faltantes[] = $o['sin_precio'] . ' sin precio'; }
                            @endphp
                            <tr>
                                <td>
                                    <button type="button" class="btn-detalle-oc" aria-expanded="false"
                                            aria-controls="detalle-oc-{{ $i }}" title="Ver los productos de la orden">
                                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                    </button>
                                </td>
                                <td>
                                    <strong>{{ $o['orden'] }}</strong>
                                    @if ($faltantes)
                                        <i class="fa-solid fa-triangle-exclamation icono-faltante"
                                           title="{{ implode(' · ', $faltantes) . ' (línea/s). Desplegá para ver cuáles.' }}"></i>
                                    @endif
                                    <div class="dato-secundario">carga {{ (int) $o['id_carga'] }}</div>
                                </td>
                                <td>{{ $o['cedi'] }}</td>
                                <td class="num"><strong>{{ fmtMil($o['cajas']) }}</strong></td>
                                <td class="num">{{ fmtMil($o['unidades']) }}</td>
                                <td class="num">{{ fmtMil($o['estibas']) }}</td>
                                <td class="num">{{ fmtMil($o['peso_kg']) }}</td>
                                <td class="num">{{ $m3($o['m3']) }}</td>
                                <td class="num">{{ $plata($o['valor']) }}</td>
                                <td class="centro">{{ $etiquetaCarro($o) }}</td>
                            </tr>
                            <tr class="fila-detalle" id="detalle-oc-{{ $i }}" hidden>
                                <td colspan="10">
                                    <table class="tabla-detalle">
                                        <thead>
                                            <tr>
                                                <th>SKU</th>
                                                <th>Producto</th>
                                                <th class="num">Tiendas</th>
                                                <th class="num">Unidades</th>
                                                <th class="num">Und/caja</th>
                                                <th class="num">Cajas</th>
                                                <th class="num">Cubicaje caja</th>
                                                <th class="num">m³</th>
                                                <th class="num">Precio bruto</th>
                                                <th class="num">Valor</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($o['productos'] as $p)
                                                <tr>
                                                    <td>{{ $p['sku'] ?? '—' }}</td>
                                                    <td>{{ $p['producto'] }}</td>
                                                    <td class="num">{{ fmtMil($p['tiendas']) }}</td>
                                                    <td class="num">{{ fmtMil($p['unidades']) }}</td>
                                                    <td class="num">{{ $p['por_caja'] ? fmtMil($p['por_caja']) : $falta('sin maestro') }}</td>
                                                    <td class="num">{{ $p['cajas'] !== null ? fmtMil($p['cajas']) : $falta('—') }}</td>
                                                    <td class="num">{{ $p['cubicaje'] !== null ? number_format($p['cubicaje'], 7, ',', '.') : $falta('sin cubicaje') }}</td>
                                                    <td class="num">{{ $p['m3'] !== null ? $m3($p['m3']) : $falta('—') }}</td>
                                                    <td class="num">{{ $p['precio'] !== null ? $plata($p['precio']) : $falta('sin precio') }}</td>
                                                    <td class="num">{{ $p['valor'] !== null ? $plata($p['valor']) : $falta('—') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fila-total">
                            <td colspan="3">TOTAL · {{ fmtMil($t['ordenes']) }} órdenes</td>
                            <td class="num">{{ fmtMil($t['cajas']) }}</td>
                            <td class="num">{{ fmtMil($t['unidades']) }}</td>
                            <td class="num">{{ fmtMil($t['estibas']) }}</td>
                            <td class="num">{{ fmtMil($t['peso_kg']) }}</td>
                            <td class="num">{{ $m3($t['m3']) }}</td>
                            <td class="num">{{ $plata($t['valor']) }}</td>
                            {{-- El carro de la fila TOTAL es el que hay que pedir para llevar TODO junto. --}}
                            <td>
                                @if (!empty($t['carro']) && $t['carro_viajes'] <= 1)
                                    <span class="etiqueta-estado etiqueta-carro"
                                          title="El vehículo para llevar las {{ fmtMil($t['ordenes']) }} órdenes juntas: {{ fmtMil($t['peso_kg']) }} kg y {{ $m3($t['m3']) }} m³">
                                        {{ $t['carro'] }}
                                    </span>
                                @elseif (!empty($t['carro']))
                                    <span class="etiqueta-estado etiqueta-excede"
                                          title="Todo junto no entra en un solo vehículo: harían falta {{ fmtMil($t['carro_viajes']) }} viajes en {{ $t['carro'] }}">
                                        {{ fmtMil($t['carro_viajes']) }} × {{ $t['carro'] }}
                                    </span>
                                @endif
                                @if (!empty($t['carro']) && !empty($t['carro_incompleto']))
                                    <i class="fa-solid fa-triangle-exclamation icono-faltante"
                                       title="Faltan cubicajes o datos del maestro: el peso y el volumen reales son mayores y el vehículo podría quedar chico."></i>
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

        {{-- AJUSTES: los dos datos que este módulo necesita y que no vienen en el Consolidado. --}}
        <details class="grupo-desplegable ajustes-oc">
            <summary class="grupo-cabecera">
                <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>
                <div class="grupo-titulo">
                    <div class="grupo-nombre">Ajustes</div>
                    <div class="grupo-resumen">
                        {{ fmtMil($cubicajes['productos']) }} productos con cubicaje ·
                        {{ fmtMil(count(array_filter($flota, fn ($v) => $v['activo']))) }} vehículos en uso ·
                        estibas: pendiente
                    </div>
                </div>
            </summary>

            <div class="ajustes-oc-cuerpo">
                <form action="{{ route('ordenes_compra.acciones') }}" method="POST"
                      enctype="multipart/form-data" class="ajuste-oc">
                    @csrf
                    <input type="hidden" name="accion" value="subir_cubicajes">
                    <h3>Cubicajes por caja</h3>
                    <p class="ayuda">
                        Excel con las columnas <strong>SKU</strong> y <strong>CUBICAJE POR CAJA</strong> (y, si
                        las trae, EAN, DENOMINACIÓN y TIPO CAJA). Agrega los productos nuevos y corrige los que
                        ya estaban; no borra los que no vengan en el archivo. Se busca por SKU, no por EAN.
                    </p>
                    <div class="campo">
                        <input type="file" name="archivo" accept=".xlsx,.xls" required>
                    </div>
                    <button type="submit" class="btn btn-primario"><i class="fa-solid fa-upload"></i> Subir cubicajes</button>
                </form>

                <div class="ajuste-oc">
                    <h3>Estibas <span class="pendiente">pendiente</span></h3>
                    <p class="ayuda">
                        Por ahora van en 0. Falta la regla —por ejemplo, cuántos m³ entran en una estiba— y en
                        cuanto esté se calculan acá. Mientras tanto, las estibas de cada vehículo no se usan para
                        elegir el carro.
                    </p>
                </div>

                <form action="{{ route('ordenes_compra.acciones') }}" method="POST" class="ajuste-oc ajuste-vehiculos">
                    @csrf
                    <input type="hidden" name="accion" value="guardar_vehiculos">
                    <h3>Vehículos</h3>
                    <p class="ayuda">
                        Para cada orden se elige el vehículo <strong>en uso</strong> de menor capacidad en el que entran
                        su peso y su volumen. Solo vehículos secos: los refrigerados no aplican. Hay dos
                        <strong>TURBO 6 SECA</strong>, uno de 2.800 kg y otro de 7.500 kg, y se puede elegir cualquiera
                        de los dos según el peso de la orden.
                    </p>
                    <table class="tabla-detalle tabla-vehiculos">
                        <thead>
                            <tr>
                                <th>Vehículo</th>
                                <th class="num">Peso (kg)</th>
                                <th class="num">Estibas</th>
                                <th class="num">m³</th>
                                <th class="centro">En uso</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($flota as $v)
                                @php $id = (int) $v['id_vehiculo']; @endphp
                                <tr @if (!$v['activo']) class="fila-inactiva" @endif>
                                    <td>{{ $v['nombre'] }}</td>
                                    <td class="num"><input type="text" inputmode="numeric" name="vehiculos[{{ $id }}][peso_kg]"
                                               value="{{ (int) $v['peso_kg'] }}" aria-label="Peso de {{ $v['nombre'] }}"></td>
                                    <td class="num"><input type="text" inputmode="numeric" name="vehiculos[{{ $id }}][estibas]"
                                               value="{{ (int) $v['estibas'] }}" aria-label="Estibas de {{ $v['nombre'] }}"></td>
                                    <td class="num"><input type="text" inputmode="decimal" name="vehiculos[{{ $id }}][m3]"
                                               value="{{ rtrim(rtrim(number_format((float) $v['m3'], 2, ',', ''), '0'), ',') ?: '0' }}"
                                               aria-label="Metros cúbicos de {{ $v['nombre'] }}"></td>
                                    <td class="centro"><input type="checkbox" name="vehiculos[{{ $id }}][activo]" value="1"
                                               @checked($v['activo']) aria-label="{{ $v['nombre'] }} en uso"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> Guardar vehículos</button>
                </form>

            </div>
        </details>

    </div>
@endsection

@push('scripts')
    <script src="{{ assetV('assets/js/desplegables.js') }}"></script>
    <script src="{{ assetV('modulos/ordenes_compra/scripts_ordenes_compra.js') }}"></script>
@endpush
