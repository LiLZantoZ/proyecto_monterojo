<?php
// app/Soporte/helpers.php
// Funciones globales del sistema, cargadas por Composer ("files" en composer.json).
//
// POR QUÉ EXISTEN (2026-09-28, migración a Laravel)
// El sistema anterior (PHP sin framework) tenía estas funciones en config/*.php y las usaban tanto
// las pantallas como los servicios (app/Servicios, que es la lógica de negocio traída tal cual).
// Acá se reimplementan SOBRE Laravel —sesión, autenticación, CSRF y URLs de Laravel— con el mismo
// nombre y el mismo comportamiento, para que esa lógica ya probada siga funcionando sin reescribirla.

use App\Soporte\Permisos;

// ------------------------------------------------------------------------------------------------
// Permisos
// ------------------------------------------------------------------------------------------------

/** ¿El usuario de la sesión tiene este permiso? (tablas permisos / rolespermisos) */
function tienePermiso(string $nombrePermiso): bool
{
    return Permisos::tiene($nombrePermiso);
}

/** Los permisos del rol de la sesión (se leen de la base una vez por request). */
function permisosDelUsuario(): array
{
    return Permisos::delUsuario();
}

/** A dónde va un usuario recién autenticado. Hoy hay un solo panel para todos los roles. */
function urlPanelDelRol($idRol = null): string
{
    return route('inicio');
}

// ------------------------------------------------------------------------------------------------
// CSRF: lo hace el middleware de Laravel. Estas quedan por compatibilidad con las vistas y los
// scripts: el campo se sigue llamando csrf_token en los JSON que arman los .js (ver
// App\Http\Middleware\VerificarCsrf, que acepta los dos nombres).
// ------------------------------------------------------------------------------------------------

function generarTokenCSRF(): string
{
    return csrf_token();
}

function campoCSRF(): void
{
    echo csrf_field();
}

/** No hace nada: la verificación ya la hizo el middleware antes de llegar al controlador. */
function validarCSRF(): void
{
}

// ------------------------------------------------------------------------------------------------
// Mensajes flotantes (el aviso de resultado de la última acción)
// ------------------------------------------------------------------------------------------------

