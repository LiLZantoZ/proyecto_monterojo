<?php
// modules/enlazar_facturas/views/enlazar_facturas.php
// ENLAZAR FACTURAS (2026-10-05): la bodega escribe el número de una factura y la cédula del operario
// que la alistó y despachó, ve un resumen de las dos cosas y la enlaza. El enlace queda en
// consolidado_mr_responsables y se ve en la columna "Responsable de empaque" del Consolidado MR.
// La lógica está en modules/consolidado_mr/model_consolidado_mr.php (buscarFacturaMr, enlazar…).

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../../consolidado_mr/model_consolidado_mr.php';

requierePermiso('modulo_enlazar_facturas', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$esc   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$plata = fn($v) => '$' . number_format((float) $v, 2, ',', '.');
$mil   = fn($n) => number_format((float) $n, 0, ',', '.');
$fecha = fn($v, $hora = false) => ($v && ($t = strtotime($v))) ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', $t) : '—';

$numero = trim($_GET['factura'] ?? '');
$cedula = trim($_GET['cedula'] ?? '');

// Se busca lo que se haya escrito: las dos cosas por separado, para decir cuál de las dos falla.
$factura = $numero !== '' ? buscarFacturaMr($pdo, $numero) : null;
$persona = $cedula !== '' ? buscarPersonaPorCedulaMr($pdo, $cedula) : null;
$puedeEnlazar = $factura && $persona && $persona['estado'] === 'Activo';

$enlaces = ultimosEnlacesMr($pdo, 25);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enlazar facturas · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/enlazar_facturas/layouts/enlazar_facturas.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/enlazar_facturas/layouts/enlazar_facturas.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Enlazar facturas</h2>
            </header>

            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Escribí el número de la factura y la cédula del operario que la <strong>alistó y despachó</strong>.
                    Al enlazarla, su nombre queda como <strong>responsable de empaque</strong> en el Consolidado MR.
                </div>
            </div>

            <form method="GET" class="enlace-form">
                <div class="enlace-paso">
                    <span class="enlace-numero">1</span>
                    <div class="campo">
                        <label for="e-factura">Número de factura</label>
                        <input type="text" id="e-factura" name="factura" value="<?php echo $esc($numero); ?>"
                               placeholder="Ej. NU04169821" autocomplete="off" required
                               <?php echo $numero === '' ? 'autofocus' : ''; ?>>
                    </div>
                </div>
                <div class="enlace-paso">
                    <span class="enlace-numero">2</span>
                    <div class="campo">
                        <label for="e-cedula">Cédula del operario</label>
                        <input type="text" id="e-cedula" name="cedula" value="<?php echo $esc($cedula); ?>"
                               placeholder="Ej. 1020304050" inputmode="numeric" autocomplete="off" required
                               <?php echo $numero !== '' && $cedula === '' ? 'autofocus' : ''; ?>>
                    </div>
                </div>
                <button type="submit" class="btn btn-acento enlace-buscar"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
            </form>

            <?php if ($numero !== '' || $cedula !== ''): ?>
                <div class="enlace-tarjetas">
                    <!-- LA FACTURA -->
                    <section class="enlace-tarjeta<?php echo $numero !== '' && !$factura ? ' enlace-error' : ''; ?>">
                        <h3><i class="fa-solid fa-file-invoice-dollar"></i> Factura</h3>
                        <?php if ($numero === ''): ?>
                            <p class="enlace-vacio">Escribí el número de la factura.</p>
                        <?php elseif (!$factura): ?>
                            <p class="enlace-vacio"><strong><?php echo $esc($numero); ?></strong> no está en el Consolidado MR. Revisá el número, o subí el Consolidado MR que la trae.</p>
                        <?php else: ?>
                            <p class="enlace-titulo"><?php echo $esc($factura['referencia'] ?: $factura['factura']); ?></p>
                            <dl class="enlace-datos">
                                <div><dt>Cliente</dt><dd><?php echo $esc($factura['nombre_cliente']); ?></dd></div>
                                <div><dt>Ciudad</dt><dd><?php echo $esc($factura['poblacion']); ?></dd></div>
                                <div><dt>Fecha</dt><dd><?php echo $fecha($factura['fecha_factura']); ?></dd></div>
                                <div><dt>Valor neto</dt><dd><?php echo $esc($plata($factura['valor_neto'])); ?></dd></div>
                                <div><dt>Pedido</dt><dd><?php echo $esc($factura['doc_ventas'] ?: '—'); ?></dd></div>
                                <div><dt>Orden de compra</dt><dd><?php echo $esc($factura['pedido_cliente'] ?: '—'); ?></dd></div>
                                <div><dt>Productos</dt><dd><?php echo $mil($factura['renglones']); ?> renglón(es) · <?php echo $mil($factura['unidades']); ?> unidades</dd></div>
                                <div><dt>Guía</dt><dd><?php echo $esc($factura['envio']['guia'] ?: '—'); ?><?php echo $factura['envio']['transportadora'] ? ' · ' . $esc($factura['envio']['transportadora']) : ''; ?><?php echo $factura['envio']['estado'] ? ' · ' . $esc($factura['envio']['estado']) : ''; ?></dd></div>
                            </dl>
                            <?php if ($factura['responsable']): ?>
                                <p class="enlace-ya">
                                    <i class="fa-solid fa-user-check"></i>
                                    Ya está enlazada a <strong><?php echo $esc($factura['responsable']['nombre']); ?></strong>
                                    desde el <?php echo $fecha($factura['responsable']['fecha_enlace'], true); ?>.
                                    Si la enlazás de nuevo, se reemplaza.
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>

                    <!-- EL OPERARIO -->
                    <section class="enlace-tarjeta<?php echo $cedula !== '' && (!$persona || $persona['estado'] !== 'Activo') ? ' enlace-error' : ''; ?>">
                        <h3><i class="fa-solid fa-user"></i> Operario</h3>
                        <?php if ($cedula === ''): ?>
                            <p class="enlace-vacio">Escribí la cédula del operario.</p>
                        <?php elseif (!$persona): ?>
                            <p class="enlace-vacio">
                                No hay nadie en Personal con la cédula <strong><?php echo $esc($cedula); ?></strong>.
                                <?php if (tienePermiso('modulo_personal')): ?>
                                    Cargale la cédula en <a href="<?php echo BASE_URL; ?>/personal">Personal</a>.
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <p class="enlace-titulo"><?php echo $esc($persona['nombre']); ?></p>
                            <dl class="enlace-datos">
                                <div><dt>Cédula</dt><dd><?php echo $esc($persona['documento']); ?></dd></div>
                                <div><dt>Cargo</dt><dd><?php echo $esc($persona['cargo'] ?: '—'); ?></dd></div>
                                <div><dt>Estado</dt><dd><?php echo $esc($persona['estado']); ?></dd></div>
                            </dl>
                            <?php if ($persona['estado'] !== 'Activo'): ?>
                                <p class="enlace-ya enlace-ya-error">Está inactivo en Personal: no se le pueden enlazar facturas.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>
                </div>

                <?php if ($puedeEnlazar): ?>
                    <form action="<?php echo BASE_URL; ?>/enlazar-facturas/acciones" method="POST" class="enlace-confirmar">
                        <?php campoCSRF(); ?>
                        <input type="hidden" name="accion" value="enlazar">
                        <input type="hidden" name="factura" value="<?php echo $esc($factura['factura']); ?>">
                        <input type="hidden" name="id_personal" value="<?php echo (int) $persona['id_personal']; ?>">
                        <input type="hidden" name="cedula" value="<?php echo $esc($cedula); ?>">
                        <!-- EL NÚMERO DE CAJAS DEL PEDIDO (2026-10-05): por ahora solo se ve en la tabla de abajo. -->
                        <div class="enlace-paso">
                            <span class="enlace-numero">3</span>
                            <div class="campo">
                                <label for="e-cajas">Número de cajas del pedido</label>
                                <input type="number" id="e-cajas" name="cajas" min="1" max="9999" step="1" required
                                       value="<?php echo $esc($factura['responsable']['cajas'] ?? ''); ?>"
                                       placeholder="Ej. 12" inputmode="numeric" autocomplete="off" autofocus>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primario btn-enlazar">
                            <i class="fa-solid fa-link"></i> Enlazar factura
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <h3 class="enlace-subtitulo">Últimos enlaces</h3>
            <?php if (!$enlaces): ?>
                <p class="enlace-vacio">Todavía no se enlazó ninguna factura.</p>
            <?php else: ?>
                <div class="tabla-caja">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th class="num">Fecha</th>
                                <th>Factura</th>
                                <th>Cliente</th>
                                <th>Responsable</th>
                                <th>Cédula</th>
                                <th class="num">Cajas</th>
                                <th>Registró</th>
                                <th class="centro">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enlaces as $e): ?>
                                <tr>
                                    <td class="num"><?php echo $fecha($e['fecha_enlace'], true); ?></td>
                                    <td><strong><?php echo $esc($e['referencia'] ?: $e['factura']); ?></strong></td>
                                    <td><?php echo $esc($e['cliente'] ?: '—'); ?></td>
                                    <td><?php echo $esc($e['nombre']); ?></td>
                                    <td><?php echo $esc($e['documento']); ?></td>
                                    <td class="num"><?php echo $e['cajas'] !== null ? $mil($e['cajas']) : '—'; ?></td>
                                    <td><?php echo $esc($e['registrado_por'] ?: '—'); ?></td>
                                    <td class="centro">
                                        <form action="<?php echo BASE_URL; ?>/enlazar-facturas/acciones" method="POST" style="display:inline;"
                                              data-confirmar="La factura queda sin responsable de empaque." data-titulo="¿Quitar el responsable?" data-aceptar="Sí, quitarlo" data-peligro>
                                            <?php campoCSRF(); ?>
                                            <input type="hidden" name="accion" value="quitar">
                                            <input type="hidden" name="factura" value="<?php echo $esc($e['factura']); ?>">
                                            <button type="submit" class="btn btn-chico" title="Quitar el enlace"><i class="fa-solid fa-link-slash"></i> Quitar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

</body>
</html>
