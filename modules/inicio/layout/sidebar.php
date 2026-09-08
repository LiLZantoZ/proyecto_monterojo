<?php
// modules/inicio/layout/sidebar.php
// El menú lateral. Se incluye DENTRO de <div class="app-shell">, antes del .content-area.
//
// Lee lo que necesita de $_SESSION por su cuenta. En el sistema de bodega dependía de que la
// vista que lo incluía hubiera definido antes $nombreUsuario, $rolUsuario y $imagenRuta: diez
// líneas idénticas copiadas en 24 archivos, y una vista que se olvidara de alguna mostraba el
// pie del menú vacío sin que nada fallara de forma visible.
require_once __DIR__ . '/../../../config/permisos.php';

$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$rolUsuario    = $_SESSION['nombre_rol'] ?? 'Sin rol asignado';
$imagenRuta    = rutaImagenPerfil($_SESSION['usuario_imagen'] ?? '');
?>

<!-- Iconos y estilos del menú -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/inicio/layout/style_sidebar.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/inicio/layout/style_sidebar.css'); ?>">

<!-- Aviso flotante del resultado de la última acción (guardado, error, permiso denegado...).
     Va acá porque el sidebar lo incluyen todas las pantallas privadas: así cualquier controlador
     que redirija con ?error= o ?exito= muestra el aviso sin que haya que tocar su vista. -->
<?php include __DIR__ . '/mensaje_sistema.php'; ?>

<!-- Solo se ve en pantallas angostas, donde los 260 px del menú se comen un tercio del ancho.
     En escritorio el CSS lo oculta y la barra queda siempre a la vista. -->
<button type="button" class="btn-menu" id="btn-menu"
        aria-label="Abrir el menú" aria-expanded="false" aria-controls="sidebar-principal">
    <i class="fa-solid fa-bars"></i>
</button>
<div class="fondo-menu" id="fondo-menu"></div>

<aside class="sidebar" id="sidebar-principal">

    <div class="sidebar-header">
        <img src="<?php echo BASE_URL; ?>/assets/img/monterojo.png"
             alt="Monterojo Gourmet" class="sidebar-marca">
        <span class="sidebar-sistema"><?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></span>
    </div>

    <nav class="sidebar-nav">
        <a href="<?php echo urlPanelDelRol($_SESSION['usuario_rol'] ?? null); ?>" class="nav-link">
            <i class="fa-solid fa-house"></i>
            <span>Inicio</span>
        </a>

        <!-- ---------------------------------------------------------------------------------
             LOS MÓDULOS. Cada uno va envuelto en su `if (tienePermiso(...))`, y no en un
             `if ($_SESSION['usuario_rol'] == 1)`: con la comparación de rol escrita a mano,
             agregar un rol obliga a revisar el proyecto entero buscando ifs.

             El permiso se da de alta en scripts/crear_esquema_completo.php, que es la
             definición de qué ve cada rol.

             Y OJO: ocultar el enlace NO es control de acceso. El controlador y la vista del
             módulo llaman además a requierePermiso(), o se entra escribiendo la URL a mano.
             --------------------------------------------------------------------------------- -->

        <?php if (tienePermiso('modulo_consolidados')): ?>
            <a href="<?php echo BASE_URL; ?>/modules/consolidados/views/consolidados.php" class="nav-link">
                <i class="fa-solid fa-boxes-stacked"></i>
                <span>Consolidados</span>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('modulo_picking')): ?>
            <a href="<?php echo BASE_URL; ?>/modules/picking/views/picking.php" class="nav-link">
                <i class="fa-solid fa-cart-flatbed"></i>
                <span>Picking</span>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('modulo_maestro')): ?>
            <a href="<?php echo BASE_URL; ?>/modules/consolidados/views/maestro.php" class="nav-link">
                <i class="fa-solid fa-list-check"></i>
                <span>Maestro de productos</span>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('modulo_historial')): ?>
            <a href="<?php echo BASE_URL; ?>/modules/historial/views/historial.php" class="nav-link">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Historial de Pedidos</span>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('modulo_rotulos')): ?>
            <a href="<?php echo BASE_URL; ?>/modules/rotulos/views/rotulos.php" class="nav-link">
                <i class="fa-solid fa-tags"></i>
                <span>Generar rótulos</span>
            </a>
        <?php endif; ?>

        <a href="<?php echo BASE_URL; ?>/modules/login/controller/logout.php" class="nav-link nav-link-logout">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Cerrar sesión</span>
        </a>
    </nav>

    <!-- Lleva a "Mi perfil": es la única forma de editar el nombre, la foto o la contraseña
         propios. Un <a> y no un botón con JS porque es una navegación normal a otra pantalla,
         no una acción que cambie algo acá mismo. -->
    <a class="sidebar-footer" href="<?php echo BASE_URL; ?>/modules/perfil/views/perfil.php"
       title="Editar mi perfil">
        <img src="<?php echo htmlspecialchars($imagenRuta); ?>"
             alt="Avatar de <?php echo htmlspecialchars($nombreUsuario); ?>"
             class="user-avatar">

        <div class="user-details">
            <span class="user-name"><?php echo htmlspecialchars($nombreUsuario); ?></span>
            <span class="user-role"><?php echo htmlspecialchars($rolUsuario); ?></span>
        </div>

        <i class="fa-solid fa-pen sidebar-footer-editar" aria-hidden="true"></i>
    </a>
</aside>

<script>
    // Marca en qué pantalla está parado el usuario. Se resuelve acá y no escribiendo class="active"
    // en cada <a> para que cualquier módulo que se agregue al menú después quede cubierto sin
    // tener que acordarse de nada.
    (function () {
        var actual = window.location.pathname.replace(/\/+$/, '');
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(function (enlace) {
            // Cerrar sesión no es una pantalla: nunca se queda marcado.
            if (enlace.classList.contains('nav-link-logout')) { return; }
            var destino = new URL(enlace.href, window.location.origin).pathname.replace(/\/+$/, '');
            if (destino === actual) { enlace.classList.add('active'); }
        });
    })();

    // Menú plegable de las pantallas angostas. En escritorio el botón está oculto por CSS y este
    // código no llega a hacer nada.
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
        // Tocar fuera cierra: es más natural que buscar un botón de cerrar
        cortina.addEventListener('click', function () { abrir(false); });
        // Al elegir una opción el menú se va solo, si no tapa la pantalla que se acaba de abrir
        menu.querySelectorAll('.nav-link').forEach(function (enlace) {
            enlace.addEventListener('click', function () { abrir(false); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { abrir(false); }
        });
    })();

    // Cierre de sesión automático por inactividad.
    //
    // Esto es COMODIDAD, no seguridad: el que manda es config/auth_guard.php, que revisa el
    // tiempo en el servidor en cada request. Este temporizador solo evita que la pantalla quede
    // abierta a la vista de cualquiera hasta que alguien la toque.
    (function () {
        var URL_LOGOUT = '<?php echo BASE_URL; ?>/modules/login/controller/logout.php?motivo=inactividad';
        var LIMITE_MS  = <?php echo TIEMPO_INACTIVIDAD_SEGUNDOS * 1000; ?>;
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
