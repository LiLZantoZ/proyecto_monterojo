<?php
// modules/chatbot/model_chatbot.php
// EL ASISTENTE (MonteBot, 2026-10-07): traído del sistema de Nutrium (NutriBot) y adaptado a Monterojo.
//
// Cómo funciona: la persona pregunta en el globo flotante; Claude decide qué HERRAMIENTAS de consulta
// usar (herramientas_chatbot.php: facturas, pedidos, productos, posiciones…), PHP las ejecuta con los
// permisos de esa persona y Claude redacta la respuesta con los datos reales. Claude NO escribe en la
// base: lo que cambia datos va por el MENÚ DE ACCIONES ("/" en el chat, acciones_chatbot.php), que
// ejecuta PHP directamente con un formulario, sin pasar por la IA.
//
// De Nutrium se trajo solo el proveedor Claude (el que tiene activo). La conversación de cada persona
// se guarda en chatbot_mensajes; lo que cuesta cada llamada, en chatbot_uso (el freno de presupuesto).

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/herramientas_chatbot.php';

const CHATBOT_NOMBRE = 'MonteBot';
const CHATBOT_CLAVE_DE_RELLENO = 'PEGA-AQUI-TU-CLAVE-DE-CLAUDE';
const CHATBOT_WORKSPACE_DE_RELLENO = 'PEGA-AQUI-EL-ID-DEL-WORKSPACE';
const CHATBOT_MAX_MENSAJE = 2000;
const CHATBOT_MAX_RONDAS_HERRAMIENTAS = 6;
const CHATBOT_MAX_RESULTADO = 4000;   // un listado largo se recorta: se cobra en cada ronda

// Precios por millón de tokens (septiembre de 2026). Un modelo que no esté en la lista se cobra con la
// tarifa más cara conocida: equivocarse por arriba hace saltar el freno antes, que es el lado seguro.
const CHATBOT_PRECIOS = [
    'claude-opus-5-5'   => ['entrada' => 4.0,  'salida' => 20.0],
    'claude-opus-5'     => ['entrada' => 5.0,  'salida' => 25.0],
    'claude-opus-4-8'   => ['entrada' => 5.0,  'salida' => 25.0],
    'claude-sonnet-5-5' => ['entrada' => 2.0,  'salida' => 10.0],
    'claude-sonnet-5'   => ['entrada' => 2.0,  'salida' => 10.0],
    'claude-haiku-4-5'  => ['entrada' => 1.0,  'salida' => 5.0],
    'claude-fable-5-1'  => ['entrada' => 10.0, 'salida' => 50.0],
];

/** Dónde vive la configuración real (con la clave): fuera del repositorio en los dos sistemas. */
function rutaConfigChatbot() {
    return function_exists('storage_path') ? storage_path('app/chatbot_config.php') : __DIR__ . '/../../config/chatbot_config.php';
}

if (is_file(rutaConfigChatbot())) {
    require_once rutaConfigChatbot();
}
defined('CHATBOT_MODELO') || define('CHATBOT_MODELO', 'claude-opus-5-5');
defined('CHATBOT_PRESUPUESTO_USD') || define('CHATBOT_PRESUPUESTO_USD', 5.00);
defined('CHATBOT_LIMITE_HISTORIAL') || define('CHATBOT_LIMITE_HISTORIAL', 8);

/** La clave: la del archivo si no es el relleno; si no, la variable de entorno ANTHROPIC_API_KEY. */
function claveChatbot() {
    if (defined('CHATBOT_ANTHROPIC_API_KEY') && CHATBOT_ANTHROPIC_API_KEY !== '' && CHATBOT_ANTHROPIC_API_KEY !== CHATBOT_CLAVE_DE_RELLENO) {
        return CHATBOT_ANTHROPIC_API_KEY;
    }
    return (string) (getenv('ANTHROPIC_API_KEY') ?: '');
}

function workspaceChatbot() {
    if (defined('CHATBOT_ANTHROPIC_WORKSPACE_ID') && CHATBOT_ANTHROPIC_WORKSPACE_ID !== '' && CHATBOT_ANTHROPIC_WORKSPACE_ID !== CHATBOT_WORKSPACE_DE_RELLENO) {
        return CHATBOT_ANTHROPIC_WORKSPACE_ID;
    }
    return (string) (getenv('ANTHROPIC_WORKSPACE_ID') ?: '');
}

