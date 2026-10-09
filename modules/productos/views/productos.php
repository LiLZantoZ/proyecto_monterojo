<?php
// modules/productos/views/productos.php
// ADMINISTRAR PRODUCTOS (2026-10-06): cada producto de la bodega (SKU, lote, vencimiento, estado)
// con su posición, el semáforo de vencimiento y su Kardex. Ver model_productos.php.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_productos.php';

requierePermiso('modulo_productos', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$puedeEditar = tienePermiso('productos_editar');
$verPosiciones = tienePermiso('modulo_posiciones');

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mil = fn($n) => number_format((float) $n, 0, ',', '.');

$filtros = [
    'buscar'    => trim($_GET['buscar'] ?? ''),
    'estado'    => in_array($_GET['estado'] ?? '', PRODUCTOS_ESTADOS, true) ? $_GET['estado'] : '',
    'ubicacion' => in_array($_GET['ubicacion'] ?? '', ['ubicado', 'sin_ubicar'], true) ? $_GET['ubicacion'] : '',
    'semaforo'  => isset(PRODUCTOS_SEMAFORO[$_GET['semaforo'] ?? '']) ? $_GET['semaforo'] : '',
];
$resultado = paginaProductos($pdo, $filtros, $_GET['pagina'] ?? 1);
$resumen   = resumenProductos($pdo, $filtros);
$filas     = $resultado['filas'];
$pagina    = $resultado['pagina'];
$totalPaginas = $resultado['total_paginas'];
$totalFilas   = $resultado['total'];
$desdeFila    = $totalFilas === 0 ? 0 : ($pagina - 1) * PRODUCTOS_POR_PAGINA + 1;
$hastaFila    = min($pagina * PRODUCTOS_POR_PAGINA, $totalFilas);
$catalogo     = $puedeEditar ? catalogoSkuProductos($pdo) : [];

$limpio   = fn(array $p) => array_filter($p, fn($v) => $v !== '');
$url      = fn(array $p) => BASE_URL . '/productos' . (($q = http_build_query($limpio($p))) !== '' ? '?' . $q : '');
$urlSem   = fn($s) => $url(['semaforo' => $s] + $filtros);
$urlPagina = fn($p) => $url($filtros + ['pagina' => $p > 1 ? $p : '']);
$volver   = http_build_query($limpio($filtros + ['pagina' => $pagina > 1 ? $pagina : '']));
$hayFiltro = (bool) $limpio($filtros);
$claseEstado = ['Disponible' => 'estado-verde', 'Bloqueado' => 'estado-gris', 'En Control De Calidad' => 'estado-azul', 'Defectuoso' => 'estado-rojo'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar productos · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Administrar productos</h2>
                <div class="modulo-acciones">
                    <a class="btn" href="<?php echo BASE_URL; ?>/productos/acciones?accion=exportar">
                        <i class="fa-solid fa-file-excel"></i> Exportar Excel
                    </a>
                    <?php if (tienePermiso('modulo_maestro')): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/maestro" title="El catálogo de SKU es el Maestro de productos">
                            <i class="fa-solid fa-list-check"></i> Ver SKU (maestro)
                        </a>
                    <?php endif; ?>
                    <?php if ($puedeEditar): ?>
                        <button type="button" class="btn btn-primario" data-abrir="modal-producto" data-modo="crear">
                            <i class="fa-solid fa-plus"></i> Registrar producto
                        </button>
                    <?php endif; ?>
                </div>
            </header>

            <!-- EL SEMÁFORO DE VENCIMIENTO: cuenta y filtra. -->
            <div class="pastillas-bodega">
                <a class="pastilla-bodega<?php echo $filtros['semaforo'] === '' ? ' activa' : ''; ?>" href="<?php echo $esc($urlSem('')); ?>">
                    Todos <strong><?php echo $mil($resumen['total']); ?></strong></a>
                <?php foreach (PRODUCTOS_SEMAFORO as $clave => [$texto, , $clase]): ?>
                    <a class="pastilla-bodega <?php echo $clase . ($filtros['semaforo'] === $clave ? ' activa' : ''); ?>" href="<?php echo $esc($urlSem($clave)); ?>">
                        <span class="punto-semaforo"></span><?php echo $esc($texto); ?> <strong><?php echo $mil($resumen[$clave]); ?></strong></a>
                <?php endforeach; ?>
                <span class="pastilla-bodega pastilla-dato">En posiciones <strong><?php echo $mil($resumen['ubicados']); ?></strong></span>
                <span class="pastilla-bodega pastilla-dato">Sin ubicar <strong><?php echo $mil($resumen['sin_ubicar']); ?></strong></span>
            </div>

            <form method="GET" class="filtros">
                <?php if ($filtros['semaforo'] !== ''): ?>
                    <input type="hidden" name="semaforo" value="<?php echo $esc($filtros['semaforo']); ?>">
                <?php endif; ?>
                <div class="filtro">
                    <label for="f-estado">Estado</label>
                    <select name="estado" id="f-estado" data-aplica-solo>
                        <option value="">Todos</option>
                        <?php foreach (PRODUCTOS_ESTADOS as $e): ?>
                            <option value="<?php echo $esc($e); ?>" <?php echo $filtros['estado'] === $e ? 'selected' : ''; ?>><?php echo $esc($e); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-ubicacion">Posición</label>
                    <select name="ubicacion" id="f-ubicacion" data-aplica-solo>
                        <option value="">Todas</option>
                        <option value="ubicado" <?php echo $filtros['ubicacion'] === 'ubicado' ? 'selected' : ''; ?>>En una posición</option>
                        <option value="sin_ubicar" <?php echo $filtros['ubicacion'] === 'sin_ubicar' ? 'selected' : ''; ?>>Sin ubicar</option>
                    </select>
                </div>
                <div class="filtro" style="flex: 1;">
                    <label for="f-buscar">Buscar</label>
                    <input type="text" name="buscar" id="f-buscar" value="<?php echo $esc($filtros['buscar']); ?>"
                           placeholder="SKU, producto, lote o posición… Para varios: 36305, R1M1">
                </div>
                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                <?php if ($hayFiltro): ?>
                    <a class="btn" href="<?php echo BASE_URL; ?>/productos">Limpiar</a>
                <?php endif; ?>
            </form>

            <?php if (!$filas): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div><?php echo $hayFiltro ? 'No hay productos con esos filtros.' : 'Todavía no hay productos. Se registran acá o al ubicar una estiba en Posiciones.'; ?></div>
                </div>
            <?php else: ?>
                <div class="tabla-caja" style="margin-top: 14px;">
                    <table class="tabla tabla-seguimiento tabla-bodega">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Producto</th>
                                <th>Lote</th>
                                <th>Vence</th>
                                <th>Estado</th>
                                <th>Posición</th>
                                <th>Cajas</th>
                                <th>Kardex</th>
                                <?php if ($puedeEditar): ?><th>Acciones</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filas as $p):
                                $sem = semaforoVencimiento($p['dias_restantes']);
                                $dias = $p['dias_restantes'] === null ? null : (int) $p['dias_restantes'];
                            ?>
                                <tr>
                                    <td><strong><?php echo $esc($p['sku']); ?></strong></td>
                                    <td><?php echo $p['producto'] !== null ? $esc($p['producto']) : '<span class="dato-faltante">Sin descripción en el maestro</span>'; ?></td>
                                    <td><?php echo $esc($p['lote']); ?></td>
                                    <td>
                                        <?php if ($sem): ?>
                                            <span class="chip-semaforo <?php echo PRODUCTOS_SEMAFORO[$sem][2]; ?>" title="<?php echo $esc(PRODUCTOS_SEMAFORO[$sem][0]); ?>">
                                                <span class="punto-semaforo"></span><?php echo date('d/m/Y', strtotime($p['fecha_vencimiento'])); ?>
                                            </span>
                                            <span class="sub-dato"><?php echo $dias < 0 ? 'Venció hace ' . $mil(-$dias) . ' día(s)' : ($dias === 0 ? 'Vence hoy' : 'Faltan ' . $mil($dias) . ' día(s)'); ?></span>
                                        <?php else: ?>
                                            <span class="dato-faltante">Sin fecha</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="chip-estado <?php echo $claseEstado[$p['estado']] ?? 'estado-gris'; ?>"><?php echo $esc($p['estado']); ?></span></td>
                                    <td>
                                        <?php if ($p['ubicacion']): ?>
                                            <?php if ($verPosiciones): ?>
                                                <a class="enlace-posicion" href="<?php echo $esc(BASE_URL . '/posiciones?buscar=' . urlencode($p['ubicacion'])); ?>"><?php echo $esc($p['ubicacion']); ?></a>
                                            <?php else: ?>
                                                <?php echo $esc($p['ubicacion']); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="dato-faltante">Sin ubicar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $p['id_estiba'] ? ($p['estiba_completa'] ? 'Estiba completa' : ($p['cantidad_cajas'] ? $mil($p['cantidad_cajas']) : '—')) : '—'; ?></td>
                                    <td>
                                        <button type="button" class="btn btn-chico" data-kardex="<?php echo $esc($p['sku']); ?>" data-producto="<?php echo $esc($p['producto']); ?>" title="Entradas y salidas de este SKU">
                                            <i class="fa-solid fa-clock-rotate-left"></i> Kardex
                                        </button>
                                    </td>
                                    <?php if ($puedeEditar): ?>
                                        <td class="celda-acciones">
                                            <button type="button" class="btn btn-chico" data-abrir="modal-producto" data-modo="editar"
                                                    data-producto-datos="<?php echo $esc(json_encode(['id' => (int) $p['id'], 'sku' => $p['sku'], 'lote' => $p['lote'],
                                                        'fecha_vencimiento' => $p['fecha_vencimiento'], 'estado' => $p['estado'], 'ubicacion' => $p['ubicacion']], JSON_UNESCAPED_UNICODE)); ?>"
                                                    title="Editar"><i class="fa-solid fa-pen"></i></button>
                                            <?php if (!$p['id_estiba']): ?>
                                                <form method="POST" action="<?php echo BASE_URL; ?>/productos/acciones" class="form-en-linea" data-confirmar="¿Eliminar el producto SKU <?php echo $esc($p['sku']); ?>, lote <?php echo $esc($p['lote']); ?>?">
                                                    <?php campoCSRF(); ?>
                                                    <input type="hidden" name="accion" value="eliminar">
                                                    <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                                    <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
                                                    <button type="submit" class="btn btn-chico btn-peligro" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $total = $totalFilas; $etiqueta = 'productos';
                include ROOT_PATH . '/modules/inicio/layout/paginacion.php';
                ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if ($puedeEditar): ?>
