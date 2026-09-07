<?php
// modules/inicio/layout/sabores_lista.php
// El portafolio de Monterojo Gourmet, en un solo lugar.
//
// Vive acá, y no dentro de la pantalla que lo pinta, para que agregar o quitar un sabor sea tocar
// UN archivo. En el sistema de bodega la lista de marcas estaba duplicada en dos pantallas y
// cualquiera de las dos podía quedar mostrando un portafolio que ya no era.
//
// 'img' es la ruta dentro de assets/img/. Si ese archivo todavía no está en disco, la galería
// muestra el logo en su lugar en vez de una imagen rota (ver imagenDeMarca() en config.php).

function saboresDelPortafolio(): array {
    return [
        ['img' => 'sabores/bbq_dulce.jpg',    'etiqueta' => 'BBQ Dulce',              'tipo' => 'linea'],
        ['img' => 'sabores/lima_limon.jpg',   'etiqueta' => 'Lima - Limón',           'tipo' => 'linea'],
        ['img' => 'sabores/sal_rosada.jpg',   'etiqueta' => 'Sal Rosada del Himalaya','tipo' => 'linea'],
        // Las ediciones especiales van marcadas distinto: son lanzamientos con otra marca
        // (Ron Viejo de Caldas, Club Colombia) y no parte del portafolio permanente.
        ['img' => 'sabores/ron_viejo_caldas.jpg', 'etiqueta' => 'Tributo a Colombia · Ron Viejo de Caldas', 'tipo' => 'especial'],
        ['img' => 'sabores/club_colombia.jpg',    'etiqueta' => 'BBQ Dulce · Club Colombia',               'tipo' => 'especial'],
    ];
}
