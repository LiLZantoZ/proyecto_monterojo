<?php
// modules/formato_conciliador/views/formato_conciliador.php
// FORMATO CONCILIADOR (2026-10-06): lo que sale de producción, estiba por estiba, con su rótulo.
// Ver model_formato_conciliador.php.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_formato_conciliador.php';

requierePermiso('modulo_formato_conciliador', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$puedeRegistrar = tienePermiso('formato_conciliador_registrar');

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mil = fn($n) => number_format((float) $n, 0, ',', '.');

$filtros = [
    'fecha'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') ? $_GET['fecha'] : '',
    'sku'         => trim($_GET['sku'] ?? ''),
    'lote'        => trim($_GET['lote'] ?? ''),
    'responsable' => trim($_GET['responsable'] ?? ''),
    'general'     => trim($_GET['general'] ?? ''),
];
$resultado = paginaConciliador($pdo, $filtros, $_GET['pagina'] ?? 1);
$filas     = $resultado['filas'];
$pagina    = $resultado['pagina'];
$totalPaginas = $resultado['total_paginas'];
$totalFilas   = $resultado['total'];
$desdeFila    = $totalFilas === 0 ? 0 : ($pagina - 1) * CONCILIADOR_POR_PAGINA + 1;
$hastaFila    = min($pagina * CONCILIADOR_POR_PAGINA, $totalFilas);
$catalogo     = $puedeRegistrar ? catalogoSkuProductos($pdo) : [];

$limpio    = fn(array $p) => array_filter($p, fn($v) => $v !== '');
$urlPagina = fn($p) => BASE_URL . '/formato-conciliador?' . http_build_query($limpio($filtros + ['pagina' => $p > 1 ? $p : '']));
$volver    = http_build_query($limpio($filtros + ['pagina' => $pagina > 1 ? $pagina : '']));
$hayFiltro = (bool) $limpio($filtros);
$claseEstado = ['Disponible' => 'estado-verde', 'En Control De Calidad' => 'estado-azul', 'Fecha Corta' => 'estado-ambar', 'Defectuoso' => 'estado-rojo'];
$turnoAhora = turnoActualConciliador();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formato Conciliador · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/formato_conciliador/layouts/formato_conciliador.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/formato_conciliador/layouts/formato_conciliador.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Formato Conciliador</h2>
                <div class="modulo-acciones">
                    <a class="btn" href="<?php echo $esc(BASE_URL . '/formato-conciliador/acciones?' . http_build_query(['accion' => 'exportar'] + $limpio($filtros))); ?>">
                        <i class="fa-solid fa-file-excel"></i> Exportar Excel
                    </a>
                    <?php if ($puedeRegistrar): ?>
                        <button type="button" class="btn btn-primario" data-abrir="modal-registro-fc">
                            <i class="fa-solid fa-plus"></i> Agregar registro
                        </button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>Cada registro es <strong>una estiba</strong> que salió de producción (hasta <?php echo CONCILIADOR_MAX_ESTIBAS; ?> por registro).
                    La columna <strong>En posiciones</strong> dice cuántas de ese SKU, lote y vencimiento ya están ubicadas.</div>
            </div>

            <form method="GET" class="filtros">
                <div class="filtro"><label for="f-fecha">Fecha</label><input type="date" name="fecha" id="f-fecha" value="<?php echo $esc($filtros['fecha']); ?>"></div>
                <div class="filtro"><label for="f-sku">SKU</label><input type="text" name="sku" id="f-sku" value="<?php echo $esc($filtros['sku']); ?>" style="width: 110px;"></div>
                <div class="filtro"><label for="f-lote">Lote</label><input type="text" name="lote" id="f-lote" value="<?php echo $esc($filtros['lote']); ?>" style="width: 120px;"></div>
                <div class="filtro"><label for="f-resp">Responsable</label><input type="text" name="responsable" id="f-resp" value="<?php echo $esc($filtros['responsable']); ?>" style="width: 150px;"></div>
                <div class="filtro" style="flex: 1;"><label for="f-gen">Buscar</label><input type="text" name="general" id="f-gen" value="<?php echo $esc($filtros['general']); ?>" placeholder="Consecutivo, descripción, vencimiento, novedades, estado, turno…"></div>
                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                <?php if ($hayFiltro): ?><a class="btn" href="<?php echo BASE_URL; ?>/formato-conciliador">Limpiar</a><?php endif; ?>
            </form>

            <?php if (!$filas): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div><?php echo $hayFiltro ? 'No hay registros con esos filtros.' : 'Todavía no hay registros en el Formato Conciliador.'; ?></div>
                </div>
            <?php else: ?>
                <div class="tabla-caja" style="margin-top: 14px;">
                    <table class="tabla tabla-seguimiento tabla-bodega tabla-conciliador">
                        <thead>
                            <tr>
                                <th>Cons</th><th>Fecha</th><th>SKU</th><th>Descripción</th><th>Lote</th><th>Vence</th>
                                <th>Cajas</th><th>Saldos</th><th>Total unid.</th><th>Responsable</th><th>Cód.</th><th>Turno</th>
                                <th>Novedades</th><th>Estado</th><th title="Estibas de este SKU, lote y vencimiento ubicadas en Posiciones / registradas">En posiciones</th>
                                <th>Rótulo</th><?php if ($puedeRegistrar): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filas as $r): ?>
                                <tr>
                                    <td><?php echo (int) $r['id']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($r['fecha'])); ?></td>
                                    <td><strong><?php echo $esc($r['sku']); ?></strong></td>
                                    <td><?php echo $esc($r['descripcion']); ?></td>
                                    <td><?php echo $esc($r['lote']); ?></td>
                                    <td><?php echo $r['fecha_vencimiento'] ? date('d/m/Y', strtotime($r['fecha_vencimiento'])) : '<span class="dato-faltante">—</span>'; ?></td>
                                    <td><?php echo $mil($r['cajas']); ?></td>
                                    <td><?php echo $mil($r['saldos']); ?></td>
                                    <td><?php echo $r['cantidad'] === null ? '<span class="dato-faltante" title="El SKU no tiene unidades por caja en el maestro">—</span>' : $mil($r['cantidad']); ?></td>
                                    <td><?php echo $esc($r['responsable']); ?></td>
                                    <td><?php echo $esc($r['codigo_responsable']); ?></td>
                                    <td><?php echo $esc($r['turno']); ?></td>
                                    <td><?php echo $r['novedades'] ? $esc($r['novedades']) : '<span class="dato-faltante">—</span>'; ?></td>
                                    <td><span class="chip-estado <?php echo $claseEstado[$r['estado']] ?? 'estado-gris'; ?>"><?php echo $esc($r['estado']); ?></span></td>
                                    <td><span class="chip-estado <?php echo (int) $r['ubicadas'] >= (int) $r['registradas'] ? 'estado-verde' : 'estado-ambar'; ?>"><?php echo (int) $r['ubicadas']; ?> de <?php echo (int) $r['registradas']; ?></span></td>
                                    <td>
                                        <button type="button" class="btn btn-chico btn-rotulo-fc" title="Ver e imprimir el rótulo"
                                                data-rotulo="<?php echo $esc(json_encode(['sku' => $r['sku'], 'descripcion' => $r['descripcion'], 'lote' => $r['lote'],
                                                    'vence' => $r['fecha_vencimiento'], 'cajas' => (int) $r['cajas']], JSON_UNESCAPED_UNICODE)); ?>">
                                            <i class="fa-solid fa-tag"></i> Rótulo
                                        </button>
                                    </td>
                                    <?php if ($puedeRegistrar): ?>
                                        <td>
                                            <form method="POST" action="<?php echo BASE_URL; ?>/formato-conciliador/acciones" class="form-en-linea"
                                                  data-confirmar="¿Eliminar el registro #<?php echo (int) $r['id']; ?> (SKU <?php echo $esc($r['sku']); ?>, lote <?php echo $esc($r['lote']); ?>)?">
                                                <?php campoCSRF(); ?>
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
                                                <button type="submit" class="btn btn-chico btn-peligro" title="Eliminar este registro"><i class="fa-solid fa-trash-can"></i></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php $total = $totalFilas; $etiqueta = 'registros'; include ROOT_PATH . '/modules/inicio/layout/paginacion.php'; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if ($puedeRegistrar): ?>
