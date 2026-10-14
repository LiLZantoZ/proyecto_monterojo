<?php
// config/errores.php
// MANEJO DE ERRORES (2026-10-14, pedido del usuario): cualquier error de cualquier operación se
// muestra con QUÉ pasó, en palabras de la bodega, y una RECOMENDACIÓN de qué hacer. Nunca la
// pantalla en blanco ni el texto técnico de PHP.
//
// Tiene tres partes:
//   1. explicarError(): traduce el error (de la base, de un Excel, de memoria, de un archivo…) a
//      ['mensaje', 'recomendacion', 'http']. Es el catálogo: si aparece un error nuevo que se
//      explica mal, se agrega acá y vale para todo el sistema.
//   2. conDetalleDeError(): para los catch que ya existían ("No se pudo guardar. Intentalo de
//      nuevo."): les agrega el motivo real y la recomendación, sin cambiar el resto.
//   3. Lo que no atrapa nadie (una excepción suelta, un error fatal, se acabó la memoria o el
//      tiempo, el archivo es más grande de lo que PHP acepta) lo atrapa el sistema y responde
//      según quién preguntó: JSON a un script, aviso + vuelta a la pantalla a un formulario, y una
//      página de error a una pantalla.
//
// Cada error inesperado queda en logs/errores_sistema.log con un CÓDIGO DE REFERENCIA (E241014-…)
// que también se le muestra al usuario: es lo que hay que pasarle al administrador para encontrarlo.
//
// El mismo archivo vive en monterojo_laravel/app/Soporte/errores.php: allá lo carga helpers.php y
// la parte 3 la hace bootstrap/app.php con el manejador de Laravel.

/** El código de referencia de un error: E + fecha y hora + 4 letras al azar ("E241014-153012-A9F3"). */
function referenciaDeError() {
    try {
        $azar = strtoupper(bin2hex(random_bytes(2)));
    } catch (Throwable $e) {
        $azar = strtoupper(dechex(mt_rand(4096, 65535)));
    }
    return 'E' . date('ymd-His') . '-' . $azar;
}

