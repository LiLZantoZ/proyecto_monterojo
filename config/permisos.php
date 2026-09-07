<?php
// config/permisos.php
// Control de acceso basado en las tablas `permisos` / `rolespermisos`.
//
// La alternativa —que es lo que hacía el sistema de bodega antes de tener esto— era que cada
// controlador comparara $_SESSION['usuario_rol'] contra números a mano, con esa comparación
// duplicada en cada archivo: agregar un rol obligaba a revisar el proyecto entero buscando ifs.
//
// Los permisos del rol se cargan UNA vez, en el login, y quedan cacheados en la sesión, así que
// tienePermiso() es una lectura en memoria y no una consulta por cada request.
//
// Trade-off aceptado: si se edita rolespermisos en caliente, un usuario que ya tenía la sesión
// abierta no ve el cambio hasta volver a entrar. Es el trade-off estándar de cachear en sesión.

// Se llama una sola vez, justo después de fijar $_SESSION['usuario_rol'] en el login.
function cargarPermisosEnSesion($pdo, $idRol) {
    $stmt = $pdo->prepare(
        "SELECT p.nombre_permiso
         FROM rolespermisos rp
         JOIN permisos p ON rp.id_permiso = p.id_permiso
         WHERE rp.id_rol = :id_rol"
    );
    $stmt->execute([':id_rol' => $idRol]);

    $_SESSION['permisos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function tienePermiso(string $nombrePermiso): bool {
    return in_array($nombrePermiso, $_SESSION['permisos'] ?? [], true);
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
