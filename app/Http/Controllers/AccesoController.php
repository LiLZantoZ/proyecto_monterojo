<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Soporte\Permisos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Entrar y salir del sistema.
 *
 * Mismas reglas que el sistema anterior (modules/login): se entra con cédula y contraseña; tras 5
 * intentos fallidos se bloquea 15 minutos, por IP Y por cuenta (tablas intentos_login e
 * intentos_login_cuenta, con las mismas funciones de antes: app/Servicios/login/login_rate_limit.php);
 * un usuario inactivo o sin rol no entra.
 */
class AccesoController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/compat.php');
        require_once app_path('Servicios/login/login_rate_limit.php');
    }

    public function mostrar(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('inicio');
        }

        $mensajeError = match ($request->query('error', '')) {
            'campos_vacios'            => 'Por favor completa la cédula y la contraseña.',
            'credenciales_incorrectas' => 'Cédula o contraseña incorrecta.',
            'usuario_inactivo'         => 'Este usuario está inactivo. Contacta a un administrador.',
            'rol_no_permitido'         => 'Tu rol no tiene acceso a este sistema.',
            'demasiados_intentos'      => 'Demasiados intentos fallidos. Intenta de nuevo en '
                                          . max(1, (int) $request->query('minutos', 15)) . ' minuto(s).',
            'inactividad'              => 'Tu sesión se cerró automáticamente por inactividad. Vuelve a iniciar sesión.',
            'no_session'               => 'Inicia sesión para continuar.',
            default                    => '',
        };

        return response()
            ->view('auth.login', ['mensajeError' => $mensajeError])
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function entrar(Request $request)
    {
        $pdo = DB::connection()->getPdo();
        $ip  = $request->ip() ?? '0.0.0.0';
        $alLogin = fn (string $error, string $extra = '') => redirect()->to(route('login') . "?error={$error}{$extra}");

        $bloqueo = verificarBloqueoLogin($pdo, $ip);
        if ($bloqueo['bloqueado']) {
            return $alLogin('demasiados_intentos', '&minutos=' . $bloqueo['minutos_restantes']);
        }

        $cedula     = trim((string) $request->input('cedula_usuario', ''));
        $contrasena = trim((string) $request->input('contrasena_usuario', ''));

        if ($cedula === '' || $contrasena === '') {
            return $alLogin('campos_vacios');
        }

        $bloqueoCuenta = verificarBloqueoLoginCuenta($pdo, $cedula);
        if ($bloqueoCuenta['bloqueado']) {
            return $alLogin('demasiados_intentos', '&minutos=' . $bloqueoCuenta['minutos_restantes']);
        }

        $usuario = Usuario::where('cedula_usuario', $cedula)->first();

        // password_verify y no Hash::check: las contraseñas son de password_hash() del sistema
        // anterior, y así se verifican exactamente igual que allá.
        if ($usuario && password_verify($contrasena, $usuario->contrasena_usuario)) {
            if ($usuario->estado !== 'Activo') {
                return $alLogin('usuario_inactivo');
            }
            if (empty($usuario->id_rol)) {
                return $alLogin('rol_no_permitido');
            }

            resetearIntentosLogin($pdo, $ip);
            resetearIntentosLoginCuenta($pdo, $cedula);

            Auth::login($usuario);
            $request->session()->regenerate();   // nuevo id de sesión: evita la fijación de sesión
            $request->session()->put('ultima_actividad', time());
            Permisos::olvidar();

            if (!Permisos::delUsuario()) {
                // Un usuario sin ningún permiso entra a un panel vacío sin explicación: que quede en
                // el log la primera vez y no cuando llame a preguntar.
                logger()->warning("El rol {$usuario->id_rol} no tiene ningún permiso asignado: quien entre con él no va a ver módulos.");
            }

            DB::table('usuarios')->where('id_usuario', $usuario->id_usuario)
                ->update(['fecha_ultimo_acceso' => DB::raw('NOW()')]);

            return redirect()->route('inicio');
        }

        registrarIntentoFallidoLogin($pdo, $ip);
        registrarIntentoFallidoLoginCuenta($pdo, $cedula);

        return $alLogin('credenciales_incorrectas');
    }

    public function salir(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Permisos::olvidar();

        $destino = route('login') . ($request->query('motivo') === 'inactividad' ? '?error=inactividad' : '');

        return redirect()->to($destino)
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
