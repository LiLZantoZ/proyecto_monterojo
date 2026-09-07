<?php
// modules/login/model/UsuarioModel.php
// Acceso a la tabla `usuarios` para lo que necesita el inicio de sesión.

require_once __DIR__ . '/../../../config/config.php';

class UsuarioModel {
    private $db;

    public function __construct($pdo) {
        $this->db = $pdo;
    }

    // El LEFT JOIN (y no un JOIN a secas) es a propósito: un usuario sin rol asignado tiene que
    // aparecer igual, para que el login lo rechace con "rol no permitido" en vez de con
    // "credenciales incorrectas", que mandaría a la persona a probar contraseñas que sí son
    // correctas.
    public function obtenerPorCedula($cedula) {
        $sql = "SELECT u.*, r.nombre_rol
                FROM usuarios u
                LEFT JOIN roles r ON u.id_rol = r.id_rol
                WHERE u.cedula_usuario = :cedula LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':cedula' => $cedula]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function crearUsuario($cedula, $nombre, $contrasenaPlana, $idRol) {
        $contrasenaHash = password_hash($contrasenaPlana, PASSWORD_DEFAULT);

        $sql = "INSERT INTO usuarios (cedula_usuario, nombre_usuario, contrasena_usuario, id_rol, estado)
                VALUES (:cedula, :nombre, :contrasena, :rol, 'Activo')";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':cedula'     => $cedula,
            ':nombre'     => $nombre,
            ':contrasena' => $contrasenaHash,
            ':rol'        => $idRol
        ]);
    }

    public function actualizarUltimoAcceso($id_usuario) {
        $sql = "UPDATE usuarios SET fecha_ultimo_acceso = NOW() WHERE id_usuario = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id_usuario]);
    }
}
