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
// De qué cadena es cada CEDI, para decirlo en su cabecera: los del Éxito y los de Cencosud
// conviven en esta lista y sin esto no hay forma de saber cuál es cuál.
$cadenasDeCedi     = $hayPendientes ? cadenasPorCedi($pdo) : [];
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
                        <a class="btn" href="<?php echo BASE_URL; ?>/maestro">
                            <i class="fa-solid fa-list-check"></i> Maestro de productos
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($porCedi)): ?>
                        <!-- Los dos consolidados son el MISMO pedido visto de dos formas:
                             el de ALISTAMIENTO junta todo lo del CEDI en un total por producto
                             (el papel del elevador, para bajar de bodega una sola vez), y el de
                             RÓTULOS lo abre por punto de venta (lo que se entrega o se le muestra
                             a la cadena). Ver consolidadoExternoPorCedi().

                             Hasta el 2026-09-22 se llamaban "interno" y "externo". Se renombraron
                             a pedido del usuario porque el nombre nuevo dice PARA QUÉ sirve cada
                             uno, que es lo que hay que saber al elegir cuál imprimir. Las
                             acciones y los nombres de función siguen diciendo interno/externo: son
                             identificadores, y cambiarlos rompería los enlaces guardados. -->
                        <a class="btn"
                           href="<?php echo BASE_URL; ?>/consolidados/acciones?accion=pdf&<?php echo $filtrosEnUrl; ?>">
                            <i class="fa-solid fa-file-pdf"></i> Consolidado para alistamiento
                        </a>
                        <a class="btn"
                           href="<?php echo BASE_URL; ?>/consolidados/acciones?accion=pdf_externo&<?php echo $filtrosEnUrl; ?>">
                            <i class="fa-solid fa-shop"></i> Consolidado para rótulos
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primario" data-abrir="modal-importar-exito">
                        <i class="fa-solid fa-store"></i> Consolidado de Éxito
                    </button>
                    <button type="button" class="btn" data-abrir="modal-importar-otros">
                        <i class="fa-solid fa-users"></i> Consolidado de otros clientes
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
                            Cárgalos en <a href="<?php echo BASE_URL; ?>/maestro">Maestro de productos</a>
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
                        <a class="btn" href="<?php echo BASE_URL; ?>/consolidados">Limpiar</a>
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
                <!-- Tilda todos los CEDI que se están viendo (con un filtro puesto, solo los que
                     quedaron). Lo tildado alimenta la barra de descargas de abajo. -->
                <label class="seleccion-general">
                    <input type="checkbox" id="chk-todos-cedi">
                    Seleccionar todos <span>(<?php echo count($porCedi); ?> CEDI)</span>
                </label>

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
                            <!-- Tildarla no abre ni cierra el grupo: desplegables.js corta el clic
                                 de los controles que viven dentro de la cabecera. -->
                            <input type="checkbox" class="chk-cedi"
                                   data-cedi="<?php echo htmlspecialchars($cedi); ?>"
                                   title="Seleccionar este CEDI"
                                   aria-label="Seleccionar <?php echo htmlspecialchars($cedi); ?>">
                            <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>
                            <div class="grupo-titulo">
                                <div class="grupo-nombre">
                                    <?php echo htmlspecialchars($cedi); ?>
                                    <?php if (!empty($cadenasDeCedi[$cedi])): ?>
                                        <span class="cadena-etiqueta"><?php echo htmlspecialchars($cadenasDeCedi[$cedi]); ?></span>
                                    <?php endif; ?>
                                </div>
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
                               href="<?php echo BASE_URL; ?>/consolidados/acciones?accion=pdf&cedi=<?php echo urlencode($cedi); ?><?php echo $filtros['linea'] !== '' ? '&linea=' . urlencode($filtros['linea']) : ''; ?>">
                                <i class="fa-solid fa-file-pdf"></i> Alistamiento
                            </a>
                            <a class="btn btn-chico"
                               href="<?php echo BASE_URL; ?>/consolidados/acciones?accion=pdf_externo&cedi=<?php echo urlencode($cedi); ?><?php echo $filtros['linea'] !== '' ? '&linea=' . urlencode($filtros['linea']) : ''; ?>">
                                <i class="fa-solid fa-shop"></i> Rótulos
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

