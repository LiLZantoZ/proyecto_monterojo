<?php
// config/actividad.php
// EL REGISTRO DE ACTIVIDAD (2026-10-06): de dónde salen los módulos Trazabilidad y Rendimiento,
// traídos del sistema de bodega.
//
// En bodega cada controlador llamaba a registrarRendimientoAutomatico() al empezar y después
// completaba el detalle a mano. Acá se hace UNA vez, en el enrutador (index.php): toda acción que
// pasa por una dirección del sistema queda registrada sola, sin tocar los controladores.
//
// QUÉ SE REGISTRA
//   · Los POST (formularios y pedidos de las pantallas): importar, guardar, borrar, despachar…
//   · Las descargas por GET (PDF, Excel): las que llevan ?accion=.
//   · Entrar y salir del sistema (también los intentos fallidos, sin la contraseña).
// QUÉ NO: abrir una pantalla, las imágenes de los códigos de barras y QR, las consultas que solo
// leen (obtener_…, buscar_…) y el agente de impresión, que pregunta cada pocos segundos.
//
// EL DETALLE sale de lo que el sistema le contestó a la persona: el mensaje verde o rojo de la
// pantalla ("Estiba agregada en R1M1N1A1: SKU 36305…"), el error de un pedido JSON o el nombre del
// archivo descargado. Así el registro dice exactamente lo que vio quien hizo la acción.

// Las direcciones que nunca se registran.
const ACTIVIDAD_RUTAS_IGNORADAS = ['rotulos/agente', 'rotulos/enlaces'];

// El nombre del módulo de cada dirección (por su primera parte).
const ACTIVIDAD_MODULOS = [
    'login' => 'Acceso', 'salir' => 'Acceso', 'consolidados' => 'Consolidados', 'maestro' => 'Maestro de productos',
    'picking' => 'Picking', 'personal' => 'Personal', 'historial' => 'Historial de pedidos',
    'cajas-punto-venta' => 'Cajas por punto de venta', 'rotulos' => 'Rótulos', 'ordenes-compra' => 'Órdenes de compra',
    'consolidado-mr' => 'Consolidado MR', 'enlazar-facturas' => 'Enlazar facturas', 'pedidos' => 'Pedidos',
    'posiciones' => 'Posiciones', 'perfil' => 'Perfil', 'productos' => 'Productos', 'formato-conciliador' => 'Formato Conciliador',
    'notificaciones' => 'Notificaciones', 'trazabilidad' => 'Trazabilidad', 'rendimiento' => 'Rendimiento',
    'usuarios' => 'Usuarios', 'chatbot' => 'Asistente',
    'seguimiento' => 'Estado de pedidos',
];

// Lo que significa cada acción, en palabras. Primero se busca "módulo:acción" y después la acción sola.
const ACTIVIDAD_ACCIONES = [
    // Consolidados y maestro
    'importar_consolidado' => 'Importó un consolidado', 'resolver_duplicado' => 'Resolvió un consolidado repetido',
    'importar_maestro' => 'Importó el maestro de productos', 'importar_maestro_sap' => 'Importó el maestro de SAP',
    'importar_maestro_exito' => 'Importó las excepciones del Éxito', 'eliminar_excepcion_exito' => 'Eliminó una excepción del Éxito',
    'eliminar_todo' => 'Borró todos los consolidados', 'pdf' => 'Descargó un PDF', 'pdf_externo' => 'Descargó un PDF externo',
    'pdf_seleccion' => 'Descargó un PDF de la selección', 'pdf_masivo' => 'Descargó varios PDF', 'pdf_reporte' => 'Descargó un reporte en PDF',
    'rotulos_pdf' => 'Descargó rótulos en PDF',
    // Picking e historial
    'guardar_pedido_sap' => 'Guardó el pedido de SAP', 'asignar_personal' => 'Asignó personal a un pedido',
    'despachar_pedidos' => 'Despachó pedidos', 'imprimir_rotulos' => 'Mandó a imprimir rótulos',
    'restaurar' => 'Restauró un pedido', 'restaurar_masivo' => 'Restauró varios pedidos',
    // Varios
    'subir_almacenes' => 'Subió la lista de almacenes', 'guardar_vehiculos' => 'Guardó los vehículos', 'subir_cubicajes' => 'Subió los cubicajes',
    'enlazar' => 'Enlazó una factura', 'quitar' => 'Quitó un enlace de factura',
    'actualizar_transportadoras' => 'Actualizó las transportadoras', 'contado' => 'Marcó facturas de contado',
    'subir_comparativa' => 'Subió la comparativa de contado', 'subir_contado' => 'Subió las facturas de contado',
    'transportador' => 'Asignó transportador', 'estado_manual' => 'Cambió a mano el estado o la entrega de una factura', 'subir_pedidos' => 'Subió los pedidos', 'subir_inventario' => 'Subió un inventario',
    'actualizar_datos' => 'Actualizó sus datos', 'cambiar_contrasena' => 'Cambió su contraseña', 'subir_imagen' => 'Cambió su foto de perfil',
    'exportar' => 'Exportó a Excel', 'exportar_excel' => 'Exportó a Excel',
    // Personal
    'Personal:crear' => 'Registró una persona del personal', 'Personal:editar' => 'Editó una persona del personal', 'Personal:eliminar' => 'Eliminó una persona del personal',
    // Pedidos y consolidado MR
    'Pedidos:vaciar' => 'Vació la tabla de pedidos', 'Consolidado MR:vaciar' => 'Vació el Consolidado MR', 'Consolidado MR:importar' => 'Importó el Consolidado MR',
    // Posiciones
    'Posiciones:crear' => 'Creó una posición', 'Posiciones:actualizar' => 'Editó una posición', 'Posiciones:eliminar' => 'Eliminó una posición',
    'Posiciones:importar' => 'Importó posiciones', 'agregar_estiba' => 'Agregó una estiba a una posición',
    'actualizar_estiba' => 'Editó los datos de una estiba', 'sacar_estiba' => 'Sacó una estiba de una posición',
    'llevar_a_picking' => 'Llevó una estiba a picking',
    // Productos, conciliador, notificaciones, trazabilidad
    'Productos:crear' => 'Registró un producto', 'Productos:editar' => 'Editó un producto', 'Productos:eliminar' => 'Eliminó un producto',
    'Formato Conciliador:crear' => 'Registró en el Formato Conciliador', 'Formato Conciliador:eliminar' => 'Eliminó un registro del Formato Conciliador',
    // Administrar usuarios
    'Usuarios:crear' => 'Creó un usuario', 'Usuarios:editar' => 'Editó un usuario', 'Usuarios:eliminar' => 'Eliminó un usuario',
    'backup' => 'Descargó un backup de la base de datos',
    // El asistente: las preguntas no se anotan (son consultas); lo que se ejecuta desde su menú, sí.
    'Asistente:ejecutar_accion' => 'Ejecutó una acción desde el asistente', 'Asistente:limpiar' => 'Borró su conversación con el asistente',
    'marcar_leida' => 'Marcó una notificación como leída', 'marcar_todas' => 'Marcó todas sus notificaciones como leídas',
];

