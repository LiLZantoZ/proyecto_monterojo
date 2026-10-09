<?php
// modules/chatbot/herramientas_chatbot.php
// Las CONSULTAS que el asistente puede hacer (2026-10-07). Todas son de lectura: lo que escribe va por
// el menú de acciones (acciones_chatbot.php), que no pasa por la IA.
//
// Cada herramienta pide el MISMO permiso que su pantalla: el asistente es una puerta más a los mismos
// datos. El permiso se usa dos veces, como en Nutrium:
//   1. herramientasParaClaudeChatbot() no le ofrece al modelo lo que la persona no puede ver (además
//      recorta tokens: menos herramientas, menos costo por llamada);
//   2. ejecutarHerramientaChatbot() lo vuelve a comprobar antes de ejecutar, por si el modelo se
//      inventa un nombre. Ocultar no es control de acceso.
//
// Las consultas usan las funciones de cada módulo (listaConsolidadoMr, lineasPedidosConDisponibilidad,
// paginaProductos…): el asistente cuenta exactamente lo mismo que la pantalla.

require_once __DIR__ . '/../../config/permisos.php';

/** Quién está usando el asistente: la sesión de Laravel o la del sistema anterior. */
function idUsuarioChatbot() {
    return function_exists('auth') ? auth()->id() : ($_SESSION['usuario_id'] ?? null);
}