/** Deja el error en el registro con su referencia. Devuelve la referencia. */
function registrarErrorSistema($e, $referencia = null) {
    $referencia = $referencia ?: referenciaDeError();
    $detalle = $e instanceof Throwable
        ? get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine()
        : (string) $e;
    $usuario = $_SESSION['usuario_id'] ?? null;
    $linea = '[' . date('Y-m-d H:i:s') . "] {$referencia} usuario=" . ($usuario ?? '-')
           . ' ' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ' · ' . $detalle;

    if (function_exists('app') && class_exists('Illuminate\Support\Facades\Log') && app()->bound('log')) {
        \Illuminate\Support\Facades\Log::error($linea);
    } else {
        error_log($linea);
        $carpeta = dirname(__DIR__) . '/logs';
        if (is_dir($carpeta) && is_writable($carpeta)) {
            @file_put_contents($carpeta . '/errores_sistema.log', $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
    return $referencia;
}

/** El tamaño máximo que acepta PHP para subir, en texto ("40 MB"). */
function limiteDeSubida() {
    $aBytes = function ($v) {
        $v = trim((string) $v);
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            case 'm': $n *= 1024;
            case 'k': $n *= 1024;
        }
        return $n;
    };
    $bytes = min(array_filter([$aBytes(ini_get('upload_max_filesize')), $aBytes(ini_get('post_max_size'))]) ?: [0]);
    return $bytes > 0 ? rtrim(rtrim(number_format($bytes / 1048576, 1, ',', '.'), '0'), ',') . ' MB' : 'el límite del servidor';
}

/** Por qué no llegó un archivo subido (el código de $_FILES[...]['error']). */
function explicarErrorDeSubida($codigo) {
    $limite = limiteDeSubida();
    switch ((int) $codigo) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['mensaje' => "El archivo es más grande de lo que el servidor acepta ({$limite}).",
                    'recomendacion' => 'Quitale hojas o filas que no hacen falta y guardalo como .xlsx (pesa menos que .xls), o dividilo en dos archivos.', 'http' => 413];
        case UPLOAD_ERR_PARTIAL:
            return ['mensaje' => 'El archivo llegó cortado: la subida se interrumpió a la mitad.',
                    'recomendacion' => 'Revisá la conexión y volvé a subirlo.', 'http' => 400];
        case UPLOAD_ERR_NO_FILE:
            return ['mensaje' => 'No se eligió ningún archivo.',
                    'recomendacion' => 'Tocá "Seleccionar archivo", elegí el Excel y después el botón de subir.', 'http' => 400];
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return ['mensaje' => 'El servidor no pudo guardar el archivo que subiste.',
                    'recomendacion' => 'Puede que el disco del servidor esté lleno. Avisale al administrador.', 'http' => 500];
    }
    return ['mensaje' => 'No se pudo recibir el archivo.', 'recomendacion' => 'Volvé a intentarlo.', 'http' => 400];
}

/**
 * QUÉ PASÓ Y QUÉ HACER: ['mensaje', 'recomendacion', 'http', 'inesperado'].
 * 'inesperado' = es una falla del sistema (no algo que el usuario pueda corregir solo): se le
 * muestra el código de referencia para que avise.
 */
function explicarError($e) {
    $texto = $e instanceof Throwable ? $e->getMessage() : (string) $e;
    $clase = $e instanceof Throwable ? get_class($e) : '';
    $r = fn($m, $rec, $http = 500, $inesperado = false) => ['mensaje' => $m, 'recomendacion' => $rec, 'http' => $http, 'inesperado' => $inesperado];

    // ---------------- La base de datos ----------------
    if ($e instanceof PDOException) {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        if (!$codigo && preg_match('/\b(1\d{3}|2\d{3})\b/', $texto, $m)) {
            $codigo = (int) $m[1];
        }
        switch ($codigo) {
            case 2002: case 2003:
                return $r('No hay conexión con la base de datos: MySQL no está respondiendo.',
                          'Abrí el panel de XAMPP y revisá que MySQL esté encendido (en verde). Si ya lo está, detenelo, volvé a darle Start y recargá la página.', 503);
            case 2006: case 2013:
                return $r('La base de datos cortó la conexión en medio de la operación (suele pasar con archivos o lotes muy grandes).',
                          'Volvé a intentarlo. Si se repite con el mismo archivo, dividilo en partes más chicas.', 503);
            case 1045:
                return $r('El sistema no pudo entrar a la base de datos: el usuario o la contraseña de la base no son correctos.',
                          'Avisale al administrador: hay que revisar DB_USER y DB_PASS en la configuración.', 503, true);
            case 1049:
                return $r('La base de datos del sistema no existe en este servidor.',
                          'Avisale al administrador: hay que crearla o revisar DB_NAME en la configuración.', 503, true);
            case 1040:
                return $r('La base de datos está atendiendo demasiadas conexiones a la vez.',
                          'Esperá un minuto y volvé a intentarlo.', 503);
            case 1062:
                return $r('Ese dato ya existe: hay otro registro igual (repetido).',
                          'Buscalo en la lista: puede que ya se haya cargado antes. Si es otro, cambiá el dato que se repite.', 409);
            case 1451:
                return $r('No se puede borrar porque hay otros registros que dependen de este.',
                          'Primero quitá o cambiá lo que está relacionado (por ejemplo, la estiba de una posición o los pedidos de un producto) y después borralo.', 409);
            case 1452:
                return $r('Se hace referencia a algo que ya no existe (por ejemplo, un producto, un usuario o una posición que se borró).',
                          'Recargá la página (F5) para ver la información actualizada y volvé a elegir.', 409);
            case 1048: case 1364:
                return $r('Falta un dato obligatorio.', 'Completá todos los campos marcados como obligatorios y volvé a guardar.', 422);
            case 1406:
                return $r('Uno de los datos es más largo de lo permitido.', 'Acortá el texto (nombres, observaciones o códigos muy largos) y volvé a guardar.', 422);
            case 1264:
                return $r('Un número está fuera del rango permitido (demasiado grande o negativo).', 'Revisá las cantidades, cajas o valores que escribiste.', 422);
            case 1366: case 1292:
                return $r('Un dato tiene un formato que no corresponde (por ejemplo, letras donde va un número o una fecha mal escrita).',
                          'Revisá que las cantidades sean solo números y las fechas tengan el formato correcto (día/mes/año en el calendario).', 422);
            case 1205: case 1213:
                return $r('La base de datos estaba ocupada con otra operación sobre los mismos datos.', 'Esperá unos segundos y volvé a intentarlo: no se guardó nada a medias.', 409);
            case 1146: case 1054:
                return $r('A la base de datos le falta una tabla o columna que el sistema necesita (la estructura está desactualizada).',
                          'Avisale al administrador: hay que actualizar la estructura de la base (scripts/crear_esquema_completo.php).', 500, true);
            case 1114: case 1021: case 28:
                return $r('El servidor se quedó sin espacio para guardar datos.', 'Avisale al administrador: hay que liberar espacio en el disco del servidor.', 507, true);
            case 1153:
                return $r('Lo que se intentó guardar de una sola vez es demasiado grande para la base.', 'Probá con un archivo más chico o dividilo en partes.', 413);
        }
        return $r('Ocurrió un error en la base de datos.', 'Volvé a intentarlo. Si se repite, avisale al administrador con el código de referencia.', 500, true);
    }

    // ---------------- Memoria, tiempo y disco ----------------
    if (stripos($texto, 'Allowed memory size') !== false || stripos($texto, 'Out of memory') !== false) {
        return $r('La operación necesitó más memoria de la que tiene el servidor (el archivo o la consulta son muy grandes).',
                  'Probá con un archivo más chico, filtrá por menos fechas o dividí el trabajo en partes.', 507);
    }
    if (stripos($texto, 'Maximum execution time') !== false) {
        return $r('La operación tardó demasiado y el servidor la cortó.',
                  'Probá con menos datos de una vez (un archivo más chico o un rango de fechas más corto). Lo que no terminó no quedó guardado a medias si se hacía en un solo paso.', 504);
    }
    if (stripos($texto, 'No space left') !== false || stripos($texto, 'disk full') !== false) {
        return $r('El disco del servidor está lleno.', 'Avisale al administrador: hay que liberar espacio.', 507, true);
    }
    if (stripos($texto, 'Permission denied') !== false || stripos($texto, 'failed to open stream') !== false
        || stripos($texto, 'Failed to open stream') !== false) {
        return $r('El servidor no pudo leer o escribir un archivo que necesita.',
                  'Si el archivo está abierto en Excel u otro programa, cerralo y volvé a intentarlo. Si sigue, avisale al administrador.', 500, true);
    }

    // ---------------- Excel, PDF y archivos ----------------
    if (stripos($clase, 'PhpSpreadsheet') !== false || stripos($texto, 'ZipArchive') !== false
        || stripos($texto, 'is not a zip archive') !== false || stripos($texto, 'Could not open') !== false) {
        return $r('No se pudo leer o escribir el archivo de Excel.',
                  'Abrilo en Excel y guardalo de nuevo como "Libro de Excel (.xlsx)". Revisá que no tenga contraseña y que no esté abierto en otro programa.', 422);
    }
    if (stripos($clase, 'Dompdf') !== false) {
        return $r('No se pudo armar el PDF.', 'Probá con menos registros a la vez. Si se repite, avisale al administrador con el código de referencia.', 500, true);
    }
    if ($e instanceof JsonException || stripos($texto, 'Syntax error') !== false && stripos($clase, 'Json') !== false) {
        return $r('Los datos que llegaron al servidor vinieron incompletos o dañados.', 'Recargá la página (F5) y volvé a intentarlo.', 400);
    }

    // ---------------- Laravel ----------------
    if (stripos($clase, 'PostTooLargeException') !== false) {
        return explicarErrorDeSubida(UPLOAD_ERR_INI_SIZE);
    }
    if (stripos($clase, 'TokenMismatch') !== false) {
        return $r('La página estuvo abierta mucho tiempo y su permiso de envío venció.', 'Recargá la página (F5) y volvé a hacer la operación.', 419);
    }
    if (is_object($e) && method_exists($e, 'getStatusCode')) {
        $http = (int) $e->getStatusCode();
        if ($http === 404) { return $r('La página o el dato que buscás no existe (puede que se haya borrado).', 'Volvé a la pantalla anterior y recargala.', 404); }
        if ($http === 403) { return $r('No tenés permiso para hacer esto.', 'Si lo necesitás, pedile al administrador que te dé el permiso en Administrar usuarios.', 403); }
        if ($http === 405) { return $r('Esa dirección no se puede usar así (se abrió directo en vez de desde su botón).', 'Volvé a la pantalla y usá el botón correspondiente.', 405); }
        if ($http === 429) { return $r('Se hicieron demasiados intentos seguidos.', 'Esperá un minuto y volvé a intentarlo.', 429); }
        if ($http === 503) { return $r('El sistema está en mantenimiento.', 'Esperá unos minutos y volvé a intentarlo.', 503); }
    }

    // ---------------- Todo lo demás: una falla del programa ----------------
    return $r('Ocurrió un error inesperado en el sistema.',
              'Recargá la página (F5) y volvé a intentarlo. Si se repite, avisale al administrador con el código de referencia.', 500, true);
}

/** "Mensaje. Recomendación: … (Código: E…)" — en una línea, para los avisos que ya muestran un texto. */
function textoErrorConRecomendacion(array $info, $referencia = null) {
    $texto = rtrim($info['mensaje']);
    if (!empty($info['recomendacion'])) {
        $texto .= ' Recomendación: ' . $info['recomendacion'];
    }
    if ($referencia && !empty($info['inesperado'])) {
        $texto .= ' (Código: ' . $referencia . ')';
    }
    return $texto;
}

/**
 * Para los catch que ya devolvían un texto ("No se pudo guardar. Intentalo de nuevo."): lo deja
 * igual y le suma el motivo real y la recomendación. Registra el error.
 */
function conDetalleDeError($mensaje, $e) {
    $info = explicarError($e);
    $referencia = registrarErrorSistema($e);
    $mensaje = rtrim($mensaje);
    // Sin el "Intentalo de nuevo." del texto viejo: la recomendación ya dice qué hacer.
    $mensaje = preg_replace('/\s*(Intentalo|Inténtalo|Intentá|Probá) de nuevo\.?$/u', '', $mensaje);
    if (!preg_match('/[.!?]$/u', $mensaje)) {
        $mensaje .= '.';
    }
    return $mensaje . ' Motivo: ' . textoErrorConRecomendacion($info, $referencia);
}

/** La página de error (cuando falla una pantalla entera). Autocontenida: no depende del layout. */
function htmlPaginaErrorSistema(array $info, $referencia = null, $detalle = null, $urlInicio = '/') {
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $nombre = defined('NOMBRE_SISTEMA') ? NOMBRE_SISTEMA : 'Sistema Monterojo';
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Algo salió mal · ' . $esc($nombre) . '</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Arial,Helvetica,sans-serif;'
        . 'background:#1a0f0f;color:#2b1d1d;padding:20px;box-sizing:border-box}'
        . '.caja{max-width:560px;width:100%;background:#fffaf5;border-radius:18px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.45)}'
        . '.cab{background:#1a0f0f;color:#fff;padding:18px 24px;border-bottom:4px solid #8b1e1e;display:flex;gap:12px;align-items:center}'
        . '.cab b{font-size:18px}.ico{width:38px;height:38px;border-radius:50%;background:#8b1e1e;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:22px}'
        . '.cuerpo{padding:22px 24px}.msg{font-size:17px;font-weight:bold;margin:0 0 14px;line-height:1.35}'
        . '.rec{background:#f3e9e0;border-left:4px solid #8b1e1e;border-radius:8px;padding:12px 14px;line-height:1.45;font-size:15px}'
        . '.rec b{display:block;margin-bottom:4px;color:#8b1e1e}.ref{margin-top:14px;font-size:13px;color:#6b5a5a}'
        . '.ref code{background:#efe3d8;padding:2px 6px;border-radius:4px;font-weight:bold;color:#2b1d1d}'
        . 'details{margin-top:14px;font-size:12px;color:#6b5a5a}pre{white-space:pre-wrap;word-break:break-word;background:#efe3d8;padding:10px;border-radius:6px}'
        . '.pie{display:flex;gap:10px;justify-content:flex-end;padding:14px 24px;background:#f3e9e0}'
        . '.pie a{padding:10px 16px;border-radius:9px;text-decoration:none;font-weight:bold;font-size:14px}'
        . '.b1{background:#fff;color:#2b1d1d;border:1px solid #d9c9bc}.b2{background:#1a0f0f;color:#fff}'
        . '</style></head><body><div class="caja"><div class="cab"><span class="ico">!</span><b>Algo salió mal</b></div><div class="cuerpo">'
        . '<p class="msg">' . $esc($info['mensaje']) . '</p>'
        . '<div class="rec"><b>Recomendación</b>' . $esc($info['recomendacion']) . '</div>'
        . ($referencia && !empty($info['inesperado']) ? '<p class="ref">Si le avisás al administrador, pasale este código: <code>' . $esc($referencia) . '</code></p>' : '')
        . ($detalle ? '<details><summary>Detalle técnico (solo se ve en desarrollo)</summary><pre>' . $esc($detalle) . '</pre></details>' : '')
        . '</div><div class="pie"><a class="b1" href="javascript:history.back()">Volver</a><a class="b2" href="' . $esc($urlInicio) . '">Ir al inicio</a></div></div></body></html>';
}

// =================================================================================================
// LO QUE NO ATRAPA NADIE (solo el sistema anterior: en Laravel lo hace bootstrap/app.php)
// =================================================================================================
// En Laravel existe app() (sus funciones se cargan antes que las nuestras): ahí no se toca nada.
if (!defined('LARAVEL_START') && !function_exists('app')) {

    /** ¿La petición la hizo un script (fetch) y espera JSON? */
    function peticionEsperaJson() {
        $acepta = $_SERVER['HTTP_ACCEPT'] ?? '';
        $tipo   = $_SERVER['CONTENT_TYPE'] ?? '';
        $dest   = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
        return stripos($acepta, 'application/json') !== false || stripos($tipo, 'application/json') !== false
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '' || ($dest !== '' && $dest !== 'document' && $dest !== 'iframe');
    }

    /** Responde el error según quién preguntó. Termina la ejecución. */
    function responderErrorSistema(array $info, $referencia = null, $detalle = null) {
        // Lo que se haya alcanzado a escribir de la pantalla se descarta: si no, el error quedaría
        // pegado debajo de media página.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $texto = textoErrorConRecomendacion($info, $referencia);
        $inicio = (defined('BASE_URL') ? BASE_URL : '') . '/inicio';

        if (headers_sent()) {
            // Ya salió parte de la pantalla (una descarga, un PDF a medias): se agrega el aviso al final.
            echo '<div style="position:fixed;inset:auto 16px 16px 16px;z-index:99999;background:#fffaf5;border:2px solid #8b1e1e;'
               . 'border-radius:12px;padding:14px 16px;font:15px Arial,sans-serif;color:#2b1d1d;box-shadow:0 10px 30px rgba(0,0,0,.35)">'
               . '<b>Algo salió mal.</b> ' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</div>';
            exit();
        }

        $http = (int) ($info['http'] ?? 500);
        http_response_code($http >= 400 && $http < 600 ? $http : 500);

        if (peticionEsperaJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['exito' => false, 'error' => $texto, 'mensaje' => $texto,
                              'recomendacion' => $info['recomendacion'], 'referencia' => $referencia], JSON_UNESCAPED_UNICODE);
            exit();
        }

        // Un formulario: de vuelta a la pantalla de donde vino, con el aviso arriba.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            require_once __DIR__ . '/mensajes.php';
            guardarMensajeFlashTexto('error', $texto);
            header('Location: ' . urlSeguraDeRetorno($inicio), true, 303);
            exit();
        }

        header('Content-Type: text/html; charset=utf-8');
        echo htmlPaginaErrorSistema($info, $referencia, $detalle, $inicio);
        exit();
    }

    $detalleParaMostrar = fn($e) => (defined('APP_ENV') && APP_ENV !== 'production')
        ? ($e instanceof Throwable ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() : (string) $e)
        : null;

    // Una excepción que nadie atrapó.
    set_exception_handler(function (Throwable $e) use ($detalleParaMostrar) {
        $referencia = registrarErrorSistema($e);
        responderErrorSistema(explicarError($e), $referencia, $detalleParaMostrar($e));
    });

    // Un error fatal (memoria, tiempo, una función que no existe…): PHP corta, pero esto corre igual.
    register_shutdown_function(function () use ($detalleParaMostrar) {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $texto = $error['message'] . ' en ' . $error['file'] . ':' . $error['line'];
        $referencia = registrarErrorSistema($texto);
        responderErrorSistema(explicarError($error['message']), $referencia, $detalleParaMostrar($texto));
    });

    // Los errores de PHP no se muestran en la pantalla (ni en desarrollo): se registran, y si cortan
    // la operación la persona ve el aviso de arriba. En desarrollo, el detalle técnico va dentro del aviso.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (is_dir(dirname(__DIR__) . '/logs')) {
        ini_set('error_log', dirname(__DIR__) . '/logs/php-error.log');
    }

    // EL ARCHIVO MÁS GRANDE DE LO QUE PHP ACEPTA: PHP lo descarta entero sin avisar y el formulario
    // llega vacío —sin el token de seguridad—, así que hasta hoy salía "la sesión venció". Se avisa lo real.
    // Se llama al final de config/config.php, cuando ya están BASE_URL y la sesión.
    function revisarSubidaDemasiadoGrande() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && empty($_FILES)
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
            && preg_match('#multipart/form-data|application/x-www-form-urlencoded#i', $_SERVER['CONTENT_TYPE'] ?? '')) {
            responderErrorSistema(explicarErrorDeSubida(UPLOAD_ERR_INI_SIZE));
        }
    }
}
