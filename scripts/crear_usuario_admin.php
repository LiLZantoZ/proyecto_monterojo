<?php
// scripts/crear_usuario_admin.php
// Crea el PRIMER usuario Administrador de una instalación nueva.
//
//   C:\xampp\php\php.exe scripts\crear_usuario_admin.php
//
// POR QUÉ EXISTE
// crear_esquema_completo.php deja la base lista pero con CERO usuarios, y no hay pantalla de
// registro: los usuarios los da de alta un administrador desde adentro del sistema, o sea que
// hace falta uno para empezar. Este script es el arranque en frío.
//
// Sirve igual si alguna vez quedan todos los administradores bloqueados o sin contraseña.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo puede ejecutarse desde la línea de comandos.');
}

require_once __DIR__ . '/../config/config.php';

const ROL_ADMINISTRADOR = 1;
const LARGO_MINIMO_CONTRASENA = 8;

function preguntar(string $etiqueta): string {
    echo $etiqueta;
    $linea = fgets(STDIN);
    return $linea === false ? '' : trim($linea);
}

// Lee una contraseña sin mostrarla en pantalla. No hay forma portable de hacerlo en PHP puro, así
// que se intenta lo que corresponde a cada sistema y, si nada funciona, se avisa y se lee a la
// vista — mejor eso que no poder crear el usuario.
function preguntarContrasena(string $etiqueta): string {
    // Si la entrada no viene de una consola sino de una tubería o un archivo
    // (`printf ... | php scripts/crear_usuario_admin.php`, que es como se prueba esto), no hay
    // tecleo que ocultar: hay que leer del STDIN que nos pasaron. Los métodos de abajo abren la
    // CONSOLA, no ese STDIN, así que se quedarían esperando para siempre una tecla que nadie va a
    // apretar. Sin esta comprobación el script se cuelga sin decir por qué.
    if (function_exists('stream_isatty') && !@stream_isatty(STDIN)) {
        return preguntar($etiqueta);
    }

    // Windows: se delega en PowerShell, que sí sabe leer sin eco
    if (strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0) {
        $comando = 'powershell -NoProfile -Command "$c = Read-Host -AsSecureString; '
                 . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
                 . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($c))"';
        echo $etiqueta;
        $salida = @shell_exec($comando);
        if ($salida !== null && $salida !== '') {
            return trim($salida);
        }
        echo "\n";
    } elseif (@shell_exec('stty -echo 2>/dev/null') !== null) {
        echo $etiqueta;
        $valor = trim((string) fgets(STDIN));
        @shell_exec('stty echo');
        echo "\n";
        return $valor;
    }

    echo "  (aviso: no se pudo ocultar el tecleo; la contraseña se va a ver en pantalla)\n";
    return preguntar($etiqueta);
}

function abortar(string $mensaje): void {
    fwrite(STDERR, "\n" . $mensaje . "\n");
    exit(1);
}

echo "== Crear usuario Administrador ==\n\n";

// Si ya hay administradores activos, se avisa: casi siempre significa que el script se está
// corriendo por error sobre una base que ya está en uso.
$yaHay = (int) $pdo->query(
    "SELECT COUNT(*) FROM usuarios WHERE id_rol = " . ROL_ADMINISTRADOR . " AND estado = 'Activo'"
)->fetchColumn();
if ($yaHay > 0) {
    echo "Esta base YA tiene {$yaHay} administrador(es) activo(s).\n";
    if (strtolower(preguntar('¿Crear otro de todos modos? (s/N): ')) !== 's') {
        echo "Cancelado.\n";
        exit(0);
    }
    echo "\n";
}

$cedula = preguntar('Cédula (es el usuario con el que se inicia sesión): ');
if ($cedula === '' || !ctype_digit($cedula)) {
    abortar('La cédula tiene que ser un número, sin puntos ni espacios.');
}

$existe = $pdo->prepare("SELECT nombre_usuario FROM usuarios WHERE cedula_usuario = ?");
$existe->execute([$cedula]);
if ($nombreExistente = $existe->fetchColumn()) {
    abortar("Ya hay un usuario con la cédula {$cedula} ({$nombreExistente}).");
}

$nombreCompleto = preguntar('Nombre completo: ');
if ($nombreCompleto === '') {
    abortar('El nombre completo no puede quedar vacío.');
}

$contrasena = preguntarContrasena('Contraseña (mínimo ' . LARGO_MINIMO_CONTRASENA . " caracteres): ");
if (strlen($contrasena) < LARGO_MINIMO_CONTRASENA) {
    abortar('La contraseña tiene que tener al menos ' . LARGO_MINIMO_CONTRASENA . ' caracteres.');
}
if (preguntarContrasena('Repetir la contraseña: ') !== $contrasena) {
    abortar('Las dos contraseñas no coinciden.');
}

// El hash se genera con password_hash igual que en el alta normal de usuarios: si acá se guardara
// el texto plano, el login (que usa password_verify) nunca lo aceptaría.
$stmt = $pdo->prepare(
    "INSERT INTO usuarios (nombre_usuario, cedula_usuario, contrasena_usuario, id_rol, estado)
     VALUES (:nombre, :cedula, :hash, :rol, 'Activo')"
);
$stmt->execute([
    ':nombre' => $nombreCompleto,
    ':cedula' => $cedula,
    ':hash'   => password_hash($contrasena, PASSWORD_DEFAULT),
    ':rol'    => ROL_ADMINISTRADOR,
]);

echo "\nListo. Administrador creado (id " . $pdo->lastInsertId() . ").\n";
echo "Entrá con la cédula {$cedula} en http://localhost" . URL_LOGIN . "\n";