// [nombre => [permiso (null = cualquiera con sesión), descripción, propiedades, obligatorias]]
function catalogoHerramientasChatbot() {
    $texto = fn($d) => ['type' => 'string', 'description' => $d];
    $entero = fn($d) => ['type' => 'integer', 'description' => $d];
    return [
        'buscar_factura' => ['modulo_consolidado_mr',
            'Una factura del Consolidado MR por su número (referencia NU… o factura de SAP): cliente, pedido, orden de compra, valor, guía, transportador, estado de entrega, si está anulada o es de contado y quién la empacó.',
            ['numero' => $texto('Número de la factura, ej. NU04169821 o 7963309278')], ['numero']],
        'listar_facturas' => ['modulo_consolidado_mr',
            'Lista facturas del Consolidado MR con filtros: por estado de entrega, cliente, población, transportador, fechas, de contado o anuladas, o las que no tienen responsable de empaque. Devuelve el total y las primeras.',
            ['grupo' => ['type' => 'string', 'enum' => ['entregado', 'en_camino', 'pendiente', 'otro', 'sin_estado'], 'description' => 'Estado de entrega'],
             'cliente' => $texto('Parte del nombre del cliente'), 'poblacion' => $texto('Ciudad o población'),
             'buscar' => $texto('Texto libre: factura, pedido, orden de compra, SKU…'),
             'transportador' => $texto("Nombre del transportador, o 'sin_asignar'"),
             'desde' => $texto('Fecha de factura desde (AAAA-MM-DD)'), 'hasta' => $texto('Fecha de factura hasta (AAAA-MM-DD)'),
             'contado' => ['type' => 'string', 'enum' => ['si', 'no'], 'description' => 'si = solo las de contado'],
             'anuladas' => ['type' => 'boolean', 'description' => 'true = solo las anuladas'],
             'sin_responsable' => ['type' => 'boolean', 'description' => 'true = solo las que no tienen responsable de empaque']], []],
        'resumen_facturacion' => ['modulo_consolidado_mr',
            'Los números del Consolidado MR: facturas por estado de entrega, de contado, anuladas, sin transportador y sin responsable de empaque.', [], []],
        'consultar_pedidos' => ['modulo_pedidos',
            'Los pedidos de los clientes contra el inventario de Bogotá y Copacabana: qué renglones se pueden despachar completos, parciales o agotados. Filtra por cliente, pedido, material o estado.',
            ['buscar' => $texto('Cliente, número de pedido, documento o material'),
             'estado' => ['type' => 'string', 'enum' => ['completo', 'parcial', 'agotado', 'sin_inventario', 'sin_sede'], 'description' => 'Estado del renglón'],
             'sede' => ['type' => 'string', 'enum' => ['BOGOTA', 'COPACABANA'], 'description' => 'Sede que despacha']], []],
        'consultar_consolidados' => ['modulo_consolidados',
            'Las entregas de los consolidados de las cadenas (Éxito y demás) por CEDI: cuántas hay y cuántas faltan despachar. Filtra por CEDI, orden de compra o punto de venta.',
            ['buscar' => $texto('CEDI, orden de compra o punto de venta')], []],
        'buscar_productos' => ['modulo_productos',
            'Busca en Administrar Productos (lo que hay en bodega) por SKU, nombre, lote o ubicación: lote, vencimiento, estado y posición de cada uno.',
            ['termino' => $texto('SKU, nombre, lote o ubicación')], ['termino']],
        'productos_por_vencer' => ['modulo_productos',
            'Los productos de bodega que vencen dentro de N días (o ya vencidos), con su lote y posición.',
            ['dias' => $entero('Días hacia adelante (por defecto 30)')], []],
        'consultar_kardex' => ['modulo_productos',
            'El Kardex de un SKU: sus últimos ingresos, salidas y llevadas a picking, con fecha, posición, cajas y quién lo hizo.',
            ['sku' => $texto('SKU del producto')], ['sku']],
        'consultar_posicion' => ['modulo_posiciones',
            'Qué hay en una posición de los racks (código tipo R1M1N1A1): producto, lote, vencimiento, cajas y estado.',
            ['codigo' => $texto('Código de la posición, ej. R1M1N1A1')], ['codigo']],
        'resumen_posiciones' => ['modulo_posiciones',
            'La ocupación de la bodega: posiciones libres y ocupadas por estado, en total o de un rack.',
            ['rack' => $entero('Número de rack (1 a 8); vacío = toda la bodega')], []],
        'consultar_conciliador' => ['modulo_formato_conciliador',
            'Los registros del Formato Conciliador (lo que sale de producción) por SKU, lote o fecha, con cuántas estibas están registradas y cuántas ya ubicadas.',
            ['sku' => $texto('SKU'), 'lote' => $texto('Lote'), 'fecha' => $texto('Fecha del registro (AAAA-MM-DD)')], []],
        'buscar_maestro' => ['modulo_maestro',
            'Busca en el maestro de productos por SKU, PLU, EAN o descripción: unidades por caja, presentación, peso y línea.',
            ['termino' => $texto('SKU, PLU, EAN o parte de la descripción')], ['termino']],
        'consultar_personal' => ['modulo_personal',
            'El personal de alistamiento: quién está activo y cuántas entregas pendientes tiene asignadas cada uno.', [], []],
        'consultar_actividad' => ['modulo_trazabilidad',
            'La Trazabilidad: las últimas acciones del sistema, con quién las hizo, cuándo y el resultado. Filtra por texto, módulo o resultado.',
            ['buscar' => $texto('Persona, módulo, acción o detalle'), 'modulo' => $texto('Nombre del módulo, ej. Consolidado MR'),
             'resultado' => ['type' => 'string', 'enum' => ['exito', 'error'], 'description' => 'Solo las que salieron bien o con error']], []],
        'consultar_usuarios' => ['modulo_usuarios',
            'Las cuentas del sistema: nombre, rol, estado y último acceso.', ['buscar' => $texto('Nombre, cédula o rol')], []],
        'mis_notificaciones' => [null,
            'Los avisos sin leer de la campana de la persona que pregunta (y los vencimientos, si su rol los recibe).', [], []],
    ];
}

/** Las herramientas que la persona puede usar, en el formato del SDK (inputSchema en camelCase). */
function herramientasParaClaudeChatbot() {
    $lista = [];
    foreach (catalogoHerramientasChatbot() as $nombre => [$permiso, $descripcion, $propiedades, $obligatorias]) {
        if ($permiso !== null && !tienePermiso($permiso)) {
            continue;
        }
        $esquema = ['type' => 'object', 'properties' => $propiedades ?: new stdClass()];
        if ($obligatorias) {
            $esquema['required'] = $obligatorias;
        }
        $lista[] = ['name' => $nombre, 'description' => $descripcion, 'inputSchema' => $esquema];
    }
    return $lista;
}

