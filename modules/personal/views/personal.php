<?php
// modules/personal/views/personal.php
// Gestión del personal de alistamiento: agregar, editar y eliminar.

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/auth_guard.php';
require_once __DIR__ . '/../../../config/permisos.php';
require_once __DIR__ . '/../model_personal.php';

requierePermiso('modulo_personal', urlPanelDelRol($_SESSION['usuario_rol'] ?? null));

$personal = listarPersonal($pdo);
$activos  = count(array_filter($personal, fn($p) => $p['estado'] === 'Activo'));
$conCarga = count(array_filter($personal, fn($p) => (int) $p['entregas_asignadas'] > 0));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal · <?php echo htmlspecialchars(NOMBRE_SISTEMA); ?></title>
    <?php include ROOT_PATH . '/modules/inicio/layout/estilos.php'; ?>
</head>
<body<?php atributosDelCuerpo(); ?>>

<div class="app-shell">
    <?php include ROOT_PATH . '/modules/inicio/layout/sidebar.php'; ?>

    <div class="content-area">
        <div class="modulo">

            <header class="modulo-header">
                <h2>Personal de alistamiento</h2>
                <div class="modulo-acciones">
                    <?php if (tienePermiso('modulo_picking')): ?>
                        <a class="btn" href="<?php echo BASE_URL; ?>/modules/picking/views/picking.php">
                            <i class="fa-solid fa-arrow-left"></i> Volver a Picking
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primario" data-abrir="modal-crear">
                        <i class="fa-solid fa-user-plus"></i> Agregar persona
                    </button>
                </div>
            </header>

            <div class="pastillas">
                <span class="pastilla">Personal <strong><?php echo count($personal); ?></strong></span>
                <span class="pastilla">Activos <strong><?php echo $activos; ?></strong></span>
                <span class="pastilla">Con entregas asignadas <strong><?php echo $conCarga; ?></strong></span>
            </div>

            <?php if (empty($personal)): ?>
                <div class="aviso aviso-info">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Todavía no hay nadie cargado. Agregá al personal de bodega y después vas a poder
                        asignarle entregas desde <strong>Picking</strong>.
                    </div>
                </div>
            <?php endif; ?>

            <div class="tabla-caja">
                <table class="tabla tabla-accion-fija">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Documento</th>
                            <th>Cargo</th>
                            <th class="centro">Estado</th>
                            <th class="num">Entregas asignadas</th>
                            <th class="centro">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($personal)): ?>
                            <tr>
                                <td colspan="6">
                                    <p class="tabla-vacia">Usá <strong>Agregar persona</strong> para empezar.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($personal as $p): ?>
                                <tr class="<?php echo $p['estado'] === 'Inactivo' ? 'fila-inactiva' : ''; ?>">
                                    <td><strong><?php echo htmlspecialchars($p['nombre']); ?></strong></td>
                                    <td><?php echo $p['documento'] !== null ? htmlspecialchars($p['documento']) : '<span class="sin-dato">—</span>'; ?></td>
                                    <td><?php echo $p['cargo'] !== null ? htmlspecialchars($p['cargo']) : '<span class="sin-dato">—</span>'; ?></td>
                                    <td class="centro">
                                        <span class="etiqueta-estado etiqueta-<?php echo strtolower($p['estado']); ?>">
                                            <?php echo htmlspecialchars($p['estado']); ?>
                                        </span>
                                    </td>
                                    <td class="num"><?php echo (int) $p['entregas_asignadas']; ?></td>
                                    <td class="centro">
                                        <div class="acciones-fila">
                                            <!-- Los datos viajan en data- y el JS los vuelca en el modal de
                                                 edición: un modal por fila serían 50 formularios ocultos en la
                                                 misma página. -->
                                            <button type="button" class="btn btn-chico btn-editar-persona"
                                                    data-id="<?php echo (int) $p['id_personal']; ?>"
                                                    data-nombre="<?php echo htmlspecialchars($p['nombre']); ?>"
                                                    data-documento="<?php echo htmlspecialchars($p['documento'] ?? ''); ?>"
                                                    data-cargo="<?php echo htmlspecialchars($p['cargo'] ?? ''); ?>"
                                                    data-estado="<?php echo htmlspecialchars($p['estado']); ?>">
                                                <i class="fa-solid fa-pen"></i> Editar
                                            </button>
                                            <button type="button" class="btn btn-chico btn-eliminar-persona"
                                                    data-id="<?php echo (int) $p['id_personal']; ?>"
                                                    data-nombre="<?php echo htmlspecialchars($p['nombre']); ?>"
                                                    data-entregas="<?php echo (int) $p['entregas_asignadas']; ?>">
                                                <i class="fa-solid fa-trash"></i> Eliminar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- MODAL: AGREGAR -->
