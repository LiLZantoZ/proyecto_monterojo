// modules/cajas_punto_venta/layouts/scripts_cajas_punto_venta.js
// El botón "Rótulo" de cada fila: arma las etiquetas de ESA tienda y las muestra para imprimir.
//
// Una tienda lleva varias cajas y cada caja lleva un producto distinto. El botón calcula la
// repartición igual que Picking: si la tienda lleva 3 cajas de un producto y 2 de otro, salen 5
// etiquetas numeradas "CAJ 1 DE 5" a "CAJ 5 DE 5", cada una con SU producto. Sin eso habría que
// abrir pedido por pedido para rotular una sola tienda.

(function () {
    'use strict';

    var modal  = document.getElementById('modal-rotulo');
    var previa = document.getElementById('rotulos-previa');
    if (!modal || !previa) { return; }

    var detalle = document.getElementById('rotulo-titulo-detalle');
    var avisoImpresion      = document.getElementById('rotulo-aviso-impresion');
    var avisoImpresionTexto = document.getElementById('rotulo-aviso-impresion-texto');
    var botonEtiquetadora   = document.getElementById('btn-imprimir-etiquetadora');

    // La lista de rótulos que hay dibujada: es lo que se manda a imprimir, para que el papel sea
    // exactamente lo que se ve en pantalla.
    var rotulosActuales = [];

    // El rótulo se dibuja y se le piden los QR con assets/js/rotulo.js, el mismo archivo para las
    // cuatro pantallas que lo muestran. Esta era la única copia que estaba al día; al unificarlas
    // (2026-09-14) se movió allá.

    function mostrarResultadoImpresion(texto, salioBien) {
        if (!avisoImpresion) { return; }
        avisoImpresionTexto.textContent = texto;
        avisoImpresion.className = 'aviso ' + (salioBien ? 'aviso-exito' : 'aviso-atencion');
        avisoImpresion.querySelector('i').className = salioBien
            ? 'fa-solid fa-circle-check'
            : 'fa-solid fa-triangle-exclamation';
        avisoImpresion.hidden = false;
    }

    document.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-rotulo-punto');
        if (!boton) { return; }

        var filas = [];
        try {
            filas = JSON.parse(boton.dataset.filas || '[]');
        } catch (error) {
            filas = [];
        }

        // Solo las líneas que dan cajas: un producto sin unidades por caja en el maestro no tiene
        // cuántas etiquetas le corresponden, y poner una "por si acaso" mandaría a pegar un rótulo
        // sobre una caja que no existe.
        var conCajas = filas.filter(function (f) { return f.cajas > 0; });
        var total = conCajas.reduce(function (n, f) { return n + f.cajas; }, 0);

        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }

        var numero = 1;
        conCajas.forEach(function (f) {
            for (var i = 0; i < f.cajas; i++) {
                rotulosActuales.push({
                    pv:        boton.dataset.nombre || boton.dataset.punto,
                    numero_pv: boton.dataset.numero,
                    cedi:      boton.dataset.cedi,
                    // Solo esta pantalla lo manda: activa el formato Éxito del rótulo, con
                    // "CEDI: 149" en grande (ver assets/js/rotulo.js).
                    numero_cedi: boton.dataset.numeroCedi || '',
                    producto:  f.producto,
                    sku:       f.sku,
                    ean:       f.ean,
                    numero:    numero++,
                    total:     total
                });
            }
        });

        if (!rotulosActuales.length) {
            previa.innerHTML = '';
            detalle.textContent = '· esta tienda no tiene cajas que rotular';
            mostrarResultadoImpresion(
                'Los productos de esta tienda no tienen unidades por caja cargadas en el maestro, '
                + 'así que no se puede saber cuántas etiquetas le corresponden.',
                false
            );
            modal.classList.add('active');
            return;
        }

        detalle.textContent = '· ' + (boton.dataset.numero ? boton.dataset.numero + ' · ' : '')
                            + (boton.dataset.nombre || boton.dataset.punto)
                            + ' · ' + total + ' caja(s)';

        // Se dibuja al instante y se vuelve a dibujar cuando llegan los QR (ver dibujar()).
        RotuloMonterojo.dibujar(previa, rotulosActuales, 0);
        modal.classList.add('active');
    });

    // Cerrar
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-cerrar]') && e.target.closest('#modal-rotulo')) {
            modal.classList.remove('active');
        }
    });

    if (botonEtiquetadora) {
        botonEtiquetadora.addEventListener('click', function () {
            if (!rotulosActuales.length) { return; }

            // Sin esto, dos clics mandan el lote dos veces — y acá eso son etiquetas de papel
            // gastadas, no una fila repetida que se pueda borrar.
            botonEtiquetadora.disabled = true;
            var textoOriginal = botonEtiquetadora.innerHTML;
            botonEtiquetadora.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
            if (avisoImpresion) { avisoImpresion.hidden = true; }

            fetch(BASE_URL + '/picking/acciones', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion:     'imprimir_rotulos',
                    csrf_token: CSRF_TOKEN,
                    rotulos:    rotulosActuales
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                mostrarResultadoImpresion(d.mensaje || d.error || 'No se pudo imprimir.', !!d.exito);
            })
            .catch(function () {
                mostrarResultadoImpresion('No se pudo contactar al servidor. Intentá de nuevo.', false);
            })
            .finally(function () {
                botonEtiquetadora.disabled = false;
                botonEtiquetadora.innerHTML = textoOriginal;
            });
        });
    }

    document.getElementById('form-rotulos-pdf')?.addEventListener('submit', function (e) {
        if (!rotulosActuales.length) { e.preventDefault(); return; }
        document.getElementById('rotulos-pdf-datos').value = JSON.stringify(rotulosActuales);
    });
})();