/** Ejecuta una herramienta y devuelve su resultado en texto. Nunca lanza. */
function ejecutarHerramientaChatbot($pdo, $nombre, array $p) {
    $catalogo = catalogoHerramientasChatbot();
    if (!isset($catalogo[$nombre])) {
        return "La herramienta «{$nombre}» no existe. Para registrar productos o mover estibas, la persona tiene que usar el menú de acciones (escribir «/»).";
    }
    $permiso = $catalogo[$nombre][0];
    if ($permiso !== null && !tienePermiso($permiso)) {
        return 'Esta persona no tiene permiso para esa consulta. Explicale que su rol no tiene acceso y no lo intentes con otra herramienta.';
    }
    $funcion = 'hChatbot' . str_replace(' ', '', ucwords(str_replace('_', ' ', $nombre)));
    try {
        return $funcion($pdo, $p);
    } catch (Throwable $e) {
        error_log("Chatbot, herramienta {$nombre}: " . $e->getMessage());
        return 'La consulta falló por un error del sistema. Decíselo a la persona y sugerile revisar la pantalla del módulo.';
    }
}

// ---------------------------------------------------------------------------------------------
// Formato
// ---------------------------------------------------------------------------------------------

function fechaChatbot($v, $conHora = false) {
    return $v ? date($conHora ? 'd/m/Y H:i' : 'd/m/Y', strtotime($v)) : '—';
}

function plataChatbot($v) {
    return '$' . number_format((float) $v, 0, ',', '.');
}

function milChatbot($n) {
    return number_format((float) $n, 0, ',', '.');
}

const CHATBOT_GRUPOS_ENTREGA = ['entregado' => 'entregada', 'en_camino' => 'en camino', 'pendiente' => 'pendiente',
                                'otro' => 'con novedad', 'sin_estado' => 'sin estado de la transportadora'];

/** Una factura en una línea (de facturasConEnvioMr / buscarFacturaMr). */
function lineaFacturaChatbot(array $f) {
    $e = $f['envio'] ?? [];
    $partes = [($f['referencia'] ?: $f['factura']) . ' (SAP ' . $f['factura'] . ')', $f['nombre_cliente'] ?: 'sin cliente',
               fechaChatbot($f['fecha_factura']), plataChatbot($f['valor_neto'])];
    $partes[] = 'entrega: ' . (CHATBOT_GRUPOS_ENTREGA[$e['grupo'] ?? 'sin_estado'] ?? 'sin estado') . (!empty($e['estado']) ? " ({$e['estado']})" : '');
    if (!empty($e['transportadora'])) { $partes[] = 'transportador ' . $e['transportadora']; }
    if (!empty($e['guia'])) { $partes[] = 'guía ' . $e['guia']; }
    if (!empty($f['anulada'])) { $partes[] = 'ANULADA'; }
    if (!empty($f['contado']) && (int) $f['contado']['de_contado'] === 1) { $partes[] = 'de contado'; }
    $partes[] = !empty($f['responsable']) ? 'empacó ' . $f['responsable']['nombre'] : 'sin responsable de empaque';
    return '- ' . implode(' · ', $partes);
}

// ---------------------------------------------------------------------------------------------
// Las herramientas
// ---------------------------------------------------------------------------------------------

function hChatbotBuscarFactura($pdo, array $p) {
    require_once __DIR__ . '/../consolidado_mr/model_consolidado_mr.php';
    $f = buscarFacturaMr($pdo, (string) ($p['numero'] ?? ''));
    if (!$f) {
        return 'No hay ninguna factura con ese número en el Consolidado MR cargado.';
    }
    $f['contado'] = contadoMr($pdo)[(string) $f['factura']] ?? null;
    $st = $pdo->prepare("SELECT MAX(anulado) FROM consolidado_mr WHERE factura = ?");
    $st->execute([$f['factura']]);
    $f['anulada'] = (int) $st->fetchColumn();
    $e = $f['envio'];
    return implode("\n", array_filter([
        'Factura ' . ($f['referencia'] ?: '—') . ' (SAP ' . $f['factura'] . ')',
        'Cliente: ' . ($f['nombre_cliente'] ?: '—') . ($f['poblacion'] ? ' · ' . $f['poblacion'] : '') . ' · solicitante ' . ($f['solicitante'] ?: '—'),
        'Fecha: ' . fechaChatbot($f['fecha_factura']) . ' · valor neto ' . plataChatbot($f['valor_neto']) . ' · ' . milChatbot($f['renglones']) . ' renglón(es), ' . milChatbot($f['unidades']) . ' unidades',
        'Pedido: ' . ($f['doc_ventas'] ?: '—') . ' · orden de compra ' . ($f['pedido_cliente'] ?: '—'),
        'Entrega: ' . (CHATBOT_GRUPOS_ENTREGA[$e['grupo']] ?? 'sin estado') . ($e['estado'] ? " ({$e['estado']})" : '')
            . ($e['fecha_entrega'] ? ' el ' . fechaChatbot($e['fecha_entrega']) : '')
            . ' · transportador ' . ($e['transportadora'] ?: 'sin asignar') . ' · guía ' . ($e['guia'] ?: '—'),
        $f['anulada'] ? 'Está ANULADA.' : null,
        !empty($f['contado']) ? ((int) $f['contado']['de_contado'] === 1 ? 'Es de contado.' : 'Estuvo marcada de contado y pasó a No.') : null,
        !empty($f['responsable']) ? 'Empacó: ' . $f['responsable']['nombre'] . ' (cédula ' . $f['responsable']['documento'] . ')'
            . ($f['responsable']['cajas'] ? ' · ' . $f['responsable']['cajas'] . ' cajas' : '') : 'No tiene responsable de empaque enlazado.',
    ]));
}

