{{-- El modal de rótulos de Picking y del Historial: los rótulos de un producto (o de la entrega
     entera) con sus campos EDITABLES antes de imprimir. Mismo HTML y mismos ids en las dos
     pantallas; la maqueta de cada rótulo la arma el script de la pantalla.

     Por qué editables: los datos salen del archivo de la cadena y del maestro, y no siempre están
     completos ni son los definitivos (el nombre de la tienda puede venir cortado, el picker puede
     necesitar más cajas...). Lo que se cambia acá vale SOLO para estos rótulos: no toca nada de lo
     guardado.

     $conPdf: Picking lleva además "Descargar PDF" (respaldo de la etiquetadora); el Historial no. --}}
<div class="modal-fondo" id="modal-rotulo">
    <div class="modal-caja modal-caja-ancha">

        <div class="modal-cabecera">
            <h2>Rótulos <span id="rotulo-titulo-detalle" style="font-weight: 400; opacity: 0.75;"></span></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>

        <div class="modal-cuerpo">

            {{-- Los controles. Cambiar cualquiera vuelve a dibujar TODOS los rótulos. --}}
            <div class="rotulo-controles">
                {{-- "CAJ {desde} DE {total}", y así {cantidad} etiquetas seguidas: el botón de un
                     producto reimprime un TRAMO del pedido, no una serie que arranque en 1. --}}
                <div class="campo">
                    <label for="rot-cantidad">Cuántos rótulos</label>
                    <input type="number" id="rot-cantidad" min="1" max="99" step="1" value="1"
                           data-rotulo-campo>
                </div>
                <div class="campo">
                    <label for="rot-desde">Desde la caja Nº</label>
                    <input type="number" id="rot-desde" min="1" max="999" step="1" value="1"
                           data-rotulo-campo>
                </div>
                <div class="campo">
                    <label for="rot-total">Cajas del pedido</label>
                    <input type="number" id="rot-total" min="1" max="999" step="1" value="1"
                           data-rotulo-campo>
                </div>
                <div class="campo campo-ancho">
                    <label for="rot-pv">Punto de venta</label>
                    <input type="text" id="rot-pv" maxlength="180" data-rotulo-campo>
                </div>
                {{-- El número de la tienda (reemplazó a la orden de compra en el rótulo). --}}
                <div class="campo">
                    <label for="rot-numero-pv">N° punto de venta</label>
                    <input type="text" id="rot-numero-pv" maxlength="20" data-rotulo-campo>
                </div>
                <div class="campo campo-ancho">
                    <label for="rot-cedi">CEDI</label>
                    <input type="text" id="rot-cedi" maxlength="120" data-rotulo-campo>
                </div>
                {{-- Al imprimir el pedido entero cada caja lleva su producto: este campo arranca vacío
                     y escribir algo lo fuerza en TODAS. --}}
                <div class="campo campo-ancho">
                    <label for="rot-producto">Producto</label>
                    <input type="text" id="rot-producto" maxlength="255"
                           placeholder="Cada caja lleva el suyo" data-rotulo-campo>
                </div>
            </div>

            <p class="rotulo-nota">
                <i class="fa-solid fa-circle-info"></i>
                Lo que se cambie acá vale <strong>solo para estos rótulos</strong>: no toca el
                Consolidado ni nada de lo que hay guardado.
            </p>

            <div class="aviso aviso-info" id="rotulo-aviso-saldos" hidden>
                <i class="fa-solid fa-circle-info"></i>
                <div id="rotulo-aviso-saldos-texto"></div>
            </div>

            {{-- Cómo salió el envío a la etiquetadora, al lado de lo que se mandó a imprimir. --}}
            <div class="aviso" id="rotulo-aviso-impresion" hidden>
                <i class="fa-solid fa-circle-info"></i>
                <div id="rotulo-aviso-impresion-texto"></div>
            </div>

            <div class="rotulos-previa" id="rotulos-previa"></div>
        </div>

        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cerrar</button>

            @if (!empty($conPdf))
                {{-- La descarga va por formulario: el navegador la trata como descarga normal. El
                     campo oculto lo llena el script con la MISMA lista de la vista previa. Es el
                     respaldo de la etiquetadora (página de 100x40mm, a tamaño real). --}}
                <form action="{{ route('picking.acciones') }}" method="POST" id="form-rotulos-pdf">
                    @csrf
                    <input type="hidden" name="accion" value="rotulos_pdf">
                    <input type="hidden" name="rotulos" id="rotulos-pdf-datos">
                    <button type="submit" class="btn">
                        <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                    </button>
                </form>
            @endif

            {{-- El sistema le habla directo a la etiquetadora en su propio idioma (TSPL). --}}
            <button type="button" class="btn btn-primario" id="btn-imprimir-etiquetadora">
                <i class="fa-solid fa-tags"></i> Imprimir en la etiquetadora
            </button>
        </div>

    </div>
</div>
