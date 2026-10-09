<?php
// modules/posiciones/views/posiciones.php
// POSICIONES DE BODEGA (2026-10-06): la grilla de posiciones del sistema de bodega con los racks
// de Monterojo (R1M1N1A1 = rack, módulo, nivel, posición). Cada tarjeta muestra la estiba que hay
// en esa posición; el color y la marca de agua dicen su estado. Ver model_posiciones.php.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_posiciones.php';

requierePermiso('modulo_posiciones', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$puedeCrear  = tienePermiso('posiciones_crear');
$puedeMover  = tienePermiso('posiciones_mover_estibas');
$puedeEditar = tienePermiso('posiciones_editar_detalle');
$puedePicking = $puedeMover && tienePermiso('posiciones_llevar_a_picking');

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mil = fn($n) => number_format((float) $n, 0, ',', '.');
$pct = fn($n) => number_format((float) $n, 2, ',', '.');

$estadosGrilla = estadosGrillaPosiciones();
$entero = fn($v, $max) => (ctype_digit((string) $v) && (int) $v >= 1 && (int) $v <= $max) ? (string) (int) $v : '';
$filtros = [
    'rack'   => $entero($_GET['rack'] ?? '', POSICIONES_RACKS),
    'modulo' => $entero($_GET['modulo'] ?? '', POSICIONES_MODULOS),
    'nivel'  => $entero($_GET['nivel'] ?? '', POSICIONES_NIVELES),
    'buscar' => trim($_GET['buscar'] ?? ''),
    'estado' => in_array($_GET['estado'] ?? '', array_merge(['libre', 'ocupada'], array_keys($estadosGrilla)), true) ? $_GET['estado'] : '',
];

$resultado    = paginaPosiciones($pdo, $filtros, $_GET['pagina'] ?? 1);
$stats        = estadisticasPosiciones($pdo, $filtros);
$filas        = $resultado['filas'];
$pagina       = $resultado['pagina'];
$totalPaginas = $resultado['total_paginas'];
$totalFilas   = $resultado['total'];
$desdeFila    = $totalFilas === 0 ? 0 : ($pagina - 1) * POSICIONES_POR_PAGINA + 1;
$hastaFila    = min($pagina * POSICIONES_POR_PAGINA, $totalFilas);
$hayFiltro    = $filtros['rack'] !== '' || $filtros['modulo'] !== '' || $filtros['nivel'] !== '' || $filtros['buscar'] !== '';
$catalogo     = ($puedeMover || $puedeEditar) ? catalogoSkuPosiciones($pdo) : [];

$urlPosiciones = fn(array $p) => BASE_URL . '/posiciones' . (($q = http_build_query(array_filter($p, fn($v) => $v !== ''))) !== '' ? '?' . $q : '');
$urlEstado = fn($e) => $urlPosiciones(['estado' => $e] + $filtros);
$urlPagina = fn($p) => $urlPosiciones($filtros + ['pagina' => $p > 1 ? $p : '']);
// Para volver a la misma página y filtros después de una acción.
$volver = http_build_query(array_filter($filtros + ['pagina' => $pagina > 1 ? $pagina : ''], fn($v) => $v !== ''));

// La tarjeta: lo que necesita el modal de detalles, en un atributo.
$datosTarjeta = fn(array $p) => json_encode([
    'id' => (int) $p['id_posicion'], 'ubicacion' => $p['ubicacion'], 'rack' => (int) $p['rack'], 'modulo' => (int) $p['modulo'],
    'nivel' => (int) $p['nivel'], 'lugar' => $p['lugar'], 'tiene' => (bool) $p['id_estiba'], 'sku' => $p['sku'],
    'producto' => $p['producto'], 'cajas' => $p['cantidad_cajas'], 'completa' => (int) $p['estiba_completa'], 'lote' => $p['lote'],
    'vence' => $p['fecha_vencimiento'], 'estado' => $p['estado_elegido'], 'estado_visto' => $p['estado_producto'],
    'observaciones' => $p['observaciones'],
    'registro' => $p['id_estiba'] ? trim(($p['registrado_por'] ? 'Agregada por ' . $p['registrado_por'] : 'Agregada')
        . ' el ' . date('d/m/Y H:i', strtotime($p['estiba_creada']))
        . ($p['estiba_editada'] ? ' · editada' . ($p['editado_por'] ? ' por ' . $p['editado_por'] : '') . ' el ' . date('d/m/Y H:i', strtotime($p['estiba_editada'])) : '')) : '',
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Posiciones de bodega · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/posiciones/layouts/posiciones.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/posiciones/layouts/posiciones.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Posiciones de bodega</h2>
                <div class="modulo-acciones">
                    <a class="btn" href="<?php echo BASE_URL; ?>/posiciones/acciones?accion=exportar">
                        <i class="fa-solid fa-file-excel"></i> Exportar Excel
                    </a>
                    <?php if ($puedeCrear): ?>
                        <button type="button" class="btn" data-abrir="modal-importar-posiciones">
                            <i class="fa-solid fa-file-arrow-up"></i> Importar Excel
                        </button>
                        <button type="button" class="btn btn-primario" data-abrir="modal-crear-posicion">
                            <i class="fa-solid fa-plus"></i> Crear posición
                        </button>
                    <?php endif; ?>
                </div>
            </header>

            <!-- LAS PASTILLAS: cuentan y filtran (como en bodega). Respetan el rack, módulo, nivel y búsqueda. -->
            <div class="stats-posiciones">
                <a class="stat-pill stat-total<?php echo $filtros['estado'] === '' ? ' activo' : ''; ?>" href="<?php echo $esc($urlEstado('')); ?>">
                    Total: <strong><?php echo $mil($stats['total']); ?></strong></a>
                <a class="stat-pill stat-libre<?php echo $filtros['estado'] === 'libre' ? ' activo' : ''; ?>" href="<?php echo $esc($urlEstado('libre')); ?>">
                    Libres: <strong><?php echo $mil($stats['libres']); ?></strong> (<?php echo $pct($stats['pct_libres']); ?>%)</a>
                <a class="stat-pill stat-ocupado<?php echo $filtros['estado'] === 'ocupada' ? ' activo' : ''; ?>" href="<?php echo $esc($urlEstado('ocupada')); ?>">
                    Ocupadas: <strong><?php echo $mil($stats['ocupadas']); ?></strong> (<?php echo $pct($stats['pct_ocupadas']); ?>%)</a>
                <?php foreach ($estadosGrilla as $slug => $etiqueta): ?>
                    <a class="stat-pill stat-estado-<?php echo $slug; ?><?php echo $filtros['estado'] === $slug ? ' activo' : ''; ?>" href="<?php echo $esc($urlEstado($slug)); ?>">
                        <?php echo $esc($etiqueta); ?>: <strong><?php echo $mil($stats['por_estado'][$slug]); ?></strong></a>
                <?php endforeach; ?>
                <?php if ($hayFiltro): ?>
                    <span class="stat-pill stat-cajas" title="Cajas sueltas de las posiciones de este filtro">Cajas: <strong><?php echo $mil($stats['cajas']); ?></strong></span>
                    <span class="stat-pill stat-estibas" title="Estibas completas de las posiciones de este filtro">Estibas completas: <strong><?php echo $mil($stats['estibas_completas']); ?></strong></span>
                <?php endif; ?>
            </div>

            <!-- LOS BUSCADORES: rack, módulo y nivel (en bodega eran pasillo y sección) y la ubicación o el SKU. -->
            <form method="GET" class="filtros filtros-posiciones">
                <?php if ($filtros['estado'] !== ''): ?>
                    <input type="hidden" name="estado" value="<?php echo $esc($filtros['estado']); ?>">
                <?php endif; ?>
                <div class="filtro">
                    <label for="f-rack"><i class="fa-solid fa-warehouse"></i> Rack</label>
                    <select name="rack" id="f-rack">
                        <option value="">Todos</option>
                        <?php for ($i = 1; $i <= POSICIONES_RACKS; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $filtros['rack'] === (string) $i ? 'selected' : ''; ?>>Rack <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-modulo"><i class="fa-solid fa-table-columns"></i> Módulo</label>
                    <select name="modulo" id="f-modulo">
                        <option value="">Todos</option>
                        <?php for ($i = 1; $i <= POSICIONES_MODULOS; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $filtros['modulo'] === (string) $i ? 'selected' : ''; ?>>Módulo <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-nivel"><i class="fa-solid fa-layer-group"></i> Nivel</label>
                    <select name="nivel" id="f-nivel">
                        <option value="">Todos</option>
                        <?php for ($i = 1; $i <= POSICIONES_NIVELES; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $filtros['nivel'] === (string) $i ? 'selected' : ''; ?>>Nivel <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filtro" style="flex: 1;">
                    <label for="f-buscar"><i class="fa-solid fa-magnifying-glass"></i> Buscar</label>
                    <input type="text" name="buscar" id="f-buscar" value="<?php echo $esc($filtros['buscar']); ?>"
                           placeholder="Ubicación, SKU, producto o lote… Para varios: R1M1N1A1, 26370">
                </div>
                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                <?php if ($hayFiltro || $filtros['estado'] !== ''): ?>
                    <a class="btn" href="<?php echo BASE_URL; ?>/posiciones">Limpiar</a>
                <?php endif; ?>
            </form>

            <?php if (!$filas): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div><?php echo $stats['total'] || $hayFiltro ? 'No hay posiciones con esos filtros. Probá con otros o limpialos.' : 'Todavía no hay posiciones creadas.'; ?></div>
                </div>
            <?php else: ?>
                <!-- LA GRILLA: una tarjeta por posición. Clic = ver o cargar su estiba. -->
                <section class="grid-posiciones">
                    <?php foreach ($filas as $p):
                        $tiene   = (bool) $p['id_estiba'];
                        $estado  = $tiene ? (string) $p['estado_producto'] : '';
                        $anormal = $estado !== '' && $estado !== 'Disponible';
                    ?>
                        <article class="card-posicion<?php echo $tiene ? ' ocupada' : ''; ?><?php echo $anormal ? ' estado-' . slugEstadoPosicion($estado) : ''; ?>"
                                 data-posicion="<?php echo $esc($datosTarjeta($p)); ?>" tabindex="0"
                                 title="<?php echo $esc($tiene ? $p['ubicacion'] . ' · ' . ($p['producto'] ?: 'SKU ' . $p['sku']) : $p['ubicacion'] . ' · libre'); ?>">
                            <?php if ($puedeCrear): ?>
                                <button type="button" class="btn-editar-posicion" title="Editar posición"><i class="fa-solid fa-pen-to-square"></i></button>
                            <?php endif; ?>
                            <div class="slots-grid">
                                <div class="slot-box slot-dato"><?php if ($tiene): ?><span class="slot-label">SKU</span><span class="slot-valor"><?php echo $esc($p['sku']); ?></span><?php endif; ?></div>
                                <div class="slot-box slot-dato"><?php if ($tiene): ?><span class="slot-label">Cajas</span><span class="slot-valor"><?php echo $p['estiba_completa'] ? 'Estiba completa' : ($p['cantidad_cajas'] ? $mil($p['cantidad_cajas']) : 'N/A'); ?></span><?php endif; ?></div>
                                <div class="slot-box slot-dato"><?php if ($tiene): ?><span class="slot-label">Lote</span><span class="slot-valor"><?php echo $esc($p['lote'] ?: 'N/A'); ?></span><?php endif; ?></div>
                                <div class="slot-box slot-dato"><?php if ($tiene): ?><span class="slot-label">Vence</span><span class="slot-valor"><?php echo $p['fecha_vencimiento'] ? date('d/m/Y', strtotime($p['fecha_vencimiento'])) : 'N/A'; ?></span><?php endif; ?></div>
                            </div>
                            <?php if ($anormal): ?>
                                <span class="marca-agua-estado" aria-hidden="true"><?php echo $esc($estado); ?></span>
                            <?php endif; ?>
                            <footer class="label-posicion">
                                <?php echo $esc($p['ubicacion']); ?>
                                <span class="label-posicion-pasillo">Rack <?php echo (int) $p['rack']; ?> · Módulo <?php echo (int) $p['modulo']; ?> · Nivel <?php echo (int) $p['nivel']; ?></span>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </section>

                <!-- PAGINACIÓN: el mismo estilo de Consolidado MR (1 2 3 … 9 … última). -->
                <div class="paginacion">
                    <span class="paginacion-info">
                        <?php echo $mil($desdeFila); ?>–<?php echo $mil($hastaFila); ?> de <strong><?php echo $mil($totalFilas); ?></strong> posiciones
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

        </div>
    </div>
</div>

<!-- DETALLES DE LA POSICIÓN: ver, agregar, editar o sacar la estiba. -->
<div class="modal-fondo" id="modal-detalle-posicion">
    <div class="modal-caja modal-detalle-posicion">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-boxes-stacked"></i> Detalles de la posición</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/posiciones/acciones" method="POST" id="form-estiba">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="agregar_estiba">
            <input type="hidden" name="id_posicion" value="">
            <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
            <div class="modal-cuerpo">
                <div class="posicion-encabezado">
                    <div class="posicion-icono"><i class="fa-solid fa-pallet"></i></div>
                    <div>
                        <div class="posicion-codigo" data-campo="ubicacion"></div>
                        <div class="posicion-donde" data-campo="donde"></div>
                        <div class="posicion-registro" data-campo="registro"></div>
                    </div>
                    <span class="chip-estado-posicion" data-campo="estado-visto"></span>
                </div>

                <div class="campos-estiba">
                    <div class="campo campo-ancho">
                        <label for="e-sku"><i class="fa-solid fa-barcode"></i> SKU del producto</label>
                        <input type="text" id="e-sku" name="sku" list="lista-skus-posiciones" autocomplete="off" inputmode="numeric" required placeholder="Ej: 26370">
                        <span class="ayuda" data-campo="producto">El SKU tiene que estar en el maestro de productos o en la lista de Monterojo.</span>
                    </div>
                    <div class="campo">
                        <label for="e-cajas"><i class="fa-solid fa-box"></i> Cantidad de cajas</label>
                        <input type="number" id="e-cajas" name="cantidad_cajas" min="1" max="99999" step="1" placeholder="Cajas">
                    </div>
                    <div class="campo">
                        <label>&nbsp;</label>
                        <label class="campo-check check-estiba"><input type="checkbox" name="estiba_completa" value="1" id="e-completa"> ¿Estiba completa?</label>
                    </div>
                    <div class="campo">
                        <label for="e-lote"><i class="fa-solid fa-hashtag"></i> Lote</label>
                        <input type="text" id="e-lote" name="lote" maxlength="60" required placeholder="Lote del producto">
                    </div>
                    <div class="campo">
                        <label for="e-vence"><i class="fa-solid fa-calendar-day"></i> Fecha de vencimiento</label>
                        <input type="date" id="e-vence" name="fecha_vencimiento" required>
                    </div>
                    <!-- Lo registrado en el Formato Conciliador y lo ubicado, en vivo (solo informa). -->
                    <div class="campo campo-ancho cupo-conciliador" data-campo="cupo" hidden></div>
                    <div class="campo campo-ancho">
                        <label for="e-estado"><i class="fa-solid fa-circle-half-stroke"></i> Estado</label>
                        <select id="e-estado" name="estado">
                            <?php foreach (POSICIONES_ESTADOS as $e): ?>
                                <option value="<?php echo $esc($e); ?>"><?php echo $esc($e); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="ayuda">"Producto vencido" no se elige: sale solo cuando la fecha de vencimiento ya pasó.</span>
                    </div>
                    <div class="campo campo-ancho">
                        <label for="e-obs"><i class="fa-solid fa-comment"></i> Observaciones</label>
                        <textarea id="e-obs" name="observaciones" rows="3" maxlength="500"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cerrar</button>
                <?php if ($puedeMover): ?>
                    <button type="button" class="btn btn-peligro" data-boton="sacar"><i class="fa-solid fa-dolly"></i> Sacar estiba</button>
                    <?php if ($puedePicking): ?>
                        <button type="button" class="btn btn-picking" data-boton="picking"><i class="fa-solid fa-cart-flatbed"></i> Llevar a picking</button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($puedeEditar): ?>
                    <button type="button" class="btn" data-boton="editar"><i class="fa-solid fa-pen"></i> Editar</button>
                    <button type="submit" class="btn btn-primario" data-boton="guardar"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button>
                <?php endif; ?>
                <?php if ($puedeMover): ?>
                    <button type="submit" class="btn btn-primario" data-boton="agregar"><i class="fa-solid fa-plus"></i> Agregar estiba</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php if ($catalogo): ?>
    <datalist id="lista-skus-posiciones">
        <?php foreach ($catalogo as $sku => $texto): ?>
            <option value="<?php echo $esc($sku); ?>"><?php echo $esc($texto ?? ''); ?></option>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>

<?php if ($puedeCrear): ?>
<!-- CREAR / EDITAR UNA POSICIÓN: el código sale solo del rack, módulo, nivel y posición. -->
<?php foreach (['crear' => 'Crear posición', 'editar' => 'Editar posición'] as $modo => $titulo): ?>
<div class="modal-fondo" id="modal-<?php echo $modo; ?>-posicion">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid <?php echo $modo === 'crear' ? 'fa-plus' : 'fa-pen-to-square'; ?>"></i> <?php echo $titulo; ?></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/posiciones/acciones" method="POST" class="form-posicion">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="<?php echo $modo === 'crear' ? 'crear' : 'actualizar'; ?>">
            <input type="hidden" name="id_posicion" value="">
            <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
            <div class="modal-cuerpo">
                <div class="campos-posicion">
                    <div class="campo">
                        <label>Rack</label>
                        <select name="rack" required>
                            <?php for ($i = 1; $i <= POSICIONES_RACKS; $i++): ?><option value="<?php echo $i; ?>">Rack <?php echo $i; ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Módulo</label>
                        <select name="modulo" required>
                            <?php for ($i = 1; $i <= POSICIONES_MODULOS; $i++): ?><option value="<?php echo $i; ?>">Módulo <?php echo $i; ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Nivel</label>
                        <select name="nivel" required>
                            <?php for ($i = 1; $i <= POSICIONES_NIVELES; $i++): ?><option value="<?php echo $i; ?>">Nivel <?php echo $i; ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label>Posición</label>
                        <select name="lugar" required>
                            <?php foreach (POSICIONES_LUGARES as $l): ?><option value="<?php echo $l; ?>"><?php echo $l; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="codigo-nuevo">Código: <strong data-campo="codigo">R1M1N1A1</strong></div>
                <span class="ayuda">El módulo 12 solo tiene los niveles 3, 4 y 5.</span>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cerrar</button>
                <?php if ($modo === 'editar'): ?>
                    <button type="button" class="btn btn-peligro" data-boton="eliminar-posicion"><i class="fa-solid fa-trash-can"></i> Eliminar</button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> <?php echo $modo === 'crear' ? 'Crear' : 'Guardar cambios'; ?></button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- IMPORTAR POSICIONES: los códigos R1M1N1A1 de cualquier celda; crea los que falten. -->
<div class="modal-fondo" id="modal-importar-posiciones">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-file-arrow-up"></i> Importar posiciones</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/posiciones/acciones" method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="importar">
            <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
            <div class="modal-cuerpo">
                <div class="campo">
                    <label for="imp-archivo"><i class="fa-solid fa-file-excel"></i> Excel con los códigos (.xlsx)</label>
                    <input type="file" id="imp-archivo" name="archivo" accept=".xlsx,.xls" required>
                    <span class="ayuda">Se toman los códigos como R1M1N1A1 de cualquier columna. Se crean los que falten; no se borra ni se cambia ninguna posición.</span>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cerrar</button>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-file-arrow-up"></i> Importar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    // Lo que el script necesita saber de los permisos (los botones que no se pueden usar ni se dibujan).
    window.POSICIONES = {
        niveles: <?php echo json_encode(array_map(fn($m) => nivelesDelModuloPosiciones($m), array_combine(range(1, POSICIONES_MODULOS), range(1, POSICIONES_MODULOS)))); ?>,
        puedeMover: <?php echo $puedeMover ? 'true' : 'false'; ?>,
        puedeEditar: <?php echo $puedeEditar ? 'true' : 'false'; ?>,
        puedePicking: <?php echo $puedePicking ? 'true' : 'false'; ?>,
        urlCupo: <?php echo json_encode(BASE_URL . '/posiciones/acciones?accion=consultar_cupo'); ?>
    };
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/posiciones/layouts/scripts_posiciones.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/posiciones/layouts/scripts_posiciones.js'); ?>"></script>
</body>
</html>
