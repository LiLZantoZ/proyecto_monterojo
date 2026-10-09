// modules/notificaciones/layouts/scripts_campana.js
// La campana de notificaciones: abre y cierra el panel, dibuja los avisos personales y los
// vencimientos, y marca un aviso como leído sin recargar la pantalla.

(function () {
    'use strict';

    var C = window.CAMPANA;
    var boton = document.getElementById('campana-btn');
    var panel = document.getElementById('campana-panel');
    var cuerpo = document.getElementById('campana-cuerpo');
    var numero = document.getElementById('campana-numero');
    if (!C || !boton || !panel) { return; }

    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    var TEXTO_SEMAFORO = { vencido: 'Vencido', por_vencer: 'Vence pronto', proximo: 'Por vencer' };

    function dias(d) {
        if (d < 0) { return 'Venció hace ' + (-d) + ' día(s)'; }
        return d === 0 ? 'Vence hoy' : 'Faltan ' + d + ' día(s)';
    }

    function dibujar() {
        var html = '';
        C.datos.personales.forEach(function (n) {
            html += '<div class="campana-item campana-personal" data-id="' + n.id + '">'
                + '<i class="fa-solid ' + (n.tipo === 'producto_nuevo' ? 'fa-box' : 'fa-bell') + '"></i>'
                + '<div><div>' + esc(n.mensaje) + '</div><small>' + esc(n.fecha) + (n.emisor ? ' · ' + esc(n.emisor) : '') + '</small></div>'
                + '<button type="button" class="campana-leida" title="Marcar como leído" data-leer="' + n.id + '"><i class="fa-solid fa-check"></i></button></div>';
        });
        C.datos.vencimientos.forEach(function (v) {
            var donde = v.ubicacion
                ? (C.urlPosiciones ? '<a href="' + esc(C.urlPosiciones + encodeURIComponent(v.ubicacion)) + '">' + esc(v.ubicacion) + '</a>' : esc(v.ubicacion))
                : 'Sin ubicar';
            html += '<div class="campana-item campana-venc campana-' + esc(v.semaforo) + '">'
                + '<i class="fa-solid fa-hourglass-half"></i>'
                + '<div><div><strong>' + esc(TEXTO_SEMAFORO[v.semaforo] || '') + ':</strong> SKU ' + esc(v.sku) + (v.producto ? ' — ' + esc(v.producto) : '') + '</div>'
                + '<small>Lote ' + esc(v.lote) + ' · vence ' + esc(v.vence) + ' (' + dias(v.dias) + ') · ' + donde + '</small></div></div>';
        });
        cuerpo.innerHTML = html || '<p class="campana-vacia">No hay notificaciones pendientes.</p>';
        var total = C.datos.personales.length + C.datos.vencimientos.length;
        numero.hidden = total === 0;
        numero.textContent = total > 99 ? '99+' : String(total);
    }

    function abrir(si) {
        panel.hidden = !si;
        boton.setAttribute('aria-expanded', si ? 'true' : 'false');
    }

    boton.addEventListener('click', function (e) {
        e.stopPropagation();
        abrir(panel.hidden);
    });
    document.addEventListener('click', function (e) {
        if (!panel.hidden && !e.target.closest('#campana-flotante')) { abrir(false); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { abrir(false); }
    });

    // Marcar como leído: se quita de la lista apenas el servidor confirma.
    cuerpo.addEventListener('click', function (e) {
        var b = e.target.closest('[data-leer]');
        if (!b) { return; }
        b.disabled = true;
        var datos = new FormData();
        datos.append('accion', 'marcar_leida');
        datos.append('id', b.dataset.leer);
        datos.append('csrf_token', C.csrf);
        fetch(C.urlAcciones, { method: 'POST', body: datos, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function () {
                C.datos.personales = C.datos.personales.filter(function (n) { return String(n.id) !== b.dataset.leer; });
                dibujar();
            })
            .catch(function () { b.disabled = false; });
    });

    dibujar();
})();
