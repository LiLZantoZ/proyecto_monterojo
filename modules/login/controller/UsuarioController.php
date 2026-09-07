<?php
// modules/login/controller/UsuarioController.php
// Recibe el POST del formulario, valida y abre la sesión. No imprime NADA: termina siempre en una
// redirección, así que un refresco después de entrar no reenvía el formulario.
//
// config.php se carga antes de session_start() para que los parámetros de cookie segura que
// define alcancen a aplicarse; al revés, la sesión ya estaría abierta con la cookie por defecto.
require_once __DIR__ . '/../../../config/config.php';
session_start();
require_once __DIR__ . '/../../../config/login_rate_limit.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model/UsuarioModel.php';

// Solo se procesa el POST del formulario. Entrar a esta URL con el navegador devuelve al login en
// vez de mostrar una página en blanco.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . URL_LOGIN);
    exit();
}

validarCSRF();

$usuarioModel = new UsuarioModel($pdo);

// Primera capa de bloqueo por fuerza bruta: por IP.
$ip = obtenerIpCliente();
$bloqueo = verificarBloqueoLogin($pdo, $ip);
if ($bloqueo['bloqueado']) {
    header("Location: " . URL_LOGIN . "?error=demasiados_intentos&minutos=" . $bloqueo['minutos_restantes']);
    exit();
}

$cedula     = trim($_POST['cedula_usuario'] ?? '');
$contrasena = trim($_POST['contrasena_usuario'] ?? '');

if ($cedula === '' || $contrasena === '') {
    header("Location: " . URL_LOGIN . "?error=campos_vacios");
    exit();
}

// Segunda capa: por cuenta. Protege contra la fuerza bruta dirigida a UNA cédula desde IPs
// rotativas, que el bloqueo por IP no llegaría a ver nunca.
$bloqueoCuenta = verificarBloqueoLoginCuenta($pdo, $cedula);
if ($bloqueoCuenta['bloqueado']) {
    header("Location: " . URL_LOGIN . "?error=demasiados_intentos&minutos=" . $bloqueoCuenta['minutos_restantes']);
    exit();
}

$usuario = $usuarioModel->obtenerPorCedula($cedula);

if ($usuario && password_verify($contrasena, $usuario['contrasena_usuario'])) {

    // La cuenta inactiva se comprueba DESPUÉS de verificar la contraseña, no antes: si se
    // revisara primero, el sistema estaría confirmando qué cédulas existen a quien no conoce
    // ninguna contraseña.
    if ($usuario['estado'] !== 'Activo') {
        header("Location: " . URL_LOGIN . "?error=usuario_inactivo");
        exit();
    }

    // Un usuario sin rol no puede entrar: sin rol no hay permisos, así que llegaría a un panel
    // sin un solo módulo y sin ninguna explicación.
    if (empty($usuario['id_rol'])) {
        header("Location: " . URL_LOGIN . "?error=rol_no_permitido");
        exit();
    }

    resetearIntentosLogin($pdo, $ip);
    resetearIntentosLoginCuenta($pdo, $cedula);

    // Contra la fijación de sesión: el identificador con el que se navegaba ANTES de
    // autenticarse deja de ser válido, así que uno preexistente no queda convertido en una
    // sesión con permisos.
    session_regenerate_id(true);

    $_SESSION['usuario_id']       = $usuario['id_usuario'];
    $_SESSION['usuario_nombre']   = $usuario['nombre_usuario'];
    $_SESSION['usuario_rol']      = $usuario['id_rol'];
    $_SESSION['nombre_rol']       = $usuario['nombre_rol'];
    $_SESSION['usuario_imagen']   = $usuario['imagen_url_Usuario'] ?? null;
    $_SESSION['ultima_actividad'] = time();

    // Los permisos del rol se leen UNA vez y quedan en la sesión (ver config/permisos.php)
    cargarPermisosEnSesion($pdo, $usuario['id_rol']);

    $usuarioModel->actualizarUltimoAcceso($usuario['id_usuario']);

    header("Location: " . urlPanelDelRol($usuario['id_rol']));
    exit();
}

// Un solo mensaje para "esa cédula no existe" y "esa contraseña no es": distinguirlos le diría a
// quien prueba al azar cuáles de sus intentos son cédulas reales.
registrarIntentoFallidoLogin($pdo, $ip);
registrarIntentoFallidoLoginCuenta($pdo, $cedula);
header("Location: " . URL_LOGIN . "?error=credenciales_incorrectas");
exit();
