<?php
// modules/cajas_punto_venta/views/cajas_punto_venta.php
// Cuántas cajas le corresponden a cada punto de venta, CEDI por CEDI.
//
// Es la planilla del camión: el que carga y el que recibe en el CEDI cuentan BULTOS, no unidades.
// Las otras pantallas contestan "qué hay que bajar de bodega" (Consolidados) y "qué lleva cada
// pedido" (Picking); esta contesta una sola pregunta, que es la que se firma en la puerta:
// a esta tienda, ¿cuántas cajas?
//
// Solo salen los puntos de venta anexados a un CEDI de cadena; los clientes directos no tienen
// tiendas que listar. Ver cajasPorPuntoDeVenta() para cómo se distinguen.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_cajas_punto_venta.php';
require_once __DIR__ . '/../../consolidados/model_almacenes_exito.php';

requierePermiso('modulo_cajas_punto_venta', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$filtros = [
    'cedi'  => trim($_GET['cedi'] ?? ''),
    'punto' => trim($_GET['punto'] ?? ''),
];

$porCedi          = cajasPorPuntoDeVenta($pdo, $filtros);
$cedisDisponibles = cedisConPuntosDeVenta($pdo);
$almacenes        = resumenAlmacenesExito($pdo);

$totalGeneral = ['puntos' => 0, 'cajas' => 0, 'unidades' => 0, 'saldos' => 0, 'sin_maestro' => 0, 'total' => 0];
foreach ($porCedi as $datos) {
    foreach ($totalGeneral as $clave => $_) {
        $totalGeneral[$clave] += $datos['totales'][$clave];
    }
}

// Los filtros activos, para que los enlaces de descarga arrastren lo mismo que hay en pantalla.
$filtrosEnUrl = http_build_query(array_filter($filtros, fn($v) => $v !== ''));
$esc = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
$num = fn($n) => number_format((int) $n, 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cajas por punto de venta · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Cajas por punto de venta</h2>
                <div class="modulo-acciones">
                    <button type="button" class="btn" data-abrir="modal-almacenes">
                        <i class="fa-solid fa-store"></i> Almacenes Éxito
                    </button>
                    <?php if (!empty($porCedi)): ?>
                        <a class="btn"
                           href="<?php echo BASE_URL; ?>/cajas-punto-venta/acciones?accion=pdf&<?php echo $filtrosEnUrl; ?>">
                            <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if (isset($_GET['error'])): ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><?php echo $esc(obtenerMensajeSistema($_GET['error'])); ?></div>
                </div>
            <?php endif; ?>

            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Acá salen <strong>solo los puntos de venta anexados a un CEDI</strong>. Los
                    despachos directos a clientes propios —distribuidores, hoteles, personas— no
                    tienen tiendas que listar y se ven en <strong>Consolidados</strong>.
                </div>
            </div>

            <?php if ($totalGeneral['sin_maestro'] > 0): ?>
                <div class="aviso aviso-atencion">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong><?php echo $num($totalGeneral['sin_maestro']); ?></strong> línea(s)
                        corresponden a productos que no están en el maestro o no tienen unidades por
                        caja, así que <strong>no suman cajas</strong> en esta planilla. Cargalos en
                        <a href="<?php echo BASE_URL; ?>/maestro">Maestro
                        de productos</a> para que el conteo quede completo.
                    </div>
                </div>
            <?php endif; ?>

            <form method="GET" class="filtros">
                <div class="filtro">
                    <label for="f-cedi">CEDI</label>
                    <select name="cedi" id="f-cedi">
                        <option value="">Todos</option>
                        <?php foreach ($cedisDisponibles as $c): ?>
                            <option value="<?php echo $esc($c); ?>" <?php echo $filtros['cedi'] === $c ? 'selected' : ''; ?>>
                                <?php echo $esc($c); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro" style="flex: 1;">
                    <label for="f-punto">Punto de venta</label>
                    <input type="text" name="punto" id="f-punto" value="<?php echo $esc($filtros['punto']); ?>"
                           placeholder="Número o nombre. Para varios: 550, 4847, exito bello"
                           title="Se puede buscar más de un punto de venta a la vez, separados por coma.">
                </div>
                <button type="submit" class="btn btn-acento"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                <?php if ($filtrosEnUrl !== ''): ?>
                    <a class="btn" href="<?php echo BASE_URL; ?>/cajas-punto-venta">Limpiar</a>
                <?php endif; ?>
            </form>

            <?php if (empty($porCedi)): ?>
                <div class="tabla-caja">
                    <p class="tabla-vacia">
                        No hay puntos de venta que mostrar. O no hay pedidos pendientes de un CEDI,
                        o el filtro no coincide con ninguno.
                    </p>
                </div>
            <?php else: ?>
                <div class="pastillas">
                    <span class="pastilla"><?php echo $num(count($porCedi)); ?> CEDI</span>
                    <span class="pastilla"><?php echo $num($totalGeneral['puntos']); ?> puntos de venta</span>
                    <span class="pastilla pastilla-fuerte"><?php echo $num($totalGeneral['cajas']); ?> cajas</span>
                    <span class="pastilla"><?php echo $num($totalGeneral['unidades']); ?> unidades</span>
                </div>

                <?php foreach ($porCedi as $cedi => $datos): $t = $datos['totales']; ?>
                    <details class="grupo-desplegable" open>
                        <summary class="grupo-cabecera">
                            <i class="fa-solid fa-chevron-right grupo-flecha" aria-hidden="true"></i>
                            <div class="grupo-titulo">
                                <div class="grupo-nombre"><?php echo $esc($cedi); ?></div>
                                <div class="grupo-resumen">
                                    <?php echo $num($t['puntos']); ?> puntos de venta ·
                                    <?php echo $num($t['cajas']); ?> cajas ·
                                    <?php echo $num($t['unidades']); ?> unidades
                                    <?php if ($t['sin_maestro'] > 0): ?>
                                        · <?php echo $num($t['sin_maestro']); ?> sin maestro
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a class="btn btn-chico"
                               href="<?php echo BASE_URL; ?>/cajas-punto-venta/acciones?accion=pdf&cedi=<?php echo urlencode($cedi); ?>">
                                <i class="fa-solid fa-file-pdf"></i> PDF
                            </a>
                        </summary>

                        <!-- Las columnas son las de la LISTA DE EMPAQUE que usan las cadenas
                             (formato confirmado con el usuario el 2026-09-11), para que esta
                             pantalla se pueda comparar renglón a renglón con el papel que firma
                             el CEDI al recibir.

                             "Refrigerado" y "Otros" van SIEMPRE vacías: Monterojo no despacha
                             refrigerado, y "otros" es una casilla que la cadena usa para lo que
                             no entra en las otras dos. Se dejan igual porque el formato es de
                             ellos, y una columna de menos obliga a quien recibe a ir contando
                             posiciones para saber si está mirando la casilla correcta. -->
                        <div class="tabla-caja">
                            <?php // ANCHOS DE COLUMNA (2026-09-21)
                                  // Van en el colgroup y no en cada <th>, y con table-layout:fixed,
                                  // por el TOTAL GENERAL de más abajo: esa es otra tabla, sin
                                  // encabezado, y con el ancho automático cada una repartía sus
                                  // columnas según su propio contenido. Como el texto "TOTAL
                                  // GENERAL · 4 CEDI · 44 puntos de venta" es largo, se comía el
                                  // espacio y sus números quedaban corridos respecto de los de
                                  // arriba. Con el layout fijo mandan estos anchos y nada más, así
                                  // que las dos tablas parten las columnas exactamente igual.
                                  // Los dos colgroup tienen que seguir siendo IDÉNTICOS. ?>
                            <table class="tabla" style="table-layout: fixed;">
                                <colgroup>
                                    <col style="width: 34px;">
                                    <col style="width: 13%;">
                                    <col>
                                    <col style="width: 13%;">
                                    <col style="width: 17%;">
                                    <col style="width: 8%;">
                                    <col style="width: 9%;">
                                    <col style="width: 10%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th class="centro">
                                            <input type="checkbox" class="chk-todos"
                                                   title="Seleccionar todos los puntos de venta de este CEDI"
                                                   aria-label="Seleccionar todos los puntos de venta de este CEDI">
                                        </th>
                                        <th>Código</th>
                                        <th>Nombre del almacén</th>
                                        <th class="num">Cajas<br>producto seco</th>
                                        <th class="num">Cajas<br>producto refrigerado</th>
                                        <th class="num">Otros</th>
                                        <th class="num">Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($datos['puntos'] as $clave => $p): ?>
                                        <tr class="fila-punto">
                                            <td class="centro">
                                                <input type="checkbox" class="chk-punto"
                                                       aria-label="Seleccionar <?php echo $esc($p['nombre'] ?: $p['punto_venta']); ?>"
                                                       data-cedi="<?php echo $esc($cedi); ?>"
                                                       data-punto="<?php echo $esc($p['punto_venta']); ?>">
                                            </td>
                                            <td><?php echo $p['numero'] !== ''
                                                    ? '<strong>' . $esc($p['numero']) . '</strong>'
                                                    : '<span class="dato-faltante">—</span>'; ?></td>
                                            <td><?php echo $p['nombre'] !== ''
                                                    ? $esc($p['nombre'])
                                                    : '<span class="dato-faltante">sin nombre en el archivo</span>'; ?></td>
                                            <td class="num"><strong><?php echo $num($p['cajas']); ?></strong></td>
                                            <td class="num"></td>
                                            <td class="num"></td>
                                            <td class="num"><strong><?php echo $num($p['total']); ?></strong></td>
                                            <td>
                                                <button type="button" class="btn btn-chico btn-rotulo-punto"
                                                        data-cedi="<?php echo $esc($cedi); ?>"
                                                        data-numero-cedi="<?php echo $esc(numeroDeCedi($cedi)); ?>"
                                                        data-punto="<?php echo $esc($p['punto_venta']); ?>"
                                                        data-numero="<?php echo $esc($p['numero']); ?>"
                                                        data-nombre="<?php echo $esc($p['nombre']); ?>"
                                                        data-cajas="<?php echo (int) $p['cajas']; ?>"
                                                        <?php // El detalle por producto: de acá sale qué producto va en cada caja. ?>
                                                        data-filas="<?php echo $esc(json_encode($p['filas'], JSON_UNESCAPED_UNICODE)); ?>">
                                                    <i class="fa-solid fa-tags"></i> Rótulo
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <!-- colspan="3": la etiqueta ocupa la casilla de selección, el
                                         código y el nombre. Sin eso la fila tenía una celda MENOS
                                         que el encabezado —desde que se agregó la columna de los
                                         tildes— y cada número caía bajo la columna de al lado: las
                                         cajas aparecían bajo "Nombre del almacén" y el total bajo
                                         "Otros" (2026-09-21). -->
                                    <tr class="fila-total">
                                        <td colspan="3">TOTAL <?php echo $esc($cedi); ?></td>
                                        <td class="num"><?php echo $num($t['cajas']); ?></td>
                                        <td class="num"></td>
                                        <td class="num"></td>
                                        <td class="num"><?php echo $num($t['total']); ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </details>
                <?php endforeach; ?>

                <?php if (count($porCedi) > 1): ?>
                    <!-- La suma de todos los CEDI. Va suelta al final y no dentro de un grupo
                         porque no pertenece a ninguno: es lo que sale de la bodega en total. -->
                    <div class="tabla-caja">
                        <?php // El MISMO colgroup y el mismo table-layout que las tablas de cada
                              // CEDI: esta no tiene encabezado, y es lo único que hace que sus
                              // números caigan justo debajo de los de arriba. ?>
                        <table class="tabla" style="table-layout: fixed;">
                            <colgroup>
                                <col style="width: 34px;">
                                <col style="width: 13%;">
                                <col>
                                <col style="width: 13%;">
                                <col style="width: 17%;">
                                <col style="width: 8%;">
                                <col style="width: 9%;">
                                <col style="width: 10%;">
                            </colgroup>
                            <tfoot>
                                <tr class="fila-total fila-total-general">
                                    <td colspan="3">TOTAL GENERAL · <?php echo $num(count($porCedi)); ?> CEDI ·
                                        <?php echo $num($totalGeneral['puntos']); ?> puntos de venta</td>
                                    <td class="num"><?php echo $num($totalGeneral['cajas']); ?></td>
                                    <td class="num"></td>
                                    <td class="num"></td>
                                    <td class="num"><?php echo $num($totalGeneral['total']); ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- BARRA DE SELECCIÓN MÚLTIPLE
     Igual que en Picking, pero acá lo que se tilda es un PUNTO DE VENTA (una fila), no un pedido
     entero: en esta pantalla cada fila YA ES un solo destino con sus cajas resueltas. -->
<div class="barra-seleccion" id="barra-seleccion" hidden>
    <div class="barra-seleccion-info">
        <strong id="barra-conteo">0</strong> punto(s) de venta seleccionado(s)
        <button type="button" class="barra-limpiar" id="btn-limpiar-seleccion">Quitar selección</button>
    </div>

    <div class="barra-seleccion-acciones">
        <button type="button" class="btn btn-chico" id="btn-rotulos-masivo">
            <i class="fa-solid fa-tags"></i> Rótulos
        </button>

        <!-- Por formulario y no por fetch, para que el navegador la trate como una descarga
             normal. Los campos ocultos con los puntos tildados los rellena scripts_cajas_punto_venta.js. -->
        <form action="<?php echo BASE_URL; ?>/cajas-punto-venta/acciones" method="POST" id="form-pdf-seleccion">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="pdf_seleccion">
            <div id="campos-pdf-seleccion"></div>
            <button type="submit" class="btn btn-chico btn-primario">
                <i class="fa-solid fa-file-pdf"></i> Descargar PDF
            </button>
        </form>
    </div>
</div>

<!-- El modal de rótulos. Es el mismo recuadro y los mismos botones que en Picking, el Historial y
     "Generar rótulos": quien imprime ve siempre la misma pantalla, venga de donde venga. -->
<div class="modal-fondo" id="modal-rotulo">
    <div class="modal-caja modal-caja-ancha">

        <div class="modal-cabecera">
            <h2>Rótulos <span id="rotulo-titulo-detalle" style="font-weight: 400; opacity: 0.75;"></span></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>

        <div class="modal-cuerpo">
            <!-- Cómo salió el envío a la etiquetadora. Va acá adentro y no en un cartel flotante
                 porque quien imprime está mirando la vista previa. -->
            <div class="aviso" id="rotulo-aviso-impresion" hidden>
                <i class="fa-solid fa-circle-info"></i>
                <div id="rotulo-aviso-impresion-texto"></div>
            </div>

            <div class="rotulos-previa" id="rotulos-previa"></div>
        </div>

        <div class="modal-pie">
            <button type="button" class="btn" data-cerrar>Cerrar</button>

            <form action="<?php echo BASE_URL; ?>/picking/acciones"
                  method="POST" id="form-rotulos-pdf">
                <?php campoCSRF(); ?>
                <input type="hidden" name="accion" value="rotulos_pdf">
                <input type="hidden" name="rotulos" id="rotulos-pdf-datos">
                <button type="submit" class="btn">
                    <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                </button>
            </form>

            <button type="button" class="btn btn-primario" id="btn-imprimir-etiquetadora">
                <i class="fa-solid fa-tags"></i> Imprimir en la etiquetadora
            </button>
        </div>

    </div>
</div>

<!-- MODAL: LA LISTA OFICIAL DE ALMACENES DEL ÉXITO -->
<div class="modal-fondo" id="modal-almacenes">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Almacenes del Éxito</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/cajas-punto-venta/acciones" method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="subir_almacenes">

            <div class="modal-cuerpo">
                <div class="pastillas" style="margin-bottom: 14px;">
                    <span class="pastilla">Almacenes <strong><?php echo $num($almacenes['almacenes'] ?? 0); ?></strong></span>
                    <?php if (!empty($almacenes['actualizado'])): ?>
                        <span class="pastilla">Actualizada <strong><?php echo date('d/m/Y H:i', strtotime($almacenes['actualizado'])); ?></strong></span>
                    <?php endif; ?>
                </div>

                <div class="campo">
                    <label for="archivo-almacenes">Archivo de Excel (.xlsx)</label>
                    <input type="file" id="archivo-almacenes" name="archivo" accept=".xlsx,.xls" required>
                    <span class="ayuda">
                        Con las columnas <strong>Dependencia</strong> (el número de punto de venta) y
                        <strong>Nombre Almacen</strong> (el punto de venta). Agrega las tiendas nuevas y
                        corrige los nombres; no borra las que no vengan en el archivo.
                    </span>
                </div>

                <div class="aviso aviso-info" style="margin-bottom: 0;">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Cada punto de venta del Éxito se muestra como <strong>"dependencia - nombre"</strong>
                        de esta lista, aunque el Consolidado lo traiga sin número o con otro nombre. Se
                        aplica al subir la lista y a cada Consolidado que se importe.
                    </div>
                </div>
            </div>

            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-upload"></i> Subir lista</button>
            </div>
        </form>
    </div>
</div>

<script>
    const BASE_URL   = '<?php echo BASE_URL; ?>';
    const LOGO_URL   = '<?php echo BASE_URL; ?>/assets/img/monterojo.png';
    const CSRF_TOKEN = '<?php echo htmlspecialchars(generarTokenCSRF(), ENT_QUOTES, 'UTF-8'); ?>';
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/rotulo.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/rotulo.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/cajas_punto_venta/layouts/scripts_cajas_punto_venta.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/cajas_punto_venta/layouts/scripts_cajas_punto_venta.js'); ?>"></script>

</body>
</html>
