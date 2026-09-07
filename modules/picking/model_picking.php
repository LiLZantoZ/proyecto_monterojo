<?php
// modules/picking/model_picking.php
// El alistamiento: qué tiene que armar el picker para cada punto de venta.
//
// Se apoya en el mismo Consolidado que la pantalla anterior; la diferencia es la agrupación. El
// Consolidado suma todo el CEDI porque el elevador baja producto; Picking abre por punto de venta
// porque el picker arma una entrega por tienda.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados.php';   // desglosarCajas()

/**
 * Una fila por (CEDI, orden de compra, punto de venta, PLU).
 *
 * Se agrupa por los cuatro y no solo por punto de venta + PLU porque una misma tienda puede
 * aparecer en dos órdenes de compra distintas, y son dos entregas separadas: sumarlas daría un
 * número de cajas que no corresponde a ningún despacho real.
 *
 * $filtros acepta 'cedi', 'punto_venta' y 'busqueda' (PLU, SKU o descripción).
 */
function filasPicking($pdo, $idCarga, array $filtros = []) {
    $where  = ['l.id_carga = :carga'];
    $params = [':carga' => $idCarga];

    // En SQL solo lo que es columna del propio Consolidado. La búsqueda por SKU o descripción
    // toca el maestro, que se resuelve en PHP (ver mapaMaestro en model_consolidados.php).
    if (!empty($filtros['cedi'])) {
        $where[] = 'l.cedi = :cedi';
        $params[':cedi'] = $filtros['cedi'];
    }
    if (!empty($filtros['punto_venta'])) {
        $where[] = 'l.punto_venta = :pv';
        $params[':pv'] = $filtros['punto_venta'];
    }

    // MAX(pedido_sap): el número se guarda en todas las líneas del grupo (ver guardarPedidoSap),
    // así que cualquiera de ellas sirve. MAX es lo que permite traerlo dentro de un GROUP BY sin
    // tener que agregarlo a la agrupación.
    // MAX(ean_punto_venta): una tienda tiene un solo EAN, así que dentro del grupo es siempre el
    // mismo valor; MAX es la forma de traerlo sin sumarlo a la agrupación. Es lo que identifica a
    // la tienda en el código de barras del rótulo, porque el NOMBRE no sirve: 14 de las 69
    // tiendas no llevan el código al principio ("TURBO CARULLA LIMONAR-4845") y alguna no lo
    // tiene en ninguna parte ("Carulla La Maria").
    // MAX(id_personal) y MAX(nombre): la asignación se guarda en todas las líneas de la entrega
    // (ver asignarPersonalAEntrega), así que dentro del grupo es siempre el mismo valor. MAX es lo
    // que permite traerlo dentro de un GROUP BY sin sumarlo a la agrupación.
    $sql = "SELECT l.cedi, l.orden_compra, l.punto_venta, l.plu, l.ean_item,
                   SUM(l.unidades) AS unidades,
                   MAX(l.pedido_sap) AS pedido_sap,
                   MAX(l.ean_punto_venta) AS ean_punto_venta,
                   MAX(l.id_personal) AS id_personal,
                   MAX(p.nombre) AS personal_nombre
            FROM consolidado_lineas l
            LEFT JOIN personal p ON p.id_personal = l.id_personal
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.cedi, l.orden_compra, l.punto_venta, l.plu, l.ean_item";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $mapa   = mapaMaestro($pdo);
    $patron = isset($filtros['busqueda']) ? mb_strtolower(trim($filtros['busqueda'])) : '';

    $filas = [];
    foreach ($stmt as $fila) {
        $fila = decorarConMaestro($fila, $mapa);

        if ($patron !== '') {
            $donde = mb_strtolower(
                ($fila['plu'] ?? '') . ' ' . ($fila['sku'] ?? '') . ' ' .
                ($fila['descripcion'] ?? '') . ' ' . $fila['punto_venta']
            );
            if (mb_strpos($donde, $patron) === false) {
                continue;
            }
        }

        $filas[] = $fila;
    }

    // Por CEDI, punto de venta y descripción; los que no tienen maestro al final de su tienda.
    usort($filas, function ($a, $b) {
        $cmp = strcmp($a['cedi'] . $a['punto_venta'], $b['cedi'] . $b['punto_venta']);
        if ($cmp !== 0) { return $cmp; }
        if (($a['descripcion'] === null) !== ($b['descripcion'] === null)) {
            return $a['descripcion'] === null ? 1 : -1;
        }
        return strcmp((string) $a['descripcion'] . $a['plu'], (string) $b['descripcion'] . $b['plu']);
    });

    return $filas;
}

