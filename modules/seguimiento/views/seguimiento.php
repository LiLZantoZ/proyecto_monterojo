<?php
// modules/seguimiento/views/seguimiento.php
// El tablero de estado de los pedidos en las transportadoras: una fila por guía/factura, con sus
// fechas y su estado. Se alimenta a mano o subiendo el Excel que exporta cada transportadora.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_seguimiento.php';

requierePermiso('modulo_seguimiento', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$filtros = [
    'transportadora' => trim($_GET['transportadora'] ?? ''),
    'estado'         => trim($_GET['estado'] ?? ''),
    'grupo'          => trim($_GET['grupo'] ?? ''),   // el semáforo: al hacer clic en una pastilla
    'origen'         => trim($_GET['origen'] ?? ''),  // la pestaña: transportadoras / sap / todos
    'buscar'         => trim($_GET['buscar'] ?? ''),
    'desde'          => trim($_GET['desde'] ?? ''),
    'hasta'          => trim($_GET['hasta'] ?? ''),
];
// Solo se aceptan los cuatro grupos conocidos; cualquier otra cosa se ignora (no filtra).
if (!in_array($filtros['grupo'], ['entregado', 'en_camino', 'pendiente', 'otro'], true)) {
    $filtros['grupo'] = '';
}
// La pestaña de origen. Por defecto "transportadoras": el tablero abre en los pedidos de siempre y
// deja los de facturación de SAP —que son muchos y no cruzan— en su propia pestaña.
if (!in_array($filtros['origen'], ['transportadoras', 'sap', 'todos'], true)) {
    $filtros['origen'] = 'transportadoras';
}

$pagina           = max(1, (int) ($_GET['pagina'] ?? 1));
$totalPedidos     = contarPedidos($pdo, $filtros);
$totalPaginas     = max(1, (int) ceil($totalPedidos / SEGUIMIENTO_POR_PAGINA));
$pagina           = min($pagina, $totalPaginas);

$pedidos          = pedidosSeguidos($pdo, $filtros, $pagina);
// El semáforo se cuenta sobre TODO el conjunto filtrado, pero SIN el filtro de grupo: así, al
// elegir una pastilla, las demás siguen mostrando su total y se puede saltar de una a otra.
$filtrosSemaforo  = $filtros; $filtrosSemaforo['grupo'] = '';
$resumen          = resumenDeEstados(estadosDelFiltro($pdo, $filtrosSemaforo));
// En la pestaña de facturación (SAP) la tabla muestra columnas propias y el total de Valor neto.
$esSap            = ($filtros['origen'] === 'sap');
$totalValorNeto   = $esSap ? sumaValorNeto($pdo, $filtros) : 0;
// Formato de plata en pesos: miles con punto, decimales con coma → $1.234.567,89
$plata = fn($v) => '$' . number_format((float) $v, 2, ',', '.');
$transportadoras  = transportadorasDePedidos($pdo);
$estadosCargados  = estadosDePedidos($pdo);
// Para decidir si mostrar "Limpiar" y qué mensaje de vacío usar: la pestaña de origen no cuenta como
// "filtro" (siempre tiene un valor), así que se excluye.
$filtrosEnUrl     = http_build_query(array_filter(array_diff_key($filtros, ['origen' => 1])));

// El rango que se está viendo, para "1–50 de 4.322".
$desdeFila = $totalPedidos === 0 ? 0 : ($pagina - 1) * SEGUIMIENTO_POR_PAGINA + 1;
$hastaFila = min($pagina * SEGUIMIENTO_POR_PAGINA, $totalPedidos);
// La URL de una página conservando los filtros.
$urlPagina = function ($p) use ($filtros) {
    return BASE_URL . '/seguimiento?' . http_build_query(array_filter($filtros) + ['pagina' => $p]);
};

// La URL de una pastilla del semáforo: conserva los demás filtros, cambia el grupo y vuelve a la
// página 1. Si se pasa el grupo que YA está activo (o '' para "Pedidos"), lo quita: la pastilla
// funciona como interruptor. Sin filtro de grupo no se agrega el parámetro, para dejar la URL limpia.
$urlPastilla = function ($grupo) use ($filtros) {
    $f = $filtros;
    $f['grupo'] = ($filtros['grupo'] === $grupo) ? '' : $grupo;
    unset($f['pagina']);
    return BASE_URL . '/seguimiento?' . http_build_query(array_filter($f));
};

// La URL de una pestaña: conserva los demás filtros, cambia el origen y vuelve a la página 1.
$urlPestana = function ($origen) use ($filtros) {
    $f = $filtros;
    $f['origen'] = $origen;
    unset($f['pagina']);
    return BASE_URL . '/seguimiento?' . http_build_query(array_filter($f));
};

// Cuántos pedidos hay en cada pestaña, con los MISMOS filtros de la vista salvo el origen (así el
// número de cada pestaña muestra cuántos vería uno al cambiarse a ella).
$conteoTransp = contarPedidos($pdo, ['origen' => 'transportadoras'] + $filtros);
$conteoSap    = contarPedidos($pdo, ['origen' => 'sap'] + $filtros);
$conteoTodos  = $conteoTransp + $conteoSap;

// El semáforo de cada estado: verde entregado, azul en camino, ámbar pendiente, gris lo demás.
$colorEstado = function ($estado) {
    switch (grupoDeEstado($estado)) {
        case 'entregado': return 'estado-verde';
        case 'en_camino': return 'estado-azul';
        case 'pendiente': return 'estado-ambar';
        default:          return 'estado-gris';
    }
};

// Fecha para mostrar: 'dd/mm hh:mm' si trae hora, 'dd/mm/aaaa' si es solo día, '—' si no hay.
$fecha = function ($valor, $conHora = true) {
    if ($valor === null || $valor === '' || $valor === '0000-00-00' || strpos($valor, '0000-00-00') === 0) {
        return '<span class="dato-faltante">—</span>';
    }
    $t = strtotime($valor);
    if (!$t) { return '<span class="dato-faltante">—</span>'; }
    return date($conHora && date('H:i', $t) !== '00:00' ? 'd/m/Y H:i' : 'd/m/Y', $t);
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado de pedidos · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/seguimiento.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/seguimiento.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Estado de pedidos</h2>
                <div class="modulo-acciones">
                    <button type="button" class="btn" data-abrir="modal-nuevo">
                        <i class="fa-solid fa-plus"></i> Registrar pedido
                    </button>
                    <button type="button" class="btn btn-primario" data-abrir="modal-importar">
                        <i class="fa-solid fa-file-arrow-up"></i> Importar listado
                    </button>
                </div>
            </header>

            <?php
            // Pestañas de origen: separan los pedidos que bajan de las transportadoras de los que se
            // adjuntan del Consolidado de facturación de SAP (que usan otra numeración y no cruzan).
            $og = $filtros['origen'];
            $tabs = [
                'transportadoras' => ['Transportadoras',  'fa-truck-fast',           $conteoTransp],
                'sap'             => ['Facturación (SAP)', 'fa-file-invoice-dollar',  $conteoSap],
                'todos'           => ['Todos',             'fa-layer-group',          $conteoTodos],
            ];
            ?>
            <nav class="pestanas-seg" aria-label="Origen de los pedidos">
                <?php foreach ($tabs as $clave => $t): ?>
                    <a class="pestana-seg<?php echo $og === $clave ? ' activa' : ''; ?>"
                       href="<?php echo $esc($urlPestana($clave)); ?>">
                        <i class="fa-solid <?php echo $t[1]; ?>"></i>
                        <span><?php echo $t[0]; ?></span>
                        <span class="pestana-conteo"><?php echo number_format($t[2], 0, ',', '.'); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Nota de por qué hoy es manual/Excel: no se automatiza sobre las claves privadas de
                 los portales de las transportadoras. Cuando haya acceso oficial, entra como una
                 fuente más sin tocar esta pantalla. -->
            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Cargá acá el estado de los despachos a mano o subiendo el <strong>Excel que baja
                    de cada transportadora</strong> (AGV, Proeslog, Solística, entregas propias).
                    Cuando cada transportadora entregue un acceso oficial para consultar sus guías,
                    se conecta y este tablero se actualiza solo.
                </div>
            </div>

            <?php
            // El semáforo, ahora clicable: cada pastilla filtra la tabla por su grupo. La activa se
            // resalta; volver a tocarla —o tocar "Pedidos"— quita el filtro. 'activa' cuando el grupo
            // de la pastilla es el que está filtrando (para "Pedidos", cuando no hay grupo).
            $grupoActivo = $filtros['grupo'];
            $claseAct = fn($g) => $grupoActivo === $g ? ' activa' : '';
            ?>
            <div class="pastillas pastillas-filtro">
                <a class="pastilla<?php echo $grupoActivo === '' ? ' activa' : ''; ?>" href="<?php echo $esc($urlPastilla('')); ?>">Pedidos <strong><?php echo number_format($resumen['total'], 0, ',', '.'); ?></strong></a>
                <a class="pastilla pastilla-estado estado-verde<?php echo $claseAct('entregado'); ?>" href="<?php echo $esc($urlPastilla('entregado')); ?>">Entregados <strong><?php echo $resumen['entregado']; ?></strong></a>
                <a class="pastilla pastilla-estado estado-azul<?php echo $claseAct('en_camino'); ?>" href="<?php echo $esc($urlPastilla('en_camino')); ?>">En camino <strong><?php echo $resumen['en_camino']; ?></strong></a>
                <a class="pastilla pastilla-estado estado-ambar<?php echo $claseAct('pendiente'); ?>" href="<?php echo $esc($urlPastilla('pendiente')); ?>">Pendientes <strong><?php echo $resumen['pendiente']; ?></strong></a>
                <?php if ($resumen['otro'] > 0 || $grupoActivo === 'otro'): ?>
                    <a class="pastilla pastilla-estado estado-gris<?php echo $claseAct('otro'); ?>" href="<?php echo $esc($urlPastilla('otro')); ?>">Otros <strong><?php echo $resumen['otro']; ?></strong></a>
                <?php endif; ?>
            </div>

            <form method="GET" class="filtros">
                <?php // El grupo del semáforo y la pestaña de origen viajan escondidos para no perderlos al filtrar por texto/fecha. ?>
                <?php if ($filtros['grupo'] !== ''): ?>
                    <input type="hidden" name="grupo" value="<?php echo $esc($filtros['grupo']); ?>">
                <?php endif; ?>
                <input type="hidden" name="origen" value="<?php echo $esc($filtros['origen']); ?>">
                <div class="filtro">
                    <label for="f-transportadora">Transportadora</label>
                    <select name="transportadora" id="f-transportadora">
                        <option value="">Todas</option>
                        <?php foreach ($transportadoras as $t): ?>
                            <option value="<?php echo $esc($t); ?>" <?php echo $filtros['transportadora'] === $t ? 'selected' : ''; ?>><?php echo $esc($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro">
                    <label for="f-estado">Estado</label>
                    <select name="estado" id="f-estado">
                        <option value="">Todos</option>
                        <?php foreach ($estadosCargados as $e): ?>
                            <option value="<?php echo $esc($e); ?>" <?php echo $filtros['estado'] === $e ? 'selected' : ''; ?>><?php echo $esc($e); ?></option>
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
                           placeholder="Factura, guía, cliente, identificación… Para varios: 001, 002"
                           title="Se puede buscar más de uno a la vez, separados por coma. Busca en factura, guía, cliente, detalle, identificación, referencia, pedido y ubicación.">
                </div>
                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                <?php if ($filtrosEnUrl !== ''): ?>
                    <a class="btn" href="<?php echo BASE_URL; ?>/seguimiento?origen=<?php echo $esc($filtros['origen']); ?>">Limpiar</a>
                <?php endif; ?>
            </form>

            <?php if (!$pedidos): ?>
                <div class="aviso aviso-atencion" style="margin-top: 14px;">
                    <i class="fa-solid fa-inbox"></i>
                    <div>
                        <?php echo $filtrosEnUrl !== ''
                            ? 'No hay pedidos con esos filtros. Probá con otros o limpialos.'
                            : 'Todavía no hay pedidos cargados. Registrá uno o subí el listado de una transportadora.'; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="tabla-caja" style="margin-top: 14px;">
                    <table class="tabla tabla-seguimiento tabla-accion-fija<?php echo $esSap ? ' tabla-sap' : ''; ?>">
                        <thead>
                            <tr>
                                <th class="col-check">
                                    <input type="checkbox" class="chk-todos" id="chk-todos"
                                           title="Seleccionar todos" aria-label="Seleccionar todos los pedidos">
                                </th>
                                <?php if ($esSap): ?>
                                    <th class="num">Fecha factura</th>
                                    <th>Factura</th>
                                    <th>Cliente</th>
                                    <th>Ubicación</th>
                                    <th>Numero De Identificación</th>
                                    <th class="num">Valor neto</th>
                                    <th>Referencia</th>
                                    <th>Pedido</th>
                                <?php else: ?>
                                    <th>Estado</th>
                                    <th>Transportadora</th>
                                    <th>Factura / Guía</th>
                                    <th>Destino</th>
                                    <th>Detalle</th>
                                    <th class="num">Despacho</th>
                                    <th class="num">Entrega</th>
                                <?php endif; ?>
                                <th class="col-acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pedidos as $p): ?>
                                <?php
                                // Todo lo del pedido viaja en un data-* como JSON, ya con las fechas
                                // formateadas: la fila se abre en un modal que muestra el detalle
                                // COMPLETO (en la tabla se recorta para que no crezca de alto), y
                                // así el modal no necesita otra consulta.
                                $datosPedido = [
                                    'estado'         => (string) $p['estado'],
                                    'grupo'          => $colorEstado($p['estado']),
                                    'transportadora' => (string) $p['transportadora'],
                                    'factura'        => (string) $p['numero_factura'],
                                    'guia'           => (string) $p['numero_guia'],
                                    'destino'        => (string) $p['destino'],
                                    'bodega'         => (string) $p['bodega'],
                                    'detalle'        => (string) $p['detalle'],
                                    'fecha_guia'     => strip_tags($fecha($p['fecha_guia'])),
                                    'fecha_despacho' => strip_tags($fecha($p['fecha_despacho'])),
                                    'fecha_entrega'  => strip_tags($fecha($p['fecha_entrega'])),
                                    'fuente'         => (string) $p['fuente'],
                                    // Datos de facturación (SAP): vacíos en los pedidos de transportadora.
                                    'direccion'      => (string) ($p['direccion'] ?? ''),
                                    'nit'            => (string) ($p['nit'] ?? ''),
                                    'valor_neto'     => ($p['valor_neto'] ?? null) !== null ? $plata($p['valor_neto']) : '',
                                    'referencia'     => (string) ($p['referencia'] ?? ''),
                                    'pedido_cliente' => (string) ($p['pedido_cliente'] ?? ''),
                                ];
                                ?>
                                <tr class="fila-pedido" tabindex="0"
                                    data-pedido="<?php echo htmlspecialchars(json_encode($datosPedido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>">
                                    <td class="col-check">
                                        <input type="checkbox" class="chk-fila" value="<?php echo (int) $p['id_pedido']; ?>"
                                               aria-label="Seleccionar pedido <?php echo $esc($p['numero_factura'] ?: $p['numero_guia']); ?>">
                                    </td>
                                    <?php if ($esSap): ?>
                                        <?php $faltante = '<span class="dato-faltante">—</span>'; ?>
                                        <td class="num"><?php echo $fecha($p['fecha_guia'], false); ?></td>
                                        <td><strong><?php echo $esc($p['numero_factura']); ?></strong></td>
                                        <td><?php echo $p['destino'] ? $esc($p['destino']) : $faltante; ?></td>
                                        <td><?php echo $p['direccion'] ? $esc($p['direccion']) : $faltante; ?></td>
                                        <td><?php echo $p['nit'] ? $esc($p['nit']) : $faltante; ?></td>
                                        <td class="num celda-valor"><?php echo $p['valor_neto'] !== null ? $esc($plata($p['valor_neto'])) : $faltante; ?></td>
                                        <td><?php echo $p['referencia'] ? $esc($p['referencia']) : $faltante; ?></td>
                                        <td><?php echo $p['pedido_cliente'] ? $esc($p['pedido_cliente']) : $faltante; ?></td>
                                    <?php else: ?>
                                    <td>
                                        <span class="chip-estado <?php echo $colorEstado($p['estado']); ?>">
                                            <?php echo $p['estado'] !== null && $p['estado'] !== ''
                                                ? $esc($p['estado'])
                                                : '<span class="dato-faltante">sin estado</span>'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $esc($p['transportadora']); ?></td>
                                    <td>
                                        <?php if ($p['numero_factura']): ?>
                                            <strong><?php echo $esc($p['numero_factura']); ?></strong>
                                        <?php endif; ?>
                                        <?php if ($p['numero_guia']): ?>
                                            <div class="sub-dato">Guía <?php echo $esc($p['numero_guia']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $p['destino'] ? $esc($p['destino']) : '<span class="dato-faltante">—</span>'; ?></td>
                                    <td class="celda-detalle">
                                        <?php echo $p['detalle'] ? $esc($p['detalle']) : '<span class="dato-faltante">—</span>'; ?>
                                    </td>
                                    <td class="num"><?php echo $fecha($p['fecha_despacho']); ?></td>
                                    <td class="num"><?php echo $fecha($p['fecha_entrega']); ?></td>
                                    <?php endif; ?>
                                    <td class="num celda-acciones">
                                        <button type="button" class="btn btn-chico btn-icono btn-ver-pedido"
                                                title="Ver detalle" aria-label="Ver detalle del pedido">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <form action="<?php echo BASE_URL; ?>/seguimiento/acciones" method="POST"
                                              onsubmit="return confirm('¿Eliminar este pedido del seguimiento?');" style="display:inline;">
                                            <?php campoCSRF(); ?>
                                            <input type="hidden" name="accion" value="eliminar_pedido">
                                            <input type="hidden" name="id_pedido" value="<?php echo (int) $p['id_pedido']; ?>">
                                            <button type="submit" class="btn btn-chico btn-icono" title="Eliminar" aria-label="Eliminar pedido">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($esSap): ?>
                            <tfoot>
                                <tr class="fila-total">
                                    <td colspan="6" class="total-etiqueta">
                                        Total Valor neto
                                        <span class="total-nota">(<?php echo number_format($totalPedidos, 0, ',', '.'); ?> factura<?php echo $totalPedidos === 1 ? '' : 's'; ?>, todo el filtro)</span>
                                    </td>
                                    <td class="num total-monto"><?php echo $esc($plata($totalValorNeto)); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- PAGINACIÓN: la tabla se pagina en el servidor (50 por página) para no mandar
                     miles de filas de una. Los filtros se conservan en cada enlace. -->
                <div class="paginacion">
                    <span class="paginacion-info">
                        <?php echo number_format($desdeFila, 0, ',', '.'); ?>–<?php echo number_format($hastaFila, 0, ',', '.'); ?>
                        de <strong><?php echo number_format($totalPedidos, 0, ',', '.'); ?></strong> pedidos
                    </span>
                    <?php if ($totalPaginas > 1): ?>
                        <?php
                        // Paginación estilo buscador: se muestran SIEMPRE la primera y la última página, más
                        // una VENTANA de números consecutivos que se desliza con la actual; los huecos se
                        // marcan con "…" (un 0 en la lista). En la página 1 la ventana arranca en 1, así se
                        // ven 1 2 3 … hasta llenar la ventana (ej. 1 2 3 4 5 6 7 8 9 … 27).
                        $ventana = 9;                              // cuántos números consecutivos se muestran
                        $mitad   = intdiv($ventana, 2);
                        $inicio  = max(1, $pagina - $mitad);
                        $fin     = min($totalPaginas, $inicio + $ventana - 1);
                        $inicio  = max(1, $fin - $ventana + 1);     // reajuste: mantener la ventana llena cerca del final
                        $numeros = [];
                        for ($i = 1; $i <= $totalPaginas; $i++) {
                            if ($i === 1 || $i === $totalPaginas || ($i >= $inicio && $i <= $fin)) {
                                $numeros[] = $i;
                            }
                        }
                        $conSaltos = [];
                        $prev = 0;
                        foreach ($numeros as $n) {
                            if ($prev && $n - $prev > 1) { $conSaltos[] = 0; }   // 0 = "…"
                            $conSaltos[] = $n;
                            $prev = $n;
                        }
                        ?>
                        <nav class="paginacion-botones" aria-label="Paginación">
                            <?php if ($pagina > 1): ?>
                                <a class="pagina-flecha" href="<?php echo $esc($urlPagina($pagina - 1)); ?>" rel="prev" aria-label="Página anterior">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </a>
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
                                <a class="pagina-flecha" href="<?php echo $esc($urlPagina($pagina + 1)); ?>" rel="next" aria-label="Página siguiente">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="pagina-flecha deshabilitada" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>

                <!-- LA BARRA DE SELECCIÓN: aparece cuando hay filas tildadas y borra todas de una.
                     Va fija abajo (mismo patrón que Picking/Cajas) porque con miles de pedidos hay
                     que scrollear. -->
                <div class="barra-seleccion" id="barra-seleccion" hidden>
                    <div class="barra-seleccion-info">
                        <strong id="barra-conteo">0</strong> seleccionado(s)
                        <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">limpiar</button>
                    </div>
                    <div class="barra-seleccion-acciones">
                        <form action="<?php echo BASE_URL; ?>/seguimiento/acciones" method="POST" id="form-eliminar-masivo">
                            <?php campoCSRF(); ?>
                            <input type="hidden" name="accion" value="eliminar_masivo">
                            <div id="campos-eliminar-masivo"></div>
                            <button type="submit" class="btn btn-chico btn-peligro">
                                <i class="fa-solid fa-trash-can"></i> Eliminar seleccionados
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- REGISTRAR UN PEDIDO A MANO -->
<div class="modal-fondo" id="modal-nuevo">
    <div class="modal-caja modal-con-panel">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-plus"></i> Registrar pedido</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/seguimiento/acciones" method="POST">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="guardar_pedido">
            <div class="modal-panel-grid">
                <aside class="modal-panel-lado">
                    <div class="panel-icono"><i class="fa-solid fa-truck-fast"></i></div>
                    <h3>Un pedido para seguir</h3>
                    <p>Cargá un despacho suelto para verlo en el tablero junto con los demás.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> Con la <strong>transportadora</strong> y la factura o la guía alcanza.</li>
                        <li><i class="fa-solid fa-check"></i> Si repetís la misma factura/guía, se <strong>actualiza</strong>, no se duplica.</li>
                        <li><i class="fa-solid fa-check"></i> El estado pinta el color solo según lo que escribas.</li>
                    </ul>
                    <div class="panel-leyenda">
                        <span class="lg-verde">Entregado</span>
                        <span class="lg-azul">En camino</span>
                        <span class="lg-ambar">Pendiente / novedad</span>
                    </div>
                </aside>

                <div class="modal-panel-form">
                    <div class="rejilla-campos">
                        <div class="campo">
                            <label for="n-transportadora"><i class="fa-solid fa-truck"></i> Transportadora *</label>
                            <input type="text" id="n-transportadora" name="transportadora" list="lista-transportadoras" required
                                   placeholder="AGV, Proeslog, propia…">
                            <datalist id="lista-transportadoras">
                                <?php foreach ($transportadoras as $t): ?><option value="<?php echo $esc($t); ?>"><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="campo">
                            <label for="n-estado"><i class="fa-solid fa-circle-half-stroke"></i> Estado</label>
                            <input type="text" id="n-estado" name="estado" list="lista-estados" placeholder="En tránsito, Entregado…">
                            <datalist id="lista-estados">
                                <?php foreach ($estadosCargados as $e): ?><option value="<?php echo $esc($e); ?>"><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="campo">
                            <label for="n-factura"><i class="fa-solid fa-file-invoice"></i> Factura</label>
                            <input type="text" id="n-factura" name="numero_factura" placeholder="N° de factura">
                        </div>
                        <div class="campo">
                            <label for="n-guia"><i class="fa-solid fa-barcode"></i> Guía</label>
                            <input type="text" id="n-guia" name="numero_guia" placeholder="N° de guía / remesa">
                        </div>
                        <div class="campo campo-ancho">
                            <label for="n-destino"><i class="fa-solid fa-location-dot"></i> Destino</label>
                            <input type="text" id="n-destino" name="destino" placeholder="Cliente o punto de venta">
                        </div>
                        <div class="campo campo-ancho">
                            <label for="n-detalle"><i class="fa-solid fa-align-left"></i> Detalle</label>
                            <input type="text" id="n-detalle" name="detalle" placeholder="Descripción de la factura / del pedido">
                        </div>
                        <div class="campo">
                            <label for="n-bodega"><i class="fa-solid fa-warehouse"></i> Bodega</label>
                            <input type="text" id="n-bodega" name="bodega">
                        </div>
                        <div class="campo"><!-- vacío para alinear la reja en dos columnas --></div>
                        <div class="campo">
                            <label for="n-f-despacho"><i class="fa-solid fa-truck-ramp-box"></i> Fecha de despacho</label>
                            <input type="datetime-local" id="n-f-despacho" name="fecha_despacho">
                        </div>
                        <div class="campo">
                            <label for="n-f-entrega"><i class="fa-solid fa-flag-checkered"></i> Fecha de entrega</label>
                            <input type="datetime-local" id="n-f-entrega" name="fecha_entrega">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- IMPORTAR EL LISTADO DE UNA TRANSPORTADORA -->
<div class="modal-fondo" id="modal-importar">
    <div class="modal-caja modal-con-panel">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-file-arrow-up"></i> Importar listado</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/seguimiento/acciones" method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="importar_excel">
            <div class="modal-panel-grid">
                <aside class="modal-panel-lado">
                    <div class="panel-icono"><i class="fa-solid fa-file-excel"></i></div>
                    <h3>El Excel de la transportadora</h3>
                    <p>Subí el reporte que baja de cada portal. El sistema reconoce solo de qué transportadora es.</p>
                    <ul class="panel-tips">
                        <li><i class="fa-solid fa-check"></i> Identifica <strong>Proeslog</strong>, <strong>AGV</strong> y <strong>Vector Foods</strong> por sus columnas, sin elegir nada.</li>
                        <li><i class="fa-solid fa-check"></i> Reconoce factura, guía, estado, fechas, destino y detalle en cualquier orden.</li>
                        <li><i class="fa-solid fa-check"></i> También lee el <strong>Consolidado completo de facturación (SAP)</strong>: agrega sus pedidos como <strong>“Facturado”</strong> (lista aparte).</li>
                        <li><i class="fa-solid fa-check"></i> Subirlo otra vez <strong>actualiza</strong>, no duplica.</li>
                    </ul>
                </aside>

                <div class="modal-panel-form">
                    <div class="campo">
                        <label for="i-archivo"><i class="fa-solid fa-file-excel"></i> Excel de la transportadora (.xlsx)</label>
                        <input type="file" id="i-archivo" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda">Los archivos de Proeslog, AGV, Vector Foods y el Consolidado de facturación (SAP) se reconocen solos.</span>
                    </div>
                    <div class="campo" style="margin-top: 14px;">
                        <label for="i-transportadora"><i class="fa-solid fa-truck"></i> Transportadora</label>
                        <input type="text" id="i-transportadora" name="transportadora" list="lista-transportadoras"
                               placeholder="Solo si no se reconoce sola…">
                        <span class="ayuda">Déjalo vacío para los formatos conocidos. Solo hace falta para un archivo que el sistema no reconozca.</span>
                    </div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-file-arrow-up"></i> Importar</button>
            </div>
        </form>
    </div>
</div>

<!-- DETALLE COMPLETO DE UN PEDIDO -->
<div class="modal-fondo" id="modal-detalle">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-eye"></i> Detalle del pedido</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <div class="modal-cuerpo">
            <div class="detalle-estado">
                <span class="chip-estado" id="det-estado"></span>
                <span class="detalle-fuente" id="det-fuente"></span>
            </div>

            <dl class="detalle-lista">
                <div><dt><i class="fa-solid fa-truck"></i> Transportadora</dt><dd id="det-transportadora"></dd></div>
                <div><dt><i class="fa-solid fa-file-invoice"></i> Factura</dt><dd id="det-factura"></dd></div>
                <div><dt><i class="fa-solid fa-barcode"></i> Guía</dt><dd id="det-guia"></dd></div>
                <div><dt><i class="fa-solid fa-location-dot"></i> Destino</dt><dd id="det-destino"></dd></div>
                <div><dt><i class="fa-solid fa-map-location-dot"></i> Ubicación</dt><dd id="det-direccion"></dd></div>
                <div><dt><i class="fa-solid fa-id-card"></i> Numero De Identificación</dt><dd id="det-nit"></dd></div>
                <div><dt><i class="fa-solid fa-money-bill-wave"></i> Valor neto</dt><dd id="det-valor"></dd></div>
                <div><dt><i class="fa-solid fa-hashtag"></i> Referencia</dt><dd id="det-referencia"></dd></div>
                <div><dt><i class="fa-solid fa-clipboard-list"></i> Pedido</dt><dd id="det-pedido"></dd></div>
                <div><dt><i class="fa-solid fa-warehouse"></i> Bodega</dt><dd id="det-bodega"></dd></div>
                <div><dt><i class="fa-solid fa-file-invoice-dollar"></i> Fecha de la guía</dt><dd id="det-fecha-guia"></dd></div>
                <div><dt><i class="fa-solid fa-truck-ramp-box"></i> Despacho</dt><dd id="det-despacho"></dd></div>
                <div><dt><i class="fa-solid fa-flag-checkered"></i> Entrega</dt><dd id="det-entrega"></dd></div>
            </dl>

            <div class="detalle-bloque">
                <div class="detalle-bloque-titulo"><i class="fa-solid fa-align-left"></i> Detalle</div>
                <div class="detalle-texto" id="det-detalle"></div>
            </div>
        </div>
        <div class="modal-pie">
            <button type="button" class="btn btn-primario" data-cerrar>Cerrar</button>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/seguimiento/layouts/scripts_seguimiento.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/seguimiento/layouts/scripts_seguimiento.js'); ?>"></script>
</body>
</html>
