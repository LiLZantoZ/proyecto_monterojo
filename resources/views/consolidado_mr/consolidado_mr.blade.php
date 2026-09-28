{{-- El Consolidado MR de SAP renglón por renglón, con el Valor neto sumado por factura.
     Usa la hoja de estilos de Estado de pedidos (misma grilla, totales y paginación) para que las
     tablas de los dos módulos se vean iguales; lo propio va en consolidado_mr.css. --}}
@extends('layouts.app')

@section('titulo', 'Consolidado MR')

@push('estilos')
    <link rel="stylesheet" href="{{ assetV('modulos/seguimiento/seguimiento.css') }}">
    <link rel="stylesheet" href="{{ assetV('modulos/consolidado_mr/consolidado_mr.css') }}">
@endpush

@section('contenido')
    @php
        $fecha = fn ($v) => ($v && ($t = strtotime($v))) ? date('d/m/Y', $t) : oGuion(null);
    @endphp
    <div class="modulo">

        <header class="modulo-header">
            <h2>Consolidado MR</h2>
            <div class="modulo-acciones">
                {{-- Importar y vaciar solo con consolidado_mr_editar: el Visitante solo consulta. --}}
                @if ($puedeEditar && ($totalLineas > 0 || $filtrosEnUrl !== ''))
                    <form action="{{ route('consolidado_mr.acciones') }}" method="POST"
                          onsubmit="return confirm('¿Borrar TODO lo cargado en el Consolidado MR? Después se puede volver a subir el Excel.');">
                        @csrf
                        <input type="hidden" name="accion" value="vaciar">
                        <button type="submit" class="btn"><i class="fa-solid fa-trash-can"></i> Vaciar</button>
                    </form>
                @endif
                @if ($puedeEditar)
                    <button type="button" class="btn btn-primario" data-abrir="modal-importar">
                        <i class="fa-solid fa-file-arrow-up"></i> Importar Consolidado MR
                    </button>
                @endif
            </div>
        </header>

        @if ($hayDatos)
            <div class="pastillas">
                <span class="pastilla">Renglones <strong>{{ fmtMil($totales['lineas']) }}</strong></span>
                <span class="pastilla">Facturas <strong>{{ fmtMil($totales['facturas']) }}</strong></span>
                <span class="pastilla">Clientes <strong>{{ fmtMil($totales['clientes']) }}</strong></span>
                <span class="pastilla">Unidades <strong>{{ fmtCantidad($totales['cantidad']) }}</strong></span>
                <span class="pastilla">Valor neto <strong>{{ fmtPlata($totales['valor']) }}</strong></span>
            </div>

            <form method="GET" class="filtros">
                <div class="filtro">
                    <label for="f-cliente">Cliente</label>
                    <select name="cliente" id="f-cliente">
                        <option value="">Todos</option>
                        @foreach ($clientes as $c)
                            <option value="{{ $c }}" @selected($filtros['cliente'] === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-poblacion">Ciudad</label>
                    <select name="poblacion" id="f-poblacion">
                        <option value="">Todas</option>
                        @foreach ($poblaciones as $p)
                            <option value="{{ $p }}" @selected($filtros['poblacion'] === $p)>{{ $p }}</option>
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
                           placeholder="SKU, producto, referencia, pedido, orden de compra… Para varios: 36334, 36335"
                           title="Se puede buscar más de uno a la vez, separados por coma. Busca en factura, solicitante, cliente, ciudad, SKU, texto del material, referencia, pedido y orden de compra.">
                </div>
                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                @if ($filtrosEnUrl !== '')
                    <a class="btn" href="{{ route('consolidado_mr') }}">Limpiar</a>
                @endif
            </form>
        @endif

        @if (!$lineas)
            <div class="aviso aviso-atencion" style="margin-top: 14px;">
                <i class="fa-solid fa-inbox"></i>
                <div>
                    @if ($filtrosEnUrl !== '')
                        No hay renglones con esos filtros. Probá con otros o limpialos.
                    @elseif ($puedeEditar)
                        Todavía no hay nada cargado. Subí el Excel del <strong>Consolidado MR</strong> con “Importar Consolidado MR”.
                    @else
                        Todavía no hay nada cargado en el Consolidado MR.
                    @endif
                </div>
            </div>
        @else
            <div class="tabla-caja" style="margin-top: 14px;">
                <table class="tabla tabla-seguimiento tabla-sap tabla-mr">
                    <thead>
                        <tr>
                            <th class="num">Fecha factura</th>
                            <th>Solicitud</th>
                            <th>Cliente</th>
                            <th>Ciudad</th>
                            <th>SKU</th>
                            <th>Texto breve de material</th>
                            <th class="num">Cantidad Facturada</th>
                            <th class="num">Valor neto</th>
                            <th>Referencia</th>
                            <th>Pedido</th>
                            <th>Orden de compra</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lineas as $i => $l)
                            @php
                                $factura      = (string) $l['factura'];
                                $esUltima     = !isset($lineas[$i + 1]) || (string) $lineas[$i + 1]['factura'] !== $factura;
                                $sigueDespues = $esUltima && !isset($lineas[$i + 1]) && $facturaSiguiente === $factura;
                            @endphp
                            @if ($i === 0 && $facturaAnterior === $factura)
                                <tr class="fila-continua">
                                    <td colspan="11"><i class="fa-solid fa-arrow-turn-down"></i> Factura {{ $factura }} — viene de la página anterior</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="num">{{ $fecha($l['fecha_factura']) }}</td>
                                <td>{{ oGuion($l['solicitante']) }}</td>
                                <td class="celda-cliente">{{ oGuion($l['nombre_cliente']) }}</td>
                                <td>{{ oGuion($l['poblacion']) }}</td>
                                <td><strong>{{ oGuion($l['material']) }}</strong></td>
                                <td class="celda-producto">{{ oGuion($l['texto_material']) }}</td>
                                <td class="num celda-valor">{{ $l['cantidad_facturada'] !== null ? fmtCantidad($l['cantidad_facturada']) : oGuion(null) }}</td>
                                <td class="num celda-valor">{{ $l['valor_neto'] !== null ? fmtPlata($l['valor_neto']) : oGuion(null) }}</td>
                                <td>{{ oGuion($l['referencia']) }}</td>
                                <td>{{ oGuion($l['doc_ventas']) }}</td>
                                <td>{{ oGuion($l['pedido_cliente']) }}</td>
                            </tr>
                            @if ($sigueDespues)
                                <tr class="fila-continua">
                                    <td colspan="11"><i class="fa-solid fa-arrow-turn-down"></i> Factura {{ $factura }} — continúa en la página siguiente (el total va al final de la factura)</td>
                                </tr>
                            @elseif ($esUltima && isset($subtotales[$factura]))
                                @php
                                    $st = $subtotales[$factura];
                                    // Con un filtro que deja afuera renglones de la factura, se muestra además el total de la factura entera.
                                    $parcial = $hayFiltro && $st['lineas'] < $st['lineas_total'];
                                @endphp
                                <tr class="fila-subtotal">
                                    <td colspan="6" class="subtotal-etiqueta">
                                        Total factura <strong>{{ $factura }}</strong>
                                        <span class="subtotal-nota">
                                            ({{ fmtMil($st['lineas']) }} {{ $st['lineas'] === 1 ? 'renglón' : 'renglones' }}{{ $parcial ? ($st['lineas'] === 1 ? ' filtrado' : ' filtrados') . ' de ' . fmtMil($st['lineas_total']) : '' }})
                                        </span>
                                    </td>
                                    <td class="num subtotal-monto">{{ fmtCantidad($st['cantidad']) }}</td>
                                    <td class="num subtotal-monto">{{ fmtPlata($st['valor']) }}</td>
                                    <td colspan="3" class="subtotal-nota">
                                        @if ($parcial)
                                            Factura completa: <strong>{{ fmtPlata($st['valor_total']) }}</strong>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fila-total">
                            <td colspan="6" class="total-etiqueta">
                                Total
                                <span class="total-nota">({{ fmtMil($totalLineas) }} {{ $totalLineas === 1 ? 'renglón' : 'renglones' }}, todo el filtro)</span>
                            </td>
                            <td class="num total-monto">{{ fmtCantidad($totales['cantidad']) }}</td>
                            <td class="num total-monto">{{ fmtPlata($totales['valor']) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @include('partes.paginacion', ['total' => $totalLineas, 'etiqueta' => 'renglones'])
        @endif

    </div>
@endsection

@section('modales')
    @if ($puedeEditar)
    {{-- IMPORTAR EL CONSOLIDADO MR --}}
    <div class="modal-fondo" id="modal-importar">
        <div class="modal-caja modal-con-panel">
            <div class="modal-cabecera">
                <h2><i class="fa-solid fa-file-arrow-up"></i> Importar Consolidado MR</h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <form action="{{ route('consolidado_mr.acciones') }}" method="POST" enctype="multipart/form-data" id="form-importar-mr">
                @csrf
                <input type="hidden" name="accion" value="importar">
                <div class="modal-panel-grid">
                    <aside class="modal-panel-lado">
                        <div class="panel-icono"><i class="fa-solid fa-file-excel"></i></div>
                        <h3>El Consolidado MR de SAP</h3>
                        <p>Subí el Excel tal cual sale de SAP. Las columnas se reconocen por su nombre.</p>
                        <ul class="panel-tips">
                            <li><i class="fa-solid fa-check"></i> Carga un renglón por <strong>producto facturado</strong>.</li>
                            <li><i class="fa-solid fa-check"></i> Omite los renglones <strong>en cero</strong> que SAP agrega al partir por lote, y las facturas <strong>anuladas</strong>.</li>
                            <li><i class="fa-solid fa-check"></i> Subir otra vez la misma factura la <strong>reemplaza</strong>, no la duplica.</li>
                        </ul>
                    </aside>

                    <div class="modal-panel-form">
                        <div class="campo">
                            <label for="i-archivo"><i class="fa-solid fa-file-excel"></i> Excel del Consolidado MR (.xlsx)</label>
                            <input type="file" id="i-archivo" name="archivo" accept=".xlsx,.xls" required>
                            <span class="ayuda">Un archivo de ~19.000 filas tarda alrededor de medio minuto en cargar. No cierres la página mientras tanto.</span>
                        </div>
                    </div>
                </div>
                <div class="modal-pie">
                    <button type="button" class="btn" data-cerrar>Cancelar</button>
                    <button type="submit" class="btn btn-primario" id="btn-importar-mr"><i class="fa-solid fa-file-arrow-up"></i> Importar</button>
                </div>
            </form>
        </div>
    </div>
    @endif
@endsection

@push('scripts')
    <script src="{{ assetV('assets/js/modales.js') }}"></script>
    <script src="{{ assetV('modulos/consolidado_mr/scripts_consolidado_mr.js') }}"></script>
@endpush
