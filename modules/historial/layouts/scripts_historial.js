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
        fetch(BASE_URL + '/modules/historial/controller_historial.php', {
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

    function identificadorDeCaja(datos, numero) {
        var tienda = datos.eanPv && datos.eanPv !== ''
            ? datos.eanPv
            : datos.pv;

        return [datos.oc, tienda, numero]
            .map(function (parte) { return String(parte).replace(/[^A-Za-z0-9]/g, ''); })
            .join('-');
    }

    // El cuerpo de letra de un valor, en milímetros, según cuán largo sea el texto.
    //
    // Es la copia EXACTA de cuerpoValorPdf() en modules/historial/helper_rotulos_pdf.php, y el
    // equivalente de textoQueEntre() en la etiquetadora (helper_rotulos_tspl.php): los tres
    // achican la letra antes que partir el texto en dos renglones. Si se partiera, el rótulo
    // crecería de alto y el último campo quedaría cortado.
    //
    // escala permite usar la misma progresión para valores que arrancan más chicos, como el
    // producto. Si se cambia algún número acá, hay que cambiarlo también en el PHP.
    function cuerpoValor(texto, escala) {
        var largo = String(texto == null ? '' : texto).trim().length;
        var base  = largo <= 22 ? 5.5
                  : largo <= 30 ? 4.5
                  : largo <= 40 ? 3.6
                  : 3.0;
        return (base * (escala || 1)).toFixed(2);
    }
    function htmlRotulo(datos, numero, total, producto) {
        var nombreProducto = producto && producto.trim() !== ''
            ? esc(producto)
            : '<span style="color:#888">________________</span>';

        var idCaja = identificadorDeCaja(datos, numero);
        var urlCodigo = BASE_URL + '/modules/historial/controller_historial.php?accion=codigo_barras&texto='
                      + encodeURIComponent(idCaja);

        // El mismo diseño y el mismo orden de campos que imprime la etiquetadora (ver
        // tsplDeUnRotulo en modules/historial/helper_rotulos_tspl.php) y que sale en el PDF
        // (helper_rotulos_pdf.php). Son CUATRO lugares que dibujan el mismo rótulo —acá, en
        // scripts_picking.js y en scripts_rotulos.js, y el PDF—: si se agrega o se mueve un campo, se mueve en los cuatro, o la
        // vista previa deja de ser una vista previa.
        var campos = [
            ['Punto de venta',  esc(datos.pv),          cuerpoValor(datos.pv)],
            ['Orden de compra', esc(datos.oc || '-'),   cuerpoValor(datos.oc, 0.75)],
            ['Cajas total',     total,                  cuerpoValor(String(total), 0.75)],
            ['Producto',        nombreProducto,         cuerpoValor(producto, 0.70)],
            ['CEDI',            esc(datos.cedi || '-'), cuerpoValor(datos.cedi, 0.75)]
        ];

        var html = ''
            + '<div class="rotulo">'
            +   '<div class="rotulo-marca">'
            +     '<img src="' + LOGO_URL + '" alt="">'
            +     '<span>Monterojo Gourmet</span>'
            +   '</div>'
            +   '<div class="rotulo-campos">';

        campos.forEach(function (campo) {
            html += '<div class="rotulo-campo">'
                  +   '<span class="rotulo-etiqueta">' + campo[0] + '</span>'
                  +   '<span class="rotulo-valor" style="font-size: ' + campo[2] + 'mm">' + campo[1] + '</span>'
                  + '</div>';
        });

        return html
            +   '</div>'
            +   '<div class="rotulo-conteo">CAJ ' + numero + ' DE ' + total + '</div>'
            +   '<div class="rotulo-codigo">'
            +     '<img src="' + urlCodigo + '" alt="Código de barras ' + esc(idCaja) + '">'
            +     '<span class="rotulo-codigo-texto">' + esc(idCaja) + '</span>'
            +   '</div>'
            + '</div>';
    }

    var campos = {
        cantidad: document.getElementById('rot-cantidad'),
        desde:    document.getElementById('rot-desde'),
        total:    document.getElementById('rot-total'),
        pv:       document.getElementById('rot-pv'),
        oc:       document.getElementById('rot-oc'),
        cedi:     document.getElementById('rot-cedi'),
        producto: document.getElementById('rot-producto')
    };

    var identificadores = { eanPv: '' };

    var segmentos = [];

    function productoDeLaCaja(numero) {
        var escrito = campos.producto.value.trim();
        if (escrito !== '') { return escrito; }

        var restante = numero;
        for (var i = 0; i < segmentos.length; i++) {
            if (restante <= segmentos[i].n) { return segmentos[i].producto; }
            restante -= segmentos[i].n;
        }

        return segmentos.length ? segmentos[segmentos.length - 1].producto : '';
    }

    function entero(campo, porDefecto, minimo, maximo) {
        var valor = parseInt(campo.value, 10);
        if (!valor || valor < minimo) { valor = porDefecto; }
        if (valor > maximo) { valor = maximo; }
        return valor;
    }

    function dibujarRotulos() {
        var cantidad = entero(campos.cantidad, 1, 1, 99);
        var desde    = entero(campos.desde, 1, 1, 999);
        var total    = entero(campos.total, 1, 1, 999);

        var ultima = desde + cantidad - 1;
        if (total < ultima) { total = ultima; }

        var datos = {
            pv:    campos.pv.value,
            oc:    campos.oc.value,
            cedi:  campos.cedi.value,
            eanPv: identificadores.eanPv
        };

        var html = '';
        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }
        for (var i = 0; i < cantidad; i++) {
            var numero = desde + i;
            var producto = productoDeLaCaja(numero);
            rotulosActuales.push({
                pv: datos.pv, oc: datos.oc, cedi: datos.cedi, ean_pv: datos.eanPv,
                numero: numero, total: total, producto: producto
            });
            html += htmlRotulo(datos, numero, total, producto);
        }
        previa.innerHTML = html;
    }

    Object.keys(campos).forEach(function (nombre) {
        campos[nombre].addEventListener('input', dibujarRotulos);
    });

    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-rotulo');
        if (!boton) { return; }

        var calculadas = parseInt(boton.dataset.cajas, 10) || 0;
        var totalPedido = parseInt(boton.dataset.total, 10) || 0;

        identificadores.eanPv = boton.dataset.eanPv || '';

        if (boton.dataset.segmentos) {
            try {
                segmentos = JSON.parse(boton.dataset.segmentos) || [];
            } catch (error) {
                segmentos = [];
            }
        } else {
            segmentos = boton.dataset.producto
                ? [{ n: Math.max(calculadas, 1), producto: boton.dataset.producto }]
                : [];
        }

        mostrarPanelEdicion(true);

        campos.cantidad.value = calculadas > 0 ? calculadas : 1;
        campos.desde.value    = parseInt(boton.dataset.desde, 10) || 1;
        campos.total.value    = totalPedido > 0 ? totalPedido : 1;
        campos.pv.value       = boton.dataset.pv || '';
        campos.oc.value       = boton.dataset.oc || '';
        campos.cedi.value     = boton.dataset.cedi || '';
        campos.producto.value = '';

        dibujarRotulos();

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

        var html = '';
        var totalRotulos = 0;
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

            var datos = {
                pv:    boton.dataset.pv,
                oc:    boton.dataset.oc,
                cedi:  boton.dataset.cedi,
                eanPv: boton.dataset.eanPv
            };

            for (var i = 1; i <= total; i++) {
                var restante = i;
                var producto = '';
                for (var s = 0; s < segmentosPedido.length; s++) {
                    if (restante <= segmentosPedido[s].n) { producto = segmentosPedido[s].producto; break; }
                    restante -= segmentosPedido[s].n;
                }
                rotulosActuales.push({
                    pv: datos.pv, oc: datos.oc, cedi: datos.cedi, ean_pv: datos.eanPv,
                    numero: i, total: total, producto: producto
                });
                html += htmlRotulo(datos, i, total, producto);
                totalRotulos++;
            }
        });

        if (!totalRotulos) {
            alert('Ninguno de los pedidos seleccionados tiene cajas que rotular.');
            return;
        }

        mostrarPanelEdicion(false);
        previa.innerHTML = html;
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

            fetch(BASE_URL + '/modules/historial/controller_historial.php', {
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