<!-- AGREGAR REGISTRO: la descripción, las unidades por caja y el vencimiento se completan solos. -->
<div class="modal-fondo" id="modal-registro-fc">
    <div class="modal-caja modal-caja-ancha">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-clipboard-list"></i> Agregar registro</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form method="POST" action="<?php echo BASE_URL; ?>/formato-conciliador/acciones" id="form-fc">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
            <div class="modal-cuerpo">
                <div class="campos-tres">
                    <div class="campo">
                        <label for="fc-sku">SKU</label>
                        <input type="text" id="fc-sku" name="sku" list="lista-skus-fc" required autocomplete="off" inputmode="numeric" placeholder="Código SKU">
                    </div>
                    <div class="campo">
                        <label for="fc-lote">Lote</label>
                        <input type="text" id="fc-lote" name="lote" maxlength="60" required placeholder="Lote del producto">
                    </div>
                    <div class="campo">
                        <label for="fc-fecha">Fecha</label>
                        <input type="date" id="fc-fecha" name="fecha" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="campo campo-ancho">
                        <label for="fc-desc">Descripción</label>
                        <input type="text" id="fc-desc" name="descripcion" maxlength="255" placeholder="Se completa sola con el SKU">
                        <span class="ayuda" data-campo="upc"></span>
                    </div>
                    <div class="campo">
                        <label for="fc-vence">Fecha de vencimiento</label>
                        <input type="date" id="fc-vence" name="fecha_vencimiento">
                    </div>
                    <div class="campo">
                        <label for="fc-cajas">Cajas</label>
                        <input type="number" id="fc-cajas" name="cajas" min="0" value="0" required>
                    </div>
                    <div class="campo">
                        <label for="fc-saldos">Saldos</label>
                        <input type="number" id="fc-saldos" name="saldos" min="0" value="0" required title="Unidades que no completan una caja">
                    </div>
                    <div class="campo">
                        <label>Total de unidades</label>
                        <div class="dato-calculado" data-campo="total">—</div>
                    </div>
                    <div class="campo">
                        <label for="fc-estibas">Cantidad de estibas</label>
                        <input type="number" id="fc-estibas" name="cantidad_estibas" min="1" max="<?php echo CONCILIADOR_MAX_ESTIBAS; ?>" value="1" required>
                        <span class="ayuda">Se crea un registro por estiba (todos iguales).</span>
                    </div>
                    <div class="campo">
                        <label for="fc-estado">Estado</label>
                        <select id="fc-estado" name="estado" required>
                            <option value="" disabled selected>Elegí el estado</option>
                            <?php foreach (CONCILIADOR_ESTADOS as $e): ?>
                                <option value="<?php echo $esc($e); ?>"><?php echo $esc($e); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo campo-ancho">
                        <label for="fc-nov">Novedades</label>
                        <input type="text" id="fc-nov" name="novedades" maxlength="255" placeholder="Opcional">
                    </div>
                </div>
                <p class="ayuda" style="margin: 6px 0 0;">Responsable: <strong><?php echo $esc($_SESSION['usuario_nombre'] ?? ''); ?></strong>
                    · código <?php echo codigoResponsableConciliador($_SESSION['nombre_rol'] ?? ''); ?> · turno <?php echo $turnoAhora; ?> (sale de la hora).</p>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cerrar</button>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>
