@extends('layouts.app')

@section('titulo', 'Inicio')

@section('contenido')
    <div class="panel-inicio">

        <div class="welcome-box">
            @if ($hero)
                <img src="{{ $hero }}" alt="Monterojo Gourmet" class="welcome-hero">
            @else
                <div class="welcome-hero-marca">
                    <img src="{{ asset('assets/img/monterojo.png') }}" alt="Monterojo Gourmet">
                </div>
            @endif

            <div class="welcome-texto">
                <h1>Bienvenido: {{ $nombreUsuario }}</h1>
                <p>Tu rol en el sistema es: <strong>{{ $rolUsuario }}</strong></p>
            </div>
        </div>

        @if (!$estado)
            <div class="estado-sistema">
                @if (tienePermiso('modulo_consolidados'))
                    <a class="estado-dato estado-dato-alerta" href="{{ route('consolidados') }}" style="grid-column: 1 / -1;">
                        <span class="estado-dato-valor">Sin pendientes</span>
                        <span class="estado-dato-etiqueta">
                            No hay ningún pedido pendiente ahora mismo. Entra a Consolidados para subir un archivo.
                        </span>
                    </a>
                @endif
            </div>
        @else
            <div class="estado-sistema">
                <div class="estado-dato">
                    <span class="estado-dato-valor">{{ $estado['cedis'] }}</span>
                    <span class="estado-dato-etiqueta">CEDI con pedidos pendientes</span>
                </div>

                <div class="estado-dato">
                    <span class="estado-dato-valor">{{ number_format($estado['unidades'], 0, ',', '.') }}</span>
                    <span class="estado-dato-etiqueta">Unidades pedidas</span>
                </div>

                <div class="estado-dato">
                    <span class="estado-dato-valor">{{ number_format($estado['cajas'], 0, ',', '.') }}</span>
                    <span class="estado-dato-etiqueta">Cajas por alistar</span>
                </div>

                @if ($estado['sin_maestro'] > 0 && tienePermiso('modulo_maestro'))
                    <a class="estado-dato estado-dato-alerta" href="{{ route('maestro', ['ver' => 'faltantes']) }}">
                        <span class="estado-dato-valor">{{ $estado['sin_maestro'] }}</span>
                        <span class="estado-dato-etiqueta">
                            PLU sin unidades por caja. Hasta cargarlos, esas líneas no suman cajas.
                        </span>
                    </a>
                @endif
            </div>
        @endif

        {{-- La galería de sabores está OCULTA por pedido del usuario (2026-09-05), no borrada: en el
             sistema anterior son modules/inicio/layout/portafolio_sabores.php y sabores_lista.php. --}}

    </div>
@endsection
