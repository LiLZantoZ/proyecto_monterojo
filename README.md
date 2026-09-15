# Sistema Monterojo

Sistema web interno de Monterojo Gourmet. PHP 8 + MySQL sobre XAMPP, sin framework.

Nace del sistema de bodega (`proyecto_bogeda`) y reutiliza de ahí lo que ya estaba resuelto: la
configuración por entorno, la protección CSRF, el freno a la fuerza bruta en el login y el aviso
flotante de resultado. Lo que **no** se copió está explicado más abajo.

Módulos: **Consolidados** (con el maestro de productos) y **Picking**.

---

## Puesta en marcha

Con Apache y MySQL encendidos en XAMPP:

```bash
composer install
```

```bash
php scripts/crear_esquema_completo.php
```

Crea la base `proyecto_monterojo`, sus tablas, el rol Administrador y los permisos. Es
idempotente: correrlo sobre una base que ya existe no toca ningún dato.

```bash
php scripts/crear_usuario_admin.php
```

Pide cédula, nombre y contraseña, y crea el primer administrador. Hace falta porque no hay
pantalla de registro: los usuarios los da de alta un administrador desde adentro, así que alguien
tiene que ser el primero.

Después, entrar en <http://localhost/proyecto_monterojo/>.

---

## Cómo se usa, en orden

1. **Maestro de productos** → *Cargar desde SAP*. Es lo primero, y sin esto las otras dos
   pantallas muestran unidades pero no cajas.
2. **Consolidados** → *Importar Consolidado*: el archivo del día que manda la cadena.
3. **Consolidados** muestra, por CEDI, qué hay que bajar de bodega. El botón **PDF de este CEDI**
   saca la hoja para el elevador; **PDF de todos los CEDI** las saca todas juntas, una por hoja.
4. **Picking** abre lo mismo por punto de venta. Ahí se escribe el **pedido SAP** de cada entrega
   y el botón **Rótulo** de cada fila saca los rótulos numerados para pegar en las cajas.

### Por qué el maestro es un módulo aparte

El Consolidado trae el PLU y las unidades, pero la columna «Descripcion del item» **viene vacía**
y no hay ninguna con las unidades por caja. Sin ese número no se puede pasar de unidades a cajas,
que es en lo que trabajan el elevador y el picker.

El maestro se llena desde el **export de facturación de SAP**, que sí trae todo:

| Del archivo de SAP | Va a |
|---|---|
| `Material` | SKU (la clave del maestro) |
| `Texto breve de material` | Descripción **y** unidades por caja |
| `Código EAN/UPC` | EAN — el puente con el Consolidado |
| `Ctd.facturada` + `Peso bruto` | Peso de una unidad |

**Las unidades por caja salen del nombre del material.** SAP escribe el empaque al final:
`PAPAS SAL ROSADA MR 100G PX20` son 20 por caja, y `PAPAS LIMA LIMÓN MR 25G BX6x16` son 16.
No se usa la división `Ctd.facturada / Cajas Físicas` porque SAP redondea las cajas a dos
decimales y esa cuenta devuelve 95 donde el empaque real es 96 — suficiente para que la conversión
salga corrida en los pedidos grandes. Sobre el archivo de prueba las dos fuentes coincidieron en
50 de 56 materiales, y las 6 diferencias eran de ±1 por ese redondeo.

**El EAN es lo que une los dos mundos.** El export de SAP no sabe qué PLU le puso la cadena a cada
producto, y el Consolidado no sabe el SKU. El EAN aparece en los dos, así que el sistema cruza por
ahí y completa el PLU solo, después de cada importación de cualquiera de los dos archivos.

Mientras un producto no esté en el maestro, su fila aparece marcada, con una raya en vez de cajas
y **sin sumar en los totales** — nunca con un cero, que se leería como «no lleva ninguna caja».
`Maestro de productos → Ver solo los que faltan` lista justo esos productos, ordenados por cuántas
unidades se pidieron.

Las cajas se calculan **al consultar**, no al importar: cargar el maestro después las hace
aparecer solas, sin volver a subir el Consolidado.

### Por qué Consolidados y Picking dan cajas distintas

No es un error: es la misma cantidad partida de otra forma. Consolidados suma todo un CEDI, así
que muchas unidades alcanzan a completar cajas; Picking abre por tienda, y una tienda que recibe
8 unidades de un producto que viene de a 16 no completa ninguna. El total de **unidades** sí es
idéntico en las dos pantallas, y es el que hay que comparar.

