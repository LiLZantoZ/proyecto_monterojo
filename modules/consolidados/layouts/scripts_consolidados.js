// modules/consolidados/layouts/scripts_consolidados.js
// La selección de varios CEDI y la barra de descargas de abajo. Es la misma idea que la de Picking
// (scripts_picking.js), pero acá lo que se tilda es un CEDI entero: Consolidados no tiene una fila
// por pedido, tiene un grupo por CEDI.

(function () {
    'use strict';

    var barra  = document.getElementById('barra-seleccion');
    var conteo = document.getElementById('barra-conteo');
    var campos = document.getElementById('campos-seleccion');
    var form   = document.getElementById('form-seleccion');
    var todos  = document.getElementById('chk-todos-cedi');

    // Sin CEDI en pantalla no se dibuja la barra y no hay nada que hacer.
    if (!barra || !conteo || !campos || !form) { return; }

    function casillas() {
        return [].slice.call(document.querySelectorAll('.chk-cedi'));
    }

    function tildadas() {
        return casillas().filter(function (chk) { return chk.checked; });
    }

    function refrescar() {
        var lista = tildadas();
        var total = casillas().length;

        // La cabecera del CEDI tildado cambia de tono: se ve qué está elegido sin abrir nada.
        casillas().forEach(function (chk) {
            chk.closest('.grupo-desplegable').classList.toggle('grupo-seleccionado', chk.checked);
        });

        // "Todos" refleja el estado real: marcado si están todos, indeterminado si hay algunos.
        if (todos) {
            todos.checked = total > 0 && lista.length === total;
            todos.indeterminate = lista.length > 0 && lista.length < total;
        }

        barra.hidden = lista.length === 0;
        conteo.textContent = lista.length;

        // Los campos ocultos se rehacen en cada cambio: el POST lleva siempre exactamente lo que
        // está tildado ahora, en el mismo orden en que se ven los CEDI.
        campos.innerHTML = '';
        lista.forEach(function (chk) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'cedi[]';
            input.value = chk.dataset.cedi;
            campos.appendChild(input);
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('chk-cedi')) {
            refrescar();
        } else if (todos && e.target === todos) {
            casillas().forEach(function (chk) { chk.checked = todos.checked; });
            refrescar();
        }
    });

    document.getElementById('btn-limpiar-seleccion').addEventListener('click', function () {
        casillas().forEach(function (chk) { chk.checked = false; });
        refrescar();
    });

    // Sin nada tildado el servidor devolvería un aviso en vez del PDF. No debería poder pasar
    // (la barra se oculta), pero la barra la muestra y oculta este mismo script.
    form.addEventListener('submit', function (e) {
        if (tildadas().length === 0) { e.preventDefault(); }
    });

    // Al volver con el botón Atrás el navegador restaura las casillas tildadas, pero no la barra:
    // se recalcula al mostrar la página para que las dos cosas coincidan.
    window.addEventListener('pageshow', refrescar);
    refrescar();
})();
