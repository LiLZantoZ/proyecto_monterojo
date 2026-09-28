{{-- La plantilla de todas las pantallas privadas: cabecera con los estilos, menú lateral y el área
     de contenido. Cada pantalla completa:
       @section('titulo')     el título de la pestaña
       @push('estilos')       hojas de estilo propias
       @section('contenido')  lo que va dentro de .content-area
       @section('modales')    los modales, fuera de .app-shell
       @push('scripts')       sus scripts, al final del body --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo') · {{ NOMBRE_SISTEMA }}</title>
    @include('partes.estilos')
    @stack('estilos')
</head>
<body{!! atributosDelCuerpo($claseCuerpo ?? '') !!}>

<div class="app-shell">
    @include('partes.menu')

    <div class="content-area">
        @yield('contenido')
    </div>
</div>

@yield('modales')

@stack('scripts')
</body>
</html>