/**
 * Agrupa las filas de filasPicking() por ENTREGA: CEDI + orden de compra + punto de venta.
 *
 * Es la unidad real de trabajo del picker —arma una tienda entera y la despacha—, y es también lo
 * que se repetía en cada fila de la tabla plana: la misma tienda, el mismo CEDI y la misma orden
 * escritos otra vez en cada producto. Al subirlos a la cabecera del grupo, cada fila queda con lo
 * único que cambia entre una y otra: el producto.
 *
 * El pedido SAP también es de la entrega y no del producto (ver guardarPedidoSap), así que vive
 * en la cabecera: un solo campo por entrega en vez del mismo número repetido en seis filas.
 *
 * Devuelve un array indexado por la clave del grupo, en el mismo orden en que venían las filas.
 */
function agruparPorEntrega(array $filas) {
    $entregas = [];

    foreach ($filas as $f) {
        $clave = $f['cedi'] . '|' . $f['orden_compra'] . '|' . $f['punto_venta'];

        if (!isset($entregas[$clave])) {
            $entregas[$clave] = [
                'clave'           => $clave,
                'cedi'            => $f['cedi'],
                'orden_compra'    => $f['orden_compra'],
                'punto_venta'     => $f['punto_venta'],
                'ean_punto_venta' => $f['ean_punto_venta'],
                'pedido_sap'      => $f['pedido_sap'],
                'id_personal'     => $f['id_personal'],
                'personal_nombre' => $f['personal_nombre'],
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

    // Segunda pasada: las CAJAS FÍSICAS de cada línea y en qué número del pedido empiezan sus
    // rótulos.
    //
    // OJO CON LA DIFERENCIA ENTRE "cajas" Y "cajas_rotulo".
    //
    // `cajas` son cajas COMPLETAS: 8 unidades de un producto que viene de a 12 son 0 cajas y 8
    // saldos. Es la cuenta que necesita el elevador, porque baja estibas de cajas llenas.
    //
    // `cajas_rotulo` son las cajas que FÍSICAMENTE salen hacia la tienda, y eso se redondea hacia
    // arriba: esas 8 unidades igual viajan dentro de una caja, aunque no la llenen. Un pedido de
    // tres productos con 8 unidades cada uno son TRES cajas, no cero — que es lo que mostraba
    // antes, dejando el rótulo en "CAJ 1 DE 1" para un pedido de tres cajas.
    //
    // Cuando el producto no está en el maestro no se puede dividir, así que se cuenta 1: hay
    // unidades, luego hay al menos una caja. Es lo mínimo cierto, y el rótulo es editable para
    // corregirlo.
    //
    // La numeración va CORRIDA sobre el total del pedido y no por producto: la tienda recibe 8
    // cajas y las cuenta como 8, sin saber cuántas eran de cada sabor. El orden es el mismo en
    // que las líneas salen de filasPicking(), que es el que se pinta en la tabla, así que el
    // número de la etiqueta coincide con el renglón que se está mirando.
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
            $linea['cajas_pedido'] = 0;   // se completa abajo, cuando ya se sabe el total
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
 * Reparte las entregas de agruparPorEntrega() por CEDI, que es la zona de despacho.
 *
 * Cada CEDI queda con su lista de entregas y sus totales sumados, para poder mostrar en la
 * cabecera el nombre del CEDI y cuántos pedidos lleva sin recorrer las entregas otra vez desde
 * la vista.
 */
function agruparPorCedi(array $entregas) {
    $porCedi = [];

    foreach ($entregas as $entrega) {
        $cedi = $entrega['cedi'];

        if (!isset($porCedi[$cedi])) {
            $porCedi[$cedi] = [
                'cedi'      => $cedi,
                'entregas'  => [],
                'totales'   => [
                    'pedidos' => 0, 'lineas' => 0, 'unidades' => 0,
                    'cajas'   => 0, 'saldos' => 0, 'peso_kg' => 0, 'sin_maestro' => 0,
                ],
            ];
        }

        $porCedi[$cedi]['entregas'][] = $entrega;

        $t = &$porCedi[$cedi]['totales'];
        $t['pedidos']++;
        foreach (['lineas', 'unidades', 'cajas', 'saldos', 'peso_kg', 'sin_maestro'] as $campo) {
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
 * Una sola entrega, con sus líneas, para la hoja de picking en PDF.
 *
 * Reutiliza filasPicking() y agruparPorEntrega() en vez de tener su propia consulta: si las dos
 * rutas calcularan las cajas por su cuenta, la hoja impresa podría no coincidir con la pantalla
 * desde la que se pidió, que es la peor forma de descubrir un error de cálculo.
 */
function entregaPicking($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta) {
    $filas = filasPicking($pdo, $idCarga, ['cedi' => $cedi, 'punto_venta' => $puntoVenta]);

    $entregas = agruparPorEntrega($filas);
    $clave = $cedi . '|' . $ordenCompra . '|' . $puntoVenta;

    return $entregas[$clave] ?? null;
}

// Los puntos de venta de la carga, para el desplegable de filtro.
function puntosDeVenta($pdo, $idCarga, $cedi = null) {
    $sql = "SELECT DISTINCT punto_venta FROM consolidado_lineas WHERE id_carga = :carga";
    $params = [':carga' => $idCarga];

    if (!empty($cedi)) {
        $sql .= " AND cedi = :cedi";
        $params[':cedi'] = $cedi;
    }
    $sql .= " ORDER BY punto_venta";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Guarda el número de pedido SAP.
 *
 * Se escribe en TODAS las líneas de (CEDI, orden de compra, punto de venta), no solo en la del
 * PLU desde el que se escribió: el pedido SAP identifica la ENTREGA a esa tienda, no el producto.
 * Guardarlo por producto haría que la misma entrega apareciera con cinco números distintos, y el
 * rótulo de cada caja diría uno diferente.
 *
 * Devuelve true si guardó.
 */
function guardarPedidoSap($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta, $pedidoSap) {
    $pedidoSap = trim((string) $pedidoSap);

    try {
        $stmt = $pdo->prepare(
            "UPDATE consolidado_lineas
             SET pedido_sap = :sap
             WHERE id_carga = :carga AND cedi = :cedi
               AND orden_compra = :oc AND punto_venta = :pv"
        );
        $stmt->execute([
            // Vacío se guarda como NULL y no como cadena vacía: así "sin pedido SAP" es un solo
            // valor y no dos que hay que comprobar por separado en cada consulta.
            ':sap'   => $pedidoSap === '' ? null : mb_substr($pedidoSap, 0, 40),
            ':carga' => $idCarga,
            ':cedi'  => $cedi,
            ':oc'    => $ordenCompra,
            ':pv'    => $puntoVenta,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('Error guardando el pedido SAP: ' . $e->getMessage());
        return false;
    }
}

// Totales de la pantalla. Las filas sin maestro no suman cajas (ver desglosarCajas) y se cuentan
// aparte, para poder decir cuántas quedaron sin poder calcular en vez de dar un total que parece
// completo y no lo es.
function resumenPicking(array $filas) {
    $resumen = [
        'puntos_venta' => count(array_unique(array_map(
            fn($f) => $f['cedi'] . '|' . $f['orden_compra'] . '|' . $f['punto_venta'], $filas
        ))),
        'lineas'      => count($filas),
        'unidades'    => 0,
        'cajas'       => 0,
        'saldos'      => 0,
        'peso_kg'     => 0,
        'sin_maestro' => 0,
    ];

    foreach ($filas as $f) {
        $resumen['unidades'] += (int) $f['unidades'];
        if ($f['sin_maestro']) {
            $resumen['sin_maestro']++;
        } else {
            $resumen['cajas']  += (int) $f['cajas'];
            $resumen['saldos'] += (int) $f['saldos'];
        }
        if ($f['peso_kg'] !== null) {
            $resumen['peso_kg'] += (float) $f['peso_kg'];
        }
    }

    $resumen['peso_kg'] = round($resumen['peso_kg'], 2);

    return $resumen;
}
