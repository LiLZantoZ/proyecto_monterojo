<?php
// modules/consolidados/views/maestro.php
// El maestro de productos: SKU, EAN, PLU, descripción, empaque, unidades por caja y peso.
//
// Existe porque el Consolidado que manda la cadena trae el PLU y el EAN, pero la columna de
// descripción viene vacía y no hay ninguna con las unidades por caja. Sin ese dato no se puede
// convertir a cajas, que es en lo que trabajan tanto el elevador como el picker.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_consolidados.php';

requierePermiso('modulo_maestro', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$busqueda = trim($_GET['q'] ?? '');
// 'faltantes' muestra solo los productos del Consolidado cargado que no se pueden convertir a
// cajas: es la lista de lo que hay que conseguir, que es para lo que se entra a esta pantalla.
$soloFaltantes = ($_GET['ver'] ?? '') === 'faltantes';

if ($soloFaltantes) {
    // La resolución del maestro mira EAN y PLU, así que la lista de faltantes se arma con la
    // misma función y no con un LEFT JOIN por PLU, que daría un resultado distinto al de las
    // pantallas de Consolidados y Picking.
    //
    // Sin filtrar por una sola carga: los archivos se acumulan (ver importarConsolidado), así que
    // "lo que falta" es sobre TODO lo pendiente, no solo la última importación.
    $stmt = $pdo->query(
        "SELECT plu, ean_item, SUM(unidades) AS unidades_pedidas
         FROM consolidado_lineas WHERE despachado = 0
         GROUP BY plu, ean_item ORDER BY unidades_pedidas DESC"
    );

    $mapa = mapaMaestro($pdo);
    $productos = [];
    foreach ($stmt as $fila) {
        $p = productoDelMaestro($mapa, $fila['ean_item'], $fila['plu']);
        if ($p !== null && !empty($p['unidades_por_caja'])) {
            continue;
        }
        $productos[] = [
            'sku'               => $p['sku'] ?? null,
            'ean'               => $fila['ean_item'],
            'plu'               => $fila['plu'],
            'descripcion'       => $p['descripcion'] ?? null,
            'unidades_por_caja' => $p['unidades_por_caja'] ?? null,
            'presentacion'      => $p['presentacion'] ?? null,
            'peso_unidad_kg'    => $p['peso_unidad_kg'] ?? null,
            'linea'             => $p['linea'] ?? null,
            'unidades_pedidas'  => (int) $fila['unidades_pedidas'],
        ];
    }
} else {
    $sql = "SELECT sku, ean, plu, descripcion, unidades_por_caja, presentacion, peso_unidad_kg,
                   linea, NULL AS unidades_pedidas
            FROM maestro_productos";
    $params = [];
    if ($busqueda !== '') {
        // Un marcador por columna: las sentencias las prepara MySQL (EMULATE_PREPARES en false)
        // y ahí un nombre repetido da "Invalid parameter number".
        $sql .= " WHERE sku LIKE :q_sku OR plu LIKE :q_plu OR ean LIKE :q_ean OR descripcion LIKE :q_desc";
        $patron = '%' . $busqueda . '%';
        $params = [':q_sku' => $patron, ':q_plu' => $patron, ':q_ean' => $patron, ':q_desc' => $patron];
    }
    $sql .= " ORDER BY descripcion IS NULL, descripcion, sku";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll();
}

$totalMaestro = (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos")->fetchColumn();
$conUnidades  = (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos WHERE unidades_por_caja > 0")->fetchColumn();
$conPlu       = (int) $pdo->query("SELECT COUNT(*) FROM maestro_productos WHERE plu IS NOT NULL")->fetchColumn();
$faltantes    = pluSinMaestro($pdo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maestro de productos · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Maestro de productos</h2>
                <div class="modulo-acciones">
                    <a class="btn" href="<?php echo BASE_URL; ?>/modules/consolidados/views/consolidados.php">
                        <i class="fa-solid fa-arrow-left"></i> Volver a Consolidados
                    </a>
                    <button type="button" class="btn" data-abrir="modal-maestro">
                        <i class="fa-solid fa-table-list"></i> Cargar planilla
                    </button>
                    <button type="button" class="btn btn-primario" data-abrir="modal-sap">
                        <i class="fa-solid fa-file-arrow-up"></i> Cargar desde SAP
                    </button>
                </div>
            </header>

            <div class="pastillas">
                <span class="pastilla">Productos <strong><?php echo number_format($totalMaestro, 0, ',', '.'); ?></strong></span>
                <span class="pastilla">Con unidades por caja <strong><?php echo number_format($conUnidades, 0, ',', '.'); ?></strong></span>
                <span class="pastilla">Cruzados con la cadena <strong><?php echo number_format($conPlu, 0, ',', '.'); ?></strong></span>
                <?php if ($faltantes > 0): ?>
                    <span class="pastilla pastilla-alerta">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Faltan para el Consolidado <strong><?php echo $faltantes; ?></strong>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($totalMaestro === 0): ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong>El maestro está vacío.</strong>
                        Hasta que se cargue, el Consolidado y Picking muestran las unidades pero no las
                        cajas ni los saldos, porque no hay con qué convertirlas.
                        Empezá por <strong>Cargar desde SAP</strong>.
                    </div>
                </div>
            <?php endif; ?>

            <div class="filtros">
                <?php if ($faltantes > 0 || $soloFaltantes): ?>
                    <?php if ($soloFaltantes): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/modules/consolidados/views/maestro.php">
                            <i class="fa-solid fa-list"></i> Ver todo el maestro
                        </a>
                    <?php else: ?>
                        <a class="btn btn-acento" href="<?php echo BASE_URL; ?>/modules/consolidados/views/maestro.php?ver=faltantes">
                            <i class="fa-solid fa-triangle-exclamation"></i> Ver solo los que faltan (<?php echo $faltantes; ?>)
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$soloFaltantes): ?>
                    <form class="filtro" method="GET" style="flex: 1;">
                        <label for="q">Buscar por SKU, PLU, EAN o descripción</label>
                        <input type="text" name="q" id="q" value="<?php echo htmlspecialchars($busqueda); ?>"
                               placeholder="Ej. 36373, 3577953 o «lima limón»">
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($soloFaltantes): ?>
                <div class="aviso aviso-info">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Estos son los productos del Consolidado cargado que todavía no se pueden convertir
                        a cajas, ordenados por cuántas unidades se pidieron. Si el <strong>SKU</strong> sale
                        vacío es que ese producto no vino en el export de SAP; si sale con SKU pero sin
                        unidades por caja, es que su nombre no traía el empaque (<code>PX20</code>,
                        <code>BX6x16</code>) y hay que completarlo con una planilla.
                    </div>
                </div>
            <?php endif; ?>

            <div class="tabla-caja">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>EAN</th>
                            <th>PLU</th>
                            <th>Descripción</th>
                            <th class="centro">Empaque</th>
                            <th class="num">Uds. por caja</th>
                            <th class="num">Peso unidad</th>
                            <th>Línea</th>
                            <?php if ($soloFaltantes): ?>
                                <th class="num">Unidades pedidas</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productos)): ?>
                            <tr>
                                <td colspan="<?php echo $soloFaltantes ? 9 : 8; ?>">
                                    <p class="tabla-vacia">
                                        <?php if ($soloFaltantes): ?>
                                            No falta ninguno: el maestro cubre todo el Consolidado cargado.
                                        <?php elseif ($busqueda !== ''): ?>
                                            Ningún producto coincide con «<?php echo htmlspecialchars($busqueda); ?>».
                                        <?php else: ?>
                                            El maestro todavía está vacío. Usá <strong>Cargar desde SAP</strong>.
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($productos as $p): ?>
                                <?php $falta = empty($p['unidades_por_caja']); ?>
                                <tr class="<?php echo $falta ? 'fila-sin-maestro' : ''; ?>">
                                    <td><?php echo $p['sku'] !== null ? htmlspecialchars($p['sku']) : '<span class="sin-dato">—</span>'; ?></td>
                                    <td><?php echo $p['ean'] !== null ? htmlspecialchars($p['ean']) : '<span class="sin-dato">—</span>'; ?></td>
                                    <td><?php echo $p['plu'] !== null ? htmlspecialchars($p['plu']) : '<span class="sin-dato">—</span>'; ?></td>
                                    <td><?php echo $p['descripcion'] !== null ? htmlspecialchars($p['descripcion']) : '<span class="sin-dato">—</span>'; ?></td>
                                    <td class="centro"><?php echo $p['presentacion'] !== null ? htmlspecialchars($p['presentacion']) : '<span class="sin-dato">—</span>'; ?></td>
                                    <td class="num">
                                        <?php echo $falta
                                            ? '<span class="sin-dato">falta</span>'
                                            : '<strong>' . (int) $p['unidades_por_caja'] . '</strong>'; ?>
                                    </td>
                                    <td class="num">
                                        <?php echo $p['peso_unidad_kg'] !== null
                                            ? number_format((float) $p['peso_unidad_kg'], 3, ',', '.') . ' kg'
                                            : '<span class="sin-dato">—</span>'; ?>
                                    </td>
                                    <td><?php echo $p['linea'] !== null ? htmlspecialchars($p['linea']) : '<span class="sin-dato">—</span>'; ?></td>
                                    <?php if ($soloFaltantes): ?>
                                        <td class="num"><?php echo number_format((int) $p['unidades_pedidas'], 0, ',', '.'); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- MODAL: CARGAR DESDE SAP -->
<div class="modal-fondo" id="modal-sap">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Cargar maestro desde SAP</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/modules/consolidados/controller_consolidados.php"
              method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="importar_maestro_sap">

            <div class="modal-cuerpo">
                <div class="campo">
                    <label for="archivo-sap">Export de facturación de SAP (.xlsx)</label>
                    <input type="file" id="archivo-sap" name="archivo" accept=".xlsx,.xls" required>
                    <span class="ayuda">
                        Es el archivo con una fila por línea facturada. Se leen las columnas
                        <strong>Material</strong>, <strong>Texto breve de material</strong>,
                        <strong>Código EAN/UPC</strong>, <strong>Ctd.facturada</strong> y
                        <strong>Peso bruto</strong>, buscadas por su nombre.
                    </span>
                </div>

                <div class="aviso aviso-info" style="margin-bottom: 0;">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Las <strong>unidades por caja</strong> salen del final del nombre del material
                        (<code>PX20</code> = 20 por caja, <code>BX6x16</code> = 16). El
                        <strong>EAN</strong> es lo que permite reconocer el producto en el Consolidado
                        de la cadena, que no trae el SKU.
                        <br>El maestro no se reemplaza: se agrega y se actualiza.
                    </div>
                </div>
            </div>

            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario">Cargar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: CARGAR UNA PLANILLA ARMADA A MANO -->
<div class="modal-fondo" id="modal-maestro">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Cargar planilla</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/modules/consolidados/controller_consolidados.php"
              method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="importar_maestro">

            <div class="modal-cuerpo">
                <div class="campo">
                    <label for="archivo-maestro">Archivo de Excel (.xlsx)</label>
                    <input type="file" id="archivo-maestro" name="archivo" accept=".xlsx,.xls" required>
                    <span class="ayuda">
                        Para completar lo que SAP no da: la <strong>línea</strong> de cada producto, o
                        las <strong>unidades por caja</strong> de los materiales cuyo nombre no traía el
                        empaque. Columnas reconocidas por su nombre, en cualquier orden:
                        <strong>SKU</strong> · <strong>PLU</strong> · <strong>Descripción</strong>
                        · <strong>Unidades por caja</strong> · <strong>Línea</strong> · <strong>EAN</strong>.
                    </span>
                </div>

                <div class="aviso aviso-info" style="margin-bottom: 0;">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Una fila con <strong>SKU</strong> crea el producto o lo actualiza. Una fila con
                        <strong>solo PLU</strong> actualiza un producto que ya esté en el maestro: sin SKU
                        no se puede crear, porque es la clave y no se puede inventar.
                    </div>
                </div>
            </div>

            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario">Cargar</button>
            </div>
        </form>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>

</body>
</html>
