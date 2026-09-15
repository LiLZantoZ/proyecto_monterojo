// assets/js/autocompletar.js
// Reemplaza la lista de sugerencias del navegador (<input list> + <datalist>) por una propia, con el
// estilo del sistema.
//
// POR QUÉ
// La lista de un <datalist> la dibuja el navegador y no hay CSS que la alcance: en Windows con tema
// oscuro sale como un recuadro negro, pegado a una pantalla clara con la marca del sistema. Los
// <select> sí se pueden estilar ahora con CSS (ver 06-listas-desplegables.css); esto no.
//
// CÓMO SE USA
// No hay que llamar a nada. Se escribe el HTML de siempre:
//
//     <input type="text" name="oc" list="lista-ordenes">
//     <datalist id="lista-ordenes"><option value="0138604723">...</datalist>
//
// y este archivo lo convierte solo al cargar la página. Lo carga el menú lateral, así que vale para
// cualquier pantalla privada, incluidas las que se agreguen después. Si el JavaScript no carga, el
// campo sigue funcionando con la lista del navegador: el <datalist> queda en el HTML.
//
// Sin librerías externas, por regla del proyecto.

(function () {
    'use strict';

    // Para comparar sin distinguir mayúsculas ni tildes: quien busca "platano" quiere "PLÁTANO".
    //
    // Letra por letra y no el texto entero de una vez: normalize('NFD') separa la tilde en un
    // carácter aparte y alarga el texto, así que la posición encontrada ya no coincidiría con la del
    // texto original y el resaltado quedaría corrido. Así cada letra sigue ocupando un lugar.
    function normalizar(texto) {
        return Array.from(String(texto), function (c) {
            return c.normalize('NFD').replace(/[̀-ͯ]/g, '') || c;
        }).join('').toLowerCase();
    }

    var contador = 0;

    function iniciar(input) {
        if (input.dataset.autocompletar) { return; }

        var datalist = document.getElementById(input.getAttribute('list'));
        if (!datalist) { return; }

        input.dataset.autocompletar = '1';

        var opciones = Array.prototype.map.call(datalist.options, function (o) { return o.value; })
            .filter(function (v) { return v !== ''; });

        // Sin el atributo list, el navegador ya no abre su propia lista encima de la nuestra.
        input.removeAttribute('list');
        input.setAttribute('autocomplete', 'off');

        var id = input.id || ('autocompletar-' + (++contador));
        if (!input.id) { input.id = id; }

        var caja = document.createElement('div');
        caja.className = 'autocompletar';
        input.parentNode.insertBefore(caja, input);
        caja.appendChild(input);

        var boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'autocompletar-boton';
        boton.tabIndex = -1;                      // con Tab se llega al campo, no a la flecha
        boton.setAttribute('aria-label', 'Ver todas las opciones');
        boton.innerHTML = '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
        caja.appendChild(boton);

        var lista = document.createElement('ul');
        lista.className = 'autocompletar-lista';
        lista.id = id + '-opciones';
        lista.setAttribute('role', 'listbox');
        lista.hidden = true;
        caja.appendChild(lista);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', lista.id);
        input.setAttribute('aria-expanded', 'false');

        var visibles = [];
        var activa = -1;

        // El texto de la opción con lo buscado resaltado. Se arma con nodos de texto y no con
        // innerHTML: las opciones pueden venir de datos cargados por un archivo.
        function textoResaltado(valor, buscado) {
            var fragmento = document.createDocumentFragment();
            var pos = buscado === '' ? -1 : normalizar(valor).indexOf(normalizar(buscado));

            if (pos < 0) {
                fragmento.appendChild(document.createTextNode(valor));
                return fragmento;
            }
            var marca = document.createElement('mark');
            marca.textContent = valor.substr(pos, buscado.length);
            fragmento.appendChild(document.createTextNode(valor.slice(0, pos)));
            fragmento.appendChild(marca);
            fragmento.appendChild(document.createTextNode(valor.slice(pos + buscado.length)));
            return fragmento;
        }

        function pintar() {
            var buscado = input.value.trim();
            var clave = normalizar(buscado);

            visibles = opciones.filter(function (v) { return clave === '' || normalizar(v).indexOf(clave) !== -1; });
            activa = -1;
            lista.innerHTML = '';

            if (!visibles.length) {
                var vacio = document.createElement('li');
                vacio.className = 'autocompletar-vacio';
                vacio.textContent = 'Sin coincidencias';
                lista.appendChild(vacio);
            }

            visibles.forEach(function (valor, i) {
                var li = document.createElement('li');
                li.className = 'autocompletar-opcion';
                li.id = lista.id + '-' + i;
                li.setAttribute('role', 'option');
                li.dataset.indice = i;
                li.appendChild(textoResaltado(valor, buscado));
                lista.appendChild(li);
            });

            // Cuántas hay y cuántas coinciden: con una lista larga, saber que son 3 de 40 dice
            // enseguida si hace falta escribir más.
            if (opciones.length > 5) {
                var pie = document.createElement('li');
                pie.className = 'autocompletar-contador';
                pie.setAttribute('aria-hidden', 'true');
                pie.textContent = clave === ''
                    ? opciones.length + ' opciones'
                    : visibles.length + ' de ' + opciones.length;
                lista.appendChild(pie);
            }
        }

        function marcar(indice) {
            var anterior = lista.querySelector('.autocompletar-opcion.activa');
            if (anterior) { anterior.classList.remove('activa'); anterior.removeAttribute('aria-selected'); }

            activa = indice;
            if (indice < 0) {
                input.removeAttribute('aria-activedescendant');
                return;
            }
            var li = document.getElementById(lista.id + '-' + indice);
            li.classList.add('activa');
            li.setAttribute('aria-selected', 'true');
            input.setAttribute('aria-activedescendant', li.id);
            li.scrollIntoView({ block: 'nearest' });
        }

        function abrir() {
            pintar();
            lista.hidden = false;
            caja.classList.add('abierto');
            input.setAttribute('aria-expanded', 'true');
        }

        function cerrar() {
            lista.hidden = true;
            caja.classList.remove('abierto');
            input.setAttribute('aria-expanded', 'false');
            marcar(-1);
        }

        function elegir(indice) {
            input.value = visibles[indice];
            cerrar();
            // Por si otra parte de la pantalla escucha el campo.
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        input.addEventListener('input', abrir);
        input.addEventListener('click', function () { if (lista.hidden) { abrir(); } });
        input.addEventListener('blur', cerrar);

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (lista.hidden) { abrir(); }
                if (!visibles.length) { return; }
                var siguiente = e.key === 'ArrowDown'
                    ? (activa + 1) % visibles.length
                    : (activa <= 0 ? visibles.length - 1 : activa - 1);
                marcar(siguiente);
            } else if (e.key === 'Enter') {
                // Con una opción marcada, Enter la elige. Sin ninguna, Enter hace lo de siempre:
                // enviar el formulario con lo que se escribió, que también es una búsqueda válida.
                if (!lista.hidden && activa >= 0) {
                    e.preventDefault();
                    elegir(activa);
                }
            } else if (e.key === 'Escape') {
                if (!lista.hidden) { e.preventDefault(); cerrar(); }
            }
        });

        // mousedown y no click: con click, el campo pierde el foco antes, se dispara blur, la lista
        // se cierra y el clic cae en el vacío.
        lista.addEventListener('mousedown', function (e) {
            e.preventDefault();
            var li = e.target.closest('.autocompletar-opcion');
            if (li) { elegir(Number(li.dataset.indice)); }
        });

        lista.addEventListener('mousemove', function (e) {
            var li = e.target.closest('.autocompletar-opcion');
            if (li && Number(li.dataset.indice) !== activa) { marcar(Number(li.dataset.indice)); }
        });

        boton.addEventListener('mousedown', function (e) {
            e.preventDefault();
            if (lista.hidden) {
                // La flecha muestra TODAS las opciones, aunque el campo tenga algo escrito: es para
                // cuando no se recuerda el número y se quiere recorrer la lista.
                var escrito = input.value;
                input.value = '';
                abrir();
                input.value = escrito;
            } else {
                cerrar();
            }
            input.focus();
        });
    }

    function iniciarTodos() {
        document.querySelectorAll('input[list]').forEach(iniciar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarTodos);
    } else {
        iniciarTodos();
    }
})();
