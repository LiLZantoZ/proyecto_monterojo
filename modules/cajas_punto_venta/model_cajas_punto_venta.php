<?php
// modules/cajas_punto_venta/model_cajas_punto_venta.php
// Cuántas cajas le corresponden a CADA punto de venta, para los CEDI de las cadenas.
//
// Es la vista más corta de todas: ni productos ni unidades, solo "a esta tienda le van N cajas".
// Es lo que se mira al cargar el camión y al firmar la entrega en el CEDI, donde nadie va a
// contar unidades sino bultos.
//
// SOLO LOS PUNTOS DE VENTA ANEXADOS A UN CEDI
// Monterojo despacha por dos caminos: a los CEDI de las cadenas —que después reparten a sus
// tiendas— y directo a clientes propios (distribuidores, hoteles, personas). Los dos entran al
// sistema como "Consolidado", y en los dos el campo `cedi` viene lleno: en el primero con el CEDI
// de verdad ("3 - CEDI VEGAS"), y en el segundo con la ciudad del cliente ("CAJICA", "MONTERIA").
// Acá solo interesan los primeros.
//
// El filtro NO mira el nombre buscando la palabra "CEDI": eso dependería de cómo la cadena decida
// escribirlo el mes que viene. Mira si el CEDI tiene alguna línea con `ean_punto_venta` cargado,
// que es un dato que SOLO existe en el formato de archivo de las cadenas —el export de SAP, que es
// el de los clientes directos, no trae esa columna en absoluto (ver columnasConsolidadoSap)—. O
// sea: la pregunta que se le hace a los datos es "¿este pedido vino de una cadena que identifica
// sus tiendas?", que es exactamente lo que significa "anexado a un CEDI".

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados.php';   // mapaMaestro(), decorarConMaestro()
require_once __DIR__ . '/../historial/helper_rotulos_lista.php';       // numeroYNombreDePunto()


/**
 * Las cajas por punto de venta, agrupadas por CEDI.
 *
 * Devuelve: [cedi => ['puntos' => [...], 'totales' => [...]]], donde cada punto trae
 * numero, nombre, orden_compra, productos, unidades, cajas, saldos, sin_maestro.
 *
 * Las cajas se calculan producto por producto y recién después se suman. No se puede sumar todas
 * las unidades de la tienda y dividir al final: cada producto tiene sus propias unidades por caja,
 * y 20 unidades de uno que va de a 12 más 20 de otro que va de a 20 no son 40 unidades "de a 16",
 * son 1 caja + 8 y 1 caja exacta.
 */
