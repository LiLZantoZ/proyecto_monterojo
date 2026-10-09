// modules/formato_conciliador/layouts/scripts_formato_conciliador.js
// Formato Conciliador: el registro (la descripción, las unidades por caja y el vencimiento se
// completan solos al escribir SKU y lote), la confirmación de eliminar y el RÓTULO de la estiba,
// igual al de bodega: SKU, descripción, lote, día juliano, vencimiento y cajas, a 27,7 × 17,6 cm.

(function () {
    'use strict';

    var CONF = window.CONCILIADOR || {};
    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    var DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    // Los formularios con data-confirmar (eliminar un registro) los confirma assets/js/dialogos.js.

    // ------------------------------------------------------------------ AGREGAR REGISTRO
    var form = document.getElementById('form-fc');
    if (form) {
        var campo = function (n) { return form.querySelector('[name="' + n + '"]'); };
        var dato = function (n) { return form.querySelector('[data-campo="' + n + '"]'); };
        var upc = null, temporizador = null, descripcionAuto = '';

        var recalcular = function () {
            var cajas = parseInt(campo('cajas').value, 10) || 0, saldos = parseInt(campo('saldos').value, 10) || 0;
            dato('total').textContent = upc ? (cajas * upc + saldos).toLocaleString('es-CO') + ' unidades' : '—';
        };
        var buscar = function () {
            var sku = campo('sku').value.trim();
            if (!sku || !CONF.urlBuscar) { return; }
            fetch(CONF.urlBuscar + '&sku=' + encodeURIComponent(sku) + '&lote=' + encodeURIComponent(campo('lote').value.trim()), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var ayuda = dato('upc');
                    if (!d.encontrado) {
                        upc = null;
                        ayuda.textContent = 'Ese SKU no está en el maestro de productos ni en la lista de Monterojo.';
                        ayuda.classList.add('ayuda-error');
                        recalcular();
                        return;
                    }
                    ayuda.classList.remove('ayuda-error');
                    // La descripción se pisa solo si la puso el sistema (no si la escribió la persona).
                    if (!campo('descripcion').value || campo('descripcion').value === descripcionAuto) {
                        descripcionAuto = d.descripcion || '';
                        campo('descripcion').value = descripcionAuto;
                    }
                    if (d.fecha_vencimiento && !campo('fecha_vencimiento').value) { campo('fecha_vencimiento').value = d.fecha_vencimiento; }
                    upc = d.unidades_por_caja;
                    ayuda.textContent = upc ? upc + ' unidades por caja (maestro de productos).' : 'El SKU no tiene unidades por caja en el maestro: el total de unidades queda vacío.';
                    recalcular();
                }).catch(function () {});
        };
        var programar = function () { clearTimeout(temporizador); temporizador = setTimeout(buscar, 300); };
        campo('sku').addEventListener('input', programar);
        campo('lote').addEventListener('input', programar);
        campo('cajas').addEventListener('input', recalcular);
        campo('saldos').addEventListener('input', recalcular);
        form.addEventListener('submit', function (e) {
            if ((parseInt(campo('cajas').value, 10) || 0) === 0 && (parseInt(campo('saldos').value, 10) || 0) === 0) {
                e.preventDefault();
                Dialogo.avisar('Escribí las cajas o los saldos: no pueden ser los dos cero.', { tipo: 'info', titulo: 'Falta un dato' });
            }
        });
    }

    // ------------------------------------------------------------------ EL RÓTULO
    // EL DÍA JULIANO sale del lote, como en bodega: máquina + día del año (3 dígitos) + año (2).
    // "T2 L 1113726" → lo que va después de la L → 1113726 → …|137|26 → 17 de mayo de 2026.
    // Se leen los últimos 5 dígitos porque el código de máquina no siempre tiene el mismo largo.
    function diaJuliano(lote) {
        if (!lote) { return null; }
        var t = String(lote).trim();
        var posL = t.toUpperCase().lastIndexOf('L');
        if (posL !== -1) { t = t.substring(posL + 1); }
        var digitos = t.replace(/\D/g, '');
        if (digitos.length < 5) { return null; }
        var bloque = digitos.slice(-5);
        var dia = parseInt(bloque.substring(0, 3), 10), anio = 2000 + parseInt(bloque.substring(3, 5), 10);
        if (dia < 1 || dia > 366) { return null; }
        var f = new Date(anio, 0, dia);
        if (f.getFullYear() !== anio) { return null; }
        return f.getDate() + ' ' + MESES[f.getMonth()] + ' ' + anio + ' (' + DIAS_SEMANA[f.getDay()] + ')';
    }
    function fecha(iso) {
        if (!iso) { return '—'; }
        var p = String(iso).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
    }

    var modalR = document.getElementById('modal-rotulo-fc');
    var rc = function (n) { return modalR.querySelector('[data-rc="' + n + '"]'); };
    // Los cuerpos de letra del Excel, en pt; si un valor no entra a lo ancho, se achica hasta que entre.
    var TAMANOS = { sku: 170, lote: 140, venc: 100, cajas: 72 };
    function ajustar(n) {
        var el = rc(n), tam = TAMANOS[n];
        el.style.fontSize = tam + 'pt';
        while (el.scrollWidth > el.clientWidth && tam > 12) { tam -= 2; el.style.fontSize = tam + 'pt'; }
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('.btn-rotulo-fc');
        if (!b || !modalR) { return; }
        var d = JSON.parse(b.dataset.rotulo || '{}');
        rc('sku').textContent = d.sku || '';
        rc('desc').textContent = d.descripcion || '';
        rc('lote').textContent = d.lote || '';
        rc('venc').textContent = fecha(d.vence);
        rc('cajas').textContent = d.cajas ? d.cajas : '—';
        var hoy = new Date();
        rc('hoy').textContent = String(hoy.getDate()).padStart(2, '0') + '/' + String(hoy.getMonth() + 1).padStart(2, '0') + '/' + hoy.getFullYear();
        var j = diaJuliano(d.lote);
        rc('juliano').textContent = j || '—';
        rc('nota').textContent = j ? '' : 'El día juliano sale del lote leído como máquina + día del año (3 dígitos) + año (2 dígitos), '
            + 'ej. "T2 L 1113726" → día 137 de 2026. Este lote no tiene ese bloque numérico, así que queda en blanco.';
        rc('nota').classList.toggle('rc-nota-alerta', !j);
        modalR.classList.add('active');
        // Después de mostrarlo: con el modal oculto los anchos miden 0.
        requestAnimationFrame(function () { Object.keys(TAMANOS).forEach(ajustar); });
    });

    // IMPRIMIR: se oculta todo menos el rótulo, que sale a tamaño real en una hoja horizontal. El
    // rótulo se MUEVE a un contenedor hijo de <body> (un elemento con un ancestro oculto no se
    // puede volver a mostrar) y se devuelve al terminar.
    var bImprimir = document.getElementById('btn-imprimir-rc');
    if (bImprimir) {
        bImprimir.addEventListener('click', function () {
            var hoja = document.getElementById('rc-hoja');
            var padre = hoja.parentNode;
            var area = document.getElementById('rc-area-impresion');
            if (!area) {
                area = document.createElement('div');
                area.id = 'rc-area-impresion';
                document.body.appendChild(area);
            }
            area.appendChild(hoja);
            document.body.classList.add('imprimiendo-rc');
            var restaurar = function () {
                padre.appendChild(hoja);
                document.body.classList.remove('imprimiendo-rc');
                window.removeEventListener('afterprint', restaurar);
            };
            window.addEventListener('afterprint', restaurar);
            window.print();
            setTimeout(function () { if (document.body.classList.contains('imprimiendo-rc')) { restaurar(); } }, 1000);
        });
    }
})();