<!-- REGISTRAR / EDITAR UN PRODUCTO -->
<div class="modal-fondo" id="modal-producto">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-box"></i> <span data-campo="titulo">Registrar producto</span></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form method="POST" action="<?php echo BASE_URL; ?>/productos/acciones" id="form-producto">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
            <div class="modal-cuerpo">
                <div class="campos-dos">
                    <div class="campo campo-ancho">
                        <label for="p-sku"><i class="fa-solid fa-barcode"></i> SKU</label>
                        <input type="text" id="p-sku" name="sku" list="lista-skus-productos" required autocomplete="off" inputmode="numeric" placeholder="Ej: 36305">
                        <span class="ayuda" data-campo="producto">Tiene que estar en el maestro de productos o en la lista de Monterojo.</span>
                    </div>
                    <div class="campo">
                        <label for="p-lote"><i class="fa-solid fa-hashtag"></i> Lote</label>
                        <input type="text" id="p-lote" name="lote" maxlength="60" required>
                    </div>
                    <div class="campo">
                        <label for="p-vence"><i class="fa-solid fa-calendar-day"></i> Fecha de vencimiento</label>
                        <input type="date" id="p-vence" name="fecha_vencimiento">
                    </div>
                    <div class="campo campo-ancho">
                        <label for="p-estado"><i class="fa-solid fa-circle-half-stroke"></i> Estado</label>
                        <select id="p-estado" name="estado">
                            <?php foreach (PRODUCTOS_ESTADOS as $e): ?>
                                <option value="<?php echo $esc($e); ?>"><?php echo $esc($e); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="ayuda" data-campo="aviso-posicion" hidden></span>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cerrar</button>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>
