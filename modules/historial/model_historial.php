<?php
// modules/historial/model_historial.php
// El Historial de Pedidos: lo que ya se despachó.
//
// A diferencia de Consolidados y Picking —que solo miran la carga vigente—, acá se mira TODA
// consolidado_lineas sin filtrar por id_carga: una entrega despachada hoy sigue en el historial
// aunque mañana se suba un archivo nuevo y la carga de hoy deje de ser la vigente. Eso es justamente
// lo que permite el cambio en importarConsolidado() (ver model_consolidados_import.php): ya no borra
// las líneas despachadas, así que sobreviven de carga en carga.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados.php';   // mapaMaestro(), decorarConMaestro()

/**
 * Una fila por (carga, CEDI, orden de compra, punto de venta, PLU) ya despachada.
 *
 * Lleva el id_carga en la clave porque, a diferencia de Picking, acá SÍ puede haber más de una
 * carga a la vez: es el registro de todo lo que salió, no de lo que hay pendiente ahora mismo.
 *
 * $filtros acepta 'busqueda' (CEDI, punto de venta, orden de compra, alistador o quien despachó).
 */
function filasHistorial($pdo, array $filtros = []) {
    $sql = "SELECT l.id_carga, l.cedi, l.orden_compra, l.punto_venta, l.plu, l.ean_item,
                   l.ean_punto_venta, l.unidades, l.pedido_sap, l.fecha_despacho,
                   l.id_personal, p.nombre AS personal_nombre,
                   l.despachado_por, u.nombre_usuario AS usuario_nombre
            FROM consolidado_lineas l
            LEFT JOIN personal p ON p.id_personal = l.id_personal
            LEFT JOIN usuarios u ON u.id_usuario = l.despachado_por
            WHERE l.despachado = 1
            ORDER BY l.fecha_despacho DESC, l.id_carga DESC, l.punto_venta, l.plu";

    $stmt = $pdo->query($sql);

    $mapa   = mapaMaestro($pdo);
    $patron = isset($filtros['busqueda']) ? mb_strtolower(trim($filtros['busqueda'])) : '';

    $filas = [];
    foreach ($stmt as $fila) {
        $fila = decorarConMaestro($fila, $mapa);

        if ($patron !== '') {
            $donde = mb_strtolower(implode(' ', [
                $fila['cedi'], $fila['punto_venta'], $fila['orden_compra'],
                (string) $fila['personal_nombre'], (string) $fila['usuario_nombre'],
                (string) $fila['pedido_sap'],
            ]));
            if (mb_strpos($donde, $patron) === false) {
                continue;
            }
        }

        $filas[] = $fila;
    }

    return $filas;
}

/**
 * Agrupa las filas de filasHistorial() por ENTREGA DESPACHADA: carga + CEDI + orden de compra +
 * punto de venta.
 *
 * La carga entra en la clave (y no solo CEDI+OC+PV, como en Picking) porque acá conviven líneas de
 * distintas cargas: la misma tienda pudo haber recibido dos pedidos en días distintos, cada uno con
 * su propio id_carga, y son dos renglones de historial separados, no uno.
 *
 * El orden de salida es el de filasHistorial(): más reciente primero.
 */
