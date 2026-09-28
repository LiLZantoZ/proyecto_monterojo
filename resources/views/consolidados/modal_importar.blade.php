{{-- Un modal para importar el Consolidado. Se usa dos veces (Éxito / otros clientes): cambia solo el
     título, el ícono y el texto de ayuda. 'canal' viaja escondido pero el controlador no lo usa: la
     clasificación real la hace la empresa compradora de cada línea. --}}
<div class="modal-fondo" id="{{ $id }}">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid {{ $icono }}"></i> {{ $titulo }}</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="{{ route('consolidados.acciones') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="accion" value="importar_consolidado">
            <input type="hidden" name="canal" value="{{ $canal }}">

            <div class="modal-cuerpo">
                <div class="campo">
                    <label>Archivo de Excel (.xlsx)</label>
                    <input type="file" name="archivo" accept=".xlsx,.xls" required>
                    <span class="ayuda">{{ $ayuda }}</span>
                </div>

                <div class="aviso aviso-info" style="margin-bottom: 0;">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        El formato se reconoce solo y <strong>cada línea se clasifica por su empresa
                        compradora</strong>: las del Éxito usan el empaque/cubicaje de las
                        <strong>excepciones de Éxito</strong> (si hay), y las demás usan el maestro base.
                        El archivo se <strong>agrega</strong> a lo pendiente, no lo reemplaza.
                    </div>
                </div>
            </div>

            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario">Importar</button>
            </div>
        </form>
    </div>
</div>