function cajasPorPuntoDeVenta($pdo, array $filtros = []) {
    $where = [
        'l.despachado = 0',
        // Solo los pedidos del Éxito. Ver condicionPedidoExito() en model_consolidados.php.
        condicionPedidoExito('l'),
    ];
    $params = [];

    if (!empty($filtros['cedi'])) {
        $where[] = 'l.cedi = :cedi';
        $params[':cedi'] = $filtros['cedi'];
    }

    $sql = "SELECT l.cedi, l.punto_venta, l.ean_punto_venta, l.direccion_punto_venta,
                   l.orden_compra, l.plu, l.ean_item, l.sku_item, l.descripcion_item,
                   MAX(l.unidades_por_caja_hoja) AS unidades_por_caja_hoja,
                   SUM(l.unidades) AS unidades
            FROM consolidado_lineas l
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.cedi, l.punto_venta, l.ean_punto_venta, l.direccion_punto_venta,
                     l.orden_compra, l.plu, l.ean_item, l.sku_item, l.descripcion_item";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    require_once __DIR__ . '/../consolidados/model_maestro_exito.php';
    $mapa       = mapaMaestro($pdo);
    $mapaExito  = mapaMaestroExito($pdo);
    $cedisExito = cedisExito($pdo);

    // Varios puntos de venta a la vez: "CARULLA CEDRO BOLIVAR, 4847, exito bello" separado por
    // comas. Cada término se busca por separado y con que UNO coincida alcanza —es la misma
    // lógica de "cualquiera de estos", no "todos estos a la vez"—, porque lo normal es pegar una
    // lista de tiendas concretas a buscar, no describir una tienda con varias palabras sueltas.
    $terminos = array_filter(array_map(
        fn($t) => mb_strtolower(trim($t)),
        explode(',', $filtros['punto'] ?? '')
    ), fn($t) => $t !== '');

    // Los puntos de venta TILDADOS en la barra de selección: acá no es "que coincida el texto",
    // es "que sea exactamente uno de estos" (mismo criterio que 'cedis' en Consolidados). La
    // clave es cedi|punto_venta, tal cual la arma este mismo bucle un poco más abajo.
    $soloEstos = !empty($filtros['puntos']) && is_array($filtros['puntos'])
        ? array_flip($filtros['puntos'])
        : null;

    $porCedi = [];
    foreach ($stmt as $fila) {
        $fila = decorarConMaestro($fila, $mapa, $mapaExito, isset($cedisExito[$fila['cedi']]));

        $clave = $fila['cedi'] . '|' . $fila['punto_venta'];

        if ($soloEstos !== null && !isset($soloEstos[$clave])) {
            continue;
        }

        $datos = numeroYNombreDePunto($fila['punto_venta'], $fila['ean_punto_venta'], $fila['direccion_punto_venta']);

        if ($terminos) {
            $donde = mb_strtolower($datos['numero'] . ' ' . $datos['nombre'] . ' ' . $fila['punto_venta']);
            $coincide = false;
            foreach ($terminos as $termino) {
                if (mb_strpos($donde, $termino) !== false) {
                    $coincide = true;
                    break;
                }
            }
            if (!$coincide) {
                continue;
            }
        }

        if (!isset($porCedi[$fila['cedi']]['puntos'][$clave])) {
            $porCedi[$fila['cedi']]['puntos'][$clave] = [
                'numero'       => $datos['numero'],
                'nombre'       => $datos['nombre'],
                'punto_venta'  => $fila['punto_venta'],
                'orden_compra' => $fila['orden_compra'],
                'productos'    => 0, 'unidades' => 0, 'cajas' => 0, 'saldos' => 0,
                'sin_maestro'  => 0,
                // El detalle por producto: es lo que necesita el botón de rótulos para saber
                // qué producto va en cada caja de esta tienda.
                'filas'        => [],
            ];
        }

        $p = &$porCedi[$fila['cedi']]['puntos'][$clave];
        $p['productos']++;
        $p['unidades'] += (int) $fila['unidades'];
        $p['filas'][] = [
            'producto' => $fila['descripcion'],
            'sku'      => $fila['sku'],
            'ean'      => $fila['ean_item'],
            // La orden de compra va POR PRODUCTO y no por tienda: una tienda puede tener pedidos de
            // más de una orden, y cada caja lleva la de su propio pedido (2026-09-28).
            'oc'       => (string) $fila['orden_compra'],
            'unidades' => (int) $fila['unidades'],
            'cajas'    => $fila['sin_maestro'] ? 0 : (int) $fila['cajas'],
        ];

        if ($fila['sin_maestro']) {
            $p['sin_maestro']++;
        } else {
            $p['cajas']  += (int) $fila['cajas'];
            $p['saldos'] += (int) $fila['saldos'];
        }
        unset($p);
    }

    foreach ($porCedi as $cedi => &$datos) {
        foreach ($datos['puntos'] as &$punto) {
            // La lista de empaque de las cadenas divide los bultos en tres casillas —seco,
            // refrigerado y otros— y su TOTAL es la suma de las tres. Monterojo solo despacha
            // seco, así que hoy el total es igual a 'cajas'; se calcula igual como una suma y no
            // como un alias, para que el día que haya refrigerado u otra categoría sea sumar un
            // término acá y no revisar quién mostraba cuál de los dos campos.
            $punto['cajas_seco']        = $punto['cajas'];
            $punto['cajas_refrigerado'] = 0;
            $punto['cajas_otros']       = 0;

            // El TOTAL de la lista de empaque son las UNIDADES, no la suma de las columnas de
            // cajas (corregido con el usuario el 2026-09-12, mirando el papel de la cadena: 12
            // cajas contra un total de 235). Tiene sentido: sumar tres columnas de las cuales
            // dos están siempre vacías daría siempre el mismo número que la primera, y una
            // columna que repite a la de al lado no le dice nada a quien recibe.
            $punto['total'] = $punto['unidades'];
        }
        unset($punto);

        // Por número de tienda, y las que no tienen número al final: el número es por donde se
        // busca en la planilla, así que ordenar por otra cosa obligaría a recorrerla entera.
        // De MENOR a MAYOR por número, comparando como NÚMERO y no como texto: en orden
        // alfabético '4319' va antes que '071' —compara el '4' contra el '0'— y la planilla
        // queda en un orden que no sirve para buscar. Las tiendas sin número, al final.
        uasort($datos['puntos'], function ($a, $b) {
            if (($a['numero'] === '') !== ($b['numero'] === '')) {
                return $a['numero'] === '' ? 1 : -1;
            }
            return [(int) $a['numero'], $a['nombre']] <=> [(int) $b['numero'], $b['nombre']];
        });

        $datos['totales'] = [
            'puntos'      => count($datos['puntos']),
            'unidades'    => array_sum(array_column($datos['puntos'], 'unidades')),
            'cajas'       => array_sum(array_column($datos['puntos'], 'cajas')),
            'saldos'      => array_sum(array_column($datos['puntos'], 'saldos')),
            'total'       => array_sum(array_column($datos['puntos'], 'total')),
            'sin_maestro' => array_sum(array_column($datos['puntos'], 'sin_maestro')),
        ];
    }
    unset($datos);

    // El CEDI con menos tiendas primero, igual que en Consolidados: es el que se resuelve rápido.
    uasort($porCedi, fn($a, $b) => count($a['puntos']) <=> count($b['puntos']));

    return $porCedi;
}

// Los CEDI que tienen puntos de venta anexados, para el filtro de la pantalla.
function cedisConPuntosDeVenta($pdo) {
    return $pdo->query(
        "SELECT DISTINCT l.cedi FROM consolidado_lineas l
          WHERE l.despachado = 0
            AND " . condicionPedidoExito('l') . "
          ORDER BY l.cedi"
    )->fetchAll(PDO::FETCH_COLUMN);
}
