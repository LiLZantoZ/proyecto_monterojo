<?php
// modules/consolidado_mr/controller_consolidado_mr.php
// Acciones del Consolidado MR (todas POST, con CSRF):
//   · importar — sube el Excel "CONSOLIDADO MR" de SAP
//   · vaciar   — borra todo lo cargado (por si se subió un archivo equivocado)
//
// La tabla no pasa por acá: la arma la vista, que es solo lectura.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/model_consolidado_mr.php';

$vista = BASE_URL . '/consolidado-mr';

requierePermiso('modulo_consolidado_mr', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));
// Importar y vaciar son de quien puede modificar (2026-09-28): el rol Visitante solo consulta.
requierePermiso('consolidado_mr_editar', BASE_URL . '/consolidado-mr');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vista}");
    exit();
}

validarCSRF();

switch ($_POST['accion'] ?? '') {

    case 'importar':
        $archivo = $_FILES['archivo'] ?? null;

        if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
            guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
            break;
        }
        if (!in_array(strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            guardarMensajeFlashTexto('error', 'El archivo tiene que ser un Excel (.xlsx o .xls).');
            break;
        }
        // Sin esto, un POST armado a mano podría pasar una ruta del servidor en tmp_name.
        if (!is_uploaded_file($archivo['tmp_name'])) {
            guardarMensajeFlashTexto('error', 'No se pudo recibir el archivo. Probá de nuevo.');
            break;
        }

        $r = importarConsolidadoMr($pdo, $archivo['tmp_name'], $_SESSION['usuario_id'] ?? null);
        guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
        break;

    case 'vaciar':
        $n = vaciarConsolidadoMr($pdo);
        guardarMensajeFlashTexto('exito', $n > 0
            ? 'Se borraron ' . number_format($n, 0, ',', '.') . ' renglón(es) del Consolidado MR.'
            : 'No había nada cargado.');
        break;
}

header("Location: {$vista}");
exit();
