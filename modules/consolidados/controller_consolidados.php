<?php
// modules/consolidados/controller_consolidados.php
// Acciones del módulo: importar el Consolidado, importar el maestro y descargar el PDF por CEDI.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_consolidados.php';

$vistaConsolidados = BASE_URL . '/consolidados';
$vistaMaestro      = BASE_URL . '/maestro';

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCSRF();
}

// Comprobaciones del archivo subido que hay que hacer ANTES de dárselo a PhpSpreadsheet.
// Devuelve la ruta temporal, o redirige con el error correspondiente y corta.
function archivoExcelSubidoOSalir($destinoSiFalla) {
    $archivo = $_FILES['archivo'] ?? null;

    if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
        // UPLOAD_ERR_INI_SIZE / FORM_SIZE son el caso frecuente y no se distinguen de un archivo
        // corrupto en el mensaje: los dos se resuelven volviendo a subirlo.
        header("Location: {$destinoSiFalla}?error=archivo");
        exit();
    }

    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls'], true)) {
        header("Location: {$destinoSiFalla}?error=formato");
        exit();
    }

    // is_uploaded_file: sin esta comprobación, un POST armado a mano podría pasar una ruta del
    // servidor en tmp_name y hacer que el sistema lea un archivo que no subió nadie.
    if (!is_uploaded_file($archivo['tmp_name'])) {
        header("Location: {$destinoSiFalla}?error=archivo");
        exit();
    }

    return $archivo;
}

// Dónde esperan los archivos cuya importación quedó pendiente de que el usuario conteste Sí o No.
//
// Hace falta guardarlos: el archivo subido vive en el temporal de PHP y desaparece en cuanto
// termina el request, así que sin esto un "Sí" no tendría nada que importar y habría que pedirle a
// la persona que lo vuelva a seleccionar. La carpeta tiene su propio .htaccess que niega el acceso
// web (son datos de pedidos).
function carpetaImportacionesPendientes() {
    $carpeta = ROOT_PATH . '/temp';
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0775, true);
    }
    return $carpeta;
}

// Borra el archivo que hubiera quedado esperando y limpia la sesión.
//
// Se llama al resolver la pregunta y también antes de dejar uno nuevo: si alguien sube un archivo,
// ve el aviso y se va de la pantalla sin contestar, ese archivo se quedaría ahí para siempre.
function descartarImportacionPendiente() {
    if (!empty($_SESSION['importacion_pendiente']['ruta'])) {
        @unlink($_SESSION['importacion_pendiente']['ruta']);
    }
    unset($_SESSION['importacion_pendiente']);
}

