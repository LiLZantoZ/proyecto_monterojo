{{-- Aviso flotante del resultado de la última acción (guardado, error, permiso denegado...).
     Lee el flash de sesión o un ?error= / ?exito= de la dirección. $soloFlash: el login ya muestra
     sus ?error= en el formulario, así que ahí solo se muestra el flash (p. ej. CSRF vencido). --}}
@php $mensajeSistema = obtenerMensajeSistema(!empty($soloFlash)); @endphp
@if ($mensajeSistema)
    @php $esExito = $mensajeSistema['tipo'] === 'exito'; @endphp
    <div class="mensaje-sistema {{ $esExito ? 'mensaje-exito' : 'mensaje-error' }}"
         id="mensaje-sistema" role="status" aria-live="polite">
        <i class="fa-solid {{ $esExito ? 'fa-circle-check' : 'fa-circle-exclamation' }} mensaje-sistema-icono" aria-hidden="true"></i>
        <p class="mensaje-sistema-texto">{{ $mensajeSistema['texto'] }}</p>
        <button type="button" class="mensaje-sistema-cerrar" id="mensaje-sistema-cerrar" aria-label="Cerrar aviso">&times;</button>
    </div>

    <script>
        (function () {
            var aviso = document.getElementById('mensaje-sistema');
            if (!aviso) return;

            var MILISEGUNDOS = {{ $esExito ? 3500 : 6000 }};
            var temporizador;

            function cerrar() {
                clearTimeout(temporizador);
                aviso.classList.add('mensaje-sistema-saliendo');
                setTimeout(function () { aviso.remove(); }, 250);
            }

            document.getElementById('mensaje-sistema-cerrar').addEventListener('click', cerrar);
            temporizador = setTimeout(cerrar, MILISEGUNDOS);

            aviso.addEventListener('mouseenter', function () { clearTimeout(temporizador); });
            aviso.addEventListener('mouseleave', function () { temporizador = setTimeout(cerrar, 2000); });

            // Se saca el ?error= / ?exito= de la barra de direcciones: si no, al recargar la página
            // el aviso vuelve a aparecer como si la acción se hubiera repetido.
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
@endif