<datalist id="lista-skus-productos">
    <?php foreach ($catalogo as $sku => $texto): ?>
        <option value="<?php echo $esc($sku); ?>"><?php echo $esc($texto ?? ''); ?></option>
    <?php endforeach; ?>
</datalist>
<?php endif; ?>

<!-- KARDEX DE UN SKU: entradas, salidas y llevadas a picking de sus estibas. -->
<div class="modal-fondo" id="modal-kardex">
    <div class="modal-caja modal-caja-ancha">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-clock-rotate-left"></i> Kardex · <span data-campo="sku"></span></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <div class="modal-cuerpo">
            <p class="kardex-producto" data-campo="producto"></p>
            <div class="filtros kardex-filtros">
                <div class="filtro"><label for="k-desde">Desde</label><input type="date" id="k-desde"></div>
                <div class="filtro"><label for="k-hasta">Hasta</label><input type="date" id="k-hasta"></div>
            </div>
            <div class="tabla-caja kardex-tabla">
                <table class="tabla tabla-seguimiento">
                    <thead><tr><th>Fecha</th><th>Movimiento</th><th>Posición</th><th>Lote</th><th>Vence</th><th>Cantidad</th><th>Usuario</th></tr></thead>
                    <tbody data-campo="filas"></tbody>
                </table>
            </div>
        </div>
        <div class="modal-pie"><button type="button" class="btn" data-cerrar>Cerrar</button></div>
    </div>
</div>

<script>
    window.PRODUCTOS = { urlKardex: <?php echo json_encode(BASE_URL . '/productos/acciones?accion=kardex'); ?> };
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/productos/layouts/scripts_productos.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/productos/layouts/scripts_productos.js'); ?>"></script>
</body>
</html>
