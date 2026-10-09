<?php
// scripts/gasto_chatbot.php
// Cuánto lleva gastado el asistente (MonteBot), contra el presupuesto: por mes, por modelo y por persona.
//
//   C:\xampp\php\php.exe scripts\gasto_chatbot.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta desde la línea de comandos.');
}

if (is_file(__DIR__ . '/../artisan')) {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    require_once app_path('Servicios/chatbot/model_chatbot.php');
    $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
} else {
    require_once __DIR__ . '/../modules/chatbot/model_chatbot.php';
}

$usd = fn($v) => 'US$ ' . number_format((float) $v, 4);
$total = gastoAcumuladoChatbot($pdo);
echo "Gasto del asistente: {$usd($total)} de US$ " . number_format(CHATBOT_PRESUPUESTO_USD, 2)
   . (CHATBOT_PRESUPUESTO_USD > 0 ? ' (' . number_format($total / CHATBOT_PRESUPUESTO_USD * 100, 1) . '%)' : ' (sin tope)') . "\n\n";

echo "Por mes:\n";
foreach ($pdo->query("SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, COUNT(*) AS llamadas, SUM(costo_usd) AS costo FROM chatbot_uso GROUP BY mes ORDER BY mes") as $f) {
    echo "  {$f['mes']}  " . str_pad($f['llamadas'], 6, ' ', STR_PAD_LEFT) . " llamadas  {$usd($f['costo'])}\n";
}
echo "\nPor modelo:\n";
foreach ($pdo->query("SELECT modelo, COUNT(*) AS llamadas, SUM(tokens_entrada) AS e, SUM(tokens_salida) AS s, SUM(costo_usd) AS costo FROM chatbot_uso GROUP BY modelo") as $f) {
    echo "  {$f['modelo']}: {$f['llamadas']} llamadas, " . number_format($f['e']) . ' tokens de entrada, ' . number_format($f['s']) . " de salida, {$usd($f['costo'])}\n";
}
echo "\nPor persona:\n";
foreach ($pdo->query("SELECT COALESCE(u.nombre_usuario, '(pruebas / cuenta eliminada)') AS quien, COUNT(*) AS llamadas, SUM(c.costo_usd) AS costo
                        FROM chatbot_uso c LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario GROUP BY quien ORDER BY costo DESC") as $f) {
    echo "  {$f['quien']}: {$f['llamadas']} llamadas, {$usd($f['costo'])}\n";
}
