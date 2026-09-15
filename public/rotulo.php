<?php
// public/rotulo.php
// La página que se abre al escanear el QR de un rótulo con el celular.
//
// Vive en /public y NO pide iniciar sesión, a diferencia de todo el resto del sistema. Es a
// propósito: quien la usa está parado en el muelle con una caja en la mano, y obligarlo a tipear
// usuario y contraseña en el celular cada vez haría que nadie la use.
//
// Lo que la protege es el token: 12 caracteres al azar de un alfabeto de 62, generados con el
// generador criptográfico del sistema (ver tokenDeRotulo). Sin el rótulo en la mano no hay forma
// de llegar acá.
//
// Por eso mismo muestra SOLO esa caja: ni el pedido completo, ni las otras tiendas, ni nada que
// permita recorrer el resto. Un token filtrado expone una etiqueta, no el despacho.

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../modules/historial/helper_rotulos_enlace.php';

$rotulo = rotuloDesdeToken($pdo, $_GET['r'] ?? '');

// Sin sesión, este archivo no debería entrar al índice de ningún buscador ni quedar cacheado en
// un proxy compartido.
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$esc = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');

if ($rotulo === null) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <!-- Esta página se ve casi siempre en un celular sostenido con una mano. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Rótulo · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <style>
        /* Los estilos van acá adentro y no en la hoja del sistema: esta página se abre desde un
           celular que puede tener la señal justa, y una hoja de estilos más es una espera más. */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f4f1ee;
            color: #1a1a1a;
            padding: 16px;
            line-height: 1.45;
        }

        .tarjeta {
            max-width: 460px;
            margin: 0 auto;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 14px rgba(0, 0, 0, 0.09);
            overflow: hidden;
        }

        .cabecera {
            background: #111;
            color: #fff;
            padding: 16px 18px;
        }
        .cabecera .marca { font-size: 0.75rem; letter-spacing: 1.4px; text-transform: uppercase; opacity: 0.75; }
        .cabecera .caja  { font-size: 1.7rem; font-weight: 700; margin-top: 2px; }

        .cuerpo { padding: 6px 18px 18px; }

        .campo { padding: 12px 0; border-bottom: 1px solid #eceae7; }
        .campo:last-child { border-bottom: none; }
        .etiqueta {
            display: block; font-size: 0.68rem; letter-spacing: 1px;
            text-transform: uppercase; color: #7a7a7a; margin-bottom: 3px;
        }
        .valor { font-size: 1.05rem; font-weight: 600; word-break: break-word; }
        .valor-grande { font-size: 1.3rem; }

        /* El EAN es la razón de ser de esta página: en la etiqueta impresa no está, y quien
           escanea lo hace justamente para verlo. Va destacado y en monoespaciada, que es como se
           lee un código cifra por cifra sin confundir un 1 con una l. */
        .campo-ean { background: #fdf6e8; margin: 12px -18px 0; padding: 14px 18px; border-bottom: none; }
        .campo-ean .valor {
            font-family: 'SF Mono', Menlo, Consolas, monospace;
            font-size: 1.45rem; letter-spacing: 1.5px;
        }

        .vacio { text-align: center; padding: 40px 22px; }
        .vacio h1 { font-size: 1.15rem; margin-bottom: 8px; }
        .vacio p  { color: #666; font-size: 0.95rem; }
    </style>
</head>
<body>

<?php if ($rotulo === null): ?>
    <div class="tarjeta">
        <div class="vacio">
            <h1>Este rótulo no existe</h1>
            <p>
                El código escaneado no corresponde a ninguna etiqueta del sistema. Puede ser de un
                despacho viejo que ya se borró, o el código se leyó mal.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="tarjeta">
        <div class="cabecera">
            <div class="marca">Monterojo Gourmet</div>
            <div class="caja">Caja <?php echo (int) $rotulo['numero']; ?> de <?php echo (int) $rotulo['total']; ?></div>
        </div>

        <div class="cuerpo">
            <div class="campo">
                <span class="etiqueta">Punto de venta</span>
                <span class="valor valor-grande">
                    <?php if (!empty($rotulo['numero_pv'])): ?><?php echo $esc($rotulo['numero_pv']); ?> · <?php endif; ?>
                    <?php echo $esc($rotulo['pv']); ?>
                </span>
            </div>

            <div class="campo">
                <span class="etiqueta">CEDI</span>
                <span class="valor"><?php echo $esc($rotulo['cedi'] !== '' ? $rotulo['cedi'] : '—'); ?></span>
            </div>

            <div class="campo">
                <span class="etiqueta">Producto</span>
                <span class="valor"><?php echo $esc($rotulo['producto'] !== '' ? $rotulo['producto'] : '—'); ?></span>
            </div>

            <div class="campo">
                <span class="etiqueta">SKU</span>
                <span class="valor"><?php echo $esc($rotulo['sku'] !== '' ? $rotulo['sku'] : '—'); ?></span>
            </div>

            <div class="campo campo-ean">
                <span class="etiqueta">EAN del producto</span>
                <span class="valor"><?php echo $esc($rotulo['ean'] !== '' ? $rotulo['ean'] : 'sin EAN en el maestro'); ?></span>
            </div>
        </div>
    </div>
<?php endif; ?>

</body>
</html>