<?php if (!empty($porCedi)): ?>
<!-- BARRA DE DESCARGAS DE LOS CEDI TILDADOS
     La misma barra flotante de Picking, con las descargas de acá. Un solo formulario para los tres
     botones: cada uno manda su 'formato' como valor del botón, y los CEDI tildados los rellena
     scripts_consolidados.js en campos ocultos. Por formulario y no por fetch, para que el navegador
     lo trate como una descarga normal. -->
<div class="barra-seleccion" id="barra-seleccion" hidden>
    <div class="barra-seleccion-info">
        <strong id="barra-conteo">0</strong> CEDI seleccionado(s)
        <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
    </div>

    <div class="barra-seleccion-acciones">
        <form action="<?php echo BASE_URL; ?>/consolidados/acciones" method="POST" id="form-seleccion">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="pdf_seleccion">
            <input type="hidden" name="linea" value="<?php echo htmlspecialchars($filtros['linea']); ?>">
            <div id="campos-seleccion"></div>

            <button type="submit" class="btn btn-chico" name="formato" value="interno">
                <i class="fa-solid fa-file-pdf"></i> Consolidado para alistamiento
            </button>
            <button type="submit" class="btn btn-chico" name="formato" value="externo">
                <i class="fa-solid fa-shop"></i> Consolidado para rótulos
            </button>
            <button type="submit" class="btn btn-chico" name="formato" value="productos"
                    title="Todos los productos de los CEDI seleccionados, sumados en una sola tabla">
                <i class="fa-solid fa-table-list"></i> Todos los productos
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: IMPORTAR EL CONSOLIDADO -->
<?php
// Dos entradas para importar: la del Éxito y la de otros clientes. Las dos usan el MISMO import
// (reconoce el formato solo y clasifica cada línea por su empresa compradora), así que el botón es
// un punto de entrada claro, no una lógica distinta. Se arma con un include para no repetir el
// formulario: cambia solo el título, el ícono y el texto de ayuda.
$modalImportar = function ($id, $titulo, $icono, $ayuda, $canal) { ?>
    <div class="modal-fondo" id="<?php echo $id; ?>">
        <div class="modal-caja">
            <div class="modal-cabecera">
                <h2><i class="fa-solid <?php echo $icono; ?>"></i> <?php echo $titulo; ?></h2>
                <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
            </div>
            <form action="<?php echo BASE_URL; ?>/consolidados/acciones"
                  method="POST" enctype="multipart/form-data">
                <?php campoCSRF(); ?>
                <input type="hidden" name="accion" value="importar_consolidado">
                <input type="hidden" name="canal" value="<?php echo $canal; ?>">

                <div class="modal-cuerpo">
                    <div class="campo">
                        <label>Archivo de Excel (.xlsx)</label>
                        <input type="file" name="archivo" accept=".xlsx,.xls" required>
                        <span class="ayuda"><?php echo $ayuda; ?></span>
                    </div>

                    <div class="aviso aviso-info" style="margin-bottom: 0;">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>
                            El formato se reconoce solo y <strong>cada línea se clasifica por su empresa
                            compradora</strong>: las del Éxito usan el empaque/cubicaje de las
                            <strong>excepciones de Éxito</strong> (si hay), y las demás usan el maestro base.
                            El archivo se <strong>agrega</strong> a lo pendiente, no lo reemplaza.
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
<?php };

$modalImportar(
    'modal-importar-exito',
    'Consolidado de Éxito',
    'fa-store',
    'El Consolidado que manda el Éxito (o el export de SAP de despachos directos del Éxito). Las columnas se buscan por su nombre, en cualquier orden.',
    'exito'
);
$modalImportar(
    'modal-importar-otros',
    'Consolidado de otros clientes',
    'fa-users',
    'El Consolidado de otras cadenas (Farmatodo, Cencosud…) o el export de facturación de SAP de clientes directos. Se reconoce solo.',
    'otros'
);
?>