### El rótulo

Cada rótulo lleva punto de venta, orden de compra, cajas totales, **producto** y CEDI.

La numeración es **corrida sobre el pedido entero**, no por producto: si la tienda recibe 8 cajas
repartidas en 4 productos, las etiquetas van `CAJ 1 DE 8` … `CAJ 8 DE 8` en el orden de la tabla.
Numerando por producto, un pedido con tres productos de dos cajas producía tres etiquetas
distintas que decían todas «CAJ 1 DE 2», y quien descarga no tenía forma de saber si llegó todo.

### Cajas completas vs. cajas físicas

Son dos cuentas distintas y conviene no confundirlas:

- La columna **Cajas** de las tablas son cajas **completas**: 8 unidades de un producto que viene
  de a 12 son `0 cajas` y `8 saldos`. Es lo que necesita el elevador, que baja estibas de cajas
  llenas.
- Los **rótulos** cuentan cajas **físicas**, redondeando hacia arriba: esas 8 unidades igual viajan
  dentro de una caja aunque no la llenen. Un pedido de tres productos con 8 unidades cada uno son
  **tres cajas**, no cero.

Cuando el producto no está en el maestro no se puede dividir, así que se cuenta 1: hay unidades,
luego hay al menos una caja. Es lo mínimo cierto, y el rótulo es editable para corregirlo.

Hay un botón en cada nivel: el de la **fila del pedido** saca las 8 de una, y el de **cada
producto** reimprime solo su tramo (las cajas 3 y 4 de 8, por ejemplo) sin volver a empezar en 1.

### Trabajar con varios pedidos a la vez

Cada fila lleva una casilla, y el encabezado de cada CEDI una de «todos» (uno por grupo y no uno
global: se trabaja un CEDI a la vez). Con al menos uno tildado aparece abajo una barra flotante
—abajo y no encima de la tabla porque con 97 entregas hay que scrollear— con tres acciones sobre
**solo lo seleccionado**:

| Acción | Qué hace |
|---|---|
| **Asignar personal** | Abre el mismo modal y asigna a todos los tildados de una |
| **Rótulos** | Junta las etiquetas de todos en una sola impresión |
| **Imprimir hojas** | Un único PDF con una hoja de alistamiento por pedido |

En los rótulos masivos **cada pedido conserva su propia numeración** (`CAJ 1 DE 3` del uno,
`CAJ 1 DE 8` del otro): son envíos a tiendas distintas y cada una cuenta las suyas. Lo que se junta
es la impresión, no la numeración. Ahí el panel de edición se oculta, porque un solo juego de
campos no puede representar a varios pedidos sin mentir sobre alguno.

Todos los campos son **editables antes de imprimir**, incluida la cantidad, desde qué caja
arranca y el total. Lo que se cambia ahí vale solo para esos rótulos: no toca el Consolidado ni el
pedido SAP guardado. Por eso el botón nunca se deshabilita, ni siquiera cuando el sistema no pudo
calcular las cajas — se abre, se ajusta a mano y se imprime.

El **pedido SAP es de la entrega, no del producto**: al escribirlo en una fila se guarda en todos
los productos de ese punto de venta dentro de esa orden de compra, y las demás filas se actualizan
solas. Si fuera por producto, la misma entrega tendría un número distinto en cada caja.

Los **saldos no llevan rótulo**: la numeración es sobre cajas completas. Cuando hay saldos el
modal lo avisa, y una fila que no completa ninguna caja tiene el botón deshabilitado explicando
por qué.

Cada rótulo lleva además un **código de barras Code 128** con el identificador de esa caja:

```
orden de compra - EAN de la tienda - número de caja
0050310013-7701001145006-3
```

Los tres alcanzan para que sea único porque la numeración es corrida sobre el pedido: la caja 3 de
un pedido es una sola, sin importar qué producto lleve adentro. La tienda va por su **EAN** y no
por el código del nombre porque el nombre no sirve como fuente — de las 69 tiendas del archivo, 14
no lo llevan adelante (`TURBO CARULLA LIMONAR-4845`) y alguna no lo tiene en ninguna parte
(`Carulla La Maria`).

El mismo identificador se imprime en texto debajo de las barras, para poder teclearlo si el lector
no engancha. El código lo genera el servidor como SVG
(`controller_picking.php?accion=codigo_barras`) y no una librería de JavaScript por CDN: en la
bodega, un rótulo sin código de barras no sirve, así que no puede depender de que haya internet.

