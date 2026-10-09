<?php
// scripts/probar_chatbot.php
// Comprueba que el asistente (MonteBot) puede hablar con Claude: que la configuración exista, que haya
// clave, que las tablas estén y que una pregunta mínima reciba respuesta. La pregunta de prueba cuesta
// una fracción de centavo y queda anotada en chatbot_uso como cualquier otra.
//
//   C:\xampp\php\php.exe scripts\probar_chatbot.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta desde la línea de comandos.');
}

$laravel = is_file(__DIR__ . '/../artisan');
if ($laravel) {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    require_once app_path('Servicios/chatbot/model_chatbot.php');
    $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
} else {
    require_once __DIR__ . '/../modules/chatbot/model_chatbot.php';
}

$ok = fn($t) => print("  ✓ {$t}\n");
$mal = function ($t) { print("  ✗ {$t}\n"); exit(1); };

echo "Asistente MonteBot\n";
is_file(rutaConfigChatbot()) ? $ok('Configuración: ' . rutaConfigChatbot()) : $mal('Falta ' . rutaConfigChatbot() . ' (copiá config/chatbot_config.example.php con ese nombre).');
claveChatbot() !== '' ? $ok('Hay clave de Claude (' . substr(claveChatbot(), 0, 10) . '…)') : $mal('Falta la clave: CHATBOT_ANTHROPIC_API_KEY en la configuración o la variable de entorno ANTHROPIC_API_KEY.');
try {
    $pdo->query("SELECT 1 FROM chatbot_mensajes LIMIT 1");
    $pdo->query("SELECT 1 FROM chatbot_uso LIMIT 1");
    $ok('Tablas chatbot_mensajes y chatbot_uso');
} catch (PDOException $e) {
    $mal('Faltan las tablas: corré scripts/crear_esquema_completo.php');
}
$ok('Modelo: ' . CHATBOT_MODELO . ' · presupuesto US$ ' . number_format(CHATBOT_PRESUPUESTO_USD, 2) . ' (gastado US$ ' . number_format(gastoAcumuladoChatbot($pdo), 4) . ')');

try {
    $r = clienteChatbot(claveChatbot())->beta->messages->create(
        model: CHATBOT_MODELO,
        maxTokens: 2000,
        messages: [['role' => 'user', 'content' => 'Respondé solo con la palabra: listo']],
        outputConfig: ['effort' => 'low'],
        fallbacks: 'default',
        betas: ['server-side-fallback-2026-07-01'],
    );
    anotarUsoChatbot($pdo, null, $r->model, $r->usage);
    $texto = '';
    foreach ($r->content as $b) {
        if (($b->type ?? '') === 'text') { $texto .= $b->text; }
    }
    $ok('Claude respondió: «' . trim($texto) . '» (' . $r->model . ', US$ ' . number_format(costoLlamadaChatbot($r->model, $r->usage), 5) . ')');
} catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
    $mal('La API respondió ' . $e->getCode() . ' (' . ($e->type?->value ?? '?') . '): ' . $e->getMessage());
} catch (\Throwable $e) {
    $mal('No se pudo conectar: ' . $e->getMessage());
}
echo "Todo en orden.\n";
