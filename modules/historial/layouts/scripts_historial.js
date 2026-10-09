// modules/historial/layouts/scripts_historial.js
// Selección, detalle y rótulos son el mismo comportamiento que Picking (ver scripts_picking.js);
// lo único propio de acá es Restaurar, con el mismo patrón de modal —nada de confirm() nativo—
// que Picking usa para despachar.

(function () {
    'use strict';

    var contenedor = document.querySelector('.modulo');
    if (!contenedor) { return; }

    function esc(texto) {
        var d = document.createElement('div');
        d.textContent = texto == null ? '' : String(texto);
        return d.innerHTML;
    }

    function botonModal(texto, clase, icono) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = clase;
        b.innerHTML = (icono ? '<i class="fa-solid ' + icono + '"></i> ' : '') + esc(texto);
        return b;
    }

    // =============================================================================
    // SELECCIÓN DE VARIOS PEDIDOS (igual que Picking)
    // =============================================================================

    var barra       = document.getElementById('barra-seleccion');
    var barraConteo = document.getElementById('barra-conteo');
    var camposPdf   = document.getElementById('campos-pdf-masivo');
    var formPdf     = document.getElementById('form-pdf-masivo');

    function pedidosSeleccionados() {
        return [].slice.call(contenedor.querySelectorAll('.chk-pedido:checked'));
    }

    function refrescarSeleccion() {
        var marcadas = pedidosSeleccionados();

        contenedor.querySelectorAll('.chk-pedido').forEach(function (chk) {
            chk.closest('.fila-pedido').classList.toggle('fila-seleccionada', chk.checked);
        });

        contenedor.querySelectorAll('.chk-todos').forEach(function (todos) {
            var tabla = todos.closest('table');
            var enTabla = [].slice.call(tabla.querySelectorAll('.chk-pedido'));
            var tildadas = enTabla.filter(function (c) { return c.checked; }).length;

            todos.checked = enTabla.length > 0 && tildadas === enTabla.length;
            todos.indeterminate = tildadas > 0 && tildadas < enTabla.length;
        });

        if (!barra) { return; }

        barra.hidden = marcadas.length === 0;
        barraConteo.textContent = marcadas.length;

        // El PDF masivo lleva también la carga de cada pedido (ver pdf_masivo en
        // controller_historial.php): a diferencia de Picking, acá dos pedidos seleccionados
        // pueden ser de cargas distintas.
        camposPdf.innerHTML = '';
        marcadas.forEach(function (chk) {
            ['carga', 'cedi', 'oc', 'pv'].forEach(function (campo) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = campo + '[]';
                input.value = chk.dataset[campo];
                camposPdf.appendChild(input);
            });
        });
    }

    contenedor.addEventListener('change', function (e) {
        if (e.target.classList.contains('chk-pedido')) {
            refrescarSeleccion();
            return;
        }

        if (e.target.classList.contains('chk-todos')) {
            var tabla = e.target.closest('table');
            tabla.querySelectorAll('.chk-pedido').forEach(function (chk) {
                chk.checked = e.target.checked;
            });
            refrescarSeleccion();
        }
    });

    contenedor.addEventListener('click', function (e) {
        if (e.target.classList.contains('chk-pedido') || e.target.classList.contains('chk-todos')) {
            e.stopPropagation();
        }
    });

    document.getElementById('btn-limpiar-seleccion')?.addEventListener('click', function () {
        contenedor.querySelectorAll('.chk-pedido, .chk-todos').forEach(function (chk) {
            chk.checked = false;
            chk.indeterminate = false;
        });
        refrescarSeleccion();
    });

    formPdf?.addEventListener('submit', function (e) {
        if (pedidosSeleccionados().length === 0) { e.preventDefault(); }
    });

    // =============================================================================
    // DETALLE DE UN PEDIDO (igual que Picking)
    // =============================================================================

    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-detalle');
        if (!boton) { return; }

        var fila = boton.closest('.fila-pedido');
        var detalle = fila.nextElementSibling;
        if (!detalle || !detalle.classList.contains('fila-detalle')) { return; }

        var abrir = detalle.hidden;
        detalle.hidden = !abrir;
        fila.classList.toggle('fila-pedido-abierta', abrir);
        boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        boton.title = abrir ? 'Ocultar los productos de este pedido' : 'Ver los productos de este pedido';
    });

    // =============================================================================
    // RESTAURAR UN PEDIDO
    // =============================================================================

    var modalRestaurar  = document.getElementById('modal-restaurar');
    var restaurarCuerpo = document.getElementById('restaurar-cuerpo');
    var restaurarPie    = document.getElementById('restaurar-pie');

    function enviarRestaurar(datos, callback) {
        fetch(BASE_URL + '/historial/acciones', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion: 'restaurar',
                csrf_token: CSRF_TOKEN,
                id_carga: datos.idCarga,
                cedi: datos.cedi,
                orden_compra: datos.oc,
                punto_venta: datos.pv,
            })
        })
        .then(function (r) { return r.json(); })
        .then(callback)
        .catch(function () {
            callback({ exito: false, error: 'No se pudo conectar con el servidor para restaurar.' });
        });
    }

    function mostrarErrorRestaurar(mensaje) {
        restaurarCuerpo.innerHTML =
            '<div class="aviso aviso-atencion" style="margin-bottom:0;">' +
            '<i class="fa-solid fa-triangle-exclamation"></i><div>' + esc(mensaje) + '</div></div>';

        restaurarPie.innerHTML = '';
        var btnCerrar = botonModal('Cerrar', 'btn');
        btnCerrar.addEventListener('click', function () { modalRestaurar.classList.remove('active'); });
        restaurarPie.appendChild(btnCerrar);
    }

    function mostrarConfirmarRestaurar(datos) {
        restaurarCuerpo.innerHTML =
            '<p class="confirmar-pregunta">¿Restaurar el pedido de ' + esc(datos.pv) + '?</p>' +
            '<div class="aviso aviso-info" style="margin-bottom:0;">' +
            '<i class="fa-solid fa-circle-info"></i>' +
            '<div>Vuelve a aparecer como pendiente en Picking y en Consolidados.</div></div>';

        restaurarPie.innerHTML = '';
        var btnCancelar  = botonModal('Cancelar', 'btn');
        var btnConfirmar = botonModal('Restaurar', 'btn btn-primario', 'fa-rotate-left');

        btnCancelar.addEventListener('click', function () { modalRestaurar.classList.remove('active'); });

        btnConfirmar.addEventListener('click', function () {
            btnConfirmar.disabled = true;
            btnCancelar.disabled = true;

            enviarRestaurar(datos, function (resp) {
                if (!resp.exito) {
                    mostrarErrorRestaurar(resp.error || 'No se pudo restaurar. Inténtalo de nuevo.');
                    return;
                }
                window.location.reload();
            });
        });

        restaurarPie.appendChild(btnCancelar);
        restaurarPie.appendChild(btnConfirmar);
        modalRestaurar.classList.add('active');
    }

    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-restaurar');
        if (!boton) { return; }

        mostrarConfirmarRestaurar({
            idCarga: boton.dataset.idCarga,
            cedi:    boton.dataset.cedi,
            oc:      boton.dataset.oc,
            pv:      boton.dataset.pv,
        });
    });

    // ---- Restaurar EN LOTE los pedidos tildados (mismo modal, misma acción por lote) ----
    function enviarRestaurarMasivo(pedidos, callback) {
        fetch(BASE_URL + '/historial/acciones', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                accion:     'restaurar_masivo',
                csrf_token: CSRF_TOKEN,
                pedidos:    pedidos
            })
        })
        .then(function (r) { return r.json(); })
        .then(callback)
        .catch(function () {
            callback({ exito: false, error: 'No se pudo conectar con el servidor para restaurar.' });
        });
    }

    function mostrarConfirmarRestaurarMasivo(pedidos) {
        restaurarCuerpo.innerHTML =
            '<p class="confirmar-pregunta">¿Restaurar los ' + pedidos.length + ' pedido(s) seleccionado(s)?</p>' +
            '<div class="aviso aviso-info" style="margin-bottom:0;">' +
            '<i class="fa-solid fa-circle-info"></i>' +
            '<div>Todos vuelven a aparecer como pendientes en Picking y en Consolidados.</div></div>';

        restaurarPie.innerHTML = '';
        var btnCancelar  = botonModal('Cancelar', 'btn');
        var btnConfirmar = botonModal('Restaurar ' + pedidos.length, 'btn btn-primario', 'fa-rotate-left');

        btnCancelar.addEventListener('click', function () { modalRestaurar.classList.remove('active'); });

        btnConfirmar.addEventListener('click', function () {
            btnConfirmar.disabled = true;
            btnCancelar.disabled = true;
            btnConfirmar.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Restaurando...';

            enviarRestaurarMasivo(pedidos, function (resp) {
                if (!resp.exito) {
                    mostrarErrorRestaurar(resp.error || 'No se pudo restaurar. Inténtalo de nuevo.');
                    return;
                }
                window.location.reload();
            });
        });

        restaurarPie.appendChild(btnCancelar);
        restaurarPie.appendChild(btnConfirmar);
        modalRestaurar.classList.add('active');
    }

    document.getElementById('btn-restaurar-masivo')?.addEventListener('click', function () {
        var marcadas = pedidosSeleccionados();
        if (!marcadas.length) { return; }

        var pedidos = marcadas.map(function (chk) {
            return {
                id_carga:     chk.dataset.carga,
                cedi:         chk.dataset.cedi,
                orden_compra: chk.dataset.oc,
                punto_venta:  chk.dataset.pv
            };
        });

        mostrarConfirmarRestaurarMasivo(pedidos);
    });

    // =============================================================================
    // RÓTULOS (igual que Picking, apuntando a controller_historial.php para el código de barras)
    // =============================================================================

    var modal   = document.getElementById('modal-rotulo');
    var previa  = document.getElementById('rotulos-previa');
    var detalleTitulo = document.getElementById('rotulo-titulo-detalle');
    var avisoSaldos = document.getElementById('rotulo-aviso-saldos');
    var avisoSaldosTexto = document.getElementById('rotulo-aviso-saldos-texto');
    var avisoImpresion      = document.getElementById('rotulo-aviso-impresion');
    var avisoImpresionTexto = document.getElementById('rotulo-aviso-impresion-texto');
    var botonEtiquetadora   = document.getElementById('btn-imprimir-etiquetadora');

    // La lista de rótulos que hay dibujada en la vista previa en este momento. Es lo que se manda
    // a imprimir: el rótulo acá es EDITABLE y, al abrirlo para un pedido entero, cada caja lleva
    // su propio producto, así que recalcularlo en el servidor daría un rótulo distinto del que la
    // persona está mirando.
    var rotulosActuales = [];

    // El rótulo se dibuja con assets/js/rotulo.js, el mismo archivo para las cuatro pantallas que
    // lo muestran. Hasta el 2026-09-14 había acá una copia propia de htmlRotulo(), y quedó con el
    // diseño viejo cuando el rótulo cambió: el "CAJ 1 DE 3" salía encima del producto.

    var campos = {
        cantidad: document.getElementById('rot-cantidad'),
        desde:    document.getElementById('rot-desde'),
        total:    document.getElementById('rot-total'),
        pv:       document.getElementById('rot-pv'),
        numeroPv: document.getElementById('rot-numero-pv'),
        cedi:     document.getElementById('rot-cedi'),
        producto: document.getElementById('rot-producto')
    };

    // Lo que viene del botón y no tiene campo propio en el panel. La orden de compra ya no se
    // imprime, pero sigue viajando con la lista.
    var identificadores = { eanPv: '', oc: '' };

    // Los productos del pedido en orden, cada uno con cuántas cajas lleva, su SKU y su EAN.
    var segmentos = [];

    function entero(campo, porDefecto, minimo, maximo) {
        var valor = parseInt(campo.value, 10);
        if (!valor || valor < minimo) { valor = porDefecto; }
        if (valor > maximo) { valor = maximo; }
        return valor;
    }

    // espera: cuánto aguardar antes de pedir el QR. Al abrir el modal no se espera; mientras se
    // escribe en el panel, sí (ver dibujar() en assets/js/rotulo.js).
    function dibujarRotulos(espera) {
        var cantidad = entero(campos.cantidad, 1, 1, 99);
        var desde    = entero(campos.desde, 1, 1, 999);
        var total    = entero(campos.total, 1, 1, 999);

        var ultima = desde + cantidad - 1;
        if (total < ultima) { total = ultima; }

        // Un producto escrito a mano reemplaza al del pedido en TODAS las cajas. El SKU y el EAN
        // eran del producto original y ya no le corresponden, así que en ese caso no se mandan: un
        // SKU equivocado en la etiqueta es peor que ninguno.
        var escrito = campos.producto.value.trim();

        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }

        for (var i = 0; i < cantidad; i++) {
            var numero = desde + i;
            var segmento = RotuloMonterojo.segmentoDeLaCaja(segmentos, numero);

            rotulosActuales.push({
                pv:        campos.pv.value,
                numero_pv: campos.numeroPv.value.trim(),
                oc:        identificadores.oc,
                cedi:      campos.cedi.value,
                ean_pv:    identificadores.eanPv,
                numero:    numero,
                total:     total,
                producto:  escrito !== '' ? escrito : (segmento.producto || ''),
                sku:       escrito !== '' ? '' : (segmento.sku || ''),
                ean:       escrito !== '' ? '' : (segmento.ean || '')
            });
        }

        RotuloMonterojo.dibujar(previa, rotulosActuales, espera);
    }

    // Envuelto en una función a propósito: pasado directo, addEventListener le daría el evento
    // como primer argumento y dibujarRotulos() lo tomaría como la espera.
    Object.keys(campos).forEach(function (nombre) {
        campos[nombre].addEventListener('input', function () { dibujarRotulos(); });
    });

    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-rotulo');
        if (!boton) { return; }

        var calculadas = parseInt(boton.dataset.cajas, 10) || 0;
        var totalPedido = parseInt(boton.dataset.total, 10) || 0;

        identificadores.eanPv = boton.dataset.eanPv || '';
        identificadores.oc    = boton.dataset.oc || '';

        if (boton.dataset.segmentos) {
            try {
                segmentos = JSON.parse(boton.dataset.segmentos) || [];
            } catch (error) {
                segmentos = [];
            }
        } else {
            segmentos = boton.dataset.producto
                ? [{
                    n:        Math.max(calculadas, 1),
                    producto: boton.dataset.producto,
                    sku:      boton.dataset.sku || '',
                    ean:      boton.dataset.ean || ''
                  }]
                : [];
        }

        mostrarPanelEdicion(true);

        campos.cantidad.value = calculadas > 0 ? calculadas : 1;
        campos.desde.value    = parseInt(boton.dataset.desde, 10) || 1;
        campos.total.value    = totalPedido > 0 ? totalPedido : 1;
        campos.pv.value       = boton.dataset.pv || '';
        campos.numeroPv.value = boton.dataset.numeroPv || '';
        campos.cedi.value     = boton.dataset.cedi || '';
        campos.producto.value = '';

        dibujarRotulos(0);

        detalleTitulo.textContent = '· ' + boton.dataset.pv + ' · '
            + (boton.dataset.descripcion || 'pedido completo');

        var saldos = parseInt(boton.dataset.saldos, 10) || 0;
        if (calculadas < 1) {
            avisoSaldosTexto.innerHTML = 'Esta línea no tiene unidades que rotular, así que el rótulo '
                + 'arranca en <strong>1</strong>. Ajustá la cantidad si hace falta.';
            avisoSaldos.hidden = false;
        } else if (saldos > 0 && !boton.dataset.segmentos) {
            avisoSaldosTexto.innerHTML = 'La última de estas <strong>' + calculadas + '</strong> cajas va '
                + 'incompleta: son <strong>' + saldos + '</strong> unidad(es) sueltas que no llenan una caja, '
                + 'pero viajaron igual y por eso llevan rótulo.';
            avisoSaldos.hidden = false;
        } else {
            avisoSaldos.hidden = true;
        }

        modal.classList.add('active');
    });

    var panelControles = document.querySelector('.rotulo-controles');
    var notaControles  = document.querySelector('.rotulo-nota');

    function mostrarPanelEdicion(visible) {
        if (panelControles) { panelControles.hidden = !visible; }
        if (notaControles)  { notaControles.hidden  = !visible; }
    }

    document.getElementById('btn-rotulos-masivo')?.addEventListener('click', function () {
        var marcadas = pedidosSeleccionados();
        if (!marcadas.length) { return; }

        var sinCajas = 0;
        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }

        marcadas.forEach(function (chk) {
            var boton = chk.closest('.fila-pedido').querySelector('.btn-rotulo');
            if (!boton) { return; }

            var total = parseInt(boton.dataset.cajas, 10) || 0;
            if (total < 1) { sinCajas++; return; }

            var segmentosPedido = [];
            try {
                segmentosPedido = JSON.parse(boton.dataset.segmentos || '[]');
            } catch (error) {
                segmentosPedido = [];
            }

            for (var i = 1; i <= total; i++) {
                var segmento = RotuloMonterojo.segmentoDeLaCaja(segmentosPedido, i);

                rotulosActuales.push({
                    pv:        boton.dataset.pv,
                    numero_pv: boton.dataset.numeroPv || '',
                    oc:        boton.dataset.oc,
                    cedi:      boton.dataset.cedi,
                    ean_pv:    boton.dataset.eanPv,
                    numero:    i,
                    total:     total,
                    producto:  segmento.producto || '',
                    sku:       segmento.sku || '',
                    ean:       segmento.ean || ''
                });
            }
        });

        var totalRotulos = rotulosActuales.length;

        if (!totalRotulos) {
            Dialogo.avisar('Ninguno de los pedidos seleccionados tiene cajas que rotular.', { tipo: 'info', titulo: 'Nada que rotular' });
            return;
        }

        mostrarPanelEdicion(false);
        RotuloMonterojo.dibujar(previa, rotulosActuales, 0);
        detalleTitulo.textContent = '· ' + totalRotulos + ' rótulos de ' + marcadas.length + ' pedido(s)';

        if (sinCajas > 0) {
            avisoSaldosTexto.innerHTML = '<strong>' + sinCajas + '</strong> de los pedidos seleccionados no '
                + 'tienen cajas que rotular, así que quedaron fuera.';
            avisoSaldos.hidden = false;
        } else {
            avisoSaldos.hidden = true;
        }

        modal.classList.add('active');
    });

    // ---------------------------------------------------------------------------------------
    // IMPRIMIR EN LA ETIQUETADORA
    //
    // Manda la lista ya dibujada y el servidor la traduce a TSPL, el idioma de la TSC (ver
    // modules/historial/helper_rotulos_tspl.php). No abre el diálogo de impresión del navegador,
    // que es de donde salían las etiquetas corridas y en blanco.
    // ---------------------------------------------------------------------------------------
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

            fetch(BASE_URL + '/historial/acciones', {
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

})();