/** Las acciones que solo leen y no se registran (empiezan así). */
function actividadEsSoloLectura($accion) {
    return $accion !== '' && preg_match('/^(obtener|buscar|consultar|estadisticas|codigo_barras|qr$|kardex)/', $accion) === 1;
}

/** La acción en palabras ("Agregó una estiba a una posición"). */
function describirAccionActividad($modulo, $accion) {
    if ($accion === '') {
        return 'Realizó una acción en ' . $modulo;
    }
    return ACTIVIDAD_ACCIONES[$modulo . ':' . $accion] ?? ACTIVIDAD_ACCIONES[$accion]
        ?? 'Realizó la acción "' . str_replace('_', ' ', $accion) . '"';
}

/** Guarda una actividad. Nunca rompe la acción que la generó: si falla, solo queda en el log. */
function registrarActividad($pdo, $idUsuario, $modulo, $accion, $detalle = null, $resultado = '', $ruta = null) {
    try {
        $pdo->prepare("INSERT INTO historial_actividades (id_usuario, modulo, accion, detalle, resultado, ruta, ip)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$idUsuario ?: null, mb_substr((string) $modulo, 0, 60), mb_substr((string) $accion, 0, 150),
                       $detalle === null || $detalle === '' ? null : mb_substr((string) $detalle, 0, 2000),
                       in_array($resultado, ['exito', 'error'], true) ? $resultado : '',
                       $ruta === null ? null : mb_substr((string) $ruta, 0, 80),
                       mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('No se pudo registrar la actividad: ' . $e->getMessage());
        return null;
    }
}

/** Lo que el sistema le contestó a la persona: [detalle, resultado]. */
function respuestaDeLaPeticionActividad(array $flashAntes, $salida, array $cabeceras) {
    // 1) El mensaje verde o rojo de la pantalla, si esta acción dejó uno nuevo.
    $flash = $_SESSION['mensaje_flash'] ?? null;
    if (is_array($flash) && $flash !== ($flashAntes['mensaje_flash'] ?? null)) {
        $texto = $flash['texto'] ?? (function_exists('textoMensajeSistema') && isset($flash['codigo'])
            ? textoMensajeSistema($flash['tipo'] ?? '', $flash['codigo']) : null);
        return [$texto, ($flash['tipo'] ?? '') === 'error' ? 'error' : 'exito'];
    }
    // 2) Una descarga: el nombre del archivo.
    foreach ($cabeceras as $c) {
        if (stripos($c, 'Content-Disposition:') === 0 && preg_match('/filename="?([^";]+)/i', $c, $m)) {
            return ['Archivo: ' . $m[1], 'exito'];
        }
    }
    // 3) Sin permiso: el sistema rebotó con ?error=acceso_denegado.
    foreach ($cabeceras as $c) {
        if (stripos($c, 'Location:') === 0 && str_contains($c, 'error=acceso_denegado')) {
            return ['No tenía permiso para hacerlo.', 'error'];
        }
    }
    // 4) Un pedido JSON de una pantalla: el error, o lo que contó.
    $json = json_decode(trim((string) $salida), true);
    if (is_array($json) && array_key_exists('exito', $json)) {
        if (!$json['exito']) {
            return [is_string($json['error'] ?? null) ? $json['error'] : (is_string($json['mensaje'] ?? null) ? $json['mensaje'] : 'No se pudo hacer.'), 'error'];
        }
        if (is_string($json['mensaje'] ?? null)) {
            return [$json['mensaje'], 'exito'];
        }
        $partes = [];
        foreach ($json as $clave => $valor) {
            if ($clave !== 'exito' && is_scalar($valor) && $valor !== '' && !is_bool($valor)) {
                $partes[] = str_replace('_', ' ', $clave) . ': ' . $valor;
            }
        }
        return [$partes ? ucfirst(implode(' · ', array_slice($partes, 0, 6))) : null, 'exito'];
    }
    return [null, ''];
}

/**
 * Lo llama el enrutador ANTES de atender una dirección de la tabla. Si la petición es de las que se
 * registran, se anota al terminar (con lo que se le contestó a la persona).
 */
function iniciarRegistroDeActividad($ruta) {
    if (in_array($ruta, ACTIVIDAD_RUTAS_IGNORADAS, true)) {
        return;
    }
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $esLogin = $ruta === 'login/entrar';
    $esSalir = $ruta === 'salir';

    // La acción: del formulario, de la dirección o del cuerpo JSON de los pedidos de las pantallas.
    $accion = (string) ($_POST['accion'] ?? $_GET['accion'] ?? '');
    if ($accion === '' && $metodo === 'POST' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
        $cuerpo = json_decode((string) file_get_contents('php://input'), true);
        $accion = is_array($cuerpo) ? (string) ($cuerpo['accion'] ?? '') : '';
    }
    $accion = preg_replace('/[^a-z0-9_]/i', '', $accion);

    if (!$esLogin && !$esSalir) {
        if ($metodo !== 'POST' && $accion === '') {
            return;   // abrir una pantalla
        }
        if (actividadEsSoloLectura($accion)) {
            return;
        }
        // Las preguntas al asistente son consultas: solo se anota lo que ejecuta su menú de acciones.
        if ($ruta === 'chatbot/acciones' && in_array($accion, ['enviar', 'historial', 'acciones'], true)) {
            return;
        }
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $usuarioAntes = $_SESSION['usuario_id'] ?? null;
    if (!$usuarioAntes && !$esLogin) {
        return;   // sin sesión: el sistema lo manda al login, no hizo nada
    }
    $flashAntes = ['mensaje_flash' => $_SESSION['mensaje_flash'] ?? null];
    $cedula = $esLogin ? preg_replace('/\D/', '', (string) ($_POST['cedula_usuario'] ?? '')) : '';
    $motivo = (string) ($_GET['motivo'] ?? '');

    // Se guarda una copia del comienzo de lo que se le contesta (para leer los JSON), sin frenar
    // las descargas grandes: lo demás pasa de largo.
    $GLOBALS['__actividadSalida'] = '';
    ob_start(function ($trozo) {
        if (strlen($GLOBALS['__actividadSalida']) < 65536) {
            $GLOBALS['__actividadSalida'] .= substr($trozo, 0, 65536);
        }
        return $trozo;
    }, 8192);

    register_shutdown_function(function () use ($ruta, $accion, $usuarioAntes, $flashAntes, $esLogin, $esSalir, $cedula, $motivo) {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        global $pdo;
        if (!$pdo instanceof PDO) {
            return;
        }
        $modulo = ACTIVIDAD_MODULOS[explode('/', $ruta)[0]] ?? 'Sistema';
        if ($esLogin) {
            $ahora = $_SESSION['usuario_id'] ?? null;
            if ($ahora && $ahora != $usuarioAntes) {
                registrarActividad($pdo, $ahora, 'Acceso', 'Inició sesión', null, 'exito', $ruta);
            } else {
                registrarActividad($pdo, null, 'Acceso', 'Intento fallido de inicio de sesión',
                    $cedula !== '' ? 'Cédula ' . $cedula : null, 'error', $ruta);
            }
            return;
        }
        if ($esSalir) {
            registrarActividad($pdo, $usuarioAntes, 'Acceso', $motivo === 'inactividad' ? 'Se cerró la sesión por inactividad' : 'Cerró sesión', null, 'exito', $ruta);
            return;
        }
        [$detalle, $resultado] = respuestaDeLaPeticionActividad($flashAntes, $GLOBALS['__actividadSalida'] ?? '', headers_list());
        registrarActividad($pdo, $usuarioAntes, $modulo, describirAccionActividad($modulo, $accion), $detalle, $resultado, $ruta);
    });
}
