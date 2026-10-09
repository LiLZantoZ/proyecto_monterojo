<?php
// modules/formato_conciliador/model_formato_conciliador.php
// FORMATO CONCILIADOR (2026-10-06): el módulo del sistema de bodega, adaptado a Monterojo.
//
// Es el registro de lo que sale de producción, estiba por estiba: fecha, SKU, lote, vencimiento,
// cajas y saldos (unidades sueltas), quién lo pasó, el turno, novedades y estado. Si se produjeron
// 3 estibas se cargan 3 registros ("Cantidad de estibas", hasta 200: en un día se llegan a hacer
// 100 estibas de un mismo lote y vencimiento).
//
// SIN CUPO (2026-10-06, pedido del usuario): en bodega cada registro daba cupo para ubicar una
// estiba en Posiciones y sin registro no se podía. Acá NO: Posiciones ubica cualquier estiba, y lo
// que hay registrado y ubicado de cada producto se muestra solo como información.
//
// LO QUE CAMBIA RESPECTO DE BODEGA:
//  · Las unidades por caja salen del MAESTRO DE PRODUCTOS de Monterojo (en bodega, del catálogo
//    de SKU), y la descripción del maestro o de la lista de Monterojo.
//  · El rótulo es el mismo de la hoja "ROTULO" del Excel de bodega (SKU, descripción, lote, día
//    juliano, vencimiento y cajas), con sus medidas.

require_once __DIR__ . '/../productos/model_productos.php';   // catalogoSkuProductos(), unidadesPorCajaSku(), textoLimpio()

const CONCILIADOR_POR_PAGINA = 50;
const CONCILIADOR_ESTADOS = ['Disponible', 'En Control De Calidad', 'Fecha Corta', 'Defectuoso'];
const CONCILIADOR_MAX_ESTIBAS = 200;   // por registro (2026-10-06: en un día se hacen hasta 100 del mismo lote)
// El código del responsable según su rol (en bodega cada rol tenía el suyo; el Administrador, 99).
const CONCILIADOR_CODIGOS_POR_ROL = ['Administrador' => 99];

/** El turno de ahora: 1 de 05:30 a 12:30, 2 de 12:30 a 21:00, 3 de 21:00 a 05:30. */
function turnoActualConciliador() {
    $hora = (int) (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Hi');
    if ($hora >= 530 && $hora < 1230) {
        return 1;
    }
    return ($hora >= 1230 && $hora < 2100) ? 2 : 3;
}

/** El código del responsable de un rol (0 si no tiene). */
function codigoResponsableConciliador($nombreRol) {
    return CONCILIADOR_CODIGOS_POR_ROL[(string) $nombreRol] ?? 0;
}

/**
 * Lo que se completa solo al escribir el SKU y el lote: la descripción, las unidades por caja y,
 * si ese producto ya se conoce (en Productos o en un registro anterior), su vencimiento.
 */
function buscarProductoConciliador($pdo, $sku, $lote) {
    $sku = trim((string) $sku);
    $catalogo = catalogoSkuProductos($pdo);
    if ($sku === '' || !array_key_exists($sku, $catalogo)) {
        return ['encontrado' => false];
    }
    $vence = null;
    if (trim((string) $lote) !== '') {
        $st = $pdo->prepare("SELECT fecha_vencimiento FROM (
                                 SELECT fecha_vencimiento, creado_el FROM productos WHERE sku = ? AND TRIM(lote) = TRIM(?) AND fecha_vencimiento IS NOT NULL
                                 UNION ALL
                                 SELECT fecha_vencimiento, creado_el FROM formato_conciliador WHERE sku = ? AND TRIM(lote) = TRIM(?) AND fecha_vencimiento IS NOT NULL
                             ) t ORDER BY creado_el DESC LIMIT 1");
        $st->execute([$sku, $lote, $sku, $lote]);
        $vence = $st->fetchColumn() ?: null;
    }
    return ['encontrado' => true, 'descripcion' => $catalogo[$sku], 'unidades_por_caja' => unidadesPorCajaSku($pdo, $sku), 'fecha_vencimiento' => $vence];
}

/**
 * Lo registrado y lo ubicado de un producto (SKU + lote + vencimiento): cuántos registros tiene en
 * el conciliador, cuántas estibas de él hay hoy en posiciones y cuántas faltan por ubicar. Solo
 * informa (no frena nada: ver la cabecera). $excluirPosicion: al editar una estiba, la suya no cuenta.
 */
function cupoConciliador($pdo, $sku, $lote, $vence, $excluirPosicion = null) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM formato_conciliador WHERE sku = ? AND TRIM(lote) <=> TRIM(?) AND fecha_vencimiento <=> ?");
    $st->execute([(string) $sku, $lote, $vence ?: null]);
    $cupo = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM posiciones_estibas e JOIN productos p ON p.id = e.id_producto
                         WHERE p.sku = ? AND TRIM(p.lote) <=> TRIM(?) AND p.fecha_vencimiento <=> ? AND e.id_posicion <> ?");
    $st->execute([(string) $sku, $lote, $vence ?: null, (int) $excluirPosicion]);
    $usadas = (int) $st->fetchColumn();
    return ['cupo' => $cupo, 'usadas' => $usadas, 'disponibles' => max(0, $cupo - $usadas)];
}

