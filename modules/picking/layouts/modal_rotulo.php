<!-- modules/picking/layouts/modal_rotulo.php
     Los rótulos de un producto de la entrega, con sus campos EDITABLES antes de imprimir.

     Por qué editables: los datos salen del archivo de la cadena y del maestro, y no siempre están
     completos ni son los definitivos. El pedido SAP puede no haberse digitado todavía, el picker
     puede necesitar más cajas de las que calcula el sistema (un producto que va suelto y se
     empaca aparte), o el nombre de la tienda puede venir cortado del archivo. Antes eso obligaba
     a imprimir el rótulo mal y corregirlo con lapicero.

     La maqueta de cada rótulo la arma scripts_picking.js: la cantidad se sabe recién al abrir, y
     cambia cada vez que se toca el campo "Cajas". -->
<div class="modal-fondo" id="modal-rotulo">
    <div class="modal-caja modal-caja-ancha">

        <div class="modal-cabecera">
            <h2>Rótulos <span id="rotulo-titulo-detalle" style="font-weight: 400; opacity: 0.75;"></span></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>

        <div class="modal-cuerpo">

            <!-- Los controles. Cambiar cualquiera vuelve a dibujar TODOS los rótulos: se edita una
                 vez y no una por caja. -->
            <div class="rotulo-controles">
                <!-- Los tres números que definen la numeración: "CAJ {desde} DE {total}", y así
                     {cantidad} etiquetas seguidas. Van separados porque el botón de un producto
                     reimprime un TRAMO del pedido —las cajas 3 y 4 de 8—, no una serie que
                     arranque en 1. -->
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
                <div class="campo">
                    <label for="rot-oc">Orden de compra</label>
                    <input type="text" id="rot-oc" maxlength="40" data-rotulo-campo>
                </div>
                <div class="campo campo-ancho">
                    <label for="rot-cedi">CEDI</label>
                    <input type="text" id="rot-cedi" maxlength="120" data-rotulo-campo>
                </div>
                <!-- Al imprimir el pedido entero cada caja lleva un producto distinto, así que
                     este campo arranca vacío y cada etiqueta toma el suyo. Escribir algo acá lo
                     fuerza en TODAS: sirve para corregir un nombre o para un rótulo suelto. -->
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

            <!-- Cómo salió el envío a la etiquetadora. Va acá adentro y no en un cartel flotante
                 porque quien imprime está mirando la vista previa: el resultado tiene que
                 aparecer al lado de lo que mandó a imprimir. -->
            <div class="aviso" id="rotulo-aviso-impresion" hidden>
                <i class="fa-solid fa-circle-info"></i>
                <div id="rotulo-aviso-impresion-texto"></div>
            </div>

            <div class="rotulos-previa" id="rotulos-previa"></div>
        </div>

        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cerrar</button>

            <!-- La descarga va por un formulario y no por fetch: así el navegador la trata como
                 una descarga normal. El campo oculto lo llena scripts_picking.js justo antes de
                 enviarlo, con la MISMA lista de rótulos que acaba de dibujar en la vista previa.

                 Es el respaldo de la etiquetadora: sirve para guardar los rótulos o sacarlos en
                 una impresora común si la TSC no está. El PDF ya trae la página de 100x40mm
                 adentro, así que se abre y se imprime a tamaño real. -->
            <form action="<?php echo BASE_URL; ?>/modules/picking/controller_picking.php"
                  method="POST" id="form-rotulos-pdf">
                <?php campoCSRF(); ?>
                <input type="hidden" name="accion" value="rotulos_pdf">
                <input type="hidden" name="rotulos" id="rotulos-pdf-datos">
                <button type="submit" class="btn">
                    <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                </button>
            </form>

            <!-- El sistema le habla directo a la etiquetadora en su propio idioma (TSPL): la
                 etiqueta sale sola, sin diálogo de impresión y sin nada que configurar en el
                 equipo. Ver modules/historial/helper_rotulos_tspl.php. -->
            <button type="button" class="btn btn-primario" id="btn-imprimir-etiquetadora">
                <i class="fa-solid fa-tags"></i> Imprimir en la etiquetadora
            </button>
        </div>

    </div>
</div>
