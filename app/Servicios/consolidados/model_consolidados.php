<?php
// modules/consolidados/model_consolidados.php
// Consultas del Consolidado. Lo usan la pantalla de Consolidados, el PDF por CEDI y Picking.

require_once __DIR__ . '/../compat.php';

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
// El producto de una línea del Consolidado se reconoce por TRES caminos, en este orden:
//
//   1. el SKU, cuando el archivo lo trae (el de Éxito sí, en una columna calculada; el export de
//      SAP lo trae como "Material"). Es el ÚNICO identificador que no admite dudas;
//   2. el EAN;
//   3. el PLU, que es el código que le puso la cadena.
//
// POR QUÉ EL SKU MANDA, Y POR QUÉ EL EAN Y EL PLU DEVUELVEN UNA LISTA
// Hay productos de Monterojo que comparten EAN y PLU y solo se distinguen por el SKU: el mismo
// item empacado de a 12 y de a 20 por caja lleva los mismos códigos pero es otro material y tiene
// otras unidades por caja (9 pares confirmados el 2026-09-11). Buscar por EAN puede devolver dos
// productos, y quedarse con "el que aparezca" significaría contar las cajas con el empaque
// equivocado — un error que no se ve en pantalla porque el número igual parece razonable.
// Por eso los índices de EAN y PLU guardan LISTAS, y una línea ambigua se queda sin producto:
// aparece como "falta en el maestro", que es visible y se corrige, en vez de salir mal en silencio.
//
// POR QUÉ ACÁ Y NO EN UN JOIN
// Un `LEFT JOIN ... ON m.ean = l.ean_item OR m.plu = l.plu` puede enganchar DOS filas distintas
// del maestro con la misma línea. Y como las consultas suman unidades, esa fila de más no da un
// dato raro en pantalla: da un total DUPLICADO, que es el peor error posible acá porque parece
// correcto. Resolviéndolo en PHP, cada línea tiene un producto o ninguno, nunca dos.
//
// El maestro son decenas o cientos de filas, así que cargarlo entero no cuesta nada.
// ---------------------------------------------------------------------------------------------
function mapaMaestro($pdo) {
    $mapa = ['sku' => [], 'ean' => [], 'plu' => []];

    $sql = "SELECT sku, ean, plu, descripcion, unidades_por_caja, presentacion, peso_unidad_kg, linea
            FROM maestro_productos";

    foreach ($pdo->query($sql) as $fila) {
        $mapa['sku'][$fila['sku']] = $fila;

        // Listas, no un solo producto: ver la explicación de arriba.
        if (!empty($fila['ean'])) { $mapa['ean'][$fila['ean']][] = $fila; }
        if (!empty($fila['plu'])) { $mapa['plu'][$fila['plu']][] = $fila; }
    }

    return $mapa;
}

