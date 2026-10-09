<?php
// modules/rendimiento/views/rendimiento.php
// RENDIMIENTO (2026-10-06): la actividad del sistema y de cada persona en una ventana de días: por
// día, por hora, por día de la semana, por módulo, las acciones más repetidas, el ranking y, de una
// persona, su jornada día por día. Ver model_rendimiento.php.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_rendimiento.php';

requierePermiso('modulo_rendimiento', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mil = fn($n) => number_format((float) $n, 0, ',', '.');
$hora = fn($v) => $v ? date('H:i', strtotime($v)) : '—';

$dias      = ventanaRendimiento($_GET['dias'] ?? 30);
$usuarios  = usuariosRendimiento($pdo);
$idUsuario = isset($usuarios[(int) ($_GET['usuario'] ?? 0)]) ? (int) $_GET['usuario'] : null;

$resumen    = resumenRendimiento($pdo, $dias, $idUsuario);
$comparar   = comparativaMensualRendimiento($pdo, $idUsuario);
$porDia     = porDiaRendimiento($pdo, $dias, $idUsuario);
$porHora    = porHoraRendimiento($pdo, $dias, $idUsuario);
$porSemana  = porDiaSemanaRendimiento($pdo, $dias, $idUsuario);
$porModulo  = porModuloRendimiento($pdo, $dias, $idUsuario);
$top        = topAccionesRendimiento($pdo, $dias, $idUsuario);
$ranking    = $idUsuario ? [] : rankingRendimiento($pdo, $dias);
$jornada    = $idUsuario ? jornadaRendimiento($pdo, $idUsuario, $dias) : [];

$pct = fn($n, $max) => ($max > 0 && $n > 0) ? max(2, round($n / $max * 100, 1)) : 0;
$urlExportar = BASE_URL . '/rendimiento/acciones?' . http_build_query(array_filter(['accion' => 'exportar', 'dias' => $dias, 'usuario' => $idUsuario]));
$diasCortos = ['Lunes' => 'Lun', 'Martes' => 'Mar', 'Miércoles' => 'Mié', 'Jueves' => 'Jue', 'Viernes' => 'Vie', 'Sábado' => 'Sáb', 'Domingo' => 'Dom'];

// Las columnas de una serie (por día, por hora, por día de la semana): [etiqueta, valor, título, mes].
// Con `mes`, la columna lleva la raya divisora y el nombre del mes encima (solo el gráfico por día).
$columnas = function (array $serie, $clase = '') use ($pct, $mil, $esc) {
    $max = max(array_merge([0], array_column($serie, 1)));
    $html = '<div class="grafico-columnas' . ($clase ? ' ' . $clase : '') . '">';
    foreach ($serie as $c) {
        [$etiqueta, $valor, $titulo] = $c;
        $mes = $c[3] ?? null;
        $html .= '<div class="columna' . ($mes ? ' columna-mes' : '') . '" title="' . $esc($titulo . ': ' . $mil($valor) . ' acción(es)') . '">'
               . ($mes ? '<span class="columna-mes-nombre" style="width: calc(' . (int) $c[4] . ' * 100% + ' . ((int) $c[4] - 1) * 2 . 'px - 4px)">' . $esc($mes) . '</span>' : '')
               . '<span class="columna-valor">' . ($valor ? $mil($valor) : '') . '</span>'
               . '<span class="columna-barra" style="height: ' . $pct($valor, $max) . '%"></span>'
               . '<span class="columna-etiqueta">' . $esc($etiqueta) . '</span></div>';
    }
    return $html . '</div>';
};
// Las barras horizontales (por módulo, acciones, ranking): [etiqueta, valor, detalle].
$barras = function (array $filas) use ($pct, $mil, $esc) {
    $max = max(array_merge([0], array_column($filas, 1)));
    $html = '<div class="grafico-barras">';
    foreach ($filas as [$etiqueta, $valor, $detalle]) {
        $html .= '<div class="barra-fila"><span class="barra-etiqueta" title="' . $esc($etiqueta) . '">' . $esc($etiqueta)
               . ($detalle !== '' ? ' <small>' . $esc($detalle) . '</small>' : '') . '</span>'
               . '<span class="barra-pista"><span class="barra-relleno" style="width: ' . $pct($valor, $max) . '%"></span></span>'
               . '<span class="barra-valor">' . $mil($valor) . '</span></div>';
    }
    return $html . '</div>';
};
$serieDia = serieDiaRendimiento($porDia);
$serieHora = serieHoraRendimiento($porHora);
$serieSemana = array_map(fn($d, $n) => [$diasCortos[$d], $n, $d], array_keys($porSemana), $porSemana);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rendimiento · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Rendimiento<?php echo $idUsuario ? ' · ' . $esc($usuarios[$idUsuario]) : ''; ?></h2>
                <div class="modulo-acciones">
                    <a class="btn" href="<?php echo $esc($urlExportar); ?>"><i class="fa-solid fa-file-excel"></i> Exportar Excel</a>
                </div>
            </header>

            <form method="GET" class="filtros">
                <div class="filtro">
                    <label for="f-dias">Período</label>
                    <select name="dias" id="f-dias" data-aplica-solo>
                        <?php foreach (RENDIMIENTO_VENTANAS as $d => $texto): ?>
                            <option value="<?php echo $d; ?>" <?php echo $dias === $d ? 'selected' : ''; ?>><?php echo $esc($texto); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-usuario">Persona</label>
                    <select name="usuario" id="f-usuario" data-aplica-solo>
                        <option value="">Todos</option>
                        <?php foreach ($usuarios as $id => $nombre): ?>
                            <option value="<?php echo (int) $id; ?>" <?php echo $idUsuario === (int) $id ? 'selected' : ''; ?>><?php echo $esc($nombre); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($idUsuario): ?><a class="btn" href="<?php echo $esc(BASE_URL . '/rendimiento?dias=' . $dias); ?>">Ver a todos</a><?php endif; ?>
            </form>

            <!-- LOS NÚMEROS DE CABECERA -->
            <div class="tarjetas-rendimiento">
                <div class="tarjeta-rend"><span>Acciones</span><strong><?php echo $mil($resumen['acciones']); ?></strong><small><?php echo $esc(RENDIMIENTO_VENTANAS[$dias]); ?></small></div>
                <?php if (!$idUsuario): ?>
                    <div class="tarjeta-rend"><span>Personas activas</span><strong><?php echo $mil($resumen['usuarios']); ?></strong><small><?php echo number_format($resumen['promedio'], 1, ',', '.'); ?> acciones por persona</small></div>
                <?php endif; ?>
                <div class="tarjeta-rend"><span>Días con actividad</span><strong><?php echo $mil($resumen['dias_activos']); ?></strong><small>de <?php echo $dias; ?></small></div>
                <div class="tarjeta-rend"><span>Entradas al sistema</span><strong><?php echo $mil($resumen['entradas']); ?></strong><small>inicios de sesión</small></div>
                <div class="tarjeta-rend<?php echo $resumen['errores'] ? ' tarjeta-alerta' : ''; ?>"><span>Con error</span><strong><?php echo $mil($resumen['errores']); ?></strong><small>acciones que no salieron</small></div>
                <div class="tarjeta-rend">
                    <span>Este mes al día <?php echo $comparar['dia']; ?></span><strong><?php echo $mil($comparar['actual']); ?></strong>
                    <small>
                        <?php if ($comparar['variacion'] === null): ?>
                            el mes pasado a esta altura: <?php echo $mil($comparar['anterior']); ?>
                        <?php else: ?>
                            <span class="<?php echo $comparar['variacion'] >= 0 ? 'texto-sube' : 'texto-baja'; ?>"><?php echo ($comparar['variacion'] >= 0 ? '▲ ' : '▼ ') . number_format(abs($comparar['variacion']), 0, ',', '.'); ?>%</span>
                            contra el mes pasado (<?php echo $mil($comparar['anterior']); ?>)
                        <?php endif; ?>
                    </small>
                </div>
            </div>

            <?php if (!$resumen['acciones']): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div>No hay actividad registrada en este período. Las acciones se anotan solas a medida que se usa el sistema (ver Trazabilidad).</div>
                </div>
            <?php else: ?>
                <section class="seccion-bodega">
                    <h3><i class="fa-solid fa-calendar-days"></i> Acciones por día</h3>
                    <?php echo $columnas($serieDia, 'grafico-dias' . (count($serieDia) > 31 ? ' grafico-denso' : '')); ?>
                </section>

                <div class="rend-dos rend-horas">
                    <section class="seccion-bodega">
                        <h3><i class="fa-solid fa-clock"></i> A qué hora se trabaja</h3>
                        <?php echo $columnas($serieHora, 'grafico-horas'); ?>
                    </section>
                    <section class="seccion-bodega">
                        <h3><i class="fa-solid fa-calendar-week"></i> Qué días de la semana</h3>
                        <?php echo $columnas($serieSemana); ?>
                    </section>
                </div>

                <div class="rend-dos">
                    <section class="seccion-bodega">
                        <h3><i class="fa-solid fa-layer-group"></i> Por módulo</h3>
                        <?php echo $barras(array_map(fn($m, $n) => [$m, $n, ''], array_keys($porModulo), $porModulo)); ?>
                    </section>
                    <section class="seccion-bodega">
                        <h3><i class="fa-solid fa-list-ol"></i> Lo que más se hace</h3>
                        <?php echo $barras(array_map(fn($a) => [$a['accion'], (int) $a['n'], $a['modulo']], $top)); ?>
                    </section>
                </div>
            <?php endif; ?>

            <?php if (!$idUsuario): ?>
                <!-- EL RANKING: clic en una persona = su jornada. -->
                <section class="seccion-bodega">
                    <h3><i class="fa-solid fa-ranking-star"></i> Personas</h3>
                    <div class="tabla-caja">
                        <table class="tabla tabla-seguimiento tabla-bodega">
                            <thead><tr><th>#</th><th>Persona</th><th>Rol</th><th>Acciones</th><th>Días con actividad</th><th>Con error</th><th>Última actividad</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($ranking as $i => $u): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><strong><?php echo $esc($u['nombre_usuario']); ?></strong><?php echo $u['estado'] !== 'Activo' ? ' <span class="chip-estado estado-gris">Inactivo</span>' : ''; ?></td>
                                        <td><?php echo $esc($u['nombre_rol']); ?></td>
                                        <td><?php echo $mil($u['acciones']); ?></td>
                                        <td><?php echo $mil($u['dias']); ?></td>
                                        <td><?php echo $u['errores'] ? '<span class="chip-estado estado-rojo">' . $mil($u['errores']) . '</span>' : '0'; ?></td>
                                        <td><?php echo $u['ultima'] ? date('d/m/Y H:i', strtotime($u['ultima'])) : '<span class="dato-faltante">—</span>'; ?></td>
                                        <td><a class="btn btn-chico" href="<?php echo $esc(BASE_URL . '/rendimiento?dias=' . $dias . '&usuario=' . (int) $u['id_usuario']); ?>"><i class="fa-solid fa-user-clock"></i> Jornada</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php else: ?>
                <!-- LA JORNADA DE LA PERSONA, día por día. -->
                <section class="seccion-bodega">
                    <h3><i class="fa-solid fa-user-clock"></i> Jornada día por día</h3>
                    <p class="texto-suave">La primera y la última <strong>acción</strong> dicen cuánto trabajó; la entrada al sistema solo dice cuándo llegó (alguien puede entrar a las 7:30 y seguir trabajando hasta la tarde sin volver a entrar).</p>
                    <?php if (!$jornada): ?>
                        <p class="texto-suave">Sin actividad en este período.</p>
                    <?php else: ?>
                        <div class="tabla-caja">
                            <table class="tabla tabla-seguimiento tabla-bodega">
                                <thead><tr><th>Fecha</th><th>Entró</th><th>Última entrada</th><th>Primera acción</th><th>Última acción</th><th>Acciones</th><th>Con error</th><th>Estibas que entraron</th><th>Estibas que salieron</th><th>Conciliador</th></tr></thead>
                                <tbody>
                                    <?php foreach ($jornada as $d): ?>
                                        <tr>
                                            <td><strong><?php echo date('d/m/Y', strtotime($d['fecha'])); ?></strong> <span class="sub-dato"><?php echo RENDIMIENTO_DIAS_SEMANA[(int) date('N', strtotime($d['fecha'])) - 1]; ?></span></td>
                                            <td><?php echo $hora($d['primera_entrada']); ?></td>
                                            <td><?php echo $hora($d['ultima_entrada']); ?></td>
                                            <td><?php echo $hora($d['primera_accion']); ?></td>
                                            <td><?php echo $hora($d['ultima_accion']); ?></td>
                                            <td><?php echo $mil($d['acciones']); ?></td>
                                            <td><?php echo (int) $d['errores'] ? '<span class="chip-estado estado-rojo">' . $mil($d['errores']) . '</span>' : '0'; ?></td>
                                            <td><?php echo $mil($d['estibas_entraron']); ?></td>
                                            <td><?php echo $mil($d['estibas_salieron']); ?></td>
                                            <td><?php echo $mil($d['registros_conciliador']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
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