/** El cliente del SDK. Lo usan el asistente y el script de prueba, para que hablen igual con la API. */
function clienteChatbot($clave) {
    $workspace = workspaceChatbot();
    return $workspace === ''
        ? new \Anthropic\Client(apiKey: $clave)
        : new \Anthropic\Client(apiKey: $clave, requestOptions: ['extraHeaders' => ['anthropic-workspace-id' => $workspace]]);
}

// ---------------------------------------------------------------------------------------------
// LA CONVERSACIÓN
// ---------------------------------------------------------------------------------------------

function guardarMensajeChatbot($pdo, $idUsuario, $rol, $mensaje) {
    $pdo->prepare("INSERT INTO chatbot_mensajes (id_usuario, rol, mensaje) VALUES (?, ?, ?)")
        ->execute([(int) $idUsuario, $rol === 'assistant' ? 'assistant' : 'user', (string) $mensaje]);
}

/** Los últimos mensajes de la persona, del más viejo al más nuevo. */
function historialChatbot($pdo, $idUsuario, $limite = CHATBOT_LIMITE_HISTORIAL) {
    $st = $pdo->prepare("SELECT rol, mensaje, fecha FROM chatbot_mensajes WHERE id_usuario = ?
                         ORDER BY fecha DESC, id_mensaje DESC LIMIT " . max(1, (int) $limite));
    $st->execute([(int) $idUsuario]);
    return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
}

function limpiarHistorialChatbot($pdo, $idUsuario) {
    $pdo->prepare("DELETE FROM chatbot_mensajes WHERE id_usuario = ?")->execute([(int) $idUsuario]);
}

/** Las instrucciones del asistente. Las reglas de "no escribís" y de permisos vienen de Nutrium. */
function promptSistemaChatbot($nombreUsuario, $rolUsuario) {
    $hoy = date('d/m/Y');
    $nombre = CHATBOT_NOMBRE;
    return <<<PROMPT
Eres {$nombre}, el asistente del sistema de Monterojo Gourmet (bodega, despacho y facturación). Respondes en español de Colombia, breve y claro.
Hablas con {$nombreUsuario} (rol: {$rolUsuario}). Hoy es {$hoy}.

Usa las herramientas para consultar datos reales del sistema; nunca inventes cifras, facturas, productos ni fechas. Si una consulta no devuelve nada, dilo tal cual.
Los productos se identifican por SKU, PLU o EAN, no por el nombre: el catálogo repite nombres con distinto gramaje. Si te dan solo un nombre, busca y muestra las opciones con su SKU.

TÚ NO ESCRIBES EN LA BASE DE DATOS: solo consultas. Registrar un producto, ubicar una estiba en una posición o sacarla se hacen desde el MENÚ DE ACCIONES: la persona escribe "/" en el chat, elige la acción y llena el formulario. Si te piden hacer una de esas cosas, responde exactamente eso. Todo lo demás (importar archivos, despachar, enlazar facturas, editar usuarios) se hace desde su pantalla del menú lateral.
Nunca digas que algo quedó guardado: no tienes forma de saberlo. Si dicen que ya lo hicieron, puedes consultar para confirmarlo.

PERMISOS: las herramientas que tienes ya están recortadas según el rol de esta persona. Si te piden algo para lo que no tienes herramienta, responde que su rol no tiene acceso a esa información y que lo consulte con un administrador. No intentes rodearlo con otra herramienta.

Responde solo sobre el trabajo de Monterojo (bodega, pedidos, despacho, facturación, inventario y el uso del sistema). Usa pocas herramientas por respuesta: la más específica. Para listas largas, muestra lo más importante y di cuántas hay en total. Escribe en texto plano (sin tablas Markdown); puedes usar guiones para listas.
PROMPT;
}

// ---------------------------------------------------------------------------------------------
// EL PRESUPUESTO
// ---------------------------------------------------------------------------------------------

/** Lo que costó una llamada, con la entrada en caché a su precio (escritura ×1,25, lectura ×0,1). */
function costoLlamadaChatbot($modelo, $usage) {
    $t = CHATBOT_PRECIOS[$modelo] ?? ['entrada' => 10.0, 'salida' => 50.0];
    $entrada = (int) ($usage->inputTokens ?? 0) + 1.25 * (int) ($usage->cacheCreationInputTokens ?? 0) + 0.1 * (int) ($usage->cacheReadInputTokens ?? 0);
    return ($entrada * $t['entrada'] + (int) ($usage->outputTokens ?? 0) * $t['salida']) / 1000000;
}

function gastoAcumuladoChatbot($pdo) {
    try {
        return (float) $pdo->query("SELECT COALESCE(SUM(costo_usd), 0) FROM chatbot_uso")->fetchColumn();
    } catch (PDOException $e) {
        error_log('Chatbot: falta la tabla chatbot_uso (corré scripts/crear_esquema_completo.php).');
        return 0.0;
    }
}

function anotarUsoChatbot($pdo, $idUsuario, $modelo, $usage) {
    try {
        $pdo->prepare("INSERT INTO chatbot_uso (id_usuario, modelo, tokens_entrada, tokens_salida, costo_usd) VALUES (?, ?, ?, ?, ?)")
            ->execute([$idUsuario ?: null, mb_substr((string) $modelo, 0, 60),
                       (int) ($usage->inputTokens ?? 0) + (int) ($usage->cacheCreationInputTokens ?? 0) + (int) ($usage->cacheReadInputTokens ?? 0),
                       (int) ($usage->outputTokens ?? 0), costoLlamadaChatbot($modelo, $usage)]);
    } catch (PDOException $e) {
        error_log('Chatbot: no se pudo anotar el uso: ' . $e->getMessage());
    }
}

function presupuestoAgotadoChatbot($pdo) {
    return CHATBOT_PRESUPUESTO_USD > 0 && gastoAcumuladoChatbot($pdo) >= CHATBOT_PRESUPUESTO_USD;
}

// ---------------------------------------------------------------------------------------------
// LA PREGUNTA
// ---------------------------------------------------------------------------------------------

/**
 * Responde una pregunta: arma la conversación, deja que Claude use las herramientas de consulta (en
 * rondas, hasta CHATBOT_MAX_RONDAS_HERRAMIENTAS) y devuelve el texto final. Nunca lanza: un error se
 * devuelve como un mensaje que la persona entiende.
 */
function responderChatbot($pdo, $idUsuario, $mensaje, array $historial, $nombreUsuario, $rolUsuario) {
    $clave = claveChatbot();
    if ($clave === '') {
        return 'El asistente todavía no tiene la clave de Claude. Un administrador tiene que ponerla en '
             . (function_exists('storage_path') ? 'storage/app/chatbot_config.php' : 'config/chatbot_config.php')
             . ' (CHATBOT_ANTHROPIC_API_KEY) o en la variable de entorno ANTHROPIC_API_KEY.';
    }
    if (presupuestoAgotadoChatbot($pdo)) {
        return sprintf('Se agotó el presupuesto del asistente (US$ %s de US$ %s). Un administrador tiene que subir '
                     . 'CHATBOT_PRESUPUESTO_USD en la configuración y revisar el saldo en console.anthropic.com.',
                       number_format(gastoAcumuladoChatbot($pdo), 2), number_format(CHATBOT_PRESUPUESTO_USD, 2));
    }

    $cliente = clienteChatbot($clave);
    $herramientas = herramientasParaClaudeChatbot();
    // El prompt va con caché: las herramientas y las instrucciones se repiten en cada ronda de la misma
    // pregunta, y leídas de la caché cuestan la décima parte.
    $sistema = [['type' => 'text', 'text' => promptSistemaChatbot($nombreUsuario, $rolUsuario), 'cacheControl' => ['type' => 'ephemeral']]];

    $mensajes = [];
    foreach ($historial as $m) {
        $mensajes[] = ['role' => $m['rol'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $m['mensaje']];
    }
    $mensajes[] = ['role' => 'user', 'content' => (string) $mensaje];

    // UNA sola puerta a la API, que anota lo que costó cada llamada (una pregunta hace 2 o 3).
    // effort 'low': es un chat de consultas; con Claude Opus 5.5 el razonamiento siempre está activo
    // y el esfuerzo es lo que lo dosifica. fallbacks 'default': si el modelo declina por política, la
    // API reintenta sola con el modelo de respaldo que corresponde (beta server-side-fallback).
    $llamar = function (array $mensajes) use ($cliente, $sistema, $herramientas, $pdo, $idUsuario) {
        $r = $cliente->beta->messages->create(
            model: CHATBOT_MODELO,
            maxTokens: 16000,
            system: $sistema,
            tools: $herramientas,
            messages: $mensajes,
            outputConfig: ['effort' => 'low'],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
        anotarUsoChatbot($pdo, $idUsuario, $r->model ?? CHATBOT_MODELO, $r->usage);
        return $r;
    };

    try {
        $respuesta = $llamar($mensajes);
        for ($ronda = 0; $ronda < CHATBOT_MAX_RONDAS_HERRAMIENTAS && $respuesta->stopReason === 'tool_use'; $ronda++) {
            $resultados = [];
            foreach ($respuesta->content as $bloque) {
                if (($bloque->type ?? '') !== 'tool_use') {
                    continue;
                }
                $entrada = is_array($bloque->input) ? $bloque->input : (array) json_decode(json_encode($bloque->input), true);
                $salida = ejecutarHerramientaChatbot($pdo, (string) $bloque->name, $entrada);
                if (mb_strlen($salida) > CHATBOT_MAX_RESULTADO) {
                    $salida = mb_substr($salida, 0, CHATBOT_MAX_RESULTADO) . "\n[…resultado recortado: pedile a la persona que acote la búsqueda.]";
                }
                $resultados[] = ['type' => 'tool_result', 'toolUseID' => $bloque->id, 'content' => $salida];
            }
            // La respuesta del asistente vuelve ENTERA (con su razonamiento) y los resultados de TODAS
            // las herramientas de la ronda van juntos en un solo mensaje.
            $mensajes[] = ['role' => 'assistant', 'content' => $respuesta->content];
            $mensajes[] = ['role' => 'user', 'content' => $resultados];
            if (presupuestoAgotadoChatbot($pdo)) {
                return 'Se agotó el presupuesto del asistente en mitad de esta consulta. Un administrador tiene que revisar CHATBOT_PRESUPUESTO_USD.';
            }
            $respuesta = $llamar($mensajes);
        }

        if ($respuesta->stopReason === 'tool_use') {
            return 'La consulta necesitó demasiados pasos. Probá preguntando algo más puntual.';
        }
        if ($respuesta->stopReason === 'refusal') {
            error_log('Chatbot: la consulta fue rechazada por el clasificador de seguridad');
            return 'No pude responder esa consulta. Probá reformulándola.';
        }
        $texto = '';
        foreach ($respuesta->content as $bloque) {
            if (($bloque->type ?? '') === 'text') {
                $texto .= $bloque->text;
            }
        }
        $texto = trim($texto);
        if ($texto === '') {
            return $respuesta->stopReason === 'max_tokens' ? 'La respuesta salió demasiado larga. Probá pidiendo algo más puntual.' : 'No recibí respuesta del modelo.';
        }
        return $texto;

    } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
        error_log('Chatbot HTTP ' . $e->getCode() . ': ' . $e->getMessage());
        $tipo = $e->type?->value ?? '';
        return match ($tipo) {
            'rate_limit_error'     => 'El asistente está recibiendo muchas consultas. Esperá unos segundos y volvé a intentar.',
            'authentication_error' => 'La clave de Claude no es válida. Un administrador tiene que revisarla en la configuración del asistente.',
            'permission_error'     => 'La clave de Claude no tiene permiso para este modelo. Un administrador tiene que revisarla.',
            'overloaded_error'     => 'El servicio de IA está saturado en este momento. Esperá un minuto y volvé a preguntar.',
            'billing_error'        => 'La cuenta de Claude no tiene saldo. Un administrador tiene que cargarlo en console.anthropic.com.',
            default                => 'El servicio de IA respondió con un error. Un administrador puede ver el detalle en el registro de errores.',
        };
    } catch (\Throwable $e) {
        error_log('Chatbot: ' . $e->getMessage());
        return 'No se pudo conectar con el servicio de IA. Revisá la conexión a internet e intentá de nuevo.';
    }
}
