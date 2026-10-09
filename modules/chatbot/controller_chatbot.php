<?php
// modules/chatbot/controller_chatbot.php
// Lo que pide el globo del asistente (siempre JSON): enviar una pregunta, el historial, la lista de
// acciones del menú "/", ejecutar una acción y borrar la conversación. Las escrituras son POST con el
// token CSRF dentro del JSON (tokenCSRFRecibido() lo busca ahí).

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/model_chatbot.php';

header('Content-Type: application/json; charset=utf-8');

$responder = function ($datos, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
};

if (!tienePermiso('modulo_chatbot')) {
    $responder(['exito' => false, 'error' => 'Tu rol no tiene acceso al asistente.'], 403);
}

$accion = $_GET['accion'] ?? '';
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($esPost) {
    validarCSRF();
}
$cuerpo = $esPost ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : [];
$idUsuario = (int) $_SESSION['usuario_id'];

switch ($accion) {
    case 'enviar':
        if (!$esPost) {
            $responder(['error' => 'Método no permitido.'], 405);
        }
        $mensaje = trim((string) ($cuerpo['mensaje'] ?? ''));
        if ($mensaje === '') {
            $responder(['error' => 'Escribí una pregunta.'], 400);
        }
        if (mb_strlen($mensaje) > CHATBOT_MAX_MENSAJE) {
            $responder(['error' => 'La pregunta es demasiado larga (máximo ' . CHATBOT_MAX_MENSAJE . ' caracteres).'], 400);
        }
        // Una pregunta puede encadenar varias llamadas a la IA: más que el tiempo por defecto del servidor.
        @set_time_limit(150);
        $historial = historialChatbot($pdo, $idUsuario);
        guardarMensajeChatbot($pdo, $idUsuario, 'user', $mensaje);
        $respuesta = responderChatbot($pdo, $idUsuario, $mensaje, $historial,
            $_SESSION['usuario_nombre'] ?? 'Usuario', $_SESSION['nombre_rol'] ?? 'Sin rol');
        guardarMensajeChatbot($pdo, $idUsuario, 'assistant', $respuesta);
        $responder(['respuesta' => $respuesta]);

    case 'historial':
        $responder(['mensajes' => historialChatbot($pdo, $idUsuario, 40)]);

    // El menú "/": lo que el rol puede ejecutar. Sin llamadas a la IA.
    case 'acciones':
        require_once __DIR__ . '/acciones_chatbot.php';
        $responder(['acciones' => (object) accionesDisponiblesChatbot()]);

    case 'ejecutar_accion':
        if (!$esPost) {
            $responder(['error' => 'Método no permitido.'], 405);
        }
        require_once __DIR__ . '/acciones_chatbot.php';
        $nombre = (string) ($cuerpo['accion_nombre'] ?? '');
        $resultado = ejecutarAccionChatbot($pdo, $nombre, is_array($cuerpo['datos'] ?? null) ? $cuerpo['datos'] : []);
        // La acción queda en la conversación con su resultado REAL, para que el historial (y el modelo,
        // en las preguntas siguientes) vean lo que de verdad pasó. En Trazabilidad la anota el enrutador.
        $etiqueta = catalogoAccionesChatbot()[$nombre]['etiqueta'] ?? $nombre;
        guardarMensajeChatbot($pdo, $idUsuario, 'user', "[Acción] {$etiqueta}");
        guardarMensajeChatbot($pdo, $idUsuario, 'assistant', $resultado['mensaje']);
        $responder($resultado);

    case 'limpiar':
        if (!$esPost) {
            $responder(['error' => 'Método no permitido.'], 405);
        }
        limpiarHistorialChatbot($pdo, $idUsuario);
        $responder(['exito' => true, 'mensaje' => 'Conversación borrada.']);

    default:
        $responder(['error' => 'Acción no reconocida.'], 400);
}
