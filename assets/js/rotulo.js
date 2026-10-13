// assets/js/rotulo.js
// El rótulo en pantalla, dibujado en UN solo lugar para las cuatro pantallas que lo muestran:
// Picking, Historial de Pedidos, Generar rótulos y Cajas por punto de venta.
//
// POR QUÉ EXISTE ESTE ARCHIVO
// Hasta el 2026-09-14 cada pantalla tenía su propia copia de htmlRotulo(). Cuando el diseño cambió
// (salió la orden de compra y el código de barras, entró el número del punto de venta y el QR) se
// actualizó una sola copia y el CSS compartido. Las otras tres siguieron armando el HTML viejo
// sobre el CSS nuevo, y el "CAJ 1 DE 3" quedó dibujado encima del producto. Con una sola copia
// eso no puede volver a pasar: el diseño se cambia acá y lo ven las cuatro.
//
// Las otras dos versiones del rótulo están en PHP y no se pueden unificar con esta: el PDF
// (modules/historial/helper_rotulos_pdf.php) y la etiquetadora (helper_rotulos_tspl.php). Si se
// agrega o se mueve un campo acá, hay que moverlo también allá, o la vista previa deja de mostrar
// lo que sale en el papel.
//
// Necesita que la vista defina antes BASE_URL, LOGO_URL y CSRF_TOKEN.

