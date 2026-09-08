<?php
// modules/picking/model_picking.php
// El alistamiento: qué tiene que armar el picker para cada punto de venta.
//
// Se apoya en el mismo Consolidado que la pantalla anterior; la diferencia es la agrupación. El
// Consolidado suma todo el CEDI porque el elevador baja producto; Picking abre por punto de venta
// porque el picker arma una entrega por tienda.
//
// YA NO MIRA "LA CARGA VIGENTE". Antes cada importación reemplazaba lo pendiente y solo podía
// haber una carga activa a la vez; ahora los archivos se acumulan (ver importarConsolidado en
// model_consolidados_import.php) y acá se mira TODO lo pendiente de TODAS las cargas juntas,
// diferenciado por su fecha —decidido con el usuario el 2026-09-08—. Por eso cada entrega lleva
// su propio id_carga en la clave, y ya no solo cedi+orden_compra+punto_venta: la misma tienda
// puede tener un pedido pendiente de ayer y otro de hoy, y son dos entregas distintas.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados.php';   // desglosarCajas()

/**
 * Una fila por (carga, CEDI, orden de compra, punto de venta, PLU).
 *
 * El id_carga entra en la agrupación por la misma razón que el resto: dos entregas de la misma
 * tienda en cargas distintas no se pueden sumar como si fueran una, aunque coincida hasta el
 * número de orden de compra.
 *
 * $filtros acepta 'cedi', 'punto_venta' y 'busqueda' (PLU, SKU o descripción).
 */
