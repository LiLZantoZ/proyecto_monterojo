<?php
// modules/posiciones/helper_plano_posiciones.php
// EL PLANO DEL RACK (2026-10-13, pedido del usuario): las posiciones de un rack dibujadas en 2D,
// como el "Plano" de Posiciones del sistema de bodega, adaptado a los racks de Monterojo.
//
// CÓMO SE DIBUJA: un rack por vez. La posición A1/A2/B1/B2 se lee como LADO (la letra) y PUESTO
// (el número), así que cada rack son dos rejillas, "Lado A" y "Lado B": una fila por nivel (el
// N5 arriba, como se ve el rack parado enfrente) y una columna por módulo·puesto (M1.1, M1.2 …
// M12.2). El módulo 12 no tiene N1 ni N2: ahí se dibuja el hueco punteado en vez de correr las
// celdas, que es lo que haría perder la forma del rack.
//
// Comparte todo con las tarjetas: los mismos datos (POSICIONES_SQL_CAMPOS), el mismo estado, los
// mismos colores y el mismo modal de detalles (cada celda lleva el mismo data-posicion que su
// tarjeta). Los filtros no quitan celdas: ATENÚAN las que no coinciden, para no deformar el rack.
//
// La usan las dos pantallas (views/posiciones.php y la de Laravel), así que el dibujo vive acá una
// sola vez.

require_once __DIR__ . '/model_posiciones.php';

