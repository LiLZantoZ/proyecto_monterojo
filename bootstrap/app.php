<?php

use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\RequierePermiso;
use App\Http\Middleware\SesionActiva;
use App\Http\Middleware\VerificarCsrf;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSRF con el nombre de campo del sistema anterior (csrf_token) además de _token.
        $middleware->web(replace: [ValidateCsrfToken::class => VerificarCsrf::class]);
        $middleware->web(append: [CabecerasSeguridad::class]);

        $middleware->alias([
            'sesion'  => SesionActiva::class,
            'permiso' => RequierePermiso::class,
        ]);

        // Las pantallas privadas mandan al login a quien no tiene sesión (lo hace SesionActiva con
        // el ?error= correcto); esto es solo por si alguna ruta usa el 'auth' estándar.
        $middleware->redirectGuestsTo(fn () => route('login') . '?error=no_session');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Token CSRF vencido (la página quedó abierta mucho tiempo). En vez de la pantalla 419 de
        // Laravel, lo mismo que hacía el sistema anterior: a un script, JSON con el error; a un
        // formulario, de vuelta a la página con el aviso.
        $vencido = function (Request $request) {
            $texto = textoMensajeSistema('error', 'csrf');
            if ($request->expectsJson() || $request->isJson()
                || ($request->header('Sec-Fetch-Dest', 'document') !== 'document')) {
                return response()->json(['exito' => false, 'error' => $texto], 403);
            }
            guardarMensajeFlash('error', 'csrf');
            return redirect()->back(302, [], route('login'));
        };

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($vencido) {
            return $vencido($request);
        });
        $exceptions->render(function (HttpException $e, Request $request) use ($vencido) {
            return $e->getStatusCode() === 419 ? $vencido($request) : null;
        });
    })->create();
