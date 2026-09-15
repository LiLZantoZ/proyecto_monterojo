<?php
// modules/ordenes_compra/controller_ordenes_compra.php
// Tres acciones:
//   · pdf                   (GET)  — la tabla en PDF, con el filtro de la pantalla
//   · subir_cubicajes       (POST) — carga o actualiza los cubicajes desde el Excel
//   · guardar_vehiculos     (POST) — la capacidad de cada vehículo y cuáles están en uso
//
// La tabla de órdenes no pasa por acá: la arma la vista, porque es solo lectura.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_ordenes_compra.php';

$vista = BASE_URL . '/ordenes-compra';

requierePermiso('modulo_ordenes_compra', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// La tabla en PDF, con el mismo filtro que hay en pantalla. Es de lectura: va por GET y sin CSRF,
// igual que los PDF de los otros módulos.
if (($_GET['accion'] ?? '') === 'pdf') {
    $filtros = ['oc' => trim($_GET['oc'] ?? '')];
    $datos   = ordenesDeCompraExito($pdo, $filtros);

    if (empty($datos['ordenes'])) {
        guardarMensajeFlashTexto('error', 'No hay órdenes que descargar con ese filtro.');
        header("Location: {$vista}" . ($filtros['oc'] !== '' ? '?oc=' . urlencode($filtros['oc']) : ''));
        exit();
    }

    require_once __DIR__ . '/helper_ordenes_compra_pdf.php';
    descargarOrdenesCompraPdf($datos, $filtros);   // termina la ejecución
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vista}");
    exit();
}

validarCSRF();

switch ($_POST['accion'] ?? '') {

    case 'subir_cubicajes':
        $archivo = $_FILES['archivo'] ?? null;

        if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
            guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
            break;
        }
        if (!in_array(strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            guardarMensajeFlashTexto('error', 'El archivo de cubicajes tiene que ser un Excel (.xlsx o .xls).');
            break;
        }
        // Sin esto, un POST armado a mano podría pasar una ruta del servidor en tmp_name y hacer que
        // el sistema lea un archivo que no subió nadie.
        if (!is_uploaded_file($archivo['tmp_name'])) {
            guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
            break;
        }

        $resultado = importarCubicajes($pdo, $archivo['tmp_name']);
        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
        break;

    case 'guardar_vehiculos':
        $filas = is_array($_POST['vehiculos'] ?? null) ? $_POST['vehiculos'] : [];

        // Las casillas "en uso" que se desmarcan no viajan en el POST: sin esto, desactivar un
        // vehículo no tendría efecto. Todo vehículo del formulario manda sus números, así que se
        // completa "activo" en 0 para los que no trajeron la casilla.
        foreach ($filas as $id => $datos) {
            $filas[$id]['activo'] = isset($datos['activo']) ? 1 : 0;
        }

        $invalidos = guardarVehiculos($pdo, $filas);

        if ($invalidos === false) {
            guardarMensajeFlashTexto('error', 'No se pudieron guardar los vehículos. Intentalo de nuevo.');
        } elseif ($invalidos > 0) {
            guardarMensajeFlashTexto('error', "Se guardaron los vehículos, salvo {$invalidos} con datos inválidos, que quedaron como estaban. "
                                   . 'Un vehículo en uso tiene que tener peso y m³ mayores que 0.');
        } else {
            guardarMensajeFlashTexto('exito', 'Vehículos guardados.');
        }
        break;
}

header("Location: {$vista}");
exit();
