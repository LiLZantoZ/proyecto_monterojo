<?php
// modules/consolidado_mr/views/consolidado_mr.php
// El Consolidado MR de SAP, UNA FILA POR FACTURA (2026-10-02): el número de factura, el pedido y la
// orden de compra adelante, el cliente y el valor neto, y con qué guía o transportador salió. La
// segunda pestaña, "Facturas de contado" (2026-10-05, antes "No pagadas"), es la MISMA tabla con
// solo las facturas registradas de contado (CPag 0010 del Excel, la comparativa o a mano) y su
// columna "De contado" Sí/No. La tercera, "Facturas anuladas" (2026-10-05), muestra TODAS con su
// columna "Anulada": las anuladas (X en "Anulado.") se ven solo ahí. Ver model_consolidado_mr.php.
//
// Usa la hoja de estilos de Estado de pedidos (misma grilla, mismos totales, misma paginación y las
// mismas pestañas) para que las tablas de los dos módulos se vean iguales; lo propio va en
// consolidado_mr.css.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_consolidado_mr.php';

requierePermiso('modulo_consolidado_mr', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// Importar, vaciar, poner transportador y marcar pagos solo con este permiso (2026-09-28): el rol
// Visitante solo consulta.
$puedeEditar = tienePermiso('consolidado_mr_editar');

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// 'no_pagadas' era la pestaña de antes: un enlace guardado cae en la de contado.
$pestana = pestanaConsolidadoMr($_GET['pestana'] ?? '');

// Los números de las pestañas, sin filtros: facturas no anuladas, de contado y anuladas.
$conteosPestanas = conteosPestanasMr($pdo);
$conteoFacturas  = $conteosPestanas['activas'];
$resumenContado = resumenContadoMr($pdo);

// Formatos: miles con punto y decimales con coma, como en el resto del sistema.
$mil      = fn($n) => number_format((float) $n, 0, ',', '.');
$plata    = fn($v) => '$' . number_format((float) $v, 2, ',', '.');
$faltante = '<span class="dato-faltante">—</span>';
$celda    = fn($v) => ($v === null || $v === '') ? $faltante : $esc($v);
$fecha    = function ($v) use ($faltante) {
    $t = $v ? strtotime($v) : false;
    return $t ? date('d/m/Y', $t) : $faltante;
};

// Las tres pestañas arman la misma lista: Facturas y De contado sin las anuladas (De contado, además,
// solo con las registradas); Anuladas, con todas.
if (in_array($pestana, ['facturas', 'contado', 'anuladas'], true)) {
    // Los filtros y la lista salen del modelo (2026-10-06): son los mismos que usa "Exportar Excel".
    $filtros = filtrosConsolidadoMr($_GET);
    [
        'filtradas' => $filtradas, 'conteoAnuladas' => $conteoAnuladas, 'conteoContado' => $conteoContado, 'resumen' => $resumen,
        'sinTransportador' => $sinTransportador, 'transportadores' => $transportadores, 'sinResponsable' => $sinResponsable,
    ] = listaConsolidadoMr($pdo, $pestana, $filtros);

    $totales          = totalesDeListaMr($filtradas);
    $totalFilas       = $totales['facturas'];
    $totalPaginas     = max(1, (int) ceil($totalFilas / CONSOLIDADO_MR_POR_PAGINA));
    $pagina           = min(max(1, (int) ($_GET['pagina'] ?? 1)), $totalPaginas);
    $facturas         = array_slice($filtradas, ($pagina - 1) * CONSOLIDADO_MR_POR_PAGINA, CONSOLIDADO_MR_POR_PAGINA);
    unset($filtradas);
    $opciones = $puedeEditar ? opcionesTransportadoraMr($pdo) : [];

    // Las URL de esta pantalla: con los filtros que se pasen y, fuera de Facturas, ?pestana=…
    $basePestana = $pestana !== 'facturas' ? ['pestana' => $pestana] : [];
    $urlMr = fn(array $p) => BASE_URL . '/consolidado-mr' . (($q = http_build_query($basePestana + array_filter($p))) !== '' ? '?' . $q : '');
    // La URL de una pastilla del semáforo: conserva los demás filtros y vuelve a la página 1.
    $urlGrupo   = fn($g) => $urlMr(['grupo' => $g] + $filtros);
    // Las pastillas de la pestaña de contado: Todas / De contado / Pasaron a "No".
    $urlContado = fn($v) => $urlMr(['contado' => $v] + $filtros);
    // Las de la pestaña de anuladas: Todas / Anuladas / No anuladas.
    $urlAnulada = fn($v) => $urlMr(['anulada' => $v] + $filtros);
    $claseGrupo = fn($g) => $filtros['grupo'] === $g ? ' activa' : '';
    // La pastilla "Sin transportador": prende y apaga el filtro, con los demás filtros puestos.
    $urlSinTransp = $urlMr(['transportador' => $filtros['transportador'] === MR_SIN_TRANSPORTADOR ? '' : MR_SIN_TRANSPORTADOR] + $filtros);
    $colorGrupo = ['entregado' => 'estado-verde', 'en_camino' => 'estado-azul', 'pendiente' => 'estado-ambar', 'otro' => 'estado-gris', 'sin_estado' => 'estado-gris'];

    $clientes     = valoresConsolidadoMr($pdo, 'nombre_cliente');
    $poblaciones  = valoresConsolidadoMr($pdo, 'poblacion');
    $filtrosEnUrl = http_build_query(array_filter($filtros));
    $hayDatos     = $filtrosEnUrl !== '' || $totalFilas > 0;
    // Con la tabla vacía: los envíos de las transportadoras que ya están guardados esperando (2026-10-05).
    $enviosEsperando = $hayDatos ? 0 : (int) resumenParaEliminarSeguimiento($pdo)['transportadoras'];
    $urlPagina    = fn($p) => $urlMr($filtros + ['pagina' => $p]);
    $unidad       = 'facturas';
}

$desdeFila = $totalFilas === 0 ? 0 : ($pagina - 1) * CONSOLIDADO_MR_POR_PAGINA + 1;
$hastaFila = min($pagina * CONSOLIDADO_MR_POR_PAGINA, $totalFilas);
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
                    <?php // EXPORTAR EXCEL (2026-10-06): la lista de esta pestaña con sus filtros, sin paginar. ?>
                    <a class="btn" href="<?php echo $esc(BASE_URL . '/consolidado-mr/acciones?' . http_build_query(['accion' => 'exportar'] + $basePestana + array_filter($filtros))); ?>">
                        <i class="fa-solid fa-file-excel"></i> Exportar Excel
                    </a>
                    <?php if ($puedeEditar && $pestana === 'facturas'): ?>
                        <?php if ($totalFilas > 0 || $filtrosEnUrl !== ''): ?>
                            <form action="<?php echo BASE_URL; ?>/consolidado-mr/acciones" method="POST"
                                  data-confirmar="Se borra TODO lo cargado en el Consolidado MR. Después se puede volver a subir el Excel." data-titulo="¿Vaciar el Consolidado MR?" data-aceptar="Sí, vaciar" data-peligro>
                                <?php campoCSRF(); ?>
                                <input type="hidden" name="accion" value="vaciar">
                                <button type="submit" class="btn"><i class="fa-solid fa-trash-can"></i> Vaciar</button>
                            </form>
                        <?php endif; ?>
                        <?php // Los reportes de las transportadoras, con su desplegable (2026-10-05). ?>
                        <button type="button" class="btn btn-peligro" data-abrir="modal-actualizar-transp">
                            <i class="fa-solid fa-truck"></i> Actualizar transportadoras
                        </button>
                        <button type="button" class="btn btn-primario" data-abrir="modal-importar">
                            <i class="fa-solid fa-file-arrow-up"></i> Importar Consolidado MR
                        </button>
                    <?php elseif ($puedeEditar && $pestana === 'contado'): ?>
                        <button type="button" class="btn" data-abrir="modal-subir-contado">
                            <i class="fa-solid fa-file-arrow-up"></i> Subir Consolidado MR
                        </button>
                        <?php // El archivo con los números de las facturas de contado (2026-10-05). ?>
                        <button type="button" class="btn btn-primario" data-abrir="modal-comparativa">
                            <i class="fa-solid fa-code-compare"></i> Subir comparativa
                        </button>
                    <?php elseif ($puedeEditar): ?>
                        <button type="button" class="btn btn-primario" data-abrir="modal-importar">
                            <i class="fa-solid fa-file-arrow-up"></i> Importar Consolidado MR
                        </button>
                    <?php endif; ?>
                </div>
            </header>

            <nav class="pestanas-seg" aria-label="Pestañas del Consolidado MR">
                <a class="pestana-seg<?php echo $pestana === 'facturas' ? ' activa' : ''; ?>" href="<?php echo BASE_URL; ?>/consolidado-mr">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <span>Facturas</span>
                    <span class="pestana-conteo"><?php echo $mil($conteoFacturas); ?></span>
                </a>
                <a class="pestana-seg<?php echo $pestana === 'contado' ? ' activa' : ''; ?>" href="<?php echo BASE_URL; ?>/consolidado-mr?pestana=contado">
                    <i class="fa-solid fa-money-bill-wave"></i>
                    <span>Facturas de contado</span>
                    <span class="pestana-conteo" title="De contado hoy"><?php echo $mil($resumenContado['si']); ?></span>
                </a>
                <a class="pestana-seg<?php echo $pestana === 'anuladas' ? ' activa' : ''; ?>" href="<?php echo BASE_URL; ?>/consolidado-mr?pestana=anuladas">
                    <i class="fa-solid fa-ban"></i>
                    <span>Facturas anuladas</span>
                    <span class="pestana-conteo" title="Anuladas"><?php echo $mil($conteosPestanas['anuladas']); ?></span>
                </a>
            </nav>

<?php if ($pestana === 'contado'): ?>
            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Las facturas <strong>de contado</strong> se marcan solas al importar el Consolidado MR: son las que traen
                    <strong>CPag 0010</strong>. También se pueden marcar con <strong>“Subir comparativa”</strong> o a mano (lo marcado a
                    mano no lo cambia un archivo nuevo). Si una pasa a <strong>No</strong>, sigue en esta lista para que quede el registro.
                </div>
            </div>
            <?php if ($conteoContado['todas'] > 0 || $conteoContado['fuera'] > 0 || $filtros['contado'] !== ''): ?>
                <div class="pastillas pastillas-filtro">
                    <a class="pastilla<?php echo $filtros['contado'] === '' ? ' activa' : ''; ?>" href="<?php echo $esc($urlContado('')); ?>">Registradas <strong><?php echo $mil($conteoContado['todas']); ?></strong></a>
                    <a class="pastilla estado-verde<?php echo $filtros['contado'] === 'si' ? ' activa' : ''; ?>" href="<?php echo $esc($urlContado('si')); ?>">De contado <strong><?php echo $mil($conteoContado['si']); ?></strong></a>
                    <a class="pastilla estado-gris<?php echo $filtros['contado'] === 'no' ? ' activa' : ''; ?>" href="<?php echo $esc($urlContado('no')); ?>" title="Fueron de contado y después se marcaron como No">Pasaron a No <strong><?php echo $mil($conteoContado['no']); ?></strong></a>
                    <a class="pastilla estado-ambar<?php echo $filtros['contado'] === 'fuera' ? ' activa' : ''; ?>" href="<?php echo $esc($urlContado('fuera')); ?>" title="Todas las facturas que NO son de contado (nunca lo fueron o pasaron a No), para marcarlas">No de contado <strong><?php echo $mil($conteoContado['fuera']); ?></strong></a>
                </div>
            <?php endif; ?>
<?php endif; ?>
<?php if ($pestana === 'anuladas'): ?>
            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Las facturas con una <strong>X en "Anulado."</strong> (o "An.") del Consolidado MR están <strong>anuladas</strong>:
                    se ven <strong>solo acá</strong>. Esta pestaña muestra también las demás, que además están en Facturas.
                </div>
            </div>
            <?php if ($conteoAnuladas['todas'] > 0 || $filtros['anulada'] !== ''): ?>
                <div class="pastillas pastillas-filtro">
                    <a class="pastilla<?php echo $filtros['anulada'] === '' ? ' activa' : ''; ?>" href="<?php echo $esc($urlAnulada('')); ?>">Todas <strong><?php echo $mil($conteoAnuladas['todas']); ?></strong></a>
                    <a class="pastilla estado-rojo<?php echo $filtros['anulada'] === 'si' ? ' activa' : ''; ?>" href="<?php echo $esc($urlAnulada('si')); ?>">Anuladas <strong><?php echo $mil($conteoAnuladas['si']); ?></strong></a>
                </div>
            <?php endif; ?>
<?php endif; ?>

            <?php if ($hayDatos): ?>
                <?php // LAS PASTILLAS DE FACTURAS (2026-10-06): solo en esa pestaña. De contado y Anuladas
                      // tienen las suyas (arriba), por pedido del usuario. ?>
                <?php if ($pestana === 'facturas'): ?>
                <!-- EL SEMÁFORO (2026-10-05): las pastillas de Estado de pedidos, que filtran la tabla
                     por el estado de la guía de cada factura en el consolidado de las transportadoras. -->
                <div class="pastillas pastillas-filtro">
                    <a class="pastilla<?php echo $claseGrupo(''); ?>" href="<?php echo $esc($urlGrupo('')); ?>">Facturas <strong><?php echo $mil($resumen['total']); ?></strong></a>
                    <?php foreach (['entregado' => 'Entregados', 'en_camino' => 'En camino', 'pendiente' => 'Pendientes', 'otro' => 'Otros', 'sin_estado' => 'Sin estado'] as $g => $texto): ?>
                        <?php if ($resumen[$g] > 0 || $filtros['grupo'] === $g || in_array($g, ['entregado', 'en_camino', 'pendiente'], true)): ?>
                            <a class="pastilla pastilla-estado <?php echo $colorGrupo[$g] . $claseGrupo($g); ?>" href="<?php echo $esc($urlGrupo($g)); ?>"
                               <?php echo $g === 'sin_estado' ? 'title="Facturas que no aparecen en el consolidado de las transportadoras"' : ''; ?>>
                                <?php echo $texto; ?> <strong><?php echo $mil($resumen[$g]); ?></strong>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="pastillas">
                    <?php if ($filtros['grupo'] !== '' || $filtros['responsable'] !== '' || $filtros['transportador'] !== ''): ?>
                        <span class="pastilla">Facturas <strong><?php echo $mil($totales['facturas']); ?></strong></span>
                    <?php endif; ?>
                    <?php // LA ALERTA: abre la lista de las facturas que no tienen responsable de empaque. ?>
                    <button type="button" class="pastilla pastilla-alerta<?php echo $sinResponsable ? '' : ' sin-alerta'; ?>" data-abrir="modal-sin-responsable"
                            title="Ver las facturas que no tienen responsable de empaque">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Sin responsable de empaque <strong><?php echo $mil(count($sinResponsable)); ?></strong>
                    </button>
                    <span class="pastilla">Clientes <strong><?php echo $mil($totales['clientes']); ?></strong></span>
                    <span class="pastilla">Valor neto <strong><?php echo $esc($plata($totales['valor'])); ?></strong></span>
                    <?php if ($pestana === 'facturas'): ?>
                        <a class="pastilla pastilla-filtro-transp estado-verde" href="<?php echo BASE_URL; ?>/consolidado-mr?pestana=contado&amp;contado=si"
                           title="Ver las facturas de contado">De contado <strong><?php echo $mil($conteoContado['si']); ?></strong></a>
                    <?php endif; ?>
                    <?php // Filtra: muestra solo las que no tienen transportador (y otro clic lo quita). ?>
                    <a class="pastilla pastilla-filtro-transp<?php echo $sinTransportador ? ' estado-ambar' : ' estado-verde'; ?><?php echo $filtros['transportador'] === MR_SIN_TRANSPORTADOR ? ' activa' : ''; ?>"
                       href="<?php echo $esc($urlSinTransp); ?>"
                       title="<?php echo $filtros['transportador'] === MR_SIN_TRANSPORTADOR ? 'Quitar el filtro' : 'Ver solo las facturas que no tienen transportador (ni del reporte de la transportadora ni puesto a mano)'; ?>">
                        Sin transportador <strong><?php echo $mil($sinTransportador); ?></strong>
                    </a>
                </div>
                <?php endif; ?>

                <form method="GET" class="filtros">
                    <?php // El grupo del semáforo viaja escondido para no perderlo al filtrar por texto o fecha. ?>
                    <?php if ($filtros['grupo'] !== ''): ?>
                        <input type="hidden" name="grupo" value="<?php echo $esc($filtros['grupo']); ?>">
                    <?php endif; ?>
                    <?php if ($filtros['responsable'] !== ''): ?>
                        <input type="hidden" name="responsable" value="sin">
                    <?php endif; ?>
                    <?php if ($pestana !== 'facturas'): ?>
                        <input type="hidden" name="pestana" value="<?php echo $esc($pestana); ?>">
                    <?php endif; ?>
                    <?php if ($filtros['anulada'] !== ''): ?>
                        <input type="hidden" name="anulada" value="<?php echo $esc($filtros['anulada']); ?>">
                    <?php endif; ?>
                    <?php if ($filtros['contado'] !== ''): ?>
                        <input type="hidden" name="contado" value="<?php echo $esc($filtros['contado']); ?>">
                    <?php endif; ?>
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
                        <label for="f-transportador">Transportador</label>
                        <select name="transportador" id="f-transportador">
                            <option value="">Todos</option>
                            <option value="<?php echo MR_SIN_TRANSPORTADOR; ?>" <?php echo $filtros['transportador'] === MR_SIN_TRANSPORTADOR ? 'selected' : ''; ?>>Sin transportador</option>
                            <?php $estaElegido = in_array($filtros['transportador'], ['', MR_SIN_TRANSPORTADOR], true); ?>
                            <?php foreach ($transportadores as $t): $esEste = mb_strtoupper($t, 'UTF-8') === mb_strtoupper($filtros['transportador'], 'UTF-8'); $estaElegido = $estaElegido || $esEste; ?>
                                <option value="<?php echo $esc($t); ?>" <?php echo $esEste ? 'selected' : ''; ?>><?php echo $esc($t); ?></option>
                            <?php endforeach; ?>
                            <?php if (!$estaElegido): ?>
                                <option value="<?php echo $esc($filtros['transportador']); ?>" selected><?php echo $esc($filtros['transportador']); ?></option>
                            <?php endif; ?>
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
                               placeholder="Factura, pedido, orden de compra, cliente, SKU… Para varios: NU04169821, NU04169822"
                               title="Se puede buscar más de uno a la vez, separados por coma. Busca en la factura, el pedido, la orden de compra, el solicitante, el cliente, la ciudad y los productos de la factura (SKU o nombre).">
                    </div>
                    <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                    <?php if ($filtrosEnUrl !== ''): ?>
                        <a class="btn" href="<?php echo $esc($urlMr([])); ?>">Limpiar</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if (!$facturas): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div>
                        <?php echo $filtrosEnUrl !== ''
                            ? 'No hay facturas con esos filtros. Probá con otros o limpialos.'
                            : ($pestana === 'contado'
                                ? 'Todavía no hay facturas de contado. Se marcan solas al importar el Consolidado MR (las de CPag 0010); también con “Subir comparativa” o a mano, con la pastilla “No de contado” o en la columna “De contado” de la pestaña Facturas.'
                            : ($pestana === 'anuladas' && $conteosPestanas['todas'] > 0
                                ? 'No hay facturas anuladas.'
                            : ($puedeEditar
                                ? 'Todavía no hay nada cargado. Subí el Excel del <strong>Consolidado MR</strong> con “Importar Consolidado MR”.'
                                : 'Todavía no hay nada cargado en el Consolidado MR.'))); ?>
                        <?php if ($pestana === 'facturas' && $filtrosEnUrl === '' && $enviosEsperando > 0): ?>
                            Ya hay <strong><?php echo $mil($enviosEsperando); ?> envío(s) de las transportadoras</strong> guardados:
                            se van a ver al lado de cada factura apenas subas el Consolidado MR.
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="tabla-caja" style="margin-top: 14px;">
                    <table class="tabla tabla-seguimiento tabla-sap tabla-mr" data-seleccion="facturas">
                        <thead>
                            <tr>
                                <?php if ($puedeEditar): ?>
                                    <th class="centro col-chk">
                                        <input type="checkbox" class="chk-todas" title="Seleccionar todas las facturas de esta página"
                                               aria-label="Seleccionar todas las facturas de esta página">
                                    </th>
                                <?php endif; ?>
                                <th>Factura</th>
                                <th>Pedido</th>
                                <th>Orden de compra</th>
                                <th class="num">Fecha factura</th>
                                <th>Solicitud</th>
                                <th>Cliente</th>
                                <th>Ciudad</th>
                                <th class="num">Valor neto</th>
                                <?php if ($pestana === 'anuladas'): ?><th class="centro">Anulada</th><?php endif; ?>
                                <th class="centro">De contado</th>
                                <th>Guía</th>
                                <th>Transportador</th>
                                <?php // Del consolidado de las transportadoras (Estado de pedidos), 2026-10-05. ?>
                                <th>Estado</th>
                                <th class="num">Fecha entrega</th>
                                <th>Responsable de empaque</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facturas as $f): ?>
                                <?php
                                $envio  = $f['envio'];
                                $numero = $f['referencia'] !== null && $f['referencia'] !== '' ? $f['referencia'] : $f['factura'];
                                ?>
                                <tr data-factura="<?php echo $esc($f['factura']); ?>">
                                    <?php if ($puedeEditar): ?>
                                        <td class="centro">
                                            <input type="checkbox" class="chk-fila" value="<?php echo $esc($f['factura']); ?>"
                                                   aria-label="Seleccionar la factura <?php echo $esc($numero); ?>">
                                        </td>
                                    <?php endif; ?>
                                    <td><strong><?php echo $esc($numero); ?></strong></td>
                                    <td><?php echo $celda($f['doc_ventas']); ?></td>
                                    <td><?php echo $celda($f['pedido_cliente']); ?></td>
                                    <td class="num"><?php echo $fecha($f['fecha_factura']); ?></td>
                                    <td><?php echo $celda($f['solicitante']); ?></td>
                                    <td class="celda-cliente"><?php echo $celda($f['nombre_cliente']); ?></td>
                                    <?php // La ciudad de destino que informa la transportadora; si no hay, la de SAP. ?>
                                    <td<?php echo !empty($envio['ciudad_destino']) ? ' title="Ciudad de destino según la transportadora. En SAP: ' . $esc($f['poblacion'] ?? '—') . '"' : ''; ?>><?php echo $celda($envio['ciudad_destino'] ?? $f['poblacion']); ?></td>
                                    <td class="num celda-valor"><?php echo $f['valor_neto'] !== null ? $esc($plata($f['valor_neto'])) : $faltante; ?></td>
                                    <?php
                                    // DE CONTADO (2026-10-05): Sí/No, editable; abajo, de dónde salió el último cambio.
                                    $c        = $f['contado'];
                                    $esContado = esDeContadoMr($f);
                                    $quien    = $c ? origenContadoMr($c) : '';
                                    ?>
                                    <?php if ($pestana === 'anuladas'): ?>
                                        <td class="centro"><span class="chip-estado <?php echo esAnuladaMr($f) ? 'estado-rojo' : 'estado-gris'; ?>"><?php echo esAnuladaMr($f) ? 'Sí' : 'No'; ?></span></td>
                                    <?php endif; ?>
                                    <td class="centro celda-contado" <?php echo $quien !== '' ? 'title="' . $esc($quien) . '"' : ''; ?>>
                                        <?php if ($puedeEditar): ?>
                                            <select class="sel-contado<?php echo $esContado ? ' es-si' : ''; ?>" data-factura="<?php echo $esc($f['factura']); ?>"
                                                    data-actual="<?php echo $esContado ? '1' : '0'; ?>" aria-label="De contado, factura <?php echo $esc($numero); ?>">
                                                <option value="0" <?php echo $esContado ? '' : 'selected'; ?>>No</option>
                                                <option value="1" <?php echo $esContado ? 'selected' : ''; ?>>Sí</option>
                                            </select>
                                        <?php else: ?>
                                            <span class="chip-estado <?php echo $esContado ? 'estado-verde' : 'estado-gris'; ?>"><?php echo $esContado ? 'Sí' : 'No'; ?></span>
                                        <?php endif; ?>
                                        <?php if ($pestana === 'contado' && $quien !== ''): ?>
                                            <div class="nota-contado"><?php echo $esc($quien); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($envio['origen'] === 'guia'): ?>
                                            <span class="guia-mr" title="<?php echo $esc($envio['estado'] ?? ''); ?>"><?php echo $esc($envio['guia']); ?></span>
                                        <?php else: ?>
                                            <?php echo $faltante; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="celda-transportador">
                                        <?php if ($envio['origen'] === 'guia' && empty($envio['editable'])): ?>
                                            <span class="chip-estado estado-azul"><?php echo $esc($envio['transportadora']); ?></span>
                                        <?php elseif ($puedeEditar): ?>
                                            <?php $actual = (string) ($envio['transportadora'] ?? ''); ?>
                                            <select class="sel-transportador" data-factura="<?php echo $esc($f['factura']); ?>"
                                                    data-actual="<?php echo $esc($actual); ?>"
                                                    aria-label="Transportador de la factura <?php echo $esc($numero); ?>">
                                                <option value="">— Sin asignar —</option>
                                                <?php $esta = false; foreach ($opciones as $o): $esta = $esta || $o === $actual; ?>
                                                    <option value="<?php echo $esc($o); ?>" <?php echo $o === $actual ? 'selected' : ''; ?>><?php echo $esc($o); ?></option>
                                                <?php endforeach; ?>
                                                <?php if ($actual !== '' && !$esta): ?>
                                                    <option value="<?php echo $esc($actual); ?>" selected><?php echo $esc($actual); ?></option>
                                                <?php endif; ?>
                                                <option value="__otro__">Otro…</option>
                                            </select>
                                            <?php if ($envio['origen'] === 'reporte'): ?>
                                                <span class="nota-transportador" title="Sale del consolidado de las transportadoras, que no trae guía para esta factura">del reporte</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo $celda($envio['transportadora']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="celda-estado-mr">
                                        <?php if ($puedeEditar): ?>
                                            <?php // Editable a mano (2026-10-07): lo puesto acá manda sobre el reporte; vacío vuelve a lo del reporte. ?>
                                            <?php $esManual = !empty($envio['estado_manual']); $estadoActual = $esManual ? (string) $envio['estado'] : ''; ?>
                                            <select class="sel-estado-mr <?php echo $colorGrupo[$envio['grupo']]; ?>" data-factura="<?php echo $esc($f['factura']); ?>"
                                                    data-actual="<?php echo $esc($estadoActual); ?>" aria-label="Estado de la factura <?php echo $esc($numero); ?>"
                                                    title="<?php echo $envio['estado_reporte'] ? 'Según la transportadora: ' . $esc($envio['estado_reporte']) : 'La transportadora no informa estado'; ?>">
                                                <option value=""><?php echo $envio['estado_reporte'] ? $esc($envio['estado_reporte']) : '— Sin estado —'; ?></option>
                                                <?php $estaEnLista = false; foreach (MR_ESTADOS_MANUALES as $o): $estaEnLista = $estaEnLista || $o === $estadoActual; ?>
                                                    <option value="<?php echo $esc($o); ?>" <?php echo $esManual && $o === $estadoActual ? 'selected' : ''; ?>><?php echo $esc($o); ?></option>
                                                <?php endforeach; ?>
                                                <?php if ($esManual && !$estaEnLista): ?>
                                                    <option value="<?php echo $esc($estadoActual); ?>" selected><?php echo $esc($estadoActual); ?></option>
                                                <?php endif; ?>
                                                <option value="__otro__">Otro…</option>
                                            </select>
                                            <?php if ($esManual): ?><span class="nota-transportador nota-mano">a mano</span><?php endif; ?>
                                        <?php elseif (($envio['estado'] ?? '') !== ''): ?>
                                            <span class="chip-estado <?php echo $colorGrupo[$envio['grupo']]; ?>"
                                                  <?php echo $envio['fecha_estado'] ? 'title="Desde el ' . $esc(date('d/m/Y', strtotime($envio['fecha_estado']))) . '"' : ''; ?>><?php echo $esc($envio['estado']); ?></span>
                                        <?php else: ?>
                                            <?php echo $faltante; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num celda-fecha-mr">
                                        <?php if ($puedeEditar): ?>
                                            <?php $fechaValor = !empty($envio['fecha_entrega']) && strtotime($envio['fecha_entrega']) ? date('Y-m-d', strtotime($envio['fecha_entrega'])) : ''; ?>
                                            <input type="date" class="fecha-entrega-mr" data-factura="<?php echo $esc($f['factura']); ?>"
                                                   data-actual="<?php echo $esc(!empty($envio['fecha_manual']) ? $fechaValor : ''); ?>"
                                                   data-reporte="<?php echo $esc(!empty($envio['fecha_entrega_reporte']) && strtotime($envio['fecha_entrega_reporte']) ? date('Y-m-d', strtotime($envio['fecha_entrega_reporte'])) : ''); ?>"
                                                   value="<?php echo $esc($fechaValor); ?>" aria-label="Fecha de entrega de la factura <?php echo $esc($numero); ?>">
                                            <?php if (!empty($envio['fecha_manual'])): ?><span class="nota-transportador nota-mano">a mano</span><?php endif; ?>
                                        <?php else: ?>
                                            <?php echo $fecha($envio['fecha_entrega']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($f['responsable'])): ?>
                                            <span title="Cédula <?php echo $esc($f['responsable']['documento']); ?> · enlazada el <?php echo $esc(date('d/m/Y H:i', strtotime($f['responsable']['fecha_enlace']))); ?>"><?php echo $esc($f['responsable']['nombre']); ?></span>
                                        <?php else: ?>
                                            <span class="sin-responsable" title="Ninguna persona enlazada: hacelo en Enlazar facturas">Sin responsable</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fila-total">
                                <td colspan="<?php echo $puedeEditar ? 8 : 7; ?>" class="total-etiqueta">
                                    Total
                                    <span class="total-nota">(<?php echo $mil($totalFilas); ?> <?php echo $totalFilas === 1 ? 'factura' : 'facturas'; ?>, todo el filtro)</span>
                                </td>
                                <td class="num total-monto"><?php echo $esc($plata($totales['valor'])); ?></td>
                                <td colspan="<?php echo $pestana === 'anuladas' ? 7 : 6; ?>"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($totalFilas > 0): ?>
                <!-- PAGINACIÓN: mismo estilo buscador que Estado de pedidos (1 2 3 … 9 … última). -->
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

        </div>
    </div>
</div>

<?php if ($hayDatos): ?>
<!-- LA ALERTA: las facturas sin responsable de empaque (2026-10-05). Se ven todas en la lista (hasta
     200; las demás, con "Ver solo estas en la tabla") y cada una lleva a Enlazar facturas. -->
<div class="modal-fondo" id="modal-sin-responsable">
    <div class="modal-caja modal-ancha">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-triangle-exclamation"></i> Facturas sin responsable de empaque</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <div class="modal-cuerpo">
            <?php if (!$sinResponsable): ?>
                <div class="aviso aviso-exito"><i class="fa-solid fa-circle-check"></i><div>Todas las facturas tienen responsable de empaque.</div></div>
            <?php else: ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong><?php echo $mil(count($sinResponsable)); ?> factura(s)</strong> no tienen a nadie enlazado como
                        responsable de alistarlas y despacharlas.
                        <?php if (tienePermiso('modulo_enlazar_facturas')): ?>Enlazalas en <strong>Enlazar facturas</strong>.<?php endif; ?>
                    </div>
                </div>
                <div class="lista-sin-responsable">
                    <table class="tabla tabla-seguimiento">
                        <thead><tr><th>Factura</th><th class="num">Fecha</th><th>Cliente</th><th>Ciudad</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($sinResponsable, 0, 200) as $s): $num = $s['referencia'] ?: $s['factura']; ?>
                                <tr>
                                    <td><strong><?php echo $esc($num); ?></strong></td>
                                    <td class="num"><?php echo $fecha($s['fecha_factura']); ?></td>
                                    <td><?php echo $celda($s['nombre_cliente']); ?></td>
                                    <td><?php echo $celda($s['poblacion']); ?></td>
                                    <td class="centro">
                                        <?php if (tienePermiso('modulo_enlazar_facturas')): ?>
                                            <a class="btn btn-chico" href="<?php echo BASE_URL; ?>/enlazar-facturas?factura=<?php echo urlencode($num); ?>"><i class="fa-solid fa-link"></i> Enlazar</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cerrar</button>
            <?php if ($sinResponsable): ?>
                <a class="btn btn-primario" href="<?php echo $esc($urlMr(['responsable' => 'sin'] + $filtros)); ?>">
                    <i class="fa-solid fa-filter"></i> Ver solo estas en la tabla
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($puedeEditar): ?>
<!-- BARRA DE ACCIONES MASIVAS: la misma de Picking. Aparece pegada abajo cuando hay al menos una
     factura tildada, con los botones de lo que se puede hacer con todas a la vez. -->
