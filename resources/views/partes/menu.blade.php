{{-- El menú lateral. Va DENTRO de <div class="app-shell">, antes del .content-area (lo pone
     layouts/app). El nombre, el rol y la foto del usuario los pone AppServiceProvider.

     OJO: ocultar un enlace NO es control de acceso. Cada ruta lleva además su middleware
     permiso:modulo_x (routes/web.php), o se entraría escribiendo la dirección a mano. --}}
@php
    // Los módulos, en el orden del menú: [permiso, ruta, icono, texto].
    $modulosMenu = [
        ['modulo_consolidados',      'consolidados',      'fa-boxes-stacked',        'Consolidados'],
        ['modulo_picking',           'picking',           'fa-cart-flatbed',         'Picking'],
        ['modulo_maestro',           'maestro',           'fa-list-check',           'Maestro de productos'],
        ['modulo_cajas_punto_venta', 'cajas_punto_venta', 'fa-truck-ramp-box',       'Cajas por punto de venta (Exito)'],
        ['modulo_ordenes_compra',    'ordenes_compra',    'fa-file-invoice-dollar',  'Órdenes de compra (Éxito)'],
        ['modulo_seguimiento',       'seguimiento',       'fa-truck-fast',           'Estado de pedidos'],
        ['modulo_consolidado_mr',    'consolidado_mr',    'fa-file-invoice-dollar',  'Consolidado MR'],
        ['modulo_historial',         'historial',         'fa-clock-rotate-left',    'Historial de Pedidos'],
        ['modulo_rotulos',           'rotulos',           'fa-tags',                 'Generar rótulos'],
    ];
@endphp

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="{{ assetV('assets/css/sidebar.css') }}">

@include('partes.mensaje')

{{-- Solo se ve en pantallas angostas; en escritorio el CSS lo oculta. --}}
<button type="button" class="btn-menu" id="btn-menu"
        aria-label="Abrir el menú" aria-expanded="false" aria-controls="sidebar-principal">
    <i class="fa-solid fa-bars"></i>
</button>
<div class="fondo-menu" id="fondo-menu"></div>

<aside class="sidebar" id="sidebar-principal">

    <div class="sidebar-header">
        <img src="{{ asset('assets/img/monterojo.png') }}" alt="Monterojo Gourmet" class="sidebar-marca">
        <span class="sidebar-sistema">{{ NOMBRE_SISTEMA }}</span>
    </div>

    <nav class="sidebar-nav">
        <a href="{{ route('inicio') }}" class="nav-link">
            <i class="fa-solid fa-house"></i>
            <span>Inicio</span>
        </a>

        @foreach ($modulosMenu as [$permiso, $ruta, $icono, $texto])
            @if (tienePermiso($permiso))
                <a href="{{ route($ruta) }}" class="nav-link">
                    <i class="fa-solid {{ $icono }}"></i>
                    <span>{{ $texto }}</span>
                </a>
            @endif
        @endforeach

        <a href="{{ route('salir') }}" class="nav-link nav-link-logout">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Cerrar sesión</span>
        </a>
    </nav>

    {{-- Lleva a "Mi perfil": la única forma de editar el nombre, la foto o la contraseña propios.
         Sin el permiso perfil_editar (el rol Visitante) la tarjeta queda igual pero NO es un enlace
         ni lleva el lápiz: un botón que después rebota con "acceso denegado" solo confunde. --}}
    @if (tienePermiso('perfil_editar'))
        <a class="sidebar-footer" href="{{ route('perfil') }}" title="Editar mi perfil">
            <img src="{{ $imagenRuta }}" alt="Avatar de {{ $nombreUsuario }}" class="user-avatar">

            <div class="user-details">
                <span class="user-name">{{ $nombreUsuario }}</span>
                <span class="user-role">{{ $rolUsuario }}</span>
            </div>

            <i class="fa-solid fa-pen sidebar-footer-editar" aria-hidden="true"></i>
        </a>
    @else
        <div class="sidebar-footer sidebar-footer-fijo">
            <img src="{{ $imagenRuta }}" alt="Avatar de {{ $nombreUsuario }}" class="user-avatar">

            <div class="user-details">
                <span class="user-name">{{ $nombreUsuario }}</span>
                <span class="user-role">{{ $rolUsuario }}</span>
            </div>
        </div>
    @endif
</aside>

{{-- Las listas de sugerencias con el estilo del sistema, en vez de la del navegador. --}}
<script src="{{ assetV('assets/js/autocompletar.js') }}" defer></script>

<script>
    // Marca en qué pantalla está parado el usuario.
    (function () {
        var actual = window.location.pathname.replace(/\/+$/, '');
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(function (enlace) {
            if (enlace.classList.contains('nav-link-logout')) { return; }
            var destino = new URL(enlace.href, window.location.origin).pathname.replace(/\/+$/, '');
            if (destino === actual) { enlace.classList.add('active'); }
        });
    })();

    // Menú plegable de las pantallas angostas.
    (function () {
        var boton   = document.getElementById('btn-menu');
        var menu    = document.getElementById('sidebar-principal');
        var cortina = document.getElementById('fondo-menu');
        if (!boton || !menu || !cortina) { return; }

        function abrir(mostrar) {
            menu.classList.toggle('sidebar-abierto', mostrar);
            cortina.classList.toggle('activo', mostrar);
            boton.setAttribute('aria-expanded', mostrar ? 'true' : 'false');
        }

        boton.addEventListener('click', function () {
            abrir(!menu.classList.contains('sidebar-abierto'));
        });
        cortina.addEventListener('click', function () { abrir(false); });
        menu.querySelectorAll('.nav-link').forEach(function (enlace) {
            enlace.addEventListener('click', function () { abrir(false); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { abrir(false); }
        });
    })();

    // Cierre de sesión automático por inactividad. Es COMODIDAD, no seguridad: el que manda es el
    // middleware SesionActiva, que revisa el tiempo en el servidor en cada pedido.
    (function () {
        var URL_LOGOUT = @json(route('salir') . '?motivo=inactividad');
        var LIMITE_MS  = {{ TIEMPO_INACTIVIDAD_SEGUNDOS * 1000 }};
        var temporizador;

        function reiniciar() {
            clearTimeout(temporizador);
            temporizador = setTimeout(function () { window.location.href = URL_LOGOUT; }, LIMITE_MS);
        }

        ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (evento) {
            document.addEventListener(evento, reiniciar, { passive: true });
        });

        reiniciar();
    })();
</script>
