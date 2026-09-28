{{-- Las constantes que esperan los scripts de las pantallas (rotulo.js, scripts_picking.js...):
     la base de las direcciones, el logo para la vista previa de los rótulos y el token CSRF, que los
     scripts mandan como "csrf_token" en sus pedidos JSON. --}}
<script>
    const BASE_URL   = @json(BASE_URL);
    const LOGO_URL   = @json(asset('assets/img/monterojo.png'));
    const CSRF_TOKEN = @json(csrf_token());
</script>