<div class="barra-seleccion" id="barra-seleccion" hidden>
    <div class="barra-seleccion-info">
        <strong id="barra-conteo">0</strong> factura(s) seleccionada(s)
        <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
    </div>
    <div class="barra-seleccion-acciones">
        <button type="button" class="btn btn-chico btn-primario" data-abrir="modal-transportador">
            <i class="fa-solid fa-truck"></i> Poner transportador
        </button>
        <button type="button" class="btn btn-chico btn-primario btn-contado-masivo" data-si="1">
            <i class="fa-solid fa-money-bill-wave"></i> Marcar de contado
        </button>
        <button type="button" class="btn btn-chico btn-contado-masivo" data-si="0">
            <i class="fa-solid fa-rotate-left"></i> Marcar no de contado
        </button>
    </div>
</div>

<?php if ($pestana !== 'contado'): ?>
<!-- ACTUALIZAR TRANSPORTADORAS (2026-10-05; reemplaza "Borrar datos de transportadoras"): el reporte
     de una transportadora, con el desplegable para elegirla. -->
<div class="modal-fondo" id="modal-actualizar-transp">
    <div class="modal-caja modal-con-panel">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-truck"></i> Actualizar transportadoras</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/consolidado-mr/acciones" method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="actualizar_transportadoras">
            <div class="modal-panel-grid">
                <aside class="modal-panel-lado">
                    <div class="panel-icono"><i class="fa-solid fa-truck-fast"></i></div>
                    <h3>Reporte de transportadora</h3>
                    <p>Subí el Excel tal cual sale del portal de la transportadora.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> Le da a cada factura su <strong>guía</strong>, su <strong>estado</strong>, la <strong>ciudad</strong> y la <strong>fecha de entrega</strong>.</li>
                        <li><i class="fa-solid fa-check"></i> Los de <strong>Proeslog</strong>, <strong>AGV</strong> (Producción diario de envíos, con DOCUMENTO NRO 1 a 4) y <strong>Vector Foods</strong> se reconocen solos.</li>
                        <li><i class="fa-solid fa-check"></i> Subir otra vez el mismo reporte <strong>actualiza</strong> los envíos, no los duplica.</li>
                    </ul>
                </aside>

                <div class="modal-panel-form">
                    <div class="campo">
                        <label for="t-archivo"><i class="fa-solid fa-file-excel"></i> Excel de la transportadora (.xlsx)</label>
                        <input type="file" id="t-archivo" name="archivo" accept=".xlsx,.xls" required>
                    </div>
                    <div class="campo">
                        <label for="t-transportadora"><i class="fa-solid fa-truck"></i> Transportadora <span class="opcional">(opcional)</span></label>
                        <input type="text" id="t-transportadora" name="transportadora" list="lista-transportadoras-actualizar"
                               maxlength="80" autocomplete="off" placeholder="Elegí de la lista o escribí otra">
                        <datalist id="lista-transportadoras-actualizar">
                            <?php foreach (opcionesTransportadoraMr($pdo, ['Proeslog', 'AGV', 'SAP']) as $o): ?>
                                <option value="<?php echo $esc($o); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <span class="ayuda">Si la dejás vacía, se reconoce sola por las columnas del archivo; si no se reconoce, queda <strong>Sin asignar</strong>.</span>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario btn-enviando"><i class="fa-solid fa-file-arrow-up"></i> Actualizar</button>
            </div>
        </form>
    </div>
