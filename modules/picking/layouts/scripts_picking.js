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
    // SELECCIÓN DE VARIOS PEDIDOS
    //
    // Las casillas de cada fila alimentan la barra de acciones de abajo. Todo por delegación:
    // las tablas viven dentro de un <details> y pueden no existir cuando corre este script.
    // =============================================================================

    var barra        = document.getElementById('barra-seleccion');
    var barraConteo  = document.getElementById('barra-conteo');
    var camposPdf    = document.getElementById('campos-pdf-masivo');
    var formPdf      = document.getElementById('form-pdf-masivo');

    function pedidosSeleccionados() {
        return [].slice.call(contenedor.querySelectorAll('.chk-pedido:checked'));
    }

    // La fila tildada se resalta: con el detalle de un pedido abierto entre medio, la casilla
    // sola queda lejos y no se ve qué está seleccionado.
    function refrescarSeleccion() {
        var marcadas = pedidosSeleccionados();

        contenedor.querySelectorAll('.chk-pedido').forEach(function (chk) {
            chk.closest('.fila-pedido').classList.toggle('fila-seleccionada', chk.checked);
        });

        // Cada "todos" refleja el estado de SU tabla: marcado si están todas, indeterminado si
        // hay algunas. Sin el indeterminado, con tres de veinte tildadas el encabezado se vería
        // igual que con ninguna.
        contenedor.querySelectorAll('.chk-todos').forEach(function (todos) {
            var tabla = todos.closest('table');
            var enTabla = [].slice.call(tabla.querySelectorAll('.chk-pedido'));
            var tildadas = enTabla.filter(function (c) { return c.checked; }).length;

            todos.checked = enTabla.length > 0 && tildadas === enTabla.length;
            todos.indeterminate = tildadas > 0 && tildadas < enTabla.length;
        });

        if (!barra) { return; }

        barra.hidden = marcadas.length === 0;
        barraConteo.textContent = marcadas.length;

        // Los campos ocultos del formulario de descarga se rehacen en cada cambio: así el POST
        // siempre lleva exactamente lo que está tildado ahora. 'carga' es nuevo: antes todo el
        // lote era de la única carga vigente, y ahora cada pedido puede ser de una distinta.
        camposPdf.innerHTML = '';
        marcadas.forEach(function (chk) {
            ['carga', 'cedi', 'oc', 'pv'].forEach(function (campo) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = campo + '[]';
                input.value = chk.dataset[campo];
                camposPdf.appendChild(input);
            });
        });
    }

    contenedor.addEventListener('change', function (e) {
        if (e.target.classList.contains('chk-pedido')) {
            refrescarSeleccion();
            return;
        }

        if (e.target.classList.contains('chk-todos')) {
            var tabla = e.target.closest('table');
            tabla.querySelectorAll('.chk-pedido').forEach(function (chk) {
                chk.checked = e.target.checked;
            });
            refrescarSeleccion();
        }
    });

    // Un clic en la casilla no debe abrir ni cerrar el detalle del pedido, ni el <details> del
    // CEDI cuando es la del encabezado.
    contenedor.addEventListener('click', function (e) {
        if (e.target.classList.contains('chk-pedido') || e.target.classList.contains('chk-todos')) {
            e.stopPropagation();
        }
    });

    document.getElementById('btn-limpiar-seleccion')?.addEventListener('click', function () {
        contenedor.querySelectorAll('.chk-pedido, .chk-todos').forEach(function (chk) {
            chk.checked = false;
            chk.indeterminate = false;
        });
        refrescarSeleccion();
    });

    // Enviar el formulario vacío descargaría un PDF sin hojas; no debería poder pasar, pero la
    // barra se muestra y se oculta por JS y una condición de carrera dejaría el botón activo.
    formPdf?.addEventListener('submit', function (e) {
        if (pedidosSeleccionados().length === 0) { e.preventDefault(); }
    });

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

    // Qué entregas está tocando el modal. Siempre una lista, aunque venga una sola: así guardar
    // es el mismo camino se haya abierto desde la fila o desde la barra de selección.
    var entregasEnCurso = [];

    // El botón "Asignar personal" de una fila, buscado por su clave de entrega. Se usa para
    // actualizar en pantalla lo que el servidor confirmó.
    function botonAsignarDe(clave) {
        return contenedor.querySelector('.btn-asignar[data-entrega="' + CSS.escape(clave) + '"]');
    }

    function abrirModalAsignar(entregas, titulo, idPersonalActual) {
        if (!modalAsignar || !entregas.length) { return; }

        entregasEnCurso = entregas;
        textoEntrega.textContent = titulo;

        if (selectPersona) {
            // Con varias entregas se arranca en blanco: si tienen asignados distintos, precargar
            // el de una sería decir que todas están así.
            selectPersona.value = idPersonalActual || '';
        }

        modalAsignar.classList.add('active');
    }

    // Desde la fila: una sola entrega, con su asignado actual precargado.
    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-asignar');
        if (!boton) { return; }

        var actual = boton.dataset.idPersonal;
        abrirModalAsignar(
            [{ carga: boton.dataset.carga, cedi: boton.dataset.cedi, orden_compra: boton.dataset.oc, punto_venta: boton.dataset.pv }],
            boton.dataset.pv + ' · O/C ' + boton.dataset.oc,
            (actual && actual !== '0') ? actual : ''
        );
    });

    // Desde la barra: todas las tildadas.
    document.getElementById('btn-asignar-masivo')?.addEventListener('click', function () {
        var marcadas = pedidosSeleccionados();
        if (!marcadas.length) { return; }

        abrirModalAsignar(
            marcadas.map(function (chk) {
                return { carga: chk.dataset.carga, cedi: chk.dataset.cedi, orden_compra: chk.dataset.oc, punto_venta: chk.dataset.pv };
            }),
            marcadas.length + ' pedido(s) seleccionado(s)',
            ''
        );
    });

    if (botonGuardar) {
        botonGuardar.addEventListener('click', function () {
            if (!entregasEnCurso.length) { return; }

            botonGuardar.disabled = true;   // sin esto, dos clics mandan dos peticiones

            fetch(BASE_URL + '/modules/picking/controller_picking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion:      'asignar_personal',
                    csrf_token:  CSRF_TOKEN,
                    entregas:    entregasEnCurso,
                    id_personal: selectPersona.value
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (datos) {
                if (!datos.exito) {
                    alert(datos.error || 'No se pudo guardar la asignación.');
                    return;
                }

                var asignado = !!datos.id_personal;

                // Se recorren las claves que devolvió el SERVIDOR, no las que se mandaron: si
                // alguna no se pudo guardar, su botón no debe quedar diciendo que sí.
                (datos.grupos || []).forEach(function (clave) {
                    var boton = botonAsignarDe(clave);
                    if (!boton) { return; }

                    boton.dataset.idPersonal = asignado ? String(datos.id_personal) : '0';
                    boton.querySelector('.texto-asignado').textContent = asignado ? datos.nombre : 'Sin asignar';
                    boton.classList.toggle('btn-sin-asignar', !asignado);
                    boton.querySelector('i').className = asignado
                        ? 'fa-solid fa-user-check'
                        : 'fa-solid fa-user-plus';

                    // Verde un momento: confirma qué filas quedaron guardadas, cosa que cerrar el
                    // modal sin más no dice.
                    boton.classList.add('guardado');
                    setTimeout(function () { boton.classList.remove('guardado'); }, 1500);
                });

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
    // DESPACHAR PEDIDOS
    //
    // Marca una o varias entregas como despachadas: desde ese momento desaparecen de Picking y
    // también de Consolidados, porque las dos pantallas leen la misma columna `despachado` de
    // la base (ver filasPicking() y consolidadoPorCedi()).
    //
    // El chequeo de "¿todos tienen personal?" se hace ACÁ para dar el aviso al instante, sin ir
    // al servidor cuando ya se sabe que falta alguno — pero el servidor lo vuelve a comprobar
    // por su cuenta (ver despacharEntregas() en model_picking.php) antes de tocar la base. La
    // pantalla puede llevar un rato abierta; confiar solo en lo que el navegador cree que hay
    // asignado dejaría despachar con datos que ya cambiaron.
    // =============================================================================

    // Arma {carga, cedi, orden_compra, punto_venta} a partir de cualquier elemento que lleve esos
    // cuatro data- (el checkbox de la fila, el propio botón de despachar...). 'carga' es
    // obligatorio ahora: cedi+oc+pv ya no alcanzan para identificar la entrega si hay más de una
    // carga pendiente con esos mismos tres datos.
    function datosDeEntrega(el) {
        return { carga: el.dataset.carga, cedi: el.dataset.cedi, orden_compra: el.dataset.oc, punto_venta: el.dataset.pv };
    }

    // Si la fila tiene personal asignado. Se lee del botón "Asignar personal" de esa misma fila
    // —la única fuente de verdad en pantalla— y no de un data- propio en el botón de despachar,
    // que quedaría desactualizado si se reasigna sin recargar la página.
    function filaTienePersonal(fila) {
        var boton = fila && fila.querySelector('.btn-asignar');
        return !!(boton && boton.dataset.idPersonal && boton.dataset.idPersonal !== '0');
    }

    function enviarDespacho(pedidos, callback) {
        fetch(BASE_URL + '/modules/picking/controller_picking.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'despachar_pedidos', csrf_token: CSRF_TOKEN, pedidos: pedidos })
        })
        .then(function (r) { return r.json(); })
        .then(callback)
        .catch(function () {
            callback({ exito: false, error: 'No se pudo conectar con el servidor para despachar.' });
        });
    }

    // -----------------------------------------------------------------------
    // EL MODAL DE DESPACHAR
    //
    // Uno solo para las dos situaciones posibles —"¿confirmás?" y "no se puede todavía"—, con el
    // mismo aspecto que el resto del sistema en vez del cuadro nativo del navegador (que ni
    // siquiera se puede reconocer como parte de esta pantalla, y que en la captura que mandó el
    // usuario aparecía como "localhost dice").
    //
    // Se arma con el DOM y no con innerHTML de punta a punta porque los botones necesitan sus
    // propios listeners (Despachar, Cerrar, "Asignar ahora"), y crear el HTML como texto
    // obligaría a usar atributos onclick en línea.
    // -----------------------------------------------------------------------

    var modalDespachar  = document.getElementById('modal-despachar');
    var despacharTitulo = document.getElementById('despachar-titulo');
    var despacharCuerpo = document.getElementById('despachar-cuerpo');
    var despacharPie    = document.getElementById('despachar-pie');

    function botonModal(texto, clase, icono) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = clase;
        b.innerHTML = (icono ? '<i class="fa-solid ' + icono + '"></i> ' : '') + esc(texto);
        return b;
    }

    // Modo "no se puede": lista los pedidos a los que les falta personal, con un atajo que abre
    // el modal de asignación directamente sobre esos mismos pedidos —así la condición que falta
    // se puede resolver ahí mismo, sin ir a buscar cada fila una por una.
    //
    // `paraAsignar` puede venir vacío (pasa cuando la lista sale de la respuesta del servidor tras
    // un intento de confirmar, que no manda el CEDI de cada pedido): ahí se omite el atajo y
    // queda solo el aviso, porque sin el CEDI no se puede armar la asignación.
    function mostrarDespachoBloqueado(pendientes, paraAsignar) {
        despacharTitulo.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> No se puede despachar todavía';

        var items = pendientes.map(function (p) {
            return '<li>' + esc(p.punto_venta) + ' <span class="sin-dato">· O/C ' + esc(p.orden_compra) + '</span></li>';
        }).join('');

        despacharCuerpo.innerHTML =
            '<div class="aviso aviso-atencion" style="margin-bottom:0;">' +
            '<i class="fa-solid fa-triangle-exclamation"></i>' +
            '<div>' +
            '<strong>' + pendientes.length + ' pedido(s) sin personal asignado.</strong> ' +
            'Se le tiene que asignar alguien a cada uno antes de poder despacharlos.' +
            '<ul class="lista-pendientes">' + items + '</ul>' +
            '</div></div>';

        despacharPie.innerHTML = '';
        var btnCerrar = botonModal('Cerrar', 'btn');
        btnCerrar.addEventListener('click', function () { modalDespachar.classList.remove('active'); });
        despacharPie.appendChild(btnCerrar);

        if (paraAsignar && paraAsignar.length) {
            var btnAsignar = botonModal('Asignar ahora', 'btn btn-primario', 'fa-user-plus');
            btnAsignar.addEventListener('click', function () {
                modalDespachar.classList.remove('active');
                abrirModalAsignar(paraAsignar, pendientes.length + ' pedido(s) sin asignar', '');
            });
            despacharPie.appendChild(btnAsignar);
        }

        modalDespachar.classList.add('active');
    }

    // Modo "¿confirmás?": la pregunta y, al aceptar, `onConfirmar` hace el envío. Si el servidor
    // responde que ya no se puede (alguien más quitó una asignación mientras el modal estaba
    // abierto), el mismo modal cambia al modo bloqueado en vez de cerrarse con un error suelto.
    function mostrarDespachoConfirmar(pedidos, descripcion) {
        despacharTitulo.textContent = pedidos.length === 1 ? 'Despachar pedido' : 'Despachar pedidos';

        despacharCuerpo.innerHTML =
            '<p class="confirmar-pregunta">¿Despachar ' + esc(descripcion) + '?</p>' +
            '<div class="aviso aviso-info" style="margin-bottom:0;">' +
            '<i class="fa-solid fa-circle-info"></i>' +
            '<div>Desaparece' + (pedidos.length === 1 ? '' : 'n') + ' de Picking y de Consolidados. '
            + 'No se puede deshacer desde acá.</div></div>';

        despacharPie.innerHTML = '';
        var btnCancelar = botonModal('Cancelar', 'btn');
        var btnConfirmar = botonModal('Despachar', 'btn btn-primario', 'fa-truck-fast');

        btnCancelar.addEventListener('click', function () { modalDespachar.classList.remove('active'); });

        btnConfirmar.addEventListener('click', function () {
            btnConfirmar.disabled = true;
            btnCancelar.disabled = true;

            enviarDespacho(pedidos, function (datos) {
                if (!datos.exito) {
                    // Los pedidos que manda el servidor acá NO traen cedi, así que no hay con qué
                    // armar el atajo "Asignar ahora" — se muestra solo la lista.
                    mostrarDespachoBloqueado(datos.sin_personal && datos.sin_personal.length
                        ? datos.sin_personal
                        : pedidos);
                    return;
                }
                window.location.reload();
            });
        });

        despacharPie.appendChild(btnCancelar);
        despacharPie.appendChild(btnConfirmar);
        modalDespachar.classList.add('active');
    }

    // Un solo pedido, desde el botón de su fila.
    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-despachar');
        if (!boton || !modalDespachar) { return; }

        var fila = boton.closest('.fila-pedido');
        var entrega = datosDeEntrega(boton);

        if (!filaTienePersonal(fila)) {
            mostrarDespachoBloqueado([entrega], [entrega]);
            return;
        }

        mostrarDespachoConfirmar([entrega], 'el pedido de ' + boton.dataset.pv);
    });

    // Varios pedidos de una, desde la barra de selección.
    document.getElementById('btn-despachar-masivo')?.addEventListener('click', function () {
        if (!modalDespachar) { return; }

        var marcadas = pedidosSeleccionados();
        if (!marcadas.length) { return; }

        var sinAsignar = [];
        var todas = [];
        marcadas.forEach(function (chk) {
            var entrega = datosDeEntrega(chk);
            todas.push(entrega);
            if (!filaTienePersonal(chk.closest('.fila-pedido'))) { sinAsignar.push(entrega); }
        });

        if (sinAsignar.length) {
            mostrarDespachoBloqueado(sinAsignar, sinAsignar);
            return;
        }

        mostrarDespachoConfirmar(todas, 'los ' + todas.length + ' pedido(s) seleccionados');
    });

    // =============================================================================
    // RÓTULOS
    // =============================================================================

    var modal   = document.getElementById('modal-rotulo');
    var previa  = document.getElementById('rotulos-previa');
    var detalle = document.getElementById('rotulo-titulo-detalle');
    var avisoSaldos = document.getElementById('rotulo-aviso-saldos');
    var avisoSaldosTexto = document.getElementById('rotulo-aviso-saldos-texto');

    function esc(texto) {
        var div = document.createElement('div');
        div.textContent = texto == null ? '' : texto;
        return div.innerHTML;
    }

    // El identificador de UNA caja, que es lo que va dentro del código de barras:
    //
    //     orden de compra - EAN de la tienda - número de caja
    function identificadorDeCaja(datos, numero) {
        var tienda = datos.eanPv && datos.eanPv !== ''
            ? datos.eanPv
            : datos.pv;

        return [datos.oc, tienda, numero]
            .map(function (parte) { return String(parte).replace(/[^A-Za-z0-9]/g, ''); })
            .join('-');
    }

    function htmlRotulo(datos, numero, total, producto) {
        var nombreProducto = producto && producto.trim() !== ''
            ? esc(producto)
            : '<span style="color:#888">_______________</span>';

        var idCaja = identificadorDeCaja(datos, numero);
        var urlCodigo = BASE_URL + '/modules/picking/controller_picking.php?accion=codigo_barras&texto='
                      + encodeURIComponent(idCaja);

        // 100mm x 40mm exactos, el mismo diseño compacto en los tres lugares que arman esto
        // (acá, en scripts_historial.js y en scripts_rotulos.js) y en el PDF (helper_rotulos_pdf.php
        // en el módulo Historial): sin logo —no entra en 4cm de alto—, sin etiqueta suelta para
        // cada campo, orden de compra y CEDI comparten renglón, y el contador de cajas sube a
        // compartir el encabezado con la marca. Ver el porqué completo en
        // assets/css/partes/04-rotulo.css.
        return ''
            + '<div class="rotulo">'
            +   '<div class="rotulo-marca">'
            +     '<span>Monterojo Gourmet</span>'
            +     '<span class="rotulo-conteo">CAJ ' + numero + ' DE ' + total + '</span>'
            +   '</div>'
            +   '<span class="rotulo-valor">' + esc(datos.pv) + '</span>'
            +   '<span class="rotulo-meta">O/C ' + esc(datos.oc) + ' · CEDI ' + esc(datos.cedi) + '</span>'
            +   '<span class="rotulo-valor-producto">' + nombreProducto + '</span>'
            +   '<div class="rotulo-codigo">'
            +     '<img src="' + urlCodigo + '" alt="Código de barras ' + esc(idCaja) + '">'
            +     '<span class="rotulo-codigo-texto">' + esc(idCaja) + '</span>'
            +   '</div>'
            + '</div>';
    }

    var campos = {
        cantidad: document.getElementById('rot-cantidad'),
        desde:    document.getElementById('rot-desde'),
        total:    document.getElementById('rot-total'),
        pv:       document.getElementById('rot-pv'),
        oc:       document.getElementById('rot-oc'),
        cedi:     document.getElementById('rot-cedi'),
        producto: document.getElementById('rot-producto')
    };

    var identificadores = { eanPv: '' };

    var segmentos = [];

    function productoDeLaCaja(numero) {
        var escrito = campos.producto.value.trim();
        if (escrito !== '') { return escrito; }

        var restante = numero;
        for (var i = 0; i < segmentos.length; i++) {
            if (restante <= segmentos[i].n) { return segmentos[i].producto; }
            restante -= segmentos[i].n;
        }

        return segmentos.length ? segmentos[segmentos.length - 1].producto : '';
    }

    function entero(campo, porDefecto, minimo, maximo) {
        var valor = parseInt(campo.value, 10);
        if (!valor || valor < minimo) { valor = porDefecto; }
        if (valor > maximo) { valor = maximo; } 
        return valor;
    }

    // Los rótulos que hay AHORA en la vista previa, uno por caja, con todo lo que va impreso en
    // cada uno. Lo llenan las dos funciones que dibujan (esta y la del botón masivo), y lo lee el
    // formulario de "Descargar PDF": así el PDF es exactamente lo que se está viendo, incluidos
    // los cambios que se hayan hecho a mano en los campos de arriba.
    var rotulosActuales = [];

    function dibujarRotulos() {
        var cantidad = entero(campos.cantidad, 1, 1, 99);
        var desde    = entero(campos.desde, 1, 1, 999);
        var total    = entero(campos.total, 1, 1, 999);

        var ultima = desde + cantidad - 1;
        if (total < ultima) { total = ultima; }

        var datos = {
            pv:    campos.pv.value,
            oc:    campos.oc.value,
            cedi:  campos.cedi.value,
            eanPv: identificadores.eanPv
        };

        var html = '';
        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }
        for (var i = 0; i < cantidad; i++) {
            var numero = desde + i;
            var producto = productoDeLaCaja(numero);
            rotulosActuales.push({
                pv: datos.pv, oc: datos.oc, cedi: datos.cedi, ean_pv: datos.eanPv,
                numero: numero, total: total, producto: producto
            });
            html += htmlRotulo(datos, numero, total, producto);
        }
        previa.innerHTML = html;
    }

    Object.keys(campos).forEach(function (nombre) {
        campos[nombre].addEventListener('input', dibujarRotulos);
    });

    contenedor.addEventListener('click', function (e) {
        var boton = e.target.closest('.btn-rotulo');
        if (!boton) { return; }

        var calculadas = parseInt(boton.dataset.cajas, 10) || 0;
        var totalPedido = parseInt(boton.dataset.total, 10) || 0;

        identificadores.eanPv = boton.dataset.eanPv || '';

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

        mostrarPanelEdicion(true);

        campos.cantidad.value = calculadas > 0 ? calculadas : 1;
        campos.desde.value    = parseInt(boton.dataset.desde, 10) || 1;
        campos.total.value    = totalPedido > 0 ? totalPedido : 1;
        campos.pv.value       = boton.dataset.pv || '';
        campos.oc.value       = boton.dataset.oc || '';
        campos.cedi.value     = boton.dataset.cedi || '';
        campos.producto.value = ''; 

        dibujarRotulos();

        detalle.textContent = '· ' + boton.dataset.pv + ' · '
            + (boton.dataset.descripcion || 'pedido completo');

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

    var panelControles = document.querySelector('.rotulo-controles');
    var notaControles  = document.querySelector('.rotulo-nota');

    function mostrarPanelEdicion(visible) {
        if (panelControles) { panelControles.hidden = !visible; }
        if (notaControles)  { notaControles.hidden  = !visible; }
    }

    document.getElementById('btn-rotulos-masivo')?.addEventListener('click', function () {
        var marcadas = pedidosSeleccionados();
        if (!marcadas.length) { return; }

        var html = '';
        var totalRotulos = 0;
        var sinCajas = 0;
        rotulosActuales = [];
        if (avisoImpresion) { avisoImpresion.hidden = true; }

        marcadas.forEach(function (chk) {
            var boton = chk.closest('.fila-pedido').querySelector('.btn-rotulo');
            if (!boton) { return; }

            var total = parseInt(boton.dataset.cajas, 10) || 0;
            if (total < 1) { sinCajas++; return; }

            var segmentosPedido = [];
            try {
                segmentosPedido = JSON.parse(boton.dataset.segmentos || '[]');
            } catch (error) {
                segmentosPedido = [];
            }

            var datos = {
                pv:    boton.dataset.pv,
                oc:    boton.dataset.oc,
                cedi:  boton.dataset.cedi,
                eanPv: boton.dataset.eanPv
            };

            for (var i = 1; i <= total; i++) {
                var restante = i;
                var producto = '';
                for (var s = 0; s < segmentosPedido.length; s++) {
                    if (restante <= segmentosPedido[s].n) { producto = segmentosPedido[s].producto; break; }
                    restante -= segmentosPedido[s].n;
                }
                rotulosActuales.push({
                    pv:      datos.pv,
                    oc:      datos.oc,
                    cedi:    datos.cedi,
                    ean_pv:  datos.eanPv,
                    numero:  i,
                    total:   total,
                    producto: producto
                });
                html += htmlRotulo(datos, i, total, producto);
                totalRotulos++;
            }
        });

        if (!totalRotulos) {
            alert('Ninguno de los pedidos seleccionados tiene cajas que rotular.');
            return;
        }

        mostrarPanelEdicion(false);
        previa.innerHTML = html;
        detalle.textContent = '· ' + totalRotulos + ' rótulos de ' + marcadas.length + ' pedido(s)';

        if (sinCajas > 0) {
            avisoSaldosTexto.innerHTML = '<strong>' + sinCajas + '</strong> de los pedidos seleccionados no '
                + 'tienen cajas que rotular, así que quedaron fuera. Se pueden abrir uno por uno desde su '
                + 'fila para ponerles la cantidad a mano.';
            avisoSaldos.hidden = false;
        } else {
            avisoSaldos.hidden = true;
        }

        modal.classList.add('active');
    });

    // ---------------------------------------------------------------------------------------
    // IMPRIMIR EN LA ETIQUETADORA
    //
    // Manda la misma lista que ya está dibujada en la vista previa y el servidor la traduce a
    // TSPL, el idioma de la TSC (ver modules/historial/helper_rotulos_tspl.php). No abre el
    // diálogo de impresión: la etiqueta sale sola.
    // ---------------------------------------------------------------------------------------
    var avisoImpresion      = document.getElementById('rotulo-aviso-impresion');
    var avisoImpresionTexto = document.getElementById('rotulo-aviso-impresion-texto');
    var botonEtiquetadora   = document.getElementById('btn-imprimir-etiquetadora');

    function mostrarResultadoImpresion(texto, salioBien) {
        if (!avisoImpresion) { return; }
        avisoImpresionTexto.textContent = texto;
        avisoImpresion.className = 'aviso ' + (salioBien ? 'aviso-exito' : 'aviso-atencion');
        avisoImpresion.querySelector('i').className = salioBien
            ? 'fa-solid fa-circle-check'
            : 'fa-solid fa-triangle-exclamation';
        avisoImpresion.hidden = false;
    }

    if (botonEtiquetadora) {
        botonEtiquetadora.addEventListener('click', function () {
            if (!rotulosActuales.length) { return; }

            // Sin esto, dos clics mandan el lote dos veces — y acá eso son etiquetas de papel
            // gastadas, no una fila repetida que se pueda borrar.
            botonEtiquetadora.disabled = true;
            var textoOriginal = botonEtiquetadora.innerHTML;
            botonEtiquetadora.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
            if (avisoImpresion) { avisoImpresion.hidden = true; }

            fetch(BASE_URL + '/modules/picking/controller_picking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    accion:     'imprimir_rotulos',
                    csrf_token: CSRF_TOKEN,
                    rotulos:    rotulosActuales
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (datos) {
                mostrarResultadoImpresion(
                    datos.mensaje || datos.error || 'No se pudo imprimir.',
                    !!datos.exito
                );
            })
            .catch(function () {
                mostrarResultadoImpresion(
                    'No se pudo contactar al servidor. Revisá la conexión e intentá de nuevo.',
                    false
                );
            })
            .finally(function () {
                botonEtiquetadora.disabled = false;
                botonEtiquetadora.innerHTML = textoOriginal;
            });
        });
    }

    // El PDF sale de la MISMA lista que se acaba de dibujar arriba, no de un recálculo en el
    // servidor. Es a propósito: acá los rótulos son editables (el picker cambia el punto de venta,
    // la cantidad de cajas o el nombre del producto), y al rotular un pedido entero cada caja lleva
    // un producto distinto. Recalcularlo del lado del servidor devolvería lo que dice el archivo
    // original, que es justamente lo que el picker acaba de corregir. Mandando la lista, el PDF
    // sale idéntico a la vista previa.
    document.getElementById('form-rotulos-pdf')?.addEventListener('submit', function (evento) {
        if (!rotulosActuales.length) {
            evento.preventDefault();
            return;
        }
        document.getElementById('rotulos-pdf-datos').value = JSON.stringify(rotulosActuales);
    });

})();