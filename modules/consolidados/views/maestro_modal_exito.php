<!-- MODAL: CARGAR EXCEPCIONES DE ÉXITO
     Un Excel con los SKU cuyo empaque o cubicaje cambian para el Éxito. -->
<div class="modal-fondo" id="modal-exito">
    <div class="modal-caja">
        <div class="modal-cabecera">
            <h2>Cargar excepciones de Éxito</h2>
            <button type="button" class="modal-cerrar" data-cerrar>&times;</button>
        </div>
        <form action="<?php echo BASE_URL; ?>/consolidados/acciones"
              method="POST" enctype="multipart/form-data">
            <?php campoCSRF(); ?>
            <input type="hidden" name="accion" value="importar_maestro_exito">

            <div class="modal-cuerpo">
                <div class="campo">
                    <label for="archivo-exito">Excel de excepciones (.xlsx)</label>
                    <input type="file" id="archivo-exito" name="archivo" accept=".xlsx,.xls" required>
                    <span class="ayuda">
                        Columnas: <strong>SKU</strong> (obligatoria) y al menos una de
                        <strong>Unidades por caja</strong> y <strong>Cubicaje m³</strong>. Poné solo los
                        productos cuyo empaque o cubicaje cambian para el Éxito.
                    </span>
                </div>

                <div class="aviso aviso-info" style="margin-bottom: 0;">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        Cada fila <strong>pisa</strong> el valor base solo para el Éxito y solo en la columna
                        que traiga: si dejás vacío el cubicaje, se sigue usando el del maestro base.
                        No se reemplaza nada: se agrega y se actualiza.
                    </div>
                </div>
            </div>

            <div class="modal-pie">
                <button type="button" class="btn" data-cerrar>Cancelar</button>
                <button type="submit" class="btn btn-primario">Cargar</button>
            </div>
        </form>
    </div>
</div>
