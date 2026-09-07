<?php
// config/login_rate_limit.php
// Bloquea intentos de inicio de sesión por fuerza bruta: 5 intentos fallidos
// desde la misma IP bloquean esa IP por 15 minutos.
//
// Toda la comparación de tiempos se hace dentro de MySQL (NOW(), INTERVAL) en vez de con
// DateTime de PHP: la zona horaria configurada en PHP puede no coincidir con la del reloj
// del sistema que usa MySQL, y mezclarlas produce comparaciones incorrectas.

const LOGIN_MAX_INTENTOS = 5;
const LOGIN_MINUTOS_BLOQUEO = 15;
const LOGIN_MINUTOS_VENTANA = 15; // tras este tiempo sin intentos, el contador se reinicia

function obtenerIpCliente() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Devuelve ['bloqueado' => bool, 'minutos_restantes' => int]
function verificarBloqueoLogin($pdo, $ip) {
    try {
        $stmt = $pdo->prepare(
            "SELECT
                (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS esta_bloqueado,
                TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) AS segundos_restantes
             FROM intentos_login WHERE ip_address = :ip LIMIT 1"
        );
        $stmt->execute([':ip' => $ip]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila || !$fila['esta_bloqueado']) {
            return ['bloqueado' => false, 'minutos_restantes' => 0];
        }

        $minutosRestantes = max(1, (int) ceil($fila['segundos_restantes'] / 60));
        return ['bloqueado' => true, 'minutos_restantes' => $minutosRestantes];
    } catch (PDOException $e) {
        error_log("Error en verificarBloqueoLogin: " . $e->getMessage());
        return ['bloqueado' => false, 'minutos_restantes' => 0];
    }
}

