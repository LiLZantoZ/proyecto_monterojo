<?php
// modules/enlazar_facturas/controller_enlazar_facturas.php
// Acciones de Enlazar facturas (POST, con CSRF):
//   · enlazar — guarda quién alistó y despachó la factura y el número de cajas (reemplaza al anterior)
//   · quitar  — borra el responsable de una factura

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../../config/mensajes.php';
require_once __DIR__ . '/../consolidado_mr/model_consolidado_mr.php';

$vista = BASE_URL . '/enlazar-facturas';

requierePermiso('modulo_enlazar_facturas', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$vista}");
    exit();
}

validarCSRF();

switch ($_POST['accion'] ?? '') {
    case 'enlazar':
        $r = enlazarResponsableMr($pdo, $_POST['factura'] ?? '', $_POST['id_personal'] ?? 0, $_SESSION['usuario_id'] ?? null, $_POST['cajas'] ?? '');
        guardarMensajeFlashTexto($r['exito'] ? 'exito' : 'error', $r['mensaje']);
        // La cédula queda puesta: casi siempre el mismo operario enlaza varias facturas seguidas.
        // Si no se pudo (p. ej. faltaron las cajas), la factura también, para corregir y volver a enlazar.
        $cedula = trim($_POST['cedula'] ?? '');
        $volver = array_filter(['factura' => $r['exito'] ? '' : trim($_POST['factura'] ?? ''), 'cedula' => $cedula]);
        if ($volver) {
            $vista .= '?' . http_build_query($volver);
        }
        break;

    case 'quitar':
        $ok = quitarResponsableMr($pdo, $_POST['factura'] ?? '');
        guardarMensajeFlashTexto($ok ? 'exito' : 'error', $ok ? 'Se quitó el responsable de la factura.' : 'Esa factura no tenía responsable.');
        break;
}

header("Location: {$vista}");
exit();