/**
 * Registra lo que salió de producción: una fila por estiba ("Cantidad de estibas"), todas iguales.
 * $usuario: ['id', 'nombre', 'rol']. Devuelve ['exito', 'mensaje'].
 */
function crearRegistrosConciliador($pdo, array $d, array $usuario) {
    $fecha = trim((string) ($d['fecha'] ?? ''));
    $sku = trim((string) ($d['sku'] ?? ''));
    $lote = textoLimpio($d['lote'] ?? null, 60);
    $vence = trim((string) ($d['fecha_vencimiento'] ?? ''));
    $cajas = (int) ($d['cajas'] ?? 0);
    $saldos = (int) ($d['saldos'] ?? 0);
    $estibas = (int) ($d['cantidad_estibas'] ?? 1);
    $estado = in_array($d['estado'] ?? '', CONCILIADOR_ESTADOS, true) ? $d['estado'] : '';
    $novedades = textoLimpio($d['novedades'] ?? null, 255);

    foreach (['fecha' => $fecha, 'vencimiento' => $vence] as $nombre => $valor) {
        if ($valor !== '' && (!($f = DateTime::createFromFormat('!Y-m-d', $valor)) || $f->format('Y-m-d') !== $valor)) {
            return ['exito' => false, 'mensaje' => "La fecha de {$nombre} no es válida."];
        }
    }
    if ($fecha === '' || $sku === '' || $lote === null || $estado === '') {
        return ['exito' => false, 'mensaje' => 'Hacen falta la fecha, el SKU, el lote y el estado.'];
    }
    if ($cajas < 0 || $saldos < 0 || ($cajas === 0 && $saldos === 0)) {
        return ['exito' => false, 'mensaje' => 'Escribí las cajas o los saldos (no pueden ser negativos ni los dos cero).'];
    }
    if ($estibas < 1 || $estibas > CONCILIADOR_MAX_ESTIBAS) {
        return ['exito' => false, 'mensaje' => 'La cantidad de estibas tiene que estar entre 1 y ' . CONCILIADOR_MAX_ESTIBAS . '.'];
    }
    $catalogo = catalogoSkuProductos($pdo);
    if (!array_key_exists($sku, $catalogo)) {
        return ['exito' => false, 'mensaje' => "El SKU {$sku} no está en el maestro de productos ni en la lista de Monterojo."];
    }
    $upc = unidadesPorCajaSku($pdo, $sku);
    $cantidad = $upc ? $cajas * $upc + $saldos : null;
    $descripcion = textoLimpio($d['descripcion'] ?? null, 255) ?? $catalogo[$sku];

    $st = $pdo->prepare("INSERT INTO formato_conciliador (fecha, sku, descripcion, lote, fecha_vencimiento, cajas, saldos, unidades_por_caja,
                                                          cantidad, responsable, codigo_responsable, turno, novedades, estado, id_usuario_registro)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $pdo->beginTransaction();
    try {
        for ($i = 0; $i < $estibas; $i++) {
            $st->execute([$fecha, $sku, $descripcion, $lote, $vence !== '' ? $vence : null, $cajas, $saldos, $upc, $cantidad,
                          mb_substr((string) $usuario['nombre'], 0, 120), codigoResponsableConciliador($usuario['rol'] ?? ''),
                          turnoActualConciliador(), $novedades, $estado, $usuario['id']]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Error registrando en el Formato Conciliador: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'Hubo un error guardando el registro. No se cargó nada.'];
    }
    return ['exito' => true, 'mensaje' => ($estibas === 1 ? 'Registro guardado' : "{$estibas} registros guardados (uno por estiba)")
        . ": SKU {$sku}, lote {$lote}" . ($vence !== '' ? ', vence ' . date('d/m/Y', strtotime($vence)) : '')
        . ", {$cajas} caja(s)" . ($saldos ? " y {$saldos} saldo(s)" : '') . '.'];
}

/** Elimina un registro. */
function eliminarRegistroConciliador($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM formato_conciliador WHERE id = ?");
    $st->execute([(int) $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return ['exito' => false, 'mensaje' => 'Ese registro ya no existe.'];
    }
    $pdo->prepare("DELETE FROM formato_conciliador WHERE id = ?")->execute([(int) $id]);
    return ['exito' => true, 'mensaje' => "Se eliminó el registro #{$r['id']} (SKU {$r['sku']}, lote {$r['lote']})."];
}

/** El WHERE de los filtros (fecha, sku, lote, responsable y una búsqueda general): [sql, parámetros]. */
function condicionesConciliador(array $f) {
    $w = []; $p = [];
    if (($f['fecha'] ?? '') !== '') {
        $w[] = 'fc.fecha = ?';
        $p[] = $f['fecha'];
    }
    foreach (['sku' => 'fc.sku', 'lote' => 'fc.lote', 'responsable' => 'fc.responsable'] as $clave => $columna) {
        if (($f[$clave] ?? '') !== '') {
            $w[] = "{$columna} LIKE ?";
            $p[] = '%' . $f[$clave] . '%';
        }
    }
    if (($f['general'] ?? '') !== '') {
        $o = [];
        foreach (['fc.id', 'fc.descripcion', "DATE_FORMAT(fc.fecha_vencimiento, '%d/%m/%Y')", 'fc.novedades', 'fc.estado', 'fc.turno'] as $c) {
            $o[] = "{$c} LIKE ?";
            $p[] = '%' . $f['general'] . '%';
        }
        $w[] = '(' . implode(' OR ', $o) . ')';
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

/**
 * Una página de registros, del más nuevo al más viejo. Cada fila trae además cuántas estibas de
 * ese producto (SKU + lote + vencimiento) hay registradas y cuántas están hoy en posiciones.
 */
function paginaConciliador($pdo, array $filtros, $pagina = 1, $porPagina = CONCILIADOR_POR_PAGINA) {
    [$where, $p] = condicionesConciliador($filtros);
    $st = $pdo->prepare("SELECT COUNT(*) FROM formato_conciliador fc {$where}");
    $st->execute($p);
    $total = (int) $st->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, (int) $pagina), $totalPaginas);
    $st = $pdo->prepare("SELECT fc.*,
            (SELECT COUNT(*) FROM formato_conciliador f2 WHERE f2.sku = fc.sku AND TRIM(f2.lote) <=> TRIM(fc.lote) AND f2.fecha_vencimiento <=> fc.fecha_vencimiento) AS registradas,
            (SELECT COUNT(*) FROM posiciones_estibas e JOIN productos pr ON pr.id = e.id_producto
              WHERE pr.sku = fc.sku AND TRIM(pr.lote) <=> TRIM(fc.lote) AND pr.fecha_vencimiento <=> fc.fecha_vencimiento) AS ubicadas
        FROM formato_conciliador fc {$where} ORDER BY fc.id DESC
        LIMIT " . (int) $porPagina . " OFFSET " . (($pagina - 1) * $porPagina));
    $st->execute($p);
    return ['filas' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pagina' => $pagina, 'total_paginas' => $totalPaginas];
}

/** Escribe el Excel de los registros (con los filtros de la pantalla) en $ruta. */
function escribirExcelConciliador($pdo, array $filtros, $ruta) {
    [$where, $p] = condicionesConciliador($filtros);
    $st = $pdo->prepare("SELECT fc.* FROM formato_conciliador fc {$where} ORDER BY fc.id DESC");
    $st->execute($p);
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Formato Conciliador');
    $hoja->fromArray(['Cons', 'Fecha', 'SKU', 'Descripción', 'Lote', 'Vence', 'Cajas', 'Saldos', 'Total unid.', 'Responsable',
                      'Código responsable', 'Turno', 'Novedades', 'Estado'], null, 'A1');
    $i = 2;
    foreach ($st as $r) {
        $hoja->setCellValue("A{$i}", (int) $r['id']);
        $hoja->setCellValue("B{$i}", date('d/m/Y', strtotime($r['fecha'])));
        $hoja->setCellValueExplicit("C{$i}", (string) $r['sku'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja->setCellValue("D{$i}", (string) $r['descripcion']);
        $hoja->setCellValueExplicit("E{$i}", (string) $r['lote'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja->setCellValue("F{$i}", $r['fecha_vencimiento'] ? date('d/m/Y', strtotime($r['fecha_vencimiento'])) : '');
        $hoja->setCellValue("G{$i}", (int) $r['cajas']);
        $hoja->setCellValue("H{$i}", (int) $r['saldos']);
        $hoja->setCellValue("I{$i}", $r['cantidad'] === null ? '' : (int) $r['cantidad']);
        $hoja->setCellValue("J{$i}", (string) $r['responsable']);
        $hoja->setCellValue("K{$i}", $r['codigo_responsable'] === null ? '' : (int) $r['codigo_responsable']);
        $hoja->setCellValue("L{$i}", $r['turno'] === null ? '' : (int) $r['turno']);
        $hoja->setCellValue("M{$i}", (string) $r['novedades']);
        $hoja->setCellValue("N{$i}", (string) $r['estado']);
        $i++;
    }
    $hoja->getStyle('A1:N1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $hoja->getStyle('A1:N1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
    foreach ([7, 11, 11, 40, 14, 11, 7, 7, 10, 22, 10, 7, 28, 20] as $c => $ancho) {
        $hoja->getColumnDimensionByColumn($c + 1)->setWidth($ancho);
    }
    $hoja->freezePane('A2');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);
}
