// assets/js/desplegables.js
// Evita que los controles que viven DENTRO de la cabecera de un <details> lo abran o lo cierren.
//
// El problema: la cabecera es un <summary>, y el navegador abre o cierra el desplegable con
// cualquier clic que le llegue. Un enlace de descarga o un campo de texto puestos ahí adentro
// funcionan igual, pero de paso pliegan el grupo — o lo despliegan entero solo por hacer clic en
// una casilla. Cortando la propagación en el control, el clic hace únicamente lo suyo.
//
// Va por delegación en el documento y no elemento por elemento: así los grupos que se agreguen
// después (o los que renderice otra pantalla) quedan cubiertos sin tocar este archivo.

(function () {
    'use strict';

    var CONTROLES = 'a, button, input, select, textarea, label';

    document.addEventListener('click', function (e) {
        var cabecera = e.target.closest('.grupo-cabecera');
        if (!cabecera) { return; }

        if (e.target.closest(CONTROLES)) {
            e.stopPropagation();
        }
    });

    // La barra espaciadora y Enter también activan un <summary>. Sin esto, escribir un espacio
    // dentro del campo del pedido SAP cerraría el grupo mientras se escribe.
    document.addEventListener('keydown', function (e) {
        if (e.key !== ' ' && e.key !== 'Enter' && e.key !== 'Spacebar') { return; }
        if (!e.target.closest('.grupo-cabecera')) { return; }

        if (e.target.matches('input, select, textarea')) {
            e.stopPropagation();
        }
    });
})();