</div>
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
                    <h3>Consolidado MR</h3>
                    <p>Subí el Excel tal cual sale de SAP. Los reportes de las transportadoras van por “Actualizar transportadoras”.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> El <strong>Consolidado MR</strong> carga las facturas: <strong>una fila por factura</strong>, con su valor neto sumado.</li>
                        <li><i class="fa-solid fa-check"></i> Omite los renglones <strong>en cero</strong> que SAP agrega al partir por lote. Las que traen <strong>X en "Anulado."</strong> están <strong>anuladas</strong>: se ven solo en la pestaña <strong>Facturas anuladas</strong>. Las de <strong>CPag 0010</strong> quedan <strong>de contado</strong>.</li>
                        <li><i class="fa-solid fa-check"></i> Subir otra vez la misma factura la <strong>reemplaza</strong>, no la duplica. El transportador que le pusiste se conserva.</li>
                    </ul>
                </aside>

                <div class="modal-panel-form">
                    <div class="campo">
                        <label for="i-archivo"><i class="fa-solid fa-file-excel"></i> Excel del Consolidado MR (.xlsx)</label>
                        <input type="file" id="i-archivo" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">Hasta un archivo de ~50.000 filas carga en unos segundos. No cierres la página mientras tanto.</span>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario btn-enviando"><i class="fa-solid fa-file-arrow-up"></i> Importar</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- PONER EL TRANSPORTADOR A VARIAS FACTURAS A LA VEZ -->