<?php if ($hayQueConfirmar): ?>
<!-- AVISO: EL ARCHIVO YA ESTABA CARGADO
     Se abre solo (la clase `active` viene puesta desde PHP) y NO se puede cerrar con la X ni con
     Escape ni tocando el fondo: hay un archivo esperando en el servidor y una de las dos
     respuestas tiene que llegar, o queda ahí colgado. Por eso tampoco lleva `data-cerrar`. -->
<div class="modal-fondo active" id="modal-duplicado" data-obligatorio>
    <div class="modal-caja">
        <?php
        // Dos motivos distintos para preguntar lo mismo: el archivo es IDÉNTICO a uno ya cargado
        // (carga_previa), o TRAE ÓRDENES que ya están pendientes aunque el archivo sea otro
        // (ordenes_repetidas) — un Consolidado por zona con parte de las órdenes del completo.
        $repetidas = $pendiente['ordenes_repetidas'] ?? null;
        ?>
        <div class="modal-cabecera">
            <h2>
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?php echo $repetidas ? 'Estos pedidos ya están cargados' : 'Este archivo ya fue subido'; ?>
            </h2>
        </div>

        <div class="modal-cuerpo">
            <?php if ($repetidas): ?>
                <p class="confirmar-pregunta">
                    <strong><?php echo htmlspecialchars($pendiente['nombre']); ?></strong>
                    trae <strong><?php echo (int) $repetidas['ordenes']; ?></strong> orden(es) de compra, y
                    <strong><?php echo count($repetidas['repetidas']); ?></strong> de ellas ya están cargadas y
                    sin despachar.
                </p>

                <div class="tabla-caja" style="margin-bottom: 16px; max-height: 240px; overflow-y: auto;">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Orden de compra</th>
                                <th>Ya cargada en</th>
                                <th>El</th>
                                <th style="text-align: right;">Líneas</th>
                                <th style="text-align: right;">Unidades</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($repetidas['repetidas'] as $r): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($r['orden_compra']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($r['nombre_archivo']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($r['fecha_carga'])); ?></td>
                                    <td style="text-align: right;"><?php echo number_format((int) $r['lineas'], 0, ',', '.'); ?></td>
                                    <td style="text-align: right;"><?php echo number_format((int) $r['unidades'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
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
            <?php endif; ?>

            <div class="aviso aviso-atencion" style="margin-bottom: 0;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <?php if ($repetidas): ?>
                        Como los archivos ya no se reemplazan, subirlo <strong>agrega una segunda copia</strong>
                        de esos pedidos: van a aparecer duplicados en Picking y en Consolidados, cada uno
                        pidiendo el doble de lo que corresponde.
                        <?php if (count($repetidas['repetidas']) < (int) $repetidas['ordenes']): ?>
                            Las demás órdenes del archivo sí son nuevas, pero subirlo las carga a todas
                            juntas: no se puede importar solo una parte.
                        <?php endif; ?>
                    <?php else: ?>
                        Como los archivos ya no se reemplazan, volver a importarlo <strong>agrega una
                        segunda copia</strong> de estos mismos pedidos: van a aparecer duplicados en
                        Picking y en Consolidados, cada uno pidiendo el doble de lo que corresponde.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="modal-pie">
            <!-- Dos formularios y no uno con dos submit: cada botón manda su propia respuesta, y
                 así ninguno depende de un `value` que un cambio posterior podría dejar vacío. -->
            <form action="<?php echo BASE_URL; ?>/consolidados/acciones" method="POST">
                <?php campoCSRF(); ?>
                <input type="hidden" name="accion" value="resolver_duplicado">
                <input type="hidden" name="respuesta" value="no">
                <button type="submit" class="btn">No</button>
            </form>
            <form action="<?php echo BASE_URL; ?>/consolidados/acciones" method="POST">
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
<script src="<?php echo BASE_URL; ?>/modules/consolidados/layouts/scripts_consolidados.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/consolidados/layouts/scripts_consolidados.js'); ?>"></script>

</body>
</html>
