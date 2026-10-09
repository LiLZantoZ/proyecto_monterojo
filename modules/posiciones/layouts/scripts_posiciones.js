// modules/posiciones/layouts/scripts_posiciones.js
// Posiciones de bodega: el modal de cada tarjeta (ver, agregar, editar o sacar la estiba), los de
// crear y editar una posición y los filtros que aplican solos. Los modales se abren y se cierran con
// assets/js/modales.js (clase .active).

(function () {
    'use strict';

    var CONF = window.POSICIONES || { niveles: {}, puedeMover: false, puedeEditar: false };

    function abrir(modal) {
        modal.classList.add('active');
    }

    // ---------------------------------------------------------------------------------------
    // LOS FILTROS: los desplegables aplican al cambiarlos; "Buscar" con Enter o el botón.
    // ---------------------------------------------------------------------------------------
    var formFiltros = document.querySelector('form.filtros-posiciones');
    if (formFiltros) {
        formFiltros.querySelectorAll('select').forEach(function (campo) {
            campo.addEventListener('change', function () {
                if (formFiltros.requestSubmit) { formFiltros.requestSubmit(); } else { formFiltros.submit(); }
            });
        });
    }

    // ---------------------------------------------------------------------------------------
    // DETALLES DE LA POSICIÓN (clic en la tarjeta).
    // Libre: el formulario vacío y "Agregar estiba". Con estiba: los datos bloqueados y, según los
    // permisos, "Editar" (desbloquea → "Guardar cambios") y "Sacar estiba".
    // ---------------------------------------------------------------------------------------
    var modalDetalle = document.getElementById('modal-detalle-posicion');
    var form = document.getElementById('form-estiba');

    function campo(nombre) { return form.querySelector('[name="' + nombre + '"]'); }
    function texto(nombre) { return modalDetalle.querySelector('[data-campo="' + nombre + '"]'); }
    function boton(nombre) { return form.querySelector('[data-boton="' + nombre + '"]'); }
    function mostrar(el, si) { if (el) { el.hidden = !si; } }

    var editables = ['sku', 'cantidad_cajas', 'estiba_completa', 'lote', 'fecha_vencimiento', 'estado', 'observaciones'];

    function bloquear(si) {
        editables.forEach(function (n) { campo(n).disabled = si; });
        if (!si) { reglaCajasEstiba(); }
    }

    // Como en bodega: o se escriben las cajas, o se marca "estiba completa"; nunca las dos.
    function reglaCajasEstiba() {
        var cajas = campo('cantidad_cajas'), completa = campo('estiba_completa');
        if (completa.checked) { cajas.value = ''; }
        cajas.disabled = completa.checked;
        completa.disabled = cajas.value.trim() !== '';
    }

    // El nombre del producto del SKU escrito (sale de la lista del datalist).
    var ayudaProducto = '';
    function mostrarProducto() {
        var sku = campo('sku').value.trim();
        var ayuda = texto('producto');
        if (!ayudaProducto) { ayudaProducto = ayuda.textContent; }
        var opcion = sku ? document.querySelector('#lista-skus-posiciones option[value="' + CSS.escape(sku) + '"]') : null;
        if (!sku) {
            ayuda.textContent = ayudaProducto;
            ayuda.classList.remove('ayuda-error');
        } else if (opcion) {
            ayuda.textContent = opcion.textContent || 'SKU encontrado (sin descripción en el maestro).';
            ayuda.classList.remove('ayuda-error');
        } else if (document.getElementById('lista-skus-posiciones')) {
            ayuda.textContent = 'Ese SKU no está en el maestro de productos ni en la lista de Monterojo.';
            ayuda.classList.add('ayuda-error');
        }
    }

    // LO REGISTRADO EN EL FORMATO CONCILIADOR: al completar SKU + lote + vencimiento se informa
    // cuántas estibas de ese producto hay registradas y cuántas ya están ubicadas. Solo informa:
    // desde el 2026-10-06 la estiba se ubica igual (en bodega sin registro no se podía).
    var posicionAbierta = null, temporizadorCupo = null;
    function consultarCupo() {
        var caja = texto('cupo');
        var sku = campo('sku').value.trim(), lote = campo('lote').value.trim(), vence = campo('fecha_vencimiento').value;
        if (!CONF.urlCupo || !sku || !lote || !vence || campo('sku').disabled) { mostrar(caja, false); return; }
        var url = CONF.urlCupo + '&sku=' + encodeURIComponent(sku) + '&lote=' + encodeURIComponent(lote)
                + '&fecha_vencimiento=' + encodeURIComponent(vence) + '&id_posicion=' + (posicionAbierta && posicionAbierta.tiene ? posicionAbierta.id : 0);
        fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (c) {
            caja.classList.remove('cupo-ok', 'cupo-no', 'cupo-info');
            if (!c || !c.cupo) {
                caja.textContent = 'Este producto no está registrado en el Formato Conciliador (se puede ubicar igual).';
                caja.classList.add('cupo-info');
            } else {
                caja.textContent = 'Formato Conciliador: ' + c.cupo + ' estiba(s) registrada(s), ' + c.usadas + ' ya ubicada(s)'
                    + (c.disponibles > 0 ? ', faltan ' + c.disponibles + ' por ubicar.' : '.');
                caja.classList.add('cupo-ok');
            }
            mostrar(caja, true);
        }).catch(function () { mostrar(caja, false); });
    }
    function programarCupo() {
        clearTimeout(temporizadorCupo);
        temporizadorCupo = setTimeout(consultarCupo, 300);
    }

    function abrirDetalle(p) {
        form.reset();
        campo('id_posicion').value = p.id;
        campo('accion').value = 'agregar_estiba';
        texto('ubicacion').textContent = p.ubicacion;
        texto('donde').textContent = 'Rack ' + p.rack + ' · Módulo ' + p.modulo + ' · Nivel ' + p.nivel + ' · Posición ' + p.lugar;
        texto('registro').textContent = p.registro || '';
        var chip = texto('estado-visto');
        chip.textContent = p.tiene ? p.estado_visto : 'Libre';
        chip.className = 'chip-estado-posicion ' + (p.tiene ? 'estado-' + String(p.estado_visto).toLowerCase().replace(/ /g, '-') : 'estado-libre');

        if (p.tiene) {
            campo('sku').value = p.sku || '';
            campo('cantidad_cajas').value = p.completa ? '' : (p.cajas || '');
            campo('estiba_completa').checked = !!p.completa;
            campo('lote').value = p.lote || '';
            campo('fecha_vencimiento').value = p.vence || '';
            campo('estado').value = p.estado || 'Disponible';
            campo('observaciones').value = p.observaciones || '';
        }
        mostrarProducto();
        if (p.tiene && !campo('sku').value) { texto('producto').textContent = ''; }
        if (p.tiene && p.producto) { texto('producto').textContent = p.producto; texto('producto').classList.remove('ayuda-error'); }

        // Libre y con permiso de mover: se puede cargar. Con estiba: se ve bloqueada.
        bloquear(p.tiene || !CONF.puedeMover);
        mostrar(boton('agregar'), !p.tiene && CONF.puedeMover);
        mostrar(boton('sacar'), p.tiene && CONF.puedeMover);
        mostrar(boton('picking'), p.tiene && CONF.puedePicking);
        posicionAbierta = p;
        mostrar(texto('cupo'), false);
        if (!p.tiene) { programarCupo(); }
        mostrar(boton('editar'), p.tiene && CONF.puedeEditar);
        mostrar(boton('guardar'), false);
        abrir(modalDetalle);
    }

    if (form) {
        campo('cantidad_cajas').addEventListener('input', reglaCajasEstiba);
        campo('estiba_completa').addEventListener('change', reglaCajasEstiba);
        campo('sku').addEventListener('input', mostrarProducto);
        ['sku', 'lote', 'fecha_vencimiento'].forEach(function (n) {
            campo(n).addEventListener('input', programarCupo);
            campo(n).addEventListener('change', programarCupo);
        });

        var bPicking = boton('picking');
        if (bPicking) {
            bPicking.addEventListener('click', function () {
                var ubicacion = texto('ubicacion').textContent;
                Dialogo.confirmar('La posición ' + ubicacion + ' queda libre y el producto sale de Administrar Productos (solo el de esta estiba).', {
                    titulo: '¿Llevar la estiba a picking?', aceptar: 'Sí, llevar a picking'
                }).then(function (si) {
                    if (!si) { return; }
                    campo('accion').value = 'llevar_a_picking';
                    form.submit();
                });
            });
        }

        var bEditar = boton('editar');
        if (bEditar) {
            bEditar.addEventListener('click', function () {
                bloquear(false);
                mostrar(bEditar, false);
                mostrar(boton('guardar'), true);
                campo('sku').focus();
                programarCupo();
            });
        }
        var bGuardar = boton('guardar');
        if (bGuardar) { bGuardar.addEventListener('click', function () { campo('accion').value = 'actualizar_estiba'; }); }
        var bAgregar = boton('agregar');
        if (bAgregar) { bAgregar.addEventListener('click', function () { campo('accion').value = 'agregar_estiba'; }); }

        var bSacar = boton('sacar');
        if (bSacar) {
            bSacar.addEventListener('click', function () {
                var ubicacion = texto('ubicacion').textContent;
                Dialogo.confirmar('La posición ' + ubicacion + ' queda libre. El producto sigue en Administrar Productos, sin ubicar.', {
                    titulo: '¿Sacar la estiba?', aceptar: 'Sí, sacarla', peligro: true
                }).then(function (si) {
                    if (!si) { return; }
                    campo('accion').value = 'sacar_estiba';
                    form.submit();   // sin validar los campos: no hacen falta para sacarla
                });
            });
        }

        // Al enviar, el botón se apaga para no mandar dos veces.
        form.addEventListener('submit', function (e) {
            var b = e.submitter;
            if (b) { b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando…'; }
        });
    }

    // ---------------------------------------------------------------------------------------
    // CREAR / EDITAR UNA POSICIÓN: el código se arma solo y el nivel respeta el módulo 12.
    // ---------------------------------------------------------------------------------------
    function prepararFormPosicion(f) {
        var sel = function (n) { return f.querySelector('[name="' + n + '"]'); };
        var actualizar = function () {
            var niveles = CONF.niveles[sel('modulo').value] || [1, 2, 3, 4, 5];
            Array.prototype.forEach.call(sel('nivel').options, function (o) {
                o.disabled = niveles.indexOf(parseInt(o.value, 10)) === -1;
            });
            if (sel('nivel').selectedOptions[0] && sel('nivel').selectedOptions[0].disabled) { sel('nivel').value = String(niveles[0]); }
            f.querySelector('[data-campo="codigo"]').textContent =
                'R' + sel('rack').value + 'M' + sel('modulo').value + 'N' + sel('nivel').value + sel('lugar').value;
        };
        ['rack', 'modulo', 'nivel', 'lugar'].forEach(function (n) { sel(n).addEventListener('change', actualizar); });
        f.actualizarCodigo = actualizar;
        f.cargar = function (p) {
            sel('id_posicion').value = p ? p.id : '';
            sel('rack').value = p ? p.rack : 1;
            sel('modulo').value = p ? p.modulo : 1;
            sel('nivel').value = p ? p.nivel : 1;
            sel('lugar').value = p ? p.lugar : 'A1';
            actualizar();
        };
        var bEliminar = f.querySelector('[data-boton="eliminar-posicion"]');
        if (bEliminar) {
            bEliminar.addEventListener('click', function () {
                Dialogo.confirmar('Solo se puede si no tiene ninguna estiba.', {
                    titulo: '¿Eliminar esta posición?', aceptar: 'Sí, eliminar', peligro: true
                }).then(function (si) {
                    if (!si) { return; }
                    sel('accion').value = 'eliminar';
                    f.submit();
                });
            });
        }
        actualizar();
    }

    var formCrear = document.querySelector('#modal-crear-posicion form');
    var modalEditar = document.getElementById('modal-editar-posicion');
    var formEditar = modalEditar ? modalEditar.querySelector('form') : null;
    if (formCrear) { prepararFormPosicion(formCrear); }
    if (formEditar) { prepararFormPosicion(formEditar); }

    // ---------------------------------------------------------------------------------------
    // CLIC EN LA GRILLA: el lápiz edita la posición; el resto de la tarjeta abre los detalles.
    // ---------------------------------------------------------------------------------------
    var grilla = document.querySelector('.grid-posiciones');
    function datos(tarjeta) {
        try { return JSON.parse(tarjeta.dataset.posicion); } catch (e) { return null; }
    }
    if (grilla) {
        grilla.addEventListener('click', function (e) {
            var tarjeta = e.target.closest('.card-posicion');
            if (!tarjeta) { return; }
            var p = datos(tarjeta);
            if (!p) { return; }
            if (e.target.closest('.btn-editar-posicion')) {
                if (formEditar) {
                    formEditar.cargar(p);
                    modalEditar.querySelector('h2').lastChild.textContent = ' Editar posición ' + p.ubicacion;
                    abrir(modalEditar);
                }
                return;
            }
            if (form) { abrirDetalle(p); }
        });
        // Con el teclado: Enter sobre una tarjeta la abre.
        grilla.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.classList.contains('card-posicion')) {
                var p = datos(e.target);
                if (p && form) { abrirDetalle(p); }
            }
        });
    }
})();
