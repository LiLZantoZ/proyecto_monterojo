<?php
// modules/inicio/layout/estilos.php
// Las hojas de estilo de la aplicación, en UN solo lugar.
//
// Se incluye dentro del <head> de cada vista:
//     include ROOT_PATH . '/modules/inicio/layout/estilos.php';
//
// POR QUÉ EXISTE
// El sistema de bodega empezó con un único style.css de 5.581 líneas y con la línea del <link>
// repetida en las 27 vistas. Encontrar una regla ahí adentro era buscar en un pajar, las reglas
// duplicadas se acumulaban sin que nadie las viera, y cualquier cambio en cómo se cargan los
// estilos había que hacerlo 27 veces. Este proyecto arranca ya partido en piezas.
//
// EL ORDEN DE ESTA LISTA ES LA CASCADA. No es alfabético ni decorativo: si se reordena, reglas
// que hoy pierden pasan a ganar y la interfaz cambia en lugares que nadie está mirando.
//
// PARA AGREGAR ESTILOS NUEVOS: crear un archivo con el número siguiente y agregarlo AL FINAL de
// la lista. Nunca insertarlo en el medio salvo que se sepa exactamente a qué le tiene que ganar.

$hojasDeEstiloApp = [
    '00-tema.css',     // la paleta de Monterojo: variables que usan todas las demás
    '01-base.css',     // reseteo, fondo general y pantalla de login
    '02-inicio.css',   // panel de bienvenida y avisos flotantes
    '03-modulos.css',  // cabecera de pantalla, tablas, filtros, botones y modales
    '04-rotulo.css',   // el rótulo de Picking, en pantalla y en papel
];

foreach ($hojasDeEstiloApp as $hoja) {
    // El ?v= con la fecha de modificación es obligatorio: sin él, el navegador sigue sirviendo la
    // versión vieja después de actualizar el archivo y el cambio "no aparece" aunque ya esté
    // desplegado.
    echo '    <link rel="stylesheet" href="' . BASE_URL . '/assets/css/partes/' . $hoja
       . '?v=' . assetVersion(ROOT_PATH . '/assets/css/partes/' . $hoja) . '">' . "\n";
}
