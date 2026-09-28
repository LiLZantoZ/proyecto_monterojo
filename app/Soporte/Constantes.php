<?php

namespace App\Soporte;

/**
 * Las constantes que usa la lógica de negocio traída del sistema anterior (app/Servicios).
 *
 * Se definen al arrancar cada request (AppServiceProvider::boot), a partir de config/monterojo.php y
 * de la URL de Laravel, con los mismos nombres de antes (BASE_URL, ROOT_PATH, ROTULO_*...).
 */
class Constantes
{
    public static function definir(): void
    {
        if (defined('BASE_URL')) {
            return;
        }

        $c = config('monterojo');

        define('NOMBRE_SISTEMA', $c['nombre_sistema']);
        // '' si el sistema está en la raíz del servidor, o '/carpeta' si está en una subcarpeta.
        define('BASE_URL', rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/'));
        define('URL_LOGIN', BASE_URL . '/login');
        // Los servicios arman rutas como ROOT_PATH . '/assets/img/monterojo.png': los archivos
        // estáticos viven en public/, así que ROOT_PATH apunta ahí.
        define('ROOT_PATH', public_path());
        define('TIEMPO_INACTIVIDAD_SEGUNDOS', $c['inactividad_minutos'] * 60);

        define('ROTULO_ANCHO_MM', $c['rotulo']['ancho_mm']);
        define('ROTULO_ALTO_MM', $c['rotulo']['alto_mm']);
        define('ROTULO_DIBUJO_ANCHO_MM', $c['rotulo']['dibujo_ancho_mm']);
        define('ROTULO_DIBUJO_ALTO_MM', $c['rotulo']['dibujo_alto_mm']);
        define('ROTULO_TSPL_DIRECCION', $c['rotulo']['tspl_direccion']);
        define('ROTULO_TSPL_GAP_MM', $c['rotulo']['tspl_gap_mm']);
        define('ROTULO_TSPL_DENSIDAD', $c['rotulo']['tspl_densidad']);
        define('ROTULO_TSPL_VELOCIDAD', $c['rotulo']['tspl_velocidad']);

        define('IMPRESORA_ROTULOS', $c['impresora_rotulos']);
        define('IMPRESION_ROTULOS_MODO', $c['impresion_rotulos_modo']);
        define('TOKEN_AGENTE_IMPRESION', $c['token_agente_impresion']);
    }

    /**
     * URL_PUBLICA_ROTULOS depende de la dirección por la que entró la petición, así que se define
     * aparte y solo cuando se necesita (la calcula direccionBaseDeRotulos()).
     */
    public static function definirUrlPublicaRotulos(): void
    {
        if (!defined('URL_PUBLICA_ROTULOS')) {
            define('URL_PUBLICA_ROTULOS', direccionBaseDeRotulos());
        }
    }
}