// De una lista de candidatos, el único que hay; o el que coincide con la descripción de la línea,
// si esa descripción alcanza para desempatar. Null si sigue habiendo dudas.
function unicoProductoDe(array $candidatos, $descripcion) {
    if (count($candidatos) === 1) {
        return $candidatos[0];
    }

    $buscada = normalizarDescripcionProducto($descripcion);

    if ($buscada !== '') {
        $coinciden = array_values(array_filter($candidatos, function ($p) use ($buscada) {
            return normalizarDescripcionProducto($p['descripcion']) === $buscada;
        }));

        if (count($coinciden) === 1) {
            return $coinciden[0];
        }
    }

    // ------------------------------------------------------------------------------------------
    // VARIOS CANDIDATOS QUE DAN EL MISMO RESULTADO (2026-09-21)
    //
    // Hasta acá, dos productos con el mismo EAN o el mismo PLU dejaban la línea sin resolver. Eso
    // está bien cuando son empaques distintos —18 por caja contra 24 cambia las cajas del pedido—,
    // pero no cuando son el MISMO producto cargado dos veces con nombres distintos: SAP tiene
    // materiales que se renombraron o que existen por duplicado para una cadena, como 36339
    // "PAPAS CHICKEN TENDERS MR 100G PX12" y 36341 "PAPAS NUGGET POLLOMIEL MR ÉXIT 100G PX12",
    // que comparten EAN, PLU, unidades por caja y hasta el cubicaje. Ahí la línea quedaba en
    // "falta para el Consolidado" sin que hubiera nada que cargar: el dato ya estaba, dos veces.
    //
    // De un producto, las cuentas solo usan las unidades por caja y el peso por unidad (ver
    // decorarConMaestro). Si TODOS los candidatos coinciden en esos dos, da igual cuál se elija:
    // las cajas, los saldos y el peso salen idénticos. Lo único que cambia es el nombre que se
    // muestra, y para eso se toma el del SKU más chico —criterio fijo, para que la misma línea no
    // se vea de una forma hoy y de otra mañana—.
    //
    // Si difieren en algo que SÍ cambia las cuentas, se sigue devolviendo null: ahí elegir por
    // nosotros sería inventar cuántas cajas salen.
    // ------------------------------------------------------------------------------------------
    $medida = function ($p) {
        return [
            $p['unidades_por_caja'] === null ? null : (int) $p['unidades_por_caja'],
            $p['peso_unidad_kg']    === null ? null : (float) $p['peso_unidad_kg'],
        ];
    };

    $primera = $medida($candidatos[0]);
    foreach ($candidatos as $p) {
        if ($medida($p) !== $primera) {
            return null;
        }
    }

    usort($candidatos, fn($a, $b) => strcmp((string) $a['sku'], (string) $b['sku']));

    return $candidatos[0];
}

