<?php
// modules/consolidados/model_consolidados.php
// Consultas del Consolidado. Lo usan la pantalla de Consolidados, el PDF por CEDI y Picking.

require_once __DIR__ . '/../../config/config.php';

// ---------------------------------------------------------------------------------------------
// EL CÁLCULO CENTRAL: de unidades a cajas y saldos.
//
// El archivo del cliente viene en UNIDADES; la bodega trabaja en CAJAS. La conversión necesita
// `unidades_por_caja`, que no está en ese archivo sino en el maestro de productos.
//
// Cuando el PLU no está en el maestro devuelve sin_maestro = true y cajas/saldos en null, y NO
// un cero: un cero se lee como "no lleva ninguna caja", que es una afirmación distinta de "no
// sabemos cuántas cajas son". Toda la pantalla depende de esa diferencia para poder avisar
// cuántas filas están esperando el maestro.
// ---------------------------------------------------------------------------------------------
function desglosarCajas($unidades, $unidadesPorCaja) {
    $unidades = (int) $unidades;

    if (empty($unidadesPorCaja) || (int) $unidadesPorCaja <= 0) {
        return ['unidades' => $unidades, 'cajas' => null, 'saldos' => null, 'sin_maestro' => true];
    }

    $porCaja = (int) $unidadesPorCaja;

    return [
        'unidades'    => $unidades,
        'cajas'       => intdiv($unidades, $porCaja),
        'saldos'      => $unidades % $porCaja,
        'sin_maestro' => false,
    ];
}

// ---------------------------------------------------------------------------------------------
// EL MAESTRO, EN MEMORIA
//
// El producto del Consolidado se puede reconocer por DOS caminos: el EAN (que es lo que trae el
// export de SAP) o el PLU (lo que trae una planilla armada a mano). Manda el EAN, porque es el
// código del producto y no el que le puso la cadena.
//
// POR QUÉ ACÁ Y NO EN UN JOIN
// Un `LEFT JOIN ... ON m.ean = l.ean_item OR m.plu = l.plu` puede enganchar DOS filas distintas
// del maestro con la misma línea (una por EAN, otra por PLU) si esos datos quedaron
// inconsistentes. Y como las consultas suman unidades, esa fila de más no da un dato raro en
// pantalla: da un total DUPLICADO, que es el peor error posible acá porque parece correcto.
// Resolviéndolo en PHP, cada línea tiene un producto o ninguno, nunca dos.
//
// El maestro son decenas o cientos de filas, así que cargarlo entero no cuesta nada.
// ---------------------------------------------------------------------------------------------
function mapaMaestro($pdo) {
    $mapa = ['ean' => [], 'plu' => []];

    $sql = "SELECT sku, ean, plu, descripcion, unidades_por_caja, presentacion, peso_unidad_kg, linea
            FROM maestro_productos";

    foreach ($pdo->query($sql) as $fila) {
        if (!empty($fila['ean'])) { $mapa['ean'][$fila['ean']] = $fila; }
        if (!empty($fila['plu'])) { $mapa['plu'][$fila['plu']] = $fila; }
    }

    return $mapa;
}

// Devuelve la fila del maestro para una línea del Consolidado, o null.
function productoDelMaestro(array $mapa, $ean, $plu) {
    if (!empty($ean) && isset($mapa['ean'][$ean])) {
        return $mapa['ean'][$ean];
    }
    if (!empty($plu) && isset($mapa['plu'][$plu])) {
        return $mapa['plu'][$plu];
    }
    return null;
}

// Junta una línea agrupada del Consolidado con su producto del maestro y le calcula el desglose.
// Lo usan por igual el Consolidado y Picking, para que las dos pantallas no puedan discrepar en
// cuántas cajas es lo mismo.
function decorarConMaestro(array $linea, array $mapa) {
    $producto = productoDelMaestro($mapa, $linea['ean_item'] ?? null, $linea['plu'] ?? null);

    $linea['sku']               = $producto['sku'] ?? null;
    $linea['descripcion']       = $producto['descripcion'] ?? null;
    $linea['unidades_por_caja'] = $producto['unidades_por_caja'] ?? null;
    $linea['presentacion']      = $producto['presentacion'] ?? null;
    $linea['linea']             = $producto['linea'] ?? null;

    $linea += desglosarCajas($linea['unidades'], $linea['unidades_por_caja']);

    // Peso total de lo pedido. Es null —y no cero— cuando el producto no tiene peso cargado, por
    // el mismo motivo que las cajas: cero significaría que no pesa nada.
    $pesoUnidad = $producto['peso_unidad_kg'] ?? null;
    $linea['peso_kg'] = ($pesoUnidad !== null && $pesoUnidad > 0)
        ? round((float) $pesoUnidad * (int) $linea['unidades'], 2)
        : null;

    return $linea;
}

