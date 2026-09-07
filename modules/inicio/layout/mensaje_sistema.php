<?php
// modules/inicio/layout/mensaje_sistema.php
// Aviso flotante de resultado (éxito / error) que se cierra solo. Se incluye desde el sidebar, así
// que aparece en todos los módulos sin tocar cada vista.
// Si no hay nada que informar, no imprime absolutamente nada.
require_once __DIR__ . '/../../../config/mensajes.php';

// Ponés $mensajeSistemaSoloFlash = true; ANTES de este include cuando la pantalla ya tiene su
// propia caja de error para los ?error=/?exito= que ella misma genera (ver auth.php) — así este
// aviso no duplica ese mensaje y solo aparece para lo que le llega por flash de sesión.
$mensajeSistema = obtenerMensajeSistema(!empty($mensajeSistemaSoloFlash));
if ($mensajeSistema):
    $esExito = $mensajeSistema['tipo'] === 'exito';
?>
<div class="mensaje-sistema <?php echo $esExito ? 'mensaje-exito' : 'mensaje-error'; ?>"
     id="mensaje-sistema" role="status" aria-live="polite">
    <i class="fa-solid <?php echo $esExito ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> mensaje-sistema-icono" aria-hidden="true"></i>
    <p class="mensaje-sistema-texto"><?php echo htmlspecialchars($mensajeSistema['texto']); ?></p>
    <button type="button" class="mensaje-sistema-cerrar" id="mensaje-sistema-cerrar" aria-label="Cerrar aviso">&times;</button>
</div>

<script>
    (function () {
        var aviso = document.getElementById('mensaje-sistema');
        if (!aviso) return;

        // Los errores se quedan más tiempo: suelen pedir leer y volver a intentar algo, mientras
        // que un "guardado" se entiende de un vistazo.
        var MILISEGUNDOS = <?php echo $esExito ? 3500 : 6000; ?>;
        var temporizador;

        function cerrar() {
            clearTimeout(temporizador);
            aviso.classList.add('mensaje-sistema-saliendo');
            setTimeout(function () { aviso.remove(); }, 250);
        }

        document.getElementById('mensaje-sistema-cerrar').addEventListener('click', cerrar);
        temporizador = setTimeout(cerrar, MILISEGUNDOS);

        // Si el puntero está encima no se cierra: da tiempo a leer un mensaje largo.
        aviso.addEventListener('mouseenter', function () { clearTimeout(temporizador); });
        aviso.addEventListener('mouseleave', function () { temporizador = setTimeout(cerrar, 2000); });

        // Se sacan ?error= / ?exito= de la barra de direcciones para que al recargar (o al volver
        // con el botón Atrás) no vuelva a aparecer un aviso de algo que ya pasó.
        if (window.history.replaceState) {
            var url = new URL(window.location.href);
            if (url.searchParams.has('error') || url.searchParams.has('exito')) {
                url.searchParams.delete('error');
                url.searchParams.delete('exito');
                window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
            }
        }
    })();
</script>
<?php endif; ?>
