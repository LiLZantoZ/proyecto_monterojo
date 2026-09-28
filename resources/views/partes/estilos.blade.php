{{-- Las hojas de estilo de la aplicación, en UN solo lugar.
     EL ORDEN DE ESTA LISTA ES LA CASCADA: si se reordena, reglas que hoy pierden pasan a ganar.
     Para agregar estilos nuevos: un archivo con el número siguiente, AL FINAL de la lista. --}}
@php
    $hojasDeEstiloApp = [
        '00-tema.css',                 // la paleta de Monterojo: variables que usan todas las demás
        '01-base.css',                 // reseteo, fondo general y pantalla de login
        '02-inicio.css',               // panel de bienvenida y avisos flotantes
        '03-modulos.css',              // cabecera de pantalla, tablas, filtros, botones y modales
        '04-rotulo.css',               // el rótulo de Picking, en pantalla y en papel
        '05-ordenes-compra.css',       // Órdenes de compra del Éxito
        '06-listas-desplegables.css',  // las listas de los select y del autocompletado
    ];
@endphp
@foreach ($hojasDeEstiloApp as $hoja)
    <link rel="stylesheet" href="{{ assetV('assets/css/partes/' . $hoja) }}">
@endforeach
