<?php
// modules/inicio/layout/portafolio_sabores.php
// La galería de sabores del panel de inicio.
//
// Cada tarjeta muestra la foto del empaque si está cargada, y el logo sobre negro si todavía no:
// un <img> apuntando a un archivo inexistente deja el icono de imagen rota, que se lee como una
// falla del sistema cuando lo único que falta es subir la foto.

require_once __DIR__ . '/sabores_lista.php';

$stand = imagenDeMarca('stand.jpg');
?>
<section class="portafolio">

    <header class="portafolio-header">
        <?php if ($stand): ?>
            <img src="<?php echo $stand; ?>" alt="Portafolio Monterojo Gourmet" class="portafolio-stand">
        <?php else: ?>
            <div class="portafolio-stand portafolio-item-sinfoto" style="height: 96px;">
                <img src="<?php echo BASE_URL; ?>/assets/img/monterojo.png" alt="">
            </div>
        <?php endif; ?>
        <div>
            <h2>Portafolio Monterojo Gourmet</h2>
            <p>Los sabores que se alistan y se despachan desde este sistema.</p>
        </div>
    </header>

    <div class="portafolio-grid">
        <?php foreach (saboresDelPortafolio() as $sabor): ?>
            <?php $foto = imagenDeMarca($sabor['img']); ?>
            <figure class="portafolio-item">
                <?php if ($foto): ?>
                    <img src="<?php echo $foto; ?>"
                         alt="<?php echo htmlspecialchars($sabor['etiqueta']); ?>" loading="lazy">
                <?php else: ?>
                    <div class="portafolio-item-sinfoto">
                        <img src="<?php echo BASE_URL; ?>/assets/img/monterojo.png" alt="">
                    </div>
                <?php endif; ?>

                <figcaption>
                    <span class="portafolio-nombre"><?php echo htmlspecialchars($sabor['etiqueta']); ?></span>
                    <?php if ($sabor['tipo'] === 'especial'): ?>
                        <span class="portafolio-etiqueta portafolio-etiqueta-especial">Edición especial</span>
                    <?php else: ?>
                        <span class="portafolio-etiqueta">Línea permanente</span>
                    <?php endif; ?>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>

</section>