// Registra un intento fallido; si supera el máximo, bloquea la IP por LOGIN_MINUTOS_BLOQUEO
function registrarIntentoFallidoLogin($pdo, $ip) {
    try {
        $stmt = $pdo->prepare(
            "SELECT intentos,
                    (ultimo_intento < NOW() - INTERVAL " . LOGIN_MINUTOS_VENTANA . " MINUTE) AS ventana_expirada
             FROM intentos_login WHERE ip_address = :ip LIMIT 1"
        );
        $stmt->execute([':ip' => $ip]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {
            $ins = $pdo->prepare("INSERT INTO intentos_login (ip_address, intentos, primer_intento, ultimo_intento) VALUES (:ip, 1, NOW(), NOW())");
            $ins->execute([':ip' => $ip]);
            return;
        }

        if ($fila['ventana_expirada']) {
            // Ya pasó la ventana de tiempo: se reinicia el contador en vez de acumular intentos viejos
            $upd = $pdo->prepare("UPDATE intentos_login SET intentos = 1, primer_intento = NOW(), ultimo_intento = NOW(), bloqueado_hasta = NULL WHERE ip_address = :ip");
            $upd->execute([':ip' => $ip]);
            return;
        }

        $nuevosIntentos = (int) $fila['intentos'] + 1;

        if ($nuevosIntentos >= LOGIN_MAX_INTENTOS) {
            $upd = $pdo->prepare(
                "UPDATE intentos_login
                 SET intentos = :intentos, ultimo_intento = NOW(), bloqueado_hasta = NOW() + INTERVAL " . LOGIN_MINUTOS_BLOQUEO . " MINUTE
                 WHERE ip_address = :ip"
            );
        } else {
            $upd = $pdo->prepare(
                "UPDATE intentos_login
                 SET intentos = :intentos, ultimo_intento = NOW()
                 WHERE ip_address = :ip"
            );
        }
        $upd->execute([':intentos' => $nuevosIntentos, ':ip' => $ip]);
    } catch (PDOException $e) {
        error_log("Error en registrarIntentoFallidoLogin: " . $e->getMessage());
    }
}

// Se llama cuando el login es exitoso, para limpiar el contador de esa IP
function resetearIntentosLogin($pdo, $ip) {
    try {
        $stmt = $pdo->prepare("DELETE FROM intentos_login WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
    } catch (PDOException $e) {
        error_log("Error en resetearIntentosLogin: " . $e->getMessage());
    }
}

// --- Segunda capa de bloqueo: por cuenta (cédula), no solo por IP ---
// Sin esto, un atacante con IPs rotativas podría intentar fuerza bruta indefinida
// contra UNA cédula específica sin activar nunca el bloqueo por IP.
// Mismos umbrales y misma lógica que las funciones de arriba, solo que la clave es la cédula.

// Devuelve ['bloqueado' => bool, 'minutos_restantes' => int]
function verificarBloqueoLoginCuenta($pdo, $cedula) {
    try {
        $stmt = $pdo->prepare(
            "SELECT
                (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS esta_bloqueado,
                TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) AS segundos_restantes
             FROM intentos_login_cuenta WHERE cedula_usuario = :cedula LIMIT 1"
        );
        $stmt->execute([':cedula' => $cedula]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila || !$fila['esta_bloqueado']) {
            return ['bloqueado' => false, 'minutos_restantes' => 0];
        }

        $minutosRestantes = max(1, (int) ceil($fila['segundos_restantes'] / 60));
        return ['bloqueado' => true, 'minutos_restantes' => $minutosRestantes];
    } catch (PDOException $e) {
        error_log("Error en verificarBloqueoLoginCuenta: " . $e->getMessage());
        return ['bloqueado' => false, 'minutos_restantes' => 0];
    }
}

// Registra un intento fallido; si supera el máximo, bloquea la cuenta por LOGIN_MINUTOS_BLOQUEO
function registrarIntentoFallidoLoginCuenta($pdo, $cedula) {
    try {
        $stmt = $pdo->prepare(
            "SELECT intentos,
                    (ultimo_intento < NOW() - INTERVAL " . LOGIN_MINUTOS_VENTANA . " MINUTE) AS ventana_expirada
             FROM intentos_login_cuenta WHERE cedula_usuario = :cedula LIMIT 1"
        );
        $stmt->execute([':cedula' => $cedula]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {
            $ins = $pdo->prepare("INSERT INTO intentos_login_cuenta (cedula_usuario, intentos, primer_intento, ultimo_intento) VALUES (:cedula, 1, NOW(), NOW())");
            $ins->execute([':cedula' => $cedula]);
            return;
        }

        if ($fila['ventana_expirada']) {
            $upd = $pdo->prepare("UPDATE intentos_login_cuenta SET intentos = 1, primer_intento = NOW(), ultimo_intento = NOW(), bloqueado_hasta = NULL WHERE cedula_usuario = :cedula");
            $upd->execute([':cedula' => $cedula]);
            return;
        }

        $nuevosIntentos = (int) $fila['intentos'] + 1;

        if ($nuevosIntentos >= LOGIN_MAX_INTENTOS) {
            $upd = $pdo->prepare(
                "UPDATE intentos_login_cuenta
                 SET intentos = :intentos, ultimo_intento = NOW(), bloqueado_hasta = NOW() + INTERVAL " . LOGIN_MINUTOS_BLOQUEO . " MINUTE
                 WHERE cedula_usuario = :cedula"
            );
        } else {
            $upd = $pdo->prepare(
                "UPDATE intentos_login_cuenta
                 SET intentos = :intentos, ultimo_intento = NOW()
                 WHERE cedula_usuario = :cedula"
            );
        }
        $upd->execute([':intentos' => $nuevosIntentos, ':cedula' => $cedula]);
    } catch (PDOException $e) {
        error_log("Error en registrarIntentoFallidoLoginCuenta: " . $e->getMessage());
    }
}

// Se llama cuando el login es exitoso, para limpiar el contador de esa cuenta
function resetearIntentosLoginCuenta($pdo, $cedula) {
    try {
        $stmt = $pdo->prepare("DELETE FROM intentos_login_cuenta WHERE cedula_usuario = :cedula");
        $stmt->execute([':cedula' => $cedula]);
    } catch (PDOException $e) {
        error_log("Error en resetearIntentosLoginCuenta: " . $e->getMessage());
    }
}
?>
