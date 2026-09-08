<?php
// config/mensajes.php
// Mensajes de resultado hacia el usuario: el aviso flotante que aparece arriba y se va solo.
//
// Cómo funciona:
//   1. Los controladores redirigen con ?error=CODIGO o ?exito=CODIGO.
//   2. Para los cortes que no son una redirección normal (un token CSRF vencido, por ejemplo)
//      existe el mensaje "flash" guardado en sesión.
//   3. modules/inicio/layout/mensaje_sistema.php lee las dos fuentes y pinta el aviso. Va
//      incluido en el sidebar, que a su vez está en todas las vistas: una sola conexión para
//      toda la aplicación.
//
// El catálogo arranca chico a propósito: solo los códigos que el sistema emite HOY. Cada módulo
// nuevo agrega los suyos acá. Un código que no esté igual muestra un aviso genérico —es
// preferible un mensaje impreciso a que la pantalla se quede muda, que era el problema original.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function catalogoMensajesSistema() {
    return [
        'error' => [
            'csrf'                => 'La página estuvo abierta demasiado tiempo y el enlace de seguridad venció. Vuelve a cargarla e inténtalo otra vez.',
            'bd'                  => 'No se pudo guardar el cambio. Inténtalo de nuevo; si sigue igual, avisa al administrador.',
            'campos_vacios'       => 'Completa todos los campos obligatorios.',
            'acceso_denegado'     => 'No tienes permiso para realizar esa acción.',
            'rol_no_permitido'    => 'Tu rol no tiene acceso a esa pantalla.',
            'usuario_inactivo'    => 'Esa cuenta está inactiva. Contacta al administrador.',
            'demasiados_intentos' => 'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentar.',
            'inactividad'         => 'Se cerró la sesión por inactividad.',
            'no_session'          => 'Inicia sesión para continuar.',
            'archivo'             => 'Hubo un problema con el archivo enviado. Vuelve a subirlo.',
            'formato'             => 'El archivo tiene que ser un Excel (.xlsx o .xls).',
            'sin_datos'           => 'Todavía no hay un Consolidado cargado.',
            'invalid_id'          => 'No se encontró ese registro. Puede que el Consolidado se haya vuelto a cargar.',
            'falta_punto_venta'   => 'Escribí al menos el punto de venta antes de generar el rótulo.',
        ],
        'exito' => [
            'creado'      => 'Registro creado correctamente.',
            'actualizado' => 'Cambios guardados.',
            'eliminado'   => 'Registro eliminado.',
        ],
    ];
}

function textoMensajeSistema($tipo, $codigo) {
    $catalogo = catalogoMensajesSistema();
    if (isset($catalogo[$tipo][$codigo])) {
        return $catalogo[$tipo][$codigo];
    }
    return $tipo === 'exito'
        ? 'Acción completada.'
        : 'No se pudo completar la acción. Inténtalo de nuevo.';
}

// Guarda un mensaje para mostrarlo en la PRÓXIMA página que se cargue. Se usa donde no hay un
// ?error= en la URL, por ejemplo cuando el token CSRF vence y hay que devolver al usuario a donde
// estaba. Se consume una sola vez.
function guardarMensajeFlash($tipo, $codigo) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['mensaje_flash'] = ['tipo' => $tipo, 'codigo' => $codigo];
}

// Igual que la anterior, pero con el texto ya escrito en vez de un código del catálogo.
//
// Existe para los resultados que traen un NÚMERO que cambia en cada ejecución: "se importaron 348
// líneas", "se cargaron 40 productos al maestro". Esos no pueden salir del catálogo, que tiene
// textos fijos, y meterlos ahí obligaría a inventar un código por cada cantidad posible.
//
// Para todo lo demás va guardarMensajeFlash() con su código: un texto suelto repetido en varios
// controladores es exactamente lo que el catálogo viene a evitar.
function guardarMensajeFlashTexto($tipo, $texto) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['mensaje_flash'] = ['tipo' => $tipo, 'texto' => $texto];
}

// Devuelve ['tipo' => ..., 'texto' => ...] o null. Prioriza el flash de sesión sobre el parámetro
// de la URL, y limpia el flash para que no reaparezca al recargar.
//
// $soloFlash = true ignora ?error= / ?exito= y solo mira el flash de sesión. Existe para
// modules/login/auth.php: esa pantalla ya tiene su PROPIA caja de error dentro del formulario
// para todos los códigos que ella misma genera (campos_vacios, usuario_inactivo...) — si este
// aviso también los leyera de la URL, el mismo texto aparecería duplicado, una vez en la caja del
// formulario y otra en el flotante. El flash sí se muestra ahí, porque es la única vía por la que
// le llega un aviso que esa pantalla no genera ella misma (el CSRF de su propio formulario).
function obtenerMensajeSistema($soloFlash = false) {
    if (!empty($_SESSION['mensaje_flash'])) {
        $flash = $_SESSION['mensaje_flash'];
        unset($_SESSION['mensaje_flash']);
        return [
            'tipo'  => $flash['tipo'],
            // El flash puede traer el texto ya escrito (guardarMensajeFlashTexto) o un código del
            // catálogo (guardarMensajeFlash). El texto manda si viene.
            'texto' => $flash['texto'] ?? textoMensajeSistema($flash['tipo'], $flash['codigo'] ?? ''),
        ];
    }

    if ($soloFlash) {
        return null;
    }

    foreach (['exito', 'error'] as $tipo) {
        if (!empty($_GET[$tipo])) {
            $codigo = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $_GET[$tipo]));
            return ['tipo' => $tipo, 'texto' => textoMensajeSistema($tipo, $codigo)];
        }
    }

    return null;
}

// URL a la que volver cuando hay que rechazar una petición sin saber de qué pantalla vino.
// Solo se acepta un Referer del propio sitio: seguir uno externo convertiría este rechazo en un
// redirector abierto hacia cualquier dominio.
function urlSeguraDeRetorno($porDefecto) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer === '') {
        return $porDefecto;
    }

    $partes = parse_url($referer);
    $hostActual = $_SERVER['HTTP_HOST'] ?? '';

    if (!empty($partes['host']) && $partes['host'] !== $hostActual) {
        return $porDefecto;
    }
    if (empty($partes['path']) || strpos($partes['path'], BASE_URL . '/') !== 0) {
        return $porDefecto;
    }

    return $partes['path'];
}
