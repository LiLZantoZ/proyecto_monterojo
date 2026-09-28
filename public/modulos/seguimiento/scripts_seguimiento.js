// modules/seguimiento/layouts/scripts_seguimiento.js
// Abre el detalle completo de un pedido en un modal.
//
// El detalle de un pedido puede ser largo ("Desde la fecha de vencimiento se…") y en la tabla se
// recorta para que la fila no crezca de alto. Al hacer clic en la fila —o en el ojo— se muestra
// todo, junto con el resto de los datos, sin ir a buscar el texto al portapapeles ni al title.
//
// Todo el pedido viaja en el atributo data-pedido de la fila, ya con las fechas formateadas desde
// PHP, así que abrir el modal no pega a la base: solo lee y pinta.

(function () {
    'use strict';

    // Los filtros aplican SOLOS al cambiar un desplegable (Transportadora, Estado) o una fecha
    // (Desde, Hasta): no hace falta tocar "Filtrar". El texto de "Buscar" sigue necesitando Enter o
    // el botón, para no recargar la página en cada tecla. Va lo primero y aparte del resto del
    // script para que funcione aunque en la página no haya tabla (p. ej. sin resultados).
    (function () {
        var formFiltros = document.querySelector('form.filtros');
        if (!formFiltros) { return; }
        formFiltros.querySelectorAll('select, input[type="date"]').forEach(function (campo) {
            campo.addEventListener('change', function () {
                if (formFiltros.requestSubmit) { formFiltros.requestSubmit(); }
                else { formFiltros.submit(); }
            });
        });
    })();

    var modal = document.getElementById('modal-detalle');
    if (!modal) { return; }

    // ocultarSiVacio: para las filas de la lista (dt/dd), cuando no hay dato se esconde la fila
    // entera en vez de mostrar un "—" (así el detalle de un pedido de SAP no muestra Transportadora,
    // Guía, Despacho… vacíos, y el de una transportadora no muestra NIT, Valor neto…).
    function ponerTexto(id, valor, ocultarSiVacio) {
        var el = document.getElementById(id);
        if (!el) { return; }
        var vacio = !valor || String(valor).trim() === '';
        if (ocultarSiVacio && el.parentElement) {
            el.parentElement.style.display = vacio ? 'none' : '';
        }
        el.textContent = vacio ? '—' : valor;
        el.classList.toggle('sin-dato', vacio);
    }

    function abrirDetalle(fila) {
        var datos;
        try {
            datos = JSON.parse(fila.dataset.pedido || '{}');
        } catch (e) {
            return;
        }

        // El chip de estado, con su color: se le pone la clase del grupo (estado-verde, etc.).
        var chip = document.getElementById('det-estado');
        chip.className = 'chip-estado ' + (datos.grupo || 'estado-gris');
        chip.textContent = (datos.estado && datos.estado.trim() !== '') ? datos.estado : 'sin estado';

        // La fuente: de dónde salió el dato (manual / excel / la integración que sea).
        var fuente = document.getElementById('det-fuente');
        fuente.textContent = datos.fuente ? ('cargado por: ' + datos.fuente) : '';

        ponerTexto('det-transportadora', datos.transportadora, true);
        ponerTexto('det-factura', datos.factura, true);
        ponerTexto('det-guia', datos.guia, true);
        ponerTexto('det-destino', datos.destino, true);
        ponerTexto('det-direccion', datos.direccion, true);
        ponerTexto('det-nit', datos.nit, true);
        ponerTexto('det-valor', datos.valor_neto, true);
        ponerTexto('det-referencia', datos.referencia, true);
        ponerTexto('det-pedido', datos.pedido_cliente, true);
        ponerTexto('det-bodega', datos.bodega, true);
        ponerTexto('det-fecha-guia', datos.fecha_guia, true);
        ponerTexto('det-despacho', datos.fecha_despacho, true);
        ponerTexto('det-entrega', datos.fecha_entrega, true);
        // El detalle (bloque de texto) siempre se muestra, con "—" si está vacío.
        ponerTexto('det-detalle', datos.detalle, false);

        modal.classList.add('active');
    }

    // Un solo listener sobre la tabla, por delegación: sirve para todas las filas y para las que
    // se rendericen después. Se ignora el clic sobre los botones de acción (ver/eliminar) para no
    // abrir el detalle al querer borrar, y sobre el de eliminar para no tragarse su submit.
    document.addEventListener('click', function (e) {
        var fila = e.target.closest('.fila-pedido');
        if (!fila) { return; }

        // El botón de eliminar (y su form) hace lo suyo; el del ojo abre el detalle igual que la fila.
        if (e.target.closest('form')) { return; }

        abrirDetalle(fila);
    });

    // Enter o barra espaciadora sobre una fila enfocada (accesibilidad): abre el detalle.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') { return; }
        var fila = document.activeElement;
        if (fila && fila.classList && fila.classList.contains('fila-pedido')) {
            e.preventDefault();
            abrirDetalle(fila);
        }
    });

    // ---------------------------------------------------------------------------------------
    // SELECCIÓN MASIVA Y BORRADO EN LOTE
    //
    // Las casillas de cada fila alimentan una barra flotante que borra todas las tildadas de una.
    // El clic en una casilla NO abre el detalle (el listener de arriba ignora clics con Shift/en
    // controles: acá se corta la propagación para asegurarlo).
    // ---------------------------------------------------------------------------------------
    var chkTodos  = document.getElementById('chk-todos');
    var barra     = document.getElementById('barra-seleccion');
    var conteo    = document.getElementById('barra-conteo');
    var campos    = document.getElementById('campos-eliminar-masivo');
    var formMasivo = document.getElementById('form-eliminar-masivo');

    function casillas() {
        return Array.prototype.slice.call(document.querySelectorAll('.chk-fila'));
    }

    function refrescarSeleccion() {
        var todas = casillas();
        var marcadas = todas.filter(function (c) { return c.checked; });

        // Resaltar las filas tildadas.
        todas.forEach(function (c) {
            var fila = c.closest('tr');
            if (fila) { fila.classList.toggle('fila-seleccionada', c.checked); }
        });

        // La casilla de "todos": tildada si están todas, en guion (indeterminada) si hay algunas.
        if (chkTodos) {
            chkTodos.checked = marcadas.length > 0 && marcadas.length === todas.length;
            chkTodos.indeterminate = marcadas.length > 0 && marcadas.length < todas.length;
        }

        // La barra: aparece con lo tildado y rearma los inputs ocultos del formulario de borrado.
        if (barra && conteo && campos) {
            conteo.textContent = marcadas.length;
            barra.hidden = marcadas.length === 0;
            campos.innerHTML = '';
            marcadas.forEach(function (c) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = c.value;
                campos.appendChild(input);
            });
        }
    }

    // Cambios en cualquier casilla de fila (delegado, cubre lo que se re-renderice).
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('chk-fila')) {
            refrescarSeleccion();
        } else if (e.target === chkTodos) {
            var v = chkTodos.checked;
            casillas().forEach(function (c) { c.checked = v; });
            refrescarSeleccion();
        }
    });

    // Un clic en la casilla no debe abrir el detalle de la fila.
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('chk-fila') || e.target.classList.contains('chk-todos')) {
            e.stopPropagation();
        }
    }, true);

    var btnLimpiar = document.getElementById('btn-limpiar-seleccion');
    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', function () {
            casillas().forEach(function (c) { c.checked = false; });
            refrescarSeleccion();
        });
    }

    if (formMasivo) {
        formMasivo.addEventListener('submit', function (e) {
            var n = casillas().filter(function (c) { return c.checked; }).length;
            if (n === 0) { e.preventDefault(); return; }
            if (!confirm('¿Eliminar ' + n + ' pedido(s) seleccionado(s)? No se puede deshacer.')) {
                e.preventDefault();
            }
        });
    }
})();