function hChatbotListarFacturas($pdo, array $p) {
    require_once __DIR__ . '/../consolidado_mr/model_consolidado_mr.php';
    $pestana = !empty($p['anuladas']) ? 'anuladas' : (($p['contado'] ?? '') === 'si' ? 'contado' : 'facturas');
    $filtros = filtrosConsolidadoMr([
        'cliente' => $p['cliente'] ?? '', 'poblacion' => $p['poblacion'] ?? '', 'buscar' => $p['buscar'] ?? '',
        'desde' => $p['desde'] ?? '', 'hasta' => $p['hasta'] ?? '', 'grupo' => $p['grupo'] ?? '',
        'responsable' => !empty($p['sin_responsable']) ? 'sin' : '', 'transportador' => $p['transportador'] ?? '',
        'contado' => ($p['contado'] ?? '') === 'si' ? 'si' : (($p['contado'] ?? '') === 'no' ? 'fuera' : ''),
        'anulada' => !empty($p['anuladas']) ? 'si' : '',
    ]);
    $l = listaConsolidadoMr($pdo, $pestana, $filtros);
    $total = count($l['filtradas']);
    if (!$total) {
        return 'No hay facturas con esos filtros.';
    }
    $valor = array_sum(array_column($l['filtradas'], 'valor_neto'));
    $salida = [milChatbot($total) . ' factura(s), por ' . plataChatbot($valor) . ($total > 25 ? '. Las 25 más recientes:' : ':')];
    foreach (array_slice($l['filtradas'], 0, 25) as $f) {
        $salida[] = lineaFacturaChatbot($f);
    }
    return implode("\n", $salida);
}

function hChatbotResumenFacturacion($pdo, array $p) {
    require_once __DIR__ . '/../consolidado_mr/model_consolidado_mr.php';
    $l = listaConsolidadoMr($pdo, 'facturas', filtrosConsolidadoMr([]));
    $r = $l['resumen'];
    if (!$r['total'] && !$l['conteoAnuladas']['si']) {
        return 'El Consolidado MR está vacío: todavía no se importó ningún archivo.';
    }
    $rango = $pdo->query("SELECT MIN(fecha_factura), MAX(fecha_factura) FROM consolidado_mr")->fetch(PDO::FETCH_NUM);
    return implode("\n", [
        'Consolidado MR (facturas del ' . fechaChatbot($rango[0]) . ' al ' . fechaChatbot($rango[1]) . '):',
        '- ' . milChatbot($r['total']) . ' facturas no anuladas: ' . milChatbot($r['entregado']) . ' entregadas, ' . milChatbot($r['en_camino']) . ' en camino, '
            . milChatbot($r['pendiente']) . ' pendientes, ' . milChatbot($r['otro']) . ' con novedad y ' . milChatbot($r['sin_estado']) . ' sin estado.',
        '- ' . milChatbot($l['conteoContado']['si']) . ' de contado · ' . milChatbot($l['conteoAnuladas']['si']) . ' anuladas.',
        '- ' . milChatbot($l['sinTransportador']) . ' sin transportador · ' . milChatbot(count($l['sinResponsable'])) . ' sin responsable de empaque.',
    ]);
}