function catalogoMensajesSistema(): array
{
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

function textoMensajeSistema($tipo, $codigo): string
{
    $catalogo = catalogoMensajesSistema();
    if (isset($catalogo[$tipo][$codigo])) {
        return $catalogo[$tipo][$codigo];
    }
    return $tipo === 'exito'
        ? 'Acción completada.'
        : 'No se pudo completar la acción. Inténtalo de nuevo.';
}

/** Deja el aviso para la próxima pantalla, por código del catálogo. */
function guardarMensajeFlash($tipo, $codigo): void
{
    session()->flash('mensaje_flash', ['tipo' => $tipo, 'codigo' => $codigo]);
}

/** Deja el aviso para la próxima pantalla, con un texto armado. */
function guardarMensajeFlashTexto($tipo, $texto): void
{
    session()->flash('mensaje_flash', ['tipo' => $tipo, 'texto' => $texto]);
}

/**
 * El aviso a mostrar: el flash de sesión, o un ?error= / ?exito= de la dirección (lo usan las
 * redirecciones del login y de los permisos). null si no hay nada.
 */
function obtenerMensajeSistema($soloFlash = false): ?array
{
    $flash = session('mensaje_flash');
    if (!empty($flash)) {
        return [
            'tipo'  => $flash['tipo'],
            'texto' => $flash['texto'] ?? textoMensajeSistema($flash['tipo'], $flash['codigo'] ?? ''),
        ];
    }
    if ($soloFlash) {
        return null;
    }
    foreach (['exito', 'error'] as $tipo) {
        $valor = request()->query($tipo);
        if (!empty($valor) && is_string($valor)) {
            $codigo = preg_replace('/[^a-z0-9_]/', '', strtolower($valor));
            return ['tipo' => $tipo, 'texto' => textoMensajeSistema($tipo, $codigo)];
        }
    }
    return null;
}

// ------------------------------------------------------------------------------------------------
// Imágenes y archivos estáticos
// ------------------------------------------------------------------------------------------------

/** La fecha de modificación del archivo, para el ?v= que evita que el navegador sirva uno viejo. */
function assetVersion($rutaAbsolutaArchivo)
{
    return file_exists($rutaAbsolutaArchivo) ? filemtime($rutaAbsolutaArchivo) : time();
}

/** URL de un archivo de public/ con su ?v= de versión. */
function assetV(string $rutaRelativa): string
{
    $rutaRelativa = ltrim($rutaRelativa, '/');
    return asset($rutaRelativa) . '?v=' . assetVersion(public_path($rutaRelativa));
}

/** La foto de perfil guardada en la base, como URL. Sin foto, el avatar por defecto. */
function rutaImagenPerfil($imagenBD): string
{
    if (empty($imagenBD)) {
        return asset('assets/img/avatar_por_defecto.svg');
    }
    if (str_starts_with($imagenBD, 'http') || str_starts_with($imagenBD, '/')) {
        return $imagenBD;
    }
    return asset(ltrim($imagenBD, '/'));
}

/** URL de una imagen de marca (assets/img/...) si existe en disco, o null. */
function imagenDeMarca($rutaRelativa): ?string
{
    $relativa = 'assets/img/' . ltrim($rutaRelativa, '/');
    if (!is_file(public_path($relativa))) {
        return null;
    }
    return assetV($relativa);
}

/** Los atributos del <body>: la foto de fondo, si está cargada. */
function atributosDelCuerpo($clasesExtra = ''): string
{
    $fondo  = imagenDeMarca('fondo.jpg');
    $clases = trim($clasesExtra . ($fondo ? ' con-fondo' : ''));
    $atributos = $clases !== '' ? ' class="' . e($clases) . '"' : '';
    if ($fondo) {
        $atributos .= ' style="--foto-fondo: url(\'' . e($fondo) . '\');"';
    }
    return $atributos;
}

// ------------------------------------------------------------------------------------------------
// Formatos para mostrar (miles con punto y decimales con coma, como en todo el sistema)
// ------------------------------------------------------------------------------------------------

/** 1234567 → "1.234.567" */
function fmtMil($n): string
{
    return number_format((float) $n, 0, ',', '.');
}

/** 1234567.8 → "$1.234.567,80" */
function fmtPlata($v): string
{
    return '$' . number_format((float) $v, 2, ',', '.');
}

/** Una cantidad en piezas: sin decimales si es entera, con los que tenga si no (12 / 12,5). */
function fmtCantidad($v): string
{
    $v = (float) $v;
    return floor($v) == $v
        ? number_format($v, 0, ',', '.')
        : rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
}

/**
 * El valor para una celda, o una raya gris si no hay dato. Devuelve algo que Blade escapa bien con
 * {{ }}: el texto del valor se escapa, la raya (HTML propio) no.
 */
function oGuion($valor, string $clase = 'dato-faltante')
{
    if ($valor === null || $valor === '') {
        return new \Illuminate\Support\HtmlString('<span class="' . e($clase) . '">—</span>');
    }
    return $valor;
}

/**
 * Los números de página a mostrar, estilo buscador: siempre la primera y la última, más una
 * ventana de consecutivos que se desliza con la actual; 0 marca un hueco ("…").
 * Ej. en la página 1 de 27: 1 2 3 4 5 6 7 8 9 … 27.
 */
function numerosDePagina(int $pagina, int $totalPaginas, int $ventana = 9): array
{
    $inicio = max(1, $pagina - intdiv($ventana, 2));
    $fin    = min($totalPaginas, $inicio + $ventana - 1);
    $inicio = max(1, $fin - $ventana + 1);

    $numeros = array_unique(array_merge([1], range($inicio, $fin), [$totalPaginas]));
    sort($numeros);

    $conSaltos = [];
    $prev = 0;
    foreach ($numeros as $n) {
        if ($prev && $n - $prev > 1) {
            $conSaltos[] = 0;
        }
        $conSaltos[] = $n;
        $prev = $n;
    }
    return $conSaltos;
}

// ------------------------------------------------------------------------------------------------
// Descargas
// ------------------------------------------------------------------------------------------------

/**
 * La respuesta de descarga de un PDF ya renderizado con Dompdf.
 *
 * En el sistema anterior los generadores de PDF hacían $dompdf->stream() y exit(): cortaban el
 * pedido ahí mismo. Dentro de Laravel eso saltea todo lo que viene después (guardar la sesión, los
 * middleware), así que ahora devuelven esta respuesta y el controlador la retorna.
 */
function descargaPdf(\Dompdf\Dompdf $dompdf, string $nombreArchivo)
{
    // Igual que Dompdf::stream(): el nombre siempre termina en .pdf.
    $nombreArchivo = str_replace(["\n", "'", '"'], '', basename($nombreArchivo, '.pdf')) . '.pdf';

    return response($dompdf->output(), 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="' . $nombreArchivo . '"',
        'Cache-Control'       => 'private, max-age=0, must-revalidate',
    ]);
}

