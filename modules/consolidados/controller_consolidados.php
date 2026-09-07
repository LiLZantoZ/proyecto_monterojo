<?php
// modules/consolidados/controller_consolidados.php
// Acciones del módulo: importar el Consolidado, importar el maestro y descargar el PDF por CEDI.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_consolidados.php';

$vistaConsolidados = BASE_URL . '/modules/consolidados/views/consolidados.php';
$vistaMaestro      = BASE_URL . '/modules/consolidados/views/maestro.php';

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
    // PDF DEL CONSOLIDADO
    // Sin ?cedi= sale el archivo completo, con una hoja por CEDI.
    // -----------------------------------------------------------------------------------------
    case 'pdf':
        requierePermiso('modulo_consolidados', $vistaConsolidados);

        $carga = cargaVigente($pdo);
        if (!$carga) {
            header("Location: {$vistaConsolidados}?error=sin_datos");
            exit();
        }

        $filtros = [
            'cedi'  => trim($_GET['cedi'] ?? ''),
            'linea' => trim($_GET['linea'] ?? ''),
        ];

        $porCedi = consolidadoPorCedi($pdo, $carga['id_carga'], $filtros);
        if (empty($porCedi)) {
            header("Location: {$vistaConsolidados}?error=sin_datos");
            exit();
        }

        require_once __DIR__ . '/helper_consolidado_pdf.php';

        $nombre = $filtros['cedi'] !== ''
            ? nombreArchivoCedi($filtros['cedi'])
            : 'Consolidado_todos_los_CEDI_' . date('Ymd') . '.pdf';

        descargarConsolidadoPdf($porCedi, $carga, $nombre);
        // descargarConsolidadoPdf() termina la ejecución.

    default:
        header("Location: {$vistaConsolidados}");
        exit();
}