<div class="modal-fondo" id="modal-transportador">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-truck"></i> Poner transportador</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <div class="modal-cuerpo">
            <p class="confirmar-pregunta">
                A las <strong class="mr-conteo-modal">0</strong> factura(s) seleccionada(s) se les pone este transportador.
                Las que ya tienen guía en el consolidado de las transportadoras siguen mostrando su guía.
            </p>
            <div class="campo">
                <label for="m-transportador">Transportador</label>
                <select id="m-transportador">
                    <?php foreach ($opciones as $o): ?>
                        <option value="<?php echo $esc($o); ?>"><?php echo $esc($o); ?></option>
                    <?php endforeach; ?>
                    <option value="__otro__">Otro…</option>
                    <option value="">— Quitar el transportador —</option>
                </select>
            </div>
            <div class="campo" id="m-transportador-otro-campo" hidden>
                <label for="m-transportador-otro">Nombre del transportador</label>
                <input type="text" id="m-transportador-otro" maxlength="80" placeholder="Ej. COORDINADORA">
            </div>
        </div>
        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cancelar</button>
            <button type="button" class="btn btn-primario" id="btn-guardar-transportador">
                <i class="fa-solid fa-floppy-disk"></i> Guardar
            </button>
        </div>
    </div>
</div>

<!-- CONFIRMAR "DE CONTADO" EN VARIAS FACTURAS A LA VEZ (2026-10-05) -->
<div class="modal-fondo" id="modal-contado">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-money-bill-wave"></i> <span id="m-contado-titulo">Marcar de contado</span></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <div class="modal-cuerpo">
            <p class="confirmar-pregunta">
                Vas a marcar <strong class="mr-conteo-modal">0</strong> factura(s) como
                <strong id="m-contado-estado">de contado</strong>. Queda anotado a tu nombre, y un archivo nuevo no lo cambia.
            </p>
        </div>
        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cancelar</button>
            <button type="button" class="btn btn-primario" id="btn-confirmar-contado">
                <i class="fa-solid fa-check"></i> Confirmar
            </button>
        </div>
    </div>
