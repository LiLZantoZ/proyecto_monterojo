<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * La protección CSRF de Laravel, aceptando además el nombre de campo del sistema anterior.
 *
 * Los formularios nuevos mandan `_token` (campoCSRF() / @csrf). Los scripts de las pantallas
 * (picking, historial, rótulos...) mandan el token en el cuerpo JSON como `csrf_token`: se acepta
 * también ese nombre para no tener que reescribirlos.
 */
class VerificarCsrf extends ValidateCsrfToken
{
    /**
     * Rutas que NO llevan token CSRF porque no las usa un navegador con sesión:
     *  · rotulos/agente: el agente de impresión de la otra PC, que se autentica con su propio token.
     */
    protected $except = [
        'rotulos/agente',
    ];

    protected function getTokenFromRequest($request)
    {
        $token = $request->input('csrf_token');

        // Sin el nombre viejo, lo de siempre de Laravel: _token, o las cabeceras X-CSRF/X-XSRF.
        return is_string($token) && $token !== '' ? $token : parent::getTokenFromRequest($request);
    }
}
