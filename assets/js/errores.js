// assets/js/errores.js
// MANEJO DE ERRORES EN EL NAVEGADOR (2026-10-14, pedido del usuario). Va en el <head> de todas las
// pantallas, antes que cualquier otro script, porque envuelve fetch().
//
// Hasta acá, cuando un botón que habla con el servidor fallaba, cada pantalla mostraba su propio
// "No se pudo contactar al servidor", fuera cual fuera el problema: la red caída, la sesión vencida
// o un error del servidor que devolvía una página en vez de datos. Ahora:
//
//   · fetch() envuelto: si no hay red, o si la respuesta no son los datos que se esperaban
//     (r.json() falla), el error que llega al .catch() de la pantalla dice QUÉ pasó y QUÉ hacer.
//   · Errores.texto(e, 'texto de siempre'): lo usan los .catch() de las pantallas; devuelve la
//     explicación si la hay, y si no, el texto de siempre.
//   · Lo que no atrapa ninguna pantalla (un error suelto en un script) se muestra en un aviso.
//
// Cuando el error viene del servidor, su JSON ya trae el texto con la recomendación (ver
// config/errores.php): las pantallas lo muestran como siempre, en d.error / d.mensaje.

(function () {
    'use strict';

    function crear(mensaje, recomendacion) {
        var e = new Error(mensaje);
        e.name = 'ErrorSistema';
        e.mensajeUsuario = mensaje + (recomendacion ? ' Recomendación: ' + recomendacion : '');
        return e;
    }

    var SIN_RED = ['No hay conexión con el servidor.',
                   'Revisá que el equipo tenga red (wifi o cable) y que el servidor esté encendido (en XAMPP, Apache en verde). Después volvé a intentarlo.'];

    // Por qué una respuesta no trajo los datos esperados, según cómo vino.
    function explicarRespuesta(r) {
        var url = String(r.url || '');
        if (r.redirected && /\/login/i.test(url)) {
            return ['Tu sesión se cerró (por inactividad o porque se inició en otro lado).',
                    'Volvé a iniciar sesión. Lo que no alcanzó a guardarse hay que hacerlo de nuevo.'];
        }
        if (r.ok) {
            return ['El servidor devolvió una página en lugar de los datos que esperaba esta pantalla.',
                    'Recargá la página (F5) y volvé a intentarlo. Si se repite, avisale al administrador.'];
        }
        switch (r.status) {
            case 400: return ['El servidor no entendió lo que se le mandó.', 'Recargá la página (F5) y volvé a intentarlo.'];
            case 401: return ['Tu sesión se cerró.', 'Volvé a iniciar sesión e intentalo de nuevo.'];
            case 403: return ['No tenés permiso para esto, o la página quedó abierta demasiado tiempo.', 'Recargá la página (F5). Si sigue, pedile el permiso al administrador.'];
            case 404: return ['El servidor no encontró lo que se pidió (puede que ya no exista).', 'Recargá la página (F5) para ver la información actualizada.'];
            case 413: return ['Lo que se intentó enviar es demasiado grande para el servidor.', 'Probá con un archivo más chico o con menos datos de una vez.'];
            case 419: return ['La página estuvo abierta mucho tiempo y su permiso de envío venció.', 'Recargá la página (F5) y volvé a hacer la operación.'];
            case 429: return ['Se hicieron demasiados intentos seguidos.', 'Esperá un minuto y volvé a intentarlo.'];
            case 500: return ['El servidor tuvo un error al hacer la operación.', 'Volvé a intentarlo. Si se repite, avisale al administrador indicando qué estabas haciendo.'];
            case 502: case 503: return ['El servidor no está disponible en este momento.', 'Esperá un momento y volvé a intentarlo. Si sigue, revisá que el servidor esté encendido.'];
            case 504: return ['La operación tardó demasiado y se cortó.', 'Probá con menos datos de una vez (un archivo más chico o menos fechas).'];
        }
        return ['El servidor respondió algo inesperado' + (r.status ? ' (código ' + r.status + ')' : '') + '.',
                'Recargá la página (F5) y volvé a intentarlo. Si se repite, avisale al administrador.'];
    }

    if (window.fetch && !window.fetch.envuelto) {
        var fetchOriginal = window.fetch;
        window.fetch = function () {
            return fetchOriginal.apply(this, arguments).then(function (r) {
                var json = r.json.bind(r);
                // Si la respuesta no son datos (una página de error, el login, un corte), se explica.
                r.json = function () {
                    return json().catch(function () {
                        var x = explicarRespuesta(r);
                        throw crear(x[0], x[1]);
                    });
                };
                return r;
            }, function (err) {
                if (err && err.name === 'AbortError') { throw err; }   // se canceló a propósito
                throw crear(SIN_RED[0], SIN_RED[1]);
            });
        };
        window.fetch.envuelto = true;
    }

    window.Errores = {
        /** El texto para mostrar: la explicación del error si la hay; si no, el de siempre. */
        texto: function (e, porDefecto) {
            if (e && e.mensajeUsuario) { return e.mensajeUsuario; }
            return porDefecto || 'Algo salió mal. Recomendación: recargá la página (F5) y volvé a intentarlo.';
        },
        crear: crear
    };

    // LO QUE NO ATRAPA NINGUNA PANTALLA. Un aviso por vez y no más de uno cada 8 segundos: un error
    // que se repite en cada tecla no puede tapar la pantalla de avisos.
    var ultimoAviso = 0;
    function avisar(texto) {
        var ahora = Date.now();
        if (ahora - ultimoAviso < 8000) { return; }
        ultimoAviso = ahora;
        if (window.Dialogo && window.Dialogo.avisar) {
            window.Dialogo.avisar(texto, { titulo: 'Algo salió mal' });
        }
    }

    window.addEventListener('unhandledrejection', function (ev) {
        var e = ev.reason;
        if (e && e.name === 'AbortError') { return; }
        avisar(Errores.texto(e, 'Una operación de esta pantalla no terminó bien. Recomendación: recargá la página (F5) y volvé a intentarlo.'));
    });

    window.addEventListener('error', function (ev) {
        // Solo los errores de los scripts del sistema (no los de una extensión del navegador ni los
        // de una imagen que no cargó).
        if (!ev.filename || ev.filename.indexOf(location.origin) !== 0) { return; }
        avisar('Algo falló en esta pantalla. Recomendación: recargá la página (F5). Si se repite, avisale al administrador qué estabas haciendo.');
    });
})();
