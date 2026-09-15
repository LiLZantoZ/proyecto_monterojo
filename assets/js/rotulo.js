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
        var base  = largo <= 22 ? 5.5
                  : largo <= 30 ? 4.5
                  : largo <= 40 ? 3.6
                  : 3.0;
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
        var anchoNumero = largoNumero * 24 * (largoNumero <= 6 ? 3 : 2);
        var anchoCedi   = ('CEDI: ' + numeroCedi).length * 16 * 2;
        return anchoNumero + 4 * 8 + anchoCedi <= 680;
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
        var producto = r.producto && String(r.producto).trim() !== ''
            ? esc(r.producto)
            : '<span style="color:#888">________________</span>';

        var numeroPv = String(r.numero_pv == null ? '' : r.numero_pv).trim();

        // El número va al 2,3 cuando es corto. Una tienda identificada por su EAN de 13 dígitos, a
        // ese tamaño, medía unos 91mm en un renglón de 85 y salía cortada; ahí va a dos tercios, la
        // misma proporción que la etiquetadora (el triple o el doble).
        var escalaNumero = numeroPv.length <= 6 ? 2.3 : 1.53;

        // El cuarto elemento marca el campo DESTACADO: etiqueta en negrita y valor grande. Es solo
        // para el número del punto de venta, el dato que se busca de lejos cuando las cajas ya
        // están estibadas y solo se ve el canto de la etiqueta.
        var campos = [
            ['Punto de venta',    esc(r.pv),                    cuerpoValor(r.pv)],
            ['N° punto de venta', esc(numeroPv || '—'),         cuerpoValor(numeroPv, escalaNumero), true],
            ['Cajas total',       esc(r.total),                 cuerpoValor(String(r.total), 0.75)],
            // El SKU acompaña a la etiqueta y no ocupa renglón propio: hay productos que comparten
            // nombre y solo se distinguen por él, pero un renglón para cinco dígitos le robaría
            // altura al nombre, que es lo que se lee.
            [r.sku ? 'Producto  ·  SKU ' + esc(r.sku) : 'Producto',
                                  producto,                     cuerpoValor(r.producto, 0.70)],
            ['CEDI',              esc(r.cedi || '-'),           cuerpoValor(r.cedi, 0.75)]
        ];

        // FORMATO ÉXITO: solo Cajas por punto de venta manda numero_cedi. Con él, la etiqueta del
        // número va más grande y a la derecha aparece "CEDI: 149". campoDelCedi es el índice del
        // campo al lado del cual va: 1 (el número) si entra, 2 (cajas total) si no.
        var numeroCedi   = String(r.numero_cedi == null ? '' : r.numero_cedi).replace(/\D+/g, '');
        var textoCedi    = numeroCedi ? 'CEDI: ' + numeroCedi : '';
        var campoDelCedi = !textoCedi ? -1 : (cediVaAlLadoDelNumero(numeroPv || '-', numeroCedi) ? 1 : 2);

        var salida = ''
            + '<div class="rotulo' + (textoCedi ? ' rotulo-con-cedi' : '') + '">'
            +   '<div class="rotulo-marca">'
            +     '<img src="' + LOGO_URL + '" alt="">'
            +     '<span>Monterojo Gourmet</span>'
            +   '</div>'
            +   '<div class="rotulo-campos">';

        campos.forEach(function (c, i) {
            var texto = '<span class="rotulo-etiqueta' + (c[3] ? ' rotulo-etiqueta-fuerte' : '') + '">' + c[0] + '</span>'
                      + '<span class="rotulo-valor" style="font-size: ' + c[2] + 'mm">' + c[1] + '</span>';

            if (i === campoDelCedi) {
                salida += '<div class="rotulo-campo rotulo-campo-cedi">'
                        +   '<div class="rotulo-campo-texto">' + texto + '</div>'
                        +   '<span class="rotulo-cedi">' + esc(textoCedi) + '</span>'
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
