// modules/consolidado_mr/layouts/scripts_consolidado_mr.js
// Filtros que aplican solos, aviso de "subiendo" mientras se envía un Excel, y lo que se guarda sin
// recargar la página: el transportador de cada factura y si está pagada (de a una o varias a la vez,
// con la misma barra de selección de Picking).

(function () {
    'use strict';

    // Los desplegables (Cliente, Ciudad) y las fechas filtran al cambiar, sin tocar "Filtrar".
    // El texto de "Buscar" sigue necesitando Enter o el botón, para no recargar en cada tecla.
    var formFiltros = document.querySelector('form.filtros');
    if (formFiltros) {
        formFiltros.querySelectorAll('select, input[type="date"]').forEach(function (campo) {
            campo.addEventListener('change', function () {
                if (formFiltros.requestSubmit) { formFiltros.requestSubmit(); }
                else { formFiltros.submit(); }
            });
        });
    }

    // Los archivos son grandes y tardan: se deshabilita el botón para que no se envíen dos veces y se
    // avisa que está trabajando.
    document.querySelectorAll('form').forEach(function (form) {
        var boton = form.querySelector('.btn-enviando');
        if (!boton) { return; }
        form.addEventListener('submit', function () {
            boton.disabled = true;
            boton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo…';
        });
    });

    // Sin permiso de edición la vista no trae nada de lo de abajo.
    var cfg = window.CONSOLIDADO_MR;
    if (!cfg) { return; }

    function enviar(datos) {
        datos.csrf_token = cfg.csrf;
        return fetch(cfg.urlAcciones, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        }).then(function (r) { return r.json(); });
    }

    function abrirModal(id) {
        var modal = document.getElementById(id);
        if (modal) { modal.classList.add('active'); }
    }

    // ---------------------------------------------------------------------------------------------
    // SELECCIÓN: las casillas de cada fila, "todas las de la página" y la barra flotante de abajo.
    // ---------------------------------------------------------------------------------------------
    var barra  = document.getElementById('barra-seleccion');
    var conteo = document.getElementById('barra-conteo');

    function seleccionadas() {
        return [].slice.call(document.querySelectorAll('.chk-fila:checked')).map(function (c) { return c.value; });
    }

    function refrescarSeleccion() {
        var n = seleccionadas().length;
        if (barra) { barra.hidden = n === 0; }
        if (conteo) { conteo.textContent = n; }
        document.querySelectorAll('.mr-conteo-modal').forEach(function (el) { el.textContent = n; });

        var todas = [].slice.call(document.querySelectorAll('.chk-fila'));
        var marcadas = todas.filter(function (c) { return c.checked; }).length;
        document.querySelectorAll('.chk-todas').forEach(function (chk) {
            chk.checked = todas.length > 0 && marcadas === todas.length;
            chk.indeterminate = marcadas > 0 && marcadas < todas.length;
        });

        document.querySelectorAll('.chk-fila').forEach(function (chk) {
            var fila = chk.closest('tr');
            if (fila) { fila.classList.toggle('fila-seleccionada', chk.checked); }
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('chk-fila')) {
            refrescarSeleccion();
        } else if (e.target.classList.contains('chk-todas')) {
            var marcar = e.target.checked;
            document.querySelectorAll('.chk-fila').forEach(function (chk) { chk.checked = marcar; });
            refrescarSeleccion();
        }
    });

    var limpiar = document.getElementById('btn-limpiar-seleccion');
    if (limpiar) {
        limpiar.addEventListener('click', function () {
            document.querySelectorAll('.chk-fila, .chk-todas').forEach(function (chk) { chk.checked = false; });
            refrescarSeleccion();
        });
    }

    // ---------------------------------------------------------------------------------------------
    // TRANSPORTADOR DE UNA FACTURA: se guarda apenas se elige, sin recargar.
    // ---------------------------------------------------------------------------------------------
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel.classList.contains('sel-transportador')) { return; }

        var valor = sel.value;
        if (valor === '__otro__') {
            // "Otro…": el nombre se pide con el mensaje del sistema (2026-10-06; antes, el prompt del navegador).
            Dialogo.pedir('Escribí el nombre del transportador de esta factura.', {
                titulo: 'Otro transportador', placeholder: 'Ej: Coordinadora', maximo: 80
            }).then(function (nombre) {
                if (!nombre) {
                    sel.value = sel.dataset.actual || '';
                    return;
                }
                var opcion = document.createElement('option');
                opcion.value = nombre;
                opcion.textContent = nombre;
                sel.insertBefore(opcion, sel.querySelector('option[value="__otro__"]'));
                sel.value = nombre;
                guardarTransportador(sel, nombre);
            });
            return;
        }
        guardarTransportador(sel, valor);
    });

    function guardarTransportador(sel, valor) {
        sel.disabled = true;
        enviar({ accion: 'transportador', facturas: [sel.dataset.factura], transportadora: valor })
            .then(function (d) {
                if (!d.exito) {
                    Dialogo.avisar(d.error || 'No se pudo guardar el transportador.');
                    sel.value = sel.dataset.actual || '';
                    return;
                }
                sel.dataset.actual = valor;
                var nota = sel.parentNode.querySelector('.nota-transportador');
                if (nota) { nota.remove(); }
                sel.classList.add('guardado');
                setTimeout(function () { sel.classList.remove('guardado'); }, 1500);
            })
            .catch(function () {
                Dialogo.avisar('No se pudo contactar al servidor. Revisá la conexión e intentá de nuevo.');
                sel.value = sel.dataset.actual || '';
            })
            .finally(function () { sel.disabled = false; });
    }

    // ---------------------------------------------------------------------------------------------
    // ESTADO Y FECHA DE ENTREGA A MANO (2026-10-07): se guardan apenas se eligen, como el transportador.
    // Vacío = vuelve a lo que dice el reporte de la transportadora.
    // ---------------------------------------------------------------------------------------------
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el.classList.contains('sel-estado-mr')) {
            if (el.value === '__otro__') {
                Dialogo.pedir('Escribí el estado de entrega de esta factura.', {
                    titulo: 'Otro estado', placeholder: 'Ej: ENTREGADO EN PORTERÍA', maximo: 80
                }).then(function (texto) {
                    if (!texto) { el.value = el.dataset.actual || ''; return; }
                    texto = texto.toUpperCase();
                    var opcion = document.createElement('option');
                    opcion.value = texto;
                    opcion.textContent = texto;
                    el.insertBefore(opcion, el.querySelector('option[value="__otro__"]'));
                    el.value = texto;
                    guardarEstadoManual(el, 'estado', texto);
                });
                return;
            }
            guardarEstadoManual(el, 'estado', el.value);
            return;
        }
        if (el.classList.contains('fecha-entrega-mr')) {
            guardarEstadoManual(el, 'fecha_entrega', el.value);
        }
    });

    function guardarEstadoManual(el, campo, valor) {
        el.disabled = true;
        enviar({ accion: 'estado_manual', factura: el.dataset.factura, campo: campo, valor: valor })
            .then(function (d) {
                if (!d.exito) {
                    Dialogo.avisar(d.error || 'No se pudo guardar.');
                    el.value = el.dataset.actual || (campo === 'fecha_entrega' ? (el.dataset.reporte || '') : '');
                    return;
                }
                el.dataset.actual = d.manual ? valor : '';
                if (campo === 'estado') {
                    el.className = 'sel-estado-mr ' + (d.color || 'estado-gris');
                } else if (!d.manual) {
                    el.value = el.dataset.reporte || '';   // sin fecha a mano, vuelve la del reporte
                }
                var nota = el.parentNode.querySelector('.nota-mano');
                if (d.manual && !nota) {
                    nota = document.createElement('span');
                    nota.className = 'nota-transportador nota-mano';
                    nota.textContent = 'a mano';
                    el.parentNode.appendChild(nota);
                } else if (!d.manual && nota) {
                    nota.remove();
                }
                el.classList.add('guardado');
                setTimeout(function () { el.classList.remove('guardado'); }, 1500);
            })
            .catch(function () {
                Dialogo.avisar('No se pudo contactar al servidor. Revisá la conexión e intentá de nuevo.');
                el.value = el.dataset.actual || '';
            })
            .finally(function () { el.disabled = false; });
    }

    // ---------------------------------------------------------------------------------------------
    // TRANSPORTADOR DE VARIAS FACTURAS: el modal que abre la barra de selección.
    // ---------------------------------------------------------------------------------------------
    var selMasivo   = document.getElementById('m-transportador');
    var campoOtro   = document.getElementById('m-transportador-otro-campo');
    var inputOtro   = document.getElementById('m-transportador-otro');
    var btnGuardarT = document.getElementById('btn-guardar-transportador');

    if (selMasivo && campoOtro) {
        selMasivo.addEventListener('change', function () {
            campoOtro.hidden = selMasivo.value !== '__otro__';
            if (!campoOtro.hidden && inputOtro) { inputOtro.focus(); }
        });
    }

    if (btnGuardarT) {
        btnGuardarT.addEventListener('click', function () {
            var facturas = seleccionadas();
            if (!facturas.length) { return; }

            var valor = selMasivo.value === '__otro__' ? (inputOtro.value || '').trim() : selMasivo.value;
            if (selMasivo.value === '__otro__' && !valor) {
                inputOtro.focus();
                return;
            }

            btnGuardarT.disabled = true;
            enviar({ accion: 'transportador', facturas: facturas, transportadora: valor })
                .then(function (d) {
                    if (!d.exito) {
                        Dialogo.avisar(d.error || 'No se pudo guardar el transportador.');
                        btnGuardarT.disabled = false;
                        return;
                    }
                    window.location.reload();
                })
                .catch(function () {
                    Dialogo.avisar('No se pudo contactar al servidor. Revisá la conexión e intentá de nuevo.');
                    btnGuardarT.disabled = false;
                });
        });
    }

    // ---------------------------------------------------------------------------------------------
    // DE CONTADO (2026-10-05): el Sí/No de cada fila se guarda apenas se elige; los botones de la
    // barra marcan varias a la vez con su modal de confirmación (y después se recarga).
    // ---------------------------------------------------------------------------------------------
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel.classList.contains('sel-contado')) { return; }
        sel.disabled = true;
        enviar({ accion: 'contado', facturas: [sel.dataset.factura], de_contado: sel.value === '1' })
            .then(function (d) {
                if (!d.exito) {
                    Dialogo.avisar(d.error || 'No se pudo guardar.');
                    sel.value = sel.dataset.actual;
                    return;
                }
                sel.dataset.actual = sel.value;
                sel.classList.toggle('es-si', sel.value === '1');
                sel.classList.add('guardado');
                setTimeout(function () { sel.classList.remove('guardado'); }, 1500);
            })
            .catch(function () {
                Dialogo.avisar('No se pudo contactar al servidor. Revisá la conexión e intentá de nuevo.');
                sel.value = sel.dataset.actual;
            })
            .finally(function () { sel.disabled = false; });
    });

    var contadoPendiente = null;   // el valor que se va a poner al confirmar el modal
    document.querySelectorAll('.btn-contado-masivo').forEach(function (boton) {
        boton.addEventListener('click', function () {
            contadoPendiente = boton.dataset.si === '1';
            var titulo = document.getElementById('m-contado-titulo');
            var estado = document.getElementById('m-contado-estado');
            if (titulo) { titulo.textContent = contadoPendiente ? 'Marcar de contado' : 'Marcar no de contado'; }
            if (estado) { estado.textContent = contadoPendiente ? 'de contado' : 'no de contado'; }
            refrescarSeleccion();
            abrirModal('modal-contado');
        });
    });

    var btnConfirmarContado = document.getElementById('btn-confirmar-contado');
    if (btnConfirmarContado) {
        btnConfirmarContado.addEventListener('click', function () {
            var facturas = seleccionadas();
            if (!facturas.length || contadoPendiente === null) { return; }
            btnConfirmarContado.disabled = true;
            enviar({ accion: 'contado', facturas: facturas, de_contado: contadoPendiente })
                .then(function (d) {
                    if (!d.exito) { Dialogo.avisar(d.error || 'No se pudo guardar.'); btnConfirmarContado.disabled = false; return; }
                    window.location.reload();
                })
                .catch(function () {
                    Dialogo.avisar('No se pudo contactar al servidor. Revisá la conexión e intentá de nuevo.');
                    btnConfirmarContado.disabled = false;
                });
        });
    }

    refrescarSeleccion();
})();
