<?php
// modules/consolidados/views/consolidados.php
// El consolidado para el elevador: qué hay que bajar de bodega para cada CEDI, en cajas y saldos.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_consolidados.php';

requierePermiso('modulo_consolidados', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

// Ya no "la carga vigente": los archivos se acumulan (ver importarConsolidado en
// model_consolidados_import.php), así que acá se juntan los pendientes de TODAS las que sigan
// activas. $cargasActivas es la lista completa (para la cabecera, "3 archivos"); $hayPendientes
// es el gate simple que reemplaza al viejo "si hay carga" en el resto de la pantalla.
$cargasActivas  = cargasActivas($pdo);
$hayPendientes  = !empty($cargasActivas);

$filtros = [
    'cedi'  => trim($_GET['cedi'] ?? ''),
    'linea' => trim($_GET['linea'] ?? ''),
    'plu'   => trim($_GET['plu'] ?? ''),
];

$porCedi           = $hayPendientes ? consolidadoPorCedi($pdo, $filtros) : [];
$cedisDisponibles  = $hayPendientes ? cedisPendientes($pdo) : [];
$lineasDisponibles = lineasDelMaestro($pdo);
$sinMaestro        = $hayPendientes ? pluSinMaestro($pdo) : 0;

// Totales generales: se suman los de cada CEDI ya calculados, para no recorrer las filas dos veces.
$totalGeneral = ['unidades' => 0, 'cajas' => 0, 'saldos' => 0, 'peso_kg' => 0, 'productos' => 0];
$totalesPorCedi = [];
foreach ($porCedi as $cedi => $filas) {
    $t = totalesDelGrupo($filas);
    $totalesPorCedi[$cedi] = $t;
    $totalGeneral['unidades']  += $t['unidades'];
    $totalGeneral['cajas']     += $t['cajas'];
    $totalGeneral['saldos']    += $t['saldos'];
    $totalGeneral['peso_kg']   += $t['peso_kg'];
    $totalGeneral['productos'] += $t['productos'];
}

// La URL para volver acá conservando los filtros; la usan los enlaces de PDF.
$filtrosEnUrl = http_build_query(array_filter($filtros));

// Importación a la espera de que se conteste "¿ya estaba cargado, lo subo igual?".
// El controlador la deja en la sesión junto con el archivo, y acá solo se muestra la pregunta.
$pendiente = $_SESSION['importacion_pendiente'] ?? null;
$hayQueConfirmar = $pendiente && ($_GET['confirmar'] ?? '') === 'duplicado';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidados · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Consolidados</h2>
                <div class="modulo-acciones">
                    <?php if (tienePermiso('modulo_maestro')): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/modules/consolidados/views/maestro.php">
                            <i class="fa-solid fa-list-check"></i> Maestro de productos
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($porCedi)): ?>
                        <a class="btn"
                           href="<?php echo BASE_URL; ?>/modules/consolidados/controller_consolidados.php?accion=pdf&<?php echo $filtrosEnUrl; ?>">
                            <i class="fa-solid fa-file-pdf"></i> PDF de todos los CEDI
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primario" data-abrir="modal-importar">
                        <i class="fa-solid fa-file-arrow-up"></i> Importar Consolidado
                    </button>
                </div>
            </header>

            <?php if ($hayPendientes): ?>
                <?php
                // El tooltip lista cada archivo activo con su fecha: la pastilla sola dice "3
                // archivos", y sin esto no habría forma de ver CUÁLES sin ir a Picking a mirar
                // fila por fila.
                $tituloArchivos = implode("\n", array_map(
                    fn($c) => $c['nombre_archivo'] . ' · ' . date('d/m/Y H:i', strtotime($c['fecha_carga'])),
                    $cargasActivas
                ));
                ?>
                <div class="pastillas">
                    <span class="pastilla" title="<?php echo htmlspecialchars($tituloArchivos); ?>">
                        <?php echo count($cargasActivas) === 1 ? 'Archivo' : 'Archivos activos'; ?>
                        <strong><?php echo count($cargasActivas) === 1
                            ? htmlspecialchars($cargasActivas[0]['nombre_archivo'])
                            : count($cargasActivas); ?></strong>
                    </span>
                    <span class="pastilla">CEDI <strong><?php echo count($cedisDisponibles); ?></strong></span>
                    <span class="pastilla">Unidades <strong><?php echo number_format($totalGeneral['unidades'], 0, ',', '.'); ?></strong></span>
                    <span class="pastilla">Cajas <strong><?php echo number_format($totalGeneral['cajas'], 0, ',', '.'); ?></strong></span>
                    <span class="pastilla">Saldos <strong><?php echo number_format($totalGeneral['saldos'], 0, ',', '.'); ?></strong></span>
                    <?php if ($totalGeneral['peso_kg'] > 0): ?>
                        <span class="pastilla" title="Peso bruto de lo pedido, según el peso por unidad del maestro">
                            Peso <strong><?php echo number_format($totalGeneral['peso_kg'], 0, ',', '.'); ?> kg</strong>
                        </span>
                    <?php endif; ?>
                    <?php if ($sinMaestro > 0): ?>
                        <span class="pastilla pastilla-alerta">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            PLU sin maestro <strong><?php echo $sinMaestro; ?></strong>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($sinMaestro > 0): ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong><?php echo $sinMaestro; ?> PLU de este archivo no están en el maestro de productos.</strong>
                        El Consolidado que manda la cadena trae el PLU y las unidades, pero no la descripción
                        ni las unidades por caja, así que esas filas se muestran con una raya en vez de cajas
                        y <strong>no suman en los totales</strong>.
                        <?php if (tienePermiso('modulo_maestro')): ?>
                            Cárgalos en <a href="<?php echo BASE_URL; ?>/modules/consolidados/views/maestro.php">Maestro de productos</a>
                            y las cajas aparecen solas, sin volver a importar el Consolidado.
                        <?php endif; ?>
                    </div>
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

                    <?php if (!empty($lineasDisponibles)): ?>
                        <div class="filtro">
                            <label for="f-linea">Línea</label>
                            <select name="linea" id="f-linea">
                                <option value="">Todas</option>
                                <?php foreach ($lineasDisponibles as $l): ?>
                                    <option value="<?php echo htmlspecialchars($l); ?>"
                                        <?php echo $filtros['linea'] === $l ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($l); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="filtro" style="flex: 1;">
                        <label for="f-plu">Buscar por PLU, SKU o descripción</label>
                        <input type="text" name="plu" id="f-plu" value="<?php echo htmlspecialchars($filtros['plu']); ?>"
                               placeholder="Ej. 3577953">
                    </div>

                    <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                    <?php if (array_filter($filtros)): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/modules/consolidados/views/consolidados.php">Limpiar</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if (!$hayPendientes): ?>
                <div class="tabla-caja">
                    <p class="tabla-vacia">
                        No hay ningún pedido pendiente.<br>
                        Usa <strong>Importar Consolidado</strong> para subir un archivo.
                    </p>
                </div>

            <?php elseif (empty($porCedi)): ?>
                <div class="tabla-caja">
                    <p class="tabla-vacia">Ningún producto coincide con el filtro.</p>
                </div>

            <?php else: ?>
                <?php foreach ($porCedi as $cedi => $filas): $t = $totalesPorCedi[$cedi]; ?>
                    <!-- <details> y no un div con JavaScript: el desplegar/plegar lo hace el
                         navegador, funciona con el teclado sin que haya que programarlo, y
                         "cerrado" es simplemente NO poner el atributo open. Como cada filtro
                         recarga la página (el formulario es un GET), quedan cerrados al entrar y
                         después de filtrar sin necesidad de guardar ningún estado.

                         El resumen de la cabecera —productos, unidades, cajas, saldos— sigue a la
                         vista con el grupo cerrado, así que se puede leer el resultado de un
                         filtro sin abrir nada. -->
                    <details class="grupo-desplegable">
                        <summary class="grupo-cabecera">
                            <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>
                            <div class="grupo-titulo">
                                <div class="grupo-nombre"><?php echo htmlspecialchars($cedi); ?></div>
                                <div class="grupo-resumen">
                                    <?php echo $t['productos']; ?> productos ·
                                    <?php echo number_format($t['unidades'], 0, ',', '.'); ?> unidades ·
                                    <?php echo number_format($t['cajas'], 0, ',', '.'); ?> cajas ·
                                    <?php echo number_format($t['saldos'], 0, ',', '.'); ?> saldos
                                    <?php if ($t['peso_kg'] > 0): ?>
                                        · <?php echo number_format($t['peso_kg'], 0, ',', '.'); ?> kg
                                    <?php endif; ?>
                                    <?php if ($t['sin_maestro'] > 0): ?>
                                        · <?php echo $t['sin_maestro']; ?> sin maestro
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a class="btn btn-chico"
                               href="<?php echo BASE_URL; ?>/modules/consolidados/controller_consolidados.php?accion=pdf&cedi=<?php echo urlencode($cedi); ?><?php echo $filtros['linea'] !== '' ? '&linea=' . urlencode($filtros['linea']) : ''; ?>">
                                <i class="fa-solid fa-file-pdf"></i> PDF de este CEDI
                            </a>
                        </summary>

                        <div class="tabla-caja">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th>PLU</th>
                                        <th>SKU</th>
                                        <th>Descripción</th>
                                        <th class="num">Unidades</th>
                                        <th class="num">Cajas</th>
                                        <th class="num">Saldos</th>
                                        <th class="centro">Ptos. venta</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filas as $f): ?>
                                        <tr class="<?php echo $f['sin_maestro'] ? 'fila-sin-maestro' : ''; ?>">
                                            <td><?php echo htmlspecialchars($f['plu']); ?></td>
                                            <td>
                                                <?php echo $f['sku'] !== null
                                                    ? htmlspecialchars($f['sku'])
                                                    : '<span class="sin-dato">—</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo $f['descripcion'] !== null
                                                    ? htmlspecialchars($f['descripcion'])
                                                    : '<span class="sin-dato">sin descripción en el maestro</span>'; ?>
                                            </td>
                                            <td class="num"><?php echo number_format((int) $f['unidades'], 0, ',', '.'); ?></td>
                                            <?php if ($f['sin_maestro']): ?>
                                                <td class="num sin-dato" title="Falta cargar las unidades por caja de este PLU">—</td>
                                                <td class="num sin-dato">—</td>
                                            <?php else: ?>
                                                <td class="num"><strong><?php echo number_format((int) $f['cajas'], 0, ',', '.'); ?></strong></td>
                                                <td class="num"><?php echo (int) $f['saldos'] > 0 ? number_format((int) $f['saldos'], 0, ',', '.') : '<span class="sin-dato">0</span>'; ?></td>
                                            <?php endif; ?>
                                            <td class="centro"><?php echo (int) $f['puntos_venta']; ?></td>
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

