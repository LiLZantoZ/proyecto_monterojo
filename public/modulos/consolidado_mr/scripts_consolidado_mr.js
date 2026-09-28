// modules/consolidado_mr/layouts/scripts_consolidado_mr.js
// Filtros que aplican solos y aviso de "importando" mientras se sube el Excel.

(function () {
    'use strict';

    // Los desplegables (Cliente, Población) y las fechas filtran al cambiar, sin tocar "Filtrar".
    // El texto de "Buscar" sigue necesitando Enter o el botón, para no recargar en cada tecla.
    var formFiltros = document.querySelector('form.filtros');
    if (formFiltros) {
        formFiltros.querySelectorAll('select, input[type="date"]').forEach(function (campo) {
            campo.addEventListener('change', function () {
                if (formFiltros.requestSubmit) { formFiltros.requestSubmit(); }
                else { formFiltros.submit(); }
            });
        });
    }

    // El archivo es grande y tarda: se deshabilita el botón para que no se envíe dos veces y se
    // avisa que está trabajando.
    var formImportar = document.getElementById('form-importar-mr');
    var botonImportar = document.getElementById('btn-importar-mr');
    if (formImportar && botonImportar) {
        formImportar.addEventListener('submit', function () {
            botonImportar.disabled = true;
            botonImportar.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importando…';
        });
    }
})();
