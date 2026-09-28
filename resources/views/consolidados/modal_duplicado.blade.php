{{-- AVISO: EL ARCHIVO YA ESTABA CARGADO.
     Se abre solo (la clase `active` viene puesta) y NO se puede cerrar con la X, con Escape ni
     tocando el fondo: hay un archivo esperando en el servidor y una de las dos respuestas tiene que
     llegar. Por eso tampoco lleva `data-cerrar`.

     Dos motivos para preguntar lo mismo: el archivo es IDÉNTICO a uno ya cargado (carga_previa), o
     TRAE ÓRDENES que ya están pendientes aunque el archivo sea otro (ordenes_repetidas). --}}
@php $repetidas = $pendiente['ordenes_repetidas'] ?? null; @endphp
<div class="modal-fondo active" id="modal-duplicado" data-obligatorio>
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>
                <i class="fa-solid fa-triangle-exclamation"></i>
                {{ $repetidas ? 'Estos pedidos ya están cargados' : 'Este archivo ya fue subido' }}
            </h2>
        </div>

        <div class="modal-cuerpo">
            @if ($repetidas)
                <p class="confirmar-pregunta">
                    <strong>{{ $pendiente['nombre'] }}</strong>
                    trae <strong>{{ (int) $repetidas['ordenes'] }}</strong> orden(es) de compra, y
                    <strong>{{ count($repetidas['repetidas']) }}</strong> de ellas ya están cargadas y
                    sin despachar.
                </p>

                <div class="tabla-caja" style="margin-bottom: 16px; max-height: 240px; overflow-y: auto;">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Orden de compra</th>
                                <th>Ya cargada en</th>
                                <th>El</th>
                                <th style="text-align: right;">Líneas</th>
                                <th style="text-align: right;">Unidades</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($repetidas['repetidas'] as $r)
                                <tr>
                                    <td><strong>{{ $r['orden_compra'] }}</strong></td>
                                    <td>{{ $r['nombre_archivo'] }}</td>
                                    <td>{{ date('d/m/Y H:i', strtotime($r['fecha_carga'])) }}</td>
                                    <td style="text-align: right;">{{ fmtMil((int) $r['lineas']) }}</td>
                                    <td style="text-align: right;">{{ fmtMil((int) $r['unidades']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="confirmar-pregunta">
                    <strong>{{ $pendiente['nombre'] }}</strong>
                    tiene exactamente la misma información que el Consolidado que ya está cargado.
                </p>

                <div class="pastillas" style="margin-bottom: 16px;">
                    <span class="pastilla">
                        Cargado como <strong>{{ $pendiente['carga_previa']['nombre_archivo'] }}</strong>
                    </span>
                    <span class="pastilla">
                        El <strong>{{ date('d/m/Y \a \l\a\s H:i', strtotime($pendiente['carga_previa']['fecha_carga'])) }}</strong>
                    </span>
                    @if (!empty($pendiente['carga_previa']['nombre_usuario']))
                        <span class="pastilla">
                            Por <strong>{{ $pendiente['carga_previa']['nombre_usuario'] }}</strong>
                        </span>
                    @endif
                    <span class="pastilla">
                        <strong>{{ fmtMil((int) $pendiente['carga_previa']['filas']) }}</strong> líneas
                    </span>
                </div>
            @endif

            <div class="aviso aviso-atencion" style="margin-bottom: 0;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    @if ($repetidas)
                        Como los archivos ya no se reemplazan, subirlo <strong>agrega una segunda copia</strong>
                        de esos pedidos: van a aparecer duplicados en Picking y en Consolidados, cada uno
                        pidiendo el doble de lo que corresponde.
                        @if (count($repetidas['repetidas']) < (int) $repetidas['ordenes'])
                            Las demás órdenes del archivo sí son nuevas, pero subirlo las carga a todas
                            juntas: no se puede importar solo una parte.
                        @endif
                    @else
                        Como los archivos ya no se reemplazan, volver a importarlo <strong>agrega una
                        segunda copia</strong> de estos mismos pedidos: van a aparecer duplicados en
                        Picking y en Consolidados, cada uno pidiendo el doble de lo que corresponde.
                    @endif
                </div>
            </div>
        </div>

        <div class="modal-pie">
            {{-- Dos formularios y no uno con dos submit: cada botón manda su propia respuesta. --}}
            <form action="{{ route('consolidados.acciones') }}" method="POST">
                @csrf
                <input type="hidden" name="accion" value="resolver_duplicado">
                <input type="hidden" name="respuesta" value="no">
                <button type="submit" class="btn">No</button>
            </form>
            <form action="{{ route('consolidados.acciones') }}" method="POST">
                @csrf
                <input type="hidden" name="accion" value="resolver_duplicado">
                <input type="hidden" name="respuesta" value="si">
                <button type="submit" class="btn btn-acento">Sí, subirlo igual</button>
            </form>
        </div>
    </div>
</div>
