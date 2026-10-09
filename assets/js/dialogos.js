// assets/js/dialogos.js
// LOS MENSAJES DEL SISTEMA (2026-10-06): avisos, confirmaciones y preguntas con el diseño de
// Monterojo, en lugar de los alert(), confirm() y prompt() del navegador ("localhost dice…"), que
// no se pueden diseñar y se ven distintos en cada navegador.
//
//   Dialogo.avisar(texto, { titulo, tipo: 'error' | 'info' | 'exito' })     → Promise (al cerrar)
//   Dialogo.confirmar(texto, { titulo, aceptar, cancelar, peligro })       → Promise<boolean>
//   Dialogo.pedir(texto, { titulo, valor, placeholder, aceptar, maximo })  → Promise<string|null>
//
// Y sin una línea de JavaScript: un <form data-confirmar="¿Seguro…?"> pide la confirmación antes de
// enviarse (data-peligro pinta el botón de rojo; data-titulo y data-aceptar cambian los textos).
//
// Los mensajes salen de a uno (si se piden dos seguidos, el segundo espera al primero). Escape
// cancela solo el mensaje, no el modal que haya debajo; Enter acepta.

(function () {
    'use strict';

    var cola = Promise.resolve();
    var abierto = null;   // el mensaje en pantalla: {cancelar}

    var ICONOS = {
        error: 'fa-circle-exclamation', exito: 'fa-circle-check', info: 'fa-circle-info',
        confirmar: 'fa-circle-question', peligro: 'fa-triangle-exclamation', pedir: 'fa-pen-to-square'
    };

    function elemento(etiqueta, clase, texto) {
        var el = document.createElement(etiqueta);
        if (clase) { el.className = clase; }
        if (texto !== undefined) { el.textContent = texto; }
        return el;
    }

    // Arma y muestra un mensaje. Devuelve una promesa con lo que contestó la persona.
    function mostrar(o) {
        return new Promise(function (resolver) {
            var anterior = document.activeElement;
            var fondo = elemento('div', 'dialogo-fondo dialogo-' + o.tipo);
            var caja = elemento('div', 'modal-caja dialogo-caja');
            caja.setAttribute('role', o.tipo === 'pedir' || o.cancelar !== false ? 'alertdialog' : 'dialog');
            caja.setAttribute('aria-modal', 'true');

            var cabecera = elemento('div', 'modal-cabecera');
            var titulo = elemento('h2');
            titulo.appendChild(elemento('i', 'fa-solid ' + (ICONOS[o.icono] || ICONOS.info)));
            titulo.appendChild(document.createTextNode(' ' + o.titulo));
            titulo.id = 'dialogo-titulo-' + Date.now();
            caja.setAttribute('aria-labelledby', titulo.id);
            cabecera.appendChild(titulo);

            var cuerpo = elemento('div', 'modal-cuerpo');
            if (o.texto) { cuerpo.appendChild(elemento('p', 'dialogo-texto', o.texto)); }
            var campo = null;
            if (o.tipo === 'pedir') {
                campo = elemento('input', 'dialogo-campo');
                campo.type = 'text';
                campo.value = o.valor || '';
                campo.placeholder = o.placeholder || '';
                campo.maxLength = o.maximo || 120;
                campo.setAttribute('aria-label', o.texto || o.titulo);
                cuerpo.appendChild(campo);
            }

            var pie = elemento('div', 'modal-pie');
            var bCancelar = null;
            if (o.cancelar !== false) {
                bCancelar = elemento('button', 'btn', o.cancelar || 'Cancelar');
                bCancelar.type = 'button';
                pie.appendChild(bCancelar);
            }
            var bAceptar = elemento('button', 'btn ' + (o.peligro ? 'btn-peligro' : 'btn-primario'), o.aceptar || 'Aceptar');
            bAceptar.type = 'button';
            pie.appendChild(bAceptar);

            caja.appendChild(cabecera);
            caja.appendChild(cuerpo);
            caja.appendChild(pie);
            fondo.appendChild(caja);
            document.body.appendChild(fondo);

            function cerrar(respuesta) {
                document.removeEventListener('keydown', teclas, true);
                abierto = null;
                fondo.classList.remove('active');
                setTimeout(function () { fondo.remove(); }, 200);
                if (anterior && anterior.focus) { try { anterior.focus(); } catch (e) { /* ya no está */ } }
                resolver(respuesta);
            }
            function aceptar() {
                if (o.tipo === 'pedir') {
                    var v = campo.value.trim();
                    if (!v) { campo.focus(); campo.classList.add('dialogo-campo-vacio'); return; }
                    cerrar(v);
                } else {
                    cerrar(true);
                }
            }
            function cancelar() { cerrar(o.tipo === 'pedir' ? null : false); }

            // En captura y cortando la propagación: Escape cierra SOLO este mensaje, no el modal
            // que haya abierto debajo (assets/js/modales.js cierra todos con Escape).
            function teclas(e) {
                if (e.key === 'Escape') {
                    e.preventDefault(); e.stopImmediatePropagation();
                    if (o.cancelar === false) { cerrar(true); } else { cancelar(); }
                } else if (e.key === 'Enter' && (e.target === campo || e.target === bAceptar || e.target === fondo || e.target === document.body)) {
                    e.preventDefault(); e.stopImmediatePropagation();
                    aceptar();
                } else if (e.key === 'Tab') {
                    // El foco no se escapa del mensaje.
                    var focos = [campo, bCancelar, bAceptar].filter(Boolean);
                    var i = focos.indexOf(document.activeElement);
                    e.preventDefault();
                    focos[(i + (e.shiftKey ? focos.length - 1 : 1)) % focos.length].focus();
                }
            }
            document.addEventListener('keydown', teclas, true);
            bAceptar.addEventListener('click', aceptar);
            if (bCancelar) { bCancelar.addEventListener('click', cancelar); }
            fondo.addEventListener('mousedown', function (e) {
                if (e.target === fondo && o.cancelar !== false) { cancelar(); }
            });
            if (campo) {
                campo.addEventListener('input', function () { campo.classList.remove('dialogo-campo-vacio'); });
            }

            abierto = { cancelar: cancelar };
            requestAnimationFrame(function () {
                fondo.classList.add('active');
                (campo || bAceptar).focus();
                if (campo) { campo.select(); }
            });
        });
    }

    // De a uno: el siguiente espera a que se cierre el anterior.
    function enCola(o) {
        var p = cola.then(function () { return mostrar(o); });
        cola = p.catch(function () {});
        return p;
    }

    var tituloAviso = { error: 'No se pudo', exito: 'Listo', info: 'Aviso' };

    window.Dialogo = {
        avisar: function (texto, op) {
            op = op || {};
            var tipo = op.tipo || 'error';
            return enCola({ tipo: tipo, icono: tipo, titulo: op.titulo || tituloAviso[tipo] || 'Aviso', texto: texto,
                            aceptar: op.aceptar || 'Entendido', cancelar: false });
        },
        confirmar: function (texto, op) {
            op = op || {};
            return enCola({ tipo: 'confirmar', icono: op.peligro ? 'peligro' : 'confirmar', titulo: op.titulo || 'Confirmar',
                            texto: texto, aceptar: op.aceptar || 'Sí, continuar', cancelar: op.cancelar || 'Cancelar', peligro: !!op.peligro });
        },
        pedir: function (texto, op) {
            op = op || {};
            return enCola({ tipo: 'pedir', icono: 'pedir', titulo: op.titulo || 'Escribí el dato', texto: texto,
                            valor: op.valor, placeholder: op.placeholder, maximo: op.maximo, aceptar: op.aceptar || 'Guardar',
                            cancelar: op.cancelar || 'Cancelar' });
        },
        abierto: function () { return !!abierto; }
    };

    // FORMULARIOS CON data-confirmar: se pide la confirmación con este mensaje antes de enviarlos.
    // Va en captura y corta los demás "submit" hasta que la persona confirme: así un script que
    // apaga el botón al enviar no lo apaga si al final se cancela.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute || !form.hasAttribute('data-confirmar')) { return; }
        if (form.dataset.confirmado === '1') { delete form.dataset.confirmado; return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var boton = e.submitter || null;
        window.Dialogo.confirmar(form.dataset.confirmar, {
            titulo: form.dataset.titulo || 'Confirmar',
            aceptar: form.dataset.aceptar || 'Sí, continuar',
            peligro: form.hasAttribute('data-peligro')
        }).then(function (si) {
            if (!si) { return; }
            form.dataset.confirmado = '1';
            if (form.requestSubmit) { form.requestSubmit(boton && boton.form === form ? boton : undefined); } else { form.submit(); }
        });
    }, true);
})();