<div class="modal-fondo" id="modal-crear">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Agregar persona</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/modules/personal/controller_personal.php" method="POST">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="crear">
            <div class="modal-cuerpo">
                <div class="campo">
                    <label for="crear-nombre">Nombre completo</label>
                    <input type="text" id="crear-nombre" name="nombre" maxlength="120" required autocomplete="off">
                </div>
                <div class="campo">
                    <label for="crear-documento">Documento <span class="opcional">(opcional)</span></label>
                    <input type="text" id="crear-documento" name="documento" maxlength="20" autocomplete="off">
                    <span class="ayuda">Sirve para distinguir a dos personas que se llaman igual.</span>
                </div>
                <div class="campo" style="margin-bottom: 0;">
                    <label for="crear-cargo">Cargo <span class="opcional">(opcional)</span></label>
                    <input type="text" id="crear-cargo" name="cargo" maxlength="60" autocomplete="off"
                           placeholder="Ej. Alistador, Auxiliar de bodega">
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario">Agregar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: EDITAR -->
<div class="modal-fondo" id="modal-editar">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Editar persona</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/modules/personal/controller_personal.php" method="POST">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id_personal" id="editar-id">
            <div class="modal-cuerpo">
                <div class="campo">
                    <label for="editar-nombre">Nombre completo</label>
                    <input type="text" id="editar-nombre" name="nombre" maxlength="120" required autocomplete="off">
                </div>
                <div class="campo">
                    <label for="editar-documento">Documento <span class="opcional">(opcional)</span></label>
                    <input type="text" id="editar-documento" name="documento" maxlength="20" autocomplete="off">
                </div>
                <div class="campo">
                    <label for="editar-cargo">Cargo <span class="opcional">(opcional)</span></label>
                    <input type="text" id="editar-cargo" name="cargo" maxlength="60" autocomplete="off">
                </div>
                <div class="campo" style="margin-bottom: 0;">
                    <label for="editar-estado">Estado</label>
                    <select id="editar-estado" name="estado">
                        <option value="Activo">Activo</option>
                        <option value="Inactivo">Inactivo</option>
                    </select>
                    <span class="ayuda">
                        A alguien inactivo no se le pueden asignar entregas nuevas, pero las que ya tenía
                        se conservan. Es la alternativa a eliminarlo cuando solo está de vacaciones o de baja.
                    </span>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ELIMINAR -->
<div class="modal-fondo" id="modal-eliminar">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Eliminar persona</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/modules/personal/controller_personal.php" method="POST">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id_personal" id="eliminar-id">
            <div class="modal-cuerpo">
                <p style="font-size: 0.95rem; line-height: 1.55;">
                    ¿Eliminar a <strong id="eliminar-nombre"></strong> del personal?
                </p>
                <!-- Solo aparece si tiene entregas asignadas: avisar de un efecto que no va a
                     ocurrir hace que se deje de leer el aviso cuando sí importa. -->
                <div class="aviso aviso-atencion" id="eliminar-aviso" hidden style="margin-top: 14px; margin-bottom: 0;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div id="eliminar-aviso-texto"></div>
                </div>
            </div>
            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-acento">Eliminar</button>
            </div>
        </form>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/modales.js?v=<?php echo assetVersion(ROOT_PATH . '/assets/js/modales.js'); ?>"></script>
<script>
    // Volcar los datos de la fila en el modal correspondiente. Por delegación en el documento,
    // así no hay que reenganchar nada si la tabla se vuelve a dibujar.
    document.addEventListener('click', function (e) {
        var editar = e.target.closest('.btn-editar-persona');
        if (editar) {
            document.getElementById('editar-id').value        = editar.dataset.id;
            document.getElementById('editar-nombre').value    = editar.dataset.nombre;
            document.getElementById('editar-documento').value = editar.dataset.documento;
            document.getElementById('editar-cargo').value     = editar.dataset.cargo;
            document.getElementById('editar-estado').value    = editar.dataset.estado;
            document.getElementById('modal-editar').classList.add('active');
            return;
        }

        var eliminar = e.target.closest('.btn-eliminar-persona');
        if (eliminar) {
            var entregas = parseInt(eliminar.dataset.entregas, 10) || 0;
            document.getElementById('eliminar-id').value = eliminar.dataset.id;
            document.getElementById('eliminar-nombre').textContent = eliminar.dataset.nombre;

            var aviso = document.getElementById('eliminar-aviso');
            if (entregas > 0) {
                document.getElementById('eliminar-aviso-texto').innerHTML =
                    'Tiene <strong>' + entregas + '</strong> entrega(s) asignadas. No se borran, pero quedan '
                    + '<strong>sin asignar</strong> y hay que volver a repartirlas.';
                aviso.hidden = false;
            } else {
                aviso.hidden = true;
            }

            document.getElementById('modal-eliminar').classList.add('active');
        }
    });
</script>

</body>
</html>
