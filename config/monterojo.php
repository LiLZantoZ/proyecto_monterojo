<?php

// config/monterojo.php
// Los ajustes propios del Sistema Monterojo (los que en el sistema anterior eran constantes de
// config/config.php). Se leen del .env con los mismos valores por defecto.

return [
    'nombre_sistema' => 'Sistema Monterojo',

    // Minutos sin actividad antes de cerrar la sesión.
    'inactividad_minutos' => (int) env('MONTEROJO_INACTIVIDAD_MINUTOS', 10),

    // Rótulos: tamaño del sticker y del área dibujada, en milímetros.
    'rotulo' => [
        'ancho_mm'         => 100,
        'alto_mm'          => 100,
        'dibujo_ancho_mm'  => 95,
        'dibujo_alto_mm'   => 95,
        'tspl_direccion'   => 1,
        'tspl_gap_mm'      => 2,
        'tspl_densidad'    => 8,
        'tspl_velocidad'   => 4,
    ],

    'url_publica_rotulos'    => env('URL_PUBLICA_ROTULOS', ''),
    'impresora_rotulos'      => env('IMPRESORA_ROTULOS', 'TSC TA210'),
    'impresion_rotulos_modo' => env('IMPRESION_ROTULOS_MODO', 'remota'),
    'token_agente_impresion' => env('TOKEN_AGENTE_IMPRESION', '97ab80e4c230908fa078e02a5d584699c0a94cf6ed7b9e45'),
];