</div>

<?php if ($pestana === 'contado'): ?>
<!-- SUBIR EL CONSOLIDADO MR DESDE LA PESTAÑA DE CONTADO (2026-10-05) -->
<div class="modal-fondo" id="modal-subir-contado">
    <div class="modal-caja modal-con-panel">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-file-arrow-up"></i> Subir Consolidado MR</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/consolidado-mr/acciones" method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="subir_contado">
            <div class="modal-panel-grid">
                <aside class="modal-panel-lado">
                    <div class="panel-icono"><i class="fa-solid fa-money-bill-wave"></i></div>
                    <h3>Consolidado MR</h3>
                    <p>El Excel del Consolidado MR tal cual sale de SAP.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> Carga las facturas igual que “Importar Consolidado MR” de la pestaña <strong>Facturas</strong>.</li>
                        <li><i class="fa-solid fa-check"></i> Marca solas las de contado: las que traen <strong>CPag 0010</strong>. Las que traen <strong>X en "Anulado."</strong> quedan anuladas.</li>
                    </ul>
                </aside>

                <div class="modal-panel-form">
                    <div class="campo">
                        <label for="c-archivo"><i class="fa-solid fa-file-excel"></i> Excel del Consolidado MR (.xlsx)</label>
                        <input type="file" id="c-archivo" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">Hasta un archivo de ~50.000 filas carga en unos segundos. No cierres la página mientras tanto.</span>
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