// Deja una descripción comparable: sin tildes, sin mayúsculas y sin espacios de más. Los archivos
// escriben el mismo producto como "MR100G PX18" o "MR 100G PX18" según quién lo exportó.
function normalizarDescripcionProducto($texto) {
    $texto = mb_strtolower(trim((string) $texto), 'UTF-8');
    $texto = strtr($texto, ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n', 'ü'=>'u']);
    return preg_replace('/\s+/', ' ', $texto);
}

/**
 * La fila del maestro para una línea del Consolidado, o null.
 *
 * $sku y $descripcion son opcionales: las líneas importadas antes del 2026-09-11 no los tienen
 * guardados, y sin ellos la búsqueda funciona igual que siempre —por EAN y por PLU— salvo que
 * ahora, si esos códigos apuntan a más de un producto, devuelve null en vez de elegir uno.
 */
function productoDelMaestro(array $mapa, $ean, $plu, $sku = null, $descripcion = null) {
    // 1. El SKU no admite dudas.
    if (!empty($sku) && isset($mapa['sku'][$sku])) {
        return $mapa['sku'][$sku];
    }

    // 2. El EAN, que es el código del producto y no el que le puso la cadena.
    if (!empty($ean) && isset($mapa['ean'][$ean])) {
        $producto = unicoProductoDe($mapa['ean'][$ean], $descripcion);
        if ($producto !== null) {
            return $producto;
        }
    }

    // 3. El PLU.
    if (!empty($plu) && isset($mapa['plu'][$plu])) {
        return unicoProductoDe($mapa['plu'][$plu], $descripcion);
    }

    return null;
}
// Junta una línea agrupada del Consolidado con su producto del maestro y le calcula el desglose.
// Lo usan por igual el Consolidado y Picking, para que las dos pantallas no puedan discrepar en
// cuántas cajas es lo mismo.
/**
 * La condición SQL que reconoce una línea de un pedido del ÉXITO, para el alias de consolidado_lineas
 * que se le pase. La usan Cajas por punto de venta y Órdenes de compra, que son solo del Éxito.
 *
 * Vive acá, en un solo lugar, porque estaba copiada en cuatro consultas y hubo que cambiarla el
 * mismo día en las cuatro (2026-09-14, al sumar Farmatodo).
 *
 *   · Si la línea sabe quién compró (empresa_compradora), manda eso: es del Éxito si el comprador
 *     dice "éxito". La comparación no distingue mayúsculas ni tildes (collation general_ci), así que
 *     "ALMACENES EXITO S.A" y "Almacenes Éxito" valen igual.
 *   · Si no lo sabe —todo lo importado antes de guardar el comprador—, se usa la regla de antes: un
 *     CEDI cuyas tiendas traen EAN. Así lo que ya estaba cargado se sigue viendo exactamente igual.
 */
/**
 * El nombre corto de la cadena que compró, a partir de la razón social del Consolidado.
 *
 * Monterojo despacha a varias cadenas y TODAS entregan igual: a una plataforma que después reparte
 * a sus tiendas. En pantalla eso hacía que "13 - CEDI CARIBE" (Éxito) y "Plataforma Cross Docking
 * Bogota" (Cencosud) quedaran mezclados en la misma lista alfabética de CEDI, sin nada que dijera
 * de quién es cada uno (reportado el 2026-09-18, con un archivo de Cencosud).
 *
 * Se busca por PEDAZO del nombre y no por igualdad: la misma cadena se escribe distinto entre
 * exportaciones ("ALMACENES EXITO S.A", "Almacenes Éxito S.A.S"). Sin tildes y sin mayúsculas, por
 * lo mismo. Una cadena que no esté en la lista no se pierde: se muestra su razón social recortada,
 * que es mejor que no decir nada.
 */
function cadenaDeLaEmpresa($empresa) {
    $texto = trim((string) $empresa);
    if ($texto === '') {
        return '';
    }

    $comparable = mb_strtolower($texto, 'UTF-8');
    $comparable = strtr($comparable, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);

    $conocidas = [
        'exito'     => 'Éxito',
        'cencosud'  => 'Cencosud',
        'farmatodo' => 'Farmatodo',
        'olimpica'  => 'Olímpica',
        'makro'     => 'Makro',
    ];

    foreach ($conocidas as $pedazo => $nombre) {
        if (mb_strpos($comparable, $pedazo) !== false) {
            return $nombre;
        }
    }

    return mb_substr($texto, 0, 24);
}

/**
 * [nombre del CEDI => cadena a la que pertenece], para poder mostrarlo al lado del CEDI.
 *
 * Un CEDI es de una sola cadena, así que MAX() sobre el grupo devuelve el único valor que hay. Se
 * arma como una consulta aparte y no sumando la columna a las consultas de cada pantalla porque
 * esas agrupan por producto o por pedido, y acá hace falta una fila por CEDI y nada más.
 */
function cadenasPorCedi($pdo) {
    $mapa = [];

    $sql = "SELECT cedi, MAX(empresa_compradora) AS empresa
            FROM consolidado_lineas
            WHERE empresa_compradora IS NOT NULL AND empresa_compradora <> ''
            GROUP BY cedi";

    foreach ($pdo->query($sql) as $fila) {
        $mapa[$fila['cedi']] = cadenaDeLaEmpresa($fila['empresa']);
    }

    return $mapa;
}

function condicionPedidoExito($alias = 'l') {
    return "({$alias}.empresa_compradora LIKE '%exito%'
             OR ({$alias}.empresa_compradora IS NULL
                 AND EXISTS (SELECT 1 FROM consolidado_lineas x
                             WHERE x.cedi = {$alias}.cedi
                               AND x.ean_punto_venta IS NOT NULL AND x.ean_punto_venta <> '')))";
}

/**
 * El conjunto de CEDI que son del Éxito, como [cedi => true]. Un CEDI es de una sola cadena, así que
 * el canal se decide por CEDI: las pantallas MIXTAS (Consolidado, Picking, Historial) miran acá para
 * saber a qué líneas aplicarles las excepciones de Éxito. Una sola consulta (DISTINCT cedi), no la
 * condición por fila.
 */
function cedisExito($pdo) {
    $set = [];
    foreach ($pdo->query("SELECT DISTINCT cedi FROM consolidado_lineas l WHERE " . condicionPedidoExito('l')) as $f) {
        $set[(string) $f['cedi']] = true;
    }
    return $set;
}

