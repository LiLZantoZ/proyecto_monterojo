<?php
// modules/ordenes_compra/views/ordenes_compra.php
// Las órdenes de compra del Éxito: la planilla para armar el transporte a los CEDI.
//
// Las columnas son las de la planilla que el usuario armaba a mano ("ordenes compra.xlsx"): orden,
// cajas, unidades, estibas, peso, mts3, valor y carro, más el CEDI de destino. Cada fila se despliega
// para ver los productos, que es donde se ve QUÉ falta cuando una orden trae un aviso. La tabla se
// descarga en PDF con el mismo filtro que hay en pantalla (ver helper_ordenes_compra_pdf.php).

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_ordenes_compra.php';

requierePermiso('modulo_ordenes_compra', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$filtros = ['oc' => trim($_GET['oc'] ?? '')];

$datos       = ordenesDeCompraExito($pdo, $filtros);
$ordenes     = $datos['ordenes'];
$t           = $datos['totales'];
$disponibles = ordenesDisponibles($pdo);
$cubicajes   = resumenCubicajes($pdo);
$flota       = vehiculos($pdo, false);   // todos, también los que no están en uso, para editarlos

$esc   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$num   = fn($n) => number_format((float) $n, 0, ',', '.');
// Hasta 7 decimales y sin ceros de más, como en la planilla del usuario: 0,0288 · 0,10245 ·
// 0,8936165. Con un número fijo de decimales, o se pierde precisión o se llena de ceros.
$m3    = fn($n) => rtrim(rtrim(number_format((float) $n, 7, ',', '.'), '0'), ',') ?: '0';
$plata = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');

// La etiqueta del carro.
$etiquetaCarro = function (array $o) use ($esc) {
    if ($o['carro'] === null) {
        return $o['carro_excede']
            ? '<span class="etiqueta-estado etiqueta-excede" title="Pesa o abulta más que el vehículo más grande: hay que partirla en varios viajes">No entra en un vehículo</span>'
            : '<span class="etiqueta-estado etiqueta-sin-definir" title="No hay vehículos en uso: activá alguno en Ajustes, abajo">Sin vehículos</span>';
    }
    $html = '<span class="etiqueta-estado etiqueta-carro">' . $esc($o['carro']) . '</span>';
    if ($o['carro_incompleto']) {
        // Le faltan productos al cálculo: su peso y su volumen reales son mayores.
        $html .= ' <i class="fa-solid fa-triangle-exclamation icono-faltante"
                      title="A esta orden le faltan cubicajes o datos del maestro: su peso y su volumen reales son mayores y el vehículo podría quedar chico."></i>';
    }
    return $html;
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Órdenes de compra · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Órdenes de compra (Éxito)</h2>
                <div class="modulo-acciones">
                    <?php if (!empty($ordenes)): ?>
                        <a class="btn"
                           href="<?php echo BASE_URL; ?>/ordenes-compra/acciones?accion=pdf<?php echo $filtros['oc'] !== '' ? '&amp;oc=' . urlencode($filtros['oc']) : ''; ?>">
                            <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Acá salen <strong>solo las órdenes del Éxito pendientes de despacho</strong>, las
                    que van a un CEDI. Las cajas cuentan también la caja incompleta de cada producto,
                    igual que los rótulos. El <strong>peso</strong> es cajas × <?php echo ORDENES_KG_POR_CAJA; ?> kg
                    y el <strong>valor</strong>, unidades × precio bruto. El <strong>carro</strong> es el
                    vehículo seco más chico en el que entran el peso y el volumen de la orden.
                </div>
            </div>

            <?php if (!empty($datos['sin_cubicaje'])): ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong><?php echo $num(count($datos['sin_cubicaje'])); ?> producto(s) no tienen cubicaje</strong>
                        y su volumen <strong>no se suma</strong> a los m³:
                        <?php echo implode(', ', array_map(fn($sku, $d) => '<strong>' . $esc($sku) . '</strong> ' . $esc($d),
                                                           array_keys($datos['sin_cubicaje']), $datos['sin_cubicaje'])); ?>.
                        Agregalos al archivo de cubicajes y volvé a subirlo en <em>Ajustes</em>, abajo.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($t['sin_maestro'] > 0): ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong><?php echo $num($t['sin_maestro']); ?> línea(s)</strong> son de productos que no están
                        en el maestro o no tienen unidades por caja: <strong>no suman cajas, peso ni volumen</strong>.
                        Cargalos en <a href="<?php echo BASE_URL; ?>/maestro">Maestro de productos</a>.
                        Desplegá las órdenes marcadas para ver cuáles son.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($t['sin_precio'] > 0): ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong><?php echo $num($t['sin_precio']); ?> línea(s)</strong> no tienen precio y
                        <strong>no suman valor</strong>. Pasa con los consolidados importados antes de que el sistema
                        guardara el precio: se completan volviendo a importar el archivo del Éxito.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$datos['hay_flota']): ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-truck"></i>
                    <div>
                        No hay ningún <strong>vehículo en uso</strong>, así que no se puede elegir el carro.
                        Activá los que correspondan en <em>Ajustes</em>, abajo.
                    </div>
                </div>
            <?php endif; ?>

            <form method="GET" class="filtros">
                <div class="filtro">
                    <label for="f-oc">Orden de compra</label>
                    <input type="text" name="oc" id="f-oc" list="lista-ordenes" autocomplete="off"
                           value="<?php echo $esc($filtros['oc']); ?>" placeholder="Número o parte del número">
                    <datalist id="lista-ordenes">
                        <?php foreach ($disponibles as $oc): ?>
                            <option value="<?php echo $esc($oc); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                <?php if ($filtros['oc'] !== ''): ?>
                    <a class="btn" href="<?php echo BASE_URL; ?>/ordenes-compra">Limpiar</a>
                <?php endif; ?>
            </form>

            <?php if (empty($ordenes)): ?>
                <div class="tabla-caja">
                    <p class="tabla-vacia">
                        <?php echo $filtros['oc'] !== ''
                            ? 'Ninguna orden pendiente coincide con "' . $esc($filtros['oc']) . '".'
                            : 'No hay órdenes del Éxito pendientes de despacho.'; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="pastillas">
                    <span class="pastilla"><strong><?php echo $num($t['ordenes']); ?></strong> órdenes</span>
                    <span class="pastilla"><strong><?php echo $num($t['cajas']); ?></strong> cajas</span>
                    <span class="pastilla"><strong><?php echo $m3($t['m3']); ?></strong> m³</span>
                    <span class="pastilla"><strong><?php echo $plata($t['valor']); ?></strong></span>
                </div>

                <div class="tabla-caja">
                    <table class="tabla tabla-ordenes">
                        <thead>
                            <tr>
                                <th style="width: 3%;"></th>
                                <th>Orden</th>
                                <th>CEDI</th>
                                <th class="num">Cajas</th>
                                <th class="num">Unidades</th>
                                <th class="num" title="Pendiente: todavía no hay regla para calcularlas">Estibas <span class="pendiente">pendiente</span></th>
                                <th class="num">Peso (kg)</th>
                                <th class="num">m³</th>
                                <th class="num">Valor</th>
                                <th class="centro">Carro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordenes as $i => $o):
                                $faltantes = [];
                                if ($o['sin_maestro'])  { $faltantes[] = $o['sin_maestro'] . ' sin maestro'; }
                                if ($o['sin_cubicaje']) { $faltantes[] = $o['sin_cubicaje'] . ' sin cubicaje'; }
                                if ($o['sin_precio'])   { $faltantes[] = $o['sin_precio'] . ' sin precio'; }
                            ?>
                                <tr>
                                    <td>
                                        <button type="button" class="btn-detalle-oc" aria-expanded="false"
                                                aria-controls="detalle-oc-<?php echo $i; ?>" title="Ver los productos de la orden">
                                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <strong><?php echo $esc($o['orden']); ?></strong>
                                        <?php if ($faltantes): ?>
                                            <i class="fa-solid fa-triangle-exclamation icono-faltante"
                                               title="<?php echo $esc(implode(' · ', $faltantes)) . ' (línea/s). Desplegá para ver cuáles.'; ?>"></i>
                                        <?php endif; ?>
                                        <div class="dato-secundario">carga <?php echo (int) $o['id_carga']; ?></div>
                                    </td>
                                    <td><?php echo $esc($o['cedi']); ?></td>
                                    <td class="num"><strong><?php echo $num($o['cajas']); ?></strong></td>
                                    <td class="num"><?php echo $num($o['unidades']); ?></td>
                                    <td class="num"><?php echo $num($o['estibas']); ?></td>
                                    <td class="num"><?php echo $num($o['peso_kg']); ?></td>
                                    <td class="num"><?php echo $m3($o['m3']); ?></td>
                                    <td class="num"><?php echo $plata($o['valor']); ?></td>
                                    <td class="centro"><?php echo $etiquetaCarro($o); ?></td>
                                </tr>
                                <tr class="fila-detalle" id="detalle-oc-<?php echo $i; ?>" hidden>
                                    <td colspan="10">
                                        <table class="tabla-detalle">
                                            <thead>
                                                <tr>
                                                    <th>SKU</th>
                                                    <th>Producto</th>
                                                    <th class="num">Tiendas</th>
                                                    <th class="num">Unidades</th>
                                                    <th class="num">Und/caja</th>
                                                    <th class="num">Cajas</th>
                                                    <th class="num">Cubicaje caja</th>
                                                    <th class="num">m³</th>
                                                    <th class="num">Precio bruto</th>
                                                    <th class="num">Valor</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($o['productos'] as $p): ?>
                                                    <tr>
                                                        <td><?php echo $esc($p['sku'] ?? '—'); ?></td>
                                                        <td><?php echo $esc($p['producto']); ?></td>
                                                        <td class="num"><?php echo $num($p['tiendas']); ?></td>
                                                        <td class="num"><?php echo $num($p['unidades']); ?></td>
                                                        <td class="num"><?php echo $p['por_caja'] ? $num($p['por_caja']) : '<span class="faltante">sin maestro</span>'; ?></td>
                                                        <td class="num"><?php echo $p['cajas'] !== null ? $num($p['cajas']) : '<span class="faltante">—</span>'; ?></td>
                                                        <td class="num"><?php echo $p['cubicaje'] !== null
                                                                ? number_format($p['cubicaje'], 7, ',', '.')
                                                                : '<span class="faltante">sin cubicaje</span>'; ?></td>
                                                        <td class="num"><?php echo $p['m3'] !== null ? $m3($p['m3']) : '<span class="faltante">—</span>'; ?></td>
                                                        <td class="num"><?php echo $p['precio'] !== null ? $plata($p['precio']) : '<span class="faltante">sin precio</span>'; ?></td>
                                                        <td class="num"><?php echo $p['valor'] !== null ? $plata($p['valor']) : '<span class="faltante">—</span>'; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fila-total">
                                <td colspan="3">TOTAL · <?php echo $num($t['ordenes']); ?> órdenes</td>
                                <td class="num"><?php echo $num($t['cajas']); ?></td>
                                <td class="num"><?php echo $num($t['unidades']); ?></td>
                                <td class="num"><?php echo $num($t['estibas']); ?></td>
                                <td class="num"><?php echo $num($t['peso_kg']); ?></td>
                                <td class="num"><?php echo $m3($t['m3']); ?></td>
                                <td class="num"><?php echo $plata($t['valor']); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>

            <!-- AJUSTES: los dos datos que este módulo necesita y que no vienen en el Consolidado. -->
            <details class="grupo-desplegable ajustes-oc">
                <summary class="grupo-cabecera">
                    <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>
                    <div class="grupo-titulo">
                        <div class="grupo-nombre">Ajustes</div>
                        <div class="grupo-resumen">
                            <?php echo $num($cubicajes['productos']); ?> productos con cubicaje ·
                            <?php echo $num(count(array_filter($flota, fn($v) => $v['activo']))); ?> vehículos en uso ·
                            estibas: pendiente
                        </div>
                    </div>
                </summary>

                <div class="ajustes-oc-cuerpo">
                    <form action="<?php echo BASE_URL; ?>/ordenes-compra/acciones" method="POST"
                          enctype="multipart/form-data" class="ajuste-oc">
                        <?php campoCSRF(); ?>
                        <input type="hidden" name="accion" value="subir_cubicajes">
                        <h3>Cubicajes por caja</h3>
                        <p class="ayuda">
                            Excel con las columnas <strong>SKU</strong> y <strong>CUBICAJE POR CAJA</strong> (y, si
                            las trae, EAN, DENOMINACIÓN y TIPO CAJA). Agrega los productos nuevos y corrige los que
                            ya estaban; no borra los que no vengan en el archivo. Se busca por SKU, no por EAN.
                        </p>
                        <div class="campo">
                            <input type="file" name="archivo" accept=".xlsx,.xls" required>
                        </div>
                        <button type="submit" class="btn btn-primario"><i class="fa-solid fa-upload"></i> Subir cubicajes</button>
                    </form>

                    <div class="ajuste-oc">
                        <h3>Estibas <span class="pendiente">pendiente</span></h3>
                        <p class="ayuda">
                            Por ahora van en 0. Falta la regla —por ejemplo, cuántos m³ entran en una estiba— y en
                            cuanto esté se calculan acá. Mientras tanto, las estibas de cada vehículo no se usan para
                            elegir el carro.
                        </p>
                    </div>

                    <form action="<?php echo BASE_URL; ?>/ordenes-compra/acciones" method="POST" class="ajuste-oc ajuste-vehiculos">
                        <?php campoCSRF(); ?>
                        <input type="hidden" name="accion" value="guardar_vehiculos">
                        <h3>Vehículos</h3>
                        <p class="ayuda">
                            Para cada orden se elige el vehículo <strong>en uso</strong> de menor capacidad en el que entran
                            su peso y su volumen. Solo vehículos secos: los refrigerados no aplican. Hay dos
                            <strong>TURBO 6 SECA</strong>, uno de 2.800 kg y otro de 7.500 kg, y se puede elegir cualquiera
                            de los dos según el peso de la orden.
                        </p>
                        <table class="tabla-detalle tabla-vehiculos">
                            <thead>
                                <tr>
                                    <th>Vehículo</th>
                                    <th class="num">Peso (kg)</th>
                                    <th class="num">Estibas</th>
                                    <th class="num">m³</th>
                                    <th class="centro">En uso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($flota as $v): $id = (int) $v['id_vehiculo']; ?>
                                    <tr<?php echo $v['activo'] ? '' : ' class="fila-inactiva"'; ?>>
                                        <td><?php echo $esc($v['nombre']); ?></td>
                                        <td class="num"><input type="text" inputmode="numeric" name="vehiculos[<?php echo $id; ?>][peso_kg]"
                                                   value="<?php echo (int) $v['peso_kg']; ?>" aria-label="Peso de <?php echo $esc($v['nombre']); ?>"></td>
                                        <td class="num"><input type="text" inputmode="numeric" name="vehiculos[<?php echo $id; ?>][estibas]"
                                                   value="<?php echo (int) $v['estibas']; ?>" aria-label="Estibas de <?php echo $esc($v['nombre']); ?>"></td>
                                        <td class="num"><input type="text" inputmode="decimal" name="vehiculos[<?php echo $id; ?>][m3]"
                                                   value="<?php echo $esc(rtrim(rtrim(number_format((float) $v['m3'], 2, ',', ''), '0'), ',') ?: '0'); ?>"
                                                   aria-label="Metros cúbicos de <?php echo $esc($v['nombre']); ?>"></td>
                                        <td class="centro"><input type="checkbox" name="vehiculos[<?php echo $id; ?>][activo]" value="1"
                                                   <?php echo $v['activo'] ? 'checked' : ''; ?> aria-label="<?php echo $esc($v['nombre']); ?> en uso"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> Guardar vehículos</button>
                    </form>

                </div>
            </details>

        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/desplegables.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/desplegables.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/ordenes_compra/layouts/scripts_ordenes_compra.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/ordenes_compra/layouts/scripts_ordenes_compra.js'); ?>"></script>
</body>
</html>