switch ($accion) {

    // -----------------------------------------------------------------------------------------
    // IMPORTAR EL CONSOLIDADO
    // -----------------------------------------------------------------------------------------
    case 'importar_consolidado':
        requierePermiso('modulo_consolidados', $vistaConsolidados);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: {$vistaConsolidados}");
            exit();
        }

        require_once __DIR__ . '/model_consolidados_import.php';
        $archivo = archivoExcelSubidoOSalir($vistaConsolidados);

        // ¿Es exactamente lo mismo que ya está cargado? Si lo es, no se importa todavía: se
        // pregunta. Reimportar borra las líneas actuales y con ellas las asignaciones de personal
        // que ya se hubieran hecho, así que rehacer sin querer el trabajo del día es un costo
        // real, no una molestia.
        $huella = huellaDelConsolidado($archivo['tmp_name']);
        $cargaPrevia = cargaConLaMismaHuella($pdo, $huella);

        // Si no es el mismo archivo, todavía puede ser un PEDAZO de uno ya cargado: un Consolidado
        // por zona con tres de las quince órdenes del completo. La huella no lo ve —son archivos
        // distintos— pero las órdenes repetidas sí. Ver ordenesYaPendientes().
        $ordenesRepetidas = null;
        if (!$cargaPrevia) {
            $yaPendientes = ordenesYaPendientes($pdo, $archivo['tmp_name']);
            if ($yaPendientes !== null && $yaPendientes['repetidas']) {
                $ordenesRepetidas = $yaPendientes;
            }
        }

        if ($cargaPrevia || $ordenesRepetidas) {
            descartarImportacionPendiente();   // por si había otro esperando de antes

            $destino = carpetaImportacionesPendientes() . '/consolidado_' . bin2hex(random_bytes(8)) . '.xlsx';

            if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
                guardarMensajeFlashTexto('error', 'No se pudo preparar el archivo para confirmarlo. Volvé a subirlo.');
                header("Location: {$vistaConsolidados}");
                exit();
            }

            $_SESSION['importacion_pendiente'] = [
                'ruta'              => $destino,
                'nombre'            => $archivo['name'],
                'carga_previa'      => $cargaPrevia,
                'ordenes_repetidas' => $ordenesRepetidas,
            ];

            header("Location: {$vistaConsolidados}?confirmar=duplicado");
            exit();
        }

        $resultado = importarConsolidado(
            $pdo, $archivo['tmp_name'], $archivo['name'], $_SESSION['usuario_id'] ?? null
        );

        // El detalle ("se importaron 348 líneas") va por flash con el texto ya escrito y no por
        // ?exito=codigo: el catálogo de mensajes tiene textos fijos y este trae un número que
        // cambia en cada carga.
        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);

        header("Location: {$vistaConsolidados}");
        exit();

    // -----------------------------------------------------------------------------------------
    // RESPUESTA A "ESTE ARCHIVO YA ESTÁ CARGADO, ¿LO SUBO IGUAL?"
    // -----------------------------------------------------------------------------------------
    case 'resolver_duplicado':
        requierePermiso('modulo_consolidados', $vistaConsolidados);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: {$vistaConsolidados}");
            exit();
        }

        $pendiente = $_SESSION['importacion_pendiente'] ?? null;

        // Sin nada esperando: la pantalla quedó abierta de antes, o se recargó el POST. No se
        // importa nada y no se avisa de un error que no lo es.
        if (!$pendiente || !is_file($pendiente['ruta'])) {
            descartarImportacionPendiente();
            header("Location: {$vistaConsolidados}");
            exit();
        }

        if (($_POST['respuesta'] ?? '') !== 'si') {
            descartarImportacionPendiente();
            guardarMensajeFlashTexto('exito', 'No se importó nada: el Consolidado cargado quedó como estaba.');
            header("Location: {$vistaConsolidados}");
            exit();
        }

        require_once __DIR__ . '/model_consolidados_import.php';

        $resultado = importarConsolidado(
            $pdo, $pendiente['ruta'], $pendiente['nombre'], $_SESSION['usuario_id'] ?? null
        );

        descartarImportacionPendiente();

        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
        header("Location: {$vistaConsolidados}");
        exit();

    // -----------------------------------------------------------------------------------------
    // IMPORTAR EL MAESTRO DESDE EL EXPORT DE FACTURACIÓN DE SAP
    // Es la vía principal: ese archivo trae SKU, descripción, EAN, empaque y peso de una vez.
    // -----------------------------------------------------------------------------------------
    case 'importar_maestro_sap':
        requierePermiso('modulo_maestro', $vistaMaestro);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: {$vistaMaestro}");
            exit();
        }

        require_once __DIR__ . '/model_consolidados_import.php';
        $archivo = archivoExcelSubidoOSalir($vistaMaestro);

        $resultado = importarMaestroDesdeSap($pdo, $archivo['tmp_name']);

        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);

        header("Location: {$vistaMaestro}");
        exit();

    // -----------------------------------------------------------------------------------------
    // IMPORTAR UN MAESTRO ARMADO A MANO
    // -----------------------------------------------------------------------------------------
    case 'importar_maestro':
        requierePermiso('modulo_maestro', $vistaMaestro);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: {$vistaMaestro}");
            exit();
        }

        require_once __DIR__ . '/model_consolidados_import.php';
        $archivo = archivoExcelSubidoOSalir($vistaMaestro);

        $resultado = importarMaestro($pdo, $archivo['tmp_name']);

        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);

        header("Location: {$vistaMaestro}");
        exit();

    // -----------------------------------------------------------------------------------------
    // EXCEPCIONES DE ÉXITO DEL MAESTRO: subir el Excel de SKU con empaque/cubicaje propios del Éxito.
    // -----------------------------------------------------------------------------------------
    case 'importar_maestro_exito':
        requierePermiso('modulo_maestro', $vistaMaestro);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: {$vistaMaestro}?ver=exito");
            exit();
        }

        require_once __DIR__ . '/model_maestro_exito.php';
        $archivo = archivoExcelSubidoOSalir($vistaMaestro . '?ver=exito');

        $resultado = importarMaestroExito($pdo, $archivo['tmp_name']);
        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);

        header("Location: {$vistaMaestro}?ver=exito");
        exit();

    // Borrar una o varias excepciones de Éxito (por SKU).
    case 'eliminar_excepcion_exito':
        requierePermiso('modulo_maestro', $vistaMaestro);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: {$vistaMaestro}?ver=exito"); exit(); }

        require_once __DIR__ . '/model_maestro_exito.php';
        $skus = is_array($_POST['skus'] ?? null) ? $_POST['skus'] : array_filter([$_POST['sku'] ?? '']);
        $n = eliminarExcepcionesExito($pdo, $skus);
        guardarMensajeFlashTexto($n > 0 ? 'exito' : 'error',
            $n > 0 ? "{$n} excepción(es) eliminada(s)." : 'No se eliminó ninguna excepción.');

        header("Location: {$vistaMaestro}?ver=exito");
        exit();

    // -----------------------------------------------------------------------------------------
    // DESCARGAS DE LOS CEDI SELECCIONADOS (barra de abajo de Consolidados)
    //
    // Por POST y no por GET como las de un solo CEDI: la lista de nombres elegidos puede ser larga
    // para una URL. 'formato' lo pone el botón que se pulsó:
    //   · interno   → consolidado interno, una hoja por CEDI;
    //   · externo   → consolidado externo, una hoja por CEDI;
    //   · productos → todos los productos de esos CEDI sumados en UNA tabla.
    // -----------------------------------------------------------------------------------------
    case 'pdf_seleccion':
        requierePermiso('modulo_consolidados', $vistaConsolidados);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: {$vistaConsolidados}");
            exit();
        }

        // Solo textos: un cedi[][] armado a mano llegaría como arreglo y no es un nombre.
        $cedis = array_values(array_unique(array_filter(
            array_map(fn($c) => is_string($c) ? trim($c) : '', (array) ($_POST['cedi'] ?? [])),
            fn($c) => $c !== ''
        )));
        $formato = $_POST['formato'] ?? '';

        if (!$cedis || !in_array($formato, ['interno', 'externo', 'productos'], true)) {
            guardarMensajeFlashTexto('error', 'Seleccioná al menos un CEDI para descargar.');
            header("Location: {$vistaConsolidados}");
            exit();
        }

        $filtros = ['cedis' => $cedis, 'linea' => is_string($_POST['linea'] ?? null) ? trim($_POST['linea']) : ''];
        $meta    = cargaVigente($pdo) ?? [];
        $varios  = count($cedis) . '_CEDI_' . date('Ymd') . '.pdf';

        if ($formato === 'productos') {
            $datos = productosDelConsolidado($pdo, $filtros);
            if (empty($datos['filas'])) {
                header("Location: {$vistaConsolidados}?error=sin_datos");
                exit();
            }
            require_once __DIR__ . '/helper_consolidado_productos_pdf.php';
            $nombre = count($cedis) === 1
                ? 'Productos_' . nombreArchivoCedi($cedis[0])
                : 'Productos_consolidado_' . $varios;
            descargarProductosConsolidadoPdf($datos, $meta, $nombre);
        }

        if ($formato === 'externo') {
            $porCedi = consolidadoExternoPorCedi($pdo, $filtros);
            if (empty($porCedi)) {
                header("Location: {$vistaConsolidados}?error=sin_datos");
                exit();
            }
            require_once __DIR__ . '/helper_consolidado_externo_pdf.php';
            $nombre = count($cedis) === 1 ? nombreArchivoCediExterno($cedis[0]) : 'Consolidado_rotulos_' . $varios;
            descargarConsolidadoExternoPdf($porCedi, $meta, $nombre);
        }

        $porCedi = consolidadoPorCedi($pdo, $filtros);
        if (empty($porCedi)) {
            header("Location: {$vistaConsolidados}?error=sin_datos");
            exit();
        }
        require_once __DIR__ . '/helper_consolidado_pdf.php';
        $nombre = count($cedis) === 1 ? nombreArchivoCedi($cedis[0]) : 'Consolidado_alistamiento_' . $varios;
        descargarConsolidadoPdf($porCedi, $meta, $nombre);
        // Las tres funciones de descarga terminan la ejecución.

    // -----------------------------------------------------------------------------------------
    // PDF DEL CONSOLIDADO
    // Sin ?cedi= sale el archivo completo, con una hoja por CEDI.
    // -----------------------------------------------------------------------------------------
    // EL CONSOLIDADO EXTERNO EN PDF
    //
    // El mismo pedido que el interno pero abierto por punto de venta: el interno es el papel
    // del elevador (cuánto bajar de bodega) y el externo es el que se entrega o se le muestra
    // a la cadena (cuánto va a cada tienda). Ver consolidadoExternoPorCedi().
    // -----------------------------------------------------------------------------------------
    case 'pdf_externo':
        requierePermiso('modulo_consolidados', $vistaConsolidados);

        $filtros = [
            'cedi'  => trim($_GET['cedi'] ?? ''),
            'linea' => trim($_GET['linea'] ?? ''),
        ];

        $porCedi = consolidadoExternoPorCedi($pdo, $filtros);
        if (empty($porCedi)) {
            header("Location: {$vistaConsolidados}?error=sin_datos");
            exit();
        }

        require_once __DIR__ . '/helper_consolidado_externo_pdf.php';

        $nombre = $filtros['cedi'] !== ''
            ? nombreArchivoCediExterno($filtros['cedi'])
            : 'Consolidado_rotulos_todos_los_CEDI_' . date('Ymd') . '.pdf';

        descargarConsolidadoExternoPdf($porCedi, cargaVigente($pdo) ?? [], $nombre);
        // descargarConsolidadoExternoPdf() termina la ejecución.

    // -----------------------------------------------------------------------------------------
    case 'pdf':
        requierePermiso('modulo_consolidados', $vistaConsolidados);

        $filtros = [
            'cedi'  => trim($_GET['cedi'] ?? ''),
            'linea' => trim($_GET['linea'] ?? ''),
        ];

        $porCedi = consolidadoPorCedi($pdo, $filtros);
        if (empty($porCedi)) {
            header("Location: {$vistaConsolidados}?error=sin_datos");
            exit();
        }

        require_once __DIR__ . '/helper_consolidado_pdf.php';

        $nombre = $filtros['cedi'] !== ''
            ? nombreArchivoCedi($filtros['cedi'])
            : 'Consolidado_alistamiento_todos_los_CEDI_' . date('Ymd') . '.pdf';

        // $meta ya no es "la carga vigente" (puede haber varias pendientes): se le pasa la última
        // importada solo como referencia de "emitido" en el encabezado del PDF, no como el alcance
        // de los datos —eso ya lo decidió consolidadoPorCedi() mirando TODO lo pendiente.
        descargarConsolidadoPdf($porCedi, cargaVigente($pdo) ?? [], $nombre);
        // descargarConsolidadoPdf() termina la ejecución.

    // -----------------------------------------------------------------------------------------
    // ELIMINAR TODOS LOS CONSOLIDADOS (y el historial de pedidos). Ver eliminarTodosLosConsolidados().
    //
    // Pide dos cosas: el permiso consolidados_eliminar_todo y la CONTRASEÑA de quien lo hace, que
    // se verifica contra la base en este momento. La contraseña es la última barrera de algo que no
    // se puede deshacer: un clic sin querer, o alguien que encontró la sesión abierta, no alcanzan.
    // -----------------------------------------------------------------------------------------
    case 'eliminar_todo':
        requierePermiso('consolidados_eliminar_todo', $vistaConsolidados);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: {$vistaConsolidados}");
            exit();
        }

        $contrasena = (string) ($_POST['contrasena'] ?? '');
        $stmt = $pdo->prepare("SELECT contrasena_usuario FROM usuarios WHERE id_usuario = ? AND estado = 'Activo'");
        $stmt->execute([$_SESSION['usuario_id'] ?? 0]);
        $hash = $stmt->fetchColumn();

        if ($contrasena === '' || !$hash || !password_verify($contrasena, $hash)) {
            // Un segundo de espera en cada intento fallido: no molesta a quien se equivocó una vez y
            // hace inútil probar contraseñas en cadena desde una sesión abierta.
            sleep(1);
            guardarMensajeFlashTexto('error', 'La contraseña no es correcta. No se borró nada.');
            header("Location: {$vistaConsolidados}");
            exit();
        }

        descartarImportacionPendiente();   // un archivo esperando confirmación ya no tiene sentido
        $r = eliminarTodosLosConsolidados($pdo);

        if (!$r['exito']) {
            guardarMensajeFlashTexto('error', 'No se pudieron eliminar los consolidados. No se borró nada; intentalo de nuevo.');
        } else {
            guardarMensajeFlashTexto('exito', 'Se eliminaron todos los consolidados: '
                . $r['cargas'] . ' archivo(s), ' . number_format($r['lineas'], 0, ',', '.') . ' línea(s) y '
                . $r['despachadas'] . ' pedido(s) del historial. El sistema quedó sin pedidos.');
        }

        header("Location: {$vistaConsolidados}");
        exit();

    default:
        header("Location: {$vistaConsolidados}");
        exit();
}
