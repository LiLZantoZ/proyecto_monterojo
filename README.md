# Sistema Monterojo — versión Laravel

El Sistema Monterojo (consolidados, picking, rótulos, historial, órdenes de compra, estado de
pedidos, Consolidado MR) migrado a **Laravel 12**. Hace exactamente lo mismo que la versión
anterior en PHP sin framework (`C:\xampp\htdocs\proyecto_monterojo`): mismas pantallas, mismas
direcciones, misma base de datos y los mismos cálculos.

Se abre en <http://localhost/monterojo_laravel/>. Durante la migración trabaja sobre una **copia**
de la base (`monterojo_laravel`), así que nada de lo que se haga acá toca el sistema en producción.

---

## Cómo está armado

| Carpeta | Qué hay |
|---|---|
| `routes/web.php` | Todas las direcciones del sistema, las mismas de antes (`/picking`, `/consolidados/acciones`...). Cada pantalla lleva su permiso. |
| `app/Http/Controllers` | Un controlador por módulo. Reciben el pedido, llaman a la lógica y devuelven la pantalla o la descarga. |
| `app/Http/Middleware` | Sesión y cierre por inactividad (`SesionActiva`), permisos (`RequierePermiso`), CSRF (`VerificarCsrf`) y cabeceras de seguridad. |
| `app/Servicios` | **La lógica de negocio**, traída tal cual del sistema anterior: importación del Consolidado, cálculo de cajas y saldos, excepciones del Éxito, órdenes de compra, rótulos (PDF, TSPL, QR)... |
| `app/Soporte` | Funciones compartidas (permisos, avisos, formatos), las constantes del sistema y los permisos por rol. |
| `app/Models/Usuario.php` | El usuario de la sesión (tabla `usuarios`). |
| `resources/views` | Las pantallas en Blade. `layouts/app` es la plantilla común (menú, avisos); `partes/` tiene los pedazos compartidos (menú, paginación, modal de rótulos). |
| `public/assets`, `public/modulos` | Hojas de estilo, scripts e imágenes. |
| `config/monterojo.php` | Ajustes propios (inactividad, rótulos, impresora). Se leen del `.env`. |
| `scripts/` | El esquema de la base y los scripts de la impresión remota (PowerShell). |

### Por qué la lógica no se reescribió

La lógica de `app/Servicios` tiene muchas reglas probadas con archivos reales (productos gemelos
con el mismo EAN, excepciones del Éxito, partición de lotes de SAP, numeración de rótulos...).
Reescribirla con otra herramienta habría vuelto a abrir todos esos casos. Se trajo sin cambios de
cálculo y se conectó a Laravel: usa la misma conexión, con los mismos ajustes de antes
(`config/database.php`). Se verificó que cada pantalla y cada PDF salen **idénticos** a los del
sistema anterior sobre los mismos datos.

---

## Comandos

```bash
php artisan monterojo:esquema
```

Crea o completa las tablas, roles y permisos en la base del `.env`. No borra datos.

```bash
php artisan monterojo:crear-admin
```

Crea un usuario Administrador (para el primero: no hay pantalla de registro).

---

## Qué cambió respecto del sistema anterior

Para quien usa el sistema, nada, salvo dos arreglos:

- **Maestro de productos**: el número de la pestaña "Excepciones de Éxito" ahora se ve también desde
  la pestaña del maestro base (antes ahí salía siempre 0).
- **Cajas por punto de venta**: el aviso de "no hay datos para descargar" ahora muestra su texto
  (antes salía el recuadro vacío).

Por dentro:

- Las sesiones, el login, el CSRF y los permisos los maneja Laravel. Los formularios mandan el token
  como `_token`; los scripts de las pantallas lo siguen mandando como `csrf_token` y se acepta igual.
- Los PDF se devuelven como una respuesta de descarga en vez de cortar el pedido con `exit()`.
- Los archivos que esperan la confirmación de "este Consolidado ya estaba cargado" se guardan en
  `storage/app/importaciones_pendientes` (fuera de lo que se sirve por la web).
- Las sesiones de Laravel no se bloquean entre pedidos, así que la vista previa de un lote grande
  de rótulos (un QR por rótulo) ya no hace fila.

---

## Pasar a producción

Mientras no se haga esto, el sistema que usa la bodega sigue siendo el anterior.

1. **Probar** con los usuarios reales en <http://localhost/monterojo_laravel/> (las cuentas de la
   copia son las mismas de producción, con las mismas contraseñas). Lo que se haga ahí queda en la
   copia; para volver a empezar con los datos del día, se vuelve a copiar la base de producción.
2. En el `.env`, cambiar `DB_DATABASE=monterojo_laravel` por `DB_DATABASE=proyecto_monterojo`, y
   poner `APP_ENV=production` y `APP_DEBUG=false`.
3. Copiar las fotos de perfil nuevas de `proyecto_monterojo/assets/img/perfiles/` a
   `public/assets/img/perfiles/`.
4. `php artisan optimize:clear` (los cachés guardan rutas absolutas de la carpeta).
5. **Mover las direcciones**: renombrar la carpeta vieja (`proyecto_monterojo` → por ejemplo
   `proyecto_monterojo_anterior`) y esta (`monterojo_laravel` → `proyecto_monterojo`), y poner
   `APP_URL=http://localhost/proyecto_monterojo`. Así se conservan las direcciones de siempre, las
   de los QR ya impresos (`/proyecto_monterojo/public/rotulo.php?r=...`) y la del agente de
   impresión de la otra PC (`/proyecto_monterojo/rotulos/agente`).
6. `php artisan config:cache` y `php artisan view:cache`.

Para volver atrás, alcanza con renombrar las carpetas al revés: la base es la misma.
