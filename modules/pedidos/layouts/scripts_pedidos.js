// modules/pedidos/layouts/scripts_pedidos.js
// Pedidos: los filtros que aplican solos y el aviso de "subiendo" mientras se envía un Excel.

(function () {
    'use strict';

    // La sede filtra al cambiarla, sin tocar "Filtrar". El texto de "Buscar" necesita Enter o el botón.
    var formFiltros = document.querySelector('form.filtros');
    if (formFiltros) {
        formFiltros.querySelectorAll('select').forEach(function (campo) {
            campo.addEventListener('change', function () {
                if (formFiltros.requestSubmit) { formFiltros.requestSubmit(); }
                else { formFiltros.submit(); }
            });
        });
    }

    // Los archivos tardan unos segundos: el botón se deshabilita para que no se envíen dos veces.
    document.querySelectorAll('form').forEach(function (form) {
        var boton = form.querySelector('.btn-enviando');
        if (!boton) { return; }
        // Vaciar la tabla pide confirmación con su data-confirmar (assets/js/dialogos.js), antes que esto.
        form.addEventListener('submit', function () {
            boton.disabled = true;
            boton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (boton.dataset.enviando || 'Subiendo…');
        });
    });

    // En "Vaciar tabla" el botón se apaga si no hay nada elegido.
    document.querySelectorAll('form[data-vaciar]').forEach(function (form) {
        var boton = form.querySelector('[type="submit"]');
        var revisar = function () {
            boton.disabled = !form.querySelector('input[name="partes[]"]:checked');
        };
        form.addEventListener('change', revisar);
        revisar();
    });
    // Los botones de subir inventario con data-sede (la sede que recomienda el sistema, 2026-10-06)
    // dejan esa sede elegida en el modal; el usuario la puede cambiar.
    document.querySelectorAll('[data-abrir="modal-inventario"][data-sede]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            var sede = document.getElementById('i-sede');
            if (sede) { sede.value = boton.dataset.sede || ''; }
        });
    });
})();
