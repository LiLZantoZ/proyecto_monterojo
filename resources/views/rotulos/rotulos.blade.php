{{-- Generador de rótulos SUELTOS: para una etiqueta que no viene de ningún pedido del sistema.
     Es la misma maqueta y el mismo cálculo que el botón "Rótulos" de Picking y del Historial, pero
     el formulario se llena a mano; por eso la pantalla entera es lo que allá es un modal. --}}
@extends('layouts.app')

@section('titulo', 'Generar rótulos')

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Generar rótulos</h2>
        </header>

        <div class="aviso aviso-info">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                Esto genera un rótulo suelto: no queda relacionado con ningún Consolidado ni
                ninguna entrega. Para reimprimir el rótulo de un pedido real, usá el botón
                <strong>Rótulos</strong> desde Picking o desde el Historial de Pedidos.
            </div>
        </div>

        <div class="rotulo-controles">
            <div class="campo">
                <label for="rot-cantidad">Cuántos rótulos</label>
                <input type="number" id="rot-cantidad" min="1" max="99" step="1" value="1">
            </div>
            <div class="campo">
                <label for="rot-desde">Desde la caja Nº</label>
                <input type="number" id="rot-desde" min="1" max="999" step="1" value="1">
            </div>
            <div class="campo">
                <label for="rot-total">Cajas del pedido</label>
                <input type="number" id="rot-total" min="1" max="999" step="1" value="1">
            </div>
            <div class="campo campo-ancho">
                <label for="rot-pv">Punto de venta</label>
                <input type="text" id="rot-pv" maxlength="180">
            </div>
            <div class="campo">
                <label for="rot-numero-pv">N° punto de venta <span class="opcional">(opcional)</span></label>
                <input type="text" id="rot-numero-pv" maxlength="20">
            </div>
            <div class="campo campo-ancho">
                <label for="rot-cedi">CEDI</label>
                <input type="text" id="rot-cedi" maxlength="120">
            </div>
            <div class="campo campo-ancho">
                <label for="rot-producto">Producto</label>
                <input type="text" id="rot-producto" maxlength="255" placeholder="Nombre del producto">
            </div>
            <div class="campo">
                <label for="rot-sku">SKU <span class="opcional">(opcional)</span></label>
                <input type="text" id="rot-sku" maxlength="30">
            </div>
            <div class="campo">
                <label for="rot-ean">EAN del producto <span class="opcional">(opcional)</span></label>
                <input type="text" id="rot-ean" maxlength="20" placeholder="Se ve al escanear el QR">
            </div>
        </div>

        <p class="rotulo-nota">
            <i class="fa-solid fa-circle-info"></i>
            Cambiar cualquier campo vuelve a dibujar todos los rótulos de abajo.
        </p>

        {{-- Cómo salió el envío a la etiquetadora: pegado a la vista previa. --}}
        <div class="aviso" id="rotulo-aviso-impresion" hidden>
            <i class="fa-solid fa-circle-info"></i>
            <div id="rotulo-aviso-impresion-texto"></div>
        </div>

        <div class="rotulos-previa" id="rotulos-previa"></div>

        <div class="modulo-acciones" style="margin-top: 18px; justify-content: flex-start;">
            <button type="button" class="btn" id="btn-limpiar-rotulos">
                <i class="fa-solid fa-broom"></i> Limpiar
            </button>
            {{-- El sistema le habla directo a la etiquetadora en su propio idioma (TSPL). --}}
            <button type="button" class="btn btn-primario" id="btn-imprimir-etiquetadora">
                <i class="fa-solid fa-tags"></i> Imprimir en la etiquetadora
            </button>

            {{-- El PDF va por un formulario normal para que el navegador lo trate como descarga.
                 Los campos ocultos los llena scripts_rotulos.js justo antes de enviarlo. --}}
            <form action="{{ route('rotulos.acciones') }}" method="POST" id="form-rotulos-pdf">
                @csrf
                <input type="hidden" name="accion" value="pdf">
                <input type="hidden" name="rotulos" id="rotulos-pdf-datos">
                <button type="submit" class="btn">
                    <i class="fa-solid fa-file-pdf"></i> Descargar PDF
                </button>
            </form>
        </div>

    </div>
@endsection

@push('scripts')
    @include('partes.constantes_js')
    <script src="{{ assetV('assets/js/rotulo.js') }}"></script>
    <script src="{{ assetV('modulos/rotulos/scripts_rotulos.js') }}"></script>
@endpush