/**
 * El código de barras (Code 128) de la vista previa de un rótulo, como imagen SVG.
 *
 * Lo piden Picking, el Historial, Cajas y "Generar rótulos" (acción codigo_barras, por GET). Es de
 * LECTURA y sin efectos, así que no lleva CSRF; lo que sí lleva es validación estricta: la cadena
 * termina adentro de una imagen servida desde el propio sistema.
 */
function respuestaCodigoBarras(?string $texto)
{
    $texto = trim((string) $texto);

    if ($texto === '' || strlen($texto) > 60 || !preg_match('/^[A-Za-z0-9\-]+$/', $texto)) {
        return response('', 400);
    }

    $generador = new \Picqer\Barcode\BarcodeGeneratorSVG();
    // Sin texto legible debajo, igual que en la etiqueta: el EAN no se muestra, se escanea.
    $svg = $generador->getBarcode($texto, $generador::TYPE_CODE_128, 2, 60);

    return response($svg, 200, [
        'Content-Type'  => 'image/svg+xml; charset=utf-8',
        'Cache-Control' => 'private, max-age=3600',
    ]);
}

// ------------------------------------------------------------------------------------------------
// Dirección pública de los rótulos (la que va en el QR). Misma lógica que el sistema anterior.
// ------------------------------------------------------------------------------------------------

function esDireccionDeLaMismaMaquina($ip): bool
{
    return $ip === '' || $ip === '::1' || strncmp($ip, '127.', 4) === 0;
}

function ipDeEstaPcEnLaRed(): ?string
{
    $socket = @stream_socket_client('udp://8.8.8.8:53', $errno, $error, 1);
    if (!$socket) {
        return null;
    }
    $nombre = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $ip = strstr($nombre, ':', true) ?: $nombre;
    return ($ip !== '' && !esDireccionDeLaMismaMaquina($ip)) ? $ip : null;
}

/**
 * La dirección base del QR, en este orden: URL_PUBLICA_ROTULOS del .env; la dirección por la que
 * entró esta petición; la IP de esta PC en la red; vacía (el rótulo sale sin QR).
 */
function direccionBaseDeRotulos(): string
{
    $delEntorno = config('monterojo.url_publica_rotulos');
    if (is_string($delEntorno) && trim($delEntorno) !== '') {
        return rtrim(trim($delEntorno), '/');
    }
    $servidor = $_SERVER['SERVER_ADDR'] ?? '';
    if (!esDireccionDeLaMismaMaquina($servidor)) {
        return 'http://' . $servidor . BASE_URL;
    }
    $propia = ipDeEstaPcEnLaRed();
    return $propia === null ? '' : 'http://' . $propia . BASE_URL;
}
