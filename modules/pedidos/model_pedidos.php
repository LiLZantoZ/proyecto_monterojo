<?php
// modules/pedidos/model_pedidos.php
// PEDIDOS (2026-10-06): qué productos hay en bodega para despachar cada pedido.
//
// Es la hoja "C1" del Excel "PEDIDOS MONTEROJO" hecha módulo. Lo alimentan (2026-10-06: el pedido y
// la base vienen en el MISMO Excel, y se suben juntos con "Subir pedidos"):
//  · PEDIDOS MONTEROJO (la base): sus hojas BD Clientes (cliente → ciudad), CIUDADES (ciudad → sede
//    que la atiende: BOGOTA o COPACABANA), OBSERVACIONES (clientes que "SALE DESDE COPACABANA") e
//    INVENTARIO (columnas I:K, la lista de materiales de Monterojo).
//  · Los pedidos: lo que piden los clientes, renglón por renglón (las columnas A:R de C1). Del Excel
//    completo se toma la primera hoja que traiga esas columnas (C1); también sirve el archivo PEDIDOS solo.
//  · INVENTARIO BOGOTA e INVENTARIO COPA: la "Libre utilización" de cada material en cada sede.
//
// Con eso cada renglón del pedido sabe su CIUDAD, quién lo ATIENDE y si es Monterojo —las columnas
// S, T y U de C1, que en el Excel son BUSCARV—, y además cuánto hay en la sede y cuánto le alcanza.
//
// LO QUE ALCANZA (decisión de diseño): el inventario de una sede se reparte entre los pedidos en el
// orden en que se crearon (fecha y hora de creación). Así, si dos pedidos piden el mismo producto y
// no hay para los dos, el primero se lleva lo que hay y el segundo queda parcial o agotado: lo que se
// ve es lo que de verdad se puede despachar, no el mismo inventario contado dos veces.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados_import.php';   // leerXlsxRapido(), leerPrimeraHoja(), mapearColumnas(), textoLimpio(), codigoLimpio(), fechaDesdeExcel()

const PEDIDOS_POR_PAGINA = 50;

// Las sedes: [código => nombre para mostrar].
const PEDIDOS_SEDES = ['BOGOTA' => 'Bogotá', 'COPACABANA' => 'Copacabana'];

// El almacén de cada sede en los archivos de inventario de SAP (INVENTARIO BOGOTA trae el 0305 e
// INVENTARIO COPA el 0300). Sirve para reconocer el archivo solo y para no cargar uno en la sede que
// no es.
const PEDIDOS_ALMACENES = ['0305' => 'BOGOTA', '0300' => 'COPACABANA'];

// Los estados de cada renglón: [clave => [texto, clase de color]].
const PEDIDOS_ESTADOS = [
    'completo'      => ['Completo', 'estado-verde'],
    'parcial'       => ['Parcial', 'estado-ambar'],
    'agotado'       => ['Agotado', 'estado-rojo'],
    'sin_inventario' => ['Sin inventario cargado', 'estado-gris'],
    'sin_sede'      => ['Sin sede', 'estado-gris'],
];

/**
 * SOLO MONTEROJO (2026-10-06, pedido del usuario): los materiales de la lista de Monterojo (la hoja
 * INVENTARIO del Excel PEDIDOS MONTEROJO, columnas I:K), como [material => true]. Lo que no está en
 * esta lista (Bary, Café Tostao…) no se guarda: ni los renglones de los pedidos ni el inventario.
 */
function materialesMonterojoPedidos($pdo) {
    return array_fill_keys(array_map('strval', $pdo->query("SELECT material FROM pedidos_productos")->fetchAll(PDO::FETCH_COLUMN)), true);
}

// Sin la lista de Monterojo no se sabe qué tomar, y no se carga nada.
const PEDIDOS_SIN_LISTA = 'Todavía no está la lista de productos de Monterojo (la pestaña INVENTARIO del Excel PEDIDOS MONTEROJO): '
    . 'subí ese Excel completo con “Subir pedidos”. No se cargó nada.';

/** Una ciudad comparable: mayúsculas, sin tildes, un solo espacio ("Bogotá  D.C." → "BOGOTA D.C."). */
function ciudadPedidos($texto) {
    $t = mb_strtoupper(trim((string) $texto), 'UTF-8');
    $t = strtr($t, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    return preg_replace('/\s+/', ' ', $t);
}

/** Un código (cliente, material) como texto: los números de Excel llegan como 1003022087.0. */
function codigoPedidos($valor, $maximo = 30) {
    if (is_float($valor) && floor($valor) == $valor) {
        $valor = sprintf('%.0f', $valor);
    }
    return codigoLimpio($valor, $maximo);
}

/** Un número (cantidad, precio) del Excel, o null. */
function numeroPedidos($valor, $decimales = 3) {
    if ($valor === null || $valor === '') {
        return null;
    }
    if (is_int($valor) || is_float($valor)) {
        return round((float) $valor, $decimales);
    }
    $n = numeroDesdeExcel(trim((string) $valor), $decimales);
    return $n === null ? null : (float) $n;
}

/** La hora del Excel (la fracción del día, 0.4692 → "11:15:39") o el texto "11:15:39", o null. */
function horaPedidos($valor) {
    if ($valor === null || $valor === '') {
        return null;
    }
    if (is_numeric($valor)) {
        $f = (float) $valor;
        $f = $f - floor($f);   // por si viene fecha y hora juntas
        $s = (int) round($f * 86400) % 86400;
        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }
    return preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', trim((string) $valor), $m)
        ? sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0) : null;
}

