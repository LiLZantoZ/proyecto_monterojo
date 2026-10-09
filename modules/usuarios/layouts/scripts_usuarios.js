// modules/usuarios/layouts/scripts_usuarios.js
// Administrar usuarios: vuelca los datos de la fila en el modal de editar o de eliminar, muestra la
// foto elegida antes de subirla, la descripción del rol y la búsqueda mientras se escribe.
// Los modales los abre y cierra assets/js/modales.js; las confirmaciones, el modal de eliminar.

(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };

    // La descripción del rol elegido, debajo del desplegable.
    function mostrarAyudaRol(select) {
        var ayuda = $(select.dataset.ayuda);
        var opcion = select.options[select.selectedIndex];
        if (ayuda) {
            ayuda.textContent = (opcion && opcion.dataset.descripcion) || 'Lo que puede ver y hacer en el sistema.';
        }
    }

    document.querySelectorAll('select[data-ayuda]').forEach(function (s) {
        s.addEventListener('change', function () { mostrarAyudaRol(s); });
    });

    // La foto elegida se ve antes de guardar.
    document.querySelectorAll('input[type="file"][data-vista]').forEach(function (input) {
        input.addEventListener('change', function () {
            var vista = $(input.dataset.vista);
            var archivo = input.files && input.files[0];
            if (!vista) { return; }
            if (!archivo) { vista.src = vista.dataset.actual || vista.dataset.porDefecto; return; }
            if (archivo.size > 3 * 1024 * 1024) {
                input.value = '';
                vista.src = vista.dataset.actual || vista.dataset.porDefecto;
                Dialogo.avisar('La foto no puede pesar más de 3 MB.', { titulo: 'Foto muy pesada' });
                return;
            }
            vista.src = URL.createObjectURL(archivo);
        });
    });

    // Las dos contraseñas tienen que coincidir (el servidor lo vuelve a revisar).
    document.querySelectorAll('.form-usuario').forEach(function (form) {
        var clave = form.querySelector('[name="contrasena"]');
        var confirmar = form.querySelector('[name="contrasena_confirmar"]');
        function revisar() {
            confirmar.setCustomValidity(confirmar.value !== clave.value ? 'No coincide con la contraseña.' : '');
            confirmar.required = clave.value !== '' || clave.required;
        }
        clave.addEventListener('input', revisar);
        confirmar.addEventListener('input', revisar);
        form.addEventListener('submit', function () {
            var boton = form.querySelector('button[type="submit"]');
            if (boton) { boton.disabled = true; boton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando…'; }
        });
    });

    // Agregar: el formulario arranca limpio cada vez.
    document.querySelectorAll('[data-abrir="modal-crear"]').forEach(function (b) {
        b.addEventListener('click', function () {
            var form = $('modal-crear').querySelector('form');
            form.reset();
            var vista = $('crear-foto-vista');
            vista.src = vista.dataset.porDefecto;
            mostrarAyudaRol($('crear-rol'));
        });
    });

    document.addEventListener('click', function (e) {
        var editar = e.target.closest('.btn-editar-usuario');
        if (editar) {
            var d = editar.dataset;
            $('modal-editar').querySelector('form').reset();
            $('editar-id').value = d.id;
            $('editar-nombre').value = d.nombre;
            $('editar-cedula').value = d.cedula;
            $('editar-telefono').value = d.telefono;
            $('editar-estado').value = d.estado;
            $('editar-rol').value = d.rol;
            mostrarAyudaRol($('editar-rol'));
            var vista = $('editar-foto-vista');
            vista.dataset.actual = d.foto;
            vista.src = d.foto;
            $('editar-aviso-yo').hidden = d.yo !== '1';
            $('modal-editar').classList.add('active');
            $('editar-nombre').focus();
            return;
        }

        var eliminar = e.target.closest('.btn-eliminar-usuario');
        if (eliminar) {
            var acciones = parseInt(eliminar.dataset.acciones, 10) || 0;
            $('eliminar-id').value = eliminar.dataset.id;
            $('eliminar-nombre').textContent = eliminar.dataset.nombre;
            var aviso = $('eliminar-aviso');
            if (acciones > 0) {
                $('eliminar-aviso-texto').innerHTML = 'Tiene <strong>' + acciones.toLocaleString('es-CO') + '</strong> acción(es) registradas. '
                    + 'Siguen en Trazabilidad, pero como «Usuario eliminado».'
                    + (eliminar.dataset.estado === 'Activo' ? ' Si solo dejó de trabajar, mejor editala y ponela <strong>Inactiva</strong>.' : '');
                aviso.hidden = false;
            } else {
                aviso.hidden = true;
            }
            $('modal-eliminar').classList.add('active');
        }
    });

    // Buscar mientras se escribe (como en el sistema de bodega): espera una pausa y busca.
    var buscador = $('buscar-usuario');
    if (buscador) {
        var espera = null;
        var ultimo = buscador.value;
        buscador.addEventListener('input', function () {
            clearTimeout(espera);
            espera = setTimeout(function () {
                if (buscador.value.trim() !== ultimo.trim()) { buscador.form.submit(); }
            }, 500);
        });
        // Al volver de una búsqueda, el cursor queda al final del texto para seguir escribiendo.
        if (buscador.value !== '') {
            buscador.focus();
            buscador.setSelectionRange(buscador.value.length, buscador.value.length);
        }
    }

    // El backup tarda unos segundos: el botón lo dice, y vuelve cuando ya empezó la descarga.
    var backup = $('form-backup');
    if (backup) {
        backup.addEventListener('submit', function () {
            var boton = backup.querySelector('button');
            var texto = boton.innerHTML;
            setTimeout(function () { boton.disabled = true; boton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generando backup…'; }, 0);
            setTimeout(function () { boton.disabled = false; boton.innerHTML = texto; }, 8000);
        });
    }
})();
