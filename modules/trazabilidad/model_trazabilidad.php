<?php
// modules/trazabilidad/model_trazabilidad.php
// TRAZABILIDAD (2026-10-06): el módulo del sistema de bodega, adaptado a Monterojo. Lista cada
// acción del sistema (historial_actividades): quién, cuándo, en qué módulo, qué hizo y qué le
// contestó el sistema. La tabla la llena sola el enrutador: ver config/actividad.php.
//
// Los LEFT JOIN son a propósito: la acción de una cuenta que después se borró tiene que seguir
// apareciendo ("Usuario eliminado"); de eso se trata una trazabilidad.
// El filtro de mes va por RANGO de fechas (no con DATE_FORMAT sobre la columna), así usa el índice.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';   // PhpSpreadsheet, para exportar

const TRAZABILIDAD_POR_PAGINA = 50;
const TRAZABILIDAD_MESES = ['01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio',
                            '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'];

/** ¿Es un mes 'AAAA-MM' válido? */
function esMesTrazabilidad($mes) {
    return is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1;
}

/** '2026-10' → 'Octubre 2026'. */
function etiquetaMesTrazabilidad($mes) {
    if (!esMesTrazabilidad($mes)) {
        return (string) $mes;
    }
    [$anio, $m] = explode('-', $mes);
    return TRAZABILIDAD_MESES[$m] . ' ' . $anio;
}

const TRAZABILIDAD_SQL_BASE = "FROM historial_actividades ha
                               LEFT JOIN usuarios u ON u.id_usuario = ha.id_usuario
                               LEFT JOIN roles r ON r.id_rol = u.id_rol";

const TRAZABILIDAD_SQL_CAMPOS = "ha.*, CASE WHEN ha.id_usuario IS NULL THEN '—' ELSE COALESCE(u.nombre_usuario, 'Usuario eliminado') END AS nombre_usuario,
                                 COALESCE(r.nombre_rol, '') AS nombre_rol";

/** El WHERE de los filtros: buscar, mes (AAAA-MM), modulo, usuario (id) y resultado. [sql, parámetros]. */
function condicionesTrazabilidad(array $f) {
    $w = []; $p = [];
    if (esMesTrazabilidad($f['mes'] ?? '')) {
        $w[] = 'ha.fecha_hora >= ? AND ha.fecha_hora < ?';
        $p[] = $f['mes'] . '-01 00:00:00';
        $p[] = date('Y-m-01 00:00:00', strtotime($f['mes'] . '-01 +1 month'));
    }
    if (($f['modulo'] ?? '') !== '') {
        $w[] = 'ha.modulo = ?';
        $p[] = $f['modulo'];
    }
    if (($f['usuario'] ?? '') !== '') {
        $w[] = 'ha.id_usuario = ?';
        $p[] = (int) $f['usuario'];
    }
    if (in_array($f['resultado'] ?? '', ['exito', 'error'], true)) {
        $w[] = 'ha.resultado = ?';
        $p[] = $f['resultado'];
    }
    if (($f['buscar'] ?? '') !== '') {
        $t = '%' . $f['buscar'] . '%';
        $w[] = "(u.nombre_usuario LIKE ? OR r.nombre_rol LIKE ? OR ha.modulo LIKE ? OR ha.accion LIKE ? OR ha.detalle LIKE ?)";
        array_push($p, $t, $t, $t, $t, $t);
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

/** Una página de acciones, de la más nueva a la más vieja: ['filas', 'total', 'pagina', 'total_paginas']. */
function paginaTrazabilidad($pdo, array $filtros, $pagina = 1, $porPagina = TRAZABILIDAD_POR_PAGINA) {
    [$where, $p] = condicionesTrazabilidad($filtros);
    $st = $pdo->prepare("SELECT COUNT(*) " . TRAZABILIDAD_SQL_BASE . " {$where}");
    $st->execute($p);
    $total = (int) $st->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, (int) $pagina), $totalPaginas);
    $st = $pdo->prepare("SELECT " . TRAZABILIDAD_SQL_CAMPOS . " " . TRAZABILIDAD_SQL_BASE . " {$where}
        ORDER BY ha.fecha_hora DESC, ha.id DESC LIMIT " . (int) $porPagina . " OFFSET " . (($pagina - 1) * $porPagina));
    $st->execute($p);
    return ['filas' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pagina' => $pagina, 'total_paginas' => $totalPaginas];
}

/** Los meses con actividad, del más nuevo al más viejo: [['mes', 'etiqueta', 'total'], …]. */
function mesesConActividad($pdo) {
    return array_map(fn($m) => ['mes' => $m['mes'], 'etiqueta' => etiquetaMesTrazabilidad($m['mes']), 'total' => (int) $m['total']],
        $pdo->query("SELECT DATE_FORMAT(fecha_hora, '%Y-%m') AS mes, COUNT(*) AS total FROM historial_actividades GROUP BY mes ORDER BY mes DESC")->fetchAll(PDO::FETCH_ASSOC));
}

/** Los módulos y los usuarios que aparecen en la trazabilidad (para los filtros). */
function opcionesFiltrosTrazabilidad($pdo) {
    return [
        'modulos'  => $pdo->query("SELECT DISTINCT modulo FROM historial_actividades ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN),
        'usuarios' => $pdo->query("SELECT DISTINCT u.id_usuario, u.nombre_usuario FROM historial_actividades ha
                                     JOIN usuarios u ON u.id_usuario = ha.id_usuario ORDER BY u.nombre_usuario")->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}

/** Escribe el Excel de las acciones de los filtros (o de todo) en $ruta. */
function escribirExcelTrazabilidad($pdo, array $filtros, $ruta) {
    [$where, $p] = condicionesTrazabilidad($filtros);
    $st = $pdo->prepare("SELECT " . TRAZABILIDAD_SQL_CAMPOS . " " . TRAZABILIDAD_SQL_BASE . " {$where} ORDER BY ha.fecha_hora DESC, ha.id DESC");
    $st->execute($p);
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Trazabilidad');
    $hoja->fromArray(['Fecha y hora', 'Usuario', 'Rol', 'Módulo', 'Acción', 'Detalle', 'Resultado', 'IP'], null, 'A1');
    $i = 2;
    foreach ($st as $r) {
        $hoja->fromArray([date('d/m/Y H:i:s', strtotime($r['fecha_hora'])), $r['nombre_usuario'], $r['nombre_rol'], $r['modulo'],
                          $r['accion'], (string) $r['detalle'], ['exito' => 'Bien', 'error' => 'Error'][$r['resultado']] ?? '', (string) $r['ip']], null, "A{$i}");
        $i++;
    }
    $hoja->getStyle('A1:H1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $hoja->getStyle('A1:H1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
    foreach ([19, 22, 16, 22, 34, 70, 10, 15] as $c => $ancho) {
        $hoja->getColumnDimensionByColumn($c + 1)->setWidth($ancho);
    }
    $hoja->freezePane('A2');
    $hoja->setAutoFilter('A1:H' . max(1, $i - 1));
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);
}
