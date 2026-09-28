{{-- El modal de rótulos. Es el mismo recuadro y los mismos botones en Picking, el Historial, Cajas y
     "Generar rótulos": quien imprime ve siempre la misma pantalla, venga de donde venga.
     $accionPdf: la dirección a la que va el formulario "Descargar PDF". --}}
<div class="modal-fondo" id="modal-rotulo">
    <div class="modal-caja modal-caja-ancha">

        <div class="modal-cabecera">
            <h2>Rótulos <span id="rotulo-titulo-detalle" style="font-weight: 400; opacity: 0.75;"></span></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>

        <div class="modal-cuerpo">
            {{-- Cómo salió el envío a la etiquetadora. Va acá adentro y no en un cartel flotante
                 porque quien imprime está mirando la vista previa. --}}
            <div class="aviso" id="rotulo-aviso-impresion" hidden>
                <i class="fa-solid fa-circle-info"></i>
                <div id="rotulo-aviso-impresion-texto"></div>
            </div>

            <div class="rotulos-previa" id="rotulos-previa"></div>
        </div>

        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cerrar</button>

            <form action="{{ $accionPdf }}" method="POST" id="form-rotulos-pdf">
                @csrf
                <input type="hidden" name="accion" value="{{ $valorAccionPdf ?? 'rotulos_pdf' }}">
                <input type="hidden" name="rotulos" id="rotulos-pdf-datos">
                <button type="submit" class="btn">
                    <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                </button>
            </form>

            <button type="button" class="btn btn-primario" id="btn-imprimir-etiquetadora">
                <i class="fa-solid fa-tags"></i> Imprimir en la etiquetadora
            </button>
        </div>

    </div>
</div>
