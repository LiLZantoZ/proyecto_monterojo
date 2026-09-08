<?php
// modules/historial/views/historial.php
// El historial de lo que ya se despachó, agrupado por CEDI igual que Picking —mismo agrupador
// visual, mismos rótulos y la misma hoja de alistamiento, porque es exactamente el mismo tipo de
// pantalla, solo que mirando hacia atrás en vez de hacia lo pendiente.
//
// Lo único que Picking no tiene es "Restaurar": deshace un despacho hecho por error y lo vuelve a
// dejar pendiente.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_historial.php';

requierePermiso('modulo_historial', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$filtros = [
    'busqueda' => trim($_GET['q'] ?? ''),
];

$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$resultado = historialPaginado($pdo, $filtros, $pagina, 20);
$entregas  = $resultado['entregas'];
$porCedi   = agruparPorCediHistorial($entregas);
$resumen   = resumenHistorialTotales($pdo, $filtros);

// La URL para volver acá conservando el filtro; la usa la paginación.
$filtrosEnUrl = http_build_query(array_filter($filtros));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Pedidos · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Historial de Pedidos</h2>
                <div class="modulo-acciones">
                    <?php if ($resumen['pedidos'] > 0): ?>
                        <a class="btn"
                           href="<?php echo BASE_URL; ?>/modules/historial/controller_historial.php?accion=pdf_reporte&<?php echo $filtrosEnUrl; ?>">
                            <i class="fa-solid fa-file-pdf"></i> PDF de todos los pedidos
                        </a>
                        <a class="btn"
                           href="<?php echo BASE_URL; ?>/modules/historial/controller_historial.php?accion=rotulos_pdf&<?php echo $filtrosEnUrl; ?>"
                           title="Descarga en un solo PDF los rótulos de todos los pedidos que se ven acá (uno por caja).">
                            <i class="fa-solid fa-tags"></i> Rótulos de todos los pedidos
                        </a>
                    <?php endif; ?>
                    <?php if (tienePermiso('modulo_picking')): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/modules/picking/views/picking.php">
                            <i class="fa-solid fa-cart-flatbed"></i> Ir a Picking
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <div class="pastillas">
                <span class="pastilla">Pedidos despachados <strong><?php echo number_format($resumen['pedidos'], 0, ',', '.'); ?></strong></span>
                <span class="pastilla">Cajas <strong><?php echo number_format($resumen['cajas'], 0, ',', '.'); ?></strong></span>
                <?php if ($resumen['peso_kg'] > 0): ?>
                    <span class="pastilla" title="Peso bruto despachado, según el peso por unidad del maestro">
                        Peso <strong><?php echo number_format($resumen['peso_kg'], 0, ',', '.'); ?> kg</strong>
                    </span>
                <?php endif; ?>
            </div>

            <form class="filtros" method="GET">
                <div class="filtro" style="flex: 1;">
                    <label for="f-q">Buscar</label>
                    <input type="text" name="q" id="f-q" value="<?php echo htmlspecialchars($filtros['busqueda']); ?>"
                           placeholder="CEDI, punto de venta, O/C, alistador o quien despachó">
                </div>

                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                <?php if (array_filter($filtros)): ?>
                    <a class="btn" href="<?php echo BASE_URL; ?>/modules/historial/views/historial.php">Limpiar</a>
                <?php endif; ?>
            </form>

            <?php if (empty($entregas)): ?>
                <div class="tabla-caja">
                    <p class="tabla-vacia">
                        <?php echo $filtros['busqueda'] !== ''
                            ? 'Ningún pedido despachado coincide con la búsqueda.'
                            : 'Todavía no se ha despachado ningún pedido.'; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($porCedi as $cedi => $grupo): $tc = $grupo['totales']; ?>
                    <!-- Un desplegable por CEDI, igual que Picking. Cerrado por omisión: con la
                         cabecera ya diciendo el nombre y el conteo, se ve de un vistazo cuánto hay
                         por zona sin abrir nada. -->
                    <details class="grupo-desplegable">
                        <summary class="grupo-cabecera">
                            <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>

                            <div class="grupo-titulo">
                                <div class="grupo-nombre">
                                    <?php echo htmlspecialchars($cedi); ?>
                                    <span class="grupo-conteo"><?php echo $tc['pedidos']; ?> pedidos</span>
                                </div>
                                <div class="grupo-resumen">
                                    <?php echo number_format($tc['lineas'], 0, ',', '.'); ?> líneas ·
                                    <?php echo number_format($tc['unidades'], 0, ',', '.'); ?> unidades ·
                                    <?php echo number_format($tc['cajas_rotulo'], 0, ',', '.'); ?> cajas
                                    <?php if ($tc['peso_kg'] > 0): ?>
                                        · <?php echo number_format($tc['peso_kg'], 0, ',', '.'); ?> kg
                                    <?php endif; ?>
                                </div>
                            </div>
                        </summary>

                        <div class="tabla-caja">
                            <table class="tabla tabla-pedidos tabla-accion-fija">
                                <thead>
                                    <tr>
                                        <th style="width: 34px;" class="centro">
                                            <input type="checkbox" class="chk-todos"
                                                   title="Seleccionar todos los pedidos de este CEDI"
                                                   aria-label="Seleccionar todos los pedidos de este CEDI">
                                        </th>
                                        <th style="width: 34px;"><span class="sr-solo">Detalle</span></th>
                                        <th>Punto de venta</th>
                                        <th>O/C</th>
                                        <th>Alistado por</th>
                                        <th>Despachado por</th>
                                        <th>Fecha</th>
                                        <th class="num">Cajas</th>
                                        <th class="num">Peso</th>
                                        <th class="centro">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grupo['entregas'] as $entrega): $t = $entrega['totales']; ?>
                                        <?php $clave = $entrega['clave']; ?>
                                        <tr class="fila-pedido" data-entrega="<?php echo htmlspecialchars($clave); ?>">
                                            <td class="centro">
                                                <input type="checkbox" class="chk-pedido"
                                                       aria-label="Seleccionar <?php echo htmlspecialchars($entrega['punto_venta']); ?>"
                                                       data-carga="<?php echo (int) $entrega['id_carga']; ?>"
                                                       data-cedi="<?php echo htmlspecialchars($entrega['cedi']); ?>"
                                                       data-oc="<?php echo htmlspecialchars($entrega['orden_compra']); ?>"
                                                       data-pv="<?php echo htmlspecialchars($entrega['punto_venta']); ?>">
                                            </td>
                                            <td class="centro">
                                                <button type="button" class="btn-detalle"
                                                        aria-expanded="false"
                                                        title="Ver los productos de este pedido">
                                                    <i class="fa-solid fa-chevron-right"></i>
                                                </button>
                                            </td>
                                            <td><?php echo htmlspecialchars($entrega['punto_venta']); ?></td>
                                            <td><?php echo $entrega['orden_compra'] !== '' ? htmlspecialchars($entrega['orden_compra']) : '<span class="sin-dato">—</span>'; ?></td>
                                            <td><?php echo $entrega['personal_nombre'] !== null ? htmlspecialchars($entrega['personal_nombre']) : '<span class="sin-dato">—</span>'; ?></td>
                                            <td><?php echo $entrega['usuario_nombre'] !== null ? htmlspecialchars($entrega['usuario_nombre']) : '<span class="sin-dato">—</span>'; ?></td>
                                            <td><?php echo $entrega['fecha_despacho'] ? date('d/m/Y H:i', strtotime($entrega['fecha_despacho'])) : '<span class="sin-dato">—</span>'; ?></td>
                                            <td class="num"><strong><?php echo number_format($t['cajas_rotulo'], 0, ',', '.'); ?></strong></td>
                                            <td class="num">
                                                <?php echo $t['peso_kg'] > 0
                                                    ? number_format($t['peso_kg'], 1, ',', '.') . ' kg'
                                                    : '<span class="sin-dato">—</span>'; ?>
                                            </td>
                                            <td class="centro">
                                                <div class="acciones-pedido">
                                                    <?php
                                                    $segmentos = [];
                                                    foreach ($entrega['lineas'] as $l) {
                                                        if ((int) $l['cajas_rotulo'] < 1) { continue; }
                                                        $segmentos[] = [
                                                            'n'        => (int) $l['cajas_rotulo'],
                                                            'producto' => $l['descripcion'] ?? ($l['sku'] ?? $l['plu']),
                                                        ];
                                                    }
                                                    $totalRotulos = (int) $t['cajas_rotulo'];
                                                    ?>
                                                    <button type="button" class="btn btn-chico btn-rotulo"
                                                            title="<?php echo $totalRotulos > 0
                                                                ? 'Reimprime los ' . $totalRotulos . ' rótulos del pedido.'
                                                                : 'Este pedido no tiene unidades que rotular. Se puede abrir el rótulo igual y ajustarlo a mano.'; ?>"
                                                            data-entrega="<?php echo htmlspecialchars($clave); ?>"
                                                            data-pv="<?php echo htmlspecialchars($entrega['punto_venta']); ?>"
                                                            data-ean-pv="<?php echo htmlspecialchars($entrega['ean_punto_venta'] ?? ''); ?>"
                                                            data-oc="<?php echo htmlspecialchars($entrega['orden_compra']); ?>"
                                                            data-cedi="<?php echo htmlspecialchars($entrega['cedi']); ?>"
                                                            data-desde="1"
                                                            data-cajas="<?php echo $totalRotulos; ?>"
                                                            data-total="<?php echo $totalRotulos; ?>"
                                                            data-segmentos="<?php echo htmlspecialchars(json_encode($segmentos, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="fa-solid fa-tags"></i>
                                                        Rótulos<?php echo $totalRotulos > 0 ? ' (' . $totalRotulos . ')' : ''; ?>
                                                    </button>

                                                    <a class="btn btn-chico"
                                                       href="<?php echo BASE_URL; ?>/modules/historial/controller_historial.php?accion=pdf&carga=<?php echo (int) $entrega['id_carga']; ?>&cedi=<?php echo urlencode($entrega['cedi']); ?>&oc=<?php echo urlencode($entrega['orden_compra']); ?>&pv=<?php echo urlencode($entrega['punto_venta']); ?>">
                                                        <i class="fa-solid fa-print"></i> Imprimir
                                                    </a>

                                                    <button type="button" class="btn btn-chico btn-restaurar"
                                                            title="Vuelve a dejar este pedido pendiente en Picking."
                                                            data-id-carga="<?php echo (int) $entrega['id_carga']; ?>"
                                                            data-cedi="<?php echo htmlspecialchars($entrega['cedi']); ?>"
                                                            data-oc="<?php echo htmlspecialchars($entrega['orden_compra']); ?>"
                                                            data-pv="<?php echo htmlspecialchars($entrega['punto_venta']); ?>">
                                                        <i class="fa-solid fa-rotate-left"></i> Restaurar
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- El detalle del pedido: sus productos, con el botón de rótulo de
                                             cada uno, igual que en Picking. -->
                                        <tr class="fila-detalle" hidden data-entrega="<?php echo htmlspecialchars($clave); ?>">
                                            <td colspan="10">
                                                <table class="tabla tabla-productos">
                                                    <thead>
                                                        <tr>
                                                            <th>SKU</th>
                                                            <th>Descripción</th>
                                                            <th class="centro">Empaque</th>
                                                            <th class="num">Unidades</th>
                                                            <th class="num">Cajas</th>
                                                            <th class="num">Saldos</th>
                                                            <th class="centro">Rótulo</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($entrega['lineas'] as $f): ?>
                                                            <?php $cajas = $f['sin_maestro'] ? null : (int) $f['cajas']; ?>
                                                            <tr class="<?php echo $f['sin_maestro'] ? 'fila-sin-maestro' : ''; ?>">
                                                                <td>
                                                                    <?php echo $f['sku'] !== null
                                                                        ? htmlspecialchars($f['sku'])
                                                                        : htmlspecialchars($f['plu']) . ' <span class="sin-dato">(PLU)</span>'; ?>
                                                                </td>
                                                                <td>
                                                                    <?php echo $f['descripcion'] !== null
                                                                        ? htmlspecialchars($f['descripcion'])
                                                                        : '<span class="sin-dato">sin descripción en el maestro</span>'; ?>
                                                                </td>
                                                                <td class="centro">
                                                                    <?php echo $f['presentacion'] !== null
                                                                        ? htmlspecialchars($f['presentacion'])
                                                                        : '<span class="sin-dato">—</span>'; ?>
                                                                </td>
                                                                <td class="num"><?php echo number_format((int) $f['unidades'], 0, ',', '.'); ?></td>
                                                                <?php if ($f['sin_maestro']): ?>
                                                                    <td class="num sin-dato" title="Falta cargar las unidades por caja de este PLU">—</td>
                                                                    <td class="num sin-dato">—</td>
                                                                <?php else: ?>
                                                                    <td class="num"><strong><?php echo $cajas; ?></strong></td>
                                                                    <td class="num"><?php echo (int) $f['saldos'] > 0 ? (int) $f['saldos'] : '<span class="sin-dato">0</span>'; ?></td>
                                                                <?php endif; ?>

                                                                <td class="centro">
                                                                    <?php
                                                                    $cajasRotulo = (int) $f['cajas_rotulo'];
                                                                    $desde = (int) $f['caja_desde'];
                                                                    $hasta = $desde + max($cajasRotulo, 1) - 1;
                                                                    $producto = $f['descripcion'] ?? ($f['sku'] ?? $f['plu']);
                                                                    ?>
                                                                    <button type="button" class="btn btn-chico btn-rotulo"
                                                                            title="<?php echo $cajasRotulo > 0
                                                                                ? 'Reimprime las cajas ' . $desde . ' a ' . $hasta . ' de ' . (int) $f['cajas_pedido'] . ' del pedido.'
                                                                                : 'Esta línea no tiene unidades que rotular. Se puede abrir el rótulo igual y ajustarlo a mano.'; ?>"
                                                                            data-entrega="<?php echo htmlspecialchars($clave); ?>"
                                                                            data-pv="<?php echo htmlspecialchars($entrega['punto_venta']); ?>"
                                                                            data-ean-pv="<?php echo htmlspecialchars($entrega['ean_punto_venta'] ?? ''); ?>"
                                                                            data-oc="<?php echo htmlspecialchars($entrega['orden_compra']); ?>"
                                                                            data-cedi="<?php echo htmlspecialchars($entrega['cedi']); ?>"
                                                                            data-desde="<?php echo $desde; ?>"
                                                                            data-cajas="<?php echo $cajasRotulo; ?>"
                                                                            data-total="<?php echo (int) $f['cajas_pedido']; ?>"
                                                                            data-saldos="<?php echo (int) $f['saldos']; ?>"
                                                                            data-producto="<?php echo htmlspecialchars($producto); ?>"
                                                                            data-descripcion="<?php echo htmlspecialchars($producto); ?>">
                                                                        <i class="fa-solid fa-tag"></i>
                                                                        Rótulo<?php echo $cajasRotulo > 0 ? ' (' . $cajasRotulo . ')' : ''; ?>
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endforeach; ?>

                <?php if ($resultado['paginas'] > 1): ?>
                    <div class="paginacion">
                        <?php if ($pagina > 1): ?>
                            <a class="btn btn-chico" href="?<?php echo $filtrosEnUrl ? $filtrosEnUrl . '&' : ''; ?>pagina=<?php echo $pagina - 1; ?>">
                                <i class="fa-solid fa-chevron-left"></i> Anterior
                            </a>
                        <?php endif; ?>
                        <span>Página <?php echo $pagina; ?> de <?php echo $resultado['paginas']; ?></span>
                        <?php if ($pagina < $resultado['paginas']): ?>
                            <a class="btn btn-chico" href="?<?php echo $filtrosEnUrl ? $filtrosEnUrl . '&' : ''; ?>pagina=<?php echo $pagina + 1; ?>">
                                Siguiente <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- BARRA DE ACCIONES MASIVAS: aparece cuando hay al menos un pedido tildado, igual que en
     Picking. Solo trae rótulos e imprimir —no hay "restaurar masivo": deshacer un despacho es
     algo que se hace de a uno, mirando bien cuál es. -->