function agruparPorEntregaHistorial(array $filas) {
    $entregas = [];

    foreach ($filas as $f) {
        $clave = $f['id_carga'] . '|' . $f['cedi'] . '|' . $f['orden_compra'] . '|' . $f['punto_venta'];

        if (!isset($entregas[$clave])) {
            $entregas[$clave] = [
                'clave'           => $clave,
                'id_carga'        => $f['id_carga'],
                'cedi'            => $f['cedi'],
                'orden_compra'    => $f['orden_compra'],
                'punto_venta'     => $f['punto_venta'],
                'ean_punto_venta' => $f['ean_punto_venta'],
                'pedido_sap'      => $f['pedido_sap'],
                'fecha_despacho'  => $f['fecha_despacho'],
                'id_personal'     => $f['id_personal'],
                'personal_nombre' => $f['personal_nombre'],
                'usuario_nombre'  => $f['usuario_nombre'],
                'lineas'          => [],
                'totales'         => [
                    'lineas' => 0, 'unidades' => 0, 'cajas' => 0,
                    'saldos' => 0, 'peso_kg' => 0, 'sin_maestro' => 0,
                ],
            ];
        }

        $entregas[$clave]['lineas'][] = $f;

        $t = &$entregas[$clave]['totales'];
        $t['lineas']++;
        $t['unidades'] += (int) $f['unidades'];
        if ($f['sin_maestro']) {
            $t['sin_maestro']++;
        } else {
            $t['cajas']  += (int) $f['cajas'];
            $t['saldos'] += (int) $f['saldos'];
        }
        if ($f['peso_kg'] !== null) {
            $t['peso_kg'] += (float) $f['peso_kg'];
        }
        unset($t);
    }

    // Segunda pasada: las cajas FÍSICAS de cada línea y desde qué número del pedido empiezan sus
    // rótulos —igual que agruparPorEntrega() en model_picking.php, y por el mismo motivo: son las
    // cajas que salieron de verdad, no las cajas completas que usa el elevador. Hace falta acá
    // porque el Historial también reimprime hojas y rótulos de un pedido ya despachado.
    foreach ($entregas as &$entrega) {
        $entrega['totales']['peso_kg'] = round($entrega['totales']['peso_kg'], 2);

        $acumuladas = 0;
        foreach ($entrega['lineas'] as &$linea) {
            if ((int) $linea['unidades'] <= 0) {
                $cajasRotulo = 0;
            } elseif ($linea['sin_maestro']) {
                $cajasRotulo = 1;
            } else {
                $cajasRotulo = (int) $linea['cajas'] + ((int) $linea['saldos'] > 0 ? 1 : 0);
            }

            $linea['cajas_rotulo'] = $cajasRotulo;
            $linea['caja_desde']   = $acumuladas + 1;
            $linea['cajas_pedido'] = 0;
            $acumuladas += $cajasRotulo;
        }
        unset($linea);

        $entrega['totales']['cajas_rotulo'] = $acumuladas;

        foreach ($entrega['lineas'] as &$linea) {
            $linea['cajas_pedido'] = $acumuladas;
        }
        unset($linea);
    }
    unset($entrega);

    return $entregas;
}

/**
 * Reparte una lista de entregas de agruparPorEntregaHistorial() por CEDI, igual que
 * agruparPorCedi() en model_picking.php: es el mismo agrupador visual, para que el Historial se
 * vea y se maneje como Picking en vez de una tabla plana.
 *
 * Se aplica DESPUÉS de paginar (ver historialPaginado): agrupa por CEDI las entregas de la PÁGINA
 * actual, no todo el historial completo, así la paginación sigue significando "20 pedidos" y no
 * "20 CEDI".
 */
function agruparPorCediHistorial(array $entregas) {
    $porCedi = [];

    foreach ($entregas as $entrega) {
        $cedi = $entrega['cedi'];

        if (!isset($porCedi[$cedi])) {
            $porCedi[$cedi] = [
                'cedi'     => $cedi,
                'entregas' => [],
                'totales'  => [
                    'pedidos' => 0, 'lineas' => 0, 'unidades' => 0,
                    'cajas' => 0, 'saldos' => 0, 'peso_kg' => 0, 'sin_maestro' => 0, 'cajas_rotulo' => 0,
                ],
            ];
        }

        $porCedi[$cedi]['entregas'][] = $entrega;

        $t = &$porCedi[$cedi]['totales'];
        $t['pedidos']++;
        foreach (['lineas', 'unidades', 'cajas', 'saldos', 'peso_kg', 'sin_maestro', 'cajas_rotulo'] as $campo) {
            $t[$campo] += $entrega['totales'][$campo];
        }
        unset($t);
    }

    foreach ($porCedi as &$grupo) {
        $grupo['totales']['peso_kg'] = round($grupo['totales']['peso_kg'], 2);
    }
    unset($grupo);

    ksort($porCedi);

    return $porCedi;
}

/**
 * Varias entregas despachadas de una vez, para reimprimir sus hojas juntas. $claves es una lista
 * de ['id_carga'=>, 'cedi'=>, 'oc'=>, 'pv'=>] —el id_carga es obligatorio acá, igual que en
 * entregasPicking(), porque la misma tienda puede tener más de un pedido en distintas cargas.
 */
function entregasHistorial($pdo, array $claves) {
    if (!$claves) {
        return [];
    }

    $buscadas = [];
    foreach ($claves as $c) {
        $clave = trim((string) ($c['id_carga'] ?? '')) . '|' . trim((string) ($c['cedi'] ?? ''))
               . '|' . trim((string) ($c['oc'] ?? '')) . '|' . trim((string) ($c['pv'] ?? ''));
        $buscadas[$clave] = true;
    }

    $entregas = agruparPorEntregaHistorial(filasHistorial($pdo));

    return array_values(array_filter(
        $entregas,
        fn($clave) => isset($buscadas[$clave]),
        ARRAY_FILTER_USE_KEY
    ));
}

