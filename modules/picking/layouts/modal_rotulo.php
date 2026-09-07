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

            <div class="rotulos-previa" id="rotulos-previa"></div>
        </div>

        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cerrar</button>
            <button type="button" class="btn btn-primario" id="btn-imprimir-rotulos">
                <i class="fa-solid fa-print"></i> Imprimir
            </button>
        </div>

    </div>
</div>

<!-- Adonde se mueven los rótulos para imprimir. Tiene que ser hijo DIRECTO de <body>: al imprimir
     se oculta todo lo demás, y un elemento con un ancestro en display:none no se puede volver a
     mostrar desde el descendiente. Ver el @media print de assets/css/partes/04-rotulo.css. -->
<div id="area-impresion-rotulos" hidden></div>
