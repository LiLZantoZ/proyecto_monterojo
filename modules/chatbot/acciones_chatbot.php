<?php
// modules/chatbot/acciones_chatbot.php
// El MENÚ DE ACCIONES del asistente (2026-10-07, como en Nutrium): lo que ESCRIBE en la base.
//
// No pasa por la IA. La persona escribe "/" en el chat, elige una acción y llena un formulario; PHP
// valida y ejecuta con las MISMAS funciones de la pantalla del módulo (crearProducto,
// agregarEstibaPosicion, sacarEstibaPosicion), así quedan el Kardex, los avisos y las reglas iguales.
// En Nutrium esto lo hacía el modelo y llegó a decir "listo" sobre algo que había fallado: con un
// formulario no hay nada que adivinar y el resultado que se muestra es el real.
//
// Cada campo se declara una sola vez: de acá salen el formulario que dibuja el widget y la validación.

require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/herramientas_chatbot.php';
require_once __DIR__ . '/../productos/model_productos.php';

function catalogoAccionesChatbot() {
    $estados = PRODUCTOS_ESTADOS;
    return [
        'crear_producto' => [
            'permiso' => 'productos_editar',
            'etiqueta' => 'Registrar producto',
            'descripcion' => 'Da de alta un producto en Administrar Productos (sin ubicar).',
            'campos' => [
                'sku'               => ['etiqueta' => 'SKU', 'tipo' => 'texto', 'obligatorio' => true],
                'lote'              => ['etiqueta' => 'Lote', 'tipo' => 'texto', 'obligatorio' => true],
                'fecha_vencimiento' => ['etiqueta' => 'Fecha de vencimiento', 'tipo' => 'fecha', 'obligatorio' => false],
                'estado'            => ['etiqueta' => 'Estado', 'tipo' => 'lista', 'obligatorio' => true, 'opciones' => $estados],
            ],
        ],
        'ubicar_estiba' => [
            'permiso' => 'posiciones_mover_estibas',
            'etiqueta' => 'Ubicar estiba en una posición',
            'descripcion' => 'Pone una estiba en una posición libre de los racks (queda en el Kardex como ingreso).',
            'campos' => [
                'codigo'            => ['etiqueta' => 'Posición (ej. R1M1N1A1)', 'tipo' => 'texto', 'obligatorio' => true],
                'sku'               => ['etiqueta' => 'SKU', 'tipo' => 'texto', 'obligatorio' => true],
                'lote'              => ['etiqueta' => 'Lote', 'tipo' => 'texto', 'obligatorio' => true],
                'fecha_vencimiento' => ['etiqueta' => 'Fecha de vencimiento', 'tipo' => 'fecha', 'obligatorio' => true],
                'estado'            => ['etiqueta' => 'Estado', 'tipo' => 'lista', 'obligatorio' => true, 'opciones' => $estados],
                'estiba_completa'   => ['etiqueta' => '¿Estiba completa?', 'tipo' => 'lista', 'obligatorio' => true, 'opciones' => ['Sí', 'No']],
                'cantidad_cajas'    => ['etiqueta' => 'Cajas (si no es completa)', 'tipo' => 'numero', 'obligatorio' => false],
                'observaciones'     => ['etiqueta' => 'Observaciones', 'tipo' => 'textarea', 'obligatorio' => false],
            ],
        ],
        'sacar_estiba' => [
            'permiso' => 'posiciones_mover_estibas',
            'etiqueta' => 'Sacar estiba de una posición',
            'descripcion' => 'Libera una posición. El producto sigue en Administrar Productos, sin ubicar (queda en el Kardex como salida).',
            'campos' => [
                'codigo' => ['etiqueta' => 'Posición (ej. R1M1N1A1)', 'tipo' => 'texto', 'obligatorio' => true],
            ],
        ],
    ];
}

/** Las acciones que la persona puede ejecutar, sin el permiso (es lo que se manda al navegador). */
function accionesDisponiblesChatbot() {
    $lista = [];
    foreach (catalogoAccionesChatbot() as $nombre => $a) {
        if (tienePermiso($a['permiso'])) {
            unset($a['permiso']);
            $lista[$nombre] = $a;
        }
    }
    return $lista;
}

/** La posición por su código, o un mensaje de error. */
function posicionDeCodigoChatbot($pdo, $texto) {
    require_once __DIR__ . '/../posiciones/model_posiciones.php';
    $c = leerCodigoPosicion($texto);
    if (!$c) {
        return 'Ese código no es una posición de la bodega (ej. R1M1N1A1).';
    }
    $st = $pdo->prepare("SELECT id_posicion FROM posiciones WHERE ubicacion = ?");
    $st->execute([codigoPosicion(...$c)]);
    return (int) $st->fetchColumn() ?: 'La posición ' . codigoPosicion(...$c) . ' no está creada en el sistema.';
}

/** Ejecuta una acción: ['exito', 'mensaje']. Revisa el permiso y los obligatorios contra el catálogo. */
function ejecutarAccionChatbot($pdo, $nombre, array $datos) {
    $catalogo = catalogoAccionesChatbot();
    if (!isset($catalogo[$nombre])) {
        return ['exito' => false, 'mensaje' => 'Esa acción no existe.'];
    }
    $accion = $catalogo[$nombre];
    if (!tienePermiso($accion['permiso'])) {
        return ['exito' => false, 'mensaje' => 'Tu rol no tiene permiso para hacer eso.'];
    }
    $d = [];
    foreach ($accion['campos'] as $campo => $def) {
        $valor = trim((string) ($datos[$campo] ?? ''));
        if ($def['obligatorio'] && $valor === '') {
            return ['exito' => false, 'mensaje' => "Falta «{$def['etiqueta']}»."];
        }
        if ($def['tipo'] === 'lista' && $valor !== '' && !in_array($valor, $def['opciones'], true)) {
            return ['exito' => false, 'mensaje' => "El valor de «{$def['etiqueta']}» no es válido."];
        }
        $d[$campo] = $valor;
    }
    $idUsuario = idUsuarioChatbot();

    switch ($nombre) {
        case 'crear_producto':
            $r = crearProducto($pdo, $d, $idUsuario);
            if ($r['exito']) {
                require_once __DIR__ . '/../notificaciones/model_notificaciones.php';
                if ($avisados = notificarProductoNuevo($pdo, $idUsuario, $r['datos'])) {
                    $r['mensaje'] .= " Se le avisó a {$avisados} persona(s).";
                }
            }
            return ['exito' => $r['exito'], 'mensaje' => $r['mensaje']];

        case 'ubicar_estiba':
            $id = posicionDeCodigoChatbot($pdo, $d['codigo']);
            if (is_string($id)) {
                return ['exito' => false, 'mensaje' => $id];
            }
            $d['estiba_completa'] = $d['estiba_completa'] === 'Sí' ? 1 : 0;
            $r = agregarEstibaPosicion($pdo, $id, $d, $idUsuario);
            return ['exito' => $r['exito'], 'mensaje' => $r['mensaje']];

        case 'sacar_estiba':
            $id = posicionDeCodigoChatbot($pdo, $d['codigo']);
            if (is_string($id)) {
                return ['exito' => false, 'mensaje' => $id];
            }
            $r = sacarEstibaPosicion($pdo, $id, $idUsuario);
            return ['exito' => $r['exito'], 'mensaje' => $r['mensaje']];
    }
    return ['exito' => false, 'mensaje' => 'Esa acción no existe.'];
}