---

## Estructura

```
config/          configuración, seguridad y helpers compartidos
  config.php         entorno, sesión, cabeceras, conexión PDO y las constantes del sistema
  csrf.php           token de formulario
  auth_guard.php     guardián de las pantallas privadas
  login_rate_limit.php  bloqueo por intentos fallidos (por IP y por cédula)
  permisos.php       tienePermiso() / requierePermiso()
  mensajes.php       catálogo de textos de los avisos

modules/
  login/           formulario, controlador, modelo y cierre de sesión
  inicio/
    dashboard.php  el panel de bienvenida
    layout/        sidebar, hojas de estilo y el aviso flotante
  consolidados/
    model_consolidados.php         consultas + desglosarCajas(), el cálculo central
    model_consolidados_import.php  lectura de los dos Excel
    helper_consolidado_pdf.php     el PDF por CEDI (dompdf)
    controller_consolidados.php
    views/                         consolidados.php y maestro.php
  picking/
    model_picking.php              filas por punto de venta y el pedido SAP
    controller_picking.php         guarda el pedido SAP (JSON)
    views/picking.php
    layouts/                       modal del rótulo y su JavaScript

assets/css/partes/  las hojas, numeradas: el orden ES la cascada (ver estilos.php)
assets/js/          JavaScript compartido por varias pantallas
assets/img/         logo y avatar por defecto

scripts/          tareas de línea de comandos (esquema, primer usuario, preparación del logo)
```

### Tablas

| Tabla | Qué guarda |
|---|---|
| `maestro_productos` | Clave **SKU**; EAN y PLU con índice único, descripción, empaque, unidades por caja, peso |
| `personal` | quiénes alistan: nombre, documento, cargo, estado |
| `consolidado_cargas` | una fila por importación: de qué archivo salió lo que se ve |
| `consolidado_lineas` | las líneas del Excel, más el `id_personal` que se asigna en Picking |

`personal` es una tabla **aparte de `usuarios`** a propósito: el que alista no entra al sistema —no
tiene contraseña, ni rol, ni permisos—, y darle un usuario solo para poder asignarle un pedido
significaría crear cuentas que nadie usa. La asignación es de la **entrega** (CEDI + O/C + punto de
venta), no del producto, y se escribe en todas sus líneas. Eliminar a alguien deja sus entregas
**sin asignar**, nunca las borra (`ON DELETE SET NULL`).

`consolidado_lineas` **no guarda cajas ni saldos** a propósito: se calculan al consultar cruzando
con el maestro. Guardadas, cargar el maestro después las dejaría en cero para siempre.

Importar el Consolidado **reemplaza lo pendiente** (el archivo del día es la foto entera de lo que
falta), pero **conserva para siempre lo que ya se despachó** — eso es el Historial de Pedidos, y no
tiene por qué borrarse solo porque llegó el archivo de mañana. Importar el maestro **no** reemplaza
nada: hace UPSERT, porque se completa de a poco.

### Aviso de archivo repetido

Si el Consolidado que se sube trae **exactamente la misma información** que el que ya está
cargado, no se importa: se pregunta con un **Sí / No**. Reimportar reemplaza las líneas actuales y
con ellas se pierden las asignaciones de personal ya hechas sobre ese pedido, así que rehacer sin
querer el trabajo del día es un costo real.

La comparación es del **contenido**, no del archivo: `consolidado_cargas.huella` guarda un SHA-256
de los valores que se importan, con las líneas ordenadas. Dos exportaciones del mismo pedido tienen
bytes distintos —cambia la fecha interna, la versión de Excel— pero la misma información, y
comparar bytes no detectaría nada.

Mientras se espera la respuesta, el archivo queda en `temp/` (con `.htaccess` que niega el acceso
web): el archivo subido vive en el temporal de PHP y desaparece al terminar el request, así que sin
guardarlo un «Sí» no tendría nada que importar. Se borra al contestar, y al empezar otra carga.

---

## Las fotos de marca

El diseño está armado para llevar fotos de producto y de campaña, pero **ninguna es obligatoria**:
el archivo que no exista se reemplaza por el logo sobre negro, no por una imagen rota. Se copian
en `assets/img/` con el nombre exacto y aparecen solas, sin tocar código.

