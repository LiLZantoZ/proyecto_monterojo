// modules/productos/layouts/scripts_productos.js
// Administrar productos: el modal de registrar/editar (con el nombre del SKU al escribirlo), el
// Kardex de un SKU, la confirmación de eliminar y los filtros que aplican solos.

(function () {
    'use strict';

    var CONF = window.PRODUCTOS || {};

    // Los desplegables de los filtros aplican al cambiarlos.
    document.querySelectorAll('select[data-aplica-solo]').forEach(function (s) {
        s.addEventListener('change', function () {
            if (s.form.requestSubmit) { s.form.requestSubmit(); } else { s.form.submit(); }
        });
    });

    // Eliminar: los formularios con data-confirmar los confirma assets/js/dialogos.js.

    // ------------------------------------------------------------------ REGISTRAR / EDITAR
    var form = document.getElementById('form-producto');
    if (form) {
        var modal = document.getElementById('modal-producto');
        var campo = function (n) { return form.querySelector('[name="' + n + '"]'); };
        var texto = function (n) { return modal.querySelector('[data-campo="' + n + '"]'); };
        var ayudaSku = texto('producto').textContent;

        var mostrarProducto = function () {
            var sku = campo('sku').value.trim();
            var op = sku ? document.querySelector('#lista-skus-productos option[value="' + CSS.escape(sku) + '"]') : null;
            var a = texto('producto');
            a.classList.toggle('ayuda-error', !!sku && !op);
            a.textContent = !sku ? ayudaSku : (op ? (op.textContent || 'SKU encontrado (sin descripción en el maestro).')
                : 'Ese SKU no está en el maestro de productos ni en la lista de Monterojo.');
        };
        campo('sku').addEventListener('input', mostrarProducto);

        // Los botones con data-abrir="modal-producto" (modales.js abre el modal): acá se llena.
        document.addEventListener('click', function (e) {
            var b = e.target.closest('[data-abrir="modal-producto"]');
            if (!b) { return; }
            form.reset();
            var editar = b.dataset.modo === 'editar';
            var d = editar ? JSON.parse(b.dataset.productoDatos || '{}') : {};
            campo('accion').value = editar ? 'editar' : 'crear';
            campo('id').value = d.id || '';
            campo('sku').value = d.sku || '';
            campo('lote').value = d.lote || '';
            campo('fecha_vencimiento').value = d.fecha_vencimiento || '';
            campo('estado').value = d.estado || 'Disponible';
            // Un producto que está en una posición necesita su vencimiento (como toda estiba).
            campo('fecha_vencimiento').required = !!d.ubicacion;
            texto('titulo').textContent = editar ? 'Editar producto' : 'Registrar producto';
            var aviso = texto('aviso-posicion');
            aviso.hidden = !d.ubicacion;
            aviso.textContent = d.ubicacion ? 'Está en la posición ' + d.ubicacion + ': la tarjeta de la posición cambia con este producto.' : '';
            mostrarProducto();
        });
    }

    // ------------------------------------------------------------------ KARDEX
    var modalK = document.getElementById('modal-kardex');
    if (modalK && CONF.urlKardex) {
        var tk = function (n) { return modalK.querySelector('[data-campo="' + n + '"]'); };
        var desde = document.getElementById('k-desde'), hasta = document.getElementById('k-hasta');
        var skuK = '';
        var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        var cargar = function () {
            var cuerpo = tk('filas');
            cuerpo.innerHTML = '<tr><td colspan="7" class="tabla-vacia">Cargando…</td></tr>';
            var url = CONF.urlKardex + '&sku=' + encodeURIComponent(skuK) + '&desde=' + encodeURIComponent(desde.value) + '&hasta=' + encodeURIComponent(hasta.value);
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (filas) {
                if (!filas.length) {
                    cuerpo.innerHTML = '<tr><td colspan="7" class="tabla-vacia">No hay movimientos de este SKU' + (desde.value || hasta.value ? ' en esas fechas' : '') + '.</td></tr>';
                    return;
                }
                cuerpo.innerHTML = filas.map(function (m) {
                    var clase = m.tipo === 'Ingreso' ? 'estado-verde' : (m.tipo === 'Picking' ? 'estado-azul' : 'estado-rojo');
                    return '<tr><td>' + esc(m.fecha) + '</td><td><span class="chip-estado ' + clase + '">' + esc(m.tipo) + '</span></td><td>'
                        + esc(m.ubicacion || '—') + '</td><td>' + esc(m.lote || '—') + '</td><td>' + esc(m.vence || '—') + '</td><td>'
                        + esc(m.cantidad) + '</td><td>' + esc(m.usuario || '—') + '</td></tr>';
                }).join('');
            }).catch(function () {
                cuerpo.innerHTML = '<tr><td colspan="7" class="tabla-vacia">No se pudo cargar el Kardex.</td></tr>';
            });
        };
        document.addEventListener('click', function (e) {
            var b = e.target.closest('[data-kardex]');
            if (!b) { return; }
            skuK = b.dataset.kardex;
            tk('sku').textContent = 'SKU ' + skuK;
            tk('producto').textContent = b.dataset.producto || '';
            desde.value = ''; hasta.value = '';
            modalK.classList.add('active');
            cargar();
        });
        desde.addEventListener('change', cargar);
        hasta.addEventListener('change', cargar);
    }
})();
