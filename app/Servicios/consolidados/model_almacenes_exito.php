<?php
// modules/consolidados/model_almacenes_exito.php
// La lista oficial de almacenes del Éxito (Dependencia → Nombre) y su aplicación a los pedidos.
//
// POR QUÉ HACE FALTA (2026-09-14)
// El Consolidado del Éxito escribe el punto de venta como quiere quien lo exporta, y en el mismo
// archivo conviven tres formas:
//
//     '320 - EXITO CANAVERAL FLORIDA BLANCA'   el número adelante
//     'TURBO CARULLA LIMONAR-4845'             el número al final
//     'Exito Acopi'                            sin número
//
// En la tercera el rótulo salía sin "N° punto de venta", y en las otras dos el nombre no era el
// oficial. El usuario pasó la lista del Éxito ("Dep exito.xlsx": Dependencia y Nombre Almacen, 567
// tiendas) con la regla:  Dependencia = número de punto de venta,  Nombre Almacen = punto de venta.
//
// CÓMO SE APLICA
// Cada punto de venta del Éxito se reescribe como "DEPENDENCIA - NOMBRE OFICIAL" (ej. "4845 - TURBO
// CARULLA LIMONAR"). Es la forma que ya entiende numeroYNombreDePunto(), así que Picking, el
// Historial, Cajas por punto de venta, los PDF y los rótulos toman el número y el nombre nuevos sin
// tocar cada pantalla.
//
// Se reconoce la tienda en este orden, y si nada coincide el texto queda COMO VINO (no se inventa):
//   1. el número adelante, si es una dependencia de la lista;
//   2. el número al final, si es una dependencia de la lista;
//   3. el nombre completo, igual a uno de la lista (sin distinguir mayúsculas, tildes ni signos).

require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/model_consolidados.php';   // condicionPedidoExito()

/** Deja un nombre comparable: mayúsculas, sin tildes, sin signos y con un solo espacio entre palabras. */
function normalizarNombreAlmacen($texto) {
    $texto = mb_strtoupper(trim((string) $texto), 'UTF-8');
    $texto = strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    $texto = preg_replace('/[^A-Z0-9]+/', ' ', $texto);
    return trim(preg_replace('/\s+/', ' ', $texto));
}

/** La lista en memoria, indexada por dependencia y por nombre normalizado. Son unos cientos de filas. */
function catalogoAlmacenesExito($pdo) {
    $catalogo = ['por_numero' => [], 'por_nombre' => []];
    foreach ($pdo->query("SELECT dependencia, nombre FROM almacenes_exito") as $fila) {
        $dependencia = (string) $fila['dependencia'];
        $catalogo['por_numero'][$dependencia] = $fila['nombre'];
        $catalogo['por_nombre'][normalizarNombreAlmacen($fila['nombre'])] = $dependencia;
    }
    return $catalogo;
}

/** Cuántas tiendas tiene la lista y cuándo se actualizó por última vez. */
function resumenAlmacenesExito($pdo) {
    return $pdo->query("SELECT COUNT(*) AS almacenes, MAX(fecha_actualizacion) AS actualizado FROM almacenes_exito")->fetch();
}

/**
 * La dependencia de un punto de venta según la lista, o null si no se reconoce.
 * Ver el orden de búsqueda en la cabecera de este archivo.
 */
function dependenciaDelPuntoDeVenta($puntoVenta, array $catalogo) {
    $texto = trim((string) $puntoVenta);
    if ($texto === '') {
        return null;
    }

    // (int) quita los ceros de adelante: el archivo escribe "071" y la lista, 71.
    if (preg_match('/^\s*([0-9]{1,6})\s*(?:[-–]\s*|\s+)(\p{L}.*)$/u', $texto, $m)
        && isset($catalogo['por_numero'][(string) (int) $m[1]])) {
        return (string) (int) $m[1];
    }

    // El separador antes del número, igual que arriba, puede ser un guion O un espacio —incluso
    // dos, como en "TURBO CARULLA COUNTRY  4847"—. Hasta el 2026-09-15 acá solo se aceptaba el
    // guion, y un punto de venta como "CARULLA CEDRO BOLIVAR 550" (nombre y número separados por
    // un simple espacio, sin guion) no lo reconocía: ni por este camino —fallaba el guion— ni por
    // el nombre completo de más abajo —"CARULLA CEDRO BOLIVAR 550" no es igual a "CARULLA CEDRO
    // BOLIVAR"—. La dependencia quedaba en la lista pero el pedido decía "no está en la lista".
    if (preg_match('/^\s*(\p{L}.*?)\s*(?:[-–]\s*|\s+)([0-9]{1,6})\s*$/u', $texto, $m)
        && isset($catalogo['por_numero'][(string) (int) $m[2]])) {
        return (string) (int) $m[2];
    }

    return $catalogo['por_nombre'][normalizarNombreAlmacen($texto)] ?? null;
}

/**
 * Reescribe con la lista los puntos de venta de los pedidos del Éxito.
 *
 * Con $idCarga, solo los de esa carga (lo usa la importación del Consolidado); sin él, todos, los
 * pendientes y los despachados (lo usa la subida de la lista, para que un nombre corregido se vea
 * también en el Historial). Los de otras cadenas —Farmatodo— no se tocan: condicionPedidoExito().
 *
 * Se actualiza por id_linea y no con un UPDATE ... WHERE condición: la condición del Éxito consulta
 * la misma tabla, y MySQL no deja actualizar una tabla leyéndola en la misma sentencia.
 *
 * Devuelve ['renombrados' => puntos de venta distintos que cambiaron,
 *           'sin_lista'   => [puntos de venta que no se encontraron en la lista]].
 */