function hChatbotConsultarPedidos($pdo, array $p) {
    require_once __DIR__ . '/../pedidos/model_pedidos.php';
    $lineas = lineasPedidosConDisponibilidad($pdo);
    if (!$lineas) {
        return 'No hay pedidos cargados en el módulo Pedidos.';
    }
    $filtros = ['estado' => isset(PEDIDOS_ESTADOS[$p['estado'] ?? '']) ? $p['estado'] : '',
                'sede' => in_array($p['sede'] ?? '', ['BOGOTA', 'COPACABANA'], true) ? $p['sede'] : '',
                'buscar' => trim((string) ($p['buscar'] ?? ''))];
    $filtradas = array_values(array_filter($lineas, fn($l) => cumpleFiltrosPedidos($l, $filtros)));
    $r = resumenEstadosPedidos($filtradas);
    $estados = [];
    foreach (PEDIDOS_ESTADOS as $clave => [$etiqueta]) {
        if ($r[$clave]) { $estados[] = milChatbot($r[$clave]) . ' ' . mb_strtolower($etiqueta); }
    }
    $salida = [milChatbot(count($filtradas)) . ' renglón(es) de ' . milChatbot(count(array_unique(array_column($filtradas, 'documento'))))
               . ' pedido(s)' . ($estados ? ': ' . implode(', ', $estados) : '') . '.'];
    foreach (array_slice($filtradas, 0, 25) as $l) {
        $salida[] = '- Pedido ' . ($l['numero_pedido'] ?: $l['documento']) . ' · ' . ($l['nombre'] ?: $l['solicitante']) . ($l['ciudad'] ? " ({$l['ciudad']})" : '')
            . ' · ' . $l['material'] . ' ' . $l['denominacion'] . ' · pidió ' . milChatbot($l['cantidad'])
            . ($l['asignado'] !== null ? ', alcanza ' . milChatbot($l['asignado']) : '') . ' · ' . (PEDIDOS_ESTADOS[$l['estado']][0] ?? $l['estado'])
            . ($l['sede'] ? ' desde ' . $l['sede'] : '');
    }
    if (count($filtradas) > 25) {
        $salida[] = '(se muestran 25 de ' . milChatbot(count($filtradas)) . ')';
    }
    return implode("\n", $salida);
}