/** Anota la última carga de un archivo: tipo 'base', 'pedidos', 'inventario_BOGOTA'… */
function registrarCargaPedidos($pdo, $tipo, $archivo, $detalle, $idUsuario) {
    $pdo->prepare(
        "INSERT INTO pedidos_cargas (tipo, archivo, detalle, id_usuario) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE archivo = VALUES(archivo), detalle = VALUES(detalle),
             id_usuario = VALUES(id_usuario), fecha = CURRENT_TIMESTAMP"
    )->execute([$tipo, mb_substr((string) $archivo, 0, 255), mb_substr((string) $detalle, 0, 255), $idUsuario]);
}

/** Las últimas cargas: [tipo => archivo, detalle, fecha, usuario]. */
function cargasPedidos($pdo) {
    $salida = [];
    foreach ($pdo->query("SELECT c.tipo, c.archivo, c.detalle, c.fecha, u.nombre_usuario AS usuario
                            FROM pedidos_cargas c LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario") as $r) {
        $salida[$r['tipo']] = $r;
    }
    return $salida;
}

/** Lo que se puede vaciar (2026-10-06): [clave => [nombre, tablas, condición, tipo de la carga]]. */
function partesVaciablesPedidos() {
    return [
        'pedidos'               => ['Los pedidos', ['pedidos_lineas'], null, 'pedidos'],
        'inventario_BOGOTA'     => ['El inventario de Bogotá', ['pedidos_inventario'], 'BOGOTA', 'inventario_BOGOTA'],
        'inventario_COPACABANA' => ['El inventario de Copacabana', ['pedidos_inventario'], 'COPACABANA', 'inventario_COPACABANA'],
        'base'                  => ['Los clientes y las ciudades', ['pedidos_clientes', 'pedidos_ciudades', 'pedidos_observaciones', 'pedidos_productos'], null, 'base'],
    ];
}

/**
 * VACIAR LA TABLA (2026-10-06): borra lo elegido ('pedidos', 'inventario_BOGOTA', 'inventario_COPACABANA',
 * 'base') y su tarjeta de carga, todo junto o nada. Devuelve ['exito', 'mensaje'].
 */
function vaciarPedidos($pdo, array $partes) {
    $todas  = partesVaciablesPedidos();
    $partes = array_values(array_intersect(array_keys($todas), $partes));
    if (!$partes) {
        return ['exito' => false, 'mensaje' => 'Elegí qué querés vaciar. No se borró nada.'];
    }
    $mil = fn($n) => number_format($n, 0, ',', '.');
    $borrado = [];
    $pdo->beginTransaction();
    try {
        foreach ($partes as $clave) {
            [$nombre, $tablas, $sede, $tipo] = $todas[$clave];
            $filas = 0;
            foreach ($tablas as $i => $tabla) {
                $st = $sede === null ? $pdo->prepare("DELETE FROM {$tabla}") : $pdo->prepare("DELETE FROM {$tabla} WHERE sede = ?");
                $st->execute($sede === null ? [] : [$sede]);
                if ($i === 0) {
                    $filas = $st->rowCount();   // los renglones, los materiales o los clientes
                }
            }
            $pdo->prepare("DELETE FROM pedidos_cargas WHERE tipo = ?")->execute([$tipo]);
            $unidad = ['pedidos' => 'renglón|renglones', 'base' => 'cliente|clientes'][$clave] ?? 'material|materiales';
            [$uno, $varios] = explode('|', $unidad);
            $borrado[] = mb_strtolower(mb_substr($nombre, 0, 1)) . mb_substr($nombre, 1) . ' (' . $mil($filas) . ' ' . ($filas === 1 ? $uno : $varios) . ')';
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error vaciando Pedidos: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error vaciando la tabla. No se borró nada.'];
    }
    $texto = count($borrado) > 1 ? implode(', ', array_slice($borrado, 0, -1)) . ' y ' . end($borrado) : $borrado[0];
    return ['exito' => true, 'mensaje' => 'Se vació: ' . $texto . '.'];
}

/**
 * Las filas de una hoja: [fila => [índice de columna => valor]]. Con el lector rápido (una hoja de
 * 137 MB como BD Clientes se lee en menos de un segundo); si no se puede, con PhpSpreadsheet.
 */
function hojaPedidos($rutaArchivo, $nombreHoja = null, ?array $columnas = null) {
    $filas = leerXlsxRapido($rutaArchivo, $columnas, 0, $nombreHoja);
    if ($filas !== null) {
        return $filas;
    }
    if ($nombreHoja !== null) {
        return null;   // un .xls viejo con varias hojas: no se lee así
    }
    $salida = [];
    foreach (leerPrimeraHoja($rutaArchivo) as $i => $fila) {
        $salida[$i + 1] = $fila;
    }
    return $salida;
}

/** La primera fila (de las primeras 10) que trae las columnas pedidas: [número de fila, mapa]. */
function encabezadoPedidos(array $filas, array $columnas, array $obligatorias) {
    $n = 0;
    foreach ($filas as $r => $fila) {
        $mapa = mapearColumnas($fila, $columnas);
        if (!array_diff($obligatorias, array_keys($mapa))) {
            return [$r, $mapa];
        }
        if (++$n >= 10) {
            break;
        }
    }
    return [null, []];
}

// =================================================================================================
// 1) LA BASE: el archivo "PEDIDOS MONTEROJO" (BD Clientes, CIUDADES, OBSERVACIONES e INVENTARIO)
// =================================================================================================

/**
 * Carga las hojas de la base. Cada subida REEMPLAZA lo que había (es la lista completa de clientes y
 * ciudades). BD Clientes y CIUDADES son obligatorias; OBSERVACIONES e INVENTARIO, si vienen.
 * Devuelve ['exito', 'mensaje'].
 */
function importarBasePedidos($pdo, $rutaArchivo, $idUsuario, $nombreArchivo = null) {
    @set_time_limit(300);

    // BD Clientes: Deudor, Nombre 1 y Población (la ciudad).
    $enc = leerXlsxRapido($rutaArchivo, null, 1, 'BD Clientes');
    if ($enc === null) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae la hoja "BD Clientes". Acá va el Excel "PEDIDOS MONTEROJO" (.xlsx), '
            . 'el que tiene las hojas BD Clientes y CIUDADES. No se cargó nada.'];
    }
    $mapa = mapearColumnas($enc[1] ?? [], ['deudor' => 'deudor', 'nombre 1' => 'nombre', 'poblacion' => 'poblacion']);
    if (!isset($mapa['deudor'], $mapa['poblacion'])) {
        return ['exito' => false, 'mensaje' => 'La hoja "BD Clientes" no trae las columnas "Deudor" y "Población" en la primera fila. No se cargó nada.'];
    }
    $clientes = [];
    foreach (hojaPedidos($rutaArchivo, 'BD Clientes', array_values($mapa)) ?? [] as $r => $f) {
        if ($r < 2) {
            continue;
        }
        $deudor = codigoPedidos($f[$mapa['deudor']] ?? null, 20);
        if ($deudor === null) {
            continue;
        }
        $clientes[$deudor] = [$deudor, textoLimpio($f[$mapa['nombre'] ?? -1] ?? null, 160), textoLimpio($f[$mapa['poblacion']] ?? null, 80)];
    }

    // CIUDADES: Ciudad → quién la atiende.
    $ciudades = [];
    $filas = hojaPedidos($rutaArchivo, 'CIUDADES');
    [$filaEnc, $mc] = $filas ? encabezadoPedidos($filas, ['ciudad' => 'ciudad', 'atendido' => 'sede', 'sede' => 'sede'], ['ciudad', 'sede']) : [null, []];
    if ($filaEnc === null) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae la hoja "CIUDADES" con las columnas "CIUDAD" y "ATENDIDO". No se cargó nada.'];
    }
    foreach ($filas as $r => $f) {
        if ($r <= $filaEnc) {
            continue;
        }
        $ciudad = ciudadPedidos($f[$mc['ciudad']] ?? '');
        $sede   = ciudadPedidos($f[$mc['sede']] ?? '');
        if ($ciudad !== '' && $sede !== '') {
            $ciudades[$ciudad] = [$ciudad, mb_substr($sede, 0, 20)];
        }
    }

    // OBSERVACIONES (opcional): Cliente → observación ("SALE DESDE COPACABANA").
    $observaciones = [];
    $filas = hojaPedidos($rutaArchivo, 'OBSERVACIONES');
    [$filaEnc, $mo] = $filas ? encabezadoPedidos($filas, ['cliente' => 'deudor', 'deudor' => 'deudor', 'observacion' => 'texto', 'observaciones' => 'texto'], ['deudor', 'texto']) : [null, []];
    if ($filaEnc !== null) {
        foreach ($filas as $r => $f) {
            $deudor = $r > $filaEnc ? codigoPedidos($f[$mo['deudor']] ?? null, 20) : null;
            $texto  = $deudor ? textoLimpio($f[$mo['texto']] ?? null, 255) : null;
            if ($texto !== null) {
                $observaciones[$deudor] = [$deudor, $texto];
            }
        }
    }

    // INVENTARIO (opcional), columnas I:K: Material, Texto breve, Centro ("Monterojo"). La hoja trae
    // otra columna "Material" en la A, así que se ubica por "Centro" y se toman las dos de su izquierda.
    $productos = [];
    $filas = hojaPedidos($rutaArchivo, 'INVENTARIO');
    if ($filas) {
        $cCentro = null;
        foreach ($filas[1] ?? [] as $i => $titulo) {
            if (normalizarEncabezado($titulo) === 'centro' && normalizarEncabezado($filas[1][$i - 2] ?? '') === 'material') {
                $cCentro = $i;
                break;
            }
        }
        if ($cCentro !== null) {
            foreach ($filas as $r => $f) {
                $material = $r > 1 ? codigoPedidos($f[$cCentro - 2] ?? null) : null;
                if ($material !== null) {
                    $productos[$material] = [$material, textoLimpio($f[$cCentro - 1] ?? null, 255), textoLimpio($f[$cCentro] ?? null, 60)];
                }
            }
        }
    }

    if (!$clientes || !$ciudades) {
        return ['exito' => false, 'mensaje' => 'Las hojas "BD Clientes" o "CIUDADES" están vacías. No se cargó nada.'];
    }

    $pdo->beginTransaction();
    try {
        foreach ([['pedidos_clientes', '(deudor, nombre, poblacion)', $clientes],
                  ['pedidos_ciudades', '(ciudad, sede)', $ciudades],
                  ['pedidos_observaciones', '(deudor, observacion)', $observaciones],
                  ['pedidos_productos', '(material, texto, centro)', $productos]] as [$tabla, $campos, $datos]) {
            // Las opcionales que no vinieron en el archivo se dejan como estaban.
            if (!$datos && in_array($tabla, ['pedidos_observaciones', 'pedidos_productos'], true)) {
                continue;
            }
            $pdo->exec("DELETE FROM {$tabla}");
            insertarEnTandasPedidos($pdo, $tabla, $campos, array_values($datos));
        }
        // Con la lista nueva, lo que ya estaba cargado y no es de Monterojo se quita.
        if ($productos) {
            foreach (['pedidos_lineas', 'pedidos_inventario'] as $tabla) {
                $pdo->exec("DELETE FROM {$tabla} WHERE material NOT IN (SELECT material FROM pedidos_productos)");
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error guardando la base de Pedidos: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error guardando el archivo. No se cambió nada.'];
    }

    $mil = fn($n) => number_format($n, 0, ',', '.');
    $detalle = $mil(count($clientes)) . ' clientes · ' . $mil(count($ciudades)) . ' ciudades';
    registrarCargaPedidos($pdo, 'base', $nombreArchivo, $detalle, $idUsuario);
    return ['exito' => true, 'mensaje' => 'Base cargada: ' . $mil(count($clientes)) . ' cliente(s) de BD Clientes y ' . $mil(count($ciudades))
        . ' ciudad(es) de CIUDADES'
        . ($observaciones ? ', ' . $mil(count($observaciones)) . ' observación(es)' : '')
        . ($productos ? ' y ' . $mil(count($productos)) . ' material(es) de Monterojo (hoja INVENTARIO)' : '') . '.'];
}

/** INSERT de muchas filas, por tandas de 500. */
function insertarEnTandasPedidos($pdo, $tabla, $campos, array $filas) {
    if (!$filas) {
        return;
    }
    $columnas = count($filas[0]);
    foreach (array_chunk($filas, 500) as $tanda) {
        $una = '(' . implode(',', array_fill(0, $columnas, '?')) . ')';
        $params = [];
        foreach ($tanda as $f) {
            array_push($params, ...array_values($f));
        }
        $pdo->prepare("INSERT INTO {$tabla} {$campos} VALUES " . implode(',', array_fill(0, count($tanda), $una)))->execute($params);
    }
}

// =================================================================================================
// 2) LOS PEDIDOS: el archivo "PEDIDOS" (las columnas A:R de la hoja C1)
// =================================================================================================

/** Encabezado del archivo de pedidos (normalizado) => campo. */
function columnasPedidos() {
    return [
        'nº de pedido' => 'numero_pedido', 'n° de pedido' => 'numero_pedido', 'no de pedido' => 'numero_pedido', 'numero de pedido' => 'numero_pedido',
        'fecha documento' => 'fecha_documento',
        'clase doc.ventas' => 'clase_doc', 'clase doc. ventas' => 'clase_doc',
        'documento comercial' => 'documento',
        'creado por' => 'creado_por',
        'solicitante' => 'solicitante',
        'nombre 1' => 'nombre',
        'moneda del documento' => 'moneda', 'moneda' => 'moneda',
        'material' => 'material',
        'denominacion' => 'denominacion',
        'cantidad de pedido' => 'cantidad',
        'precio neto' => 'precio_neto',
        'valor neto' => 'total', 'total' => 'total',
        'motivo de rechazo' => 'motivo_rechazo',
        'creado el' => 'creado_el',
        'hora' => 'hora', 'hora de creacion' => 'hora',
        'observaciones' => 'observaciones', 'oberservaciones' => 'observaciones', 'obervaciones' => 'observaciones',
        'condicion de pago' => 'condicion_pago', 'condiciòn de pago' => 'condicion_pago',
    ];
}

/** Los nombres de las hojas de un .xlsx, en orden; null si no es un .xlsx. */
function hojasXlsxPedidos($rutaArchivo) {
    $zip = new ZipArchive();
    if ($zip->open($rutaArchivo, ZipArchive::RDONLY) !== true) {
        return null;
    }
    $libro = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    if ($libro === false) {
        return null;
    }
    preg_match_all('/<(?:\w+:)?sheet\b[^>]*\bname="([^"]*)"/', $libro, $m);
    return array_map(fn($n) => html_entity_decode($n, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
}

/** ¿La hoja se llama así? Sin distinguir mayúsculas ni espacios de más ("MANDATO " = "mandato"). */
function mismaHojaPedidos($a, $b) {
    $n = fn($x) => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $x)), 'UTF-8');
    return $n($a) === $n($b);
}

