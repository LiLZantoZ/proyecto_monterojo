<?php
// modules/usuarios/views/usuarios.php
// ADMINISTRAR USUARIOS (2026-10-06, del sistema de bodega): las cuentas que entran al sistema.
// Buscar, agregar, editar (con foto y contraseña), eliminar, ver el rendimiento de cada uno y
// descargar el backup de la base. Las reglas están en model_usuarios.php.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_usuarios.php';

requierePermiso('modulo_usuarios', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mil = fn($n) => number_format((float) $n, 0, ',', '.');

$idSesion  = (int) $_SESSION['usuario_id'];
$buscar    = trim($_GET['buscar'] ?? '');
$roles     = rolesUsuarios($pdo);
$resumen   = resumenUsuarios($pdo);
$resultado = paginaUsuarios($pdo, $buscar, $_GET['pagina'] ?? 1);
$usuarios  = $resultado['filas'];
$pagina    = $resultado['pagina'];
$totalPaginas = $resultado['total_paginas'];
$total     = $resultado['total'];
$desdeFila = $total === 0 ? 0 : ($pagina - 1) * USUARIOS_POR_PAGINA + 1;
$hastaFila = min($pagina * USUARIOS_POR_PAGINA, $total);
$etiqueta  = 'usuarios';
$urlPagina = fn($p) => BASE_URL . '/usuarios?' . http_build_query(array_filter(['buscar' => $buscar, 'pagina' => $p > 1 ? $p : '']));
// Para volver a la misma búsqueda y página después de guardar.
$volver = ($q = http_build_query(array_filter(['buscar' => $buscar, 'pagina' => $pagina > 1 ? $pagina : '']))) !== '' ? '?' . $q : '';

$puedeRendimiento = tienePermiso('modulo_rendimiento');
$puedeBackup      = tienePermiso('usuarios_backup');
$urlAcciones      = BASE_URL . '/usuarios/acciones';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar usuarios · <?php echo $esc(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/modules/usuarios/layouts/usuarios.css?v=<?php echo assetVersion(ROOT_PATH . '/modules/usuarios/layouts/usuarios.css'); ?>">
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Administrar usuarios</h2>
                <div class="modulo-acciones">
                    <?php if ($puedeBackup): ?>
                        <form method="POST" action="<?php echo $esc($urlAcciones); ?>" id="form-backup">
                            <?php campoCSRF(); ?>
                            <input type="hidden" name="accion" value="backup">
                            <button type="submit" class="btn" title="Descarga un .sql con todas las tablas y sus datos">
                                <i class="fa-solid fa-database"></i> <span>Descargar backup</span>
                            </button>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primario" data-abrir="modal-crear">
                        <i class="fa-solid fa-user-plus"></i> Agregar usuario
                    </button>
                </div>
            </header>

            <div class="pastillas">
                <span class="pastilla">Usuarios <strong><?php echo $mil($resumen['total']); ?></strong></span>
                <span class="pastilla">Activos <strong><?php echo $mil($resumen['activos']); ?></strong></span>
                <span class="pastilla">Inactivos <strong><?php echo $mil($resumen['inactivos']); ?></strong></span>
            </div>

            <form method="GET" class="buscador" id="form-buscar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="buscar" id="buscar-usuario" value="<?php echo $esc($buscar); ?>"
                       placeholder="Buscar por nombre, cédula, teléfono o rol…" autocomplete="off">
            </form>

            <div class="tabla-caja">
                <table class="tabla tabla-usuarios">
                    <thead>
                        <tr>
                            <th class="centro">Foto</th>
                            <th>Nombre</th>
                            <th>Cédula</th>
                            <th>Teléfono</th>
                            <th class="centro">Estado</th>
                            <th>Rol</th>
                            <th>Último acceso</th>
                            <?php if ($puedeRendimiento): ?><th class="centro">Rendimiento</th><?php endif; ?>
                            <th class="centro">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$usuarios): ?>
                            <tr><td colspan="9"><p class="tabla-vacia"><?php echo $buscar !== '' ? 'Ninguna cuenta coincide con «' . $esc($buscar) . '».' : 'Todavía no hay cuentas.'; ?></p></td></tr>
                        <?php endif; ?>
                        <?php foreach ($usuarios as $u):
                            $esYo = (int) $u['id_usuario'] === $idSesion; ?>
                            <tr class="<?php echo $u['estado'] === 'Inactivo' ? 'fila-inactiva' : ''; ?>">
                                <td class="centro"><img class="usuario-avatar" src="<?php echo $esc(rutaImagenPerfil($u['imagen_url_Usuario'])); ?>" alt="" loading="lazy"></td>
                                <td><strong><?php echo $esc($u['nombre_usuario']); ?></strong><?php echo $esYo ? ' <span class="etiqueta-yo">Tú</span>' : ''; ?></td>
                                <td><?php echo $esc($u['cedula_usuario']); ?></td>
                                <td><?php echo $u['telefono_usuario'] ? $esc($u['telefono_usuario']) : '<span class="sin-dato">—</span>'; ?></td>
                                <td class="centro"><span class="etiqueta-estado etiqueta-<?php echo strtolower($u['estado']); ?>"><?php echo $esc($u['estado']); ?></span></td>
                                <td><?php echo $u['nombre_rol'] ? $esc($u['nombre_rol']) : '<span class="sin-dato">Sin rol</span>'; ?></td>
                                <td><?php echo $u['fecha_ultimo_acceso'] ? date('d/m/Y H:i', strtotime($u['fecha_ultimo_acceso'])) : '<span class="sin-dato">Nunca entró</span>'; ?></td>
                                <?php if ($puedeRendimiento): ?>
                                    <td class="centro">
                                        <a class="btn btn-chico btn-rendimiento" href="<?php echo $esc(BASE_URL . '/rendimiento?dias=30&usuario=' . (int) $u['id_usuario']); ?>">
                                            <i class="fa-solid fa-chart-line"></i> Ver rendimiento
                                        </a>
                                        <span class="sub-dato"><?php echo $mil($u['acciones']); ?> acción(es)</span>
                                    </td>
                                <?php endif; ?>
                                <td class="centro">
                                    <div class="acciones-fila">
                                        <!-- Los datos viajan en data- y el JS los vuelca en el modal (como en Personal). -->
                                        <button type="button" class="btn btn-chico btn-editar-usuario" title="Editar"
                                                data-id="<?php echo (int) $u['id_usuario']; ?>"
                                                data-nombre="<?php echo $esc($u['nombre_usuario']); ?>"
                                                data-cedula="<?php echo $esc($u['cedula_usuario']); ?>"
                                                data-telefono="<?php echo $esc($u['telefono_usuario'] ?? ''); ?>"
                                                data-estado="<?php echo $esc($u['estado']); ?>"
                                                data-rol="<?php echo (int) $u['id_rol']; ?>"
                                                data-foto="<?php echo $esc(rutaImagenPerfil($u['imagen_url_Usuario'])); ?>"
                                                data-yo="<?php echo $esYo ? '1' : '0'; ?>">
                                            <i class="fa-solid fa-pen"></i> Editar
                                        </button>
                                        <?php if (!$esYo): ?>
                                            <button type="button" class="btn btn-chico btn-eliminar-usuario" title="Eliminar"
                                                    data-id="<?php echo (int) $u['id_usuario']; ?>"
                                                    data-nombre="<?php echo $esc($u['nombre_usuario']); ?>"
                                                    data-acciones="<?php echo (int) $u['acciones']; ?>"
                                                    data-estado="<?php echo $esc($u['estado']); ?>">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php include ROOT_PATH . '/modules/inicio/layout/paginacion.php'; ?>

        </div>
    </div>
</div>

<?php
// Los dos formularios (agregar y editar) son el mismo, con estas diferencias.
foreach (['crear' => 'Agregar usuario', 'editar' => 'Editar usuario'] as $modo => $tituloModal):
    $p = $modo; // prefijo de los id
?>
<div class="modal-fondo" id="modal-<?php echo $modo; ?>">
    <div class="modal-caja modal-caja-ancha">
        <div class="modal-cabecera">
            <h2><i class="fa-solid <?php echo $modo === 'crear' ? 'fa-user-plus' : 'fa-user-pen'; ?>"></i> <?php echo $tituloModal; ?></h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo $esc($urlAcciones); ?>" method="POST" enctype="multipart/form-data" class="form-usuario">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="<?php echo $modo; ?>">
            <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
            <?php if ($modo === 'editar'): ?><input type="hidden" name="id_usuario" id="editar-id"><?php endif; ?>
            <div class="modal-cuerpo">
                <div class="usuarios-grilla">
                    <div>
                        <div class="campo">
                            <label for="<?php echo $p; ?>-nombre">Nombre</label>
                            <input type="text" id="<?php echo $p; ?>-nombre" name="nombre" maxlength="255" required autocomplete="off" placeholder="Ej. Jorge Orrego">
                        </div>
                        <div class="campo">
                            <label for="<?php echo $p; ?>-cedula">Cédula</label>
                            <input type="text" id="<?php echo $p; ?>-cedula" name="cedula" maxlength="20" required autocomplete="off" inputmode="numeric" pattern="[0-9]+" title="Solo números, sin puntos ni espacios">
                            <span class="ayuda">Es con lo que entra al sistema. Solo números.</span>
                        </div>
                        <div class="campo">
                            <label for="<?php echo $p; ?>-telefono">Teléfono <span class="opcional">(opcional)</span></label>
                            <input type="text" id="<?php echo $p; ?>-telefono" name="telefono" maxlength="15" autocomplete="off" inputmode="tel">
                        </div>
                        <div class="campo campo-foto">
                            <label for="<?php echo $p; ?>-foto">Foto <span class="opcional">(opcional)</span></label>
                            <div class="usuario-foto-fila">
                                <img class="usuario-foto-vista" id="<?php echo $p; ?>-foto-vista" src="<?php echo $esc(rutaImagenPerfil('')); ?>" data-por-defecto="<?php echo $esc(rutaImagenPerfil('')); ?>" alt="">
                                <input type="file" id="<?php echo $p; ?>-foto" name="foto" accept="image/jpeg,image/png,image/webp,image/gif" data-vista="<?php echo $p; ?>-foto-vista">
                            </div>
                            <span class="ayuda">JPG, PNG, WEBP o GIF, hasta 3 MB.<?php echo $modo === 'editar' ? ' Si no elegís ninguna, queda la que tiene.' : ''; ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="campo">
                            <label for="<?php echo $p; ?>-rol">Rol</label>
                            <select id="<?php echo $p; ?>-rol" name="id_rol" required data-ayuda="<?php echo $p; ?>-rol-ayuda">
                                <option value="">Elegí un rol</option>
                                <?php foreach ($roles as $id => $r): ?>
                                    <option value="<?php echo (int) $id; ?>" data-descripcion="<?php echo $esc($r['descripcion']); ?>"><?php echo $esc($r['nombre_rol']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="ayuda" id="<?php echo $p; ?>-rol-ayuda">Lo que puede ver y hacer en el sistema.</span>
                        </div>
                        <div class="campo">
                            <label for="<?php echo $p; ?>-estado">Estado</label>
                            <select id="<?php echo $p; ?>-estado" name="estado">
                                <option value="Activo">Activo</option>
                                <option value="Inactivo">Inactivo</option>
                            </select>
                            <span class="ayuda">Una cuenta inactiva no puede entrar, pero sus acciones quedan en Trazabilidad. Es lo indicado cuando alguien deja de trabajar.</span>
                        </div>
                        <div class="campo">
                            <label for="<?php echo $p; ?>-contrasena">Contraseña<?php echo $modo === 'editar' ? ' nueva <span class="opcional">(opcional)</span>' : ''; ?></label>
                            <input type="password" id="<?php echo $p; ?>-contrasena" name="contrasena" minlength="<?php echo USUARIOS_CONTRASENA_MINIMA; ?>" autocomplete="new-password"<?php echo $modo === 'crear' ? ' required' : ' placeholder="Dejala vacía para no cambiarla"'; ?>>
                            <span class="ayuda">Al menos <?php echo USUARIOS_CONTRASENA_MINIMA; ?> caracteres.</span>
                        </div>
                        <div class="campo" style="margin-bottom: 0;">
                            <label for="<?php echo $p; ?>-confirmar">Confirmar contraseña</label>
                            <input type="password" id="<?php echo $p; ?>-confirmar" name="contrasena_confirmar" autocomplete="new-password"<?php echo $modo === 'crear' ? ' required' : ''; ?>>
                        </div>
                    </div>
                </div>
                <?php if ($modo === 'editar'): ?>
                    <div class="aviso aviso-info" id="editar-aviso-yo" hidden style="margin: 14px 0 0;">
                        <i class="fa-solid fa-circle-info"></i>
                        <div>Es tu propia cuenta: no podés desactivarla ni quitarte el acceso a Administrar usuarios.</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario"><?php echo $modo === 'crear' ? 'Agregar usuario' : 'Guardar cambios'; ?></button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- MODAL: ELIMINAR -->
<div class="modal-fondo" id="modal-eliminar">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2><i class="fa-solid fa-user-xmark"></i> Eliminar usuario</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo $esc($urlAcciones); ?>" method="POST">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="volver" value="<?php echo $esc($volver); ?>">
            <input type="hidden" name="id_usuario" id="eliminar-id">
            <div class="modal-cuerpo">
                <p style="font-size: 0.95rem; line-height: 1.55; margin: 0;">
                    ¿Eliminar la cuenta de <strong id="eliminar-nombre"></strong>? No se puede deshacer.
                </p>
                <div class="aviso aviso-atencion" id="eliminar-aviso" hidden style="margin: 14px 0 0;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div id="eliminar-aviso-texto"></div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-peligro"><i class="fa-solid fa-trash"></i> Eliminar</button>
            </div>
        </form>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/modules/usuarios/layouts/scripts_usuarios.js?v=<?php echo assetVersion(ROOT_PATH . '/modules/usuarios/layouts/scripts_usuarios.js'); ?>"></script>
</body>
</html>