function aplicarAlmacenesExito($pdo, $idCarga = null) {
    $catalogo = catalogoAlmacenesExito($pdo);
    $resultado = ['renombrados' => 0, 'sin_lista' => []];

    if (!$catalogo['por_numero']) {
        return $resultado;   // la lista todavía no se cargó: no hay contra qué comparar
    }

    $sql = "SELECT l.id_linea, l.punto_venta FROM consolidado_lineas l WHERE " . condicionPedidoExito('l');
    $params = [];
    if ($idCarga !== null) {
        $sql .= " AND l.id_carga = ?";
        $params[] = (int) $idCarga;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $idsPorPunto = [];
    foreach ($stmt as $fila) {
        $idsPorPunto[$fila['punto_venta']][] = (int) $fila['id_linea'];
    }

    $pdo->beginTransaction();
    try {
        foreach ($idsPorPunto as $puntoVenta => $ids) {
            $dependencia = dependenciaDelPuntoDeVenta($puntoVenta, $catalogo);

            if ($dependencia === null) {
                $resultado['sin_lista'][] = $puntoVenta;
                continue;
            }

            $nuevo = mb_substr($dependencia . ' - ' . $catalogo['por_numero'][$dependencia], 0, 180);
            if ($nuevo === $puntoVenta) {
                continue;   // ya estaba con el nombre oficial
            }

            foreach (array_chunk($ids, 500) as $tanda) {
                $marcas = implode(',', array_fill(0, count($tanda), '?'));
                $pdo->prepare("UPDATE consolidado_lineas SET punto_venta = ? WHERE id_linea IN ($marcas)")
                    ->execute(array_merge([$nuevo], $tanda));
            }
            $resultado['renombrados']++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error aplicando la lista de almacenes del Éxito: ' . $e->getMessage());
    }

    return $resultado;
}

/**
 * Carga o actualiza la lista desde el Excel del Éxito (columnas "Dependencia" y "Nombre Almacen";
 * también acepta "Número de punto de venta" y "Punto de venta").
 *
 * Es UPSERT por dependencia, igual que el maestro y los cubicajes: una tienda que no venga en el
 * archivo no se borra. Después se aplica a los pedidos ya cargados, así el cambio se ve enseguida.
 */
function importarAlmacenesExito($pdo, $rutaArchivo) {
    require_once __DIR__ . '/model_consolidados_import.php';   // leerPrimeraHoja(), mapearColumnas(), textoLimpio()

    $filas = leerPrimeraHoja($rutaArchivo);
    if (count($filas) < 2) {
        return ['exito' => false, 'mensaje' => 'El archivo no tiene filas de datos.'];
    }

    $mapa = mapearColumnas(array_shift($filas), [
        'dependencia'              => 'dependencia',
        'numero de punto de venta' => 'dependencia',
        'numero punto de venta'    => 'dependencia',
        'nombre almacen'           => 'nombre',
        'nombre de almacen'        => 'nombre',
        'punto de venta'           => 'nombre',
        'nombre punto de venta'    => 'nombre',
    ]);

    if (!isset($mapa['dependencia'], $mapa['nombre'])) {
        return ['exito' => false, 'mensaje' => 'El archivo tiene que traer las columnas "Dependencia" y "Nombre Almacen".'];
    }

    $guardar = $pdo->prepare(
        "INSERT INTO almacenes_exito (dependencia, nombre) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)"
    );

    $nuevos = 0; $actualizados = 0; $sinCambios = 0; $omitidas = 0;

    $pdo->beginTransaction();
    try {
        foreach ($filas as $fila) {
            $dependencia = textoLimpio($fila[$mapa['dependencia']] ?? null, 10);
            $nombre      = textoLimpio($fila[$mapa['nombre']] ?? null, 150);

            if ($dependencia === null && $nombre === null) {
                continue;   // fila vacía del final
            }
            // La dependencia es un número; "071" y 71 son la misma tienda.
            if ($dependencia === null || !ctype_digit($dependencia) || (int) $dependencia <= 0 || $nombre === null) {
                $omitidas++;
                continue;
            }

            $guardar->execute([(int) $dependencia, $nombre]);
            match ($guardar->rowCount()) {
                1       => $nuevos++,
                2       => $actualizados++,
                default => $sinCambios++,
            };
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error importando almacenes del Éxito: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar la lista de almacenes en la base.'];
    }

    $aplicado = aplicarAlmacenesExito($pdo);

    $partes = [];
    if ($nuevos)       { $partes[] = "{$nuevos} nuevo(s)"; }
    if ($actualizados) { $partes[] = "{$actualizados} con nombre corregido"; }
    if ($sinCambios)   { $partes[] = "{$sinCambios} sin cambios"; }

    $mensaje = 'Almacenes del Éxito: ' . ($partes ? implode(', ', $partes) : 'ninguno') . '.';
    if ($omitidas) {
        $mensaje .= " Se saltaron {$omitidas} fila(s) sin una dependencia numérica o sin nombre.";
    }
    if ($aplicado['renombrados']) {
        $mensaje .= " {$aplicado['renombrados']} punto(s) de venta de los pedidos cargados tomaron el nombre oficial.";
    }
    if ($aplicado['sin_lista']) {
        $mensaje .= ' ' . count($aplicado['sin_lista']) . ' no están en la lista y quedaron como venían: '
                  . implode(', ', array_slice($aplicado['sin_lista'], 0, 5)) . (count($aplicado['sin_lista']) > 5 ? '…' : '') . '.';
    }

    return ['exito' => true, 'mensaje' => $mensaje];
}
