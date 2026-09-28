{{-- "Mi perfil": la foto, los datos y la contraseña de la cuenta propia. --}}
@extends('layouts.app')

@section('titulo', 'Mi perfil')

@section('contenido')
    <div class="modulo">

        <header class="modulo-header">
            <h2>Mi perfil</h2>
            <div class="modulo-acciones">
                <a class="btn" href="{{ route('inicio') }}">
                    <i class="fa-solid fa-arrow-left"></i> Volver
                </a>
            </div>
        </header>

        <div class="perfil-grilla">

            {{-- FOTO --}}
            <section class="tarjeta-perfil">
                <h3>Foto de perfil</h3>
                <div class="perfil-foto-actual">
                    <img src="{{ rutaImagenPerfil($usuario['imagen_url_Usuario']) }}" alt="Tu foto de perfil">
                </div>
                <form action="{{ route('perfil.acciones') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="accion" value="subir_imagen">
                    <div class="campo" style="margin-bottom: 0;">
                        <label for="perfil-imagen">Cambiar foto</label>
                        <input type="file" id="perfil-imagen" name="imagen" accept="image/png, image/jpeg, image/webp, image/gif" required>
                        <span class="ayuda">JPG, PNG, WEBP o GIF, hasta 3 MB.</span>
                    </div>
                    <div style="margin-top: 14px;">
                        <button type="submit" class="btn btn-primario"><i class="fa-solid fa-upload"></i> Subir foto</button>
                    </div>
                </form>
            </section>

            {{-- DATOS --}}
            <section class="tarjeta-perfil">
                <h3>Mis datos</h3>
                <form action="{{ route('perfil.acciones') }}" method="POST">
                    @csrf
                    <input type="hidden" name="accion" value="actualizar_datos">

                    <div class="campo">
                        <label for="perfil-nombre">Nombre completo</label>
                        <input type="text" id="perfil-nombre" name="nombre" maxlength="255" required
                               value="{{ $usuario['nombre_usuario'] }}">
                    </div>

                    <div class="campo">
                        <label for="perfil-cedula">Cédula</label>
                        <input type="text" id="perfil-cedula" name="cedula" maxlength="20" required
                               inputmode="numeric" pattern="[0-9]+"
                               value="{{ $usuario['cedula_usuario'] }}">
                        <span class="ayuda">Es la que usás para entrar: solo números, sin puntos ni espacios.</span>
                    </div>

                    <div class="campo" style="margin-bottom: 0;">
                        <label for="perfil-telefono">Teléfono <span class="opcional">(opcional)</span></label>
                        <input type="text" id="perfil-telefono" name="telefono" maxlength="15" autocomplete="off"
                               value="{{ $usuario['telefono_usuario'] ?? '' }}">
                    </div>

                    <div style="margin-top: 14px;">
                        <button type="submit" class="btn btn-primario"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button>
                    </div>
                </form>
            </section>

            {{-- CONTRASEÑA --}}
            <section class="tarjeta-perfil">
                <h3>Cambiar contraseña</h3>
                <form action="{{ route('perfil.acciones') }}" method="POST">
                    @csrf
                    <input type="hidden" name="accion" value="cambiar_contrasena">

                    <div class="campo">
                        <label for="perfil-actual">Contraseña actual</label>
                        <input type="password" id="perfil-actual" name="contrasena_actual" required autocomplete="current-password">
                    </div>

                    <div class="campo">
                        <label for="perfil-nueva">Contraseña nueva</label>
                        <input type="password" id="perfil-nueva" name="contrasena_nueva" required minlength="8" autocomplete="new-password">
                        <span class="ayuda">Al menos 8 caracteres.</span>
                    </div>

                    <div class="campo" style="margin-bottom: 0;">
                        <label for="perfil-confirmar">Confirmar contraseña nueva</label>
                        <input type="password" id="perfil-confirmar" name="contrasena_confirmar" required minlength="8" autocomplete="new-password">
                    </div>

                    <div style="margin-top: 14px;">
                        <button type="submit" class="btn btn-acento"><i class="fa-solid fa-key"></i> Cambiar contraseña</button>
                    </div>
                </form>
            </section>

        </div>

    </div>
@endsection