<!-- SUBIR COMPARATIVA (2026-10-05): el archivo con los números de las facturas de contado. -->
<div class="modal-fondo" id="modal-comparativa">
    <div class="modal-caja modal-con-panel">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-code-compare"></i> Subir comparativa</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/consolidado-mr/acciones" method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="subir_comparativa">
            <div class="modal-panel-grid">
                <aside class="modal-panel-lado">
                    <div class="panel-icono"><i class="fa-solid fa-code-compare"></i></div>
                    <h3>Las facturas de contado</h3>
                    <p>Un Excel con los números de las facturas que están de contado. No importa en qué columna estén.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> Las facturas que <strong>están en el archivo</strong> pasan a <strong>Sí</strong>.</li>
                        <li><i class="fa-solid fa-check"></i> Las que eran de contado y <strong>no están</strong> pasan a <strong>No</strong> (y siguen en la lista).</li>
                        <li><i class="fa-solid fa-check"></i> Manda sobre lo marcado a mano. Sirve el número NU… o el de SAP.</li>
                        <li><i class="fa-solid fa-check"></i> Si el archivo no trae ninguna factura del Consolidado MR, no se cambia nada.</li>
                    </ul>
                </aside>

                <div class="modal-panel-form">
                    <div class="campo">
                        <label for="cmp-archivo"><i class="fa-solid fa-file-excel"></i> Excel con las facturas de contado (.xlsx)</label>
                        <input type="file" id="cmp-archivo" name="archivo" accept=".xlsx,.xls" required>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario btn-enviando"><i class="fa-solid fa-code-compare"></i> Comparar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    // Lo que necesita scripts_consolidado_mr.js para guardar sin recargar la página.
    window.CONSOLIDADO_MR = {
        urlAcciones: '<?php echo BASE_URL; ?>/consolidado-mr/acciones',
        csrf: '<?php echo $esc(generarTokenCSRF()); ?>'
    };
</script>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/consolidado_mr/layouts/scripts_consolidado_mr.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/consolidado_mr/layouts/scripts_consolidado_mr.js'); ?>"></script>
</body>
</html>