function filasPicking($pdo, array $filtros = []) {
    // despachado = 0 SIEMPRE, no como filtro opcional: una vez que una entrega salió, tiene que
    // dejar de existir para Picking (y, como Consolidados se apoya en la misma columna, también
    // para Consolidados). Si esto fuera condicional, un pedido despachado seguiría apareciendo
    // cada vez que alguien llamara a esta función sin acordarse de excluirlo.
    $where  = ['l.despachado = 0'];
    $params = [];

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
    // MAX(fecha_carga) y MAX(nombre_archivo): igual que el resto de los MAX de acá arriba —dentro
    // del grupo (que ya incluye id_carga) son siempre el mismo valor—, y son lo que permite
    // mostrar de qué archivo y de qué fecha vino cada entrega, ahora que puede haber varias cargas
    // pendientes al mismo tiempo.
    $sql = "SELECT l.id_carga, l.cedi, l.orden_compra, l.punto_venta, l.plu, l.ean_item,
                   SUM(l.unidades) AS unidades,
                   MAX(l.pedido_sap) AS pedido_sap,
                   MAX(l.ean_punto_venta) AS ean_punto_venta,
                   MAX(l.id_personal) AS id_personal,
                   MAX(p.nombre) AS personal_nombre,
                   MAX(cc.fecha_carga) AS fecha_carga,
                   MAX(cc.nombre_archivo) AS nombre_archivo
            FROM consolidado_lineas l
            LEFT JOIN personal p ON p.id_personal = l.id_personal
            LEFT JOIN consolidado_cargas cc ON cc.id_carga = l.id_carga
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.id_carga, l.cedi, l.orden_compra, l.punto_venta, l.plu, l.ean_item";

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

    // Por fecha (lo más nuevo primero), después CEDI, punto de venta y descripción; los que no
    // tienen maestro al final de su tienda. La fecha manda porque es justo lo que separa una
    // entrega de otra ahora que puede haber varias cargas pendientes a la vez.
    usort($filas, function ($a, $b) {
        $cmp = strcmp((string) $b['fecha_carga'], (string) $a['fecha_carga']);
        if ($cmp !== 0) { return $cmp; }
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
 * Agrupa las filas de filasPicking() por ENTREGA: carga + CEDI + orden de compra + punto de
 * venta.
 *
 * Es la unidad real de trabajo del picker —arma una tienda entera y la despacha—, y es también lo
 * que se repetía en cada fila de la tabla plana: la misma carga, el mismo CEDI y la misma orden
 * escritos otra vez en cada producto. Al subirlos a la cabecera del grupo, cada fila queda con lo
 * único que cambia entre una y otra: el producto.
 *
 * La carga entra en la clave (y no solo CEDI+orden+punto de venta) porque ahora pueden convivir
 * pendientes de varios archivos: la misma tienda con la misma orden de compra en dos cargas
 * distintas son DOS entregas, no una —fusionarlas sumaría cajas de dos pedidos que llegaron por
 * separado como si fueran uno solo.
 *
 * El pedido SAP también es de la entrega y no del producto (ver guardarPedidoSap), así que vive
 * en la cabecera: un solo campo por entrega en vez del mismo número repetido en seis filas.
 *
 * Devuelve un array indexado por la clave del grupo, en el mismo orden en que venían las filas.
 */
function agruparPorEntrega(array $filas) {
    $entregas = [];

    foreach ($filas as $f) {
        $clave = $f['id_carga'] . '|' . $f['cedi'] . '|' . $f['orden_compra'] . '|' . $f['punto_venta'];

        if (!isset($entregas[$clave])) {
            $entregas[$clave] = [
                'clave'           => $clave,
                'id_carga'        => $f['id_carga'],
                'fecha_carga'     => $f['fecha_carga'],
                'nombre_archivo'  => $f['nombre_archivo'],
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
 * la vista. Un mismo CEDI puede traer entregas de varias fechas a la vez —cada una lo dice en su
 * propia fila (ver fecha_carga)—, así que acá no hace falta separarlas: se agrupan igual que
 * siempre y la fecha se distingue dentro del grupo, no entre grupos.
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

    // El CEDI con MENOS líneas primero, hacia abajo de menor a mayor —el mismo criterio que
    // consolidadoPorCedi() en model_consolidados.php, y por la misma razón (decidido con el
    // usuario el 2026-09-08): un CEDI chico se resuelve rápido, y verlo primero deja el grande
    // para después de haber arrancado con lo fácil, en vez de tener que ir a buscarlo entre CEDI
    // ordenados alfabéticamente sin relación con cuánto trabajo representan.
    uasort($porCedi, fn($a, $b) => $a['totales']['lineas'] <=> $b['totales']['lineas']);

    return $porCedi;
}

/**
 * Varias entregas de una vez, para imprimir sus hojas juntas.
 *
 * $claves es una lista de ['carga' => ..., 'cedi' => ..., 'oc' => ..., 'pv' => ...]. Se lee TODO
 * lo pendiente una sola vez y después se filtra en memoria: llamando a entregaPicking() en un
 * bucle, veinte pedidos seleccionados serían veinte consultas más veinte lecturas del maestro.
 *
 * Devuelve las entregas en el mismo orden en que aparecen en la pantalla, no en el que se hayan
 * tildado: la carpeta de hojas impresas queda ordenada como la tabla.
 */
function entregasPicking($pdo, array $claves) {
    if (!$claves) {
        return [];
    }

    $buscadas = [];
    foreach ($claves as $c) {
        $clave = trim((string) ($c['carga'] ?? '')) . '|' . trim((string) ($c['cedi'] ?? ''))
               . '|' . trim((string) ($c['oc'] ?? '')) . '|' . trim((string) ($c['pv'] ?? ''));
        $buscadas[$clave] = true;
    }

    $entregas = agruparPorEntrega(filasPicking($pdo));

    return array_values(array_filter(
        $entregas,
        fn($clave) => isset($buscadas[$clave]),
        ARRAY_FILTER_USE_KEY
    ));
}

/**
 * Una sola entrega, con sus líneas, para la hoja de picking en PDF.
 *
 * Reutiliza filasPicking() y agruparPorEntrega() en vez de tener su propia consulta: si las dos
 * rutas calcularan las cajas por su cuenta, la hoja impresa podría no coincidir con la pantalla
 * desde la que se pidió, que es la peor forma de descubrir un error de cálculo.
 */
function entregaPicking($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta) {
    $filas = filasPicking($pdo, ['cedi' => $cedi, 'punto_venta' => $puntoVenta]);

    $entregas = agruparPorEntrega($filas);
    $clave = $idCarga . '|' . $cedi . '|' . $ordenCompra . '|' . $puntoVenta;

    return $entregas[$clave] ?? null;
}

// Los puntos de venta con algo pendiente, para el desplegable de filtro. Ya no se limita a una
// carga: junta los de todas las que sigan teniendo líneas sin despachar.
function puntosDeVenta($pdo, $cedi = null) {
    $sql = "SELECT DISTINCT punto_venta FROM consolidado_lineas WHERE despachado = 0";
    $params = [];

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
 * Se escribe en TODAS las líneas de (carga, CEDI, orden de compra, punto de venta), no solo en la
 * del PLU desde el que se escribió: el pedido SAP identifica la ENTREGA a esa tienda, no el
 * producto. Guardarlo por producto haría que la misma entrega apareciera con cinco números
 * distintos, y el rótulo de cada caja diría uno diferente.
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

/**
 * Despacha una o varias entregas: las marca como salidas, y desde ese momento filasPicking() ya
 * no las devuelve —así desaparecen de Picking— y tampoco consolidadoPorCedi(), que se apoya en la
 * misma columna —así desaparecen también de Consolidados.
 *
 * NO SE CONFÍA EN LO QUE MANDÓ EL NAVEGADOR. El botón de la pantalla ya comprueba en el cliente que
 * haya personal asignado, pero esa comprobación mira el DOM en el momento del clic: si dos
 * personas tienen Picking abierto y una quita una asignación mientras la otra ya tenía la pantalla
 * cargada, el clic de la segunda vería datos viejos. Por eso cada entrega se vuelve a mirar en la
 * base justo antes de despacharla.
 *
 * Es TODO O NADA: si alguna de las entregas pedidas no tiene personal (o ya no existe, por ejemplo
 * porque esa carga se restauró o cambió), NINGUNA se despacha. Despachar la mitad de un lote y
 * dejar la otra mitad pendiente sin que quien lo pidió lo haya decidido así es más confuso que
 * simplemente no hacer nada y decir por qué.
 *
 * $claves es una lista de ['carga' => ..., 'cedi' => ..., 'oc' => ..., 'pv' => ...]. La carga es
 * obligatoria acá —a diferencia de antes— porque ahora una misma tienda puede tener más de un
 * pedido pendiente a la vez, cada uno en su propia carga.
 *
 * Devuelve:
 *   ['exito' => true,  'despachadas' => int]  — cuántas entregas se marcaron
 *   ['exito' => false, 'mensaje' => string, 'sin_personal' => [['punto_venta'=>, 'orden_compra'=>], ...]]
 */
function despacharEntregas($pdo, array $claves, $idUsuario) {
    if (!$claves) {
        return ['exito' => false, 'mensaje' => 'No se recibió ninguna entrega para despachar.', 'sin_personal' => []];
    }

    // Se relee TODO lo pendiente una sola vez (igual que hace entregasPicking()) y se comprueba
    // cada clave pedida contra ese estado fresco, en vez de una consulta por clave.
    $entregasActuales = agruparPorEntrega(filasPicking($pdo));

    $porDespachar = [];
    $sinPersonal  = [];

    foreach ($claves as $c) {
        $carga = trim((string) ($c['carga'] ?? ''));
        $cedi  = trim((string) ($c['cedi'] ?? ''));
        $oc    = trim((string) ($c['oc'] ?? $c['orden_compra'] ?? ''));
        $pv    = trim((string) ($c['pv'] ?? $c['punto_venta'] ?? ''));

        if ($carga === '' || $cedi === '' || $oc === '' || $pv === '') {
            continue;
        }

        $clave = $carga . '|' . $cedi . '|' . $oc . '|' . $pv;
        $entrega = $entregasActuales[$clave] ?? null;

        // No existe (ya se despachó, o esa carga cambió): no hay nada que despachar, pero
        // tampoco es un error a mostrar — simplemente no entra ni a la lista buena ni a la mala.
        if ($entrega === null) {
            continue;
        }

        if (empty($entrega['id_personal'])) {
            $sinPersonal[] = ['punto_venta' => $pv, 'orden_compra' => $oc];
            continue;
        }

        $porDespachar[] = ['carga' => $carga, 'cedi' => $cedi, 'oc' => $oc, 'pv' => $pv];
    }

    if ($sinPersonal) {
        return [
            'exito'        => false,
            'mensaje'      => count($sinPersonal) === 1
                ? 'Hay un pedido sin personal asignado. Asignalo antes de despachar.'
                : 'Hay ' . count($sinPersonal) . ' pedidos sin personal asignado. Asignalos antes de despachar.',
            'sin_personal' => $sinPersonal,
        ];
    }

    if (!$porDespachar) {
        return ['exito' => false, 'mensaje' => 'Ninguno de los pedidos seleccionados sigue disponible para despachar.', 'sin_personal' => []];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "UPDATE consolidado_lineas
             SET despachado = 1, fecha_despacho = NOW(), despachado_por = :usuario
             WHERE id_carga = :carga AND cedi = :cedi AND orden_compra = :oc AND punto_venta = :pv
               AND despachado = 0"
        );

        foreach ($porDespachar as $p) {
            $stmt->execute([
                ':usuario' => $idUsuario ?: null,
                ':carga'   => $p['carga'],
                ':cedi'    => $p['cedi'],
                ':oc'      => $p['oc'],
                ':pv'      => $p['pv'],
            ]);
        }

        $pdo->commit();

        return ['exito' => true, 'despachadas' => count($porDespachar)];

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error despachando entregas: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo despachar. Inténtalo de nuevo.', 'sin_personal' => []];
    }
}

// Totales de la pantalla. Las filas sin maestro no suman cajas (ver desglosarCajas) y se cuentan
// aparte, para poder decir cuántas quedaron sin poder calcular en vez de dar un total que parece
// completo y no lo es.
function resumenPicking(array $filas) {
    $resumen = [
        'puntos_venta' => count(array_unique(array_map(
            fn($f) => $f['id_carga'] . '|' . $f['cedi'] . '|' . $f['orden_compra'] . '|' . $f['punto_venta'], $filas
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
