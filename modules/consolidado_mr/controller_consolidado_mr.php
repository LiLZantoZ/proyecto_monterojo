<?php
// modules/consolidado_mr/controller_consolidado_mr.php
// Acciones del Consolidado MR (todas POST, con CSRF):
//   · importar       — sube el Excel "CONSOLIDADO MR" de SAP (solo ese)
//   · actualizar_transportadoras — sube el reporte de una transportadora, con su desplegable
//   · vaciar         — borra todo lo cargado (por si se subió un archivo equivocado)
//   · subir_contado  — sube el Consolidado MR desde la pestaña "Facturas de contado"
//   · subir_comparativa — el archivo con los números de las facturas de contado (2026-10-05)
//   · transportador  — (JSON) pone o quita el transportador de una o varias facturas sin guía
//   · contado        — (JSON) marca una o varias facturas de contado Sí/No (2026-10-05)
//   · exportar (GET) — el Excel de la pestaña con sus filtros (2026-10-06); con ver el módulo alcanza
//
// La tabla no pasa por acá: la arma la vista, que es solo lectura (con listaConsolidadoMr()).

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_consolidado_mr.php';

$vista = BASE_URL . '/consolidado-mr';

requierePermiso('modulo_consolidado_mr', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// EXPORTAR EXCEL (2026-10-06): solo lee, así que alcanza con ver el módulo (también el Visitante).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['accion'] ?? '') === 'exportar') {
    @set_time_limit(300);
    $pestanaExcel = pestanaConsolidadoMr($_GET['pestana'] ?? '');
    $ruta = tempnam(sys_get_temp_dir(), 'mr');
    try {
        escribirExcelConsolidadoMr($pdo, $pestanaExcel, filtrosConsolidadoMr($_GET), $ruta);
    } catch (Throwable $e) {
        error_log('Error exportando el Consolidado MR: ' . $e->getMessage());
        @unlink($ruta);
        guardarMensajeFlashTexto('error', 'No se pudo generar el Excel del Consolidado MR.');
        header("Location: {$vista}");
        exit();
    }
    $nombre = ['facturas' => 'consolidado_mr', 'contado' => 'consolidado_mr_contado', 'anuladas' => 'consolidado_mr_anuladas'][$pestanaExcel];
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '_' . date('Y-m-d_His') . '.xlsx"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    @unlink($ruta);
    exit();
}

// Todo lo que cambia algo es de quien puede modificar (2026-09-28): el rol Visitante solo consulta.
requierePermiso('consolidado_mr_editar', BASE_URL . '/consolidado-mr');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vista}");
    exit();
}

// El transportador y el pago llegan como JSON desde scripts_consolidado_mr.js y responden JSON: la
// pantalla no se recarga entera por cada factura. validarCSRF() lee el token del cuerpo JSON.
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    validarCSRF();

    $cuerpo  = json_decode(file_get_contents('php://input'), true) ?: [];
    $usuario = $_SESSION['usuario_id'] ?? null;

    switch ($cuerpo['accion'] ?? '') {
        case 'transportador':
            $n = guardarTransportadoraMr($pdo, (array) ($cuerpo['facturas'] ?? []), $cuerpo['transportadora'] ?? null, $usuario);
            echo json_encode($n > 0
                ? ['exito' => true, 'actualizadas' => $n]
                : ['exito' => false, 'error' => 'No se pudo guardar el transportador. Recargá la página y probá de nuevo.']);
            exit();

        // El estado o la fecha de entrega de UNA factura, puestos a mano (2026-10-07).
        case 'estado_manual':
            echo json_encode(guardarEstadoManualMr($pdo, (string) ($cuerpo['factura'] ?? ''), (string) ($cuerpo['campo'] ?? ''), $cuerpo['valor'] ?? null, $usuario), JSON_UNESCAPED_UNICODE);
            exit();

        case 'contado':
            $n = marcarContadoMr($pdo, (array) ($cuerpo['facturas'] ?? []), !empty($cuerpo['de_contado']), $usuario);
            echo json_encode(['exito' => true, 'actualizadas' => $n]);
            exit();
    }

    http_response_code(400);
    echo json_encode(['exito' => false, 'error' => 'Acción desconocida.']);
    exit();
}

validarCSRF();

// El archivo subido, ya revisado; null (con el mensaje puesto) si no sirve.
$archivoSubido = function () {
    $archivo = $_FILES['archivo'] ?? null;
    if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
        guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
        return null;
    }
    if (!in_array(strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
        guardarMensajeFlashTexto('error', 'El archivo tiene que ser un Excel (.xlsx o .xls).');
        return null;
    }
    // Sin esto, un POST armado a mano podría pasar una ruta del servidor en tmp_name.
    if (!is_uploaded_file($archivo['tmp_name'])) {
        guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
        return null;
    }
    return $archivo['tmp_name'];
};

$volverA = $vista;

switch ($_POST['accion'] ?? '') {

    case 'importar':
        if ($ruta = $archivoSubido()) {
            // Solo el Consolidado MR de SAP (2026-10-05); los reportes van por actualizar_transportadoras.
            $r = importarSoloConsolidadoMr($pdo, $ruta, $_SESSION['usuario_id'] ?? null);
            guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
        }
        break;

    case 'vaciar':
        $n = vaciarConsolidadoMr($pdo);
        guardarMensajeFlashTexto('exito', $n > 0
            ? 'Se borraron ' . number_format($n, 0, ',', '.') . ' renglón(es) del Consolidado MR.'
            : 'No había nada cargado.');
        break;

    case 'subir_contado':
        $volverA = $vista . '?pestana=contado';
        if ($ruta = $archivoSubido()) {
            $r = importarContadoMr($pdo, $ruta, $_SESSION['usuario_id'] ?? null);
            guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
        }
        break;

    case 'subir_comparativa':
        $volverA = $vista . '?pestana=contado';
        if ($ruta = $archivoSubido()) {
            $r = importarComparativaContadoMr($pdo, $ruta, $_SESSION['usuario_id'] ?? null);
            guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
        }
        break;

    // ACTUALIZAR TRANSPORTADORAS (2026-10-05; reemplaza el borrado de los datos de las
    // transportadoras): el reporte de una transportadora, con la transportadora elegida (opcional).
    case 'actualizar_transportadoras':
        if ($ruta = $archivoSubido()) {
            $r = actualizarTransportadorasMr($pdo, $ruta, $_SESSION['usuario_id'] ?? null, $_POST['transportadora'] ?? null);
            guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
        }
        break;
}

header("Location: {$volverA}");
exit();
