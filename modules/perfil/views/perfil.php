<?php
// modules/perfil/views/perfil.php
// "Mi perfil": lo único que cualquier usuario puede cambiar de su propia cuenta. No hay
// requierePermiso() —solo auth_guard.php, estar logueado— porque no es un módulo de gestión
// sobre otros, es editarse a uno mismo.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../model_perfil.php';

$usuario = obtenerUsuarioPerfil($pdo, $_SESSION['usuario_id']);

// No debería pasar (la sesión ya probó que el usuario existe al loguearse), salvo que alguien lo
// haya eliminado desde otra pestaña mientras esta seguía abierta.
if (!$usuario) {
    session_destroy();
    header("Location: " . URL_LOGIN . "?error=no_session");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi perfil · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Mi perfil</h2>
                <div class="modulo-acciones">
                    <a class="btn" href="<?php echo urlPanelDelRol($_SESSION['usuario_rol'] ?? null); ?>">
                        <i class="fa-solid fa-arrow-left"></i> Volver
                    </a>
                </div>
            </header>

            <div class="perfil-grilla">

                <!-- FOTO -->
                <section class="tarjeta-perfil">
                    <h3>Foto de perfil</h3>
                    <div class="perfil-foto-actual">
                        <img src="<?php echo htmlspecialchars(rutaImagenPerfil($usuario['imagen_url_Usuario'])); ?>"
                             alt="Tu foto de perfil">
                    </div>
                    <form action="<?php echo BASE_URL; ?>/modules/perfil/controller_perfil.php"
                          method="POST" enctype="multipart/form-data">
                        <?php campoCSRF(); ?>
                        <input type="hidden" name="accion" value="subir_imagen">
                        <div class="campo" style="margin-bottom: 0;">
                            <label for="perfil-imagen">Cambiar foto</label>
                            <input type="file" id="perfil-imagen" name="imagen" accept="image/png, image/jpeg, image/webp, image/gif" required>
                            <span class="ayuda">JPG, PNG, WEBP o GIF, hasta 3 MB.</span>
                        </div>
                        <div style="margin-top: 14px;">
                            <button type="submit" class="btn btn-primario"><i class="fa-solid fa-upload"></i> Subir foto</button>
                        </div>
                    </form>
                </section>

                <!-- DATOS -->
                <section class="tarjeta-perfil">
                    <h3>Mis datos</h3>
                    <form action="<?php echo BASE_URL; ?>/modules/perfil/controller_perfil.php" method="POST">
                        <?php campoCSRF(); ?>
                        <input type="hidden" name="accion" value="actualizar_datos">

                        <div class="campo">
                            <label for="perfil-nombre">Nombre completo</label>
                            <input type="text" id="perfil-nombre" name="nombre" maxlength="255" required
                                   value="<?php echo htmlspecialchars($usuario['nombre_usuario']); ?>">
                        </div>

                        <div class="campo">
                            <label for="perfil-cedula">Cédula</label>
                            <input type="text" id="perfil-cedula" name="cedula" maxlength="20" required
                                   inputmode="numeric" pattern="[0-9]+"
                                   value="<?php echo htmlspecialchars($usuario['cedula_usuario']); ?>">
                            <span class="ayuda">Es la que usás para entrar: solo números, sin puntos ni espacios.</span>
                        </div>

                        <div class="campo" style="margin-bottom: 0;">
                            <label for="perfil-telefono">Teléfono <span class="opcional">(opcional)</span></label>
                            <input type="text" id="perfil-telefono" name="telefono" maxlength="15" autocomplete="off"
                                   value="<?php echo htmlspecialchars($usuario['telefono_usuario'] ?? ''); ?>">
                        </div>

                        <div style="margin-top: 14px;">
                            <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button>
                        </div>
                    </form>
                </section>

                <!-- CONTRASEÑA -->
                <section class="tarjeta-perfil">
                    <h3>Cambiar contraseña</h3>
                    <form action="<?php echo BASE_URL; ?>/modules/perfil/controller_perfil.php" method="POST">
                        <?php campoCSRF(); ?>
                        <input type="hidden" name="accion" value="cambiar_contrasena">

                        <div class="campo">
                            <label for="perfil-actual">Contraseña actual</label>
                            <input type="password" id="perfil-actual" name="contrasena_actual" required autocomplete="current-password">
                        </div>

                        <div class="campo">
                            <label for="perfil-nueva">Contraseña nueva</label>
                            <input type="password" id="perfil-nueva" name="contrasena_nueva" required minlength="8" autocomplete="new-password">
                            <span class="ayuda">Al menos 8 caracteres.</span>
                        </div>

                        <div class="campo" style="margin-bottom: 0;">
                            <label for="perfil-confirmar">Confirmar contraseña nueva</label>
                            <input type="password" id="perfil-confirmar" name="contrasena_confirmar" required minlength="8" autocomplete="new-password">
                        </div>

                        <div style="margin-top: 14px;">
                            <button type="submit" class="btn btn-acento"><i class="fa-solid fa-key"></i> Cambiar contraseña</button>
                        </div>
                    </form>
                </section>

            </div>

        </div>
    </div>
</div>

</body>
</html>