// Una sola entrega despachada, para la hoja en PDF.
function entregaHistorial($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta) {
    $entregas = agruparPorEntregaHistorial(filasHistorial($pdo));
    $clave = $idCarga . '|' . $cedi . '|' . $ordenCompra . '|' . $puntoVenta;

    return $entregas[$clave] ?? null;
}

// Los datos de la carga por su id, para el encabezado del PDF ("Archivo: ..."). A diferencia de
// cargaVigente(), esta puede ser una carga vieja: la hoja de un pedido despachado hace tres días
// tiene que decir el archivo de HACE TRES DÍAS, no el de hoy.
function cargaPorId($pdo, $idCarga) {
    $stmt = $pdo->prepare(
        "SELECT c.id_carga, c.nombre_archivo, c.filas, c.fecha_carga, u.nombre_usuario
         FROM consolidado_cargas c
         LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario
         WHERE c.id_carga = :id_carga"
    );
    $stmt->execute([':id_carga' => $idCarga]);
    return $stmt->fetch() ?: null;
}

/**
 * El historial, ya paginado.
 *
 * La agrupación pasa por PHP porque las cajas y el peso salen del maestro, que no vive en
 * consolidado_lineas —igual que en Picking—, así que no se puede sumar directamente en SQL. Es
 * aceptable acá: un historial de bodega acumula miles de líneas, no millones, y sigue siendo una
 * sola consulta a la base por carga de pantalla.
 *
 * Devuelve ['entregas' => [...], 'total' => int] — $total es la cantidad de ENTREGAS que cumplen el
 * filtro, no de líneas, para que la paginación cuente lo mismo que se ve en la tabla.
 */
function historialPaginado($pdo, array $filtros = [], $pagina = 1, $porPagina = 20) {
    $entregas = array_values(agruparPorEntregaHistorial(filasHistorial($pdo, $filtros)));

    $total   = count($entregas);
    $pagina  = max(1, (int) $pagina);
    $desde   = ($pagina - 1) * $porPagina;

    return [
        'entregas' => array_slice($entregas, $desde, $porPagina),
        'total'    => $total,
        'pagina'   => $pagina,
        'paginas'  => max(1, (int) ceil($total / $porPagina)),
    ];
}

// Totales para las pastillas de cabecera: sobre TODO lo que cumple el filtro, no solo la página
// que se está mostrando —si no, cambiarían de número al pasar de página, lo que parecería un error.
function resumenHistorialTotales($pdo, array $filtros = []) {
    $entregas = agruparPorEntregaHistorial(filasHistorial($pdo, $filtros));

    $resumen = ['pedidos' => count($entregas), 'cajas' => 0, 'peso_kg' => 0];
    foreach ($entregas as $e) {
        $resumen['cajas']   += $e['totales']['cajas_rotulo'];
        $resumen['peso_kg'] += $e['totales']['peso_kg'];
    }
    $resumen['peso_kg'] = round($resumen['peso_kg'], 2);

    return $resumen;
}

/**
 * Restaura una entrega despachada: la vuelve a dejar pendiente, como si nunca se hubiera
 * despachado.
 *
 * Ya no hace falta que su carga sea "la vigente": Picking y Consolidados ahora miran TODO lo
 * pendiente de TODAS las cargas juntas (ver filasPicking, consolidadoPorCedi — cambiado el
 * 2026-09-08 para que un archivo nuevo no borre los pedidos de otro canal que todavía no se habían
 * despachado), así que una entrega restaurada de CUALQUIER carga vuelve a aparecer ahí sin
 * quedar huérfana. Antes esto solo se permitía para la carga vigente, porque en ese momento era la
 * única que esas dos pantallas llegaban a mirar.
 *
 * Devuelve ['exito' => bool, 'mensaje' => string] (mensaje solo si exito es false).
 */
function restaurarEntregaHistorial($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta) {
    try {
        $stmt = $pdo->prepare(
            "UPDATE consolidado_lineas
             SET despachado = 0, fecha_despacho = NULL, despachado_por = NULL
             WHERE id_carga = :carga AND cedi = :cedi
               AND orden_compra = :oc AND punto_venta = :pv AND despachado = 1"
        );
        $stmt->execute([
            ':carga' => $idCarga,
            ':cedi'  => $cedi,
            ':oc'    => $ordenCompra,
            ':pv'    => $puntoVenta,
        ]);

        if ($stmt->rowCount() === 0) {
            return ['exito' => false, 'mensaje' => 'Ese pedido ya no está despachado. Recargá la pantalla.'];
        }

        return ['exito' => true];

    } catch (PDOException $e) {
        error_log('Error restaurando entrega del historial: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo restaurar. Inténtalo de nuevo.'];
    }
}
