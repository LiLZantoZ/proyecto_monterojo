<?php
// modules/rendimiento/model_rendimiento.php
// RENDIMIENTO (2026-10-06): el módulo del sistema de bodega, adaptado a Monterojo. Las mismas
// preguntas sobre historial_actividades (ver config/actividad.php): cuánto se movió el sistema,
// quién, a qué hora, qué días, en qué módulos y qué acciones; y, de una persona, su jornada día
// por día (primera y última entrada, primera y última acción, y qué hizo).
//
// Todo mira una VENTANA de días (7, 30 o 90), no la tabla entera: así usa el índice de fecha y
// responde cómo se trabaja AHORA.
//
// LO QUE CAMBIA RESPECTO DE BODEGA: allá había reportes por rol (Elevador, Notificador). Acá la
// jornada es la misma para todos y suma lo que cuenta en Monterojo: estibas que entraron y salieron
// de Posiciones y registros del Formato Conciliador.

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';   // PhpSpreadsheet, para exportar

const RENDIMIENTO_VENTANAS = [7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días'];
const RENDIMIENTO_DIAS_SEMANA = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
const RENDIMIENTO_MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

/** La ventana pedida, si es una de la lista; si no, 30. */
function ventanaRendimiento($dias) {
    return isset(RENDIMIENTO_VENTANAS[(int) $dias]) ? (int) $dias : 30;
}

/** El WHERE de la ventana (y del usuario, si hay): [sql, parámetros]. Las de entrar y salir no cuentan como trabajo. */
function condicionesRendimiento($dias, $idUsuario = null, $sinAcceso = true) {
    $w = ['ha.fecha_hora >= ?'];
    $p = [date('Y-m-d 00:00:00', strtotime('-' . (ventanaRendimiento($dias) - 1) . ' days'))];
    if ($idUsuario) {
        $w[] = 'ha.id_usuario = ?';
        $p[] = (int) $idUsuario;
    } else {
        $w[] = 'ha.id_usuario IS NOT NULL';
    }
    if ($sinAcceso) {
        $w[] = "ha.modulo <> 'Acceso'";
    }
    return ['WHERE ' . implode(' AND ', $w), $p];
}

function consultaRendimiento($pdo, $sql, array $p) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Los números de cabecera: acciones, personas activas, promedio por persona, errores y días con actividad. */
function resumenRendimiento($pdo, $dias, $idUsuario = null) {
    [$w, $p] = condicionesRendimiento($dias, $idUsuario);
    $f = consultaRendimiento($pdo, "SELECT COUNT(*) AS acciones, COUNT(DISTINCT ha.id_usuario) AS usuarios,
                                           SUM(ha.resultado = 'error') AS errores, COUNT(DISTINCT DATE(ha.fecha_hora)) AS dias_activos
                                      FROM historial_actividades ha {$w}", $p)[0];
    [$wa, $pa] = condicionesRendimiento($dias, $idUsuario, false);
    $entradas = (int) consultaRendimiento($pdo, "SELECT COUNT(*) AS n FROM historial_actividades ha {$wa} AND ha.accion = 'Inició sesión'", $pa)[0]['n'];
    $acciones = (int) $f['acciones'];
    $usuarios = (int) $f['usuarios'];
    return ['acciones' => $acciones, 'usuarios' => $usuarios, 'errores' => (int) $f['errores'], 'dias_activos' => (int) $f['dias_activos'],
            'entradas' => $entradas, 'promedio' => $usuarios ? $acciones / $usuarios : 0];
}

/**
 * El mes en curso contra el anterior, cortando los dos el MISMO día del mes: comparar 6 días de
 * octubre contra el septiembre entero diría siempre que la actividad se desplomó.
 */
function comparativaMensualRendimiento($pdo, $idUsuario = null) {
    $hoy = new DateTime('today');
    $dia = (int) $hoy->format('j');
    $inicioActual = (clone $hoy)->modify('first day of this month');
    $inicioAnterior = (clone $inicioActual)->modify('-1 month');
    $diasAnterior = (int) $inicioAnterior->format('t');
    $corteAnterior = (clone $inicioAnterior)->modify('+' . (min($dia, $diasAnterior)) . ' days');
    $contar = function ($desde, $hasta) use ($pdo, $idUsuario) {
        $sql = "SELECT COUNT(*) AS n FROM historial_actividades WHERE fecha_hora >= ? AND fecha_hora < ? AND modulo <> 'Acceso' AND id_usuario " . ($idUsuario ? '= ' . (int) $idUsuario : 'IS NOT NULL');
        return (int) consultaRendimiento($pdo, $sql, [$desde->format('Y-m-d 00:00:00'), $hasta->format('Y-m-d 00:00:00')])[0]['n'];
    };
    $actual = $contar($inicioActual, (clone $hoy)->modify('+1 day'));
    $anterior = $contar($inicioAnterior, $corteAnterior);
    return ['actual' => $actual, 'anterior' => $anterior, 'dia' => $dia,
            'variacion' => $anterior ? ($actual - $anterior) / $anterior * 100 : null];
}

/** Acciones por día de la ventana, con los días sin actividad en 0: [['fecha', 'n'], …]. */
function porDiaRendimiento($pdo, $dias, $idUsuario = null) {
    [$w, $p] = condicionesRendimiento($dias, $idUsuario);
    $hay = [];
    foreach (consultaRendimiento($pdo, "SELECT DATE(ha.fecha_hora) AS fecha, COUNT(*) AS n, COUNT(DISTINCT ha.id_usuario) AS usuarios
                                          FROM historial_actividades ha {$w} GROUP BY fecha", $p) as $f) {
        $hay[$f['fecha']] = $f;
    }
    $salida = [];
    for ($i = ventanaRendimiento($dias) - 1; $i >= 0; $i--) {
        $fecha = date('Y-m-d', strtotime("-{$i} days"));
        $salida[] = ['fecha' => $fecha, 'n' => (int) ($hay[$fecha]['n'] ?? 0), 'usuarios' => (int) ($hay[$fecha]['usuarios'] ?? 0)];
    }
    return $salida;
}

/** Acciones por hora (0 a 23), con las horas sin actividad en 0: el almuerzo y el fin del turno SON el dato. */
function porHoraRendimiento($pdo, $dias, $idUsuario = null) {
    [$w, $p] = condicionesRendimiento($dias, $idUsuario);
    $horas = array_fill(0, 24, 0);
    foreach (consultaRendimiento($pdo, "SELECT HOUR(ha.fecha_hora) AS h, COUNT(*) AS n FROM historial_actividades ha {$w} GROUP BY h", $p) as $f) {
        $horas[(int) $f['h']] = (int) $f['n'];
    }
    return $horas;
}

/** Las columnas del gráfico por día: [etiqueta, valor, título, mes, columnas del mes]. `mes` va en el
 *  primer día de la ventana y en cada día 1: ahí el gráfico pone la raya divisora y el nombre del mes,
 *  que ocupa justo las columnas de ese mes. Si son pocas, el nombre va abreviado (Oct). */
function serieDiaRendimiento(array $porDia) {
    $variosAnios = count(array_unique(array_map(fn($d) => substr($d['fecha'], 0, 4), $porDia))) > 1;
    $total = count($porDia);
    $serie = [];
    foreach ($porDia as $i => $d) {
        $t = strtotime($d['fecha']);
        $mes = null;
        $columnas = 0;
        if ($i === 0 || date('j', $t) === '1') {
            $columnas = min($total - $i, (int) date('t', $t) - (int) date('j', $t) + 1);
            $nombre = RENDIMIENTO_MESES[(int) date('n', $t) - 1];
            $corto = $columnas < max(4, $total * 0.1);
            $mes = ($corto ? mb_substr($nombre, 0, 3) : $nombre) . ($variosAnios && !$corto ? ' ' . date('Y', $t) : '');
        }
        // Con 90 días no caben todos los números: solo el 1, 8, 15, 22 y 29 (la fecha va en el título).
        $etiqueta = $total <= 31 || in_array((int) date('j', $t), [1, 8, 15, 22, 29], true) ? date('d', $t) : '';
        $serie[] = [$etiqueta, $d['n'], date('d/m/Y', $t) . ' · ' . $d['usuarios'] . ' persona(s)', $mes, $columnas];
    }
    return $serie;
}

/** Las columnas del gráfico por hora, con todas las horas: 00:00, 01:00 … 23:00. */
function serieHoraRendimiento(array $porHora) {
    return array_map(fn($h, $n) => [sprintf('%02d:00', $h), $n, sprintf('%02d:00 a %02d:59', $h, $h), null], array_keys($porHora), $porHora);
}

/** Acciones por día de la semana, de lunes a domingo (WEEKDAY empieza el lunes en 0). */
function porDiaSemanaRendimiento($pdo, $dias, $idUsuario = null) {
    [$w, $p] = condicionesRendimiento($dias, $idUsuario);
    $semana = array_fill(0, 7, 0);
    foreach (consultaRendimiento($pdo, "SELECT WEEKDAY(ha.fecha_hora) AS d, COUNT(*) AS n FROM historial_actividades ha {$w} GROUP BY d", $p) as $f) {
        $semana[(int) $f['d']] = (int) $f['n'];
    }
    return array_combine(RENDIMIENTO_DIAS_SEMANA, $semana);
}

/** Acciones por módulo, de la que más a la que menos: [modulo => n]. */
function porModuloRendimiento($pdo, $dias, $idUsuario = null) {
    [$w, $p] = condicionesRendimiento($dias, $idUsuario);
    return array_column(consultaRendimiento($pdo, "SELECT ha.modulo, COUNT(*) AS n FROM historial_actividades ha {$w} GROUP BY ha.modulo ORDER BY n DESC", $p), 'n', 'modulo');
}

/** Las acciones concretas que más se repiten: QUÉ se hace, no solo dónde. */
function topAccionesRendimiento($pdo, $dias, $idUsuario = null, $limite = 10) {
    [$w, $p] = condicionesRendimiento($dias, $idUsuario);
    return consultaRendimiento($pdo, "SELECT ha.modulo, ha.accion, COUNT(*) AS n FROM historial_actividades ha {$w}
                                       GROUP BY ha.modulo, ha.accion ORDER BY n DESC LIMIT " . (int) $limite, $p);
}

/** Las personas de la ventana, de la más activa a la menos (incluye las que no hicieron nada, en 0). */
function rankingRendimiento($pdo, $dias) {
    [$w, $p] = condicionesRendimiento($dias);
    $act = [];
    foreach (consultaRendimiento($pdo, "SELECT ha.id_usuario, COUNT(*) AS n, SUM(ha.resultado = 'error') AS errores,
                                               COUNT(DISTINCT DATE(ha.fecha_hora)) AS dias, MAX(ha.fecha_hora) AS ultima
                                          FROM historial_actividades ha {$w} GROUP BY ha.id_usuario", $p) as $f) {
        $act[(int) $f['id_usuario']] = $f;
    }
    $filas = [];
    foreach ($pdo->query("SELECT u.id_usuario, u.nombre_usuario, u.estado, r.nombre_rol FROM usuarios u LEFT JOIN roles r ON r.id_rol = u.id_rol") as $u) {
        $a = $act[(int) $u['id_usuario']] ?? null;
        if (!$a && $u['estado'] !== 'Activo') {
            continue;
        }
        $filas[] = $u + ['acciones' => (int) ($a['n'] ?? 0), 'errores' => (int) ($a['errores'] ?? 0), 'dias' => (int) ($a['dias'] ?? 0), 'ultima' => $a['ultima'] ?? null];
    }
    usort($filas, fn($x, $y) => [$y['acciones'], $x['nombre_usuario']] <=> [$x['acciones'], $y['nombre_usuario']]);
    return $filas;
}

/**
 * LA JORNADA de una persona, día por día (el más reciente primero): primera y última vez que entró,
 * primera y última acción (lo que de verdad dice cuánto trabajó: alguien que entró a las 7:36 y no
 * volvió a entrar pudo seguir trabajando hasta las 16:00), cuántas acciones, y lo que cuenta en
 * Monterojo: estibas que entraron y salieron de Posiciones y registros del Formato Conciliador.
 */
function jornadaRendimiento($pdo, $idUsuario, $dias) {
    [$w, $p] = condicionesRendimiento($dias, $idUsuario, false);
    return consultaRendimiento($pdo, "SELECT DATE(ha.fecha_hora) AS fecha,
            MIN(CASE WHEN ha.accion = 'Inició sesión' THEN ha.fecha_hora END) AS primera_entrada,
            MAX(CASE WHEN ha.accion = 'Inició sesión' THEN ha.fecha_hora END) AS ultima_entrada,
            MIN(CASE WHEN ha.modulo <> 'Acceso' THEN ha.fecha_hora END) AS primera_accion,
            MAX(CASE WHEN ha.modulo <> 'Acceso' THEN ha.fecha_hora END) AS ultima_accion,
            SUM(ha.modulo <> 'Acceso') AS acciones,
            SUM(ha.resultado = 'error' AND ha.modulo <> 'Acceso') AS errores,
            SUM(ha.accion = 'Agregó una estiba a una posición' AND ha.resultado = 'exito') AS estibas_entraron,
            SUM(ha.accion IN ('Sacó una estiba de una posición', 'Llevó una estiba a picking') AND ha.resultado = 'exito') AS estibas_salieron,
            SUM(ha.accion = 'Registró en el Formato Conciliador' AND ha.resultado = 'exito') AS registros_conciliador
        FROM historial_actividades ha {$w} GROUP BY fecha ORDER BY fecha DESC", $p);
}

/** Los usuarios para el desplegable: [id => nombre]. */
function usuariosRendimiento($pdo) {
    return $pdo->query("SELECT id_usuario, nombre_usuario FROM usuarios ORDER BY nombre_usuario")->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Escribe el Excel del rendimiento (de todos, o de una persona con su jornada) en $ruta. */
function escribirExcelRendimiento($pdo, $dias, $idUsuario, $ruta) {
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $negrita = function ($rango) use ($hoja) {
        $hoja->getStyle($rango)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hoja->getStyle($rango)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
    };
    $hora = fn($v) => $v ? date('H:i', strtotime($v)) : '';
    if ($idUsuario) {
        $hoja->setTitle('Jornada');
        $hoja->fromArray(['Fecha', 'Primera entrada', 'Última entrada', 'Primera acción', 'Última acción', 'Acciones', 'Errores',
                          'Estibas que entraron', 'Estibas que salieron', 'Registros del conciliador'], null, 'A1');
        $i = 2;
        foreach (jornadaRendimiento($pdo, $idUsuario, $dias) as $d) {
            $hoja->fromArray([date('d/m/Y', strtotime($d['fecha'])), $hora($d['primera_entrada']), $hora($d['ultima_entrada']),
                              $hora($d['primera_accion']), $hora($d['ultima_accion']), (int) $d['acciones'], (int) $d['errores'],
                              (int) $d['estibas_entraron'], (int) $d['estibas_salieron'], (int) $d['registros_conciliador']], null, "A{$i}");
            $i++;
        }
        $negrita('A1:J1');
        foreach (range('A', 'J') as $c) { $hoja->getColumnDimension($c)->setWidth(15); }
    } else {
        $hoja->setTitle('Rendimiento');
        $hoja->fromArray(['Usuario', 'Rol', 'Acciones', 'Días con actividad', 'Errores', 'Última actividad'], null, 'A1');
        $i = 2;
        foreach (rankingRendimiento($pdo, $dias) as $u) {
            $hoja->fromArray([$u['nombre_usuario'], (string) $u['nombre_rol'], $u['acciones'], $u['dias'], $u['errores'],
                              $u['ultima'] ? date('d/m/Y H:i', strtotime($u['ultima'])) : ''], null, "A{$i}");
            $i++;
        }
        $negrita('A1:F1');
        foreach (['A' => 26, 'B' => 18, 'C' => 11, 'D' => 18, 'E' => 10, 'F' => 18] as $c => $a) { $hoja->getColumnDimension($c)->setWidth($a); }
    }
    $hoja->freezePane('A2');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($ruta);
}
