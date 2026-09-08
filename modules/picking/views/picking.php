<?php
// modules/picking/views/picking.php
// El alistamiento, agrupado por ENTREGA: CEDI + orden de compra + punto de venta.
//
// Antes era una tabla plana de 348 filas donde la tienda, el CEDI y la orden se repetían en cada
// una. Ahora esos tres suben a la cabecera del grupo y cada fila queda solo con el producto, que
// es lo único que cambia entre una y otra. El pedido SAP también sube: es de la entrega, no del
// producto (ver guardarPedidoSap), así que es un campo por grupo y no el mismo número repetido.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_picking.php';
require_once __DIR__ . '/../../personal/model_personal.php';

requierePermiso('modulo_picking', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// Solo los ACTIVOS: a alguien que ya no está no se le puede asignar una entrega nueva. Los
// inactivos que ya tuvieran una asignada siguen apareciendo en su fila, porque el nombre sale de
// la propia asignación y no de esta lista.
$personalActivo = listarPersonal($pdo, true);

// Ya no depende de "la carga vigente": los archivos se acumulan (ver importarConsolidado en
// model_consolidados_import.php), así que acá se junta lo pendiente de TODAS las cargas activas a
// la vez, cada entrega con su propia fecha (decidido con el usuario el 2026-09-08).
$filtros = [
    'cedi'        => trim($_GET['cedi'] ?? ''),
    'punto_venta' => trim($_GET['punto_venta'] ?? ''),
    'busqueda'    => trim($_GET['q'] ?? ''),
];

$filas    = filasPicking($pdo, $filtros);
$resumen  = resumenPicking($filas);
$entregas = agruparPorEntrega($filas);
// La zona de despacho es el CEDI (decidido con el usuario el 2026-09-07): el sistema no guarda
// ninguna otra noción de zona, y la dirección del punto de venta no trae ciudad.
$porCedi  = agruparPorCedi($entregas);

// Sin filtrar: es lo que decide si hay ALGO pendiente en todo el sistema, no si el filtro actual
// encontró algo —esas son las dos cosas que antes distinguían "sin carga" de "sin resultados".
$cedisDisponibles  = cedisPendientes($pdo);
$hayPendientes     = !empty($cedisDisponibles);
$puntosDisponibles = puntosDeVenta($pdo, $filtros['cedi']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Picking · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Picking</h2>
                <div class="modulo-acciones">
                    <?php if (tienePermiso('modulo_consolidados')): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/modules/consolidados/views/consolidados.php">
                            <i class="fa-solid fa-boxes-stacked"></i> Ir a Consolidados
                        </a>
                    <?php endif; ?>
                    <?php if (tienePermiso('modulo_personal')): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/modules/personal/views/personal.php">
                            <i class="fa-solid fa-users-gear"></i> Gestionar personal
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($hayPendientes): ?>
                <div class="pastillas">
                    <span class="pastilla">Entregas <strong><?php echo number_format(count($entregas), 0, ',', '.'); ?></strong></span>
                    <span class="pastilla">Líneas <strong><?php echo number_format($resumen['lineas'], 0, ',', '.'); ?></strong></span>
                    <span class="pastilla">Unidades <strong><?php echo number_format($resumen['unidades'], 0, ',', '.'); ?></strong></span>
                    <span class="pastilla">Cajas <strong><?php echo number_format($resumen['cajas'], 0, ',', '.'); ?></strong></span>
                    <span class="pastilla">Saldos <strong><?php echo number_format($resumen['saldos'], 0, ',', '.'); ?></strong></span>
                    <?php if ($resumen['peso_kg'] > 0): ?>
                        <span class="pastilla" title="Peso bruto de lo que hay que alistar">
                            Peso <strong><?php echo number_format($resumen['peso_kg'], 0, ',', '.'); ?> kg</strong>
                        </span>
                    <?php endif; ?>
                    <?php if ($resumen['sin_maestro'] > 0): ?>
                        <span class="pastilla pastilla-alerta">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Sin maestro <strong><?php echo $resumen['sin_maestro']; ?></strong>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hayPendientes): ?>
                <form class="filtros" method="GET">
                    <div class="filtro">
                        <label for="f-cedi">CEDI</label>
                        <select name="cedi" id="f-cedi">
                            <option value="">Todos</option>
                            <?php foreach ($cedisDisponibles as $c): ?>
                                <option value="<?php echo htmlspecialchars($c['cedi']); ?>"
                                    <?php echo $filtros['cedi'] === $c['cedi'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['cedi']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filtro">
                        <label for="f-pv">Punto de venta</label>
                        <select name="punto_venta" id="f-pv">
                            <option value="">Todos</option>
                            <?php foreach ($puntosDisponibles as $pv): ?>
                                <option value="<?php echo htmlspecialchars($pv); ?>"
                                    <?php echo $filtros['punto_venta'] === $pv ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pv); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filtro" style="flex: 1;">
                        <label for="f-q">Buscar</label>
                        <input type="text" name="q" id="f-q" value="<?php echo htmlspecialchars($filtros['busqueda']); ?>"
                               placeholder="PLU, SKU, descripción o punto de venta">
                    </div>

                    <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                    <?php if (array_filter($filtros)): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/modules/picking/views/picking.php">Limpiar</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if (!$hayPendientes): ?>
                <div class="tabla-caja">
                    <p class="tabla-vacia">
                        No hay ningún pedido pendiente.<br>
                        Picking se arma con esos archivos: súbelos primero en <strong>Consolidados</strong>.
                    </p>
                </div>

            <?php elseif (empty($entregas)): ?>
                <div class="tabla-caja">
                    <p class="tabla-vacia">Ninguna entrega coincide con el filtro.</p>
                </div>

            <?php else: ?>
                <?php foreach ($porCedi as $cedi => $grupo): $tc = $grupo['totales']; ?>
                    <!-- Un desplegable por CEDI, que es la zona de despacho. En la cabecera van el
                         nombre del CEDI y cuántos pedidos lleva; adentro, UNA tabla con todos sus
                         pedidos en vez de una tarjeta suelta por cada uno.

                         Cerrado por omisión, igual que Consolidados: con la cabecera ya diciendo
                         el nombre y el conteo, se ve de un vistazo cuánto hay por zona sin abrir
                         nada, y se abre la zona que se va a despachar. -->
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
                                    <?php echo number_format($tc['cajas'], 0, ',', '.'); ?> cajas ·
                                    <?php echo number_format($tc['saldos'], 0, ',', '.'); ?> saldos
                                    <?php if ($tc['peso_kg'] > 0): ?>
                                        · <?php echo number_format($tc['peso_kg'], 0, ',', '.'); ?> kg
                                    <?php endif; ?>
                                    <?php if ($tc['sin_maestro'] > 0): ?>
                                        · <?php echo $tc['sin_maestro']; ?> sin maestro
                                    <?php endif; ?>
                                </div>
                            </div>
                        </summary>

                        <div class="tabla-caja">
                            <table class="tabla tabla-pedidos tabla-accion-fija">
                                <thead>
                                    <tr>
                                        <!-- Tilda o destilda todos los pedidos de ESTE CEDI. Uno por
                                             grupo y no uno global: se trabaja un CEDI a la vez, y un
                                             "todos" general seleccionaría 97 pedidos de tres zonas. -->
                                        <th style="width: 34px;" class="centro">
                                            <input type="checkbox" class="chk-todos"
                                                   title="Seleccionar todos los pedidos de este CEDI"
                                                   aria-label="Seleccionar todos los pedidos de este CEDI">
                                        </th>
                                        <th style="width: 34px;"><span class="sr-solo">Detalle</span></th>
                                        <th>Punto de venta</th>
                                        <th>O/C</th>
                                        <!-- Distingue pedidos que antes se habrían mezclado o
                                             reemplazado entre sí: ahora los archivos se acumulan
                                             (ver importarConsolidado), y esta es la columna que
                                             dice de cuál vino cada uno. -->
                                        <th>Fecha</th>
                                        <th class="num">Productos</th>
                                        <th class="num">Unidades</th>
                                        <th class="num">Cajas</th>
                                        <th class="num">Saldos</th>
                                        <th class="num">Peso</th>
                                        <th class="centro">Asignar personal</th>
                                        <th class="centro">Hoja</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grupo['entregas'] as $entrega): $t = $entrega['totales']; ?>
                                        <?php $clave = $entrega['clave']; ?>
                                        <tr class="fila-pedido<?php echo $t['sin_maestro'] > 0 ? ' fila-sin-maestro' : ''; ?>"
                                            data-entrega="<?php echo htmlspecialchars($clave); ?>">
                                            <td class="centro">
                                                <!-- Los datos de la entrega van acá y no solo en el botón
                                                     de asignar: la barra de acciones lee las casillas
                                                     tildadas y necesita identificar cada pedido sin
                                                     depender de qué otros botones tenga la fila.
                                                     data-carga es obligatorio ahora: cedi+oc+pv ya no
                                                     alcanzan para identificar la entrega si hay más de
                                                     una carga pendiente con esos mismos tres datos. -->
                                                <input type="checkbox" class="chk-pedido"
                                                       aria-label="Seleccionar <?php echo htmlspecialchars($entrega['punto_venta']); ?>"
                                                       data-carga="<?php echo (int) $entrega['id_carga']; ?>"
                                                       data-cedi="<?php echo htmlspecialchars($entrega['cedi']); ?>"
                                                       data-oc="<?php echo htmlspecialchars($entrega['orden_compra']); ?>"
                                                       data-pv="<?php echo htmlspecialchars($entrega['punto_venta']); ?>">
                                            </td>
                                            <td class="centro">
                                                <!-- Abre la fila de detalle de ESTE pedido. Es un botón y no un
                                                     clic en toda la fila: la fila lleva un campo de texto y un
                                                     enlace, y hacer toda la fila pulsable haría que tocarlos
                                                     desplegara el detalle sin querer. -->
                                                <button type="button" class="btn-detalle"
                                                        aria-expanded="false"
                                                        title="Ver los productos de este pedido">
                                                    <i class="fa-solid fa-chevron-right"></i>
                                                </button>
                                            </td>
                                            <td><?php echo htmlspecialchars($entrega['punto_venta']); ?></td>
                                            <td><?php echo htmlspecialchars($entrega['orden_compra']); ?></td>
                                            <td>
                                                <?php if ($entrega['fecha_carga']): ?>
                                                    <span title="<?php echo htmlspecialchars($entrega['nombre_archivo'] ?? ''); ?>">
                                                        <?php echo date('d/m', strtotime($entrega['fecha_carga'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="sin-dato">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="num"><?php echo $t['lineas']; ?></td>
                                            <td class="num"><?php echo number_format($t['unidades'], 0, ',', '.'); ?></td>
                                            <td class="num"><strong><?php echo number_format($t['cajas'], 0, ',', '.'); ?></strong></td>
                                            <td class="num"><?php echo $t['saldos'] > 0 ? number_format($t['saldos'], 0, ',', '.') : '<span class="sin-dato">0</span>'; ?></td>
                                            <td class="num">
                                                <?php echo $t['peso_kg'] > 0
                                                    ? number_format($t['peso_kg'], 1, ',', '.') . ' kg'
                                                    : '<span class="sin-dato">—</span>'; ?>
                                            </td>
                                            <td class="centro">
                                                <!-- La asignación es de la ENTREGA, no del producto: quien
                                                     alista arma la tienda completa. El botón muestra a quién
                                                     está asignada, o "Sin asignar" si todavía no. -->
                                                <button type="button"
                                                        class="btn btn-chico btn-asignar<?php echo empty($entrega['id_personal']) ? ' btn-sin-asignar' : ''; ?>"
                                                        data-entrega="<?php echo htmlspecialchars($clave); ?>"
                                                        data-carga="<?php echo (int) $entrega['id_carga']; ?>"
                                                        data-cedi="<?php echo htmlspecialchars($entrega['cedi']); ?>"
                                                        data-oc="<?php echo htmlspecialchars($entrega['orden_compra']); ?>"
                                                        data-pv="<?php echo htmlspecialchars($entrega['punto_venta']); ?>"
                                                        data-id-personal="<?php echo (int) ($entrega['id_personal'] ?? 0); ?>">
                                                    <i class="fa-solid <?php echo empty($entrega['id_personal']) ? 'fa-user-plus' : 'fa-user-check'; ?>"></i>
                                                    <span class="texto-asignado">
                                                        <?php echo !empty($entrega['personal_nombre'])
                                                            ? htmlspecialchars($entrega['personal_nombre'])
                                                            : 'Sin asignar'; ?>
                                                    </span>
                                                </button>
                                            </td>
                                            <td class="centro">
                                                <div class="acciones-pedido">
                                                    <?php
                                                    // Qué producto va en cada caja del pedido, en el mismo orden en
                                                    // que se numeran. El rótulo muestra el nombre del producto, y
                                                    // cuando se imprime el pedido entero cada etiqueta lleva el
                                                    // suyo — de ahí que haga falta pasarle la lista al modal y no
                                                    // un solo nombre.
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
                                                    <!-- Los rótulos de TODO el pedido, numerados corrido: CAJ 1 DE 8
                                                         … CAJ 8 DE 8. Es la forma normal de imprimirlos; el botón de
                                                         cada producto queda para reimprimir solo las suyas. -->
                                                    <button type="button" class="btn btn-chico btn-rotulo"
                                                            title="<?php echo $totalRotulos > 0
                                                                ? 'Genera los ' . $totalRotulos . ' rótulos del pedido, numerados 1 de ' . $totalRotulos . ' en adelante.'
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
                                                       href="<?php echo BASE_URL; ?>/modules/picking/controller_picking.php?accion=pdf&carga=<?php echo (int) $entrega['id_carga']; ?>&cedi=<?php echo urlencode($entrega['cedi']); ?>&oc=<?php echo urlencode($entrega['orden_compra']); ?>&pv=<?php echo urlencode($entrega['punto_venta']); ?>">
                                                        <i class="fa-solid fa-print"></i> Imprimir
                                                    </a>

                                                    <!-- No lleva su propio data-id-personal: el JS comprueba la
                                                         asignación leyendo el botón "Asignar personal" de ESTA
                                                         misma fila, que es la única fuente de verdad en pantalla.
                                                         Una copia acá quedaría desactualizada si se reasigna sin
                                                         recargar la página. -->
                                                    <button type="button" class="btn btn-chico btn-despachar"
                                                            title="Marca este pedido como despachado: desaparece de Picking y de Consolidados."
                                                            data-entrega="<?php echo htmlspecialchars($clave); ?>"
                                                            data-carga="<?php echo (int) $entrega['id_carga']; ?>"
                                                            data-cedi="<?php echo htmlspecialchars($entrega['cedi']); ?>"
                                                            data-oc="<?php echo htmlspecialchars($entrega['orden_compra']); ?>"
                                                            data-pv="<?php echo htmlspecialchars($entrega['punto_venta']); ?>">
                                                        <i class="fa-solid fa-truck-fast"></i> Despachar
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- El detalle del pedido: sus productos, con el botón de rótulo de
                                             cada uno. Va en su propia <tr> ocupando todo el ancho, para no
                                             tener que meter una tabla dentro de una celda de la fila de
                                             arriba y que las dos peleen por el ancho de las columnas. -->
                                        <tr class="fila-detalle" hidden data-entrega="<?php echo htmlspecialchars($clave); ?>">
                                            <td colspan="12">
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
                                                                    <!-- El botón NUNCA se deshabilita, aunque el sistema no haya
                                                                         podido calcular las cajas: el rótulo es editable, así que
                                                                         siempre se puede abrir, ajustar la cantidad y los datos, e
                                                                         imprimirlo. Antes estas filas quedaban sin salida.
                                                                         data-cajas va en 0 cuando no se pudo calcular; el modal lo
                                                                         interpreta y arranca en 1 avisando por qué. -->
                                                                    <?php
                                                                    // De qué caja a qué caja del PEDIDO son los rótulos de este
                                                                    // producto. La numeración es corrida sobre el pedido entero
                                                                    // (ver agruparPorEntrega), así que este botón reimprime un
                                                                    // tramo —"de la 3 a la 4 de 8"— y no vuelve a empezar en 1.
                                                                    //
                                                                    // Son cajas FÍSICAS (cajas_rotulo), no cajas completas: 8
                                                                    // unidades que no llenan la caja igual viajan en una.
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
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- BARRA DE ACCIONES MASIVAS
     Aparece pegada abajo cuando hay al menos un pedido tildado. Flotante y no encima de la tabla
     porque las 97 entregas obligan a scrollear: si estuviera arriba, al llegar al pedido veinte
     habría que volver hasta el principio para pulsar el botón. -->
<div class="barra-seleccion" id="barra-seleccion" hidden>
    <div class="barra-seleccion-info">
        <strong id="barra-conteo">0</strong> pedido(s) seleccionado(s)
        <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
    </div>

    <div class="barra-seleccion-acciones">
        <?php if (tienePermiso('modulo_personal')): ?>
            <button type="button" class="btn btn-chico" id="btn-asignar-masivo">
                <i class="fa-solid fa-user-plus"></i> Asignar personal
            </button>
        <?php endif; ?>

        <button type="button" class="btn btn-chico" id="btn-rotulos-masivo">
            <i class="fa-solid fa-tags"></i> Rótulos
        </button>

        <!-- La descarga va por un formulario y no por fetch: así el navegador la trata como una
             descarga normal, con su barra de progreso y su carpeta de destino. Los campos ocultos
             con los pedidos tildados los rellena scripts_picking.js justo antes de enviarlo. -->
        <form action="<?php echo BASE_URL; ?>/modules/picking/controller_picking.php"
              method="POST" id="form-pdf-masivo">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="pdf_masivo">
            <div id="campos-pdf-masivo"></div>
            <button type="submit" class="btn btn-chico btn-primario">
                <i class="fa-solid fa-print"></i> Imprimir hojas
            </button>
        </form>

        <button type="button" class="btn btn-chico btn-despachar-masivo" id="btn-despachar-masivo">
            <i class="fa-solid fa-truck-fast"></i> Despachar seleccionados
        </button>

    </div>
</div>

<?php include __DIR__ . '/../layouts/modal_rotulo.php'; ?>

<!-- MODAL: ASIGNAR PERSONAL A UNA ENTREGA -->
<div class="modal-fondo" id="modal-asignar">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Asignar personal</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>

        <div class="modal-cuerpo">
            <p class="asignar-entrega" id="asignar-entrega"></p>

            <?php if (empty($personalActivo)): ?>
                <div class="aviso aviso-atencion" style="margin-bottom: 0;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong>Todavía no hay personal cargado.</strong>
                        <?php if (tienePermiso('modulo_personal')): ?>
                            Agregalo en <a href="<?php echo BASE_URL; ?>/modules/personal/views/personal.php">Gestionar personal</a>
                            y después vas a poder asignar entregas.
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="campo" style="margin-bottom: 0;">
                    <label for="asignar-persona">¿Quién alista este pedido?</label>
                    <select id="asignar-persona">
                        <!-- El valor vacío es "quitar la asignación", y va primero para que
                             desasignar no obligue a buscar entre cincuenta nombres. -->
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($personalActivo as $p): ?>
                            <option value="<?php echo (int) $p['id_personal']; ?>">
                                <?php echo htmlspecialchars($p['nombre']); ?><?php
                                    echo $p['cargo'] !== null ? ' · ' . htmlspecialchars($p['cargo']) : ''; ?>
                                <?php if ((int) $p['entregas_asignadas'] > 0): ?>
                                    (<?php echo (int) $p['entregas_asignadas']; ?> asignadas)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="ayuda">
                        El número entre paréntesis es cuántas entregas tiene ya asignadas, para poder
                        repartir la carga sin tener que ir a contarlas a otra pantalla.
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cancelar</button>
            <?php if (!empty($personalActivo)): ?>
                <button type="button" class="btn btn-primario" id="btn-guardar-asignacion">Guardar</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL: DESPACHAR (confirmación, o el aviso de que falta personal)
     Un solo modal para las dos situaciones: el título, el cuerpo y los botones del pie los arma
     scripts_picking.js según haga falta, porque cuál de las dos toca depende de datos que solo
     se conocen en el momento del clic (qué pedido, si tiene personal asignado). -->
<div class="modal-fondo" id="modal-despachar">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2 id="despachar-titulo">Despachar</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <div class="modal-cuerpo" id="despachar-cuerpo"></div>
        <div class="modal-pie" id="despachar-pie"></div>
    </div>
</div>

<script>
    const BASE_URL   = '<?php echo BASE_URL; ?>';
    const CSRF_TOKEN = '<?php echo htmlspecialchars(generarTokenCSRF(), ENT_QUOTES, 'UTF-8'); ?>';
    const LOGO_URL   = '<?php echo BASE_URL; ?>/assets/img/monterojo.png';
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/desplegables.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/desplegables.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/picking/layouts/scripts_picking.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/picking/layouts/scripts_picking.js'); ?>"></script>

</body>
</html>
