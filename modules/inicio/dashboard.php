<?php
// modules/inicio/dashboard.php
// El panel de inicio: lo primero que se ve después de entrar.
//
// Es UNO solo para todos los roles. El sistema de bodega tiene tres paneles casi idénticos, y esa
// lista de "qué rol va a qué panel" está copiada en cuatro archivos: cuando se desincronizan, el
// panel devuelve al usuario al login y el login lo devuelve al panel, en bucle. Acá lo que cambia
// entre un rol y otro son los módulos que le aparecen en el menú, no la pantalla de inicio; si
// algún día un rol necesita la suya, el desvío se agrega en urlPanelDelRol() (config/config.php)
// y en ningún otro lado.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/permisos.php';
require_once __DIR__ . '/../consolidados/model_consolidados.php';

$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';
$rolUsuario    = $_SESSION['nombre_rol'] ?? 'Rol no asignado';

// El estado real del sistema. Sin esto el panel es una tarjeta con un nombre y nada más, y hay
// que entrar módulo por módulo para saber si el archivo del día ya está cargado.
$carga = cargaVigente($pdo);
$estado = null;

if ($carga) {
    $porCedi = consolidadoPorCedi($pdo, $carga['id_carga']);
    $totales = ['unidades' => 0, 'cajas' => 0];
    foreach ($porCedi as $filas) {
        $t = totalesDelGrupo($filas);
        $totales['unidades'] += $t['unidades'];
        $totales['cajas']    += $t['cajas'];
    }

    $estado = [
        'cedis'       => count($porCedi),
        'unidades'    => $totales['unidades'],
        'cajas'       => $totales['cajas'],
        'sin_maestro' => pluSinMaestro($pdo, $carga['id_carga']),
    ];
}

// La foto de campaña de la cabecera. Si todavía no está en disco, la tarjeta muestra el logo
// sobre negro en su lugar (ver imagenDeMarca en config/config.php).
$hero = imagenDeMarca('bienvenida.jpg');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">

    <?php
    // El sidebar va DESPUÉS del <!DOCTYPE html> a propósito: incluirlo antes hace que el
    // navegador reciba HTML por delante de la declaración de tipo de documento y entre en Quirks
    // Mode, donde buena parte de la hoja de estilos se comporta distinto sin avisar.
    include __DIR__ . '/layout/sidebar.php';
    ?>

    <div class="content-area">
        <div class="panel-inicio">

            <div class="welcome-box">
                <?php if ($hero): ?>
                    <img src="<?php echo $hero; ?>" alt="Monterojo Gourmet" class="welcome-hero">
                <?php else: ?>
                    <div class="welcome-hero-marca">
                        <img src="<?php echo BASE_URL; ?>/assets/img/monterojo.png" alt="Monterojo Gourmet">
                    </div>
                <?php endif; ?>

                <div class="welcome-texto">
                    <h1>Bienvenido: <?php echo htmlspecialchars($nombreUsuario); ?></h1>
                    <p>Tu rol en el sistema es: <strong><?php echo htmlspecialchars($rolUsuario); ?></strong></p>
                </div>
            </div>

            <?php if (!$carga): ?>
                <div class="estado-sistema">
                    <?php if (tienePermiso('modulo_consolidados')): ?>
                        <a class="estado-dato estado-dato-alerta"
                           href="<?php echo BASE_URL; ?>/modules/consolidados/views/consolidados.php"
                           style="grid-column: 1 / -1;">
                            <span class="estado-dato-valor">Sin Consolidado</span>
                            <span class="estado-dato-etiqueta">
                                Todavía no se ha cargado el archivo del día. Entra a Consolidados para subirlo.
                            </span>
                        </a>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="estado-sistema">
                    <div class="estado-dato">
                        <span class="estado-dato-valor"><?php echo $estado['cedis']; ?></span>
                        <span class="estado-dato-etiqueta">
                            CEDI en el archivo del <?php echo date('d/m/Y', strtotime($carga['fecha_carga'])); ?>
                        </span>
                    </div>

                    <div class="estado-dato">
                        <span class="estado-dato-valor"><?php echo number_format($estado['unidades'], 0, ',', '.'); ?></span>
                        <span class="estado-dato-etiqueta">Unidades pedidas</span>
                    </div>

                    <div class="estado-dato">
                        <span class="estado-dato-valor"><?php echo number_format($estado['cajas'], 0, ',', '.'); ?></span>
                        <span class="estado-dato-etiqueta">Cajas por alistar</span>
                    </div>

                    <?php if ($estado['sin_maestro'] > 0 && tienePermiso('modulo_maestro')): ?>
                        <a class="estado-dato estado-dato-alerta"
                           href="<?php echo BASE_URL; ?>/modules/consolidados/views/maestro.php?ver=faltantes">
                            <span class="estado-dato-valor"><?php echo $estado['sin_maestro']; ?></span>
                            <span class="estado-dato-etiqueta">
                                PLU sin unidades por caja. Hasta cargarlos, esas líneas no suman cajas.
                            </span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // La galería de sabores está OCULTA por pedido del usuario (2026-09-05), no borrada.
            // Los archivos siguen ahí —layout/portafolio_sabores.php y layout/sabores_lista.php—
            // así que para volver a mostrarla basta con descomentar la línea de abajo.
            //
            // include __DIR__ . '/layout/portafolio_sabores.php';
            ?>

        </div>
    </div>

</div>

</body>
</html>
