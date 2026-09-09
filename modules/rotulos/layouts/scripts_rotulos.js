// modules/rotulos/layouts/scripts_rotulos.js
// El generador manual: dibuja la vista previa apenas se toca cualquier campo, y arma la
// impresión y la descarga en PDF con lo que haya en pantalla en ese momento. Es el mismo cálculo
// que scripts_historial.js —mismo htmlRotulo(), mismo identificador de caja—, adaptado a que acá
// no hay ninguna fila de la que salgan los datos: todo sale de estos mismos campos.
(function () {
    'use strict';

    function esc(texto) {
        var d = document.createElement('div');
        d.textContent = texto == null ? '' : String(texto);
        return d.innerHTML;
    }

    // El identificador de UNA caja: orden de compra - EAN de la tienda (o el punto de venta, si
    // no se cargó uno) - número de caja. Igual que en Picking y en Historial.
    function identificadorDeCaja(datos, numero) {
        var tienda = datos.eanPv && datos.eanPv !== '' ? datos.eanPv : datos.pv;
        return [datos.oc, tienda, numero]
            .map(function (parte) { return String(parte).replace(/[^A-Za-z0-9]/g, ''); })
            .join('-');
    }

    function htmlRotulo(datos, numero, total, producto) {
        var nombreProducto = producto && producto.trim() !== ''
            ? esc(producto)
            : '<span style="color:#888">_______________</span>';

        var idCaja = identificadorDeCaja(datos, numero);
        var urlCodigo = BASE_URL + '/modules/rotulos/controller_rotulos.php?accion=codigo_barras&texto='
                      + encodeURIComponent(idCaja);

        // 100mm x 40mm exactos, el mismo diseño compacto en los tres lugares que arman esto (acá,
        // en scripts_picking.js y en scripts_historial.js) y en el PDF (helper_rotulos_pdf.php,
        // en el módulo Historial): sin logo —no entra en 4cm de alto—, sin etiqueta suelta para
        // cada campo, orden de compra y CEDI comparten renglón, y el contador de cajas sube a
        // compartir el encabezado con la marca. Ver el porqué completo en
        // assets/css/partes/04-rotulo.css.
        return ''
            + '<div class="rotulo">'
            +   '<div class="rotulo-marca">'
            +     '<span>Monterojo Gourmet</span>'
            +     '<span class="rotulo-conteo">CAJ ' + numero + ' DE ' + total + '</span>'
            +   '</div>'
            +   '<span class="rotulo-valor">' + esc(datos.pv) + '</span>'
            +   '<span class="rotulo-meta">O/C ' + esc(datos.oc) + ' · CEDI ' + esc(datos.cedi) + '</span>'
            +   '<span class="rotulo-valor-producto">' + nombreProducto + '</span>'
            +   '<div class="rotulo-codigo">'
            +     '<img src="' + urlCodigo + '" alt="Código de barras ' + esc(idCaja) + '">'
            +     '<span class="rotulo-codigo-texto">' + esc(idCaja) + '</span>'
            +   '</div>'
            + '</div>';
    }

    // La lista de rótulos que hay dibujada en la vista previa en este momento: es lo que se
    // manda a la etiquetadora, para que lo que sale por la impresora sea exactamente lo que
    // se está viendo en pantalla.
    var rotulosActuales = [];

    var previa = document.getElementById('rotulos-previa');

    var campos = {
        cantidad: document.getElementById('rot-cantidad'),
        desde:    document.getElementById('rot-desde'),
        total:    document.getElementById('rot-total'),
        pv:       document.getElementById('rot-pv'),
        oc:       document.getElementById('rot-oc'),
        cedi:     document.getElementById('rot-cedi'),
        producto: document.getElementById('rot-producto'),
        eanPv:    document.getElementById('rot-ean-pv'),
    };

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
            eanPv: campos.eanPv.value
        };

        var html = '';
        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }
        for (var i = 0; i < cantidad; i++) {
            rotulosActuales.push({
                pv: datos.pv, oc: datos.oc, cedi: datos.cedi, ean_pv: datos.eanPv,
                numero: desde + i, total: total, producto: campos.producto.value
            });
            html += htmlRotulo(datos, desde + i, total, campos.producto.value);
        }
        previa.innerHTML = html;
    }

    Object.keys(campos).forEach(function (nombre) {
        campos[nombre].addEventListener('input', dibujarRotulos);
    });

    document.getElementById('btn-limpiar-rotulos')?.addEventListener('click', function () {
        campos.cantidad.value = 1;
        campos.desde.value    = 1;
        campos.total.value    = 1;
        campos.pv.value       = '';
        campos.oc.value       = '';
        campos.cedi.value     = '';
        campos.producto.value = '';
        campos.eanPv.value    = '';
        dibujarRotulos();
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

            fetch(BASE_URL + '/modules/rotulos/controller_rotulos.php', {
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
    // DESCARGAR PDF: se llenan los campos ocultos del formulario justo antes de mandarlo, con
    // los mismos valores (ya validados por entero()) que se usaron para dibujar la vista previa.
    // -----------------------------------------------------------------------
    document.getElementById('form-rotulos-pdf')?.addEventListener('submit', function (e) {
        var form = e.target;
        form.querySelector('[name=cantidad]').value = entero(campos.cantidad, 1, 1, 99);
        form.querySelector('[name=desde]').value    = entero(campos.desde, 1, 1, 999);
        form.querySelector('[name=total]').value    = entero(campos.total, 1, 1, 999);
        form.querySelector('[name=pv]').value       = campos.pv.value;
        form.querySelector('[name=oc]').value       = campos.oc.value;
        form.querySelector('[name=cedi]').value     = campos.cedi.value;
        form.querySelector('[name=producto]').value = campos.producto.value;
        form.querySelector('[name=ean_pv]').value   = campos.eanPv.value;
    });

    dibujarRotulos();
})();
