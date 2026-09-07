<?php
// scripts/preparar_logo.php
// Recorta el fondo blanco del logo de Monterojo y deja el PNG con transparencia.
//
//   C:\xampp\php\php.exe scripts\preparar_logo.php
//
// POR QUÉ EXISTE
// El logo que venía del sistema de bodega (assets/img/marcas/monterojo.png) es un PNG de PALETA,
// sin canal alfa: el círculo negro está sobre un cuadrado blanco opaco. Puesto sobre el panel
// negro del login se veía como un recuadro blanco alrededor de la marca.
//
// POR QUÉ NO SE ARREGLÓ CON CSS
// Un `clip-path: circle()` habría tapado el problema igual de bien, pero deja la corrección
// atada a cada sitio donde se use el logo: el día que se ponga en un correo, en un PDF o en una
// pantalla nueva que se olvide la clase, el recuadro blanco vuelve. El archivo estaba mal, no la
// hoja de estilos.
//
// POR QUÉ NO UN RELLENO POR COLOR
// Hacer transparente "todo lo blanco" también borraría las letras de MONTEROJO y la montaña, que
// son blancas. Y un relleno por contigüidad desde la esquina se frena en el antialias del borde y
// deja un halo claro. Acá se recorta por GEOMETRÍA: el logo es un círculo, y lo que queda fuera
// de ese círculo es fondo por definición. El radio no está escrito a mano — se mide leyendo el
// propio archivo, así que si mañana se reemplaza por otra versión el script se sigue ajustando.
//
// Es idempotente: correrlo sobre un archivo ya procesado lo vuelve a dejar igual.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Este script solo puede ejecutarse desde la línea de comandos.');
}

$ruta = __DIR__ . '/../assets/img/monterojo.png';

if (!file_exists($ruta)) {
    fwrite(STDERR, "No se encontró {$ruta}\n");
    exit(1);
}

$origen = imagecreatefrompng($ruta);
if (!$origen) {
    fwrite(STDERR, "No se pudo leer el PNG.\n");
    exit(1);
}

$ancho = imagesx($origen);
$alto  = imagesy($origen);

// --- 1. Medir el círculo: la caja que contiene todo lo que NO es fondo claro ---
// El umbral de 200 es holgado a propósito: cuenta como "marca" el gris del antialias del borde,
// pero no el blanco del fondo, que es 255 exacto.
$minX = $ancho; $maxX = -1;
$minY = $alto;  $maxY = -1;

for ($y = 0; $y < $alto; $y++) {
    for ($x = 0; $x < $ancho; $x++) {
        $c = imagecolorsforindex($origen, imagecolorat($origen, $x, $y));
        if ($c['alpha'] > 100) {
            continue;   // ya era transparente: no cuenta como contenido
        }
        if ($c['red'] < 200 || $c['green'] < 200 || $c['blue'] < 200) {
            if ($x < $minX) { $minX = $x; }
            if ($x > $maxX) { $maxX = $x; }
            if ($y < $minY) { $minY = $y; }
            if ($y > $maxY) { $maxY = $y; }
        }
    }
}

if ($maxX < 0) {
    fwrite(STDERR, "La imagen está en blanco: no hay nada que recortar.\n");
    exit(1);
}

$centroX = ($minX + $maxX) / 2;
$centroY = ($minY + $maxY) / 2;
// El radio sale del lado MENOR de la caja: si el archivo trajera algún píxel suelto fuera del
// círculo, tomar el mayor agrandaría el radio y volvería a dejar fondo blanco adentro.
$radio   = min($maxX - $minX, $maxY - $minY) / 2;

printf("Círculo detectado: centro (%.1f, %.1f), radio %.1f px sobre un lienzo de %dx%d\n",
    $centroX, $centroY, $radio, $ancho, $alto);

// --- 2. Copiar a un lienzo con canal alfa, dejando fuera todo lo que cae afuera del círculo ---
$destino = imagecreatetruecolor($ancho, $alto);
imagealphablending($destino, false);   // se escriben los alfa tal cual, sin mezclarlos con el fondo
imagesavealpha($destino, true);        // el canal alfa se guarda en el archivo

$transparente = imagecolorallocatealpha($destino, 0, 0, 0, 127);
imagefilledrectangle($destino, 0, 0, $ancho, $alto, $transparente);

// Franja de suavizado de 1 px hacia adentro del borde: sin ella el círculo queda con el filo
// dentado, que es justo lo que se nota en un logo.
const SUAVIZADO = 1.0;

for ($y = 0; $y < $alto; $y++) {
    for ($x = 0; $x < $ancho; $x++) {
        $distancia = sqrt(($x - $centroX) ** 2 + ($y - $centroY) ** 2);

        if ($distancia > $radio) {
            continue;   // fuera del círculo: se queda transparente
        }

        $c = imagecolorsforindex($origen, imagecolorat($origen, $x, $y));

        // 0 = opaco, 127 = transparente del todo
        $alfa = 0;
        if ($distancia > $radio - SUAVIZADO) {
            $alfa = (int) round((($distancia - ($radio - SUAVIZADO)) / SUAVIZADO) * 127);
        }

        $color = imagecolorallocatealpha($destino, $c['red'], $c['green'], $c['blue'], $alfa);
        imagesetpixel($destino, $x, $y, $color);
    }
}

if (!imagepng($destino, $ruta)) {
    fwrite(STDERR, "No se pudo escribir {$ruta}\n");
    exit(1);
}

echo "Listo: " . basename($ruta) . " quedó con el fondo transparente.\n";