<!-- MODAL: IMPORTAR EL CONSOLIDADO -->
<div class="modal-fondo" id="modal-importar">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Importar Consolidado</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/modules/consolidados/controller_consolidados.php"
              method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="importar_consolidado">

            <div class="modal-cuerpo">
                <div class="campo">
                    <label for="archivo">Archivo de Excel (.xlsx)</label>
                    <input type="file" id="archivo" name="archivo" accept=".xlsx,.xls" required>
                    <span class="ayuda">
                        Se lee la <strong>primera hoja</strong> del archivo tal como lo manda la cadena.
                        Las columnas se buscan por su nombre, así que no importa en qué orden vengan.
                    </span>
                </div>

                <div class="aviso aviso-info" style="margin-bottom: 0;">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Este archivo se <strong>agrega</strong> a lo que ya está pendiente, no lo
                        reemplaza: los pedidos que traiga se suman a los que hubiera de otros
                        archivos, diferenciados por su fecha en Picking y en Consolidados.
                    </div>
                </div>
            </div>

            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario">Importar</button>
            </div>
        </form>
    </div>
</div>

<?php if ($hayQueConfirmar): ?>
<!-- AVISO: EL ARCHIVO YA ESTABA CARGADO
     Se abre solo (la clase `active` viene puesta desde PHP) y NO se puede cerrar con la X ni con
     Escape ni tocando el fondo: hay un archivo esperando en el servidor y una de las dos
     respuestas tiene que llegar, o queda ahí colgado. Por eso tampoco lleva `data-cerrar`. -->