/**
 * "Subir pedidos" (2026-10-06): el Excel PEDIDOS MONTEROJO completo —los pedidos en su hoja C1 y,
 * en las demás, BD Clientes, CIUDADES, OBSERVACIONES e INVENTARIO— o el archivo PEDIDOS solo.
 *
 *  · Los pedidos salen de la PRIMERA hoja que traiga sus columnas (en el Excel completo, C1).
 *  · Si el archivo trae BD Clientes y CIUDADES, se cargan también (reemplazan las de antes); si no, se
 *    usan las que ya estaban cargadas.
 *
 * REEMPLAZA los pedidos que había: el archivo es la lista de lo que hay que despachar ahora. Se saltan
 * los renglones sin documento o sin material (la fila de totales del export de SAP). Al final dice qué
 * inventario hace falta subir. Devuelve ['exito', 'mensaje'].
 */
function importarArchivoPedidos($pdo, $rutaArchivo, $idUsuario, $nombreArchivo = null) {
    @set_time_limit(300);
    $hojas = hojasXlsxPedidos($rutaArchivo);
    $requeridas = ['documento', 'solicitante', 'material', 'cantidad'];

    // La hoja de los pedidos: la primera que traiga sus columnas (BD Clientes se salta: es enorme y no es).
    $hojaPedidos = null; $filas = null;
    if ($hojas) {
        foreach ($hojas as $h) {
            if (mismaHojaPedidos($h, 'BD Clientes')) {
                continue;
            }
            $enc = leerXlsxRapido($rutaArchivo, null, 10, $h);
            if ($enc && encabezadoPedidos($enc, columnasPedidos(), $requeridas)[0] !== null) {
                $hojaPedidos = $h;
                break;
            }
        }
        if ($hojaPedidos !== null) {
            $filas = hojaPedidos($rutaArchivo, $hojaPedidos);
        }
    } else {
        $filas = hojaPedidos($rutaArchivo);   // un .xls: la primera hoja
    }
    if (!$filas) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae ninguna hoja con los pedidos: tiene que traer las columnas "Documento comercial", '
            . '"Solicitante", "Material" y "Cantidad de pedido" (en el Excel PEDIDOS MONTEROJO, la hoja C1). No se cargó nada.'];
    }
    [$filaEnc, $mapa] = encabezadoPedidos($filas, columnasPedidos(), $requeridas);
    if ($filaEnc === null) {
        return ['exito' => false, 'mensaje' => 'Este archivo no parece el de PEDIDOS: tiene que traer las columnas "Documento comercial", '
            . '"Solicitante", "Material" y "Cantidad de pedido". No se cargó nada.'];
    }
    $v = fn(array $f, $campo) => isset($mapa[$campo]) ? ($f[$mapa[$campo]] ?? null) : null;

    $lineas = [];
    foreach ($filas as $r => $f) {
        if ($r <= $filaEnc) {
            continue;
        }
        $documento = codigoPedidos($v($f, 'documento'));
        $material  = codigoPedidos($v($f, 'material'));
        if ($documento === null || $material === null) {
            continue;   // la fila de totales o una vacía
        }
        $lineas[] = [
            textoLimpio($v($f, 'numero_pedido'), 80),
            fechaDesdeExcel($v($f, 'fecha_documento')),
            textoLimpio($v($f, 'clase_doc'), 10),
            $documento,
            textoLimpio($v($f, 'creado_por'), 40),
            codigoPedidos($v($f, 'solicitante'), 20),
            textoLimpio($v($f, 'nombre'), 160),
            textoLimpio($v($f, 'moneda'), 5),
            $material,
            textoLimpio($v($f, 'denominacion'), 255),
            numeroPedidos($v($f, 'cantidad'), 3),
            numeroPedidos($v($f, 'precio_neto'), 2),
            numeroPedidos($v($f, 'total'), 2),
            textoLimpio($v($f, 'motivo_rechazo'), 80),
            fechaDesdeExcel($v($f, 'creado_el')),
            horaPedidos($v($f, 'hora')),
            textoLimpio($v($f, 'observaciones'), 255),
            textoLimpio($v($f, 'condicion_pago'), 80),
        ];
    }
    if (!$lineas) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae ningún renglón con documento y material. No se cargó nada.'];
    }

    // Los clientes y las ciudades, si el archivo los trae (el Excel completo).
    $base = null;
    if ($hojas && array_filter($hojas, fn($h) => mismaHojaPedidos($h, 'BD Clientes'))) {
        $base = importarBasePedidos($pdo, $rutaArchivo, $idUsuario, $nombreArchivo);
        if (!$base['exito']) {
            return $base;   // ya dice qué falta; no se cargaron los pedidos
        }
    }

    // SOLO MONTEROJO: los renglones de otras marcas no se guardan.
    $monterojo = materialesMonterojoPedidos($pdo);
    if (!$monterojo) {
        return ['exito' => false, 'mensaje' => PEDIDOS_SIN_LISTA];
    }
    $leidos = count($lineas);
    $lineas = array_values(array_filter($lineas, fn($l) => isset($monterojo[(string) $l[8]])));
    $fuera  = $leidos - count($lineas);
    if (!$lineas) {
        return ['exito' => false, 'mensaje' => 'Ninguno de los ' . number_format($leidos, 0, ',', '.') . ' renglones del archivo es un producto de Monterojo '
            . '(la pestaña INVENTARIO del Excel PEDIDOS MONTEROJO). No se cargó nada.'];
    }
    $documentos = array_fill_keys(array_column($lineas, 3), true);

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM pedidos_lineas");
        insertarEnTandasPedidos($pdo, 'pedidos_lineas',
            '(numero_pedido, fecha_documento, clase_doc, documento, creado_por, solicitante, nombre, moneda, material,
              denominacion, cantidad, precio_neto, total, motivo_rechazo, creado_el, hora, observaciones, condicion_pago)', $lineas);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error guardando los pedidos: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error guardando el archivo. No se cambió nada.'];
    }

    $mil = fn($n) => number_format($n, 0, ',', '.');
    $detalle = $mil(count($lineas)) . ' renglones · ' . $mil(count($documentos)) . ' pedidos';
    registrarCargaPedidos($pdo, 'pedidos', $nombreArchivo, $detalle, $idUsuario);
    $msg = 'Pedidos cargados: ' . $mil(count($lineas)) . ' renglón(es) de ' . $mil(count($documentos)) . ' pedido(s)'
         . ($hojaPedidos !== null && count($hojas) > 1 ? ' (hoja ' . trim($hojaPedidos) . ')' : '') . '. Reemplazan a los que había.'
         . ($fuera ? ' Se dejaron por fuera ' . $mil($fuera) . ' renglón(es) de productos que no son de Monterojo.' : '');
    if ($base) {
        $msg .= ' ' . preg_replace('/^Base cargada: /', 'Clientes y ciudades: ', $base['mensaje']);
    } elseif (!$pdo->query("SELECT COUNT(*) FROM pedidos_ciudades")->fetchColumn()) {
        $msg .= ' OJO: el archivo no trae las hojas BD Clientes y CIUDADES, y todavía no hay clientes ni ciudades cargados: '
              . 'subí el Excel PEDIDOS MONTEROJO completo para saber qué sede atiende cada pedido.';
    }
    $recomendacion = mensajeRecomendacionPedidos(recomendacionInventariosPedidos(lineasPedidosConDisponibilidad($pdo), cargasPedidos($pdo)));
    return ['exito' => true, 'mensaje' => $msg . ($recomendacion !== '' ? ' ' . $recomendacion : '')];
}

