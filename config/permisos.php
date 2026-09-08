<?php
// config/permisos.php
// Control de acceso basado en las tablas `permisos` / `rolespermisos`.
//
// La alternativa —que es lo que hacía el sistema de bodega antes de tener esto— era que cada
// controlador comparara $_SESSION['usuario_rol'] contra números a mano, con esa comparación
// duplicada en cada archivo: agregar un rol obligaba a revisar el proyecto entero buscando ifs.
//
// POR QUÉ NO SE CACHEAN EN LA SESIÓN
// Antes los permisos se leían UNA vez, en el login, y quedaban guardados en $_SESSION. Suena
// razonable —una consulta menos por request— pero produce un error que cuesta mucho encontrar:
// se le da un permiso nuevo a un rol, la base queda bien, y el botón sigue sin aparecer porque la
// sesión abierta trae la lista vieja. Pasó exactamente eso con "Gestionar personal" (2026-09-07):
// el permiso estaba creado y asignado, el botón estaba en la vista, y no se veía.
//
// Ahora se leen de la base y se memorizan SOLO durante el request. Es una consulta por página
// contra dos tablas de unas pocas filas, y a cambio un cambio de permisos se aplica al instante,
// sin que nadie tenga que volver a entrar ni sepa que eso hacía falta.

// Los permisos del rol de la sesión, leídos una vez por request.
//
// La variable estática es lo que evita que las ~10 llamadas a tienePermiso() de una pantalla
// (una por entrada del menú, más las de los botones) se conviertan en 10 consultas.
function permisosDelUsuario(): array {
    static $permisos = null;

    if ($permisos !== null) {
        return $permisos;
    }

    $idRol = $_SESSION['usuario_rol'] ?? null;
    if (empty($idRol)) {
        return $permisos = [];
    }

    // $pdo se crea en config/config.php, que ya cargó cualquier pantalla que llegue hasta acá.
    // `global` y no un parámetro porque tienePermiso() se llama desde las vistas, en medio del
    // HTML, donde arrastrar la conexión hasta cada llamada haría el código ilegible.
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return $permisos = [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT p.nombre_permiso
             FROM rolespermisos rp
             JOIN permisos p ON rp.id_permiso = p.id_permiso
             WHERE rp.id_rol = :id_rol"
        );
        $stmt->execute([':id_rol' => $idRol]);

        return $permisos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    } catch (PDOException $e) {
        // Si la consulta falla se responde "sin permisos", no "con todos": ante la duda, la
        // pantalla se cierra en vez de abrirse.
        error_log('Error leyendo los permisos del rol: ' . $e->getMessage());
        return $permisos = [];
    }
}

// Se sigue llamando en el login, justo después de fijar $_SESSION['usuario_rol'].
//
// Ya no guarda nada —los permisos se leen en cada request—, pero comprueba que el rol tenga al
// menos uno: un usuario sin ningún permiso entra a un panel sin módulos y sin ninguna explicación,
// y conviene que eso quede en el log del servidor la primera vez y no cuando llame a preguntar.
function cargarPermisosEnSesion($pdo, $idRol) {
    unset($_SESSION['permisos']);   // limpia la lista cacheada de sesiones viejas

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rolespermisos WHERE id_rol = :id_rol");
    $stmt->execute([':id_rol' => $idRol]);

    if ((int) $stmt->fetchColumn() === 0) {
        error_log("El rol {$idRol} no tiene ningún permiso asignado: quien entre con él no va a ver módulos.");
    }
}

function tienePermiso(string $nombrePermiso): bool {
    return in_array($nombrePermiso, permisosDelUsuario(), true);
}

// Patrón que se repite en cada controlador: si falta el permiso, redirige con
// ?error=acceso_denegado y corta la ejecución. Centralizarlo evita el if/header/exit copiado a
// mano en cada archivo, que es donde se cuelan los que se olvidan del exit() y siguen ejecutando
// la acción que acababan de "prohibir".
function requierePermiso(string $nombrePermiso, string $redirectUrl) {
    if (!tienePermiso($nombrePermiso)) {
        header("Location: " . $redirectUrl . (str_contains($redirectUrl, '?') ? '&' : '?') . "error=acceso_denegado");
        exit();
    }
}
