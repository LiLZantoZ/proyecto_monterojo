// modules/ordenes_compra/layouts/scripts_ordenes_compra.js
// Despliega y pliega el detalle de productos de cada orden.
//
// Es una fila aparte (<tr hidden>) y no un <details> adentro de la celda: así el detalle ocupa el
// ancho entero de la tabla y sus columnas se leen alineadas, en vez de apretadas en la celda de la
// orden.

(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-detalle-oc');
        if (!boton) { return; }

        var detalle = document.getElementById(boton.getAttribute('aria-controls'));
        if (!detalle) { return; }

        var abrir = detalle.hidden;
        detalle.hidden = !abrir;
        boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        boton.closest('tr').classList.toggle('fila-desplegada', abrir);
    });
})();
