{{-- La pantalla de ingreso. No usa layouts/app porque no lleva el menú lateral. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión · {{ NOMBRE_SISTEMA }}</title>

    @include('partes.estilos')
    {{-- Los iconos se cargan acá porque esta pantalla no incluye el menú, que es quien los trae. --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body{!! atributosDelCuerpo('pantalla-login') !!}>

{{-- Aviso flotante para lo que $mensajeError no cubre —en particular, un token CSRF vencido en
     ESTE formulario, que vuelve con el flash de sesión y no con ?error=—. Solo el flash: los ?error=
     ya se muestran dentro del formulario y saldrían duplicados. --}}
@include('partes.mensaje', ['soloFlash' => true])

<div class="login-box">

    {{-- Panel de marca. El logo de Monterojo es un círculo negro, así que la columna va en negro. --}}
    <aside class="login-panel">
        <div class="login-panel-contenido">
            <img src="{{ asset('assets/img/monterojo.png') }}" alt="Monterojo Gourmet" class="login-panel-marca">
            <p class="login-panel-titulo">{{ NOMBRE_SISTEMA }}</p>
            <p class="login-panel-texto">
                Acceso para el personal autorizado de Monterojo Gourmet.
            </p>
        </div>
    </aside>

    <div class="login-formulario">
        <h2>Iniciar sesión</h2>
        <p class="login-subtitulo">Ingresa tus credenciales para continuar</p>

        {{-- autocomplete="off": estas pantallas se usan en equipos compartidos. --}}
        <form action="{{ route('login.entrar') }}" method="POST" autocomplete="off" id="formLogin">

            @if (!empty($mensajeError))
                <div class="login-error" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span>{{ $mensajeError }}</span>
                </div>
            @endif

            @csrf

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
                    {{-- new-password: evita que el navegador rellene la contraseña guardada de otro usuario. --}}
                    <input type="password" id="password" name="contrasena_usuario" required
                           placeholder="Ingresa tu contraseña" autocomplete="new-password">
                    <button type="button" class="btn-ver-clave" id="btnVerClave"
                            aria-label="Mostrar la contraseña" title="Mostrar la contraseña">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
                {{-- Bloq Mayús encendido es la causa más común de un "mi contraseña no funciona". --}}
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

        function revisarMayusculas(evento) {
            if (typeof evento.getModifierState !== 'function') { return; }
            aviso.hidden = evento.getModifierState('CapsLock') !== true;
        }
        clave.addEventListener('keydown', revisarMayusculas);
        clave.addEventListener('keyup', revisarMayusculas);
        clave.addEventListener('blur', function () { aviso.hidden = true; });

        // Al enviar, el botón queda desactivado: un doble clic mandaría dos veces el formulario y el
        // segundo contaría como intento fallido contra el límite de 5. El disabled va en un
        // setTimeout de 0 porque deshabilitarlo en pleno submit hace que algunos navegadores
        // cancelen el envío.
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
