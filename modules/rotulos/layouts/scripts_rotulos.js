// modules/rotulos/layouts/scripts_rotulos.js
// El generador manual: dibuja la vista previa apenas se toca cualquier campo, y arma la
// impresión y la descarga en PDF con lo que haya en pantalla en ese momento. Es el mismo cálculo
// que en Picking e Historial —el rótulo lo dibuja assets/js/rotulo.js—, adaptado a que acá
// no hay ninguna fila de la que salgan los datos: todo sale de estos mismos campos.
(function () {
    'use strict';

    // El rótulo se dibuja con assets/js/rotulo.js, el mismo archivo para las cuatro pantallas que
    // lo muestran. Hasta el 2026-09-14 había acá una copia propia de htmlRotulo() que quedó con el
    // diseño viejo cuando el rótulo cambió.

    // La lista de rótulos que hay dibujada en la vista previa en este momento: es lo que se
    // manda a la etiquetadora y al PDF, para que el papel sea exactamente lo que se ve.
    var rotulosActuales = [];

    var previa = document.getElementById('rotulos-previa');

    var campos = {
        cantidad: document.getElementById('rot-cantidad'),
        desde:    document.getElementById('rot-desde'),
        total:    document.getElementById('rot-total'),
        pv:       document.getElementById('rot-pv'),
        numeroPv: document.getElementById('rot-numero-pv'),
        cedi:     document.getElementById('rot-cedi'),
        producto: document.getElementById('rot-producto'),
        sku:      document.getElementById('rot-sku'),
        ean:      document.getElementById('rot-ean')
    };

    function entero(campo, porDefecto, minimo, maximo) {
        var valor = parseInt(campo.value, 10);
        if (!valor || valor < minimo) { valor = porDefecto; }
        if (valor > maximo) { valor = maximo; }
        return valor;
    }

    // espera: cuánto aguardar antes de pedir el QR. Acá todo se escribe a mano, así que por defecto
    // se espera a que se deje de tipear (ver dibujar() en assets/js/rotulo.js).
    function dibujarRotulos(espera) {
        var cantidad = entero(campos.cantidad, 1, 1, 99);
        var desde    = entero(campos.desde, 1, 1, 999);
        var total    = entero(campos.total, 1, 1, 999);

        var ultima = desde + cantidad - 1;
        if (total < ultima) { total = ultima; }

        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }

        for (var i = 0; i < cantidad; i++) {
            rotulosActuales.push({
                pv:        campos.pv.value,
                numero_pv: campos.numeroPv.value.trim(),
                cedi:      campos.cedi.value,
                producto:  campos.producto.value,
                sku:       campos.sku.value.trim(),
                ean:       campos.ean.value.trim(),
                numero:    desde + i,
                total:     total
            });
        }

        RotuloMonterojo.dibujar(previa, rotulosActuales, espera);
    }

    // Envuelto en una función a propósito: pasado directo, addEventListener le daría el evento
    // como primer argumento y dibujarRotulos() lo tomaría como la espera.
    Object.keys(campos).forEach(function (nombre) {
        campos[nombre].addEventListener('input', function () { dibujarRotulos(); });
    });

    document.getElementById('btn-limpiar-rotulos')?.addEventListener('click', function () {
        campos.cantidad.value = 1;
        campos.desde.value    = 1;
        campos.total.value    = 1;
        campos.pv.value       = '';
        campos.numeroPv.value = '';
        campos.cedi.value     = '';
        campos.producto.value = '';
        campos.sku.value      = '';
        campos.ean.value      = '';
        dibujarRotulos(0);
        campos.pv.focus();
    });

    // -----------------------------------------------------------------------
    // IMPRIMIR: mismo mecanismo que Picking y Historial (mover los rótulos al área de
    // impresión que vive fuera de .modulo, imprimir, y traerlos de vuelta).
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // IMPRIMIR EN LA ETIQUETADORA
    //
    // Manda la lista que está dibujada abajo y el servidor la traduce a TSPL, el idioma de la
    // TSC (ver modules/historial/helper_rotulos_tspl.php). Va por fetch y no por formulario para
    // no recargar la pantalla: acá los campos se llenan a mano y recargar los borraría.
    // -----------------------------------------------------------------------
    var avisoImpresion      = document.getElementById('rotulo-aviso-impresion');
    var avisoImpresionTexto = document.getElementById('rotulo-aviso-impresion-texto');
    var botonEtiquetadora   = document.getElementById('btn-imprimir-etiquetadora');

    function mostrarResultadoImpresion(texto, salioBien) {
        if (!avisoImpresion) { return; }
        avisoImpresionTexto.textContent = texto;
        avisoImpresion.className = 'aviso ' + (salioBien ? 'aviso-exito' : 'aviso-atencion');
        avisoImpresion.querySelector('i').className = salioBien
            ? 'fa-solid fa-circle-check'
            : 'fa-solid fa-triangle-exclamation';
        avisoImpresion.hidden = false;
    }

    if (botonEtiquetadora) {
        botonEtiquetadora.addEventListener('click', function () {
            if (!rotulosActuales.length) { return; }

            // Sin esto, dos clics mandan el lote dos veces — y acá eso son etiquetas de papel
            // gastadas, no una fila repetida que se pueda borrar.
            botonEtiquetadora.disabled = true;
            var textoOriginal = botonEtiquetadora.innerHTML;
            botonEtiquetadora.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
            if (avisoImpresion) { avisoImpresion.hidden = true; }

            fetch(BASE_URL + '/rotulos/acciones', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion:     'imprimir_rotulos',
                    csrf_token: CSRF_TOKEN,
                    rotulos:    rotulosActuales
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (datos) {
                mostrarResultadoImpresion(
                    datos.mensaje || datos.error || 'No se pudo imprimir.',
                    !!datos.exito
                );
            })
            .catch(function () {
                mostrarResultadoImpresion(
                    'No se pudo contactar al servidor. Revisá la conexión e intentá de nuevo.',
                    false
                );
            })
            .finally(function () {
                botonEtiquetadora.disabled = false;
                botonEtiquetadora.innerHTML = textoOriginal;
            });
        });
    }

    // -----------------------------------------------------------------------
    // DESCARGAR PDF: se manda la misma lista que está dibujada, igual que en Picking. Antes se
    // mandaban los campos sueltos y el servidor rearmaba los rótulos por su cuenta, con lo que el
    // PDF podía no coincidir con la vista previa.
    // -----------------------------------------------------------------------
    document.getElementById('form-rotulos-pdf')?.addEventListener('submit', function (e) {
        if (!rotulosActuales.length) { e.preventDefault(); return; }
        document.getElementById('rotulos-pdf-datos').value = JSON.stringify(rotulosActuales);
    });

    dibujarRotulos(0);
})();
