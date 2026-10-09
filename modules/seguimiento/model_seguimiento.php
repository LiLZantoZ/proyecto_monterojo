<?php
// modules/seguimiento/model_seguimiento.php
// El estado de los pedidos en las transportadoras: una fila por guía/factura, con sus fechas y su
// estado, sin importar quién la mueva (AGV, Proeslog, Solística, entrega propia).
//
// POR QUÉ EXISTE (2026-09-22)
// Los estados de los despachos viven repartidos en el portal de cada transportadora, cada uno con
// su login y su formato. Para saber qué pasó con un pedido había que entrar a tres páginas
// distintas. Este módulo los junta en una sola pantalla.
//
// DE DÓNDE SALE EL DATO
// Cada fila guarda en `fuente` de dónde vino: 'manual' (se escribió a mano), 'excel' (se subió el
// listado que exporta la transportadora) y, el día que haya acceso oficial —una API o un usuario
// de solo lectura autorizado—, el nombre de esa integración. Se armó así a propósito: no se
// construye sobre las claves privadas de los portales de terceros, que pueden cambiar sin aviso y
// dejar el tablero muerto; se parte de lo que la bodega ya controla (el Excel que baja de cada
// portal) y cada fuente automática se enchufa después sin rehacer la pantalla.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../consolidados/model_consolidados_import.php';   // leerPrimeraHoja(), mapearColumnas(), textoLimpio(), fechaDesdeExcel()

/**
 * La clave que identifica una fila para no duplicarla al reimportar o sincronizar.
 *
 * Se arma con la transportadora, la factura y la guía, sin espacios ni signos y en mayúsculas, así
 * "Guía 001-A" y "guia001a" cuentan como la misma. Si no hay guía se usa solo factura, y al revés:
 * lo que haya, con tal de que dos filas del MISMO despacho caigan en la misma clave.
 */