// La carga vigente: siempre la última importada. No hay pantalla para elegir entre cargas viejas
// porque el archivo del día es la foto completa — mirar el de ayer al lado del de hoy solo sirve
// para alistar lo que no era.
function cargaVigente($pdo) {
    $stmt = $pdo->query(
        "SELECT c.id_carga, c.nombre_archivo, c.filas, c.fecha_carga, u.nombre_usuario
         FROM consolidado_cargas c
         LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario
         ORDER BY c.id_carga DESC LIMIT 1"
    );
    return $stmt->fetch() ?: null;
}

// Los CEDI que trae la carga. Salen del propio archivo y no de una lista fija: hoy vienen tres,
// otro día pueden ser cuatro, y una lista escrita a mano dejaría el cuarto sin pantalla.
//
// despachado = 0: un CEDI cuyas entregas ya salieron todas no debe seguir ofreciéndose en el
// filtro ni contando para "cuántos CEDI trae la carga" — ya no queda nada pendiente ahí.
function cedisDeLaCarga($pdo, $idCarga) {
    $stmt = $pdo->prepare(
        "SELECT cedi, COUNT(*) AS lineas, SUM(unidades) AS unidades
         FROM consolidado_lineas WHERE id_carga = :carga AND despachado = 0
         GROUP BY cedi ORDER BY cedi"
    );
    $stmt->execute([':carga' => $idCarga]);
    return $stmt->fetchAll();
}