/** Cuántas posiciones tiene cada rack y cuántas están ocupadas: [rack => ['total', 'ocupadas']]. */
function resumenRacksPosiciones($pdo) {
    $salida = [];
    for ($r = 1; $r <= POSICIONES_RACKS; $r++) {
        $salida[$r] = ['total' => 0, 'ocupadas' => 0];
    }
    $st = $pdo->query("SELECT pos.rack, COUNT(*) AS total, COUNT(e.id_estiba) AS ocupadas
                         FROM posiciones pos LEFT JOIN posiciones_estibas e ON e.id_posicion = pos.id_posicion
                        GROUP BY pos.rack");
    foreach ($st as $f) {
        $salida[(int) $f['rack']] = ['total' => (int) $f['total'], 'ocupadas' => (int) $f['ocupadas']];
    }
    return $salida;
}

/**
 * Un rack listo para dibujar: ['rack', 'niveles', 'columnas', 'lados', 'celdas', 'coinciden',
 * 'hay_filtro', 'total', 'ocupadas'].
 *  · celdas:    [nivel|lado|modulo|puesto => la fila de la posición, con sus datos y su estado]
 *  · coinciden: [id_posicion => true] las que cumplen los filtros de módulo, nivel, búsqueda y
 *               estado (las demás se atenúan). Sin esos filtros, todas.
 */
function planoPosiciones($pdo, $rack, array $filtros) {
    $rack = max(1, min(POSICIONES_RACKS, (int) $rack));

    $st = $pdo->prepare("SELECT " . POSICIONES_SQL_CAMPOS . ", " . POSICIONES_SQL_ESTADO . " AS estado_producto "
        . POSICIONES_SQL_BASE . " WHERE pos.rack = ? ORDER BY pos.modulo, pos.nivel, pos.lugar");
    $st->execute([$rack]);

    $celdas = [];
    $ocupadas = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $lugar = strtoupper((string) $p['lugar']);
        $celdas[(int) $p['nivel'] . '|' . substr($lugar, 0, 1) . '|' . (int) $p['modulo'] . '|' . (int) substr($lugar, 1)] = $p;
        if ($p['id_estiba']) {
            $ocupadas++;
        }
    }

    // Las que cumplen los demás filtros, con la misma condición que las tarjetas.
    $hayFiltro = ($filtros['modulo'] ?? '') !== '' || ($filtros['nivel'] ?? '') !== ''
              || ($filtros['buscar'] ?? '') !== '' || ($filtros['estado'] ?? '') !== '';
    $coinciden = [];
    if ($hayFiltro) {
        [$where, $params] = condicionesPosiciones(['rack' => (string) $rack] + $filtros);
        $st = $pdo->prepare("SELECT pos.id_posicion " . POSICIONES_SQL_BASE . " {$where}");
        $st->execute($params);
        $coinciden = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    // Los ejes salen de la estructura de la bodega, no de las filas: así el hueco del módulo 12
    // (sin N1 ni N2) se dibuja aunque no haya ninguna posición ahí.
    $lados = $puestos = [];
    foreach (POSICIONES_LUGARES as $l) {
        $lados[substr($l, 0, 1)] = true;
        $puestos[(int) substr($l, 1)] = true;
    }
    $columnas = [];
    for ($m = 1; $m <= POSICIONES_MODULOS; $m++) {
        foreach (array_keys($puestos) as $pu) {
            $columnas[] = ['modulo' => $m, 'puesto' => $pu];
        }
    }

    return [
        'rack'       => $rack,
        'niveles'    => range(POSICIONES_NIVELES, 1),
        'columnas'   => $columnas,
        'lados'      => array_keys($lados),
        'celdas'     => $celdas,
        'coinciden'  => $coinciden,
        'hay_filtro' => $hayFiltro,
        'total'      => count($celdas),
        'ocupadas'   => $ocupadas,
    ];
}

/**
 * El HTML del plano de un rack. $datosTarjeta es la misma función con la que la pantalla arma el
 * data-posicion de cada tarjeta: la celda abre el mismo modal con los mismos datos.
 */
function htmlPlanoPosiciones(array $plano, callable $datosTarjeta) {
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $html = '';

    foreach ($plano['lados'] as $lado) {
        $html .= '<div class="plano-lado"><h5 class="plano-lado-titulo">Lado ' . $esc($lado) . '</h5>'
               . '<div class="plano-rejilla-scroll"><div class="plano-rejilla" style="grid-template-columns: 34px repeat('
               . count($plano['columnas']) . ', minmax(34px, 1fr));">';

        // Encabezado: el número de módulo sobre sus dos puestos.
        $html .= '<span class="plano-eje-esquina"></span>';
        foreach ($plano['columnas'] as $c) {
            $html .= '<span class="plano-eje-columna' . ($c['puesto'] === 1 ? ' inicio-modulo' : '') . '">'
                   . 'M' . $c['modulo'] . '<small>.' . $c['puesto'] . '</small></span>';
        }

        foreach ($plano['niveles'] as $nivel) {
            $html .= '<span class="plano-eje-nivel">N' . $nivel . '</span>';
            foreach ($plano['columnas'] as $c) {
                $p = $plano['celdas'][$nivel . '|' . $lado . '|' . $c['modulo'] . '|' . $c['puesto']] ?? null;
                $inicio = $c['puesto'] === 1 ? ' inicio-modulo' : '';
                if ($p === null) {
                    $html .= '<span class="plano-celda plano-celda-inexistente' . $inicio . '" aria-hidden="true"></span>';
                    continue;
                }
                $tiene  = (bool) $p['id_estiba'];
                $estado = $tiene ? (string) ($p['estado_producto'] ?: 'Disponible') : '';
                $clases = 'plano-celda' . $inicio . ($tiene ? ' plano-celda-ocupada plano-estado-' . slugEstadoPosicion($estado) : ' plano-celda-libre');
                if ($plano['hay_filtro'] && !isset($plano['coinciden'][(int) $p['id_posicion']])) {
                    $clases .= ' plano-celda-atenuada';
                }
                $ayuda = [$p['ubicacion']];
                if ($tiene) {
                    $ayuda[] = $estado;
                    $ayuda[] = 'SKU ' . $p['sku'] . ($p['producto'] ? ' · ' . $p['producto'] : '');
                    $ayuda[] = $p['estiba_completa'] ? 'estiba completa' : ($p['cantidad_cajas'] ? $p['cantidad_cajas'] . ' cajas' : '');
                    if ($p['lote']) { $ayuda[] = 'lote ' . $p['lote']; }
                    if ($p['fecha_vencimiento']) { $ayuda[] = 'vence ' . date('d/m/Y', strtotime($p['fecha_vencimiento'])); }
                } else {
                    $ayuda[] = 'Libre';
                }
                $html .= '<button type="button" class="' . $clases . '" data-posicion="' . $esc($datosTarjeta($p)) . '"'
                       . ' title="' . $esc(implode(' · ', array_filter($ayuda))) . '">'
                       . '<span class="plano-celda-texto">' . ($tiene ? $esc($p['sku']) : '') . '</span></button>';
            }
        }
        $html .= '</div></div></div>';
    }

    return '<article class="plano-rack">'
         . '<header class="plano-rack-header"><h4><i class="fa-solid fa-warehouse"></i> Rack ' . (int) $plano['rack'] . '</h4>'
         . '<span class="plano-rack-conteo">' . number_format($plano['total'], 0, ',', '.') . ' posiciones · '
         . number_format($plano['ocupadas'], 0, ',', '.') . ' ocupadas · '
         . number_format($plano['total'] - $plano['ocupadas'], 0, ',', '.') . ' libres</span></header>'
         . $html . '</article>';
}

/** La leyenda: los mismos nombres y colores que las pastillas y las tarjetas. */
function htmlLeyendaPlanoPosiciones() {
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $html = '<div class="plano-leyenda"><span class="plano-leyenda-item"><span class="plano-muestra plano-muestra-libre"></span> Libre</span>';
    foreach (estadosGrillaPosiciones() as $slug => $etiqueta) {
        $html .= '<span class="plano-leyenda-item"><span class="plano-muestra plano-estado-' . $slug . '"></span> ' . $esc($etiqueta) . '</span>';
    }
    return $html . '<span class="plano-leyenda-item"><span class="plano-muestra plano-muestra-inexistente"></span> No existe en el rack</span></div>';
}