// =================================================================================================
// 3) EL INVENTARIO: los archivos "INVENTARIO BOGOTA" e "INVENTARIO COPA"
// =================================================================================================

/**
 * Carga el inventario de una sede ('BOGOTA', 'COPACABANA' o '' para reconocerla por el almacén del
 * archivo). REEMPLAZA el inventario que tenía esa sede. La "Libre utilización" de cada material se
 * suma entre sus lotes. Devuelve ['exito', 'mensaje'].
 */
function importarInventarioPedidos($pdo, $rutaArchivo, $sede, $idUsuario, $nombreArchivo = null) {
    $filas = hojaPedidos($rutaArchivo);
    if (!$filas) {
        return ['exito' => false, 'mensaje' => 'No se pudo leer el archivo, o está vacío.'];
    }
    [$filaEnc, $mapa] = encabezadoPedidos($filas, [
        'material' => 'material', 'texto breve de material' => 'texto', 'almacen' => 'almacen',
        'libre utilizacion' => 'libre', 'bloqueado' => 'bloqueado', 'en control calidad' => 'calidad',
    ], ['material', 'libre']);
    if ($filaEnc === null) {
        return ['exito' => false, 'mensaje' => 'Este archivo no parece un inventario: tiene que traer las columnas "Material" y '
            . '"Libre utilización". No se cargó nada.'];
    }

    $inventario = []; $almacenes = [];
    foreach ($filas as $r => $f) {
        if ($r <= $filaEnc) {
            continue;
        }
        $material = codigoPedidos($f[$mapa['material']] ?? null);
        if ($material === null) {
            continue;   // la fila de totales
        }
        if (isset($mapa['almacen']) && ($a = codigoPedidos($f[$mapa['almacen']] ?? null, 10)) !== null) {
            $almacenes[str_pad($a, 4, '0', STR_PAD_LEFT)] = true;
        }
        $i = $inventario[$material] ?? [$material, textoLimpio($f[$mapa['texto'] ?? -1] ?? null, 255), 0.0, 0.0, 0.0];
        $i[2] += (float) numeroPedidos($f[$mapa['libre']] ?? null);
        $i[3] += (float) numeroPedidos($f[$mapa['bloqueado'] ?? -1] ?? null);
        $i[4] += (float) numeroPedidos($f[$mapa['calidad'] ?? -1] ?? null);
        $inventario[$material] = $i;
    }
    if (!$inventario) {
        return ['exito' => false, 'mensaje' => 'El archivo no trae ningún material. No se cargó nada.'];
    }

    // SOLO MONTEROJO: los materiales de otras marcas no se guardan.
    $monterojo = materialesMonterojoPedidos($pdo);
    if (!$monterojo) {
        return ['exito' => false, 'mensaje' => PEDIDOS_SIN_LISTA];
    }
    $leidos     = count($inventario);
    $inventario = array_intersect_key($inventario, $monterojo);
    $fuera      = $leidos - count($inventario);
    if (!$inventario) {
        return ['exito' => false, 'mensaje' => 'Ninguno de los ' . number_format($leidos, 0, ',', '.') . ' materiales del archivo es un producto de Monterojo. No se cargó nada.'];
    }

    // La sede: la elegida, o la del almacén del archivo. Si las dos están y no coinciden, no se carga.
    $delArchivo = array_values(array_unique(array_filter(array_map(fn($a) => PEDIDOS_ALMACENES[$a] ?? null, array_keys($almacenes)))));
    $sede = strtoupper(trim((string) $sede));
    if ($sede === '') {
        if (count($delArchivo) !== 1) {
            return ['exito' => false, 'mensaje' => 'No se pudo reconocer de qué sede es el inventario. Elegí la sede (Bogotá o Copacabana) y volvé a subirlo.'];
        }
        $sede = $delArchivo[0];
    } elseif (!isset(PEDIDOS_SEDES[$sede])) {
        return ['exito' => false, 'mensaje' => 'Sede desconocida. Elegí Bogotá o Copacabana.'];
    } elseif ($delArchivo && !in_array($sede, $delArchivo, true)) {
        return ['exito' => false, 'mensaje' => 'Este inventario es de ' . PEDIDOS_SEDES[$delArchivo[0]] . ' (almacén '
            . implode(', ', array_keys($almacenes)) . '), no de ' . PEDIDOS_SEDES[$sede] . '. No se cargó nada.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM pedidos_inventario WHERE sede = ?")->execute([$sede]);
        insertarEnTandasPedidos($pdo, 'pedidos_inventario', '(sede, material, texto, libre, bloqueado, calidad)',
            array_map(fn($i) => array_merge([$sede], $i), array_values($inventario)));
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error guardando el inventario de Pedidos: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error guardando el archivo. No se cambió nada.'];
    }

    $mil = fn($n) => number_format($n, 0, ',', '.');
    $total = array_sum(array_column($inventario, 2));
    $detalle = $mil(count($inventario)) . ' productos · ' . $mil($total) . ' unidades libres';
    registrarCargaPedidos($pdo, 'inventario_' . $sede, $nombreArchivo, $detalle, $idUsuario);
    return ['exito' => true, 'mensaje' => 'Inventario de ' . PEDIDOS_SEDES[$sede] . ' cargado: ' . $mil(count($inventario))
        . ' producto(s), ' . $mil($total) . ' unidades de libre utilización. Reemplaza al que había de esa sede.'
        . ($fuera ? ' Se dejaron por fuera ' . $mil($fuera) . ' material(es) que no son de Monterojo.' : '')];
}

// =================================================================================================
// 4) LA TABLA: cada renglón del pedido con su ciudad, su sede, lo que hay y lo que le alcanza
// =================================================================================================

/**
 * TODOS los renglones con lo calculado: ciudad, sede ('sede' + 'sede_por' = ciudad|observacion),
 * monterojo, inventario de la sede, asignado y estado (ver PEDIDOS_ESTADOS). El reparto del
 * inventario se hace sobre todos —en el orden en que se crearon los pedidos— y recién después se
 * filtra para mostrar, así un filtro no cambia lo que le alcanza a cada uno.
 */
function lineasPedidosConDisponibilidad($pdo) {
    $clientes = [];
    foreach ($pdo->query("SELECT deudor, poblacion FROM pedidos_clientes") as $c) {
        $clientes[(string) $c['deudor']] = $c['poblacion'];
    }
    $ciudades = $pdo->query("SELECT ciudad, sede FROM pedidos_ciudades")->fetchAll(PDO::FETCH_KEY_PAIR);
    $observaciones = [];
    foreach ($pdo->query("SELECT deudor, observacion FROM pedidos_observaciones") as $o) {
        $observaciones[(string) $o['deudor']] = $o['observacion'];
    }
    $productos = [];
    foreach ($pdo->query("SELECT material, centro FROM pedidos_productos") as $p) {
        $productos[(string) $p['material']] = $p['centro'];
    }
    $inventario = []; $sedesCargadas = [];
    foreach ($pdo->query("SELECT sede, material, libre FROM pedidos_inventario") as $i) {
        $inventario[$i['sede']][(string) $i['material']] = (float) $i['libre'];
        $sedesCargadas[$i['sede']] = true;
    }

    // Solo los de Monterojo (ya se guardan solo esos; esto cubre lo que hubiera quedado de antes).
    $lineas = array_values(array_filter($pdo->query(
        "SELECT * FROM pedidos_lineas
          ORDER BY COALESCE(creado_el, fecha_documento), hora, documento, id_linea"
    )->fetchAll(PDO::FETCH_ASSOC), fn($l) => isset($productos[(string) $l['material']])));

    $quedan = $inventario;   // lo que va quedando en cada sede a medida que se reparte
    foreach ($lineas as &$l) {
        $deudor = (string) $l['solicitante'];
        $l['ciudad']     = $clientes[$deudor] ?? null;
        $l['sin_cliente'] = !isset($clientes[$deudor]);
        // La observación del cliente ("SALE DESDE COPACABANA") manda sobre la ciudad.
        $obs = $observaciones[$deudor] ?? null;
        $l['observacion_cliente'] = $obs;
        $sede = null; $por = null;
        if ($obs !== null && preg_match('/SALE\s+DESDE\s+(BOGOTA|BOGOTÁ|COPACABANA)/iu', $obs, $m)) {
            $sede = ciudadPedidos($m[1]);
            $por  = 'observacion';
        } elseif ($l['ciudad'] !== null && isset($ciudades[ciudadPedidos($l['ciudad'])])) {
            $sede = $ciudades[ciudadPedidos($l['ciudad'])];
            $por  = 'ciudad';
        }
        $l['sede']     = $sede;
        $l['sede_por'] = $por;
        $l['monterojo'] = $productos[(string) $l['material']] ?? null;

        $pedida = (float) $l['cantidad'];
        if ($sede === null) {
            $l['inventario_sede'] = null;
            $l['asignado'] = null;
            $l['estado'] = 'sin_sede';
        } elseif (!isset($sedesCargadas[$sede])) {
            $l['inventario_sede'] = null;
            $l['asignado'] = null;
            $l['estado'] = 'sin_inventario';
        } else {
            $hay = $inventario[$sede][(string) $l['material']] ?? 0.0;
            $queda = $quedan[$sede][(string) $l['material']] ?? 0.0;
            $asignado = max(0.0, min($pedida, $queda));
            $quedan[$sede][(string) $l['material']] = $queda - $asignado;
            $l['inventario_sede'] = $hay;
            $l['queda_antes'] = $queda;   // lo que quedaba cuando le tocó a este renglón
            $l['asignado'] = $asignado;
            $l['estado'] = $asignado >= $pedida && $pedida > 0 ? 'completo' : ($asignado > 0 ? 'parcial' : 'agotado');
        }
    }
    unset($l);
    return $lineas;
}

/** Cuántos renglones hay en cada estado. */
function resumenEstadosPedidos(array $lineas) {
    $r = ['total' => count($lineas)] + array_fill_keys(array_keys(PEDIDOS_ESTADOS), 0);
    foreach ($lineas as $l) {
        $r[$l['estado']]++;
    }
    return $r;
}

/**
 * ¿El renglón pasa los filtros? 'estado' ('' = todos; o uno de PEDIDOS_ESTADOS), 'sede'
 * (BOGOTA|COPACABANA|sin), 'buscar' (varios con coma).
 */
function cumpleFiltrosPedidos(array $l, array $filtros) {
    if ($filtros['estado'] !== '' && $l['estado'] !== $filtros['estado']) {
        return false;
    }
    if ($filtros['sede'] === 'sin' ? $l['sede'] !== null : ($filtros['sede'] !== '' && $l['sede'] !== $filtros['sede'])) {
        return false;
    }
    if ($filtros['buscar'] !== '') {
        $donde = mb_strtoupper(implode(' ', [$l['numero_pedido'], $l['documento'], $l['solicitante'], $l['nombre'], $l['material'],
                                            $l['denominacion'], $l['ciudad']]), 'UTF-8');
        foreach (array_filter(array_map('trim', explode(',', $filtros['buscar']))) as $t) {
            if (mb_strpos($donde, mb_strtoupper($t, 'UTF-8')) !== false) {
                return true;
            }
        }
        return false;
    }
    return true;
}

// =================================================================================================
// 5) QUÉ INVENTARIO HAY QUE SUBIR (2026-10-06)
//
// Según el cliente y su zona (ciudad → sede), los pedidos cargados necesitan el inventario de Bogotá,
// el de Copacabana o los dos. Para cada sede se dice cuántos renglones y pedidos lo necesitan y cómo
// está su inventario: falta subirlo, es de un día anterior a los pedidos (conviene actualizarlo) o
// está al día. Solo hay productos Monterojo (los de otras marcas no se guardan).
// =================================================================================================

/**
 * [sede => ['renglones', 'pedidos', 'estado' (falta|viejo|al_dia|no_hace_falta), 'fecha' (de su
 * inventario o null)]], en el orden de PEDIDOS_SEDES.
 */
function recomendacionInventariosPedidos(array $lineas, array $cargas) {
    $fechaPedidos = isset($cargas['pedidos']) ? date('Y-m-d', strtotime($cargas['pedidos']['fecha'])) : null;
    $salida = [];
    foreach (PEDIDOS_SEDES as $sede => $nombre) {
        $suyas = array_filter($lineas, fn($l) => $l['sede'] === $sede);
        $carga = $cargas['inventario_' . $sede] ?? null;
        $fecha = $carga ? $carga['fecha'] : null;
        if (!$suyas) {
            $estado = 'no_hace_falta';
        } elseif (!$carga) {
            $estado = 'falta';
        } elseif ($fechaPedidos !== null && date('Y-m-d', strtotime($fecha)) < $fechaPedidos) {
            $estado = 'viejo';
        } else {
            $estado = 'al_dia';
        }
        $salida[$sede] = [
            'renglones' => count($suyas),
            'pedidos'   => count(array_unique(array_column($suyas, 'documento'))),
            'estado'    => $estado,
            'fecha'     => $fecha,
        ];
    }
    return $salida;
}

/** La sede que conviene subir primero (la que falta, si no la vieja con más renglones), o null. */
function sedeRecomendadaPedidos(array $recomendacion) {
    foreach (['falta', 'viejo'] as $estado) {
        $candidatas = array_filter($recomendacion, fn($r) => $r['estado'] === $estado);
        if ($candidatas) {
            uasort($candidatas, fn($a, $b) => $b['renglones'] <=> $a['renglones']);
            return array_key_first($candidatas);
        }
    }
    return null;
}

/** La recomendación en una frase, para el mensaje de la carga ('' si no hace falta nada). */
function mensajeRecomendacionPedidos(array $recomendacion) {
    $mil = fn($n) => number_format($n, 0, ',', '.');
    $partes = [];
    foreach ($recomendacion as $sede => $r) {
        $nombre = PEDIDOS_SEDES[$sede];
        if ($r['estado'] === 'falta') {
            $partes[] = "subí el inventario de {$nombre} (lo necesitan " . $mil($r['renglones']) . ' renglón(es) de ' . $mil($r['pedidos']) . ' pedido(s))';
        } elseif ($r['estado'] === 'viejo') {
            $partes[] = "actualizá el inventario de {$nombre}, que es del " . date('d/m/Y', strtotime($r['fecha']))
                      . ' (lo necesitan ' . $mil($r['renglones']) . ' renglón(es))';
        }
    }
    return $partes ? 'Recomendado: ' . implode('; ', $partes) . '.' : '';
}

// =================================================================================================
// 6) POR PRODUCTO: las hojas MANDATO e INVENTARIO del Excel (2026-10-06)
//
// En el Excel, MANDATO es una tabla dinámica de C1 que suma la cantidad pedida y el total de cada
// material (con filtros por ATENDIDO y por cliente), e INVENTARIO las cruza con la Libre utilización:
//     PEDIDO     = BUSCARV(Material; MANDATO; 2)       → lo que se pidió de ese material
//     DIFERENCIA = Libre utilización − PEDIDO          → si es negativa, falta producto
// Acá es lo mismo, pero por SEDE (cada pedido contra el inventario de la bodega que lo atiende) y con
// los filtros de la pantalla (sede y búsqueda, p. ej. un cliente). Solo productos Monterojo con sede.
// =================================================================================================

/** Los estados de un producto: [clave => [texto, clase de color]]. */
const PEDIDOS_ESTADOS_PRODUCTO = [
    'falta'          => ['Falta', 'estado-rojo'],
    'alcanza'        => ['Alcanza', 'estado-verde'],
    'sin_inventario' => ['Sin inventario cargado', 'estado-gris'],
];

/**
 * Un renglón por sede y material: pedido (suma de lo pedido), libre (inventario de la sede), diferencia
 * (libre − pedido), valor, pedidos y clientes que lo piden, y estado. Primero los que faltan (de la
 * diferencia más negativa a la menos), después los que alcanzan.
 */
function productosPedidos($pdo, array $lineas) {
    $inventario = []; $sedesCargadas = [];
    foreach ($pdo->query("SELECT sede, material, texto, libre FROM pedidos_inventario") as $i) {
        $inventario[$i['sede']][(string) $i['material']] = $i;
        $sedesCargadas[$i['sede']] = true;
    }
    $grupos = [];
    foreach ($lineas as $l) {
        if ($l['sede'] === null) {
            continue;
        }
        $k = $l['sede'] . '|' . $l['material'];
        $g = $grupos[$k] ?? ['sede' => $l['sede'], 'material' => (string) $l['material'], 'texto' => $l['denominacion'],
                             'pedido' => 0.0, 'valor' => 0.0, 'documentos' => [], 'clientes' => []];
        $g['pedido'] += (float) $l['cantidad'];
        $g['valor']  += (float) $l['total'];
        $g['documentos'][(string) $l['documento']] = true;
        $g['clientes'][(string) $l['solicitante']] = true;
        $grupos[$k] = $g;
    }
    foreach ($grupos as &$g) {
        $i = $inventario[$g['sede']][$g['material']] ?? null;
        $g['libre']      = isset($sedesCargadas[$g['sede']]) ? ($i ? (float) $i['libre'] : 0.0) : null;
        $g['diferencia'] = $g['libre'] === null ? null : $g['libre'] - $g['pedido'];
        $g['estado']     = $g['libre'] === null ? 'sin_inventario' : ($g['diferencia'] >= 0 ? 'alcanza' : 'falta');
        $g['pedidos']    = count($g['documentos']);
        $g['n_clientes'] = count($g['clientes']);
        unset($g['documentos'], $g['clientes']);
    }
    unset($g);
    $orden = ['falta' => 0, 'sin_inventario' => 1, 'alcanza' => 2];
    usort($grupos, fn($a, $b) => [$orden[$a['estado']], $a['diferencia'] ?? 0, $a['sede'], $a['material']]
                             <=> [$orden[$b['estado']], $b['diferencia'] ?? 0, $b['sede'], $b['material']]);
    return $grupos;
}
