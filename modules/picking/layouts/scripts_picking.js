// modules/picking/layouts/scripts_picking.js
// Dos cosas: asignar quién alista cada entrega, y armar los rótulos.

(function () {
    'use strict';

    // Todo se engancha al documento por delegación y no a una tabla concreta: ahora hay una tabla
    // por entrega —97 en un día normal— y además viven dentro de un <details>, así que el
    // navegador puede no haberlas construido todavía cuando corre este script.
    var contenedor = document.querySelector('.modulo');
    if (!contenedor) { return; }

    // =============================================================================
    // ASIGNAR PERSONAL
    //
    // La asignación es de la ENTREGA, no del producto: quien alista arma la tienda completa. Por
    // eso el botón vive en la fila del pedido y el servidor lo escribe en todas sus líneas (ver
    // asignarPersonalAEntrega).
    // =============================================================================

    var modalAsignar   = document.getElementById('modal-asignar');
    var selectPersona  = document.getElementById('asignar-persona');
    var textoEntrega   = document.getElementById('asignar-entrega');
    var botonGuardar   = document.getElementById('btn-guardar-asignacion');

    // Qué botón —y por lo tanto qué entrega— abrió el modal. Se guarda para poder actualizar ese
    // mismo botón cuando el servidor confirme, sin volver a buscarlo por selector.
    var botonEnCurso = null;

    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-asignar');
        if (!boton || !modalAsignar) { return; }

        botonEnCurso = boton;

        textoEntrega.textContent = boton.dataset.pv + ' · O/C ' + boton.dataset.oc;
        if (selectPersona) {
            // El 0 del data- significa "sin asignar", y en el <select> eso es la opción vacía.
            var actual = boton.dataset.idPersonal;
            selectPersona.value = (actual && actual !== '0') ? actual : '';
        }

        modalAsignar.classList.add('active');
    });

    if (botonGuardar) {
        botonGuardar.addEventListener('click', function () {
            if (!botonEnCurso) { return; }

            var boton = botonEnCurso;
            botonGuardar.disabled = true;   // sin esto, dos clics mandan dos peticiones

            fetch(BASE_URL + '/modules/picking/controller_picking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion:       'asignar_personal',
                    csrf_token:   CSRF_TOKEN,
                    cedi:         boton.dataset.cedi,
                    orden_compra: boton.dataset.oc,
                    punto_venta:  boton.dataset.pv,
                    id_personal:  selectPersona.value
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (datos) {
                if (!datos.exito) {
                    alert(datos.error || 'No se pudo guardar la asignación.');
                    return;
                }

                var asignado = !!datos.id_personal;

                boton.dataset.idPersonal = asignado ? String(datos.id_personal) : '0';
                boton.querySelector('.texto-asignado').textContent = asignado ? datos.nombre : 'Sin asignar';
                boton.classList.toggle('btn-sin-asignar', !asignado);
                boton.querySelector('i').className = asignado
                    ? 'fa-solid fa-user-check'
                    : 'fa-solid fa-user-plus';

                // Verde un momento: confirma que ESA fila quedó guardada, cosa que cerrar el modal
                // sin más no dice.
                boton.classList.add('guardado');
                setTimeout(function () { boton.classList.remove('guardado'); }, 1500);

                modalAsignar.classList.remove('active');
            })
            .catch(function () {
                alert('No se pudo conectar con el servidor para guardar la asignación.');
            })
            .finally(function () {
                botonGuardar.disabled = false;
            });
        });
    }

    // =============================================================================
    // DETALLE DE UN PEDIDO
    //
    // Cada pedido de la tabla tiene debajo una <tr> oculta con sus productos. El botón de la
    // primera columna la muestra u oculta.
    //
    // Se usa el atributo `hidden` y no style.display: así el estado se lee del propio HTML —y
    // cualquiera que inspeccione la fila entiende por qué no se ve— en vez de quedar escondido en
    // un estilo en línea.
    // =============================================================================

    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-detalle');
        if (!boton) { return; }

        var fila = boton.closest('.fila-pedido');
        // El detalle es la fila siguiente, no una que se busque por selector: son 97 pedidos y
        // varios comparten punto de venta, así que la posición es más fiable que la clave.
        var detalle = fila.nextElementSibling;
        if (!detalle || !detalle.classList.contains('fila-detalle')) { return; }

        var abrir = detalle.hidden;
        detalle.hidden = !abrir;
        fila.classList.toggle('fila-pedido-abierta', abrir);
        boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        boton.title = abrir ? 'Ocultar los productos de este pedido' : 'Ver los productos de este pedido';
    });

    // =============================================================================
    // RÓTULOS
    // =============================================================================

    var modal   = document.getElementById('modal-rotulo');
    var previa  = document.getElementById('rotulos-previa');
    var detalle = document.getElementById('rotulo-titulo-detalle');
    var avisoSaldos = document.getElementById('rotulo-aviso-saldos');
    var avisoSaldosTexto = document.getElementById('rotulo-aviso-saldos-texto');
    var areaImpresion = document.getElementById('area-impresion-rotulos');

    function esc(texto) {
        var div = document.createElement('div');
        div.textContent = texto == null ? '' : texto;
        return div.innerHTML;
    }

    // El identificador de UNA caja, que es lo que va dentro del código de barras:
    //
    //     orden de compra - EAN de la tienda - número de caja
    //
    // Los tres alcanzan para que sea único porque la numeración es CORRIDA sobre el pedido: la
    // caja 3 de un pedido es una sola, sin importar qué producto lleve adentro. Antes el
    // identificador incluía además el SKU, y hacía falta: con cada producto numerando desde 1, la
    // "caja 1" de un pedido podían ser tres cajas distintas. Al pasar a numeración corrida ese
    // dato dejó de aportar, y sacarlo acorta el código de 32 a 26 caracteres — barras más gruesas
    // en el mismo ancho de rótulo, que es lo que hace que el lector enganche a la primera.
    //
    // La tienda va por su EAN y no por el código del nombre. El nombre NO sirve como fuente: de
    // las 69 tiendas del archivo, 14 no llevan el código adelante ("TURBO CARULLA LIMONAR-4845")
    // y alguna no lo tiene en ninguna parte ("Carulla La Maria"). El EAN está siempre y es
    // inequívoco. Si falta —no debería—, se cae al nombre limpio antes que generar un código
    // ambiguo.
    function identificadorDeCaja(datos, numero) {
        var tienda = datos.eanPv && datos.eanPv !== ''
            ? datos.eanPv
            : datos.pv;

        return [datos.oc, tienda, numero]
            .map(function (parte) { return String(parte).replace(/[^A-Za-z0-9]/g, ''); })
            .join('-');
    }

    // Un rótulo: punto de venta, orden de compra, cajas totales, producto, CEDI y la numeración.
    //
    // `producto` llega por etiqueta y no en `datos` porque al imprimir el pedido entero cada caja
    // lleva uno distinto.
    function htmlRotulo(datos, numero, total, producto) {
        var nombreProducto = producto && producto.trim() !== ''
            ? esc(producto)
            // Se imprime el hueco en vez de dejar el renglón afuera: el rótulo se pega igual y
            // alguien tiene que poder escribirlo a mano.
            : '<span style="color:#888">_______________</span>';

        var idCaja = identificadorDeCaja(datos, numero);
        var urlCodigo = BASE_URL + '/modules/picking/controller_picking.php?accion=codigo_barras&texto='
                      + encodeURIComponent(idCaja);

        return ''
            + '<div class="rotulo">'
            +   '<div class="rotulo-marca">'
            +     '<img src="' + LOGO_URL + '" alt="">'
            +     '<span>Monterojo Gourmet</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">Punto de venta</span>'
            +     '<span class="rotulo-valor">' + esc(datos.pv) + '</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">Orden de compra</span>'
            +     '<span class="rotulo-valor">' + esc(datos.oc) + '</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">Cajas total</span>'
            +     '<span class="rotulo-valor">' + total + '</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">Producto</span>'
            +     '<span class="rotulo-valor rotulo-valor-producto">' + nombreProducto + '</span>'
            +   '</div>'
            +   '<div class="rotulo-campo">'
            +     '<span class="rotulo-etiqueta">CEDI</span>'
            +     '<span class="rotulo-valor">' + esc(datos.cedi) + '</span>'
            +   '</div>'
            +   '<div class="rotulo-conteo">CAJ ' + numero + ' DE ' + total + '</div>'
            // El mismo identificador va en barras y en texto debajo: si el lector no engancha
            // —etiqueta arrugada, mal impresa—, alguien tiene que poder teclearlo.
            +   '<div class="rotulo-codigo">'
            +     '<img src="' + urlCodigo + '" alt="Código de barras ' + esc(idCaja) + '">'
            +     '<span class="rotulo-codigo-texto">' + esc(idCaja) + '</span>'
            +   '</div>'
            + '</div>';
    }

    // Los campos editables del modal. Se leen de acá y no del botón que lo abrió, para que
    // dibujar los rótulos sea siempre lo mismo: lo que haya en pantalla es lo que se imprime.
    var campos = {
        cantidad: document.getElementById('rot-cantidad'),
        desde:    document.getElementById('rot-desde'),
        total:    document.getElementById('rot-total'),
        pv:       document.getElementById('rot-pv'),
        oc:       document.getElementById('rot-oc'),
        cedi:     document.getElementById('rot-cedi'),
        producto: document.getElementById('rot-producto')
    };

    // El EAN de la tienda no se edita: es con lo que se arma el código de barras, y dejarlo
    // escribir a mano abriría la puerta a rótulos con un código que no corresponde a nada. Se
    // guarda del botón que abrió el modal.
    var identificadores = { eanPv: '' };

    // Qué producto va en cada caja: [{n: 2, producto: 'PAPAS BBQ DULCE...'}, ...] en el orden de
    // la numeración. Al abrir el rótulo de un producto suelto trae un solo tramo; al abrir el del
    // pedido completo, uno por línea.
    var segmentos = [];

    // El producto que le toca a la caja número `numero` (1 = la primera del pedido).
    //
    // Lo escrito a mano en el campo manda sobre la lista: es la vía para corregir un nombre o
    // para poner uno cuando el producto todavía no está en el maestro.
    function productoDeLaCaja(numero) {
        var escrito = campos.producto.value.trim();
        if (escrito !== '') { return escrito; }

        var restante = numero;
        for (var i = 0; i < segmentos.length; i++) {
            if (restante <= segmentos[i].n) { return segmentos[i].producto; }
            restante -= segmentos[i].n;
        }

        // Más allá de lo que cubren los tramos —pasa si se sube la cantidad a mano— se repite el
        // último producto, que es lo más probable que esté empacando quien agregó cajas.
        return segmentos.length ? segmentos[segmentos.length - 1].producto : '';
    }

    function entero(campo, porDefecto, minimo, maximo) {
        var valor = parseInt(campo.value, 10);
        if (!valor || valor < minimo) { valor = porDefecto; }
        if (valor > maximo) { valor = maximo; }   // el mismo tope del input, por si se teclea
        return valor;
    }

    function dibujarRotulos() {
        var cantidad = entero(campos.cantidad, 1, 1, 99);
        var desde    = entero(campos.desde, 1, 1, 999);
        var total    = entero(campos.total, 1, 1, 999);

        // El total nunca puede quedar por debajo de la última caja que se está imprimiendo: un
        // "CAJ 8 DE 5" no significa nada para quien lo recibe.
        var ultima = desde + cantidad - 1;
        if (total < ultima) { total = ultima; }

        var datos = {
            pv:    campos.pv.value,
            oc:    campos.oc.value,
            cedi:  campos.cedi.value,
            eanPv: identificadores.eanPv
        };

        var html = '';
        for (var i = 0; i < cantidad; i++) {
            var numero = desde + i;
            html += htmlRotulo(datos, numero, total, productoDeLaCaja(numero));
        }
        previa.innerHTML = html;
    }

    // Cualquier campo del panel vuelve a dibujar TODOS los rótulos: se edita una vez y no una por
    // caja. 'input' y no 'change' para que la previa acompañe mientras se escribe.
    Object.keys(campos).forEach(function (nombre) {
        campos[nombre].addEventListener('input', dibujarRotulos);
    });

    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-rotulo');
        if (!boton) { return; }

        // Cuántas cajas propone el sistema. Puede ser 0 —una línea sin unidades—; en ese caso se
        // arranca en 1 en vez de no mostrar nada: el rótulo se puede ver y ajustar igual, que es
        // para lo que es editable.
        var calculadas = parseInt(boton.dataset.cajas, 10) || 0;
        var totalPedido = parseInt(boton.dataset.total, 10) || 0;

        identificadores.eanPv = boton.dataset.eanPv || '';

        // El botón del pedido trae la lista de tramos; el de un producto, su nombre a secas.
        if (boton.dataset.segmentos) {
            try {
                segmentos = JSON.parse(boton.dataset.segmentos) || [];
            } catch (error) {
                segmentos = [];
            }
        } else {
            segmentos = boton.dataset.producto
                ? [{ n: Math.max(calculadas, 1), producto: boton.dataset.producto }]
                : [];
        }

        campos.cantidad.value = calculadas > 0 ? calculadas : 1;
        campos.desde.value    = parseInt(boton.dataset.desde, 10) || 1;
        campos.total.value    = totalPedido > 0 ? totalPedido : 1;
        campos.pv.value       = boton.dataset.pv || '';
        campos.oc.value       = boton.dataset.oc || '';
        campos.cedi.value     = boton.dataset.cedi || '';
        campos.producto.value = '';   // vacío = cada caja toma el suyo de los tramos

        dibujarRotulos();

        detalle.textContent = '· ' + boton.dataset.pv + ' · '
            + (boton.dataset.descripcion || 'pedido completo');

        // El conteo son cajas FÍSICAS: las unidades que no llenan una caja igual viajan en una, y
        // esa caja va contada y rotulada. Se avisa solo cuando la última queda incompleta, para
        // que nadie la dé por errónea al verla a medio llenar.
        var saldos = parseInt(boton.dataset.saldos, 10) || 0;
        if (calculadas < 1) {
            avisoSaldosTexto.innerHTML = 'Esta línea no tiene unidades que rotular, así que el rótulo '
                + 'arranca en <strong>1</strong>. Ajustá la cantidad si vas a despachar alguna.';
            avisoSaldos.hidden = false;
        } else if (saldos > 0 && !boton.dataset.segmentos) {
            avisoSaldosTexto.innerHTML = 'La última de estas <strong>' + calculadas + '</strong> cajas va '
                + 'incompleta: son <strong>' + saldos + '</strong> unidad(es) sueltas que no llenan una caja, '
                + 'pero viajan igual y por eso llevan rótulo.';
            avisoSaldos.hidden = false;
        } else {
            avisoSaldos.hidden = true;
        }

        modal.classList.add('active');
    });

    // Impresión: los rótulos se MUEVEN a un contenedor hijo directo de <body>, porque al imprimir
    // se oculta todo lo demás y un elemento con un ancestro en display:none no se puede volver a
    // mostrar desde el descendiente. Al terminar vuelven a su lugar dentro del modal.
    document.getElementById('btn-imprimir-rotulos')?.addEventListener('click', function () {
        while (previa.firstChild) {
            areaImpresion.appendChild(previa.firstChild);
        }
        areaImpresion.hidden = false;
        document.body.classList.add('imprimiendo-rotulos');

        function restaurar() {
            while (areaImpresion.firstChild) {
                previa.appendChild(areaImpresion.firstChild);
            }
            areaImpresion.hidden = true;
            document.body.classList.remove('imprimiendo-rotulos');
            window.removeEventListener('afterprint', restaurar);
        }

        window.addEventListener('afterprint', restaurar);
        window.print();

        // Respaldo: algunos navegadores no disparan afterprint, y sin esto la pantalla quedaría
        // en blanco (con todo oculto por la clase de impresión) hasta recargar.
        setTimeout(function () {
            if (document.body.classList.contains('imprimiendo-rotulos')) { restaurar(); }
        }, 1500);
    });
})();