/**
 * Junta una línea con su producto del maestro y le calcula el desglose en cajas/saldos/peso.
 *
 * EXCEPCIONES DE ÉXITO (2026-09-23): un mismo SKU puede empacarse distinto para el Éxito. Si la línea
 * es de un pedido del Éxito ($esExito) y hay una excepción con "unidades por caja" propias para ese
 * SKU (en $mapaExito, ver model_maestro_exito.php), se usa ESE empaque en vez del base. Todo lo demás
 * usa el base. Sin $mapaExito (o sin excepción para el SKU) se comporta igual que siempre.
 *
 * $esExito: true/false si el que llama ya sabe (las pantallas solo-Éxito pasan true); null = deducir
 * de la empresa compradora de la propia línea, si la trae.
 */
function decorarConMaestro(array $linea, array $mapa, array $mapaExito = [], $esExito = null) {
    $producto = productoDelMaestro(
        $mapa,
        $linea['ean_item'] ?? null,
        $linea['plu'] ?? null,
        $linea['sku_item'] ?? null,
        $linea['descripcion_item'] ?? null
    );

    $sku             = $producto['sku'] ?? null;
    $unidadesPorCaja = $producto['unidades_por_caja'] ?? null;

    // ¿Es una línea del Éxito? Si no lo dijeron, se deduce de la empresa compradora de la línea.
    if ($esExito === null) {
        $emp = mb_strtolower((string) ($linea['empresa_compradora'] ?? ''), 'UTF-8');
        $emp = strtr($emp, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
        $esExito = ($emp !== '' && mb_strpos($emp, 'exito') !== false);
    }

    // El empaque propio del Éxito pisa al base solo si la línea es del Éxito y hay excepción de
    // "unidades por caja" para ese SKU. El cubicaje y el peso no se tocan acá (el cubicaje vive en
    // Órdenes de compra; el peso por unidad no depende del empaque).
    if ($esExito && $sku !== null && isset($mapaExito[$sku]) && $mapaExito[$sku]['unidades_por_caja'] !== null) {
        $unidadesPorCaja = (int) $mapaExito[$sku]['unidades_por_caja'];
    }

    $linea['sku']               = $sku;
    $linea['descripcion']       = $producto['descripcion'] ?? null;
    $linea['unidades_por_caja'] = $unidadesPorCaja;
    $linea['presentacion']      = $producto['presentacion'] ?? null;
    $linea['linea']             = $producto['linea'] ?? null;

    $linea += desglosarCajas($linea['unidades'], $unidadesPorCaja);

    // Peso total de lo pedido. Es null —y no cero— cuando el producto no tiene peso cargado, por
    // el mismo motivo que las cajas: cero significaría que no pesa nada.
    $pesoUnidad = $producto['peso_unidad_kg'] ?? null;
    $linea['peso_kg'] = ($pesoUnidad !== null && $pesoUnidad > 0)
        ? round((float) $pesoUnidad * (int) $linea['unidades'], 2)
        : null;

    return $linea;
}

// La última carga importada, sea cual sea su estado. Ya no es "el alcance de todo lo pendiente"
// —eso ahora lo dan cargasActivas()— sino solo un dato de referencia: qué fue lo último que se
// subió, para el aviso de "¿archivo repetido?" al importar y para mostrarlo en pantalla.
function cargaVigente($pdo) {
    $stmt = $pdo->query(
        "SELECT c.id_carga, c.nombre_archivo, c.filas, c.fecha_carga, u.nombre_usuario
         FROM consolidado_cargas c
         LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario
         ORDER BY c.id_carga DESC LIMIT 1"
    );
    return $stmt->fetch() ?: null;
}

/**
 * Todas las cargas que todavía tienen alguna línea pendiente (despachado = 0), de la más nueva a
 * la más vieja.
 *
 * Antes solo existía "la carga vigente" —la última— porque importar reemplazaba lo pendiente y
 * nunca podía haber más de una activa. Ahora los archivos se acumulan (ver importarConsolidado en
 * model_consolidados_import.php) y Picking/Consolidados muestran los pendientes de TODAS estas
 * juntos, diferenciados por su fecha. Esta es la lista que arma esa cabecera ("3 archivos
 * activos") y la que usan Picking/Consolidados para saber sobre qué cargas construir su consulta.
 */
function cargasActivas($pdo) {
    return $pdo->query(
        "SELECT c.id_carga, c.nombre_archivo, c.filas, c.fecha_carga, u.nombre_usuario
         FROM consolidado_cargas c
         LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario
         WHERE EXISTS (
             SELECT 1 FROM consolidado_lineas l WHERE l.id_carga = c.id_carga AND l.despachado = 0
         )
         ORDER BY c.id_carga DESC"
    )->fetchAll();
}

// Los CEDI que traen las cargas pendientes. Salen del propio archivo y no de una lista fija: hoy
// vienen tres, otro día pueden ser cuatro, y una lista escrita a mano dejaría el cuarto sin
// pantalla.
//
// Ya no se filtra por UNA carga (ver cargasActivas): un CEDI puede tener pendientes de varios
// archivos a la vez, y acá se suman todos.
//
// despachado = 0: un CEDI cuyas entregas ya salieron todas no debe seguir ofreciéndose en el
// filtro ni contando para "cuántos CEDI hay pendientes" — ya no queda nada pendiente ahí.
function cedisPendientes($pdo) {
    return $pdo->query(
        "SELECT cedi, COUNT(*) AS lineas, SUM(unidades) AS unidades
         FROM consolidado_lineas WHERE despachado = 0
         GROUP BY cedi ORDER BY cedi"
    )->fetchAll();
}

// Las líneas de producto que existen en el maestro, para el desplegable de filtro.
function lineasDelMaestro($pdo) {
    return $pdo->query(
        "SELECT DISTINCT linea FROM maestro_productos
         WHERE linea IS NOT NULL AND linea <> '' ORDER BY linea"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Agrega al WHERE el filtro por CEDI, sobre el alias l de consolidado_lineas.
 *
 *   · 'cedis' => [...]  varios, los tildados en la barra de selección de Consolidados;
 *   · 'cedi'  => '...'  uno solo, el del desplegable de filtros o el botón de un grupo.
 *
 * Si vienen los dos manda la lista: es lo que la persona eligió a mano. Un marcador por CEDI y no
 * los nombres pegados en el SQL: los nombres salen del archivo de la cadena y son texto libre.
 */
function filtroDeCedis(array $filtros, array &$where, array &$params) {
    if (!empty($filtros['cedis']) && is_array($filtros['cedis'])) {
        $marcas = [];
        foreach (array_values($filtros['cedis']) as $i => $cedi) {
            $marcas[] = ":cedi{$i}";
            $params[":cedi{$i}"] = (string) $cedi;
        }
        $where[] = 'l.cedi IN (' . implode(', ', $marcas) . ')';
    } elseif (!empty($filtros['cedi'])) {
        $where[] = 'l.cedi = :cedi';
        $params[':cedi'] = $filtros['cedi'];
    }
}

// ---------------------------------------------------------------------------------------------
// EL CONSOLIDADO PARA EL ELEVADOR
//
// Una fila por CEDI y PLU, con las unidades de TODOS los puntos de venta de ese CEDI sumadas —y,
// desde que los archivos se acumulan (ver importarConsolidado), de TODAS las cargas pendientes
// también: al elevador no le importa si dos cajas del mismo producto vinieron en archivos
// distintos, solo cuántas tiene que bajar en total para ese CEDI ahora mismo. El reparto por
// tienda (y por fecha) es problema de Picking.
//
// $filtros acepta 'cedi' (o 'cedis', varios), 'linea' y 'plu' (búsqueda por PLU, SKU o descripción).
// ---------------------------------------------------------------------------------------------
function consolidadoPorCedi($pdo, array $filtros = []) {
    // despachado = 0 SIEMPRE: una entrega despachada ya salió de bodega, y no tiene que seguir
    // apareciendo como pendiente ni acá ni en el PDF que se arma con esto mismo.
    $where  = ['l.despachado = 0'];
    $params = [];

    // Solo el CEDI se filtra en SQL: es una columna del propio Consolidado. La línea y la búsqueda
    // por descripción viven en el maestro, que se resuelve en PHP (ver mapaMaestro), así que se
    // aplican abajo sobre el resultado.
    filtroDeCedis($filtros, $where, $params);

    $sql = "SELECT l.cedi, l.plu, l.ean_item, l.sku_item, l.descripcion_item,
                   SUM(l.unidades) AS unidades,
                   COUNT(DISTINCT l.punto_venta) AS puntos_venta
            FROM consolidado_lineas l
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.cedi, l.plu, l.ean_item, l.sku_item, l.descripcion_item";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    require_once __DIR__ . '/model_maestro_exito.php';
    $mapa       = mapaMaestro($pdo);
    $mapaExito  = mapaMaestroExito($pdo);
    $cedisExito = cedisExito($pdo);   // a qué CEDI aplicar el empaque propio del Éxito
    $patron = isset($filtros['plu']) ? mb_strtolower(trim($filtros['plu'])) : '';

    // Se agrupa por CEDI acá y no en la vista: el PDF necesita exactamente la misma agrupación, y
    // con la lógica en la vista habría que repetirla —y mantenerla— en los dos lados.
    $porCedi = [];
    foreach ($stmt as $fila) {
        $fila = decorarConMaestro($fila, $mapa, $mapaExito, isset($cedisExito[$fila['cedi']]));

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

    // ORDEN DE LOS CEDI: el que tiene MENOS productos primero, hacia abajo de menor a mayor
    // (decidido con el usuario el 2026-09-08). Un CEDI con pocos productos es un consolidado
    // chico que se resuelve rápido, y verlo primero deja lo grande —lo que va a tomar más
    // tiempo— para cuando ya se calentó con lo fácil, en vez de tener que buscarlo entre CEDI
    // ordenados alfabéticamente sin ninguna relación con cuánto trabajo representan.
    uasort($porCedi, fn($a, $b) => count($a) <=> count($b));

    // ORDEN DENTRO DE CADA CEDI: primero los productos que van a MÁS puntos de venta.
    //
    // Es el orden de trabajo del elevador: el producto que se reparte entre más tiendas es el que
    // más veces hay que tocar, así que conviene tenerlo bajado y a mano desde el principio. Los
    // que van a una sola tienda pueden esperar al final.
    //
    // A igual cantidad de tiendas manda el que mueve más unidades, y recién ahí el orden
    // alfabético — que no aporta nada operativo, pero hace que dos cargas del mismo archivo
    // salgan siempre en el mismo orden en vez de depender de cómo las devolvió la base.
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

// ---------------------------------------------------------------------------------------------
// TODOS LOS PRODUCTOS EN UNA SOLA TABLA (2026-09-14)
//
// Lo que piden los CEDI elegidos, sumado por producto sin importar a cuál va: cuánto hay que tener
// en total de cada cosa. Sale de consolidadoPorCedi() y no de una consulta propia, así los filtros y
// la resolución del maestro son exactamente los mismos que en el consolidado interno.
//
// Se junta por el SKU del MAESTRO y no por PLU: el mismo producto llega con un PLU del Éxito y otro
// de Farmatodo, y por PLU saldría dos veces. Una línea que no está en el maestro no tiene SKU con
// qué juntarse y queda como su propia fila, con los códigos que trajo el archivo.
//
// Las cajas se recalculan sobre el TOTAL y no se suman las de cada CEDI, con el mismo criterio del
// interno, que suma las unidades de las tiendas antes de dividir: sumando por CEDI, dos saldos de 10
// en una paca de 20 darían 0 cajas y 20 saldos en vez de 1 caja.
//
// Devuelve ['filas' => [...], 'cedis' => [nombres]]. Las filas traen lo mismo que las del interno
// (así sirven con totalesDelGrupo), con 'plu' juntando los PLU distintos y 'cedis' contando destinos.
// ---------------------------------------------------------------------------------------------
function productosDelConsolidado($pdo, array $filtros = []) {
    $porCedi   = consolidadoPorCedi($pdo, $filtros);
    $productos = [];

    foreach ($porCedi as $cedi => $filas) {
        foreach ($filas as $f) {
            $clave = $f['sku'] !== null
                ? 'S:' . $f['sku']
                : 'L:' . implode('|', [$f['plu'], $f['ean_item'], $f['sku_item'], $f['descripcion_item']]);

            if (!isset($productos[$clave])) {
                $productos[$clave] = [
                    'plus'              => [],
                    'sku'               => $f['sku'],
                    'descripcion'       => $f['descripcion'],
                    'unidades_por_caja' => $f['unidades_por_caja'],
                    'unidades'          => 0,
                    'peso_kg'           => null,
                    'destinos'          => [],
                    'puntos_venta'      => 0,
                ];
            }

            $p = &$productos[$clave];
            if ($f['plu'] !== null && $f['plu'] !== '') { $p['plus'][$f['plu']] = true; }
            $p['unidades']       += (int) $f['unidades'];
            $p['puntos_venta']   += (int) $f['puntos_venta'];
            $p['destinos'][$cedi] = true;
            if ($f['peso_kg'] !== null) { $p['peso_kg'] = round((float) $p['peso_kg'] + (float) $f['peso_kg'], 2); }
            unset($p);
        }
    }

    $filas = [];
    foreach ($productos as $p) {
        $fila = [
            'plu'          => implode(' / ', array_keys($p['plus'])),
            'sku'          => $p['sku'],
            'descripcion'  => $p['descripcion'],
            'peso_kg'      => $p['peso_kg'],
            'cedis'        => count($p['destinos']),
            'puntos_venta' => $p['puntos_venta'],
        ];
        $filas[] = $fila + desglosarCajas($p['unidades'], $p['unidades_por_caja']);
    }

    // Primero lo que va a más CEDI, después lo que mueve más unidades: el mismo orden de trabajo que
    // el interno (lo que más se reparte, a mano desde el principio).
    usort($filas, function ($a, $b) {
        return [$b['cedis'], $b['unidades'], (string) $a['descripcion']]
           <=> [$a['cedis'], $a['unidades'], (string) $b['descripcion']];
    });

    return ['filas' => $filas, 'cedis' => array_keys($porCedi)];
}

// ---------------------------------------------------------------------------------------------
// EL CONSOLIDADO EXTERNO: CEDI -> PUNTO DE VENTA -> PRODUCTOS
//
// Es el mismo pedido que el consolidado interno, mirado desde el otro lado. El interno junta todo
// lo que va a un CEDI en un solo total por producto: es el papel del elevador, que baja de bodega
// una sola vez lo que después se reparte. El externo lo abre por tienda, porque es lo que se
// entrega —o se le muestra— a la cadena, y ahí a nadie le sirve saber que del CEDI salen 40 cajas
// si no dice cuántas son de cada local.
//
// Devuelve: [cedi => ['puntos' => [punto_venta => ['filas' => [...], 'totales' => [...]]],
//                     'totales' => [...]]]
//
// La estructura la arma este modelo y no la vista, por lo mismo que consolidadoPorCedi(): el PDF
// necesita exactamente la misma agrupación y los mismos subtotales, y con la lógica repetida en
// los dos lados terminarían discrepando el día que alguien toque uno solo.
// ---------------------------------------------------------------------------------------------
function consolidadoExternoPorCedi($pdo, array $filtros = []) {
    $where  = ['l.despachado = 0'];
    $params = [];

    filtroDeCedis($filtros, $where, $params);

    // El punto de venta entra al GROUP BY: acá cada fila es "este producto, para esta tienda", no
    // "este producto en todo el CEDI" como en el interno. Las unidades por tienda son el dato del
    // que cuelga todo lo demás.
    $sql = "SELECT l.cedi, l.punto_venta, l.ean_punto_venta, l.direccion_punto_venta,
                   l.orden_compra, l.plu, l.ean_item, l.sku_item, l.descripcion_item,
                   SUM(l.unidades) AS unidades
            FROM consolidado_lineas l
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.cedi, l.punto_venta, l.ean_punto_venta, l.direccion_punto_venta,
                     l.orden_compra, l.plu, l.ean_item, l.sku_item, l.descripcion_item";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    require_once __DIR__ . '/model_maestro_exito.php';
    $mapa       = mapaMaestro($pdo);
    $mapaExito  = mapaMaestroExito($pdo);
    $cedisExito = cedisExito($pdo);
    $patron = isset($filtros['plu']) ? mb_strtolower(trim($filtros['plu'])) : '';

    $porCedi = [];
    foreach ($stmt as $fila) {
        $fila = decorarConMaestro($fila, $mapa, $mapaExito, isset($cedisExito[$fila['cedi']]));

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

        $porCedi[$fila['cedi']]['puntos'][$fila['punto_venta']]['filas'][] = $fila;
    }

    foreach ($porCedi as $cedi => &$datos) {
        $todasLasFilas = [];

        foreach ($datos['puntos'] as $punto => &$pv) {
            // Dentro de una tienda, primero lo que más unidades lleva: es el orden en que conviene
            // armar la estiba, lo pesado abajo.
            usort($pv['filas'], function ($a, $b) {
                return [(int) $b['unidades'], (string) $a['descripcion']]
                   <=> [(int) $a['unidades'], (string) $b['descripcion']];
            });

            $pv['totales'] = totalesDelGrupo($pv['filas']);

            // La orden de compra y el EAN de la tienda son los mismos en todas sus líneas; se
            // suben al nivel del punto de venta para que el PDF no tenga que ir a buscarlos a la
            // primera fila.
            $pv['orden_compra']    = $pv['filas'][0]['orden_compra'] ?? null;
            $pv['ean_punto_venta'] = $pv['filas'][0]['ean_punto_venta'] ?? null;
            $pv['direccion']       = $pv['filas'][0]['direccion_punto_venta'] ?? null;

            $todasLasFilas = array_merge($todasLasFilas, $pv['filas']);
        }
        unset($pv);

        // Las tiendas, alfabéticas: acá no hay un orden de trabajo que respetar como en el
        // interno —el externo se lee buscando una tienda concreta— y alfabético es donde el ojo
        // la encuentra sin pensar.
        ksort($datos['puntos']);

        // El total del CEDI se calcula sobre TODAS las filas y no sumando los subtotales de cada
        // tienda: 'productos' tiene que contar productos distintos del CEDI, y sumando subtotales
        // un producto que va a ocho tiendas contaría ocho veces.
        $datos['totales'] = totalesDelGrupo($todasLasFilas);
        $datos['totales']['productos'] = count(array_unique(array_map(
            fn($f) => ($f['sku'] ?? '') . '|' . ($f['plu'] ?? ''),
            $todasLasFilas
        )));
        $datos['totales']['puntos_venta'] = count($datos['puntos']);
    }
    unset($datos);

    // Mismo criterio que el interno: el CEDI con menos trabajo primero. Acá "menos trabajo" son
    // menos tiendas que atender, que es lo que define el tamaño de este consolidado.
    uasort($porCedi, fn($a, $b) => count($a['puntos']) <=> count($b['puntos']));

    return $porCedi;
}
// Cuántos productos distintos de TODO lo pendiente no se pueden convertir a cajas: o no están en
// el maestro, o están pero sin unidades por caja. Es el número que decide si la pantalla muestra
// el aviso de "falta cargar el maestro".
//
// Se cuenta por PLU + EAN + SKU + descripción, que es exactamente lo que mira la resolución
// del maestro: contar por menos campos juntaría en una sola cuenta dos productos que comparten
// PLU y EAN pero son materiales distintos (ver productoDelMaestro).
function pluSinMaestro($pdo) {
    $stmt = $pdo->query(
        "SELECT DISTINCT plu, ean_item, sku_item, descripcion_item
          FROM consolidado_lineas WHERE despachado = 0"
    );

    $mapa = mapaMaestro($pdo);
    $faltan = 0;

    foreach ($stmt as $fila) {
        $producto = productoDelMaestro(
            $mapa, $fila['ean_item'], $fila['plu'], $fila['sku_item'], $fila['descripcion_item']
        );
        if ($producto === null || empty($producto['unidades_por_caja'])) {
            $faltan++;
        }
    }

    return $faltan;
}