<div class="modal-fondo active" id="modal-duplicado" data-obligatorio>
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-triangle-exclamation"></i> Este archivo ya fue subido</h2>
        </div>

        <div class="modal-cuerpo">
            <p class="confirmar-pregunta">
                <strong><?php echo htmlspecialchars($pendiente['nombre']); ?></strong>
                tiene exactamente la misma información que el Consolidado que ya está cargado.
            </p>

            <div class="pastillas" style="margin-bottom: 16px;">
                <span class="pastilla">
                    Cargado como <strong><?php echo htmlspecialchars($pendiente['carga_previa']['nombre_archivo']); ?></strong>
                </span>
                <span class="pastilla">
                    El <strong><?php echo date('d/m/Y \a \l\a\s H:i', strtotime($pendiente['carga_previa']['fecha_carga'])); ?></strong>
                </span>
                <?php if (!empty($pendiente['carga_previa']['nombre_usuario'])): ?>
                    <span class="pastilla">
                        Por <strong><?php echo htmlspecialchars($pendiente['carga_previa']['nombre_usuario']); ?></strong>
                    </span>
                <?php endif; ?>
                <span class="pastilla">
                    <strong><?php echo number_format((int) $pendiente['carga_previa']['filas'], 0, ',', '.'); ?></strong> líneas
                </span>
            </div>

            <div class="aviso aviso-atencion" style="margin-bottom: 0;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    Como los archivos ya no se reemplazan, volver a importarlo <strong>agrega una
                    segunda copia</strong> de estos mismos pedidos: van a aparecer duplicados en
                    Picking y en Consolidados, cada uno pidiendo el doble de lo que corresponde.
                </div>
            </div>
        </div>

        <div class="modal-pie">
            <!-- Dos formularios y no uno con dos submit: cada botón manda su propia respuesta, y
                 así ninguno depende de un `value` que un cambio posterior podría dejar vacío. -->
            <form action="<?php echo BASE_URL; ?>/modules/consolidados/controller_consolidados.php" method="POST">
                <?php campoCSRF(); ?>
                <input type="hidden" name="accion" value="resolver_duplicado">
                <input type="hidden" name="respuesta" value="no">
                <button type="submit" class="btn">No</button>
            </form>
            <form action="<?php echo BASE_URL; ?>/modules/consolidados/controller_consolidados.php" method="POST">
                <?php campoCSRF(); ?>
                <input type="hidden" name="accion" value="resolver_duplicado">
                <input type="hidden" name="respuesta" value="si">
                <button type="submit" class="btn btn-acento">Sí, subirlo igual</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>/assets/js/desplegables.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/desplegables.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>

</body>
</html>
