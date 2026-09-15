<?php
// config/rutas.php
// LA TABLA DE RUTAS: la dirección que ve el usuario → el archivo que la atiende.
//
// Es la ÚNICA lista de lo que se puede pedir por URL. index.php, el enrutador, la usa para las dos
// cosas: atender cada ruta de acá y rechazar todo lo demás. Un archivo PHP de modules/ que no está
// en esta tabla no se puede ejecutar pidiéndolo por el navegador, aunque exista (ver .htaccess).
//
// POR QUÉ (2026-09-14)
// Antes la barra de direcciones mostraba la estructura interna del sistema
// (/modules/picking/views/picking.php) y cualquier archivo PHP se podía pedir suelto: modelos,
// helpers, pedazos de pantalla. Pedido directo, el menú lateral devolvía un error con la ruta del
// disco del servidor. Ahora la dirección es /picking y lo interno no responde.
//
// Esto NO reemplaza al login ni a los permisos: cada archivo de acá sigue comprobando sesión y
// permiso por su cuenta (auth_guard.php, tienePermiso). Una ruta de esta lista no abre nada que no
// estuviera abierto antes; lo que cierra es todo lo que no está en la lista.
//
// PARA AGREGAR UNA PANTALLA: sumarla acá y usar BASE_URL . '/nombre' en los enlaces.
//
// La página que abren los QR (public/rotulo.php) NO pasa por acá a propósito: su dirección está
// impresa en las etiquetas que ya circulan y tiene que seguir funcionando tal cual.

return [
    // ---------------- Pantallas ----------------
    'login'                     => 'modules/login/auth.php',
    'inicio'                    => 'modules/inicio/dashboard.php',
    'consolidados'              => 'modules/consolidados/views/consolidados.php',
    'maestro'                   => 'modules/consolidados/views/maestro.php',
    'picking'                   => 'modules/picking/views/picking.php',
    'personal'                  => 'modules/personal/views/personal.php',
    'historial'                 => 'modules/historial/views/historial.php',
    'cajas-punto-venta'         => 'modules/cajas_punto_venta/views/cajas_punto_venta.php',
    'rotulos'                   => 'modules/rotulos/views/rotulos.php',
    'ordenes-compra'            => 'modules/ordenes_compra/views/ordenes_compra.php',
    'perfil'                    => 'modules/perfil/views/perfil.php',

    // ---------------- Acciones (formularios, descargas y pedidos de las pantallas) ----------------
    'login/entrar'              => 'modules/login/controller/UsuarioController.php',
    'salir'                     => 'modules/login/controller/logout.php',
    'consolidados/acciones'     => 'modules/consolidados/controller_consolidados.php',
    'picking/acciones'          => 'modules/picking/controller_picking.php',
    'personal/acciones'         => 'modules/personal/controller_personal.php',
    'historial/acciones'        => 'modules/historial/controller_historial.php',
    'cajas-punto-venta/acciones'=> 'modules/cajas_punto_venta/controller_cajas_punto_venta.php',
    'rotulos/acciones'          => 'modules/rotulos/controller_rotulos.php',
    'rotulos/enlaces'           => 'modules/historial/controller_rotulos_enlace.php',
    'rotulos/agente'            => 'modules/historial/controller_agente_impresion.php',
    'ordenes-compra/acciones'   => 'modules/ordenes_compra/controller_ordenes_compra.php',
    'perfil/acciones'           => 'modules/perfil/controller_perfil.php',
];
