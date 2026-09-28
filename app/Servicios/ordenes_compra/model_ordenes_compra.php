<?php
// modules/ordenes_compra/model_ordenes_compra.php
// Las órdenes de compra del Éxito, una fila por orden: cajas, unidades, estibas, peso, volumen,
// valor y carro. Es la planilla con la que se arma el transporte hacia los CEDI.
//
// DE DÓNDE SALE CADA COLUMNA (verificado el 2026-09-14 contra la planilla que armaba el usuario a
// mano, "ordenes compra.xlsx", y el archivo del Éxito del que salió):
//
//   unidades  suma de las unidades de la orden. Coincidió exacto en 8 de 8 órdenes.
//   valor     suma de unidades × PRECIO BRUTO. Exacto en 8 de 8 (con el neto, solo en 5).
//   cajas     por línea, las cajas que salen físicamente: si un producto no llena una caja, la
//             incompleta CUENTA como caja. Es la misma cuenta que los rótulos, así que el número de
//             cajas de una orden es el número de etiquetas que se pegan. Decisión del usuario.
//   peso      cajas × 2 kg. Es la fórmula de la planilla del usuario (=B2*2).
//   mts3      suma de cajas × cubicaje de UNA caja de ese producto (tabla cubicajes, por SKU).
//   estibas   0. PENDIENTE: el usuario todavía no tiene la regla; quedó en decidirla.
//   carro     el vehículo de menor capacidad en el que entran el peso y el volumen de la ORDEN
//             (tabla vehiculos, solo secos). Por orden y no por viaje al CEDI: decisión del usuario.
//
// LO QUE NO SE INVENTA
// Un producto sin cubicaje cargado no suma volumen, y uno sin precio no suma valor. La orden se
// muestra igual, con un aviso que dice qué le falta. Decisión del usuario: preferible un número
// incompleto que se ve incompleto a uno completo que está mal. En la planilla vieja pasaba lo
// segundo: en las órdenes que mezclaban productos, el volumen no salía con ninguna de 16 fórmulas
// posibles, porque varios productos no estaban en el archivo de cubicajes.

require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/../consolidados/model_consolidados.php';          // mapaMaestro(), decorarConMaestro()
require_once __DIR__ . '/../consolidados/model_consolidados_import.php';   // leerPrimeraHoja(), numeroDesdeExcel()

/** Kilos por caja para la columna peso. Es la fórmula de la planilla del usuario. */
const ORDENES_KG_POR_CAJA = 2;

// =================================================================================================
// CUBICAJES
// =================================================================================================

/**
 * El cubicaje de una caja de cada producto, por SKU: ['36373' => 0.0288, ...].
 *
 * Por SKU y no por EAN: el 36353 (PX18) y el 36354 (PX24) comparten EAN, y buscar por EAN le daría
 * al primero el volumen de la caja del segundo. Ver la tabla cubicajes en crear_esquema_completo.
 */
