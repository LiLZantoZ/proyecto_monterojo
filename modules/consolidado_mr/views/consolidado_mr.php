<?php
// modules/consolidado_mr/views/consolidado_mr.php
// El Consolidado MR de SAP renglón por renglón: qué se facturó, a quién, cuántas unidades y por
// cuánto. Se alimenta subiendo el Excel "CONSOLIDADO MR". Ver model_consolidado_mr.php.
//
// Usa la hoja de estilos de Estado de pedidos (misma grilla, mismos totales, misma paginación) para
// que las tablas de los dos módulos se vean iguales; lo propio va en consolidado_mr.css.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_consolidado_mr.php';

requierePermiso('modulo_consolidado_mr', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// Importar y vaciar solo con este permiso (2026-09-28): el rol Visitante solo consulta la tabla.
$puedeEditar = tienePermiso('consolidado_mr_editar');

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$filtros = [
    'cliente'   => trim($_GET['cliente'] ?? ''),
    'poblacion' => trim($_GET['poblacion'] ?? ''),
    'buscar'    => trim($_GET['buscar'] ?? ''),
    'desde'     => trim($_GET['desde'] ?? ''),
    'hasta'     => trim($_GET['hasta'] ?? ''),
];

$totales      = totalesConsolidadoMr($pdo, $filtros);
$totalLineas  = $totales['lineas'];
$totalPaginas = max(1, (int) ceil($totalLineas / CONSOLIDADO_MR_POR_PAGINA));
$pagina       = min(max(1, (int) ($_GET['pagina'] ?? 1)), $totalPaginas);
$lineas       = lineasConsolidadoMr($pdo, $filtros, $pagina);

// VALOR NETO SUMADO POR FACTURA: después del último renglón de cada factura va una fila de subtotal.
// Los renglones vienen ordenados por factura, así que cada una es un bloque seguido. Si la última
// factura de la página sigue en la página siguiente, su subtotal se muestra allá (donde termina) y
// acá solo se avisa que continúa; igual con la primera, si arrancó en la página anterior.
$subtotales       = subtotalesPorFacturaConsolidadoMr($pdo, $filtros, array_column($lineas, 'factura'));
$offsetPagina     = ($pagina - 1) * CONSOLIDADO_MR_POR_PAGINA;
$facturaSiguiente = $lineas ? facturaEnPosicionConsolidadoMr($pdo, $filtros, $offsetPagina + count($lineas)) : null;
$facturaAnterior  = $offsetPagina > 0 ? facturaEnPosicionConsolidadoMr($pdo, $filtros, $offsetPagina - 1) : null;
$hayFiltro        = count(array_filter($filtros)) > 0;
$clientes     = valoresConsolidadoMr($pdo, 'nombre_cliente');
$poblaciones  = valoresConsolidadoMr($pdo, 'poblacion');
$filtrosEnUrl = http_build_query(array_filter($filtros));
$hayDatos     = $filtrosEnUrl !== '' || $totalLineas > 0;

$desdeFila = $totalLineas === 0 ? 0 : ($pagina - 1) * CONSOLIDADO_MR_POR_PAGINA + 1;
$hastaFila = min($pagina * CONSOLIDADO_MR_POR_PAGINA, $totalLineas);
$urlPagina = fn($p) => BASE_URL . '/consolidado-mr?' . http_build_query(array_filter($filtros) + ['pagina' => $p]);

// Formatos: miles con punto y decimales con coma, como en el resto del sistema.
$mil      = fn($n) => number_format((float) $n, 0, ',', '.');
$plata    = fn($v) => '$' . number_format((float) $v, 2, ',', '.');
// La cantidad viene en piezas: sin decimales si es entera, con los que tenga si no.
$cantidad = function ($v) {
    $v = (float) $v;
    return floor($v) == $v ? number_format($v, 0, ',', '.') : rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
};
$faltante = '<span class="dato-faltante">—</span>';
$celda    = fn($v) => ($v === null || $v === '') ? $faltante : $esc($v);
$fecha    = function ($v) use ($faltante) {
    $t = $v ? strtotime($v) : false;
    return $t ? date('d/m/Y', $t) : $faltante;
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidado MR · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/consolidado_mr/layouts/consolidado_mr.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/consolidado_mr/layouts/consolidado_mr.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Consolidado MR</h2>
                <div class="modulo-acciones">
                    <?php // Importar y vaciar solo con consolidado_mr_editar: el Visitante solo consulta. ?>
                    <?php if ($puedeEditar && ($totalLineas > 0 || $filtrosEnUrl !== '')): ?>
                        <form action="<?php echo BASE_URL; ?>/consolidado-mr/acciones" method="POST"
                              onsubmit="return confirm('¿Borrar TODO lo cargado en el Consolidado MR? Después se puede volver a subir el Excel.');">
                            <?php campoCSRF(); ?>
                            <input type="hidden" name="accion" value="vaciar">
                            <button type="submit" class="btn"><i class="fa-solid fa-trash-can"></i> Vaciar</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($puedeEditar): ?>
                        <button type="button" class="btn btn-primario" data-abrir="modal-importar">
                            <i class="fa-solid fa-file-arrow-up"></i> Importar Consolidado MR
                        </button>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($hayDatos): ?>
                <div class="pastillas">
                    <span class="pastilla">Renglones <strong><?php echo $mil($totales['lineas']); ?></strong></span>
                    <span class="pastilla">Facturas <strong><?php echo $mil($totales['facturas']); ?></strong></span>
                    <span class="pastilla">Clientes <strong><?php echo $mil($totales['clientes']); ?></strong></span>
                    <span class="pastilla">Unidades <strong><?php echo $cantidad($totales['cantidad']); ?></strong></span>
                    <span class="pastilla">Valor neto <strong><?php echo $esc($plata($totales['valor'])); ?></strong></span>
                </div>

                <form method="GET" class="filtros">
                    <div class="filtro">
                        <label for="f-cliente">Cliente</label>
                        <select name="cliente" id="f-cliente">
                            <option value="">Todos</option>
                            <?php foreach ($clientes as $c): ?>
                                <option value="<?php echo $esc($c); ?>" <?php echo $filtros['cliente'] === $c ? 'selected' : ''; ?>><?php echo $esc($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro">
                        <label for="f-poblacion">Ciudad</label>
                        <select name="poblacion" id="f-poblacion">
                            <option value="">Todas</option>
                            <?php foreach ($poblaciones as $p): ?>
                                <option value="<?php echo $esc($p); ?>" <?php echo $filtros['poblacion'] === $p ? 'selected' : ''; ?>><?php echo $esc($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro">
                        <label for="f-desde">Desde</label>
                        <input type="date" name="desde" id="f-desde" value="<?php echo $esc($filtros['desde']); ?>">
                    </div>
                    <div class="filtro">
                        <label for="f-hasta">Hasta</label>
                        <input type="date" name="hasta" id="f-hasta" value="<?php echo $esc($filtros['hasta']); ?>">
                    </div>
                    <div class="filtro" style="flex: 1;">
                        <label for="f-buscar">Buscar</label>
                        <input type="text" name="buscar" id="f-buscar" value="<?php echo $esc($filtros['buscar']); ?>"
                               placeholder="SKU, producto, referencia, pedido, orden de compra… Para varios: 36334, 36335"
                               title="Se puede buscar más de uno a la vez, separados por coma. Busca en factura, solicitante, cliente, ciudad, SKU, texto del material, referencia, pedido y orden de compra.">
                    </div>
                    <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                    <?php if ($filtrosEnUrl !== ''): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/consolidado-mr">Limpiar</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if (!$lineas): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div>
                        <?php echo $filtrosEnUrl !== ''
                            ? 'No hay renglones con esos filtros. Probá con otros o limpialos.'
                            : ($puedeEditar
                                ? 'Todavía no hay nada cargado. Subí el Excel del <strong>Consolidado MR</strong> con “Importar Consolidado MR”.'
                                : 'Todavía no hay nada cargado en el Consolidado MR.'); ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="tabla-caja" style="margin-top: 14px;">
                    <table class="tabla tabla-seguimiento tabla-sap tabla-mr">
                        <thead>
                            <tr>
                                <th class="num">Fecha factura</th>
                                <th>Solicitud</th>
                                <th>Cliente</th>
                                <th>Ciudad</th>
                                <th>SKU</th>
                                <th>Texto breve de material</th>
                                <th class="num">Cantidad Facturada</th>
                                <th class="num">Valor neto</th>
                                <th>Referencia</th>
                                <th>Pedido</th>
                                <th>Orden de compra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lineas as $i => $l): ?>
                                <?php
                                $factura      = (string) $l['factura'];
                                $esUltima     = !isset($lineas[$i + 1]) || (string) $lineas[$i + 1]['factura'] !== $factura;
                                $sigueDespues = $esUltima && !isset($lineas[$i + 1]) && $facturaSiguiente === $factura;
                                ?>
                                <?php if ($i === 0 && $facturaAnterior === $factura): ?>
                                    <tr class="fila-continua">
                                        <td colspan="11"><i class="fa-solid fa-arrow-turn-down"></i> Factura <?php echo $esc($factura); ?> — viene de la página anterior</td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="num"><?php echo $fecha($l['fecha_factura']); ?></td>
                                    <td><?php echo $celda($l['solicitante']); ?></td>
                                    <td class="celda-cliente"><?php echo $celda($l['nombre_cliente']); ?></td>
                                    <td><?php echo $celda($l['poblacion']); ?></td>
                                    <td><strong><?php echo $celda($l['material']); ?></strong></td>
                                    <td class="celda-producto"><?php echo $celda($l['texto_material']); ?></td>
                                    <td class="num celda-valor"><?php echo $l['cantidad_facturada'] !== null ? $cantidad($l['cantidad_facturada']) : $faltante; ?></td>
                                    <td class="num celda-valor"><?php echo $l['valor_neto'] !== null ? $esc($plata($l['valor_neto'])) : $faltante; ?></td>
                                    <td><?php echo $celda($l['referencia']); ?></td>
                                    <td><?php echo $celda($l['doc_ventas']); ?></td>
                                    <td><?php echo $celda($l['pedido_cliente']); ?></td>
                                </tr>
                                <?php if ($sigueDespues): ?>
                                    <tr class="fila-continua">
                                        <td colspan="11"><i class="fa-solid fa-arrow-turn-down"></i> Factura <?php echo $esc($factura); ?> — continúa en la página siguiente (el total va al final de la factura)</td>
                                    </tr>
                                <?php elseif ($esUltima && isset($subtotales[$factura])): ?>
                                    <?php
                                    $st = $subtotales[$factura];
                                    // Con un filtro que deja afuera renglones de la factura, se muestra además el total de la factura entera.
                                    $parcial = $hayFiltro && $st['lineas'] < $st['lineas_total'];
                                    ?>
                                    <tr class="fila-subtotal">
                                        <td colspan="6" class="subtotal-etiqueta">
                                            Total factura <strong><?php echo $esc($factura); ?></strong>
                                            <span class="subtotal-nota">
                                                (<?php echo $mil($st['lineas']); ?> <?php echo $st['lineas'] === 1 ? 'renglón' : 'renglones'; ?><?php
                                                echo $parcial ? ($st['lineas'] === 1 ? ' filtrado' : ' filtrados') . ' de ' . $mil($st['lineas_total']) : ''; ?>)
                                            </span>
                                        </td>
                                        <td class="num subtotal-monto"><?php echo $cantidad($st['cantidad']); ?></td>
                                        <td class="num subtotal-monto"><?php echo $esc($plata($st['valor'])); ?></td>
                                        <td colspan="3" class="subtotal-nota">
                                            <?php if ($parcial): ?>
                                                Factura completa: <strong><?php echo $esc($plata($st['valor_total'])); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fila-total">
                                <td colspan="6" class="total-etiqueta">
                                    Total
                                    <span class="total-nota">(<?php echo $mil($totalLineas); ?> <?php echo $totalLineas === 1 ? 'renglón' : 'renglones'; ?>, todo el filtro)</span>
                                </td>
                                <td class="num total-monto"><?php echo $cantidad($totales['cantidad']); ?></td>
                                <td class="num total-monto"><?php echo $esc($plata($totales['valor'])); ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- PAGINACIÓN: mismo estilo buscador que Estado de pedidos (1 2 3 … 9 … última). -->
                <div class="paginacion">
                    <span class="paginacion-info">
                        <?php echo $mil($desdeFila); ?>–<?php echo $mil($hastaFila); ?>
                        de <strong><?php echo $mil($totalLineas); ?></strong> renglones
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

<?php if ($puedeEditar): ?>
<!-- IMPORTAR EL CONSOLIDADO MR -->
<div class="modal-fondo" id="modal-importar">
    <div class="modal-caja modal-con-panel">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-file-arrow-up"></i> Importar Consolidado MR</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/consolidado-mr/acciones" method="POST" enctype="multipart/form-data" id="form-importar-mr">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="importar">
            <div class="modal-panel-grid">
                <aside class="modal-panel-lado">
                    <div class="panel-icono"><i class="fa-solid fa-file-excel"></i></div>
                    <h3>El Consolidado MR de SAP</h3>
                    <p>Subí el Excel tal cual sale de SAP. Las columnas se reconocen por su nombre.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> Carga un renglón por <strong>producto facturado</strong>.</li>
                        <li><i class="fa-solid fa-check"></i> Omite los renglones <strong>en cero</strong> que SAP agrega al partir por lote, y las facturas <strong>anuladas</strong>.</li>
                        <li><i class="fa-solid fa-check"></i> Subir otra vez la misma factura la <strong>reemplaza</strong>, no la duplica.</li>
                    </ul>
                </aside>

                <div class="modal-panel-form">
                    <div class="campo">
                        <label for="i-archivo"><i class="fa-solid fa-file-excel"></i> Excel del Consolidado MR (.xlsx)</label>
                        <input type="file" id="i-archivo" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">Un archivo de ~19.000 filas tarda alrededor de medio minuto en cargar. No cierres la página mientras tanto.</span>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario" id="btn-importar-mr"><i class="fa-solid fa-file-arrow-up"></i> Importar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/consolidado_mr/layouts/scripts_consolidado_mr.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/consolidado_mr/layouts/scripts_consolidado_mr.js'); ?>"></script>
</body>
</html>