function hChatbotConsultarConsolidados($pdo, array $p) {
    $buscar = trim((string) ($p['buscar'] ?? ''));
    $w = ''; $par = [];
    if ($buscar !== '') {
        $w = 'WHERE (cedi LIKE ? OR orden_compra LIKE ? OR punto_venta LIKE ?)';
        $par = array_fill(0, 3, '%' . $buscar . '%');
    }
    $st = $pdo->prepare("SELECT cedi, COUNT(DISTINCT orden_compra, punto_venta) AS entregas,
                                COUNT(DISTINCT CASE WHEN despachado = 0 THEN CONCAT(orden_compra, '|', punto_venta) END) AS pendientes,
                                SUM(cantidad_total) AS unidades, MIN(fecha_minima_entrega) AS desde, MAX(fecha_maxima_entrega) AS hasta
                           FROM consolidado_lineas {$w} GROUP BY cedi ORDER BY pendientes DESC, cedi");
    $st->execute($par);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$filas) {
        return $buscar !== '' ? 'Ningún consolidado coincide con esa búsqueda.' : 'No hay consolidados cargados.';
    }
    $ultima = $pdo->query("SELECT nombre_archivo, fecha_carga FROM consolidado_cargas ORDER BY fecha_carga DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $salida = $ultima ? ['Último consolidado cargado: ' . $ultima['nombre_archivo'] . ' (' . fechaChatbot($ultima['fecha_carga'], true) . ').'] : [];
    foreach (array_slice($filas, 0, 30) as $f) {
        $salida[] = '- ' . $f['cedi'] . ': ' . milChatbot($f['entregas']) . ' entrega(s), ' . milChatbot($f['pendientes']) . ' sin despachar, '
            . milChatbot($f['unidades']) . ' unidades · entrega entre ' . fechaChatbot($f['desde']) . ' y ' . fechaChatbot($f['hasta']);
    }
    return implode("\n", $salida);
}

function hChatbotBuscarProductos($pdo, array $p) {
    require_once __DIR__ . '/../productos/model_productos.php';
    $r = paginaProductos($pdo, ['buscar' => trim((string) ($p['termino'] ?? ''))], 1, 25);
    if (!$r['total']) {
        return 'No hay productos en bodega que coincidan con «' . ($p['termino'] ?? '') . '».';
    }
    $salida = [milChatbot($r['total']) . ' producto(s)' . ($r['total'] > 25 ? ' (los 25 que vencen antes):' : ':')];
    foreach ($r['filas'] as $f) {
        $salida[] = '- SKU ' . $f['sku'] . ' ' . $f['producto'] . ' · lote ' . ($f['lote'] ?: '—') . ' · vence ' . fechaChatbot($f['fecha_vencimiento'])
            . ($f['dias_restantes'] !== null ? ' (' . ((int) $f['dias_restantes'] < 0 ? 'vencido' : $f['dias_restantes'] . ' días') . ')' : '')
            . ' · ' . $f['estado'] . ' · ' . ($f['ubicacion'] ? 'en ' . $f['ubicacion'] . ($f['estiba_completa'] ? ' (estiba completa)' : ($f['cantidad_cajas'] ? ", {$f['cantidad_cajas']} cajas" : '')) : 'sin ubicar');
    }
    return implode("\n", $salida);
}

function hChatbotProductosPorVencer($pdo, array $p) {
    $dias = max(0, min(365, (int) ($p['dias'] ?? 30) ?: 30));
    $st = $pdo->prepare("SELECT p.sku, p.producto, p.lote, p.fecha_vencimiento, p.estado, DATEDIFF(p.fecha_vencimiento, CURDATE()) AS dias, pos.ubicacion
                           FROM productos p
                           LEFT JOIN posiciones_estibas e ON e.id_producto = p.id
                           LEFT JOIN posiciones pos ON pos.id_posicion = e.id_posicion
                          WHERE p.fecha_vencimiento IS NOT NULL AND DATEDIFF(p.fecha_vencimiento, CURDATE()) <= ?
                          ORDER BY p.fecha_vencimiento, p.sku");
    $st->execute([$dias]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$filas) {
        return "Ningún producto de bodega vence en los próximos {$dias} días.";
    }
    $vencidos = count(array_filter($filas, fn($f) => (int) $f['dias'] < 0));
    $salida = [milChatbot(count($filas)) . " producto(s) vencen en {$dias} días o menos" . ($vencidos ? " ({$vencidos} ya vencidos)" : '') . ':'];
    foreach (array_slice($filas, 0, 30) as $f) {
        $salida[] = '- SKU ' . $f['sku'] . ' ' . $f['producto'] . ' · lote ' . ($f['lote'] ?: '—') . ' · vence ' . fechaChatbot($f['fecha_vencimiento'])
            . ' (' . ((int) $f['dias'] < 0 ? 'vencido hace ' . abs((int) $f['dias']) . ' días' : $f['dias'] . ' días') . ') · ' . ($f['ubicacion'] ?: 'sin ubicar');
    }
    return implode("\n", $salida);
}

function hChatbotConsultarKardex($pdo, array $p) {
    require_once __DIR__ . '/../productos/model_productos.php';
    $sku = trim((string) ($p['sku'] ?? ''));
    $filas = kardexPorSku($pdo, $sku);
    if (!$filas) {
        return "El SKU {$sku} no tiene movimientos en el Kardex.";
    }
    $salida = [milChatbot(count($filas)) . " movimiento(s) del SKU {$sku}" . (count($filas) > 20 ? ' (los 20 más recientes):' : ':')];
    foreach (array_slice($filas, 0, 20) as $m) {
        $salida[] = '- ' . fechaChatbot($m['fecha_hora'], true) . ' · ' . $m['tipo'] . ' · ' . ($m['ubicacion'] ?: '—') . ' · lote ' . ($m['lote'] ?: '—')
            . ' · ' . ($m['estiba_completa'] ? 'estiba completa' : ($m['cantidad_cajas'] ? $m['cantidad_cajas'] . ' cajas' : '—')) . ' · ' . ($m['nombre_usuario'] ?: '—');
    }
    return implode("\n", $salida);
}

function hChatbotConsultarPosicion($pdo, array $p) {
    require_once __DIR__ . '/../posiciones/model_posiciones.php';
    $c = leerCodigoPosicion($p['codigo'] ?? '');
    if (!$c) {
        return 'Ese código no es una posición de la bodega. Los códigos son R(rack 1-8) M(módulo 1-12) N(nivel) y A1/A2/B1/B2, por ejemplo R1M1N1A1.';
    }
    $codigo = codigoPosicion(...$c);
    $st = $pdo->prepare("SELECT id_posicion FROM posiciones WHERE ubicacion = ?");
    $st->execute([$codigo]);
    $id = $st->fetchColumn();
    if (!$id) {
        return "La posición {$codigo} no está creada en el sistema.";
    }
    $pos = posicionPorId($pdo, $id);
    if (!$pos['id_estiba']) {
        return "La posición {$codigo} está libre.";
    }
    return "Posición {$codigo}: SKU {$pos['sku']} {$pos['producto']} · lote " . ($pos['lote'] ?: '—') . ' · vence ' . fechaChatbot($pos['fecha_vencimiento'])
        . ' · ' . ($pos['estiba_completa'] ? 'estiba completa' : ($pos['cantidad_cajas'] ? $pos['cantidad_cajas'] . ' cajas' : 'sin cantidad'))
        . ' · estado ' . $pos['estado_producto'] . ' · registrada el ' . fechaChatbot($pos['estiba_creada'], true) . ' por ' . ($pos['registrado_por'] ?: '—')
        . ($pos['observaciones'] ? ' · observaciones: ' . $pos['observaciones'] : '');
}

function hChatbotResumenPosiciones($pdo, array $p) {
    require_once __DIR__ . '/../posiciones/model_posiciones.php';
    $rack = (int) ($p['rack'] ?? 0);
    $r = estadisticasPosiciones($pdo, $rack ? ['rack' => $rack] : []);
    if (!$r['total']) {
        return 'No hay posiciones creadas' . ($rack ? " en el rack {$rack}" : '') . '.';
    }
    $estados = [];
    foreach (estadosGrillaPosiciones() as $slug => $etiqueta) {
        if ($r['por_estado'][$slug]) { $estados[] = milChatbot($r['por_estado'][$slug]) . ' ' . mb_strtolower($etiqueta); }
    }
    return ($rack ? "Rack {$rack}" : 'Toda la bodega') . ': ' . milChatbot($r['total']) . ' posiciones, ' . milChatbot($r['ocupadas']) . ' ocupadas ('
        . number_format($r['pct_ocupadas'], 1, ',', '.') . '%) y ' . milChatbot($r['libres']) . ' libres.'
        . ($estados ? ' Ocupadas por estado: ' . implode(', ', $estados) . '.' : '')
        . ' ' . milChatbot($r['estibas_completas']) . ' estibas completas y ' . milChatbot($r['cajas']) . ' cajas sueltas.';
}

function hChatbotConsultarConciliador($pdo, array $p) {
    require_once __DIR__ . '/../formato_conciliador/model_formato_conciliador.php';
    $r = paginaConciliador($pdo, ['sku' => trim((string) ($p['sku'] ?? '')), 'lote' => trim((string) ($p['lote'] ?? '')), 'fecha' => trim((string) ($p['fecha'] ?? ''))], 1, 20);
    if (!$r['total']) {
        return 'No hay registros del Formato Conciliador con esos datos.';
    }
    $salida = [milChatbot($r['total']) . ' registro(s)' . ($r['total'] > 20 ? ' (los 20 más nuevos):' : ':')];
    foreach ($r['filas'] as $f) {
        $salida[] = '- #' . $f['id'] . ' ' . fechaChatbot($f['fecha']) . ' · SKU ' . $f['sku'] . ' ' . $f['descripcion'] . ' · lote ' . ($f['lote'] ?: '—')
            . ' · vence ' . fechaChatbot($f['fecha_vencimiento']) . ' · ' . milChatbot($f['cajas']) . ' cajas · turno ' . $f['turno'] . ' · ' . ($f['responsable'] ?: '—')
            . ' · de ese producto hay ' . $f['registradas'] . ' estiba(s) registradas y ' . $f['ubicadas'] . ' ubicadas';
    }
    return implode("\n", $salida);
}

function hChatbotBuscarMaestro($pdo, array $p) {
    $t = trim((string) ($p['termino'] ?? ''));
    if ($t === '') {
        return 'Hace falta un SKU, PLU, EAN o parte de la descripción.';
    }
    $st = $pdo->prepare("SELECT sku, ean, plu, descripcion, unidades_por_caja, presentacion, peso_unidad_kg, linea FROM maestro_productos
                          WHERE sku = ? OR plu = ? OR ean = ? OR descripcion LIKE ? ORDER BY (sku = ?) DESC, descripcion LIMIT 25");
    $st->execute([$t, $t, $t, '%' . $t . '%', $t]);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$filas) {
        return "No hay productos en el maestro que coincidan con «{$t}».";
    }
    return implode("\n", array_map(fn($f) => '- SKU ' . $f['sku'] . ' · ' . $f['descripcion'] . ' · PLU ' . ($f['plu'] ?: '—') . ' · EAN ' . ($f['ean'] ?: '—')
        . ' · ' . ($f['unidades_por_caja'] ? $f['unidades_por_caja'] . ' unidades por caja' : 'sin unidades por caja')
        . ($f['presentacion'] ? ' · ' . $f['presentacion'] : '') . ($f['linea'] ? ' · línea ' . $f['linea'] : ''), $filas));
}

function hChatbotConsultarPersonal($pdo, array $p) {
    require_once __DIR__ . '/../personal/model_personal.php';
    $personal = listarPersonal($pdo);
    if (!$personal) {
        return 'No hay personal de alistamiento cargado.';
    }
    return implode("\n", array_map(fn($x) => '- ' . $x['nombre'] . ($x['cargo'] ? " ({$x['cargo']})" : '') . ' · ' . $x['estado']
        . ' · ' . (int) $x['entregas_asignadas'] . ' entrega(s) pendiente(s) asignada(s)', $personal));
}

function hChatbotConsultarActividad($pdo, array $p) {
    require_once __DIR__ . '/../trazabilidad/model_trazabilidad.php';
    $r = paginaTrazabilidad($pdo, ['buscar' => trim((string) ($p['buscar'] ?? '')), 'modulo' => trim((string) ($p['modulo'] ?? '')),
                                   'resultado' => $p['resultado'] ?? ''], 1, 20);
    if (!$r['total']) {
        return 'No hay acciones registradas con esos filtros.';
    }
    $salida = [milChatbot($r['total']) . ' acción(es); las 20 más recientes:'];
    foreach ($r['filas'] as $a) {
        $salida[] = '- ' . fechaChatbot($a['fecha_hora'], true) . ' · ' . $a['nombre_usuario'] . ' · ' . $a['modulo'] . ': ' . $a['accion']
            . ($a['resultado'] === 'error' ? ' (CON ERROR)' : '') . ($a['detalle'] ? ' — ' . mb_substr($a['detalle'], 0, 160) : '');
    }
    return implode("\n", $salida);
}

function hChatbotConsultarUsuarios($pdo, array $p) {
    require_once __DIR__ . '/../usuarios/model_usuarios.php';
    $r = paginaUsuarios($pdo, (string) ($p['buscar'] ?? ''), 1);
    if (!$r['total']) {
        return 'No hay cuentas que coincidan.';
    }
    return implode("\n", array_map(fn($u) => '- ' . $u['nombre_usuario'] . ' · ' . ($u['nombre_rol'] ?: 'sin rol') . ' · ' . $u['estado']
        . ' · último acceso ' . fechaChatbot($u['fecha_ultimo_acceso'], true), $r['filas']));
}

function hChatbotMisNotificaciones($pdo, array $p) {
    require_once __DIR__ . '/../notificaciones/model_notificaciones.php';
    $d = datosCampanaNotificaciones($pdo, (int) idUsuarioChatbot(), tienePermiso('notificaciones_vencimiento'));
    if (!$d['total']) {
        return 'No tiene avisos pendientes en la campana.';
    }
    $salida = [];
    foreach ($d['personales'] as $n) {
        $salida[] = '- Aviso del ' . $n['fecha'] . ($n['emisor'] ? ' de ' . $n['emisor'] : '') . ': ' . $n['mensaje'];
    }
    foreach (array_slice($d['vencimientos'], 0, 15) as $v) {
        $salida[] = '- Vencimiento: SKU ' . $v['sku'] . ' ' . $v['producto'] . ' · lote ' . ($v['lote'] ?: '—') . ' · vence ' . $v['vence']
            . ' (' . ($v['dias'] < 0 ? 'vencido' : $v['dias'] . ' días') . ') · ' . ($v['ubicacion'] ?: 'sin ubicar');
    }
    return implode("\n", $salida);
}