| Archivo | Dónde sale |
|---|---|
| `assets/img/fondo.jpg` | Fondo de todas las pantallas (con un velo vinoso encima) |
| `assets/img/bienvenida.jpg` | Cabecera de la tarjeta del panel de inicio |
| `assets/img/stand.jpg` | Miniatura del bloque «Portafolio Monterojo Gourmet» |
| `assets/img/sabores/*.jpg` | Una por sabor de la galería |

La lista completa, con las medidas y qué foto va bien en cada sitio, está en
[`assets/img/LEEME.txt`](assets/img/LEEME.txt). Para agregar o quitar un sabor de la galería se
edita un solo archivo: `modules/inicio/layout/sabores_lista.php`.

---

## Decisiones que conviene conocer antes de tocar el código

**Una sola pantalla de login.** El sistema de bodega tiene una portada donde se elige el perfil y
después el formulario, pero esa portada no decide nada: los cuatro perfiles llevan al mismo
formulario y el rol real sale de la cédula. Acá se entra de una.

**Un solo panel para todos los roles.** Allá hay tres paneles casi idénticos y la lista de "qué rol
va a qué panel" está copiada en cuatro archivos; cuando se desincronizan, el panel devuelve al
usuario al login y el login lo devuelve al panel, en bucle. Acá lo que cambia entre roles son los
módulos del menú, no la pantalla de inicio. Si algún día un rol necesita la suya, el desvío se
agrega en `urlPanelDelRol()` (config/config.php) y en ningún otro lado.

**Los permisos van por tabla, no por número de rol.** Un módulo nuevo se muestra con
`tienePermiso('modulo_loquesea')`, nunca con `if ($_SESSION['usuario_rol'] == 1)`. Con la
comparación escrita a mano, agregar un rol obliga a revisar el proyecto entero buscando ifs.
El reparto de permisos vive en `scripts/crear_esquema_completo.php`.

**Ocultar el enlace no es control de acceso.** Todo controlador de módulo tiene que llamar además a
`requierePermiso()`, o se entra igual escribiendo la URL a mano.

**Los colores salen de `00-tema.css`.** Ninguna otra hoja escribe un color literal. En el sistema de
bodega el verde de marca está escrito a mano en decenas de reglas repartidas en quince archivos, y
cambiar un tono es buscar y reemplazar a ciegas.

**Las rutas se arman con `BASE_URL`**, nunca con `/proyecto_monterojo/...` escrito a mano: en un
servidor de verdad la aplicación cuelga de la raíz del dominio y esa cadena no existe.

**Los `<link>` y `<script>` llevan `?v=` con `assetVersion()`.** Sin eso el navegador sigue sirviendo
la versión en caché y un cambio ya desplegado "no aparece".

---

## Configuración en el servidor

Nada de esto se edita en el código: se definen como variables de entorno.

| Variable   | Por defecto (local)   | En producción                                  |
|------------|-----------------------|------------------------------------------------|
| `APP_ENV`  | `development`         | `production` — oculta los errores y los manda al log |
| `DB_HOST`  | `localhost`           | el host del hosting                            |
| `DB_NAME`  | `proyecto_monterojo`  | suele venir con prefijo de cuenta               |
| `DB_USER`  | `root`                | el usuario de la base                          |
| `DB_PASS`  | vacío                 | la contraseña                                  |
| `BASE_URL` | `/proyecto_monterojo` | **cadena vacía** si cuelga de la raíz del dominio |

---

## Seguridad ya incorporada

- Contraseñas con `password_hash()` / `password_verify()`; nunca en texto plano.
- Token CSRF en el formulario de login (y disponible para los que vengan).
- Bloqueo tras 5 intentos fallidos durante 15 minutos, en dos capas: por IP y por cédula. La
  segunda existe porque un atacante con IPs rotativas podría probar contra una sola cédula sin
  activar nunca la primera.
- `session_regenerate_id(true)` al autenticar, contra la fijación de sesión.
- Cookie de sesión `httponly`, `samesite=Strict` y `secure` automático bajo HTTPS.
- Cierre por inactividad a los 10 minutos, verificado **en el servidor** (`auth_guard.php`). El
  temporizador de JavaScript del sidebar es solo comodidad.
- Cabeceras `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` y CSP.
- `.htaccess` que bloquea el acceso web a `config/` y a `scripts/`.
- El login responde lo mismo ante una cédula que no existe y una contraseña equivocada: distinguir
  los dos casos le diría a quien prueba al azar cuáles de sus intentos son cédulas reales.