function claveDePedido($transportadora, $factura, $guia) {
    $limpiar = fn($v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
    return $limpiar($transportadora) . '|' . $limpiar($factura) . '|' . $limpiar($guia);
}

// Cuántos pedidos por página. La tabla se pagina en el servidor: con miles de pedidos, mandar
// TODOS de una vez hacía una página de 17 MB que el navegador arrastraba (medido el 2026-09-22).
const SEGUIMIENTO_POR_PAGINA = 50;

/**
 * Arma el WHERE y sus parámetros a partir de los filtros. Devuelve [sqlWhere, params].
 *
 * En un solo lugar porque lo usan tres consultas —la página, el conteo total y los estados para el
 * semáforo—, y las tres tienen que filtrar EXACTAMENTE igual o los números no cerrarían.
 *
 * Filtros: 'transportadora' y 'estado' (exactos), 'buscar' (factura, guía, destino o detalle),
 * 'desde'/'hasta' (sobre la fecha de despacho o de guía), 'fuente'.
 */
function filtroDePedidos(array $filtros) {
    $where  = [];
    $params = [];

    if (!empty($filtros['transportadora'])) {
        $where[] = 'transportadora = :transportadora';
        $params[':transportadora'] = $filtros['transportadora'];
    }
    if (!empty($filtros['estado'])) {
        $where[] = 'estado = :estado';
        $params[':estado'] = $filtros['estado'];
    }
    // Filtro por GRUPO del semáforo (entregado / en_camino / pendiente / otro), el que se activa al
    // hacer clic en una pastilla. Como el grupo no es un estado fijo sino una clasificación por
    // palabras clave, se traduce a SQL la MISMA cascada de grupoDeEstado() con REGEXP. Los patrones
    // son constantes (no vienen del usuario), así que van inline; REGEXP es case/acento-insensible
    // bajo la colación utf8mb4_general_ci, igual que la comparación de PHP.
    if (!empty($filtros['grupo'])) {
        $cond = condicionGrupoEstado($filtros['grupo']);
        if ($cond !== '') {
            $where[] = $cond;
        }
    }
    if (!empty($filtros['fuente'])) {
        $where[] = 'fuente = :fuente';
        $params[':fuente'] = $filtros['fuente'];
    }
    // Pestaña de origen: separa los pedidos de facturación de SAP (fuente 'facturacion') de los
    // "normales" (los que bajan de las transportadoras o se cargan a mano). 'todos' no filtra.
    if (!empty($filtros['origen'])) {
        if ($filtros['origen'] === 'sap') {
            $where[] = "fuente = 'facturacion'";
        } elseif ($filtros['origen'] === 'transportadoras') {
            $where[] = "(fuente IS NULL OR fuente <> 'facturacion')";
        }
    }
    if (!empty($filtros['desde'])) {
        $where[] = '(fecha_despacho >= :desde OR fecha_guia >= :desde2)';
        $params[':desde']  = $filtros['desde'] . ' 00:00:00';
        $params[':desde2'] = $filtros['desde'];
    }
    if (!empty($filtros['hasta'])) {
        $where[] = '(fecha_despacho <= :hasta OR fecha_guia <= :hasta2)';
        $params[':hasta']  = $filtros['hasta'] . ' 23:59:59';
        $params[':hasta2'] = $filtros['hasta'];
    }
    if (!empty($filtros['buscar'])) {
        // Cada término separado por coma se busca por separado, y con que uno coincida alcanza:
        // es lo esperable cuando se pega una lista de facturas a buscar.
        $terminos = array_filter(array_map('trim', explode(',', $filtros['buscar'])), fn($t) => $t !== '');
        // Un placeholder DISTINTO por columna: con EMULATE_PREPARES en false (ver config.php) un
        // mismo :nombre no se puede repetir en la consulta, hay que nombrarlos todos aparte.
        $ors = [];
        $i = 0;
        foreach ($terminos as $t) {
            $campos = [];
            // Se busca en los datos del pedido de transportadora Y en los de facturación de SAP
            // (nit = Numero De Identificación, referencia, pedido), para que un valor visible en la
            // tabla —cualquiera de esas columnas— se encuentre desde el buscador.
            foreach (['numero_factura', 'numero_guia', 'destino', 'detalle',
                      'nit', 'referencia', 'pedido_cliente', 'direccion'] as $col) {
                $p = ":busca{$i}";
                $campos[] = "{$col} LIKE {$p}";
                $params[$p] = '%' . $t . '%';
                $i++;
            }
            $ors[] = '(' . implode(' OR ', $campos) . ')';
        }
        if ($ors) {
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

/**
 * Una PÁGINA de pedidos, con filtros. Los más recientes primero.
 *
 * $pagina arranca en 1. Sin fecha de despacho van al final: son los que todavía no salieron.
 */
function pedidosSeguidos($pdo, array $filtros = [], $pagina = 1) {
    [$whereSql, $params] = filtroDePedidos($filtros);

    $pagina  = max(1, (int) $pagina);
    $offset  = ($pagina - 1) * SEGUIMIENTO_POR_PAGINA;

    // LIMIT/OFFSET van pegados como enteros y no como parámetros: MariaDB no siempre acepta un
    // placeholder ahí, y estos dos ya son enteros saneados (no vienen del usuario sin filtrar).
    $sql = "SELECT * FROM seguimiento_pedidos{$whereSql}
            ORDER BY COALESCE(fecha_despacho, fecha_guia, fecha_creacion) DESC, id_pedido DESC
            LIMIT " . (int) SEGUIMIENTO_POR_PAGINA . " OFFSET " . (int) $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Cuántos pedidos cumplen el filtro (para la paginación). */
function contarPedidos($pdo, array $filtros = []) {
    [$whereSql, $params] = filtroDePedidos($filtros);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM seguimiento_pedidos{$whereSql}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Solo la columna 'estado' de TODO el conjunto filtrado, para contar el semáforo sobre el total y
 * no sobre la página que se ve. Es una consulta liviana: miles de textos cortos, no la fila entera.
 */
function estadosDelFiltro($pdo, array $filtros = []) {
    [$whereSql, $params] = filtroDePedidos($filtros);
    $stmt = $pdo->prepare("SELECT estado FROM seguimiento_pedidos{$whereSql}");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Las transportadoras que ya tienen algún pedido cargado, para el desplegable del filtro. */
function transportadorasDePedidos($pdo) {
    return $pdo->query(
        "SELECT DISTINCT transportadora FROM seguimiento_pedidos
          WHERE transportadora IS NOT NULL AND transportadora <> '' ORDER BY transportadora"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** Los estados distintos que hay cargados, para el desplegable del filtro. */
function estadosDePedidos($pdo) {
    return $pdo->query(
        "SELECT DISTINCT estado FROM seguimiento_pedidos
          WHERE estado IS NOT NULL AND estado <> '' ORDER BY estado"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * A qué "grupo" de estado pertenece un texto de estado, para el semáforo y el conteo.
 *
 * Cada transportadora escribe sus estados a su manera ("ENTREGADO", "Entrega exitosa", "En
 * reparto"...), así que no se puede pedir una lista fija. Se clasifica por palabras clave en tres
 * grupos: entregado, en camino y pendiente. Lo que no cae en ninguno queda como 'otro'.
 */
function grupoDeEstado($estado) {
    $t = mb_strtolower(trim((string) $estado));
    if ($t === '') {
        return 'pendiente';
    }
    // Los problemas van PRIMERO: "DEVOLUCIÓN TOTAL" contiene "entrega/entregada" pero no es una
    // entrega cumplida, así que si se mirara "entreg" primero se pintaría de verde.
    // "ENTREGA PARCIAL" SÍ cuenta como entrega (2026-10-06, pedido del usuario): antes estaba acá con
    // los problemas y salía en Pendientes; ahora cae en "entreg" y sale verde, en Entregados.
    if (preg_match('/devol|devuel|novedad|fallid|anul|rechaz|no despach|no entreg/', $t)) { return 'pendiente'; }
    if (preg_match('/entreg|exitos|cumplid|efectiv|recib|finaliz/', $t))                          { return 'entregado'; }
    if (preg_match('/tr[aá]nsit|camino|repart|despach|ruta|env[ií]|ofrecimiento|reintent|traslad|distribuc|sucursal|cargue|reexped/', $t)) { return 'en_camino'; }
    if (preg_match('/pendient|programad|alistad|alistam|por despach|nuevo|factur|cita/', $t))                    { return 'pendiente'; }
    return 'otro';
}

/**
 * La condición SQL que selecciona los pedidos de un GRUPO del semáforo, para filtrar la tabla al
 * hacer clic en una pastilla. Es el espejo en SQL de grupoDeEstado(): usa los MISMOS patrones y el
 * MISMO orden de la cascada (problemas primero, luego entregado, en camino y pendiente), para que
 * lo que cuenta la pastilla y lo que muestra la tabla filtrada coincidan exactamente.
 *
 * Devuelve un fragmento para el WHERE, o '' si el grupo no es válido.
 */
function condicionGrupoEstado($grupo) {
    // Estado normalizado (NULL cuenta como vacío). REGEXP ignora mayúsculas y acentos por la
    // colación de la columna, así que los patrones van en minúscula igual que en grupoDeEstado().
    $est = "COALESCE(estado, '')";
    $P = "{$est} REGEXP 'devol|devuel|novedad|fallid|anul|rechaz|no despach|no entreg'";   // sin 'parcial' (2026-10-06)
    $E = "{$est} REGEXP 'entreg|exitos|cumplid|efectiv|recib|finaliz'";
    $C = "{$est} REGEXP 'tr(a|á)nsit|camino|repart|despach|ruta|env(i|í)|ofrecimiento|reintent|traslad|distribuc|sucursal|cargue|reexped'";
    $D = "{$est} REGEXP 'pendient|programad|alistad|alistam|por despach|nuevo|factur|cita'";

    switch ($grupo) {
        case 'entregado':
            return "({$est} <> '' AND NOT ({$P}) AND ({$E}))";
        case 'en_camino':
            return "({$est} <> '' AND NOT ({$P}) AND NOT ({$E}) AND ({$C}))";
        case 'pendiente':
            // Vacío, o un problema, o —sin ser entrega ni ir en camino— una palabra de pendiente.
            return "({$est} = '' OR ({$P}) OR (NOT ({$E}) AND NOT ({$C}) AND ({$D})))";
        case 'otro':
            return "({$est} <> '' AND NOT ({$P}) AND NOT ({$E}) AND NOT ({$C}) AND NOT ({$D}))";
        default:
            return '';
    }
}

/**
 * Cuántos pedidos hay en cada grupo de estado. Recibe una lista de ESTADOS (strings), no de
 * pedidos: así se cuenta sobre todo el conjunto filtrado (estadosDelFiltro) y no solo sobre la
 * página que se muestra.
 */
function resumenDeEstados(array $estados) {
    $r = ['total' => 0, 'entregado' => 0, 'en_camino' => 0, 'pendiente' => 0, 'otro' => 0];
    foreach ($estados as $estado) {
        $r['total']++;
        $r[grupoDeEstado($estado)]++;
    }
    return $r;
}

/**
 * Crea o actualiza UN pedido a mano. Devuelve ['exito' => bool, 'mensaje' => string].
 *
 * La transportadora y al menos uno de factura/guía son obligatorios: sin eso la fila no identifica
 * ningún despacho. Si ya existe una fila con la misma clave, se actualiza (no se duplica).
 */
function guardarPedidoManual($pdo, array $datos, $idUsuario) {
    $transportadora = textoLimpio($datos['transportadora'] ?? null, 80);
    $factura        = textoLimpio($datos['numero_factura'] ?? null, 60);
    $guia           = textoLimpio($datos['numero_guia'] ?? null, 60);

    if ($transportadora === null) {
        return ['exito' => false, 'mensaje' => 'Falta la transportadora.'];
    }
    if ($factura === null && $guia === null) {
        return ['exito' => false, 'mensaje' => 'Tenés que poner al menos el número de factura o el de guía.'];
    }

    $fila = [
        'transportadora' => $transportadora,
        'numero_factura' => $factura,
        'numero_guia'    => $guia,
        'estado'         => textoLimpio($datos['estado'] ?? null, 80),
        'detalle'        => textoLimpio($datos['detalle'] ?? null, 500),
        'bodega'         => textoLimpio($datos['bodega'] ?? null, 80),
        'destino'        => textoLimpio($datos['destino'] ?? null, 160),
        'fecha_guia'     => fechaSoloDia($datos['fecha_guia'] ?? null),
        'fecha_despacho' => fechaYHora($datos['fecha_despacho'] ?? null),
        'fecha_entrega'  => fechaYHora($datos['fecha_entrega'] ?? null),
        'fuente'         => 'manual',
    ];

    guardarFilasDePedidos($pdo, [$fila], $idUsuario);
    return ['exito' => true, 'mensaje' => 'Pedido guardado.'];
}

/**
 * Importa un listado de pedidos desde el Excel que exporta una transportadora.
 *
 * Las columnas se buscan por nombre, con varios alias por campo, porque cada transportadora titula
 * distinto. Lo único imprescindible es la factura o la guía. La transportadora se toma si se puede
 * (formulario, columna o formato reconocido); si no, queda vacía (2026-10-05, pedido del usuario).
 * Se AGREGA/ACTUALIZA por clave: subir el mismo archivo dos veces no duplica.
 *
 * Devuelve ['exito' => bool, 'mensaje' => string, 'nuevos' => int, 'actualizados' => int].
 */
function importarPedidosExcel($pdo, $rutaArchivo, $idUsuario, $transportadoraPorDefecto = null) {
    // Antes de cargar la hoja entera se mira SOLO el encabezado. El export de facturación de SAP
    // (el "Consolidado completo") trae decenas de miles de filas —cargarlo entero con leerPrimeraHoja
    // reventaría la memoria—, y además no es un reporte de transportadora sino la lista de facturas.
    // Si se reconoce, se desvía al importador dedicado, que lee liviano y cruza por número de factura.
    $encabezadoRapido = encabezadoDeExcel($rutaArchivo);
    if ($encabezadoRapido && esFacturacionSap($encabezadoRapido)) {
        return importarFacturacionSap($pdo, $rutaArchivo, $idUsuario, $encabezadoRapido);
    }

    // El lector rápido (2026-10-05); si no puede (un .xls, una fórmula sin valor guardado), PhpSpreadsheet.
    $rapido = leerXlsxRapido($rutaArchivo);
    $filas  = $rapido !== null ? array_values($rapido) : leerPrimeraHoja($rutaArchivo);
    unset($rapido);
    if (count($filas) < 2) {
        return ['exito' => false, 'mensaje' => 'El archivo no tiene filas de datos.'];
    }

    // Los nombres se comparan normalizados (sin tildes, minúsculas, un solo espacio) PERO el guion
    // bajo NO es un espacio, así que un encabezado "factura_real" queda "factura_real": por eso
    // conviven las variantes con espacio y con guion bajo, para leer tanto el archivo "prolijo"
    // como los export crudos de las transportadoras (Detalle_Facturas, exportable_5902…).
    $columnas = [
        // transportadora
        'transportadora'    => 'transportadora',
        'transportador'     => 'transportadora',
        'operador'          => 'transportadora',
        // factura
        'factura'           => 'numero_factura',
        'factura_real'      => 'numero_factura',
        'numero factura'    => 'numero_factura',
        'nro factura'       => 'numero_factura',
        'no factura'        => 'numero_factura',
        'documento'         => 'numero_factura',
        // "Producción diario de envíos" (2026-10-05): la factura NU viene en "DOCUMENTO NRO 1".
        'documento nro 1'   => 'numero_factura',
        'documento nro'     => 'numero_factura',
        // AGV (2026-10-08): una misma remesa puede llevar hasta cuatro facturas (DOCUMENTO NRO 2 a 4).
        'documento nro 2'   => 'numero_factura_2',
        'documento nro 3'   => 'numero_factura_3',
        'documento nro 4'   => 'numero_factura_4',
        // guía
        'guia'              => 'numero_guia',
        'numero guia'       => 'numero_guia',
        'numero_guia'       => 'numero_guia',
        'nro guia'          => 'numero_guia',
        'no guia'           => 'numero_guia',
        'guiatransporte'    => 'numero_guia',
        'guia_transporte'   => 'numero_guia',
        'guia_cliente'      => 'numero_guia',
        'guiacliente'       => 'numero_guia',
        'remesa'            => 'numero_guia',
        // estado
        'estado'            => 'estado',
        'estado guia'       => 'estado',
        'estado_guia'       => 'estado',
        // Proeslog (exportable_5902): el NOMBRE del estado; 'codigoestadoguia' es solo un número.
        'nombreestadoguia'  => 'estado',
        // Producción diario de envíos: "ESTADO MERCANCIA" (ENTREGADO, ENTREGA PARCIAL…) se entiende
        // mejor; "ESTADO TRANSPORTE" (CUMPLIDO…) queda de respaldo para cuando viene vacío.
        'estado mercancia'  => 'estado',
        'estado transporte' => 'estado_transporte',
        'novedad'           => 'estado',
        // detalle
        'detalle'           => 'detalle',
        'descripcion'       => 'detalle',
        'observacion'       => 'detalle',
        'observaciones'     => 'detalle',
        'dice_contener'     => 'detalle',
        'causal'            => 'detalle',
        // bodega / origen
        'bodega'            => 'bodega',
        'origen'            => 'bodega',
        'ciudad_origen'     => 'bodega',
        'ciudad origen'     => 'bodega',
        // destino / cliente que recibe
        'destino'           => 'destino',
        'nombre_pdv'        => 'destino',
        'nombre_tercero'    => 'destino',
        'nombredestinatario'=> 'destino',
        'nom_destinatario'  => 'destino',
        'cliente'           => 'destino',
        'punto de venta'    => 'destino',
        // El destinatario aparte (2026-10-05): con él se reconocen las devoluciones a la empresa.
        // El destino es la CIUDAD DESTINO (pedido del usuario); si no viene, el destinatario.
        'destinatario'      => 'destinatario',
        // La CIUDAD de destino, en su propia columna: el Consolidado MR la muestra como Ciudad.
        'ciudad_destino'    => 'ciudad_destino',
        'ciudad destino'    => 'ciudad_destino',
        // fechas
        'fecha guia'        => 'fecha_guia',
        'fecha_guia'        => 'fecha_guia',
        'fecha documento'   => 'fecha_guia',
        'fecha_factura'     => 'fecha_guia',
        'fechaguiacliente'  => 'fecha_guia',
        'fecha elaboracion' => 'fecha_guia',
        'fecha despacho'    => 'fecha_despacho',
        'fecha_despacho'    => 'fecha_despacho',
        'fecha de despacho' => 'fecha_despacho',
        'despacho'          => 'fecha_despacho',
        'fechaingreso'      => 'fecha_despacho',
        'fecha entrega'     => 'fecha_entrega',
        'fechaentrega'      => 'fecha_entrega',
        // Desde cuándo el pedido está en el estado que muestra (2026-10-02).
        'fechaestadoguia'   => 'fecha_estado',
        'fecha estado'      => 'fecha_estado',
        'fecha_estado'      => 'fecha_estado',
        'fecha_entrega'     => 'fecha_entrega',
        'fecha de entrega'  => 'fecha_entrega',
        'entrega'           => 'fecha_entrega',
        // La hora en su propia columna (Producción diario de envíos): se le pega a su fecha.
        'hora entrega'      => 'hora_entrega',
        'hora estado'       => 'hora_estado',
    ];
    [$encabezado, $mapa, $filas] = encabezadoDelReporte($filas, $columnas);

    // La transportadora sale, en este orden: de lo que se eligió en el formulario (si se eligió),
    // de la COLUMNA del archivo (Vector Foods trae el transportador real de cada guía), o de
    // RECONOCER el formato del archivo por sus columnas —así el Excel de Proeslog o de AGV se sube
    // sin tener que elegir nada—. Ver detectarTransportadora().
    $formato  = detectarTransportadora($encabezado);
    $elegida  = textoLimpio($transportadoraPorDefecto, 80);
    $transDef = $elegida ?? $formato;

    // Sin transportadora en el archivo NI reconocida NI elegida, el archivo se carga igual y la
    // transportadora queda vacía (2026-10-05, pedido del usuario: la columna no es obligatoria). En el
    // Consolidado MR esas facturas muestran la guía y el estado, y el transportador se pone a mano.
    if (!isset($mapa['numero_factura']) && !isset($mapa['numero_guia'])) {
        return [
            'exito'   => false,
            'mensaje' => 'El archivo no trae ni "Factura" ni "Guía": sin uno de los dos no se puede identificar el pedido.',
        ];
    }

    $valor = fn(array $f, $campo) => isset($mapa[$campo]) ? ($f[$mapa[$campo]] ?? null) : null;

    // La hora que viene en otra columna ("07/09/2026" + "13:57"), pegada a su fecha.
    $conHora = function ($fecha, $hora) {
        $fecha = trim((string) $fecha);
        $hora  = trim((string) $hora);
        return ($fecha !== '' && !is_numeric($fecha) && strpos($fecha, ':') === false && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $hora))
            ? "{$fecha} {$hora}" : $fecha;
    };

    $preparadas   = [];
    $devoluciones = 0;
    $sinFacturaNu = 0;   // AGV: filas sin ninguna factura NU (2026-10-08)
    foreach ($filas as $f) {
        // '' y no null: la columna de la tabla no admite NULL, y vacío es "no se sabe".
        $transportadora = textoLimpio($valor($f, 'transportadora'), 80) ?? $transDef ?? '';
        // codigoLimpio y no textoLimpio: varios export ponen "0" en la guía o la factura cuando el
        // número todavía no está asignado. Un "0" no identifica ningún despacho —vale lo mismo que
        // vacío— y guardarlo agruparía en una sola fila pedidos que no tienen nada que ver.
        // sinBom: la primera celda de los export que vienen de un CSV trae la marca invisible del
        // archivo pegada (U+FEFF antes de "1074781"), y esa guía no se encontraba buscándola.
        $sinBom         = fn($v) => $v === null ? null : preg_replace('/^\x{FEFF}+/u', '', (string) $v);
        $factura        = codigoLimpio($sinBom($valor($f, 'numero_factura')), 60);
        $guia           = codigoLimpio($sinBom($valor($f, 'numero_guia')), 60);

        // PROESLOG (2026-10-02): su columna "factura" es la factura de FLETE que Proeslog le cobra a
        // la empresa (35742, la misma para cientos de guías), no la de la mercancía. La de la empresa
        // ("NU04164879") viene escrita en "observaciones". Se toma esa: es la que se busca, la que
        // cruza con el Consolidado MR, y no cambia cuando Proeslog factura el flete.
        if ($formato === 'Proeslog' && !isset($mapa['transportadora'])) {
            $factura = preg_match('/\bNU\s*0*\d{6,}\b/i', (string) $valor($f, 'detalle'), $nu)
                ? strtoupper(preg_replace('/\s+/', '', $nu[0])) : null;
        }

        // AGV (2026-10-08): las facturas de la remesa están en DOCUMENTO NRO 1 a 4, y solo cuentan las
        // que empiezan por NU (en esas columnas también vienen otros números: devoluciones "DEV…",
        // números de guía). Cada factura NU es un envío aparte, con la misma guía y el mismo estado.
        $facturasDeLaFila = [$factura];
        if ($formato === 'AGV' && isset($mapa['numero_guia']) && !isset($mapa['transportadora'])) {
            $facturasDeLaFila = [];
            foreach (['numero_factura', 'numero_factura_2', 'numero_factura_3', 'numero_factura_4'] as $campoFactura) {
                $doc = strtoupper((string) codigoLimpio($sinBom($valor($f, $campoFactura)), 60));
                if (str_starts_with($doc, 'NU') && !in_array($doc, $facturasDeLaFila, true)) {
                    $facturasDeLaFila[] = $doc;
                }
            }
            if (!$facturasDeLaFila) {
                $sinFacturaNu++;
                continue;
            }
            $factura = $facturasDeLaFila[0];
        }

        if ($factura === null && $guia === null) {
            continue;   // fila vacía o incompleta: se salta en silencio
        }

        // DEVOLUCIONES A LA EMPRESA (2026-10-05): el reporte de Producción diario de envíos trae, con
        // el mismo número de factura, el envío de vuelta de la mercancía rechazada (destinatario
        // "NUTRIUM S.A.S DEV"). Ese envío sí llega ("ENTREGADO") y taparía el estado real de la
        // factura —un rechazo— en el Consolidado MR. No se guarda.
        if (esDevolucionALaEmpresa($valor($f, 'destinatario'))) {
            $devoluciones++;
            continue;
        }

        foreach ($facturasDeLaFila as $factura) {
        $preparadas[] = [
            'transportadora' => $transportadora,
            // La clave NO lleva el nombre elegido al importar (2026-10-05): lleva el de la columna o el
            // del formato reconocido. Así, subir el mismo reporte con otro nombre actualiza los envíos
            // (y les cambia el nombre) en vez de guardar una copia de cada uno.
            'clave_transportadora' => textoLimpio($valor($f, 'transportadora'), 80) ?? $formato ?? '',
            'numero_factura' => $factura,
            'numero_guia'    => $guia,
            // limpiarTextoImportado: los reportes traen la observación con entidades HTML.
            'estado'         => limpiarTextoImportado(textoLimpio($valor($f, 'estado'), 80)
                                                  ?? textoLimpio($valor($f, 'estado_transporte'), 80)),
            'detalle'        => limpiarTextoImportado(textoLimpio($valor($f, 'detalle'), 500)),
            'bodega'         => limpiarTextoImportado(textoLimpio($valor($f, 'bodega'), 80)),
            'destino'        => limpiarTextoImportado(textoLimpio($valor($f, 'destino'), 160)
                                                  ?? textoLimpio($valor($f, 'ciudad_destino'), 160)
                                                  ?? textoLimpio($valor($f, 'destinatario'), 160)),
            'ciudad_destino' => limpiarTextoImportado(textoLimpio($valor($f, 'ciudad_destino'), 80)),
            'fecha_guia'     => fechaSoloDia($valor($f, 'fecha_guia')),
            'fecha_despacho' => fechaYHora($valor($f, 'fecha_despacho')),
            'fecha_entrega'  => fechaYHora($conHora($valor($f, 'fecha_entrega'), $valor($f, 'hora_entrega'))),
            'fecha_estado'   => fechaYHora($conHora($valor($f, 'fecha_estado'), $valor($f, 'hora_estado'))),
            'fuente'         => 'excel',
        ];
        }
    }

    if (!$preparadas) {
        // El archivo de AGV (el manifiesto de carga) no trae número de guía real ni factura: sus
        // filas se saltan todas y no queda nada que mostrar. Se avisa de forma útil.
        $comoCual = $transDef ? " (se reconoció como {$transDef})" : '';
        return [
            'exito'   => false,
            'mensaje' => "El archivo{$comoCual} no trae ninguna fila con un número de factura o de guía, "
                       . 'así que no hay pedidos para seguir. Revisá que sea el reporte de guías despachadas '
                       . 'y no el formato de carga.',
        ];
    }

    $r = guardarFilasDePedidos($pdo, $preparadas, $idUsuario);

    $partes = [];
    if ($r['nuevos'])       { $partes[] = "{$r['nuevos']} nuevo(s)"; }
    if ($r['actualizados']) { $partes[] = "{$r['actualizados']} actualizado(s)"; }
    if ($r['sin_cambios'])  { $partes[] = "{$r['sin_cambios']} sin cambios"; }

    // Si la transportadora se reconoció sola (no vino en columna), se dice cuál, para que quien
    // sube el archivo confirme que se identificó bien sin tener que abrir la tabla.
    $reconocida = (!isset($mapa['transportadora']) && $transDef)
        ? ($elegida !== null ? " Transportadora elegida: {$elegida}." : " Transportadora reconocida: {$transDef}.") : '';
    if (!isset($mapa['transportadora']) && !$transDef) {
        $reconocida = ' El archivo no trae la columna "Transportadora": quedó vacía.';
    }

    // Aviso de doble carga: el reporte de Vector Foods YA incluye las guías que mueve Proeslog
    // (aparecen con "PROESLOG" en su columna 'transportador'). Si además se sube el archivo propio
    // de Proeslog, esos envíos entran por dos vías —Vector Foods los identifica por factura y
    // Proeslog por número de guía—, así que no se funden en un solo pedido y quedan repetidos. Se
    // avisa solo cuando el archivo se reconoció como Proeslog (que es cuando puede pasar).
    $avisoDoble = '';
    if (!isset($mapa['transportadora']) && $formato === 'Proeslog') {
        $avisoDoble = ' Ojo: si también subís el reporte de Vector Foods, esas guías de Proeslog '
                    . 'ya vienen ahí y quedarían repetidas. Elegí una sola fuente por transportadora.';
    }

    return [
        'exito'        => true,
        'mensaje'      => 'Pedidos: ' . ($partes ? implode(', ', $partes) : 'ninguno') . '.' . $reconocida . $avisoDoble
                        . ($devoluciones ? ' Se dejaron afuera ' . $devoluciones . ' envío(s) de devolución a la empresa, para que no tapen el estado real de su factura.' : '')
                        . ($sinFacturaNu ? ' ' . $sinFacturaNu . ' fila(s) no traían ninguna factura NU en DOCUMENTO NRO 1 a 4 y no se tomaron.' : ''),
        'nuevos'       => $r['nuevos'],
        // Las facturas del reporte, para decir cuántas cruzan con el Consolidado MR.
        'facturas'     => array_values(array_unique(array_filter(array_column($preparadas, 'numero_factura')))),
        'actualizados' => $r['actualizados'],
    ];
}

/**
 * La fila de los TÍTULOS de un reporte y lo que viene debajo: [encabezado, mapa, filas de datos].
 *
 * Casi todos los reportes los traen en la fila 1, pero el "Producción diario de envíos" (2026-10-05)
 * arranca con el nombre del reporte y el rango de fechas, y los títulos están en la fila 5. Se toma
 * la primera de las primeras 20 filas que traiga Factura o Guía y al menos tres columnas conocidas.
 */
function encabezadoDelReporte(array $filas, array $columnas) {
    $filas = array_values($filas);
    foreach (array_slice($filas, 0, 20) as $i => $fila) {
        $mapa = mapearColumnas($fila, $columnas);
        if ((isset($mapa['numero_factura']) || isset($mapa['numero_guia'])) && count($mapa) >= 3) {
            return [$fila, $mapa, array_slice($filas, $i + 1)];
        }
    }
    $encabezado = $filas[0] ?? [];
    return [$encabezado, mapearColumnas($encabezado, $columnas), array_slice($filas, 1)];
}

/**
 * ¿El envío vuelve a la empresa? Destinatario "NUTRIUM S.A.S DEV", "NUTRIUM DEVOL", "MONTEROJO SEDE
 * PRINCIPAL"…: la mercancía rechazada que regresa. Con el número de la factura original, pero no es
 * su entrega.
 */
function esDevolucionALaEmpresa($destinatario) {
    $d = trim((string) $destinatario);
    return $d !== '' && preg_match('/\bDEV(OL\w*)?\b|^(NUTRIUM|MONTEROJO)\b/iu', $d) === 1;
}

/**
 * Reconoce de qué transportadora es un archivo por sus COLUMNAS, para no tener que elegirla a mano.
 *
 * Cada transportadora exporta con un juego de columnas propio e inconfundible (confirmado con el
 * usuario el 2026-09-22, un archivo real de cada una):
 *
 *   · PROESLOG  — "exportable_5902": trae 'guiatransporte' y 'rel_envio', que no aparecen en
 *                 ningún otro formato.
 *   · AGV       — "importaciones": el manifiesto de envíos, con 'dice_contener' y 'doc_remitente'
 *                 (remitente/destinatario y qué contiene la carga), sin la guía de transporte de
 *                 Proeslog.
 *   · VECTOR FOODS — "Detalle_Facturas": el reporte consolidado, con 'factura_real' y 'estado_guia'.
 *                 Este trae ADEMÁS la columna 'transportador' con el operador real de cada guía
 *                 (SOLISTICA, PROESLOG, INTERNO…), así que NO se le pone una etiqueta única: cada
 *                 fila conserva su transportador. Por eso acá devuelve null —no hay una sola
 *                 transportadora que ponerle— y la importación usa la columna.
 *   · AGV (Producción diario de envíos) — "Produccion_Diario_Envios…": trae 'remesa', 'documento nro 1'
 *                 y 'estado mercancia', que no aparecen juntos en otro. Hasta el 2026-10-08 se tomaba como
 *                 de Solistica; el usuario aclaró que es de AGV, y la versión nueva trae DOCUMENTO NRO 2 a 4.
 *
 * Devuelve el nombre a usar como transportadora del archivo, o null si no se reconoce o si el
 * archivo ya trae el transportador por fila.
 */
function detectarTransportadora(array $encabezado) {
    $cols  = array_map('normalizarEncabezado', $encabezado);
    $tiene = fn($clave) => in_array($clave, $cols, true);

    if ($tiene('guiatransporte') && $tiene('rel_envio')) {
        return 'Proeslog';
    }
    // "Producción diario de envíos": es el reporte de AGV (2026-10-08, pedido del usuario; antes se
    // tomaba como de Solistica). Viene con títulos arriba (fila 5) o sin ellos (fila 1), y la versión
    // nueva trae además DOCUMENTO NRO 2, 3 y 4.
    if ($tiene('remesa') && $tiene('documento nro 1') && $tiene('estado mercancia')) {
        return 'AGV';
    }
    if ($tiene('dice_contener') && ($tiene('doc_remitente') || $tiene('nom_destinatario'))
        && !$tiene('guiatransporte')) {
        return 'AGV';
    }

    // Vector Foods (y cualquier archivo con su propia columna de transportador) se importa con esa
    // columna, no con una etiqueta fija: null.
    return null;
}

/**
 * Graba una tanda de filas ya normalizadas, en una transacción. UPSERT por clave con COALESCE:
 * un campo que la fila nueva traiga vacío NO borra el que ya estaba (así una actualización de
 * estado no pierde la fecha de despacho cargada antes).
 */
function guardarFilasDePedidos($pdo, array $filas, $idUsuario) {
    $insertar = $pdo->prepare(
        "INSERT INTO seguimiento_pedidos
            (clave, transportadora, numero_factura, numero_guia, estado, detalle, bodega, destino, ciudad_destino,
             fecha_guia, fecha_despacho, fecha_entrega, fecha_estado, fuente, id_usuario)
         VALUES
            (:clave, :transportadora, :factura, :guia, :estado, :detalle, :bodega, :destino, :ciudad,
             :f_guia, :f_despacho, :f_entrega, :f_estado, :fuente, :usuario)
         ON DUPLICATE KEY UPDATE
             transportadora = VALUES(transportadora),
             numero_guia    = COALESCE(VALUES(numero_guia), numero_guia),
             fecha_estado   = COALESCE(VALUES(fecha_estado), fecha_estado),
             estado         = COALESCE(VALUES(estado), estado),
             detalle        = COALESCE(VALUES(detalle), detalle),
             bodega         = COALESCE(VALUES(bodega), bodega),
             destino        = COALESCE(VALUES(destino), destino),
             ciudad_destino = COALESCE(VALUES(ciudad_destino), ciudad_destino),
             fecha_guia     = COALESCE(VALUES(fecha_guia), fecha_guia),
             fecha_despacho = COALESCE(VALUES(fecha_despacho), fecha_despacho),
             fecha_entrega  = COALESCE(VALUES(fecha_entrega), fecha_entrega),
             fuente         = VALUES(fuente)"
    );

    $nuevos = 0; $actualizados = 0; $sinCambios = 0;

    // LA MISMA GUÍA ES EL MISMO PEDIDO (2026-10-02). La clave lleva la factura, y la factura de una
    // guía puede aparecer o cambiar entre un export y el siguiente (Proeslog le pone la de flete
    // cuando la factura; acá además se pasó a tomar la NU de observaciones). Sin esto, esa guía
    // quedaba DOS veces en el tablero. Antes de guardar, la fila existente de esa transportadora y
    // esa guía pasa a la clave nueva, y el UPSERT la actualiza en vez de duplicarla.
    $limpiar  = fn($v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
    $porGuia  = [];
    $claves   = [];
    foreach ($pdo->query("SELECT id_pedido, clave, transportadora, numero_guia FROM seguimiento_pedidos") as $e) {
        $claves[$e['clave']] = true;
        if ($limpiar($e['numero_guia']) !== '') {
            // La transportadora de la CLAVE (lo que va antes del primer "|"), no el nombre mostrado.
            $porGuia[strstr($e['clave'], '|', true) . '|' . $limpiar($e['numero_guia'])] = $e;
        }
    }
    $cambiarClave = $pdo->prepare("UPDATE seguimiento_pedidos SET clave = ?, numero_factura = ? WHERE id_pedido = ?");

    // Una guía que en ESTE archivo trae varias facturas (AGV, 2026-10-08: DOCUMENTO NRO 1 a 4) son varios
    // envíos y no uno con la factura cambiada: a esas no se les pasa la clave de una factura a otra.
    $facturasPorGuia = [];
    foreach ($filas as $f) {
        if ($limpiar($f['numero_guia']) !== '') {
            $facturasPorGuia[$limpiar($f['clave_transportadora'] ?? $f['transportadora']) . '|' . $limpiar($f['numero_guia'])][(string) $f['numero_factura']] = true;
        }
    }

    $pdo->beginTransaction();
    try {
        foreach ($filas as $f) {
            $deClave = $f['clave_transportadora'] ?? $f['transportadora'];
            $clave = claveDePedido($deClave, $f['numero_factura'], $f['numero_guia']);
            $deGuia = $limpiar($deClave) . '|' . $limpiar($f['numero_guia']);
            if ($limpiar($f['numero_guia']) !== '' && isset($porGuia[$deGuia]) && count($facturasPorGuia[$deGuia] ?? []) <= 1
                && $porGuia[$deGuia]['clave'] !== $clave && !isset($claves[$clave])) {
                $cambiarClave->execute([$clave, $f['numero_factura'], $porGuia[$deGuia]['id_pedido']]);
                unset($claves[$porGuia[$deGuia]['clave']]);
                $claves[$clave] = true;
                $porGuia[$deGuia]['clave'] = $clave;
            }

            $insertar->execute([
                ':clave'          => $clave,
                ':transportadora' => $f['transportadora'],
                ':factura'        => $f['numero_factura'],
                ':guia'           => $f['numero_guia'],
                ':estado'         => $f['estado'],
                ':detalle'        => $f['detalle'],
                ':bodega'         => $f['bodega'],
                ':destino'        => $f['destino'],
                ':ciudad'         => $f['ciudad_destino'] ?? null,
                ':f_guia'         => $f['fecha_guia'],
                ':f_despacho'     => $f['fecha_despacho'],
                ':f_entrega'      => $f['fecha_entrega'],
                ':f_estado'       => $f['fecha_estado'] ?? null,
                ':fuente'         => $f['fuente'],
                ':usuario'        => $idUsuario,
            ]);
            // rowCount: 1 = insertó, 2 = actualizó, 0 = ya estaba igual.
            match ($insertar->rowCount()) {
                1       => $nuevos++,
                2       => $actualizados++,
                default => $sinCambios++,
            };
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error guardando pedidos de seguimiento: ' . $e->getMessage());
        return ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0];
    }

    return ['nuevos' => $nuevos, 'actualizados' => $actualizados, 'sin_cambios' => $sinCambios];
}

// =================================================================================================
// IMPORTAR EL EXPORT DE FACTURACIÓN DE SAP ("Consolidado completo")
//
// Este archivo NO es un reporte de transportadora: es lo que la fábrica factura a cada distribuidor,
// una fila por material (decenas de miles) que se agrupan por número de factura. No trae
// transportadora, ni estado, ni guía, ni fechas de despacho/entrega —eso vive en los reportes de las
// transportadoras—. Trae, en cambio, el dato REAL del cliente, la ciudad, la fecha de factura, el
// pedido y la entrega de SAP.
//
// QUÉ HACE AL SUBIRLO (decidido con el usuario el 2026-09-23, opción "base + enriquecer"):
//   · A las facturas que YA están en el tablero (subidas desde Proeslog/Vector/AGV) les corrige el
//     cliente y la ciudad (destino) y completa la fecha, con el dato de SAP. NO toca estado,
//     transportadora, guía ni las fechas de despacho/entrega de la transportadora.
//   · A las facturas que todavía NO están, las crea como fila base con estado "Facturado" (sin
//     transportadora): cuando después se suba el reporte de la transportadora, esa fila recibe el
//     estado y la guía.
//   El cruce es por NÚMERO DE FACTURA, que es lo único en común entre este archivo y los de las
//   transportadoras.
// =================================================================================================

/**
 * Filtro de lectura para PhpSpreadsheet: permite leer SOLO ciertas columnas (por letra) y/o hasta
 * cierta fila. Con esto el export de SAP se carga liviano —solo las 6 columnas que interesan— en vez
 * de las 56 completas, que es lo que agotaba la memoria.
 */
class FiltroColumnasSeguimiento implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
    private array $cols;
    private int $filaMax;
    public function __construct(array $cols = [], int $filaMax = 0) {
        $this->cols = $cols;
        $this->filaMax = $filaMax;
    }
    public function readCell($col, $row, $ws = ''): bool {
        if ($this->filaMax > 0 && (int) $row > $this->filaMax) { return false; }
        if ($this->cols && !in_array($col, $this->cols, true)) { return false; }
        return true;
    }
}

/** Sube el límite de memoria a por lo menos $minMB si está por debajo (nunca lo baja). */
function asegurarMemoriaSeguimiento($minMB) {
    $cur = trim((string) ini_get('memory_limit'));
    if ($cur === '' || $cur === '-1') { return; }
    $num  = (int) $cur;
    $unit = strtoupper(substr($cur, -1));
    $mb   = $unit === 'G' ? $num * 1024 : $num;   // se asume M si no dice G
    if ($mb > 0 && $mb < $minMB) { @ini_set('memory_limit', $minMB . 'M'); }
}

/**
 * Lee SOLO la fila de encabezado de un Excel, barato y sin cargar los datos. Sirve para reconocer
 * de qué archivo se trata antes de decidir cómo leerlo entero.
 */
function encabezadoDeExcel($rutaArchivo) {
    // Primero el lector rápido (2026-10-05): lee la fila 1 y corta. PhpSpreadsheet, aun con el filtro
    // de "solo la fila 1", recorría el archivo entero: 15 s con el Consolidado MR de 11 MB.
    $rapido = leerXlsxRapido($rutaArchivo, null, 1);
    if ($rapido !== null) {
        return $rapido[1] ?? [];
    }
    try {
        $lector = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($rutaArchivo);
        $lector->setReadDataOnly(true);
        $nombres = $lector->listWorksheetNames($rutaArchivo);
        if ($nombres) { $lector->setLoadSheetsOnly($nombres[0]); }
        $lector->setReadFilter(new FiltroColumnasSeguimiento([], 1));   // solo la fila 1
        $libro = $lector->load($rutaArchivo);
        $fila  = $libro->getSheet(0)->toArray(null, false, false, false)[0] ?? [];
        $libro->disconnectWorksheets();
        return $fila;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * ¿El encabezado es el del export de facturación de SAP? Se usa el mismo criterio que el módulo de
 * Consolidados (pareceExportSap: material + cantidad + cajas, que ningún reporte de transportadora
 * tiene) y además tiene que traer la columna "Factura", que es con lo que se cruza cada pedido.
 */
function esFacturacionSap(array $encabezado) {
    if (!function_exists('pareceExportSap') || !pareceExportSap($encabezado)) {
        return false;
    }
    $mapa = mapearColumnas($encabezado, ['factura' => 'factura']);
    return isset($mapa['factura']);
}

/**
 * Importa el export de facturación de SAP al tablero. Ver el bloque de arriba para el qué y el
 * porqué. Devuelve ['exito'=>bool, 'mensaje'=>string, 'nuevos'=>int, 'actualizados'=>int].
 */
function importarFacturacionSap($pdo, $rutaArchivo, $idUsuario, array $encabezado = null) {
    @set_time_limit(300);                 // el archivo es grande: puede tardar ~medio minuto
    asegurarMemoriaSeguimiento(512);

    $encabezado = $encabezado ?? encabezadoDeExcel($rutaArchivo);

    // Las columnas se ubican por NOMBRE (no por posición fija): un export podría reordenarlas.
    // (En "valor neto" gana la PRIMERA de las dos columnas homónimas —la numérica—; la segunda es
    // la moneda "COP". mapearColumnas ya prefiere la primera coincidencia.)
    $mapa = mapearColumnas($encabezado, [
        'factura'                    => 'factura',
        'fecha factura'              => 'fecha',
        'nombre 1'                   => 'cliente',
        'destinatario de mercancias' => 'cliente_alt',
        'poblacion'                  => 'ciudad',
        'calle'                      => 'calle',
        'nº ident.fis.1'             => 'nit',
        'no ident.fis.1'             => 'nit',
        'valor neto'                 => 'valor_neto',
        'referencia'                 => 'referencia',
        'an.'                        => 'an',
        'pedido cliente'             => 'pedido',
        'entrega'                    => 'entrega',
    ]);
    if (!isset($mapa['factura'])) {
        return ['exito' => false, 'mensaje' => 'El archivo de SAP no trae la columna "Factura": sin ella no se puede identificar cada pedido.'];
    }

    // Índice de columna (0-based) -> letra ("A", "B"...), para leer solo esas columnas.
    $col = [];
    foreach ($mapa as $campo => $idx) {
        $col[$campo] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    }

    // Carga liviana: solo las columnas mapeadas, de todas las filas.
    try {
        $lector = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($rutaArchivo);
        $lector->setReadDataOnly(true);
        $nombres = $lector->listWorksheetNames($rutaArchivo);
        if ($nombres) { $lector->setLoadSheetsOnly($nombres[0]); }
        $lector->setReadFilter(new FiltroColumnasSeguimiento(array_values($col)));
        $libro = $lector->load($rutaArchivo);
    } catch (Throwable $e) {
        error_log('Error leyendo facturación SAP: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo leer el archivo de SAP. Revisá que sea el Excel correcto.'];
    }

    $hoja    = $libro->getSheet(0);
    $maxFila = $hoja->getHighestRow();
    $leer    = fn($campo, $r) => isset($col[$campo]) ? $hoja->getCell($col[$campo] . $r)->getValue() : null;

    // Se agrupa por factura. La cabecera (cliente, ciudad, fecha, etc.) se toma del PRIMER renglón,
    // pero el Valor neto se SUMA sobre todos los renglones de la factura (el total facturado).
    //
    // ANULADAS (columna "An."): un renglón con "X" es de una factura anulada. En este export una
    // factura está entera anulada o entera vigente (nunca mezcla), así que en cuanto se ve una X se
    // marca toda la factura como anulada, no se muestra y —si ya estaba cargada— se borra después.
    $porFactura = [];
    $anuladas   = [];
    for ($r = 2; $r <= $maxFila; $r++) {
        $factura = codigoLimpio($leer('factura', $r), 60);
        if ($factura === null) { continue; }

        if (strtoupper(trim((string) $leer('an', $r))) === 'X') {
            $anuladas[$factura] = true;
            unset($porFactura[$factura]);   // por si hubiera empezado a acumular (no debería mezclar)
            continue;
        }
        if (isset($anuladas[$factura])) { continue; }

        $q = $leer('valor_neto', $r);
        $q = is_numeric($q) ? (float) $q : 0.0;

        if (isset($porFactura[$factura])) {
            $porFactura[$factura]['valor_neto'] += $q;   // sumar el renglón a la factura ya vista
            continue;
        }

        // Primer renglón de la factura: cabecera.
        $cliente = textoLimpio($leer('cliente', $r), 160) ?? textoLimpio($leer('cliente_alt', $r), 160);
        $ciudad  = textoLimpio($leer('ciudad', $r), 80);
        $calle   = textoLimpio($leer('calle', $r), 200);
        $pedido  = textoLimpio($leer('pedido', $r), 80);
        $entrega = codigoLimpio($leer('entrega', $r), 40);

        // Ubicación (dirección) = "Población - Calle", como lo pidió el usuario.
        $direccion = null;
        if ($ciudad !== null && $calle !== null) { $direccion = mb_substr($ciudad . ' - ' . $calle, 0, 255); }
        elseif ($ciudad !== null)                { $direccion = $ciudad; }
        elseif ($calle !== null)                 { $direccion = $calle; }

        // detalle: se conserva para la vista "Todos" (que usa las columnas de transportadora).
        $trozos = [];
        if ($pedido !== null)  { $trozos[] = 'Pedido ' . $pedido; }
        if ($entrega !== null) { $trozos[] = 'Entrega ' . $entrega; }

        $porFactura[$factura] = [
            'destino'        => $cliente,                        // Nombre 1 (cliente)
            'direccion'      => $direccion,                      // Población - Calle
            'nit'            => codigoLimpio($leer('nit', $r), 40),
            'referencia'     => textoLimpio($leer('referencia', $r), 60),
            'pedido_cliente' => $pedido,
            'valor_neto'     => $q,
            'fecha'          => fechaDesdeExcel($leer('fecha', $r)),
            'detalle'        => $trozos ? implode(' · ', $trozos) : null,
        ];
    }
    $libro->disconnectWorksheets();
    unset($libro, $hoja);

    if (!$porFactura && !$anuladas) {
        return ['exito' => false, 'mensaje' => 'El archivo de SAP no trae ninguna factura legible.'];
    }

    // Qué facturas de SAP ya están cargadas (fuente 'facturacion'), para actualizar esas y crear solo
    // las que faltan. Se mira solo la fuente de facturación: los pedidos de transportadora usan otra
    // numeración y nunca comparten factura con SAP.
    $existentes = [];
    $q = $pdo->query("SELECT DISTINCT numero_factura FROM seguimiento_pedidos
                       WHERE fuente = 'facturacion' AND numero_factura IS NOT NULL AND numero_factura <> ''");
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) { $existentes[(string) $f] = true; }

    // Actualiza una fila de facturación ya cargada con los datos frescos de SAP.
    $actualizar = $pdo->prepare(
        "UPDATE seguimiento_pedidos
            SET destino=:destino, direccion=:direccion, nit=:nit, valor_neto=:valor,
                referencia=:referencia, pedido_cliente=:pedped, detalle=:detalle, fecha_guia=:fecha
          WHERE fuente='facturacion' AND numero_factura=:factura"
    );
    // Crea la fila base. ON DUPLICATE por si acaso (no debería chocar: la factura no estaba).
    $insertar = $pdo->prepare(
        "INSERT INTO seguimiento_pedidos
            (clave, transportadora, numero_factura, numero_guia, estado, detalle, destino,
             direccion, nit, valor_neto, referencia, pedido_cliente, fecha_guia, fuente, id_usuario)
         VALUES (:clave, '', :factura, NULL, 'Facturado', :detalle, :destino,
             :direccion, :nit, :valor, :referencia, :pedped, :fecha, 'facturacion', :usuario)
         ON DUPLICATE KEY UPDATE
             destino=VALUES(destino), direccion=VALUES(direccion), nit=VALUES(nit),
             valor_neto=VALUES(valor_neto), referencia=VALUES(referencia),
             pedido_cliente=VALUES(pedido_cliente), detalle=VALUES(detalle), fecha_guia=VALUES(fecha_guia)"
    );
    // Borra una fila de facturación cuya factura quedó anulada (se había cargado antes con vigente).
    $borrarAnulada = $pdo->prepare(
        "DELETE FROM seguimiento_pedidos WHERE fuente='facturacion' AND numero_factura = ?"
    );

    $nuevas = 0; $enriquecidas = 0; $borradas = 0;
    $pdo->beginTransaction();
    try {
        foreach ($porFactura as $factura => $d) {
            $params = [
                ':factura'    => (string) $factura,
                ':detalle'    => $d['detalle'],
                ':destino'    => $d['destino'],
                ':direccion'  => $d['direccion'],
                ':nit'        => $d['nit'],
                ':valor'      => $d['valor_neto'],
                ':referencia' => $d['referencia'],
                ':pedped'     => $d['pedido_cliente'],
                ':fecha'      => $d['fecha'],
            ];
            if (isset($existentes[$factura])) {
                $actualizar->execute($params);
                $enriquecidas++;
            } else {
                $insertar->execute([':clave' => claveDePedido(null, $factura, null), ':usuario' => $idUsuario] + $params);
                $nuevas++;
            }
        }
        // Sacar del tablero las facturas que ahora vienen anuladas.
        foreach (array_keys($anuladas) as $factura) {
            $borrarAnulada->execute([(string) $factura]);
            $borradas += $borrarAnulada->rowCount();
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error guardando facturación SAP: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error guardando las facturas. No se cambió nada en el tablero.'];
    }

    $partes = [];
    if ($nuevas)       { $partes[] = "{$nuevas} nueva(s)"; }
    if ($enriquecidas) { $partes[] = "{$enriquecidas} actualizada(s)"; }
    $notaAnul = count($anuladas)
        ? ' Se omitieron ' . count($anuladas) . ' factura(s) anulada(s) (An. = X)' . ($borradas ? ", y se quitaron {$borradas} que estaban cargadas" : '') . '.'
        : '';

    return [
        'exito'        => true,
        'mensaje'      => 'Facturación de SAP reconocida. Facturas vigentes: ' . (implode(', ', $partes) ?: 'ninguna') . '.'
                        . $notaAnul . ' Se ven en la pestaña "Facturación (SAP)".',
        'nuevos'       => $nuevas,
        'actualizados' => $enriquecidas,
    ];
}

/**
 * Suma del Valor neto de las facturas que cumplen el filtro (para el total de la pestaña SAP).
 * Solo tiene sentido con origen='sap'; con otros orígenes las filas no tienen valor_neto.
 */
function sumaValorNeto($pdo, array $filtros = []) {
    [$whereSql, $params] = filtroDePedidos($filtros);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor_neto), 0) FROM seguimiento_pedidos{$whereSql}");
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

/** Borra un pedido por su id. Devuelve true si borró algo. */
function eliminarPedido($pdo, $idPedido) {
    $stmt = $pdo->prepare("DELETE FROM seguimiento_pedidos WHERE id_pedido = ?");
    $stmt->execute([(int) $idPedido]);
    return $stmt->rowCount() > 0;
}

/**
 * Borra varios pedidos de una vez, por sus ids. Devuelve cuántos borró.
 *
 * Los ids se pasan como marcadores y no concatenados: aunque vengan de un checkbox del propio
 * sistema, entran por POST y podrían venir armados a mano.
 */
// -------------------------------------------------------------------------------------------------
// ELIMINAR TODOS LOS DATOS (2026-10-02)
//
// Pedido del usuario: un botón que vacíe el tablero, igual que el de Consolidados (modal que avisa
// qué se pierde y pide la contraseña). Se elige QUÉ se borra, porque las pestañas son dos fuentes
// distintas: los pedidos de las transportadoras, la facturación de SAP, o todo.
// -------------------------------------------------------------------------------------------------

/** La condición SQL de cada cosa que se puede borrar. Fija, no viene del usuario. */
function condicionesParaEliminarSeguimiento() {
    return [
        'transportadoras' => "(fuente IS NULL OR fuente <> 'facturacion')",
        'sap'             => "fuente = 'facturacion'",
        'todos'           => '1 = 1',
    ];
}

/** Cuántos pedidos hay hoy en cada opción del modal: ['transportadoras', 'sap', 'todos']. */
function resumenParaEliminarSeguimiento($pdo) {
    $r = $pdo->query(
        "SELECT SUM(fuente IS NULL OR fuente <> 'facturacion') AS transportadoras,
                SUM(fuente = 'facturacion') AS sap, COUNT(*) AS todos
           FROM seguimiento_pedidos"
    )->fetch(PDO::FETCH_ASSOC);
    return array_map('intval', $r ?: ['transportadoras' => 0, 'sap' => 0, 'todos' => 0]);
}

/** Borra los pedidos de $origen ('transportadoras', 'sap' o 'todos'). Devuelve ['exito', 'borrados']. */
function eliminarTodoSeguimiento($pdo, $origen) {
    $condicion = condicionesParaEliminarSeguimiento()[$origen] ?? null;
    if ($condicion === null) {
        return ['exito' => false, 'borrados' => 0];
    }
    try {
        $n = $pdo->exec("DELETE FROM seguimiento_pedidos WHERE {$condicion}");
        return ['exito' => true, 'borrados' => (int) $n];
    } catch (PDOException $e) {
        error_log('Error eliminando el Estado de pedidos: ' . $e->getMessage());
        return ['exito' => false, 'borrados' => 0];
    }
}

function eliminarPedidos($pdo, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($n) => $n > 0)));
    if (!$ids) {
        return 0;
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM seguimiento_pedidos WHERE id_pedido IN ($marcas)");
    $stmt->execute($ids);
    return $stmt->rowCount();
}

// -------------------------------------------------------------------------------------------------
// Fechas: aceptan lo que venga de un <input> (texto) o de una celda de Excel (número de serie o
// texto), y devuelven el formato que espera MySQL, o null.
// -------------------------------------------------------------------------------------------------

/**
 * Deshace las entidades HTML que traen algunos export en los campos de texto.
 *
 * El reporte de Vector Foods trae la observación con entidades, y a veces DOBLE-codificadas:
 * "15&amp;#x2F;02&amp;#x2F;22" es "15/02/22" pasado por el codificador dos veces. Guardar eso tal
 * cual llenaba el detalle de "&#x2F;" ilegibles. Se decodifica en un bucle corto hasta que el
 * texto deja de cambiar, para deshacer también la doble codificación, con un tope por las dudas.
 */
function limpiarTextoImportado($texto) {
    if ($texto === null) {
        return null;
    }
    for ($i = 0; $i < 3; $i++) {
        $nuevo = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($nuevo === $texto) {
            break;
        }
        $texto = $nuevo;
    }
    return $texto;
}

/** 'Y-m-d' o null. Usa fechaDesdeExcel() para el número de serie de Excel. */
function fechaSoloDia($valor) {
    if ($valor === null || trim((string) $valor) === '') {
        return null;
    }
    return fechaDesdeExcel($valor);
}

/** 'Y-m-d H:i:s' o null. Conserva la hora si viene; si solo hay fecha, queda a las 00:00. */
function fechaYHora($valor) {
    if ($valor === null || trim((string) $valor) === '') {
        return null;
    }
    // Número de serie de Excel: la parte entera es el día y la decimal, la hora.
    if (is_numeric($valor)) {
        try {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $valor)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
    $texto = trim((string) $valor);
    foreach (['!d/m/Y H:i', '!d/m/Y H:i:s', '!Y-m-d H:i', '!Y-m-d H:i:s', '!d/m/Y', '!Y-m-d'] as $formato) {
        $fecha = DateTime::createFromFormat($formato, $texto);
        if ($fecha !== false) {
            return $fecha->format('Y-m-d H:i:s');
        }
    }
    $tiempo = strtotime($texto);
    return $tiempo ? date('Y-m-d H:i:s', $tiempo) : null;
}
