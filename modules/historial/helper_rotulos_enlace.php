<?php
// modules/historial/helper_rotulos_enlace.php
// El enlace que va dentro del QR de cada rótulo, y su vuelta: del token a los datos.
//
// Para qué sirve: la etiqueta impresa NO muestra el EAN del producto (decisión del usuario el
// 2026-09-11). Quien necesita ese dato en el muelle escanea el QR con el celular y se le abre una
// página con el rótulo completo, el EAN incluido.
//
// POR QUÉ UN TOKEN CORTO Y NO LOS DATOS DENTRO DEL QR
// Medido con datos reales: metiendo el rótulo entero en el código, el QR queda de 61x61 módulos
// —23mm de lado a la densidad mínima que un lector engancha—. Con un token de 12 caracteres baja a
// 33x33, o sea 12,4mm. En una etiqueta donde el QR comparte renglón con el código de barras, esa
// diferencia es la que decide si entra o no.
//
// POR QUÉ EL TOKEN ES LARGO Y AL AZAR
// La página se abre SIN iniciar sesión: el que escanea en el muelle no va a tipear usuario y
// contraseña en el celular. Entonces lo único que separa esos datos de cualquiera conectado al
// mismo WiFi es que la dirección no se pueda adivinar. 12 caracteres de un alfabeto de 62 son
// 62^12 combinaciones (unas 3.000.000.000.000.000.000.000): probar direcciones al azar no lleva a
// ningún lado.

require_once __DIR__ . '/../../config/config.php';

// Sin mayúsculas y minúsculas ambiguas no hace falta: el token no se tipea a mano, se escanea.
const ROTULO_TOKEN_ALFABETO = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
const ROTULO_TOKEN_LARGO    = 12;

/**
 * Los campos del rótulo que viajan al celular. Se fija la lista —y su orden— para que la huella
 * sea estable: si dependiera de cómo venga armado el arreglo, el mismo rótulo daría huellas
 * distintas y se crearía un token nuevo en cada impresión.
 */
function datosDeRotuloParaEnlace(array $r) {
    return [
        'pv'       => (string) ($r['pv'] ?? ''),
        'numero_pv'=> (string) ($r['numero_pv'] ?? ''),
        'cedi'     => (string) ($r['cedi'] ?? ''),
        'producto' => (string) ($r['producto'] ?? ''),
        'sku'      => (string) ($r['sku'] ?? ''),
        'ean'      => (string) ($r['ean'] ?? ''),
        'numero'   => (int) ($r['numero'] ?? 1),
        'total'    => (int) ($r['total'] ?? 1),
    ];
}

/**
 * El token de un rótulo: el que ya tenía si se reimprime, o uno nuevo.
 *
 * Devuelve el token, o null si no se pudo guardar. Que falle NO puede impedir imprimir: el rótulo
 * sale igual, solo que sin QR.
 */
function tokenDeRotulo($pdo, array $rotulo) {
    $datos  = datosDeRotuloParaEnlace($rotulo);
    $huella = hash('sha256', json_encode($datos, JSON_UNESCAPED_UNICODE));

    try {
        $existente = $pdo->prepare("SELECT token FROM rotulos_enlace WHERE huella = ?");
        $existente->execute([$huella]);
        $token = $existente->fetchColumn();
        if ($token !== false) {
            return $token;
        }

        // random_int y no rand(): este número es lo único que protege la página, así que tiene
        // que venir del generador criptográfico del sistema y no de uno predecible.
        $token = '';
        for ($i = 0; $i < ROTULO_TOKEN_LARGO; $i++) {
            $token .= ROTULO_TOKEN_ALFABETO[random_int(0, strlen(ROTULO_TOKEN_ALFABETO) - 1)];
        }

        $pdo->prepare("INSERT INTO rotulos_enlace (token, huella, datos) VALUES (?, ?, ?)")
            ->execute([$token, $huella, json_encode($datos, JSON_UNESCAPED_UNICODE)]);

        return $token;

    } catch (PDOException $e) {
        // Carrera: dos impresiones simultáneas del mismo rótulo. La segunda choca con la huella
        // única y se queda con el token que grabó la primera.
        $existente = $pdo->prepare("SELECT token FROM rotulos_enlace WHERE huella = ?");
        $existente->execute([$huella]);
        $token = $existente->fetchColumn();
        if ($token !== false) {
            return $token;
        }

        error_log('No se pudo crear el enlace del rótulo: ' . $e->getMessage());
        return null;
    }
}

/**
 * La URL completa que se codifica en el QR, o null si no se puede armar.
 *
 * Devuelve null cuando URL_PUBLICA_ROTULOS está vacía: es preferible una etiqueta SIN QR a una con
 * un código que, al escanearlo, no lleva a ninguna parte —eso último parece que funciona y hace
 * perder tiempo en el muelle averiguando por qué no abre.
 */
function enlaceDeRotulo($pdo, array $rotulo) {
    $base = rtrim((string) URL_PUBLICA_ROTULOS, '/');

    // Sin dirección pública o sin conexión no hay enlace que armar. Se devuelve null y el rótulo
    // sale sin QR: una etiqueta sin código es molesta, pero una con un código que no abre nada
    // hace perder tiempo en el muelle averiguando por qué no funciona.
    if ($base === '' || !($pdo instanceof PDO)) {
        return null;
    }

    $token = tokenDeRotulo($pdo, $rotulo);
    return $token === null ? null : $base . '/public/rotulo.php?r=' . $token;
}

/**
 * Los datos de un rótulo a partir del token del QR, o null si el token no existe.
 */
function rotuloDesdeToken($pdo, $token) {
    if (!preg_match('/^[0-9A-Za-z]{1,16}$/', (string) $token)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT datos FROM rotulos_enlace WHERE token = ?");
    $stmt->execute([$token]);
    $datos = $stmt->fetchColumn();

    return $datos === false ? null : json_decode($datos, true);
}
