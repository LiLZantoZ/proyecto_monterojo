<?php
// config/chatbot_config.example.php
// PLANTILLA de la configuración del asistente (MonteBot). Este archivo SÍ va al repositorio y por eso
// NUNCA lleva una clave real: solo el texto de relleno.
//
// Para encender el asistente:
//   1. Copiar este archivo como config/chatbot_config.php (en Laravel: storage/app/chatbot_config.php).
//      Ese archivo está en .gitignore: la clave no viaja nunca al repositorio.
//   2. Poner la clave de la API de Claude en CHATBOT_ANTHROPIC_API_KEY, o mejor, dejar el relleno y
//      definir la variable de entorno ANTHROPIC_API_KEY (y reiniciar Apache).
//      La clave se saca de https://console.anthropic.com (empieza por sk-ant-). Es un producto
//      distinto de la suscripción Claude Pro/Max: se paga por uso, desde la primera consulta.
//   3. Comprobarlo con:  php scripts/probar_chatbot.php
//
// (2026-10-07) Traído del asistente del sistema de Nutrium (NutriBot), solo con el proveedor Claude.

// El modelo. Precios por millón de tokens (referencia de septiembre de 2026, confirmar en la consola):
//     claude-opus-5-5     $4 entrada / $20 salida    el más capaz (el de fábrica)
//     claude-sonnet-5-5   $2 / $10                   equilibrio
//     claude-haiku-4-5    $1 / $5                    el más barato (el que usa Nutrium)
define('CHATBOT_MODELO', 'claude-opus-5-5');

// ▼▼▼ LA CLAVE (reemplazá el texto entre comillas, o usá la variable de entorno) ▼▼▼
define('CHATBOT_ANTHROPIC_API_KEY', 'PEGA-AQUI-TU-CLAVE-DE-CLAUDE');

// Solo si la API responde 400 pidiendo "anthropic-workspace-id" (claves vinculadas a una persona).
// El id está en console.anthropic.com → Settings → Workspaces (wrkspc_...). Si no hace falta, dejalo así.
define('CHATBOT_ANTHROPIC_WORKSPACE_ID', 'PEGA-AQUI-EL-ID-DEL-WORKSPACE');

// TOPE DE GASTO en dólares. Cada llamada se anota en chatbot_uso y el asistente se frena al llegar acá,
// ANTES de llamar. No es un límite duro (no ve lo que se gaste con la misma clave desde otro lado):
// el freno de verdad es el límite de gasto de console.anthropic.com → Settings → Billing.
// 0 desactiva el control (no recomendado). Para ver lo gastado:  php scripts/gasto_chatbot.php
define('CHATBOT_PRESUPUESTO_USD', 5.00);

// Cuántos mensajes anteriores de la conversación se le mandan al modelo como contexto.
define('CHATBOT_LIMITE_HISTORIAL', 8);
