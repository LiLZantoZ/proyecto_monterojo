<?php
// modules/pedidos/views/pedidos.php
// PEDIDOS (2026-10-06): cada renglón de los pedidos de los clientes con la ciudad, la sede que lo
// atiende y lo que hay en esa bodega para despacharlo. Es la hoja C1 del Excel "PEDIDOS MONTEROJO".
// Ver model_pedidos.php.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_pedidos.php';

requierePermiso('modulo_pedidos', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// Subir los archivos solo con este permiso; el resto solo consulta.
$puedeEditar = tienePermiso('pedidos_editar');

$esc      = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mil      = fn($n) => number_format((float) $n, 0, ',', '.');
$plata    = fn($v) => '$' . number_format((float) $v, 2, ',', '.');
$faltante = '<span class="dato-faltante">—</span>';
$celda    = fn($v) => ($v === null || $v === '') ? $faltante : $esc($v);
$fecha    = function ($v) use ($faltante) {
    $t = $v ? strtotime($v) : false;
    return $t ? date('d/m/Y', $t) : $faltante;
};
$cantidad = fn($v) => $v === null ? '<span class="dato-faltante">—</span>' : number_format((float) $v, (float) $v == floor((float) $v) ? 0 : 2, ',', '.');

$filtros = [
    'estado' => array_key_exists($_GET['estado'] ?? '', PEDIDOS_ESTADOS) ? $_GET['estado'] : '',
    'sede'   => in_array($_GET['sede'] ?? '', array_merge(array_keys(PEDIDOS_SEDES), ['sin']), true) ? $_GET['sede'] : '',
    'buscar' => trim($_GET['buscar'] ?? ''),
    // La vista "Por producto" (2026-10-06): las hojas MANDATO e INVENTARIO del Excel. 'producto' filtra
    // por su estado (falta, alcanza, sin_inventario).
    'vista'    => ($_GET['vista'] ?? '') === 'productos' ? 'productos' : '',
    'producto' => array_key_exists($_GET['producto'] ?? '', PEDIDOS_ESTADOS_PRODUCTO) ? $_GET['producto'] : '',
];
$porProducto = $filtros['vista'] === 'productos';

// TODOS los renglones con su disponibilidad (el reparto se hace sobre todos); después se filtra.
$todas   = lineasPedidosConDisponibilidad($pdo);
$resumen = resumenEstadosPedidos(array_filter($todas, fn($l) => cumpleFiltrosPedidos($l, ['estado' => $l['estado']] + $filtros)));
$resumen['monterojo'] = $resumen['total'];   // solo se guardan los de Monterojo
$lineas  = array_values(array_filter($todas, fn($l) => cumpleFiltrosPedidos($l, $filtros)));
$cargas  = cargasPedidos($pdo);
// QUÉ INVENTARIO HAY QUE SUBIR (2026-10-06): según la zona de los clientes de los pedidos cargados.
$recomendacion   = recomendacionInventariosPedidos($todas, $cargas);
$sedeRecomendada = sedeRecomendadaPedidos($recomendacion);
// Sin la base (BD Clientes y CIUDADES) no se sabe la ciudad ni la sede: todo sale "Sin sede" y #N/A.
$faltaBase = !isset($cargas['base']);
if ($porProducto) {
    // Los productos de los renglones Monterojo que pasan la sede y la búsqueda (el estado del renglón no cuenta).
    $productos = productosPedidos($pdo, array_filter($todas, fn($l) => cumpleFiltrosPedidos($l, ['estado' => ''] + $filtros)));
    $resumenProductos = ['total' => count($productos)] + array_fill_keys(array_keys(PEDIDOS_ESTADOS_PRODUCTO), 0);
    foreach ($productos as $p) {
        $resumenProductos[$p['estado']]++;
    }
    if ($filtros['producto'] !== '') {
        $productos = array_values(array_filter($productos, fn($p) => $p['estado'] === $filtros['producto']));
    }
}
unset($todas);

$listaDeLaVista = $porProducto ? $productos : $lineas;
$totalFilas   = count($listaDeLaVista);
$totalPaginas = max(1, (int) ceil($totalFilas / PEDIDOS_POR_PAGINA));
$pagina       = min(max(1, (int) ($_GET['pagina'] ?? 1)), $totalPaginas);
$pedidosDeLaVista = count(array_unique(array_column($lineas, 'documento')));
$valorDeLaVista   = array_sum(array_column($lineas, 'total'));
$filas        = array_slice($listaDeLaVista, ($pagina - 1) * PEDIDOS_POR_PAGINA, PEDIDOS_POR_PAGINA);
$unidad       = $porProducto ? 'productos' : 'renglones';
$desdeFila    = $totalFilas === 0 ? 0 : ($pagina - 1) * PEDIDOS_POR_PAGINA + 1;
$hastaFila    = min($pagina * PEDIDOS_POR_PAGINA, $totalFilas);

$urlPedidos = fn(array $p) => BASE_URL . '/pedidos' . (($q = http_build_query(array_filter($p))) !== '' ? '?' . $q : '');
$urlEstado  = fn($e) => $urlPedidos(['estado' => $e] + $filtros);
$urlVista   = fn($v) => $urlPedidos(['vista' => $v, 'producto' => '', 'estado' => ''] + $filtros);
$urlProducto = fn($e) => $urlPedidos(['producto' => $e] + $filtros);
$urlPagina  = fn($p) => $urlPedidos($filtros + ['pagina' => $p]);
$filtrosEnUrl = http_build_query(array_filter($filtros));
$hayPedidos   = isset($cargas['pedidos']);

// Las tarjetas de las cargas: [tipo, título, icono, qué subir].
$tarjetas = [
    ['pedidos', 'Pedidos', 'fa-file-lines', 'Subí el archivo PEDIDOS.'],
    ['inventario_BOGOTA', 'Inventario Bogotá', 'fa-warehouse', 'Subí el archivo INVENTARIO BOGOTA.'],
    ['inventario_COPACABANA', 'Inventario Copacabana', 'fa-warehouse', 'Subí el archivo INVENTARIO COPA.'],
    ['base', 'Clientes y ciudades', 'fa-address-book', 'Vienen en el Excel PEDIDOS MONTEROJO: subilo con “Subir pedidos”.'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/pedidos/layouts/pedidos.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/pedidos/layouts/pedidos.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Pedidos</h2>
                <?php if ($puedeEditar): ?>
                    <div class="modulo-acciones">
                        <button type="button" class="btn" data-abrir="modal-vaciar"<?php echo $cargas ? '' : ' disabled title="No hay nada cargado"'; ?>>
                            <i class="fa-solid fa-trash-can"></i> Vaciar tabla
                        </button>
                        <button type="button" class="btn" data-abrir="modal-inventario" data-sede="<?php echo $esc($sedeRecomendada ?? ''); ?>">
                            <i class="fa-solid fa-warehouse"></i> Subir inventario
                        </button>
                        <button type="button" class="btn btn-primario" data-abrir="modal-pedidos">
                            <i class="fa-solid fa-file-arrow-up"></i> Subir pedidos
                        </button>
                    </div>
                <?php endif; ?>
            </header>

            <!-- LAS CARGAS: qué archivo se subió por última vez, y cuándo. -->
            <div class="cargas-pedidos">
                <?php foreach ($tarjetas as [$tipo, $titulo, $icono, $falta]): $c = $cargas[$tipo] ?? null; ?>
                    <div class="carga-pedidos<?php echo $c ? '' : ' carga-falta'; ?>">
                        <i class="fa-solid <?php echo $icono; ?>"></i>
                        <div>
                            <strong><?php echo $titulo; ?></strong>
                            <?php if ($c): ?>
                                <span><?php echo $esc($c['detalle']); ?></span>
                                <small title="<?php echo $esc($c['archivo']); ?>"><?php echo $esc(date('d/m/Y H:i', strtotime($c['fecha']))); ?><?php echo $c['usuario'] ? ' · ' . $esc($c['usuario']) : ''; ?></small>
                            <?php else: ?>
                                <span>Sin cargar. <?php echo $falta; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($hayPedidos && $faltaBase): ?>
                <!-- SIN LA BASE no hay ciudad ni sede: es lo primero que hay que resolver. -->
                <div class="aviso aviso-peligro aviso-falta-base">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong>Faltan los clientes y las ciudades.</strong> Sin las hojas <strong>BD Clientes</strong> y <strong>CIUDADES</strong>
                        no se sabe a qué ciudad va cada pedido ni qué sede lo atiende: por eso todo sale <strong>“Sin sede”</strong> y
                        <strong>#N/A</strong>, como en el Excel cuando falla el BUSCARV.
                        <?php if ($puedeEditar): ?>Subí el Excel <strong>PEDIDOS MONTEROJO completo</strong> con “Subir pedidos” (el archivo
                        PEDIDOS solo no las trae). Se hace una vez: después alcanza con el archivo de pedidos.<?php endif; ?>
                    </div>
                </div>
            <?php elseif ($hayPedidos): ?>
                <!-- QUÉ INVENTARIO HAY QUE SUBIR: según la zona (ciudad → sede) de los clientes de los pedidos. -->
                <div class="recomendacion-pedidos">
                    <div class="recomendacion-titulo"><i class="fa-solid fa-lightbulb"></i> Inventarios que necesitan estos pedidos</div>
                    <div class="recomendacion-sedes">
                        <?php foreach ($recomendacion as $sede => $r): $nombreSede = PEDIDOS_SEDES[$sede]; ?>
                            <div class="recomendacion-sede rec-<?php echo $r['estado']; ?>">
                                <?php if ($r['estado'] === 'falta'): ?>
                                    <i class="fa-solid fa-circle-exclamation"></i>
                                    <div><strong>Inventario <?php echo $nombreSede; ?>: falta subirlo.</strong>
                                        Lo necesitan <?php echo $mil($r['renglones']); ?> renglón(es) de <?php echo $mil($r['pedidos']); ?> pedido(s).</div>
                                <?php elseif ($r['estado'] === 'viejo'): ?>
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                    <div><strong>Inventario <?php echo $nombreSede; ?>: conviene actualizarlo.</strong>
                                        Es del <?php echo $esc(date('d/m/Y', strtotime($r['fecha']))); ?> y lo necesitan <?php echo $mil($r['renglones']); ?> renglón(es) de <?php echo $mil($r['pedidos']); ?> pedido(s).</div>
                                <?php elseif ($r['estado'] === 'al_dia'): ?>
                                    <i class="fa-solid fa-circle-check"></i>
                                    <div><strong>Inventario <?php echo $nombreSede; ?>: al día.</strong>
                                        Lo usan <?php echo $mil($r['renglones']); ?> renglón(es) de <?php echo $mil($r['pedidos']); ?> pedido(s).</div>
                                <?php else: ?>
                                    <i class="fa-solid fa-circle-minus"></i>
                                    <div><strong>Inventario <?php echo $nombreSede; ?>: no hace falta.</strong>
                                        Ningún pedido de Monterojo es de su zona.</div>
                                <?php endif; ?>
                                <?php if ($puedeEditar && in_array($r['estado'], ['falta', 'viejo'], true)): ?>
                                    <button type="button" class="btn btn-chico btn-primario" data-abrir="modal-inventario" data-sede="<?php echo $sede; ?>">
                                        <i class="fa-solid fa-file-arrow-up"></i> Subir
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$hayPedidos): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div>
                        Todavía no hay pedidos cargados. <?php if ($puedeEditar): ?>Subí el Excel <strong>PEDIDOS MONTEROJO</strong> con “Subir pedidos”:
                        trae los pedidos, los clientes y las ciudades. Después el sistema te dice qué <strong>inventario</strong> (Bogotá o Copacabana) hace falta subir.<?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- LAS DOS VISTAS: renglón por renglón (la hoja C1) o por producto (MANDATO + INVENTARIO). -->
                <nav class="pestanas-seg" aria-label="Vistas de Pedidos">
                    <a class="pestana-seg<?php echo $porProducto ? '' : ' activa'; ?>" href="<?php echo $esc($urlVista('')); ?>">
                        <i class="fa-solid fa-list"></i> <span>Renglones</span>
                    </a>
                    <a class="pestana-seg<?php echo $porProducto ? ' activa' : ''; ?>" href="<?php echo $esc($urlVista('productos')); ?>"
                       title="Lo pedido de cada producto contra la libre utilización de la sede (las hojas MANDATO e INVENTARIO del Excel)">
                        <i class="fa-solid fa-boxes-stacked"></i> <span>Por producto</span>
                    </a>
                </nav>

                <?php if ($porProducto): ?>
                    <!-- Los estados de los productos: si lo pedido alcanza con lo que hay en la sede. -->
                    <div class="pastillas pastillas-filtro">
                        <a class="pastilla<?php echo $filtros['producto'] === '' ? ' activa' : ''; ?>" href="<?php echo $esc($urlProducto('')); ?>">Productos <strong><?php echo $mil($resumenProductos['total']); ?></strong></a>
                        <?php foreach (PEDIDOS_ESTADOS_PRODUCTO as $clave => [$texto, $color]): ?>
                            <?php if ($resumenProductos[$clave] > 0 || $filtros['producto'] === $clave || $clave !== 'sin_inventario'): ?>
                                <a class="pastilla pastilla-estado <?php echo $color . ($filtros['producto'] === $clave ? ' activa' : ''); ?>" href="<?php echo $esc($urlProducto($clave)); ?>">
                                    <?php echo $texto; ?> <strong><?php echo $mil($resumenProductos[$clave]); ?></strong>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                <!-- LOS ESTADOS: filtran la tabla. "Monterojo" = todos los renglones de Monterojo. -->
                <div class="pastillas pastillas-filtro">
                    <a class="pastilla<?php echo $filtros['estado'] === '' ? ' activa' : ''; ?>" href="<?php echo $esc($urlEstado('')); ?>"
                       title="Todos los renglones de productos Monterojo">Monterojo <strong><?php echo $mil($resumen['monterojo']); ?></strong></a>
                    <?php foreach (PEDIDOS_ESTADOS as $clave => [$texto, $color]): ?>
                        <?php if ($resumen[$clave] > 0 || $filtros['estado'] === $clave || in_array($clave, ['completo', 'parcial', 'agotado'], true)): ?>
                            <a class="pastilla pastilla-estado <?php echo $color . ($filtros['estado'] === $clave ? ' activa' : ''); ?>" href="<?php echo $esc($urlEstado($clave)); ?>">
                                <?php echo $texto; ?> <strong><?php echo $mil($resumen[$clave]); ?></strong>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="pastillas">
                    <span class="pastilla">Pedidos <strong><?php echo $mil($pedidosDeLaVista); ?></strong></span>
                    <span class="pastilla">Valor <strong><?php echo $esc($plata($valorDeLaVista)); ?></strong></span>
                </div>
                <?php endif; ?>

                <form method="GET" class="filtros">
                    <?php foreach (['estado', 'vista', 'producto'] as $oculto): ?>
                        <?php if ($filtros[$oculto] !== ''): ?>
                            <input type="hidden" name="<?php echo $oculto; ?>" value="<?php echo $esc($filtros[$oculto]); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="filtro">
                        <label for="f-sede">Sede</label>
                        <select name="sede" id="f-sede">
                            <option value="">Todas</option>
                            <?php foreach (PEDIDOS_SEDES as $codigo => $nombre): ?>
                                <option value="<?php echo $codigo; ?>" <?php echo $filtros['sede'] === $codigo ? 'selected' : ''; ?>><?php echo $nombre; ?></option>
                            <?php endforeach; ?>
                            <option value="sin" <?php echo $filtros['sede'] === 'sin' ? 'selected' : ''; ?>>Sin sede</option>
                        </select>
                    </div>
                    <div class="filtro" style="flex: 1;">
                        <label for="f-buscar">Buscar</label>
                        <input type="text" name="buscar" id="f-buscar" value="<?php echo $esc($filtros['buscar']); ?>"
                               placeholder="Pedido, documento, cliente, material, producto, ciudad… Para varios: 2599052483, 36384">
                    </div>
                    <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                    <?php if ($filtrosEnUrl !== ''): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/pedidos">Limpiar</a>
                    <?php endif; ?>
                </form>

                <?php if (!$filas): ?>
                    <div class="aviso aviso-atencion" style="margin-top: 14px;">
                        <i class="fa-solid fa-inbox"></i>
                        <div>No hay <?php echo $unidad; ?> con esos filtros. Probá con otros o limpialos.</div>
                    </div>
                <?php elseif ($porProducto): ?>
                    <!-- POR PRODUCTO: como las hojas MANDATO (lo pedido) e INVENTARIO (DIFERENCIA = libre − pedido). -->
                    <div class="tabla-caja" style="margin-top: 14px;">
                        <table class="tabla tabla-seguimiento tabla-sap tabla-productos-pedidos">
                            <thead>
                                <tr>
                                    <th>Sede</th>
                                    <th>Material</th>
                                    <th>Producto</th>
                                    <th class="num">Libre utilización</th>
                                    <th class="num">Pedido</th>
                                    <th class="num">Diferencia</th>
                                    <th class="centro">Estado</th>
                                    <th class="num">Pedidos</th>
                                    <th class="num">Clientes</th>
                                    <th class="num">Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filas as $p): [$textoEstado, $colorEstado] = PEDIDOS_ESTADOS_PRODUCTO[$p['estado']]; ?>
                                    <tr>
                                        <td><span class="chip-estado estado-azul"><?php echo $esc($p['sede']); ?></span></td>
                                        <td><strong><?php echo $esc($p['material']); ?></strong></td>
                                        <td class="celda-producto"><?php echo $celda($p['texto']); ?></td>
                                        <td class="num"><?php echo $cantidad($p['libre']); ?></td>
                                        <td class="num"><strong><?php echo $cantidad($p['pedido']); ?></strong></td>
                                        <td class="num<?php echo ($p['diferencia'] ?? 0) < 0 ? ' celda-falta' : ''; ?>"><strong><?php echo $cantidad($p['diferencia']); ?></strong></td>
                                        <td class="centro">
                                            <span class="chip-estado <?php echo $colorEstado; ?>"
                                                  <?php if ($p['estado'] === 'falta'): ?>title="Faltan <?php echo $esc($cantidad(-$p['diferencia'])); ?> para despachar todo lo pedido"<?php endif; ?>
                                                  <?php if ($p['estado'] === 'sin_inventario'): ?>title="Falta subir el inventario de <?php echo $esc(PEDIDOS_SEDES[$p['sede']] ?? $p['sede']); ?>"<?php endif; ?>><?php echo $textoEstado; ?></span>
                                        </td>
                                        <td class="num"><?php echo $mil($p['pedidos']); ?></td>
                                        <td class="num"><?php echo $mil($p['n_clientes']); ?></td>
                                        <td class="num"><?php echo $esc($plata($p['valor'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="tabla-caja" style="margin-top: 14px;">
                        <table class="tabla tabla-seguimiento tabla-sap tabla-pedidos">
                            <thead>
                                <tr>
                                    <th>Nº de pedido</th>
                                    <th class="num">Fecha documento</th>
                                    <th>Clase doc. ventas</th>
                                    <th>Documento comercial</th>
                                    <th>Creado por</th>
                                    <th>Solicitante</th>
                                    <th>Nombre 1</th>
                                    <th>Moneda</th>
                                    <th>Material</th>
                                    <th>Denominación</th>
                                    <th class="num">Cantidad de pedido</th>
                                    <?php // Lo que hay en la bodega de la sede y lo que le alcanza a este renglón. ?>
                                    <th class="num col-dispo">Inventario sede</th>
                                    <th class="num col-dispo">Disponible</th>
                                    <th class="centro col-dispo">Estado</th>
                                    <th class="num">Precio neto</th>
                                    <th class="num">Total</th>
                                    <th>Motivo de rechazo</th>
                                    <th class="num">Creado el</th>
                                    <th class="num">Hora</th>
                                    <th>Observaciones</th>
                                    <th>Condición de pago</th>
                                    <th>Ciudad</th>
                                    <th>Monterojo</th>
                                    <th>Atendido</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filas as $l): [$textoEstado, $colorEstado] = PEDIDOS_ESTADOS[$l['estado']]; ?>
                                    <tr>
                                        <td><?php echo $celda($l['numero_pedido']); ?></td>
                                        <td class="num"><?php echo $fecha($l['fecha_documento']); ?></td>
                                        <td><?php echo $celda($l['clase_doc']); ?></td>
                                        <td><strong><?php echo $celda($l['documento']); ?></strong></td>
                                        <td><?php echo $celda($l['creado_por']); ?></td>
                                        <td><?php echo $celda($l['solicitante']); ?></td>
                                        <td class="celda-cliente"><?php echo $celda($l['nombre']); ?></td>
                                        <td><?php echo $celda($l['moneda']); ?></td>
                                        <td><?php echo $celda($l['material']); ?></td>
                                        <td class="celda-producto"><?php echo $celda($l['denominacion']); ?></td>
                                        <td class="num"><strong><?php echo $cantidad($l['cantidad']); ?></strong></td>
                                        <td class="num col-dispo"><?php echo $cantidad($l['inventario_sede']); ?></td>
                                        <td class="num col-dispo"
                                            <?php echo isset($l['queda_antes']) && $l['queda_antes'] < ($l['inventario_sede'] ?? 0) ? 'title="De las ' . $esc($cantidad($l['inventario_sede'])) . ' que hay, ya se reservaron para pedidos anteriores: quedaban ' . $esc($cantidad($l['queda_antes'])) . '"' : ''; ?>>
                                            <strong><?php echo $cantidad($l['asignado']); ?></strong>
                                        </td>
                                        <td class="centro col-dispo">
                                            <span class="chip-estado <?php echo $colorEstado; ?>"
                                                  <?php if ($l['estado'] === 'sin_sede'): ?>title="<?php echo $l['sin_cliente'] ? 'El cliente no está en BD Clientes' : 'La ciudad ' . $esc($l['ciudad']) . ' no está en CIUDADES'; ?>"<?php endif; ?>
                                                  <?php if ($l['estado'] === 'sin_inventario'): ?>title="Falta subir el inventario de <?php echo $esc(PEDIDOS_SEDES[$l['sede']] ?? $l['sede']); ?>"<?php endif; ?>><?php echo $textoEstado; ?></span>
                                        </td>
                                        <td class="num"><?php echo $l['precio_neto'] !== null ? $esc($plata($l['precio_neto'])) : $faltante; ?></td>
                                        <td class="num"><?php echo $l['total'] !== null ? $esc($plata($l['total'])) : $faltante; ?></td>
                                        <td><?php echo $celda($l['motivo_rechazo']); ?></td>
                                        <td class="num"><?php echo $fecha($l['creado_el']); ?></td>
                                        <td class="num"><?php echo $l['hora'] ? $esc(substr($l['hora'], 0, 5)) : $faltante; ?></td>
                                        <td><?php echo $celda($l['observaciones'] ?? $l['observacion_cliente']); ?></td>
                                        <td><?php echo $celda($l['condicion_pago']); ?></td>
                                        <td><?php echo $l['ciudad'] !== null ? $esc($l['ciudad']) : '<span class="dato-faltante" title="El cliente no está en BD Clientes">#N/A</span>'; ?></td>
                                        <td><?php echo $l['monterojo'] !== null ? $esc($l['monterojo']) : '<span class="dato-faltante" title="El material no está en la lista de Monterojo">#N/A</span>'; ?></td>
                                        <td>
                                            <?php if ($l['sede'] !== null): ?>
                                                <span class="chip-estado estado-azul" <?php echo $l['sede_por'] === 'observacion' ? 'title="Por la observación del cliente: ' . $esc($l['observacion_cliente']) . '"' : ''; ?>>
                                                    <?php echo $esc($l['sede']); ?><?php echo $l['sede_por'] === 'observacion' ? ' *' : ''; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="dato-faltante">#N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($totalFilas > 0): ?>
                    <!-- PAGINACIÓN: el mismo estilo de Consolidado MR (1 2 3 … 9 … última). -->
                    <div class="paginacion">
                        <span class="paginacion-info">
                            <?php echo $mil($desdeFila); ?>–<?php echo $mil($hastaFila); ?>
                            de <strong><?php echo $mil($totalFilas); ?></strong> <?php echo $unidad; ?>
                        </span>
                        <?php if ($totalPaginas > 1): ?>
                            <?php
                            $ventana = 9;
                            $inicio  = max(1, $pagina - intdiv($ventana, 2));
                            $fin     = min($totalPaginas, $inicio + $ventana - 1);
                            $inicio  = max(1, $fin - $ventana + 1);
                            $conSaltos = [];
                            $prev = 0;
                            foreach (array_unique(array_merge([1], range($inicio, $fin), [$totalPaginas])) as $n) {
                                if ($prev && $n - $prev > 1) { $conSaltos[] = 0; }   // 0 = "…"
                                $conSaltos[] = $n;
                                $prev = $n;
                            }
                            ?>
                            <nav class="paginacion-botones" aria-label="Paginación">
                                <?php if ($pagina > 1): ?>
                                    <a class="pagina-flecha" href="<?php echo $esc($urlPagina($pagina - 1)); ?>" rel="prev" aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></a>
                                <?php else: ?>
                                    <span class="pagina-flecha deshabilitada" aria-hidden="true"><i class="fa-solid fa-chevron-left"></i></span>
                                <?php endif; ?>
                                <?php foreach ($conSaltos as $n): ?>
                                    <?php if ($n === 0): ?>
                                        <span class="pagina-elipsis">…</span>
                                    <?php elseif ($n === $pagina): ?>
                                        <span class="pagina-num activa" aria-current="page"><?php echo $n; ?></span>
                                    <?php else: ?>
                                        <a class="pagina-num" href="<?php echo $esc($urlPagina($n)); ?>"><?php echo $n; ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($pagina < $totalPaginas): ?>
                                    <a class="pagina-flecha" href="<?php echo $esc($urlPagina($pagina + 1)); ?>" rel="next" aria-label="Página siguiente"><i class="fa-solid fa-chevron-right"></i></a>
                                <?php else: ?>
                                    <span class="pagina-flecha deshabilitada" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if ($puedeEditar): ?>
<!-- SUBIR LOS PEDIDOS (con los clientes y las ciudades, 2026-10-06) -->
<div class="modal-fondo" id="modal-pedidos">
    <div class="modal-caja modal-con-panel">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-file-arrow-up"></i> Subir pedidos</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/pedidos/acciones" method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="subir_pedidos">
            <div class="modal-panel-grid">
                <aside class="modal-panel-lado">
                    <div class="panel-icono"><i class="fa-solid fa-file-lines"></i></div>
                    <h3>El Excel PEDIDOS MONTEROJO</h3>
                    <p>Con los pedidos y las hojas de clientes y ciudades, todo en el mismo archivo.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> Los <strong>pedidos</strong> salen de la hoja que los trae (C1): documento, solicitante, material y cantidad.</li>
                        <li><i class="fa-solid fa-check"></i> De <strong>BD Clientes</strong>, <strong>CIUDADES</strong>, <strong>OBSERVACIONES</strong> e <strong>INVENTARIO</strong> salen la ciudad, la sede y qué es Monterojo.</li>
                        <li><i class="fa-solid fa-check"></i> Solo se toman los productos de <strong>Monterojo</strong> (la pestaña INVENTARIO): el resto no se guarda.</li>
                        <li><i class="fa-solid fa-check"></i> También sirve el archivo PEDIDOS solo: se usan los clientes y ciudades que ya estaban cargados.</li>
                        <li><i class="fa-solid fa-check"></i> <strong>Reemplaza</strong> los pedidos que había. Al terminar te dice qué <strong>inventario</strong> subir.</li>
                    </ul>
                </aside>
                <div class="modal-panel-form">
                    <div class="campo">
                        <label for="p-archivo"><i class="fa-solid fa-file-excel"></i> Excel PEDIDOS MONTEROJO o PEDIDOS (.xlsx)</label>
                        <input type="file" id="p-archivo" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">El Excel completo pesa unos 10 MB: tarda unos segundos.</span>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario btn-enviando"><i class="fa-solid fa-file-arrow-up"></i> Subir</button>
            </div>
        </form>
    </div>
</div>

<!-- SUBIR UN INVENTARIO -->
<div class="modal-fondo" id="modal-inventario">
    <div class="modal-caja modal-con-panel">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-warehouse"></i> Subir inventario</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/pedidos/acciones" method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="subir_inventario">
            <div class="modal-panel-grid">
                <aside class="modal-panel-lado">
                    <div class="panel-icono"><i class="fa-solid fa-warehouse"></i></div>
                    <h3>INVENTARIO BOGOTA o INVENTARIO COPA</h3>
                    <p>El inventario de SAP de una sede.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> Se toma la <strong>Libre utilización</strong> de cada material, sumada entre sus lotes.</li>
                        <li><i class="fa-solid fa-check"></i> Solo se guardan los productos de <strong>Monterojo</strong>; los de otras marcas se dejan por fuera.</li>
                        <li><i class="fa-solid fa-check"></i> La sede se reconoce sola por el almacén (0305 Bogotá, 0300 Copacabana).</li>
                        <li><i class="fa-solid fa-check"></i> <strong>Reemplaza</strong> el inventario que tenía esa sede.</li>
                    </ul>
                </aside>
                <div class="modal-panel-form">
                    <div class="campo">
                        <label for="i-archivo"><i class="fa-solid fa-file-excel"></i> Excel del inventario (.xlsx)</label>
                        <input type="file" id="i-archivo" name="archivo" accept=".xlsx,.xls" required>
                    </div>
                    <div class="campo">
                        <label for="i-sede"><i class="fa-solid fa-location-dot"></i> Sede</label>
                        <select id="i-sede" name="sede">
                            <option value="">Reconocerla sola (por el almacén)</option>
                            <?php foreach (PEDIDOS_SEDES as $codigo => $nombre): ?>
                                <option value="<?php echo $codigo; ?>"><?php echo $nombre; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="ayuda">Si elegís una sede y el archivo es de la otra, no se carga.</span>
                        <?php if ($hayPedidos && ($texto = mensajeRecomendacionPedidos($recomendacion)) !== ''): ?>
                            <div class="aviso aviso-info" style="margin-top: 12px;"><i class="fa-solid fa-lightbulb"></i><div><?php echo $esc($texto); ?></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario btn-enviando"><i class="fa-solid fa-file-arrow-up"></i> Subir</button>
            </div>
        </form>
    </div>
</div>

<!-- VACIAR LA TABLA (2026-10-06): se elige qué borrar; por defecto, solo los pedidos. -->
<div class="modal-fondo" id="modal-vaciar">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-trash-can"></i> Vaciar tabla</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/pedidos/acciones" method="POST" data-vaciar data-confirmar="¿Seguro que querés vaciar lo que elegiste? No se puede deshacer." data-titulo="Vaciar tabla" data-aceptar="Sí, vaciar" data-peligro>
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="vaciar">
            <div class="modal-cuerpo">
                <p style="margin-top: 0;">Elegí qué querés borrar. <strong>No se puede deshacer</strong>: para volver a verlo hay que subir el archivo otra vez.</p>
                <div class="vaciar-opciones">
                    <?php foreach (partesVaciablesPedidos() as $clave => [$nombre, , , $tipo]): $c = $cargas[$tipo] ?? null; ?>
                        <label class="vaciar-opcion<?php echo $c ? '' : ' vaciar-vacia'; ?>">
                            <input type="checkbox" name="partes[]" value="<?php echo $clave; ?>"<?php echo $c ? ($clave === 'pedidos' ? ' checked' : '') : ' disabled'; ?>>
                            <span>
                                <strong><?php echo $nombre; ?></strong>
                                <small><?php echo $c ? $esc($c['detalle']) . ' · ' . $esc(date('d/m/Y H:i', strtotime($c['fecha']))) : 'Sin cargar'; ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="aviso aviso-atencion" style="margin-top: 12px;">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>Los clientes y las ciudades vuelven a cargarse solos la próxima vez que subas el Excel PEDIDOS MONTEROJO completo.</div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-peligro btn-enviando" data-enviando="Vaciando…"><i class="fa-solid fa-trash-can"></i> Vaciar</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/pedidos/layouts/scripts_pedidos.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/pedidos/layouts/scripts_pedidos.js'); ?>"></script>
</body>
</html>