<datalist id="lista-skus-fc">
    <?php foreach ($catalogo as $sku => $texto): ?>
        <option value="<?php echo $esc($sku); ?>"><?php echo $esc($texto ?? ''); ?></option>
    <?php endforeach; ?>
</datalist>
<?php endif; ?>

<!-- EL RÓTULO: la hoja "ROTULO" del Excel de bodega (27,7 × 17,6 cm), para pegar en la estiba. -->
<div class="modal-fondo" id="modal-rotulo-fc">
    <div class="modal-caja modal-caja-rotulo-fc">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-tag"></i> Rótulo</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <div class="modal-cuerpo rc-scroll">
            <div class="rc-escala">
                <div class="rc-hoja" id="rc-hoja">
                    <div class="rc-celda rc-lbl rc-lbl-sku"><span>SKU</span><span class="rc-fecha-hoy" data-rc="hoy"></span></div>
                    <div class="rc-celda rc-lbl">Descripción</div>
                    <div class="rc-celda rc-val-sku" data-rc="sku"></div>
                    <div class="rc-celda rc-val-desc" data-rc="desc"></div>
                    <div class="rc-celda rc-lbl">LOTE</div>
                    <div class="rc-celda rc-lbl">Día Juliano</div>
                    <div class="rc-celda rc-val-lote" data-rc="lote"></div>
                    <div class="rc-celda rc-val-juliano" data-rc="juliano"></div>
                    <div class="rc-celda rc-lbl">FECHA VENCIMIENTO</div>
                    <div class="rc-celda rc-lbl">N° Cajas</div>
                    <div class="rc-celda rc-val-venc" data-rc="venc"></div>
                    <div class="rc-celda rc-val-cajas" data-rc="cajas"></div>
                </div>
            </div>
            <p class="rc-nota" data-rc="nota"></p>
        </div>
        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cerrar</button>
            <button type="button" class="btn btn-primario" id="btn-imprimir-rc"><i class="fa-solid fa-print"></i> Imprimir</button>
        </div>
    </div>
</div>

<script>
    window.CONCILIADOR = { urlBuscar: <?php echo json_encode(BASE_URL . '/formato-conciliador/acciones?accion=buscar_producto'); ?> };
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/formato_conciliador/layouts/scripts_formato_conciliador.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/formato_conciliador/layouts/scripts_formato_conciliador.js'); ?>"></script>
</body>
</html>
