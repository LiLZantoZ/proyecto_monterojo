<?php
// modules/personal/model_personal.php
// El personal de alistamiento: quiénes arman los pedidos.
//
// Es una lista APARTE de `usuarios` a propósito: el que alista no entra al sistema. Darle un
// usuario solo para poder asignarle un pedido obligaría a crear cuentas con contraseña y rol que
// nadie usa nunca.

require_once __DIR__ . '/../../config/config.php';

/**
 * Todo el personal. $soloActivos deja fuera a los inactivos, que es lo que necesita el modal de
 * asignación: a alguien que ya no está no se le puede asignar un pedido nuevo.
 */
function listarPersonal($pdo, $soloActivos = false) {
    $sql = "SELECT p.id_personal, p.nombre, p.documento, p.cargo, p.estado,
                   -- Cuántas entregas tiene asignadas HOY (en la carga vigente). Se cuentan
                   -- entregas y no líneas: una entrega de seis productos es un solo trabajo.
                   (SELECT COUNT(DISTINCT CONCAT(l.cedi, '|', l.orden_compra, '|', l.punto_venta))
                    FROM consolidado_lineas l
                    WHERE l.id_personal = p.id_personal) AS entregas_asignadas
            FROM personal p";

    if ($soloActivos) {
        $sql .= " WHERE p.estado = 'Activo'";
    }
    $sql .= " ORDER BY p.estado, p.nombre";

    return $pdo->query($sql)->fetchAll();
}

function obtenerPersona($pdo, $idPersonal) {
    $stmt = $pdo->prepare("SELECT * FROM personal WHERE id_personal = :id");
    $stmt->execute([':id' => (int) $idPersonal]);
    return $stmt->fetch() ?: null;
}

/**
 * Da de alta a alguien. Devuelve ['exito' => bool, 'mensaje' => string].
 *
 * El documento vacío se guarda como NULL y no como cadena vacía: la columna tiene índice único, y
 * dos cadenas vacías chocarían entre sí mientras que varios NULL conviven sin problema. Es decir:
 * el documento es opcional para todos, no solo para el primero.
 */
function crearPersona($pdo, $nombre, $documento, $cargo) {
    $nombre = trim((string) $nombre);
    if ($nombre === '') {
        return ['exito' => false, 'mensaje' => 'El nombre no puede quedar vacío.'];
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO personal (nombre, documento, cargo) VALUES (:nombre, :documento, :cargo)"
        );
        $stmt->execute([
            ':nombre'    => mb_substr($nombre, 0, 120),
            ':documento' => trim((string) $documento) === '' ? null : mb_substr(trim($documento), 0, 20),
            ':cargo'     => trim((string) $cargo) === '' ? null : mb_substr(trim($cargo), 0, 60),
        ]);
        return ['exito' => true, 'mensaje' => "Se agregó a {$nombre} al personal."];

    } catch (PDOException $e) {
        // 23000 es la violación de una restricción de integridad; acá solo puede ser el documento
        // repetido, así que se traduce a algo que se entienda en vez del texto de MySQL.
        if ($e->getCode() === '23000') {
            return ['exito' => false, 'mensaje' => 'Ya hay alguien registrado con ese documento.'];
        }
        error_log('Error creando personal: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar. Inténtalo de nuevo.'];
    }
}

function editarPersona($pdo, $idPersonal, $nombre, $documento, $cargo, $estado) {
    $nombre = trim((string) $nombre);
    if ($nombre === '') {
        return ['exito' => false, 'mensaje' => 'El nombre no puede quedar vacío.'];
    }
    if (!in_array($estado, ['Activo', 'Inactivo'], true)) {
        $estado = 'Activo';
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE personal SET nombre = :nombre, documento = :documento, cargo = :cargo, estado = :estado
             WHERE id_personal = :id"
        );
        $stmt->execute([
            ':nombre'    => mb_substr($nombre, 0, 120),
            ':documento' => trim((string) $documento) === '' ? null : mb_substr(trim($documento), 0, 20),
            ':cargo'     => trim((string) $cargo) === '' ? null : mb_substr(trim($cargo), 0, 60),
            ':estado'    => $estado,
            ':id'        => (int) $idPersonal,
        ]);
        return ['exito' => true, 'mensaje' => "Se guardaron los cambios de {$nombre}."];

    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['exito' => false, 'mensaje' => 'Ya hay alguien registrado con ese documento.'];
        }
        error_log('Error editando personal: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo guardar. Inténtalo de nuevo.'];
    }
}

/**
 * Elimina a alguien del personal.
 *
 * Las entregas que tuviera asignadas quedan SIN asignar, no se borran: lo garantiza el
 * ON DELETE SET NULL de la clave foránea. Se avisa cuántas eran, porque alguien tiene que
 * volver a repartirlas.
 */
function eliminarPersona($pdo, $idPersonal) {
    $persona = obtenerPersona($pdo, $idPersonal);
    if (!$persona) {
        return ['exito' => false, 'mensaje' => 'No se encontró esa persona.'];
    }

    try {
        $contar = $pdo->prepare(
            "SELECT COUNT(DISTINCT CONCAT(cedi, '|', orden_compra, '|', punto_venta))
             FROM consolidado_lineas WHERE id_personal = :id"
        );
        $contar->execute([':id' => (int) $idPersonal]);
        $entregas = (int) $contar->fetchColumn();

        $pdo->prepare("DELETE FROM personal WHERE id_personal = :id")->execute([':id' => (int) $idPersonal]);

        $mensaje = "Se eliminó a {$persona['nombre']} del personal.";
        if ($entregas > 0) {
            $mensaje .= " {$entregas} entrega(s) que tenía asignadas quedaron sin asignar.";
        }
        return ['exito' => true, 'mensaje' => $mensaje];

    } catch (PDOException $e) {
        error_log('Error eliminando personal: ' . $e->getMessage());
        return ['exito' => false, 'mensaje' => 'No se pudo eliminar. Inténtalo de nuevo.'];
    }
}

/**
 * Asigna (o quita) el alistador de una ENTREGA.
 *
 * Se escribe en TODAS las líneas de (CEDI, orden de compra, punto de venta), igual que el pedido
 * SAP: quien alista lo hace de la entrega completa, no de un producto suelto.
 *
 * $idPersonal en null quita la asignación.
 */
function asignarPersonalAEntrega($pdo, $idCarga, $cedi, $ordenCompra, $puntoVenta, $idPersonal) {
    try {
        $stmt = $pdo->prepare(
            "UPDATE consolidado_lineas SET id_personal = :personal
             WHERE id_carga = :carga AND cedi = :cedi
               AND orden_compra = :oc AND punto_venta = :pv"
        );
        $stmt->execute([
            ':personal' => $idPersonal === null ? null : (int) $idPersonal,
            ':carga'    => $idCarga,
            ':cedi'     => $cedi,
            ':oc'       => $ordenCompra,
            ':pv'       => $puntoVenta,
        ]);
        return true;

    } catch (PDOException $e) {
        error_log('Error asignando personal: ' . $e->getMessage());
        return false;
    }
}
