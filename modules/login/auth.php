<?php
// modules/login/auth.php
// LA pantalla de entrada del sistema: el formulario, directo.
//
// El sistema de bodega tiene dos pantallas antes del panel —una portada donde se elige el perfil
// y después el formulario—, pero esa portada no decide nada: los cuatro perfiles llevaban al
// mismo formulario y el rol real sale de la cédula. Acá se entra de una.

require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cabeceras anti-caché (evita que el botón atrás/adelante del navegador muestre una versión
// guardada de este formulario en vez de volver a consultar el servidor)
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// EFECTO REBOTE: si el usuario ya está logueado (y esa sesión sigue vigente) y llega acá —por
// ejemplo con el botón atrás—, se lo devuelve a su panel en vez de mostrarle el formulario otra
// vez. sesionUsuarioActiva() ya descarta las sesiones vencidas por inactividad, así que en ese
// caso simplemente se muestra el formulario normal.
if (sesionUsuarioActiva()) {
    header("Location: " . urlPanelDelRol($_SESSION['usuario_rol'] ?? null));
    exit();
}

$error = $_GET['error'] ?? '';
$mensajeError = '';
switch ($error) {
    case 'campos_vacios':
        $mensajeError = 'Por favor completa la cédula y la contraseña.';
        break;
    case 'credenciales_incorrectas':
        $mensajeError = 'Cédula o contraseña incorrecta.';
        break;
    case 'usuario_inactivo':
        $mensajeError = 'Este usuario está inactivo. Contacta a un administrador.';
        break;
    case 'rol_no_permitido':
        $mensajeError = 'Tu rol no tiene acceso a este sistema.';
        break;
    case 'demasiados_intentos':
        $minutos = (int) ($_GET['minutos'] ?? 15);
        $mensajeError = "Demasiados intentos fallidos. Intenta de nuevo en {$minutos} minuto(s).";
        break;
    case 'inactividad':
        $mensajeError = 'Tu sesión se cerró automáticamente por inactividad. Vuelve a iniciar sesión.';
        break;
    case 'no_session':
        $mensajeError = 'Inicia sesión para continuar.';
        break;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>

    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <!-- Los iconos se cargan acá porque esta pantalla no incluye el sidebar, que es quien los
         trae en el resto del sistema -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body<?php atributosDelCuerpo('pantalla-login'); ?>>

<!-- Aviso flotante para lo que $mensajeError de acá arriba no cubre — en particular, un token
     CSRF vencido en ESTE formulario: esa redirección no trae ?error= (usa el flash de sesión),
     así que sin este include el aviso nunca se mostraría.
     $mensajeSistemaSoloFlash en true porque $mensajeError ya cubre todos los ?error= que esta
     pantalla misma genera — sin la bandera, ese mismo texto aparecería duplicado. -->
<?php $mensajeSistemaSoloFlash = true; include ROOT_PATH . '/modules/inicio/layout/mensaje_sistema.php'; ?>

<div class="login-box">

    <!-- Panel de marca. El logo de Monterojo es un círculo negro, así que la columna va en negro
         y el logo se apoya sobre su propio color en vez de recortarse contra un fondo claro. -->
    <aside class="login-panel">
        <div class="login-panel-contenido">
            <img src="<?php echo BASE_URL; ?>/assets/img/monterojo.png"
                 alt="Monterojo Gourmet" class="login-panel-marca">
            <p class="login-panel-titulo"><?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></p>
            <p class="login-panel-texto">
                Acceso para el personal autorizado de Monterojo Gourmet.
            </p>
        </div>
    </aside>

    <div class="login-formulario">
        <h2>Iniciar sesión</h2>
        <p class="login-subtitulo">Ingresa tus credenciales para continuar</p>

        <!-- autocomplete="off" para que el navegador no ofrezca la cédula de otra persona: estas
             pantallas se usan en equipos compartidos. -->
        <form action="<?php echo BASE_URL; ?>/modules/login/controller/UsuarioController.php"
              method="POST" autocomplete="off" id="formLogin">

            <?php if (!empty($mensajeError)): ?>
                <div class="login-error" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($mensajeError); ?></span>
                </div>
            <?php endif; ?>

            <?php campoCSRF(); ?>

            <div class="input-group">
                <label for="cedula">Cédula</label>
                <div class="campo-con-icono">
                    <i class="fa-solid fa-id-card"></i>
                    <input type="number" id="cedula" name="cedula_usuario" required
                           placeholder="Ingresa tu cédula" autocomplete="off" autofocus>
                </div>
            </div>

            <div class="input-group">
                <label for="password">Contraseña</label>
                <div class="campo-con-icono">
                    <i class="fa-solid fa-lock"></i>
                    <!-- autocomplete="new-password": evita que Chrome/Edge rellenen la contraseña
                         guardada de otro usuario en un equipo compartido. -->
                    <input type="password" id="password" name="contrasena_usuario" required
                           placeholder="Ingresa tu contraseña" autocomplete="new-password">
                    <button type="button" class="btn-ver-clave" id="btnVerClave"
                            aria-label="Mostrar la contraseña" title="Mostrar la contraseña">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
                <!-- Bloq Mayús encendido es la causa más común de un "mi contraseña no funciona"
                     que en realidad sí funciona. Avisarlo ahorra el bloqueo por intentos
                     fallidos, que en este sistema llega a los 5. -->
                <p class="aviso-mayusculas" id="avisoMayusculas" hidden>
                    <i class="fa-solid fa-triangle-exclamation"></i> Bloq Mayús está activado
                </p>
            </div>

            <button type="submit" class="btn-login" id="btnIngresar">
                <span class="btn-login-texto">Ingresar</span>
            </button>
        </form>
    </div>

    <script>
    (function () {
        var cedula     = document.getElementById('cedula');
        var clave      = document.getElementById('password');
        var verClave   = document.getElementById('btnVerClave');
        var aviso      = document.getElementById('avisoMayusculas');
        var formulario = document.getElementById('formLogin');
        var boton      = document.getElementById('btnIngresar');

        // Vacía los campos si se vuelve con la flecha del navegador: la página puede salir de la
        // caché con lo que escribió la persona anterior todavía adentro.
        window.addEventListener('pageshow', function () {
            cedula.value = '';
            clave.value = '';
        });

        verClave.addEventListener('click', function () {
            var oculta = clave.type === 'password';
            clave.type = oculta ? 'text' : 'password';
            this.querySelector('i').className = oculta ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            this.setAttribute('aria-label', oculta ? 'Ocultar la contraseña' : 'Mostrar la contraseña');
            this.setAttribute('title', oculta ? 'Ocultar la contraseña' : 'Mostrar la contraseña');
            clave.focus();
        });

        // getModifierState devuelve null si el navegador no sabe el estado; solo se muestra el
        // aviso cuando la respuesta es un sí.
        function revisarMayusculas(evento) {
            if (typeof evento.getModifierState !== 'function') { return; }
            aviso.hidden = evento.getModifierState('CapsLock') !== true;
        }
        clave.addEventListener('keydown', revisarMayusculas);
        clave.addEventListener('keyup', revisarMayusculas);
        clave.addEventListener('blur', function () { aviso.hidden = true; });

        // Al enviar, el botón queda desactivado: sin esto, un doble clic manda dos veces el
        // formulario y el segundo cuenta como intento fallido contra el límite de 5.
        //
        // El disabled va DENTRO de un setTimeout de 0 a propósito: deshabilitar el botón de envío
        // en pleno evento submit hace que algunos navegadores cancelen el envío. Con el retraso de
        // un ciclo, el navegador ya arrancó la petición y recién ahí se apaga el botón. El texto y
        // el giro sí se cambian al instante, que es lo que se ve.
        formulario.addEventListener('submit', function () {
            boton.classList.add('cargando');
            boton.querySelector('.btn-login-texto').textContent = 'Ingresando...';
            setTimeout(function () { boton.disabled = true; }, 0);
        });
    })();
    </script>

</div>

</body>
</html>
