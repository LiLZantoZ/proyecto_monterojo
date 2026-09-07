// assets/js/modales.js
// Abrir y cerrar los modales de cualquier pantalla.
//
// Funciona por delegación sobre el documento y por atributos (data-abrir / data-cerrar), no con
// un onclick escrito en cada botón: así un modal nuevo no necesita ni una línea de JavaScript
// propia, y las filas que se agreguen después de cargar la página quedan cubiertas igual.
//
// Los modales se abren y se cierran SOLO con la clase .active. Tocar style.display saltea la
// transición de entrada y el modal aparece de golpe.

(function () {
    'use strict';

    function abrir(modal) {
        if (!modal) { return; }
        modal.classList.add('active');

        // El foco se lleva al primer campo utilizable: quien abre un modal para subir un archivo
        // no debería tener que ir a buscar el campo con el mouse.
        var primero = modal.querySelector('input:not([type="hidden"]), select, textarea, button');
        if (primero) { primero.focus(); }
    }

    function cerrar(modal) {
        if (modal) { modal.classList.remove('active'); }
    }

    document.addEventListener('click', function (e) {
        var disparador = e.target.closest('[data-abrir]');
        if (disparador) {
            abrir(document.getElementById(disparador.dataset.abrir));
            return;
        }

        if (e.target.closest('[data-cerrar]')) {
            cerrar(e.target.closest('.modal-fondo'));
            return;
        }

        // Clic en el fondo oscuro (y no dentro de la caja) también cierra
        if (e.target.classList.contains('modal-fondo')) {
            cerrar(e.target);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        document.querySelectorAll('.modal-fondo.active').forEach(cerrar);
    });
})();
