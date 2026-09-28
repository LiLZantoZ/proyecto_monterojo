<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "Mi perfil": lo único que cualquier usuario puede cambiar de su propia cuenta. No pide permiso de
 * módulo —solo estar logueado— porque no es gestionar a otros, es editarse a uno mismo.
 *
 * Todas las acciones son sobre la cuenta de QUIEN ESTÁ LOGUEADO: el id sale de la sesión, nunca
 * del formulario, así nadie puede mandar el id de otro usuario y editar una cuenta que no es suya.
 */
class PerfilController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/perfil/model_perfil.php');
    }

    public function index(Request $request)
    {
        $usuario = obtenerUsuarioPerfil(DB::connection()->getPdo(), Auth::id());

        // No debería pasar, salvo que el usuario se haya eliminado desde otra pestaña.
        if (!$usuario) {
            Auth::logout();
            $request->session()->invalidate();
            return redirect()->to(route('login') . '?error=no_session');
        }

        return view('perfil.perfil', ['usuario' => $usuario]);
    }

    public function acciones(Request $request)
    {
        $pdo       = DB::connection()->getPdo();
        $idUsuario = Auth::id();

        switch ($request->input('accion', '')) {
            case 'actualizar_datos':
                $resultado = actualizarDatosPerfil($pdo, $idUsuario, $request->input('nombre', ''),
                    $request->input('cedula', ''), $request->input('telefono', ''));
                break;

            case 'cambiar_contrasena':
                $resultado = cambiarContrasenaPerfil($pdo, $idUsuario,
                    $request->input('contrasena_actual', ''),
                    $request->input('contrasena_nueva', ''),
                    $request->input('contrasena_confirmar', ''));
                break;

            case 'subir_imagen':
                $resultado = $this->subirImagen($pdo, $idUsuario, $request->file('imagen'));
                break;

            default:
                return redirect()->route('perfil');
        }

        // El menú lateral lee el nombre y la foto del usuario de la base en cada pantalla, así que
        // el cambio se ve apenas se vuelve al perfil, sin cerrar sesión.
        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);

        return redirect()->route('perfil');
    }

    /**
     * Sube la foto de perfil. Mismas reglas que el sistema anterior: hasta 3 MB, y tiene que ser
     * una imagen DE VERDAD (se abre con getimagesize, no se confía en la extensión del nombre).
     */
    private function subirImagen($pdo, $idUsuario, ?UploadedFile $archivo): array
    {
        if (!$archivo || !$archivo->isValid()) {
            $error = $archivo?->getError();
            $mensaje = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Esa imagen pesa demasiado.'
                : 'No se pudo recibir la imagen. Probá de nuevo.';
            return ['exito' => false, 'mensaje' => $mensaje];
        }

        if ($archivo->getSize() > 3 * 1024 * 1024) {
            return ['exito' => false, 'mensaje' => 'La imagen no puede pesar más de 3 MB.'];
        }

        $info = @getimagesize($archivo->getRealPath());
        $extensionesPermitidas = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF  => 'gif',
        ];
        if ($info === false || !isset($extensionesPermitidas[$info[2]])) {
            return ['exito' => false, 'mensaje' => 'El archivo tiene que ser una imagen (JPG, PNG, WEBP o GIF).'];
        }

        $carpeta = public_path('assets/img/perfiles');
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        // Nombre propio y no el original: dos "foto.jpg" no se pisan y no hay que sanitizar nada.
        $nombreArchivo = 'usuario_' . $idUsuario . '_' . bin2hex(random_bytes(4)) . '.' . $extensionesPermitidas[$info[2]];

        try {
            $archivo->move($carpeta, $nombreArchivo);
        } catch (\Throwable $e) {
            return ['exito' => false, 'mensaje' => 'No se pudo guardar la imagen. Intentalo de nuevo.'];
        }

        // La foto vieja se borra solo si la subió este mismo mecanismo (vive en perfiles/): nunca se
        // toca la imagen de marca ni la de otro usuario por una coincidencia de ruta.
        $anterior = obtenerUsuarioPerfil($pdo, $idUsuario)['imagen_url_Usuario'] ?? null;

        $rutaRelativa = 'assets/img/perfiles/' . $nombreArchivo;
        $resultado = actualizarImagenPerfil($pdo, $idUsuario, $rutaRelativa);

        if ($resultado['exito']) {
            if ($anterior && str_starts_with($anterior, 'assets/img/perfiles/')) {
                @unlink(public_path($anterior));
            }
        } else {
            @unlink($carpeta . '/' . $nombreArchivo);   // que no quede una foto huérfana
        }

        return $resultado;
    }
}