function mapaCubicajes($pdo) {
    return array_map('floatval', $pdo->query("SELECT sku, cubicaje_m3 FROM cubicajes")->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Cuántos productos tienen cubicaje cargado y cuándo fue la última actualización. */
function resumenCubicajes($pdo) {
    return $pdo->query("SELECT COUNT(*) AS productos, MAX(fecha_actualizacion) AS actualizado FROM cubicajes")->fetch();
}

/**
 * Carga o actualiza los cubicajes desde el Excel ("cubicajes por cajas.xlsx": EAN, SKU,
 * DENOMINACIÓN, TIPO CAJA, CUBICAJE POR CAJA).
 *
 * Es UPSERT por SKU, igual que el maestro: se completa de a poco. Subir un archivo con cinco
 * productos agrega o corrige esos cinco y no borra los demás.
 *
 * Una fila sin SKU o con un cubicaje que no es un número mayor que cero se SALTA y se cuenta en el
 * resultado. No se guarda en cero: un cubicaje en cero sumaría volumen cero sin que nadie lo note,
 * que es exactamente lo que este módulo no tiene que hacer.
 *
 * DE PASO, COMPLETA EL MAESTRO (2026-09-15)
 * Este archivo ya trae SKU, EAN y DENOMINACIÓN —lo mismo que hace falta para que un producto deje
 * de aparecer "sin maestro" en Consolidados—, y antes esos datos se quedaban solo en la tabla
 * cubicajes: subir el archivo diez veces no le agregaba la descripción al maestro ni una sola vez.
 * Ahora, para cada fila, si el SKU YA EXISTE en el maestro y le falta la descripción o las
 * unidades por caja, se completan con la DENOMINACIÓN de acá —las unidades, si el nombre trae el
 * empaque al final (PX20, BX6x8...), igual que hace importarMaestroDesdeSap() con el export de SAP.
 * Nunca pisa un dato que ya estuviera cargado, y nunca CREA un producto nuevo: eso sigue siendo
 * trabajo de "Cargar desde SAP" o "Cargar planilla", donde si hace falta se puede armar el SKU
 * desde cero.
 */
function importarCubicajes($pdo, $rutaArchivo) {
    $filas = leerPrimeraHoja($rutaArchivo);
    if (count($filas) < 2) {
        return ['exito' => false, 'mensaje' => 'El archivo no tiene filas de datos.'];
    }

    $encabezado = array_shift($filas);

    $mapa = mapearColumnas($encabezado, [
        'sku'               => 'sku',
        'ean'               => 'ean',
        'denominacion'      => 'denominacion',
        'descripcion'       => 'denominacion',
        'desc.componente'   => 'denominacion',
        'desc componente'   => 'denominacion',
        'tipo caja'         => 'tipo_caja',
        'tipo de caja'      => 'tipo_caja',
        'cubicaje por caja' => 'cubicaje',
        'cubicaje'          => 'cubicaje',
    ]);

    // ------------------------------------------------------------------------------------------
    // CUÁL DE LAS COLUMNAS "CUBICAJE" ES LA DEL VOLUMEN (2026-09-21)
    //
    // Hay dos planillas en uso y los títulos no alcanzan para distinguirlas:
    //
    //   "cubicajes por cajas.xlsx"          CUBICAJE POR CAJA = 0,0576   <- son m³
    //   "EMBALAJE Y TIPO DE CAJAS.xlsx"     CUBICAJE POR CAJA = 8        <- son unidades por caja
    //                                       CUBICAJE          = 0,06048  <- acá están los m³
    //
    // O sea que "CUBICAJE POR CAJA" significa una cosa en un archivo y la otra en el otro, y la
    // segunda planilla además trae las dos. mapearColumnas() se queda con la primera que coincide,
    // así que leía las unidades por caja como si fueran metros cúbicos: de ahí salieron 221
    // cubicajes falsos y los camiones imposibles de la pantalla de órdenes.
    //
    // Se elige POR EL VALOR y no por el título: de las columnas que se llaman "cubicaje" algo, la
    // buena es aquella cuyos números parecen un volumen (menores que 1 m³ por caja). Es el mismo
    // criterio que usa el guardián de más abajo para rechazar un archivo entero.
    //
    // La otra columna no se tira: si trae unidades por caja sirve para completar el maestro.
    // ------------------------------------------------------------------------------------------
    $columnasCubicaje = [];
    foreach ($encabezado as $i => $titulo) {
        if (strpos(normalizarEncabezado($titulo), 'cubicaje') !== false) {
            $columnasCubicaje[] = $i;
        }
    }

    $mapa['unidades_por_caja'] = null;

    if (count($columnasCubicaje) > 1) {
        $mejor = null;
        $mejorChicos = -1;

        foreach ($columnasCubicaje as $i) {
            $chicos = 0;
            foreach ($filas as $fila) {
                $v = numeroDesdeExcel($fila[$i] ?? null, 7);
                if ($v !== null && $v > 0 && $v < 1) { $chicos++; }
            }
            if ($chicos > $mejorChicos) { $mejorChicos = $chicos; $mejor = $i; }
        }

        if ($mejor !== null) {
            $mapa['cubicaje'] = $mejor;
            // La otra columna de "cubicaje", si trae enteros, son las unidades por caja.
            foreach ($columnasCubicaje as $i) {
                if ($i !== $mejor) { $mapa['unidades_por_caja'] = $i; break; }
            }
        }
    }

    $faltan = array_diff(['sku', 'cubicaje'], array_keys($mapa));
    if ($faltan) {
        return [
            'exito'   => false,
            'mensaje' => 'Al archivo le faltan las columnas ' . implode(' y ', array_map('strtoupper', $faltan))
                       . '. Tiene que traer al menos "SKU" y "CUBICAJE POR CAJA".',
        ];
    }

    $valor = fn(array $fila, $campo) => isset($mapa[$campo]) ? ($fila[$mapa[$campo]] ?? null) : null;

    // ------------------------------------------------------------------------------------------
    // ¿LA COLUMNA DEL CUBICAJE TRAE METROS CÚBICOS? (2026-09-21)
    //
    // Pasó de verdad: se subió una planilla cuya columna "cubicaje por caja" traía las UNIDADES
    // POR CAJA (12, 24, 120…) en vez del volumen (0,0576). El archivo entró sin una queja, y a
    // partir de ahí cada orden mostró un volumen igual a sus unidades: una orden de 20 cajas decía
    // 292 m³ y la pantalla recomendaba "no entra en un vehículo" para tres cajas de pasabocas.
    //
    // Una caja de este catálogo mide entre 0,006 y 0,08 m³. Un metro cúbico POR CAJA ya sería una
    // caja del tamaño de una estiba entera, y el camión más grande de la flota lleva 90 m³: si la
    // mayoría de las filas dicen 1 o más, la columna no es volumen.
    //
    // Se RECHAZA en vez de avisar, al revés que otras comprobaciones: un cubicaje mal no se nota
    // mirando la pantalla —los números existen y se ven razonables— y de él salen los vehículos
    // que se piden. Mejor no cargar nada que cargar un volumen que nadie va a poder desmentir.
    // ------------------------------------------------------------------------------------------
    $conDato = 0;
    $grandes = 0;
    $ejemplo = null;

    foreach ($filas as $fila) {
        $c = numeroDesdeExcel($valor($fila, 'cubicaje'), 7);
        if ($c === null || $c <= 0) {
            continue;
        }
        $conDato++;
        if ($c >= 1) {
            $grandes++;
            if ($ejemplo === null) {
                $ejemplo = ['sku' => textoLimpio($valor($fila, 'sku'), 30), 'valor' => $c];
            }
        }
    }

    if ($conDato > 0 && $grandes > $conDato / 2) {
        return [
            'exito'   => false,
            'mensaje' => "La columna del cubicaje no trae metros cúbicos: {$grandes} de {$conDato} filas "
                       . 'dicen 1 m³ o más POR CAJA'
                       . ($ejemplo ? " (por ejemplo, el SKU {$ejemplo['sku']} dice {$ejemplo['valor']})" : '')
                       . '. Una caja de este catálogo mide entre 0,006 y 0,08 m³, y el camión más grande '
                       . 'lleva 90 m³. Suele pasar cuando la columna trae las UNIDADES POR CAJA en vez '
                       . 'del volumen. No se cargó nada: revisá el archivo y volvé a subirlo.',
        ];
    }

    $guardar = $pdo->prepare(
        "INSERT INTO cubicajes (sku, ean, denominacion, tipo_caja, cubicaje_m3)
         VALUES (:sku, :ean, :denominacion, :tipo, :cubicaje)
         ON DUPLICATE KEY UPDATE ean = VALUES(ean), denominacion = VALUES(denominacion),
                                 tipo_caja = VALUES(tipo_caja), cubicaje_m3 = VALUES(cubicaje_m3)"
    );

    // COALESCE: solo llena lo que esté en NULL. Un producto que ya tenía su descripción o su
    // empaque cargados —por SAP o por una planilla— no se toca, aunque este archivo traiga otra
    // forma de escribir el mismo nombre.
    $completarMaestro = $pdo->prepare(
        "UPDATE maestro_productos SET
             descripcion       = COALESCE(descripcion, :descripcion),
             presentacion      = COALESCE(presentacion, :presentacion),
             unidades_por_caja = COALESCE(unidades_por_caja, :uxc)
         WHERE sku = :sku"
    );

    $nuevos = 0; $actualizados = 0; $sinCambios = 0; $omitidos = []; $maestroCompletado = 0;

    $pdo->beginTransaction();
    try {
        foreach ($filas as $numero => $fila) {
            $sku      = textoLimpio($valor($fila, 'sku'), 30);
            $cubicaje = numeroDesdeExcel($valor($fila, 'cubicaje'), 7);

            if ($sku === null && $cubicaje === null) {
                continue;   // fila vacía del final del archivo
            }
            if ($sku === null || $cubicaje === null || $cubicaje <= 0) {
                $omitidos[] = 'fila ' . ($numero + 2) . ($sku !== null ? " (SKU {$sku})" : '');
                continue;
            }

            $guardar->execute([
                ':sku'          => $sku,
                ':ean'          => textoLimpio($valor($fila, 'ean'), 20),
                ':denominacion' => textoLimpio($valor($fila, 'denominacion'), 255),
                ':tipo'         => textoLimpio($valor($fila, 'tipo_caja'), 40),
                ':cubicaje'     => $cubicaje,
            ]);

            // MySQL devuelve 1 si insertó, 2 si actualizó y 0 si la fila ya estaba igual.
            match ($guardar->rowCount()) {
                1       => $nuevos++,
                2       => $actualizados++,
                default => $sinCambios++,
            };

            $denominacion = textoLimpio($valor($fila, 'denominacion'), 255);
            if ($denominacion !== null) {
                $empaque = empaqueDelNombre($denominacion);

                // Las unidades por caja salen del nombre ("...PX24") y, si el archivo trae además
                // una columna con ese número —la segunda "cubicaje" de EMBALAJE Y TIPO DE CAJAS—,
                // manda la columna: es un dato puesto a mano, no deducido de cómo se escribió el
                // nombre. Solo se acepta un entero razonable, por lo mismo que en el maestro.
                $uxc = $empaque['unidades_por_caja'] ?? null;
                if ($mapa['unidades_por_caja'] !== null) {
                    $delArchivo = numeroDesdeExcel($fila[$mapa['unidades_por_caja']] ?? null, 0);
                    if ($delArchivo !== null && $delArchivo >= 1 && $delArchivo <= 10000
                        && (float) $delArchivo === floor((float) $delArchivo)) {
                        $uxc = (int) $delArchivo;
                    }
                }

                $completarMaestro->execute([
                    ':descripcion'  => $denominacion,
                    ':presentacion' => $empaque['presentacion'] ?? null,
                    ':uxc'          => $uxc,
                    ':sku'          => $sku,
                ]);
                // rowCount() en un UPDATE con COALESCE solo cuenta si ALGÚN campo cambió de
                // verdad: un producto que ya tenía todo cargado da 0, aunque el SKU exista.
                if ($completarMaestro->rowCount() > 0) {
                    $maestroCompletado++;
                }
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error importando cubicajes: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudieron guardar los cubicajes en la base.'];
    }

    $partes = [];
    if ($nuevos)       { $partes[] = "{$nuevos} nuevo(s)"; }
    if ($actualizados) { $partes[] = "{$actualizados} actualizado(s)"; }
    if ($sinCambios)   { $partes[] = "{$sinCambios} sin cambios"; }

    $mensaje = 'Cubicajes cargados: ' . ($partes ? implode(', ', $partes) : 'ninguno') . '.';
    if ($omitidos) {
        $mensaje .= ' Se saltaron ' . count($omitidos) . ' fila(s) sin SKU o sin un cubicaje válido: '
                  . implode(', ', array_slice($omitidos, 0, 8)) . (count($omitidos) > 8 ? '…' : '') . '.';
    }
    if ($maestroCompletado) {
        $mensaje .= " Además, {$maestroCompletado} producto(s) del maestro que les faltaba la "
                  . 'descripción o las unidades por caja se completaron con este archivo.';
    }

    return ['exito' => true, 'mensaje' => $mensaje, 'nuevos' => $nuevos, 'actualizados' => $actualizados,
            'sin_cambios' => $sinCambios, 'omitidos' => count($omitidos), 'maestro_completado' => $maestroCompletado];
}

// =================================================================================================
// LOS VEHÍCULOS
// =================================================================================================

/**
 * La flota, de menor a mayor capacidad: primero por peso y, a igual peso, por volumen.
 *
 * Se ordena por PESO porque así queda una escala limpia (camioneta, luv, turbos, sencillo,
 * dobletroque, minimula, tractomula, doblepiso). Por volumen, la LUV (5 m³) quedaría antes que la
 * camioneta (6 m³) aunque carga más del doble de kilos.
 *
 * $soloActivos: los que se pueden elegir como carro. La pantalla de ajustes los muestra todos.
 */
function vehiculos($pdo, $soloActivos = true) {
    return $pdo->query(
        "SELECT id_vehiculo, nombre, peso_kg, estibas, m3, activo
         FROM vehiculos " . ($soloActivos ? "WHERE activo = 1 " : "") . "
         ORDER BY peso_kg, m3, nombre"
    )->fetchAll();
}

/**
 * El vehículo para una carga: el de MENOR capacidad en el que entran su peso Y su volumen.
 * Devuelve la fila del vehículo, o null si no entra en ninguno (una orden más grande que el
 * vehículo más grande tiene que partirse en varios viajes, y eso se avisa, no se decide).
 *
 * Las ESTIBAS no se comparan mientras sigan pendientes: todas las órdenes tienen 0 y compararlas
 * no cambiaría nada. Cuando haya regla, se suma la condición acá.
 */
function vehiculoParaCarga($pesoKg, $m3, array $flota) {
    foreach ($flota as $v) {
        if ($pesoKg <= (float) $v['peso_kg'] && $m3 <= (float) $v['m3']) {
            return $v;
        }
    }
    return null;
}

/**
 * El vehículo para llevar TODO lo que hay en pantalla de una sola vez.
 *
 * El carro de cada fila responde "¿en qué mando esta orden?", que es lo que se pide cuando se
 * despacha una sola. Pero cuando el camión sale con todo el pedido del día, lo que hace falta es
 * el vehículo que aguante la SUMA: 24 órdenes de tres cajas entran cada una en una camioneta, y
 * juntas no (pedido del usuario, 2026-09-21).
 *
 * Si no hay ninguno que aguante todo, devuelve el más grande de la flota y cuántos viajes harían
 * falta. Es una cota OPTIMISTA —reparte peso y volumen como si la carga fuera líquida, sin contar
 * que una caja no se parte— pero dice lo que importa: que con uno no alcanza y por cuánto.
 *
 * Devuelve ['vehiculo' => fila de la flota, 'viajes' => int] o null si no hay flota cargada.
 */
function vehiculoParaTodo($pesoKg, $m3, array $flota) {
    if (!$flota) {
        return null;
    }

    $entra = vehiculoParaCarga($pesoKg, $m3, $flota);
    if ($entra !== null) {
        return ['vehiculo' => $entra, 'viajes' => 1];
    }

    // El más grande es el último: la flota viene ordenada de menor a mayor capacidad.
    $mayor = end($flota);

    $porPeso    = (float) $mayor['peso_kg'] > 0 ? $pesoKg / (float) $mayor['peso_kg'] : 0;
    $porVolumen = (float) $mayor['m3']      > 0 ? $m3     / (float) $mayor['m3']      : 0;

    return ['vehiculo' => $mayor, 'viajes' => max(1, (int) ceil(max($porPeso, $porVolumen)))];
}

/**
 * Guarda las capacidades editadas desde la pantalla. $filas: id_vehiculo => [peso_kg, estibas, m3,
 * activo]. Solo actualiza vehículos que ya existen: no crea ni borra.
 *
 * Devuelve la cantidad de vehículos con algún dato inválido, que se dejan como estaban. Un número
 * negativo o vacío no se guarda en cero: un vehículo con 0 m³ no recibiría nunca ninguna carga y
 * nadie sabría por qué.
 */
function guardarVehiculos($pdo, array $filas) {
    $actualizar = $pdo->prepare(
        "UPDATE vehiculos SET peso_kg = ?, estibas = ?, m3 = ?, activo = ? WHERE id_vehiculo = ?"
    );

    $invalidos = 0;
    $pdo->beginTransaction();
    try {
        foreach ($filas as $id => $f) {
            $peso    = numeroDesdeExcel($f['peso_kg'] ?? null, 0);
            $estibas = numeroDesdeExcel($f['estibas'] ?? null, 0);
            $m3      = numeroDesdeExcel($f['m3'] ?? null, 2);
            $activo  = !empty($f['activo']) ? 1 : 0;

            // Un vehículo activo tiene que poder llevar algo; uno inactivo puede quedar en cero
            // (es el caso del motocarro, que la tabla trae con 0 m³).
            if ($peso === null || $estibas === null || $m3 === null || ($activo && ($peso <= 0 || $m3 <= 0))) {
                $invalidos++;
                continue;
            }

            $actualizar->execute([(int) $peso, (int) $estibas, $m3, $activo, (int) $id]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error guardando vehículos: ' . $e->getMessage());
        return false;
    }

    return $invalidos;
}

// =================================================================================================
// LAS ÓRDENES
// =================================================================================================

/**
 * Las órdenes de compra del Éxito PENDIENTES de despacho, con sus totales y el detalle por producto.
 *
 * "Del Éxito" es lo mismo que en Cajas por punto de venta: las líneas de un CEDI de cadena, que se
 * reconocen porque sus puntos de venta traen EAN. Los despachos directos a clientes propios no
 * tienen CEDI ni orden de compra de la cadena.
 *
 * Solo pendientes, como en Cajas por punto de venta: es la planilla para armar el transporte, y
 * una orden ya despachada ya viajó. Además, el mismo archivo subido dos veces deja la orden en dos
 * cargas, y sumar las despachadas con las pendientes la contaría doble.
 *
 * Cada fila es carga + orden y no solo la orden, por la misma razón: si una orden llegara a estar
 * pendiente en dos cargas, se ven dos filas —con su número de carga— en vez de un total inflado.
 *
 * $filtros: 'oc' (coincidencia parcial con el número de orden).
 *
 * EL CARRO SE ELIGE POR ORDEN, con el peso y el volumen de esa orden sola (decisión del usuario,
 * 2026-09-14). Ver vehiculoParaCarga().
 */
function ordenesDeCompraExito($pdo, array $filtros = []) {
    $stmt = $pdo->query(
        "SELECT l.id_carga, l.cedi, l.orden_compra, l.plu, l.ean_item, l.sku_item, l.descripcion_item,
                l.unidades, l.precio_bruto
         FROM consolidado_lineas l
         WHERE l.despachado = 0
           AND " . condicionPedidoExito('l') . "
         ORDER BY l.orden_compra, l.id_carga"
    );

    require_once __DIR__ . '/../consolidados/model_maestro_exito.php';
    $maestro   = mapaMaestro($pdo);
    $mapaExito  = mapaMaestroExito($pdo);
    $cubicajes = mapaCubicajes($pdo);
    // Órdenes de compra es SOLO del Éxito (la consulta ya filtra con condicionPedidoExito). Así que
    // acá el empaque Y el cubicaje se toman de la excepción de Éxito cuando existe: se pisa el
    // cubicaje base con el propio del Éxito antes de calcular los m³.
    foreach ($mapaExito as $skuExc => $exc) {
        if ($exc['cubicaje_m3'] !== null) { $cubicajes[$skuExc] = (float) $exc['cubicaje_m3']; }
    }
    $flota     = vehiculos($pdo);

    $ordenes = [];
    $sinCubicaje = [];           // sku/plu => descripción, para el aviso general

    foreach ($stmt as $fila) {
        $l = decorarConMaestro($fila, $maestro, $mapaExito, true);

        $clave = $l['id_carga'] . '|' . $l['orden_compra'];
        if (!isset($ordenes[$clave])) {
            $ordenes[$clave] = [
                'id_carga' => (int) $l['id_carga'],
                'orden'    => $l['orden_compra'],
                'cedi'     => $l['cedi'],
                'cajas' => 0, 'unidades' => 0, 'estibas' => 0, 'peso_kg' => 0, 'm3' => 0.0, 'valor' => 0.0,
                'sin_maestro' => 0, 'sin_cubicaje' => 0, 'sin_precio' => 0,
                'lineas' => [],
            ];
        }
        $o = &$ordenes[$clave];

        $sku      = $l['sku'] ?? $l['sku_item'];
        $unidades = (int) $l['unidades'];
        $producto = $l['descripcion'] ?? $l['descripcion_item'] ?? ('PLU ' . $l['plu']);

        // Las cajas que salen físicamente: las completas más una por las unidades sueltas.
        $cajas = $l['sin_maestro'] ? null : (int) $l['cajas'] + ((int) $l['saldos'] > 0 ? 1 : 0);

        $cubicaje = ($sku !== null && isset($cubicajes[$sku])) ? $cubicajes[$sku] : null;
        $m3       = ($cajas !== null && $cubicaje !== null) ? $cajas * $cubicaje : null;
        $precio   = $l['precio_bruto'] !== null ? (float) $l['precio_bruto'] : null;
        $valor    = $precio !== null ? $unidades * $precio : null;

        $o['unidades'] += $unidades;
        if ($cajas === null)  { $o['sin_maestro']++; }  else { $o['cajas'] += $cajas; }
        if ($valor === null)  { $o['sin_precio']++; }   else { $o['valor'] += $valor; }
        if ($m3 === null) {
            // Sin maestro tampoco hay cajas, y ese faltante ya se cuenta arriba: acá solo los que
            // tienen cajas pero no cubicaje, para que el aviso diga qué cargar y dónde.
            if ($cajas !== null) {
                $o['sin_cubicaje']++;
                $sinCubicaje[$sku ?? ('PLU ' . $l['plu'])] = $producto;
            }
        } else {
            $o['m3'] += $m3;
        }

        $o['lineas'][] = [
            'sku' => $sku, 'plu' => $l['plu'], 'producto' => $producto, 'unidades' => $unidades,
            'por_caja' => $l['unidades_por_caja'], 'cajas' => $cajas, 'cubicaje' => $cubicaje, 'm3' => $m3,
            'precio' => $precio, 'valor' => $valor,
        ];
        unset($o);
    }

    // Peso, carro y el detalle agrupado por producto (una orden trae el mismo producto para varias
    // tiendas: en el detalle se ve una vez, con la suma).
    foreach ($ordenes as &$o) {
        $o['peso_kg'] = $o['cajas'] * ORDENES_KG_POR_CAJA;
        $o['m3']      = round($o['m3'], 7);
        $o['valor']   = round($o['valor'], 2);

        // El carro con lo que se sabe de la orden. Si le faltan cubicajes o maestros, su peso y su
        // volumen reales son MAYORES que los calculados y el carro elegido podría quedar chico: se
        // marca, para que se revise antes de pedir el vehículo.
        $vehiculo = vehiculoParaCarga($o['peso_kg'], $o['m3'], $flota);
        $o['carro']            = $vehiculo['nombre'] ?? null;
        $o['carro_excede']     = $vehiculo === null && $flota;
        $o['carro_incompleto'] = ($o['sin_maestro'] + $o['sin_cubicaje']) > 0;

        $o['productos'] = agruparLineasPorProducto($o['lineas']);
        unset($o['lineas']);
    }
    unset($o);

    $oc = trim((string) ($filtros['oc'] ?? ''));
    if ($oc !== '') {
        $ordenes = array_filter($ordenes, fn($o) => stripos($o['orden'], $oc) !== false);
    }

    ksort($sinCubicaje, SORT_NATURAL);

    $totales = totalesDeOrdenes($ordenes);

    // El vehículo para llevarlo TODO junto, que es otra pregunta que la de cada fila. Se calcula
    // sobre lo que se está viendo: si hay un filtro puesto, es el carro de lo filtrado.
    $todo = vehiculoParaTodo($totales['peso_kg'], $totales['m3'], $flota);
    $totales['carro']        = $todo['vehiculo']['nombre'] ?? null;
    $totales['carro_viajes'] = $todo['viajes'] ?? 0;

    // La capacidad del vehículo y cuánto de ella se usa, para poder decir "464 de 2.500 kg" en vez
    // de solo el nombre: es lo que permite ver de un vistazo si sobra lugar para sumarle algo o si
    // va al límite. Los porcentajes son sobre UN vehículo, aunque hagan falta varios viajes.
    $totales['carro_capacidad'] = null;
    if ($todo !== null) {
        $v = $todo['vehiculo'];
        $totales['carro_capacidad'] = [
            'peso_kg'     => (float) $v['peso_kg'],
            'm3'          => (float) $v['m3'],
            'estibas'     => (int) $v['estibas'],
            'uso_peso'    => (float) $v['peso_kg'] > 0 ? $totales['peso_kg'] / (float) $v['peso_kg'] * 100 : null,
            'uso_volumen' => (float) $v['m3']      > 0 ? $totales['m3']      / (float) $v['m3']      * 100 : null,
        ];
    }
    // Igual que en cada orden: si faltan cubicajes o maestros, el peso y el volumen reales son
    // MAYORES que los calculados y el vehículo elegido podría quedar chico.
    $totales['carro_incompleto'] = $totales['sin_cubicaje'] > 0 || $totales['sin_maestro'] > 0;

    return [
        'ordenes'      => array_values($ordenes),
        'totales'      => $totales,
        'sin_cubicaje' => $sinCubicaje,
        'hay_flota'    => (bool) $flota,
    ];
}

/**
 * El detalle de una orden, una fila por producto con las líneas de todas sus tiendas sumadas.
 * Un faltante en cualquiera de las líneas deja la suma de esa columna en null: un total armado con
 * la mitad de los datos se leería como completo.
 */
function agruparLineasPorProducto(array $lineas) {
    $productos = [];
    foreach ($lineas as $l) {
        $k = $l['sku'] ?? ('plu:' . $l['plu']);
        if (!isset($productos[$k])) {
            $productos[$k] = $l;
            $productos[$k]['tiendas'] = 1;
            continue;
        }
        $p = &$productos[$k];
        $p['tiendas']++;
        $p['unidades'] += $l['unidades'];
        foreach (['cajas', 'm3', 'valor'] as $campo) {
            $p[$campo] = ($p[$campo] === null || $l[$campo] === null) ? null : $p[$campo] + $l[$campo];
        }
        unset($p);
    }

    uasort($productos, fn($a, $b) => strcmp((string) $a['producto'], (string) $b['producto']));
    return array_values($productos);
}

function totalesDeOrdenes(array $ordenes) {
    $t = ['ordenes' => 0, 'cajas' => 0, 'unidades' => 0, 'estibas' => 0, 'peso_kg' => 0, 'm3' => 0.0, 'valor' => 0.0,
          'sin_maestro' => 0, 'sin_cubicaje' => 0, 'sin_precio' => 0];
    foreach ($ordenes as $o) {
        $t['ordenes']++;
        foreach (['cajas', 'unidades', 'estibas', 'peso_kg', 'm3', 'valor', 'sin_maestro', 'sin_cubicaje', 'sin_precio'] as $c) {
            $t[$c] += $o[$c];
        }
    }
    $t['m3']    = round($t['m3'], 7);
    $t['valor'] = round($t['valor'], 2);
    return $t;
}

/** Los números de orden pendientes del Éxito, para el buscador. */
function ordenesDisponibles($pdo) {
    return $pdo->query(
        "SELECT DISTINCT l.orden_compra
         FROM consolidado_lineas l
         WHERE l.despachado = 0
           AND " . condicionPedidoExito('l') . "
         ORDER BY l.orden_compra"
    )->fetchAll(PDO::FETCH_COLUMN);
}
