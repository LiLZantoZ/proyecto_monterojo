<?php
// modules/inicio/layout/paginacion.php
// PAGINACIÓN estilo buscador (1 2 3 … 9 … última), la misma de Consolidado MR y Pedidos, en un
// solo lugar (2026-10-06). Se incluye con estas variables puestas:
//   $pagina, $totalPaginas, $total, $desdeFila, $hastaFila, $etiqueta ("productos", "acciones"…)
//   y $urlPagina (función: número de página → URL con los filtros actuales).
$_pagEsc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$_pagMil = fn($n) => number_format((float) $n, 0, ',', '.');
?>
<div class="paginacion">
    <span class="paginacion-info">
        <?php echo $_pagMil($desdeFila); ?>–<?php echo $_pagMil($hastaFila); ?> de <strong><?php echo $_pagMil($total); ?></strong> <?php echo $_pagEsc($etiqueta); ?>
    </span>
    <?php if ($totalPaginas > 1):
        $_pagVentana = 9;
        $_pagInicio  = max(1, $pagina - intdiv($_pagVentana, 2));
        $_pagFin     = min($totalPaginas, $_pagInicio + $_pagVentana - 1);
        $_pagInicio  = max(1, $_pagFin - $_pagVentana + 1);
        $_pagNumeros = [];
        $_pagPrev = 0;
        foreach (array_unique(array_merge([1], range($_pagInicio, $_pagFin), [$totalPaginas])) as $_pagN) {
            if ($_pagPrev && $_pagN - $_pagPrev > 1) { $_pagNumeros[] = 0; }   // 0 = "…"
            $_pagNumeros[] = $_pagN;
            $_pagPrev = $_pagN;
        }
    ?>
        <nav class="paginacion-botones" aria-label="Paginación">
            <?php if ($pagina > 1): ?>
                <a class="pagina-flecha" href="<?php echo $_pagEsc($urlPagina($pagina - 1)); ?>" rel="prev" aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></a>
            <?php else: ?>
                <span class="pagina-flecha deshabilitada" aria-hidden="true"><i class="fa-solid fa-chevron-left"></i></span>
            <?php endif; ?>
            <?php foreach ($_pagNumeros as $_pagN): ?>
                <?php if ($_pagN === 0): ?>
                    <span class="pagina-elipsis">…</span>
                <?php elseif ($_pagN === $pagina): ?>
                    <span class="pagina-num activa" aria-current="page"><?php echo $_pagN; ?></span>
                <?php else: ?>
                    <a class="pagina-num" href="<?php echo $_pagEsc($urlPagina($_pagN)); ?>"><?php echo $_pagN; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="pagina-flecha" href="<?php echo $_pagEsc($urlPagina($pagina + 1)); ?>" rel="next" aria-label="Página siguiente"><i class="fa-solid fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="pagina-flecha deshabilitada" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