// Las líneas de producto que existen en el maestro, para el desplegable de filtro.
function lineasDelMaestro($pdo) {
    return $pdo->query(
        "SELECT DISTINCT linea FROM maestro_productos
         WHERE linea IS NOT NULL AND linea <> '' ORDER BY linea"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// ---------------------------------------------------------------------------------------------
// EL CONSOLIDADO PARA EL ELEVADOR
//
// Una fila por CEDI y PLU, con las unidades de TODOS los puntos de venta de ese CEDI sumadas:
// al elevador no le sirve saber a qué tienda va cada caja, sino cuántas cajas de cada producto
// tiene que bajar para ese CEDI. El reparto por tienda es problema de Picking.
//
// $filtros acepta 'cedi', 'linea' y 'plu' (búsqueda por PLU, SKU o descripción).
// ---------------------------------------------------------------------------------------------
function consolidadoPorCedi($pdo, $idCarga, array $filtros = []) {
    // despachado = 0 SIEMPRE: una entrega despachada ya salió de bodega, y no tiene que seguir
    // apareciendo como pendiente ni acá ni en el PDF que se arma con esto mismo.
    $where  = ['l.id_carga = :carga', 'l.despachado = 0'];
    $params = [':carga' => $idCarga];

    // Solo el CEDI se filtra en SQL: es una columna del propio Consolidado. La línea y la búsqueda
    // por descripción viven en el maestro, que se resuelve en PHP (ver mapaMaestro), así que se
    // aplican abajo sobre el resultado.
    if (!empty($filtros['cedi'])) {
        $where[] = 'l.cedi = :cedi';
        $params[':cedi'] = $filtros['cedi'];
    }

    $sql = "SELECT l.cedi, l.plu, l.ean_item,
                   SUM(l.unidades) AS unidades,
                   COUNT(DISTINCT l.punto_venta) AS puntos_venta
            FROM consolidado_lineas l
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.cedi, l.plu, l.ean_item";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $mapa   = mapaMaestro($pdo);
    $patron = isset($filtros['plu']) ? mb_strtolower(trim($filtros['plu'])) : '';

    // Se agrupa por CEDI acá y no en la vista: el PDF necesita exactamente la misma agrupación, y
    // con la lógica en la vista habría que repetirla —y mantenerla— en los dos lados.
    $porCedi = [];
    foreach ($stmt as $fila) {
        $fila = decorarConMaestro($fila, $mapa);

        if (!empty($filtros['linea']) && $fila['linea'] !== $filtros['linea']) {
            continue;
        }

        // La misma caja busca por PLU, por SKU o por descripción: quien la usa tiene un papel en
        // la mano con uno de los tres y no tiene por qué saber cuál es.
        if ($patron !== '') {
            $donde = mb_strtolower(($fila['plu'] ?? '') . ' ' . ($fila['sku'] ?? '') . ' ' . ($fila['descripcion'] ?? ''));
            if (mb_strpos($donde, $patron) === false) {
                continue;
            }
        }

        $porCedi[$fila['cedi']][] = $fila;
    }

    // ORDEN: primero los productos que van a MÁS puntos de venta.
    //
    // Es el orden de trabajo del elevador: el producto que se reparte entre más tiendas es el que
    // más veces hay que tocar, así que conviene tenerlo bajado y a mano desde el principio. Los
    // que van a una sola tienda pueden esperar al final.
    //
    // A igual cantidad de tiendas manda el que mueve más unidades, y recién ahí el orden
    // alfabético — que no aporta nada operativo, pero hace que dos cargas del mismo archivo
    // salgan siempre en el mismo orden en vez de depender de cómo las devolvió la base.
    ksort($porCedi);
    foreach ($porCedi as &$filas) {
        usort($filas, function ($a, $b) {
            $cmp = (int) $b['puntos_venta'] <=> (int) $a['puntos_venta'];
            if ($cmp !== 0) { return $cmp; }

            $cmp = (int) $b['unidades'] <=> (int) $a['unidades'];
            if ($cmp !== 0) { return $cmp; }

            return strcmp((string) $a['descripcion'] . $a['plu'], (string) $b['descripcion'] . $b['plu']);
        });
    }
    unset($filas);

    return $porCedi;
}

// Totales de un grupo ya calculado por consolidadoPorCedi(). Las cajas de las filas sin maestro
// no suman (son null), así que además se informa cuántas quedaron afuera: sin ese dato el total
// parecería completo cuando no lo es.
function totalesDelGrupo(array $filas) {
    $totales = [
        'unidades' => 0, 'cajas' => 0, 'saldos' => 0, 'peso_kg' => 0,
        'sin_maestro' => 0, 'productos' => count($filas),
    ];

    foreach ($filas as $f) {
        $totales['unidades'] += (int) $f['unidades'];
        if ($f['sin_maestro']) {
            $totales['sin_maestro']++;
        } else {
            $totales['cajas']  += (int) $f['cajas'];
            $totales['saldos'] += (int) $f['saldos'];
        }
        // El peso se suma aparte de las cajas: un producto puede tener unidades por caja y no
        // tener peso cargado, o al revés.
        if ($f['peso_kg'] !== null) {
            $totales['peso_kg'] += (float) $f['peso_kg'];
        }
    }

    $totales['peso_kg'] = round($totales['peso_kg'], 2);

    return $totales;
}

// Cuántos productos distintos de la carga no se pueden convertir a cajas: o no están en el
// maestro, o están pero sin unidades por caja. Es el número que decide si la pantalla muestra el
// aviso de "falta cargar el maestro".
//
// Se cuenta por PLU + EAN y no solo por PLU porque la resolución del maestro mira los dos.
function pluSinMaestro($pdo, $idCarga) {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT plu, ean_item FROM consolidado_lineas WHERE id_carga = :carga AND despachado = 0"
    );
    $stmt->execute([':carga' => $idCarga]);

    $mapa = mapaMaestro($pdo);
    $faltan = 0;

    foreach ($stmt as $fila) {
        $producto = productoDelMaestro($mapa, $fila['ean_item'], $fila['plu']);
        if ($producto === null || empty($producto['unidades_por_caja'])) {
            $faltan++;
        }
    }

    return $faltan;
}
