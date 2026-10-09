// modules/chatbot/layouts/chatbot.js
// El globo del asistente (MonteBot, 2026-10-07, traído de NutriBot de Nutrium). Abre y cierra el panel,
// carga la conversación guardada, manda las preguntas, y el menú "/" de acciones: la lista sale del
// servidor (lo que el rol puede ejecutar) y cada acción se llena en un formulario, que ejecuta PHP.
// Todo el texto que llega del servidor se pone con textContent: nunca se arma HTML con datos.

(function () {
    'use strict';

    var burbuja = document.getElementById('chatbotBurbuja');
    if (!burbuja) { return; }
    var URL_API = burbuja.dataset.url;
    var CSRF = burbuja.dataset.csrf;
    var panel = document.getElementById('chatbotPanel');
    var mensajes = document.getElementById('chatbotMensajes');
    var form = document.getElementById('chatbotForm');
    var input = document.getElementById('chatbotInput');
    var botonEnviar = document.getElementById('chatbotEnviar');
    var panelAcciones = document.getElementById('chatbotAcciones');
    var listaAcciones = document.getElementById('chatbotAccionesLista');
    var abierto = false, cargado = false, ocupado = false, acciones = null;

    var SUGERENCIAS = [
        '¿Cómo va la facturación del Consolidado MR?',
        '¿Qué productos vencen en los próximos 30 días?',
        '¿Cuántas posiciones libres hay en la bodega?'
    ];

    function pedir(accion, cuerpo) {
        var opciones = cuerpo === undefined ? {} : {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF }, cuerpo))
        };
        opciones.headers = Object.assign({ 'Accept': 'application/json' }, opciones.headers || {});
        opciones.credentials = 'same-origin';
        return fetch(URL_API + '?accion=' + encodeURIComponent(accion), opciones).then(function (r) {
            return r.json().catch(function () { return { error: 'El servidor respondió algo inesperado (código ' + r.status + ').' }; });
        });
    }

    function abrir() {
        abierto = true;
        panel.classList.add('chatbot-panel-visible');
        panel.setAttribute('aria-hidden', 'false');
        burbuja.classList.add('chatbot-burbuja-oculta');
        if (!cargado) { cargado = true; cargarHistorial(); }
        input.focus();
        bajar();
    }

    function cerrar() {
        abierto = false;
        panel.classList.remove('chatbot-panel-visible');
        panel.setAttribute('aria-hidden', 'true');
        burbuja.classList.remove('chatbot-burbuja-oculta');
        ocultarAcciones();
        burbuja.focus();
    }

    function bajar() { mensajes.scrollTop = mensajes.scrollHeight; }

    function bienvenida() {
        mensajes.textContent = '';
        var caja = document.createElement('div');
        caja.className = 'chatbot-bienvenida';
        var icono = document.createElement('i');
        icono.className = 'fa-solid fa-robot';
        var texto = document.createElement('p');
        texto.textContent = 'Hola, soy MonteBot. Te ayudo a consultar facturas, pedidos, productos, posiciones y más. Escribí / para registrar un producto o mover una estiba.';
        var lista = document.createElement('div');
        lista.className = 'chatbot-sugerencias';
        SUGERENCIAS.forEach(function (s) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'chatbot-sugerencia';
            b.textContent = s;
            b.addEventListener('click', function () { input.value = s; enviar(); });
            lista.appendChild(b);
        });
        caja.appendChild(icono);
        caja.appendChild(texto);
        caja.appendChild(lista);
        mensajes.appendChild(caja);
    }

    function agregar(rol, texto, animado, esError) {
        var b = mensajes.querySelector('.chatbot-bienvenida');
        if (b) { b.remove(); }
        var fila = document.createElement('div');
        fila.className = 'chatbot-msg chatbot-msg-' + (rol === 'user' ? 'user' : 'assistant') + (animado ? ' chatbot-msg-nuevo' : '') + (esError ? ' chatbot-msg-error' : '');
        var contenido = document.createElement('div');
        contenido.className = 'chatbot-msg-contenido';
        contenido.textContent = texto;
        fila.appendChild(contenido);
        mensajes.appendChild(fila);
        bajar();
        return fila;
    }

    function escribiendo(mostrar) {
        var actual = document.getElementById('chatbotEscribiendo');
        if (!mostrar) { if (actual) { actual.remove(); } return; }
        var fila = document.createElement('div');
        fila.className = 'chatbot-msg chatbot-msg-assistant chatbot-msg-nuevo';
        fila.id = 'chatbotEscribiendo';
        var c = document.createElement('div');
        c.className = 'chatbot-msg-contenido chatbot-escribiendo';
        c.setAttribute('aria-label', 'MonteBot está escribiendo');
        for (var i = 0; i < 3; i++) { c.appendChild(document.createElement('span')); }
        fila.appendChild(c);
        mensajes.appendChild(fila);
        bajar();
    }

    function cargarHistorial() {
        bienvenida();
        pedir('historial').then(function (d) {
            if (d.mensajes && d.mensajes.length) {
                mensajes.textContent = '';
                d.mensajes.forEach(function (m) { agregar(m.rol, m.mensaje, false); });
            }
        }).catch(function () { /* sin historial: queda la bienvenida */ });
    }

    function bloquear(si) {
        ocupado = si;
        input.disabled = si;
        botonEnviar.disabled = si;
        if (!si) { input.focus(); }
    }

    function enviar() {
        var texto = input.value.trim();
        if (!texto || ocupado) { return; }
        if (texto === '/') { mostrarAcciones(); return; }   // "/" solo abre el menú: no gasta una consulta
        input.value = '';
        ocultarAcciones();
        agregar('user', texto, true);
        escribiendo(true);
        bloquear(true);
        pedir('enviar', { mensaje: texto }).then(function (d) {
            escribiendo(false);
            agregar('assistant', d.respuesta || d.error || 'Sin respuesta.', true, !d.respuesta);
        }).catch(function () {
            escribiendo(false);
            agregar('assistant', 'No se pudo conectar con el servidor. Intentá de nuevo.', true, true);
        }).finally(function () { bloquear(false); });
    }

    // ---------------- El menú "/" ----------------

    function ocultarAcciones() { panelAcciones.hidden = true; }

    function mostrarAcciones() {
        var cargar = acciones ? Promise.resolve(acciones) : pedir('acciones').then(function (d) { acciones = d.acciones || {}; return acciones; });
        cargar.then(function (lista) {
            listaAcciones.textContent = '';
            var nombres = Object.keys(lista);
            if (!nombres.length) {
                var vacio = document.createElement('p');
                vacio.className = 'chatbot-acciones-vacio';
                vacio.textContent = 'Tu rol no tiene acciones disponibles desde el asistente.';
                listaAcciones.appendChild(vacio);
            }
            nombres.forEach(function (nombre) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'chatbot-accion';
                var t = document.createElement('strong');
                t.textContent = lista[nombre].etiqueta;
                var s = document.createElement('span');
                s.textContent = lista[nombre].descripcion;
                b.appendChild(t);
                b.appendChild(s);
                b.addEventListener('click', function () { abrirFormulario(nombre); });
                listaAcciones.appendChild(b);
            });
            panelAcciones.hidden = false;
        }).catch(function () { agregar('assistant', 'No se pudieron cargar las acciones.', true, true); });
    }

    function abrirFormulario(nombre) {
        var accion = acciones[nombre];
        ocultarAcciones();
        input.value = '';
        var fila = agregar('assistant', '', true);
        var caja = fila.querySelector('.chatbot-msg-contenido');
        caja.classList.add('chatbot-formulario');
        fila.style.maxWidth = '100%';
        fila.style.width = '100%';

        var titulo = document.createElement('p');
        titulo.className = 'chatbot-formulario-titulo';
        titulo.textContent = accion.etiqueta;
        caja.appendChild(titulo);

        var f = document.createElement('form');
        var controles = {};
        Object.keys(accion.campos).forEach(function (campo, i) {
            var def = accion.campos[campo];
            var id = 'chatbot-campo-' + nombre + '-' + campo;
            var etiqueta = document.createElement('label');
            etiqueta.htmlFor = id;
            etiqueta.textContent = def.etiqueta + (def.obligatorio ? ' *' : '');
            var control;
            if (def.tipo === 'lista') {
                control = document.createElement('select');
                (def.opciones || []).forEach(function (op) {
                    var o = document.createElement('option');
                    o.value = op;
                    o.textContent = op;
                    control.appendChild(o);
                });
            } else if (def.tipo === 'textarea') {
                control = document.createElement('textarea');
                control.rows = 2;
            } else {
                control = document.createElement('input');
                control.type = def.tipo === 'numero' ? 'number' : (def.tipo === 'fecha' ? 'date' : 'text');
                if (def.tipo === 'numero') { control.min = '1'; }
            }
            control.id = id;
            control.required = !!def.obligatorio;
            f.appendChild(etiqueta);
            f.appendChild(control);
            controles[campo] = control;
        });

        var botones = document.createElement('div');
        botones.className = 'chatbot-formulario-botones';
        var ejecutar = document.createElement('button');
        ejecutar.type = 'submit';
        ejecutar.className = 'chatbot-ejecutar';
        ejecutar.textContent = 'Ejecutar';
        var cancelar = document.createElement('button');
        cancelar.type = 'button';
        cancelar.className = 'chatbot-cancelar';
        cancelar.textContent = 'Cancelar';
        cancelar.addEventListener('click', function () { fila.remove(); input.focus(); });
        botones.appendChild(ejecutar);
        botones.appendChild(cancelar);
        f.appendChild(botones);

        f.addEventListener('submit', function (e) {
            e.preventDefault();
            ejecutar.disabled = true;
            ejecutar.textContent = 'Ejecutando…';
            var datos = {};
            Object.keys(controles).forEach(function (c) { datos[c] = controles[c].value; });
            pedir('ejecutar_accion', { accion_nombre: nombre, datos: datos }).then(function (r) {
                fila.remove();
                agregar('user', '[Acción] ' + accion.etiqueta, true);
                // El resultado que se muestra es el REAL: si falló, lo dice.
                agregar('assistant', r.mensaje || r.error || 'Sin respuesta.', true, r.exito !== true);
            }).catch(function () {
                ejecutar.disabled = false;
                ejecutar.textContent = 'Ejecutar';
                agregar('assistant', 'No se pudo conectar para ejecutar la acción.', true, true);
            });
        });

        caja.appendChild(f);
        bajar();
        var primero = f.querySelector('input, select, textarea');
        if (primero) { primero.focus(); }
    }

    // ---------------- Eventos ----------------

    burbuja.addEventListener('click', abrir);
    document.getElementById('chatbotCerrar').addEventListener('click', cerrar);
    form.addEventListener('submit', function (e) { e.preventDefault(); enviar(); });
    input.addEventListener('input', function () { if (this.value === '/') { mostrarAcciones(); } else { ocultarAcciones(); } });

    document.getElementById('chatbotLimpiar').addEventListener('click', function () {
        Dialogo.confirmar('Se borra toda tu conversación con MonteBot. Lo que hiciste desde el menú de acciones no se deshace.', {
            titulo: 'Borrar la conversación', aceptar: 'Sí, borrar', peligro: true
        }).then(function (si) {
            if (!si) { return; }
            pedir('limpiar', {}).then(function () { bienvenida(); input.focus(); });
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !abierto || document.querySelector('.dialogo-fondo')) { return; }
        if (!panelAcciones.hidden) { ocultarAcciones(); return; }
        cerrar();
    });
})();
