<?php
// modules/trazabilidad/views/trazabilidad.php
// TRAZABILIDAD (2026-10-06): cada acción del sistema, con quién la hizo, cuándo y qué le contestó
// el sistema. Ver model_trazabilidad.php y config/actividad.php (de dónde salen las acciones).

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_trazabilidad.php';

requierePermiso('modulo_trazabilidad', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$opciones = opcionesFiltrosTrazabilidad($pdo);
$meses    = mesesConActividad($pdo);
$filtros  = [
    'buscar'    => trim($_GET['buscar'] ?? ''),
    'mes'       => esMesTrazabilidad($_GET['mes'] ?? '') ? $_GET['mes'] : '',
    'modulo'    => in_array($_GET['modulo'] ?? '', $opciones['modulos'], true) ? $_GET['modulo'] : '',
    'usuario'   => isset($opciones['usuarios'][(int) ($_GET['usuario'] ?? 0)]) ? (string) (int) $_GET['usuario'] : '',
    'resultado' => in_array($_GET['resultado'] ?? '', ['exito', 'error'], true) ? $_GET['resultado'] : '',
];
$resultado = paginaTrazabilidad($pdo, $filtros, $_GET['pagina'] ?? 1);
$filas     = $resultado['filas'];
$pagina    = $resultado['pagina'];
$totalPaginas = $resultado['total_paginas'];
$total     = $resultado['total'];
$desdeFila = $total === 0 ? 0 : ($pagina - 1) * TRAZABILIDAD_POR_PAGINA + 1;
$hastaFila = min($pagina * TRAZABILIDAD_POR_PAGINA, $total);
$etiqueta  = 'acciones';

$limpio    = fn(array $p) => array_filter($p, fn($v) => $v !== '');
$urlPagina = fn($p) => BASE_URL . '/trazabilidad?' . http_build_query($limpio($filtros + ['pagina' => $p > 1 ? $p : '']));
$hayFiltro = (bool) $limpio($filtros);
$urlExportar = BASE_URL . '/trazabilidad/acciones?' . http_build_query(['accion' => 'exportar'] + $limpio($filtros));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trazabilidad · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Trazabilidad del sistema</h2>
                <div class="modulo-acciones">
                    <a class="btn" href="<?php echo $esc($urlExportar); ?>">
                        <i class="fa-solid fa-file-excel"></i> <?php echo $filtros['mes'] !== '' ? 'Exportar ' . $esc(etiquetaMesTrazabilidad($filtros['mes'])) : ($hayFiltro ? 'Exportar lo filtrado' : 'Exportar Excel'); ?>
                    </a>
                </div>
            </header>

            <form method="GET" class="filtros">
                <div class="filtro">
                    <label for="f-mes">Mes</label>
                    <select name="mes" id="f-mes" data-aplica-solo>
                        <option value="">Todo el historial</option>
                        <?php foreach ($meses as $m): ?>
                            <option value="<?php echo $esc($m['mes']); ?>" <?php echo $filtros['mes'] === $m['mes'] ? 'selected' : ''; ?>><?php echo $esc($m['etiqueta']); ?> (<?php echo number_format($m['total'], 0, ',', '.'); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-modulo">Módulo</label>
                    <select name="modulo" id="f-modulo" data-aplica-solo>
                        <option value="">Todos</option>
                        <?php foreach ($opciones['modulos'] as $m): ?>
                            <option value="<?php echo $esc($m); ?>" <?php echo $filtros['modulo'] === $m ? 'selected' : ''; ?>><?php echo $esc($m); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-usuario">Usuario</label>
                    <select name="usuario" id="f-usuario" data-aplica-solo>
                        <option value="">Todos</option>
                        <?php foreach ($opciones['usuarios'] as $id => $nombre): ?>
                            <option value="<?php echo (int) $id; ?>" <?php echo $filtros['usuario'] === (string) $id ? 'selected' : ''; ?>><?php echo $esc($nombre); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-resultado">Resultado</label>
                    <select name="resultado" id="f-resultado" data-aplica-solo>
                        <option value="">Todos</option>
                        <option value="exito" <?php echo $filtros['resultado'] === 'exito' ? 'selected' : ''; ?>>Salió bien</option>
                        <option value="error" <?php echo $filtros['resultado'] === 'error' ? 'selected' : ''; ?>>Con error</option>
                    </select>
                </div>
                <div class="filtro" style="flex: 1;">
                    <label for="f-buscar">Buscar</label>
                    <input type="text" name="buscar" id="f-buscar" value="<?php echo $esc($filtros['buscar']); ?>" placeholder="Usuario, rol, módulo, acción o detalle…">
                </div>
                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                <?php if ($hayFiltro): ?><a class="btn" href="<?php echo BASE_URL; ?>/trazabilidad">Limpiar</a><?php endif; ?>
            </form>

            <?php if (!$filas): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div><?php echo $hayFiltro ? 'No hay acciones con esos filtros.' : 'Todavía no hay acciones registradas. Se anotan solas a medida que se usa el sistema.'; ?></div>
                </div>
            <?php else: ?>
                <div class="tabla-caja" style="margin-top: 14px;">
                    <table class="tabla tabla-seguimiento tabla-bodega tabla-trazabilidad">
                        <thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Rol</th><th>Módulo</th><th>Acción</th><th>Detalle</th></tr></thead>
                        <tbody>
                            <?php foreach ($filas as $a): ?>
                                <tr class="<?php echo $a['resultado'] === 'error' ? 'fila-error' : ''; ?>">
                                    <td class="celda-fecha"><?php echo date('d/m/Y H:i', strtotime($a['fecha_hora'])); ?></td>
                                    <td><?php echo $esc($a['nombre_usuario']); ?></td>
                                    <td><?php echo $a['nombre_rol'] !== '' ? $esc($a['nombre_rol']) : '<span class="dato-faltante">—</span>'; ?></td>
                                    <td><span class="chip-estado estado-gris"><?php echo $esc($a['modulo']); ?></span></td>
                                    <td>
                                        <?php if ($a['resultado'] === 'error'): ?><i class="fa-solid fa-circle-xmark icono-error" title="No salió bien"></i><?php endif; ?>
                                        <?php echo $esc($a['accion']); ?>
                                    </td>
                                    <td class="celda-detalle-traza"><?php echo $a['detalle'] !== null ? $esc($a['detalle']) : '<span class="dato-faltante">—</span>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php include ROOT_PATH . '/modules/inicio/layout/paginacion.php'; ?>
            <?php endif; ?>

        </div>
    </div>
</div>
<script>
    document.querySelectorAll('select[data-aplica-solo]').forEach(function (s) {
        s.addEventListener('change', function () { s.form.requestSubmit ? s.form.requestSubmit() : s.form.submit(); });
    });
</script>
</body>
</html>
