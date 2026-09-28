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

    // =============================================================================
    // SELECCIÓN DE VARIOS PUNTOS DE VENTA
    //
    // Mismo mecanismo que Picking (ver scripts_picking.js): una casilla por fila, un "todos" por
    // tabla de CEDI, y una barra flotante abajo con lo tildado. Acá una fila YA es un punto de
    // venta con sus cajas resueltas, así que no hace falta ir a buscar nada más para armar sus
    // rótulos: los mismos datos que usa el botón "Rótulo" de la fila (data-filas, data-cajas...)
    // alcanzan.
    // =============================================================================

    var contenedor  = document.querySelector('.modulo');
    var barra       = document.getElementById('barra-seleccion');
    var barraConteo = document.getElementById('barra-conteo');
    var camposPdf   = document.getElementById('campos-pdf-seleccion');
    var formPdf     = document.getElementById('form-pdf-seleccion');

    function puntosSeleccionados() {
        return contenedor ? [].slice.call(contenedor.querySelectorAll('.chk-punto:checked')) : [];
    }

    function refrescarSeleccion() {
        if (!contenedor) { return; }
        var marcadas = puntosSeleccionados();

        // La fila tildada se resalta, igual que en Picking: con el detalle de una tienda a la
        // vista es fácil perder de vista cuáles quedaron marcadas.
        contenedor.querySelectorAll('.chk-punto').forEach(function (chk) {
            chk.closest('.fila-punto').classList.toggle('fila-seleccionada', chk.checked);
        });

        // Cada "todos" refleja el estado de SU tabla (un CEDI), no el de todas juntas.
        contenedor.querySelectorAll('.chk-todos').forEach(function (todos) {
            var tabla   = todos.closest('table');
            var enTabla = [].slice.call(tabla.querySelectorAll('.chk-punto'));
            var tildadas = enTabla.filter(function (c) { return c.checked; }).length;

            todos.checked = enTabla.length > 0 && tildadas === enTabla.length;
            todos.indeterminate = tildadas > 0 && tildadas < enTabla.length;
        });

        if (!barra) { return; }

        barra.hidden = marcadas.length === 0;
        if (barraConteo) { barraConteo.textContent = marcadas.length; }

        if (camposPdf) {
            camposPdf.innerHTML = '';
            marcadas.forEach(function (chk) {
                ['cedi', 'punto'].forEach(function (campo) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = campo + '[]';
                    input.value = chk.dataset[campo];
                    camposPdf.appendChild(input);
                });
            });
        }
    }

    if (contenedor) {
        contenedor.addEventListener('change', function (e) {
            if (e.target.classList.contains('chk-punto')) {
                refrescarSeleccion();
                return;
            }
            if (e.target.classList.contains('chk-todos')) {
                var tabla = e.target.closest('table');
                tabla.querySelectorAll('.chk-punto').forEach(function (chk) {
                    chk.checked = e.target.checked;
                });
                refrescarSeleccion();
            }
        });
    }

    document.getElementById('btn-limpiar-seleccion')?.addEventListener('click', function () {
        contenedor.querySelectorAll('.chk-punto, .chk-todos').forEach(function (chk) {
            chk.checked = false;
            chk.indeterminate = false;
        });
        refrescarSeleccion();
    });

    // Enviar el formulario vacío descargaría un PDF sin puntos; no debería poder pasar (la barra
    // se oculta sin selección), pero por las dudas.
    formPdf?.addEventListener('submit', function (e) {
        if (puntosSeleccionados().length === 0) { e.preventDefault(); }
    });

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

    // El botón "Rótulos" de la barra de selección: junta los rótulos de TODAS las tiendas
    // tildadas en un solo lote. Cada tienda mantiene su propia numeración "CAJA X DE Y" —el
    // mismo criterio que Picking— porque ese número le sirve a quien arma la estiba de ESA
    // tienda, y mezclarlo con el de otra no significaría nada.
    document.getElementById('btn-rotulos-masivo')?.addEventListener('click', function () {
        var marcadas = puntosSeleccionados();
        if (!marcadas.length) { return; }

        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }
        var sinCajas = 0;

        marcadas.forEach(function (chk) {
            var boton = chk.closest('.fila-punto').querySelector('.btn-rotulo-punto');
            if (!boton) { return; }

            var filas = [];
            try { filas = JSON.parse(boton.dataset.filas || '[]'); } catch (error) { filas = []; }

            var conCajas = filas.filter(function (f) { return f.cajas > 0; });
            var total = conCajas.reduce(function (n, f) { return n + f.cajas; }, 0);

            if (!total) { sinCajas++; return; }

            var numero = 1;
            conCajas.forEach(function (f) {
                for (var i = 0; i < f.cajas; i++) {
                    rotulosActuales.push({
                        pv:          boton.dataset.nombre || boton.dataset.punto,
                        numero_pv:   boton.dataset.numero,
                        cedi:        boton.dataset.cedi,
                        numero_cedi: boton.dataset.numeroCedi || '',
                        producto:    f.producto,
                        sku:         f.sku,
                        ean:         f.ean,
                        numero:      numero++,
                        total:       total
                    });
                }
            });
        });

        if (!rotulosActuales.length) {
            previa.innerHTML = '';
            detalle.textContent = '· ninguno de los puntos seleccionados tiene cajas que rotular';
            mostrarResultadoImpresion(
                'Los productos de los puntos de venta seleccionados no tienen unidades por caja '
                + 'cargadas en el maestro, así que no se puede saber cuántas etiquetas les corresponden.',
                false
            );
            modal.classList.add('active');
            return;
        }

        detalle.textContent = '· ' + rotulosActuales.length + ' rótulos de ' + marcadas.length + ' punto(s) de venta';
        if (sinCajas > 0) {
            mostrarResultadoImpresion(
                sinCajas + ' de los puntos seleccionados no tenían cajas que rotular y quedaron fuera.',
                true
            );
        }

        RotuloMonterojo.dibujar(previa, rotulosActuales, 0);
        modal.classList.add('active');
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
