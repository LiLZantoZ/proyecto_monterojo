<!-- modules/historial/layouts/modal_rotulo.php
     Copia del de Picking (mismo HTML, mismos ids): reimprime los rótulos de un pedido YA
     despachado, para cuando se perdió la etiqueta original o hay que volver a mandarla.

     Los campos siguen siendo editables por la misma razón que en Picking: el rótulo no toca nada
     de lo guardado, así que corregir un nombre acá no reescribe el historial.

     La maqueta de cada rótulo la arma scripts_historial.js: la cantidad se sabe recién al abrir, y
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

            <!-- Cómo salió el envío a la etiquetadora. Va acá adentro y no en un cartel
                 flotante porque quien imprime está mirando la vista previa: el resultado
                 tiene que aparecer al lado de lo que mandó a imprimir. -->
            <div class="aviso" id="rotulo-aviso-impresion" hidden>
                <i class="fa-solid fa-circle-info"></i>
                <div id="rotulo-aviso-impresion-texto"></div>
            </div>

            <div class="rotulos-previa" id="rotulos-previa"></div>
        </div>

        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cerrar</button>

            <!-- Imprimir por el navegador queda como salida de emergencia, no como el camino
                 normal: es el que depende del diálogo de impresión (escala, márgenes, tamaño de
                 papel) y el que sacaba las etiquetas corridas o en blanco. Sirve si la
                 etiquetadora no está y hay que sacar el rótulo en una impresora común. -->
            <button type="button" class="btn" id="btn-imprimir-rotulos">
                <i class="fa-solid fa-print"></i> Imprimir por el navegador
            </button>

            <!-- El camino bueno: el sistema le habla directo a la etiquetadora en su propio
                 idioma (TSPL), sin PDF y sin diálogo del navegador en el medio. Ver
                 modules/historial/helper_rotulos_tspl.php. -->
            <button type="button" class="btn btn-primario" id="btn-imprimir-etiquetadora">
                <i class="fa-solid fa-tags"></i> Imprimir en la etiquetadora
            </button>
        </div>

    </div>
</div>

<!-- Adonde se mueven los rótulos para imprimir. Tiene que ser hijo DIRECTO de <body>: al imprimir
     se oculta todo lo demás, y un elemento con un ancestro en display:none no se puede volver a
     mostrar desde el descendiente. Ver el @media print de assets/css/partes/04-rotulo.css. -->
<div id="area-impresion-rotulos" hidden></div>