(function () {
    'use strict';

    // La dirección que devuelve los tokens y dibuja el QR. Es una sola para las cuatro pantallas
    // y acepta el permiso de cualquiera de ellas (ver controller_rotulos_enlace.php).
    var URL_ENLACES = BASE_URL + '/rotulos/enlaces';

    function esc(texto) {
        return String(texto == null ? '' : texto).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // El cuerpo de letra de un valor, en milímetros, según cuán largo sea el texto.
    //
    // Es la copia EXACTA de cuerpoValorPdf() en helper_rotulos_pdf.php, y el equivalente de
    // textoQueEntre() en la etiquetadora: los tres achican la letra antes que partir el texto en
    // dos renglones. Si se partiera, el rótulo crecería de alto y el último campo quedaría cortado.
    // Si se cambia algún número acá, hay que cambiarlo también en el PHP.
    function cuerpoValor(texto, escala) {
        var largo = String(texto == null ? '' : texto).trim().length;
        var base  = largo <= 22 ? 5.0
                  : largo <= 30 ? 4.1
                  : largo <= 40 ? 3.3
                  : 2.7;
        return (base * (escala || 1)).toFixed(2);
    }

    /**
     * Si "CEDI: 149" entra al lado del número del punto de venta o va al lado de "Cajas total".
     *
     * Es la copia EXACTA de cediVaAlLadoDelNumero() en modules/historial/helper_rotulos_lista.php:
     * la cuenta se hace en puntos de la etiquetadora, que es el más estricto de los tres rótulos.
     * Si se cambia allá, se cambia acá.
     */
    function cediVaAlLadoDelNumero(numeroPv, numeroCedi) {
        var largoNumero = String(numeroPv).length;
        var anchoNumero = largoNumero * 24 * 2;
        var anchoCedi   = ('CEDI: ' + numeroCedi).length * 16 * 2;
        return anchoNumero + 4 * 8 + anchoCedi <= 680;
    }

    // LA LETRA CRECE CON EL ESPACIO QUE QUEDA (2026-10-09). Es la copia EXACTA de
    // tamanosDelRotulo() en modules/historial/helper_rotulos_lista.php, que usan el PDF y la
    // etiquetadora: si se cambia un número acá, se cambia allá. Devuelve los milímetros de cada
    // campo (etiqueta, oc, valor, cedi), el campo al lado del cual va "CEDI: 149" y el aire que sobra.
    var CAMPOS_ALTO_MM = 40, CAMPOS_ANCHO_MM = 85, ESCALA_MAXIMA = 4;

    function tamanos(r) {
        var lleno = function (v) { return v != null && String(v).trim() !== ''; };
        var largo = function (v) { return String(v == null ? '' : v).trim().length; };
        var numeroCedi = String(r.numero_cedi == null ? '' : r.numero_cedi).replace(/\D+/g, '');
        var conCedi = numeroCedi !== '';
        var campoDelCedi = !conCedi ? null
            : (lleno(r.numero_pv) && cediVaAlLadoDelNumero(String(r.numero_pv).trim(), numeroCedi) ? 'numero' : 'cajas');

        var campos = [];
        if (lleno(r.pv)) { campos.push(['pv', 14, 0, largo(r.pv), +cuerpoValor(r.pv), false]); }
        if (lleno(r.numero_pv)) { campos.push(['numero', 17, 0, largo(r.numero_pv), +cuerpoValor(r.numero_pv, 1.6), true]); }
        var conOc = lleno(r.oc);
        campos.push(['cajas', conOc ? 16 : 11, conOc ? 4 + largo(r.oc) : 0, largo(r.total), +cuerpoValor(String(r.total), 0.75), false]);
        if (lleno(r.producto)) {
            campos.push(['producto', lleno(r.sku) ? 17 + largo(r.sku) : 8, 0, largo(r.producto), +cuerpoValor(r.producto, 0.70), false]);
        } else if (lleno(r.sku)) {
            campos.push(['producto', 3, 0, largo(r.sku), +cuerpoValor(r.sku, 0.70), false]);
        }
        if (lleno(r.cedi)) { campos.push(['cedi', 4, 0, largo(r.cedi), +cuerpoValor(r.cedi, 0.75), false]); }

        var alto = function (c, et, oc, valor) { return Math.max(et, c[2] ? oc : 0) * 1.1 + valor + 0.5; };
        var etiquetaBase = function (c) { return c[5] ? (conCedi ? 4.0 : 3.0) : 2.2; };

        var natural = 0;
        campos.forEach(function (c) { natural += alto(c, etiquetaBase(c), 2.8, c[4]); });
        var escala = Math.min(ESCALA_MAXIMA, Math.max(1, CAMPOS_ALTO_MM / natural));
        var escalaEtiqueta = 1 + (escala - 1) * 0.5;
        var redondo = function (n) { return Math.round(n * 100) / 100; };

        var salida = {}, usado = 0;
        campos.forEach(function (c) {
            var cedi  = c[0] === campoDelCedi ? redondo(6.5 * Math.min(escalaEtiqueta, 1.4)) : 0;
            if (cedi && c[1] * etiquetaBase(c) * 0.8 + ('CEDI: ' + numeroCedi).length * cedi * 0.68 + 3 > CAMPOS_ANCHO_MM) {
                cedi = 6.5;
            }
            var ancho = CAMPOS_ANCHO_MM - (cedi ? ('CEDI: ' + numeroCedi).length * cedi * 0.68 + 3 : 0);

            var valor = c[4] * escala;
            if (c[3] > 0) { valor = Math.min(valor, ancho / (c[3] * 0.72)); }
            valor = Math.max(c[4], valor);

            var et = etiquetaBase(c) * escalaEtiqueta;
            var oc = 2.8 * escalaEtiqueta;
            et = Math.min(et, Math.max(etiquetaBase(c), valor * 0.6));
            oc = Math.min(oc, Math.max(2.8, valor * 0.9));
            var anchoEtiqueta = c[1] * et * 0.8 + c[2] * oc * 0.72;
            if (anchoEtiqueta > ancho) {
                var f = Math.max(1 / escalaEtiqueta, ancho / anchoEtiqueta);
                et *= f;
                oc *= f;
            }

            salida[c[0]] = { etiqueta: redondo(et), oc: redondo(oc), valor: redondo(valor), cedi: cedi };
            usado += alto(c, et, oc, valor);
        });

        return { campos: salida, campoDelCedi: campoDelCedi, aire: redondo(Math.max(0, CAMPOS_ALTO_MM - usado)) };
    }

    /**
     * Un rótulo en HTML.
     *
     * r trae: pv, numero_pv, cedi, producto, sku, numero, total y, si ya se pidió, token. Cajas por
     * punto de venta manda además numero_cedi, que activa el formato Éxito.
     * Es la misma forma de objeto que se manda a la etiquetadora y al PDF, así que la lista que se
     * dibuja es literalmente la que se imprime.
     */
    function html(r) {
        var numeroPv = String(r.numero_pv == null ? '' : r.numero_pv).trim();

        // El número va a 1,6 veces el cuerpo normal, el equivalente del doble de la etiquetadora
        // (hasta el rollo de 100x80, 2026-09-29, iba al 2,3 cuando era corto). Ver helper_rotulos_pdf.php.
        var escalaNumero = 1.6;

        // El cuarto elemento marca el campo DESTACADO: etiqueta en negrita y valor grande. Es solo
        // para el número del punto de venta, el dato que se busca de lejos cuando las cajas ya
        // están estibadas y solo se ve el canto de la etiqueta.
        // La orden de compra acompaña a la etiqueta de "Cajas total" y no ocupa renglón propio, por
        // la misma razón que el SKU: un sexto campo no entra en la etiqueta. Ver helper_rotulos_pdf.php.
        // Los tamaños de letra: crecen cuando hay pocos campos (ver tamanos()).
        var tam = tamanos(r);
        var etiquetaCajas = r.oc && String(r.oc).trim() !== ''
            ? 'Cajas total  ·  <span class="rotulo-oc" style="font-size: ' + tam.campos.cajas.oc + 'mm">O/C ' + esc(r.oc) + '</span>'
            : 'Cajas total';

        // Solo van los campos que traen algo (2026-10-09, pedido del usuario): un rótulo suelto con
        // solo la orden de compra sale con la O/C, las cajas y el "CAJ x DE y", sin renglones vacíos.
        // "Cajas total" va siempre. El quinto elemento es el nombre del campo (ver campoDelCedi).
        // Lo mismo hacen htmlRotuloPdf() y tsplDeUnRotulo(): si se cambia acá, se cambia allá.
        var lleno = function (v) { return v != null && String(v).trim() !== ''; };
        var campos = [];
        if (lleno(r.pv)) { campos.push(['Punto de venta', esc(r.pv), cuerpoValor(r.pv), false, 'pv']); }
        if (numeroPv !== '') {
            campos.push(['N° punto de venta', esc(numeroPv), cuerpoValor(numeroPv, escalaNumero), true, 'numero']);
        }
        campos.push([etiquetaCajas, esc(r.total), cuerpoValor(String(r.total), 0.75), false, 'cajas']);
        // El SKU acompaña a la etiqueta y no ocupa renglón propio: hay productos que comparten
        // nombre y solo se distinguen por él, pero un renglón para cinco dígitos le robaría
        // altura al nombre, que es lo que se lee. Sin nombre, el SKU va solo.
        if (lleno(r.producto)) {
            campos.push([lleno(r.sku) ? 'Producto  ·  SKU ' + esc(r.sku) : 'Producto',
                         esc(r.producto), cuerpoValor(r.producto, 0.70), false, 'producto']);
        } else if (lleno(r.sku)) {
            campos.push(['SKU', esc(r.sku), cuerpoValor(r.sku, 0.70), false, 'producto']);
        }
        if (lleno(r.cedi)) { campos.push(['CEDI', esc(r.cedi), cuerpoValor(r.cedi, 0.75), false, 'cedi']); }

        // FORMATO ÉXITO: solo Cajas por punto de venta manda numero_cedi. Con él, la etiqueta del
        // número va más grande y a la derecha aparece "CEDI: 149". campoDelCedi es el campo al lado
        // del cual va: el número si está y entra, cajas total si no.
        var numeroCedi   = String(r.numero_cedi == null ? '' : r.numero_cedi).replace(/\D+/g, '');
        var textoCedi    = numeroCedi ? 'CEDI: ' + numeroCedi : '';
        var campoDelCedi = tam.campoDelCedi || '';

        var salida = ''
            + '<div class="rotulo' + (textoCedi ? ' rotulo-con-cedi' : '') + '">'
            +   '<div class="rotulo-marca">'
            +     '<img src="' + LOGO_URL + '" alt="">'
            +     '<span>Monterojo Gourmet</span>'
            +   '</div>'
            +   '<div class="rotulo-campos">';

        campos.forEach(function (c) {
            var t = tam.campos[c[4]];
            var texto = '<span class="rotulo-etiqueta' + (c[3] ? ' rotulo-etiqueta-fuerte' : '') + '" style="font-size: ' + t.etiqueta + 'mm">' + c[0] + '</span>'
                      + '<span class="rotulo-valor" style="font-size: ' + t.valor + 'mm">' + c[1] + '</span>';

            if (c[4] === campoDelCedi) {
                salida += '<div class="rotulo-campo rotulo-campo-cedi">'
                        +   '<div class="rotulo-campo-texto">' + texto + '</div>'
                        +   '<span class="rotulo-cedi" style="font-size: ' + t.cedi + 'mm">' + esc(textoCedi) + '</span>'
                        + '</div>';
            } else {
                salida += '<div class="rotulo-campo">' + texto + '</div>';
            }
        });

        salida += '</div>'
                + '<div class="rotulo-pie">'
                +   '<div class="rotulo-conteo">CAJ ' + esc(r.numero) + ' DE ' + esc(r.total) + '</div>';

        // Sin token no hay QR que dibujar: se deja el hueco en vez de una imagen rota. Pasa en el
        // instante entre que se dibuja la vista previa y llegan los tokens, o si el servidor no
        // pudo armar el enlace (en ese caso la etiqueta impresa también sale sin QR).
        if (r.token) {
            salida += '<img class="rotulo-qr" alt="" src="' + URL_ENLACES + '?accion=qr&t='
                    + encodeURIComponent(r.token) + '">';
        }

        return salida + '</div></div>';
    }

    /**
     * Pide al servidor el token del QR de cada rótulo y se lo agrega a cada objeto de la lista.
     *
     * Es UNA sola petición para todo el lote: una tienda con 40 cajas dispararía 40 requests solo
     * para dibujar la vista previa. Nunca falla hacia afuera: si no se puede armar el enlace, los
     * rótulos se ven e imprimen igual, sin QR.
     */
    function pedirEnlaces(rotulos) {
        if (!rotulos.length) { return Promise.resolve(); }

        return fetch(URL_ENLACES, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'enlaces', csrf_token: CSRF_TOKEN, rotulos: rotulos })
        })
        .then(function (resp) { return resp.json(); })
        .then(function (d) {
            if (!d.exito || !d.tokens) { return; }
            d.tokens.forEach(function (t, i) {
                if (rotulos[i]) { rotulos[i].token = t; }
            });
        })
        .catch(function () {});
    }

    /**
     * Dibuja la lista en el contenedor y, cuando llegan los tokens, la vuelve a dibujar con el QR.
     *
     * Se dibuja dos veces a propósito: la primera al instante, para que la vista previa no se
     * quede en blanco esperando al servidor, y la segunda ya con el QR.
     *
     * LA ESPERA ANTES DE PEDIR LOS TOKENS
     * En Historial, Picking y Generar rótulos la vista previa se redibuja con cada tecla. Cada
     * combinación distinta de datos es un token nuevo guardado en rotulos_enlace, así que pedirlos
     * en cada tecla dejaría guardado "S", "SU", "SUP"... Se espera a que se deje de escribir un
     * momento. Además, si mientras tanto la lista cambió, la respuesta vieja se descarta: si no,
     * podría pintar sobre la vista previa el QR de datos que ya no son los de pantalla.
     */
    var temporizador = null;
    var turno = 0;

    function dibujar(contenedor, rotulos, espera) {
        contenedor.innerHTML = rotulos.map(html).join('');

        clearTimeout(temporizador);
        var miTurno = ++turno;

        temporizador = setTimeout(function () {
            pedirEnlaces(rotulos).then(function () {
                if (miTurno !== turno) { return; }
                contenedor.innerHTML = rotulos.map(html).join('');
            });
        }, espera == null ? 600 : espera);
    }

    /**
     * El segmento (producto, SKU, EAN) que le toca a la caja número `numero` de un pedido.
     *
     * segmentos es la lista de productos del pedido en orden, cada uno con cuántas cajas lleva:
     * [{n: 3, producto, sku, ean}, {n: 2, ...}] → las cajas 1-3 son del primero y 4-5 del segundo.
     */
    function segmentoDeLaCaja(segmentos, numero) {
        var restante = numero;
        for (var i = 0; i < segmentos.length; i++) {
            if (restante <= segmentos[i].n) { return segmentos[i]; }
            restante -= segmentos[i].n;
        }
        return segmentos.length ? segmentos[segmentos.length - 1] : {};
    }

    window.RotuloMonterojo = {
        html: html,
        dibujar: dibujar,
        pedirEnlaces: pedirEnlaces,
        segmentoDeLaCaja: segmentoDeLaCaja,
        cuerpoValor: cuerpoValor
    };
})();