<div class="barra-seleccion" id="barra-seleccion" hidden>
    <div class="barra-seleccion-info">
        <strong id="barra-conteo">0</strong> pedido(s) seleccionado(s)
        <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
    </div>

    <div class="barra-seleccion-acciones">
        <button type="button" class="btn btn-chico" id="btn-rotulos-masivo">
            <i class="fa-solid fa-tags"></i> Rótulos
        </button>

        <form action="<?php echo BASE_URL; ?>/modules/historial/controller_historial.php"
              method="POST" id="form-pdf-masivo">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="pdf_masivo">
            <div id="campos-pdf-masivo"></div>
            <button type="submit" class="btn btn-chico btn-primario">
                <i class="fa-solid fa-print"></i> Imprimir hojas
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../layouts/modal_rotulo.php'; ?>

<!-- MODAL: RESTAURAR (confirmación con los estilos del sistema, no un confirm() nativo — mismo
     patrón que el modal de despachar de Picking). -->
<div class="modal-fondo" id="modal-restaurar">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Restaurar pedido</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <div class="modal-cuerpo" id="restaurar-cuerpo"></div>
        <div class="modal-pie" id="restaurar-pie"></div>
    </div>
</div>

<script>
    const BASE_URL   = '<?php echo BASE_URL; ?>';
    const CSRF_TOKEN = '<?php echo htmlspecialchars(generarTokenCSRF(), ENT_QUOTES, 'UTF-8'); ?>';
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/desplegables.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/desplegables.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/historial/layouts/scripts_historial.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/historial/layouts/scripts_historial.js'); ?>"></script>

</body>
</html>
