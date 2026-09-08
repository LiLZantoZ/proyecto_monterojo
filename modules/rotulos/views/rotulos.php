<?php
// modules/rotulos/views/rotulos.php
// Generador de rótulos SUELTOS: para cuando hace falta imprimir una etiqueta que no viene de
// ningún pedido del sistema —una caja que se rearma a mano, un reemplazo, una muestra— y no tiene
// sentido inventar un pedido en Consolidados solo para poder generar el rótulo.
//
// Es la misma maqueta y el mismo cálculo que el botón "Rótulos" de Picking y de Historial (ver
// scripts_picking.js / scripts_historial.js), pero acá el formulario se llena a mano en vez de
// venir precargado desde una entrega real. Por eso la pantalla entera es lo que en esas dos era
// un modal: acá no hay ninguna fila de la que "abrirlo".

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';

requierePermiso('modulo_rotulos', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar rótulos · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Generar rótulos</h2>
            </header>

            <div class="aviso aviso-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    Esto genera un rótulo suelto: no queda relacionado con ningún Consolidado ni
                    ninguna entrega. Para reimprimir el rótulo de un pedido real, usá el botón
                    <strong>Rótulos</strong> desde Picking o desde el Historial de Pedidos.
                </div>
            </div>

            <div class="rotulo-controles">
                <div class="campo">
                    <label for="rot-cantidad">Cuántos rótulos</label>
                    <input type="number" id="rot-cantidad" min="1" max="99" step="1" value="1">
                </div>
                <div class="campo">
                    <label for="rot-desde">Desde la caja Nº</label>
                    <input type="number" id="rot-desde" min="1" max="999" step="1" value="1">
                </div>
                <div class="campo">
                    <label for="rot-total">Cajas del pedido</label>
                    <input type="number" id="rot-total" min="1" max="999" step="1" value="1">
                </div>
                <div class="campo campo-ancho">
                    <label for="rot-pv">Punto de venta</label>
                    <input type="text" id="rot-pv" maxlength="180">
                </div>
                <div class="campo">
                    <label for="rot-oc">Orden de compra <span class="opcional">(opcional)</span></label>
                    <input type="text" id="rot-oc" maxlength="40">
                </div>
                <div class="campo campo-ancho">
                    <label for="rot-cedi">CEDI</label>
                    <input type="text" id="rot-cedi" maxlength="120">
                </div>
                <div class="campo campo-ancho">
                    <label for="rot-producto">Producto</label>
                    <input type="text" id="rot-producto" maxlength="255" placeholder="Nombre del producto">
                </div>
                <div class="campo campo-ancho">
                    <label for="rot-ean-pv">Código de la tienda para el código de barras <span class="opcional">(opcional)</span></label>
                    <input type="text" id="rot-ean-pv" maxlength="40" placeholder="Si lo dejás vacío, usa el punto de venta">
                </div>
            </div>

            <p class="rotulo-nota">
                <i class="fa-solid fa-circle-info"></i>
                Cambiar cualquier campo vuelve a dibujar todos los rótulos de abajo.
            </p>

            <div class="rotulos-previa" id="rotulos-previa"></div>

            <div class="modulo-acciones" style="margin-top: 18px; justify-content: flex-start;">
                <button type="button" class="btn" id="btn-limpiar-rotulos">
                    <i class="fa-solid fa-broom"></i> Limpiar
                </button>
                <button type="button" class="btn btn-primario" id="btn-imprimir-rotulos">
                    <i class="fa-solid fa-print"></i> Imprimir
                </button>

                <!-- El PDF va por un formulario normal y no por fetch: así el navegador lo trata
                     como una descarga, con su carpeta de destino, y no como una respuesta que hay
                     que interpretar en JS. Los campos ocultos los llena scripts_rotulos.js justo
                     antes de enviarlo, con lo que haya en pantalla en ese momento. -->
                <form action="<?php echo BASE_URL; ?>/modules/rotulos/controller_rotulos.php" method="POST" id="form-rotulos-pdf">
                    <?php campoCSRF(); ?>
                    <input type="hidden" name="accion" value="pdf">
                    <input type="hidden" name="cantidad">
                    <input type="hidden" name="desde">
                    <input type="hidden" name="total">
                    <input type="hidden" name="pv">
                    <input type="hidden" name="oc">
                    <input type="hidden" name="cedi">
                    <input type="hidden" name="producto">
                    <input type="hidden" name="ean_pv">
                    <button type="submit" class="btn">
                        <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<!-- Adonde se mueven los rótulos para imprimir. Tiene que ser hijo DIRECTO de <body>: al imprimir
     se oculta todo lo demás, y un elemento con un ancestro en display:none no se puede volver a
     mostrar desde el descendiente. Ver el @media print de assets/css/partes/04-rotulo.css. -->
<div id="area-impresion-rotulos" hidden></div>

<script>
    const BASE_URL   = '<?php echo BASE_URL; ?>';
    const LOGO_URL   = '<?php echo BASE_URL; ?>/assets/img/monterojo.png';
</script>
<script src="<?php echo BASE_URL; ?>/modules/rotulos/layouts/scripts_rotulos.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/rotulos/layouts/scripts_rotulos.js'); ?>"></script>

</body>
</html>
