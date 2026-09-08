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

        return ''
            + '<div class="rotulo">'
            +   '<div class="rotulo-marca">'
            +     '<img src="' + LOGO_URL + '" alt="">'
            +     '<span>Monterojo Gourmet</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">Punto de venta</span>'
            +     '<span class="rotulo-valor">' + esc(datos.pv) + '</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">Orden de compra</span>'
            +     '<span class="rotulo-valor">' + esc(datos.oc) + '</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">Cajas total</span>'
            +     '<span class="rotulo-valor">' + total + '</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">Producto</span>'
            +     '<span class="rotulo-valor rotulo-valor-producto">' + nombreProducto + '</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">CEDI</span>'
            +     '<span class="rotulo-valor">' + esc(datos.cedi) + '</span>'
            +   '</div>'
            +   '<div class="rotulo-conteo">CAJ ' + numero + ' DE ' + total + '</div>'
            +   '<div class="rotulo-codigo">'
            +     '<img src="' + urlCodigo + '" alt="Código de barras ' + esc(idCaja) + '">'
            +     '<span class="rotulo-codigo-texto">' + esc(idCaja) + '</span>'
            +   '</div>'
            + '</div>';
    }

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
        for (var i = 0; i < cantidad; i++) {
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
    var areaImpresion = document.getElementById('area-impresion-rotulos');

    document.getElementById('btn-imprimir-rotulos')?.addEventListener('click', function () {
        while (previa.firstChild) {
            areaImpresion.appendChild(previa.firstChild);
        }
        areaImpresion.hidden = false;
        document.body.classList.add('imprimiendo-rotulos');

        function restaurar() {
            while (areaImpresion.firstChild) {
                previa.appendChild(areaImpresion.firstChild);
            }
            areaImpresion.hidden = true;
            document.body.classList.remove('imprimiendo-rotulos');
            window.removeEventListener('afterprint', restaurar);
        }

        window.addEventListener('afterprint', restaurar);
        window.print();

        setTimeout(function () {
            if (document.body.classList.contains('imprimiendo-rotulos')) { restaurar(); }
        }, 1500);
    });

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
