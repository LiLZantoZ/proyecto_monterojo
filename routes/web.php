<?php

// routes/web.php
// LAS RUTAS DEL SISTEMA: las mismas direcciones que el sistema anterior (config/rutas.php), para
// que los marcadores, los enlaces copiados y las costumbres de la gente sigan sirviendo.
//
// Cada pantalla y su dirección de acciones llevan:
//   · 'sesion'            → sesión iniciada y no vencida por inactividad (App\Http\Middleware\SesionActiva)
//   · 'permiso:modulo_x'  → el permiso del módulo (App\Http\Middleware\RequierePermiso)
// Las acciones siguen siendo una dirección por módulo (/modulo/acciones) con el campo "accion",
// como antes: así los formularios y los scripts de las pantallas no cambian.

use App\Http\Controllers\AccesoController;
use App\Http\Controllers\AgenteImpresionController;
use App\Http\Controllers\CajasPuntoVentaController;
use App\Http\Controllers\ConsolidadoMrController;
use App\Http\Controllers\ConsolidadosController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\OrdenesCompraController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PersonalController;
use App\Http\Controllers\PickingController;
use App\Http\Controllers\RotuloPublicoController;
use App\Http\Controllers\RotulosController;
use App\Http\Controllers\RotulosEnlaceController;
use App\Http\Controllers\SeguimientoController;
use Illuminate\Support\Facades\Route;

// ---------------- Entrar y salir ----------------
Route::redirect('/', 'login');
Route::get('login', [AccesoController::class, 'mostrar'])->name('login');
Route::post('login/entrar', [AccesoController::class, 'entrar'])->name('login.entrar');
Route::get('salir', [AccesoController::class, 'salir'])->name('salir');

// ---------------- Lo que se abre SIN sesión ----------------
// La página que abren los QR de los rótulos. Su dirección está IMPRESA en las etiquetas que ya
// circulan (.../public/rotulo.php?t=...), así que tiene que seguir respondiendo exactamente ahí.
// (Con /public/ en la dirección, Laravel la resuelve como "rotulo.php": ver public/index.php.)
Route::get('rotulo.php', RotuloPublicoController::class)->name('rotulo.publico');
Route::get('public/rotulo.php', RotuloPublicoController::class);

// El agente de impresión de la otra PC: se autentica con su propio token, no con sesión. Sin el
// manejo de sesión y cookies de 'web': pregunta cada pocos segundos y cada pregunta abriría una
// sesión nueva que nadie usa.
Route::match(['get', 'post'], 'rotulos/agente', AgenteImpresionController::class)
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerificarCsrf::class,
    ])
    ->name('rotulos.agente');

// El script del agente de impresión, para bajarlo desde la PC de la etiquetadora. En el sistema
// anterior estaba en public/agente_impresion_remota.ps1; se sirve en esa misma dirección (y en la
// raíz) desde scripts/, que es donde vive la única copia.
$agentePs1 = fn () => response()->file(base_path('scripts/agente_impresion_remota.ps1'), [
    'Content-Type' => 'text/plain; charset=utf-8',
]);
Route::get('agente_impresion_remota.ps1', $agentePs1);
Route::get('public/agente_impresion_remota.ps1', $agentePs1);

// ---------------- Pantallas privadas ----------------
Route::middleware('sesion')->group(function () {

    Route::get('inicio', InicioController::class)->name('inicio');

    // Mi perfil: pide perfil_editar (2026-09-28): el rol Visitante entra a mirar con una cuenta
    // compartida y no puede cambiarle el nombre, la foto ni la contraseña.
    Route::middleware('permiso:perfil_editar')->group(function () {
        Route::get('perfil', [PerfilController::class, 'index'])->name('perfil');
        Route::post('perfil/acciones', [PerfilController::class, 'acciones'])->name('perfil.acciones');
    });

    // Consolidados y el maestro de productos comparten la dirección de acciones; cada acción
    // exige su propio permiso adentro del controlador (unas son de Consolidados y otras del Maestro).
    Route::get('consolidados', [ConsolidadosController::class, 'index'])
        ->middleware('permiso:modulo_consolidados')->name('consolidados');
    Route::get('maestro', [ConsolidadosController::class, 'maestro'])
        ->middleware('permiso:modulo_maestro')->name('maestro');
    Route::match(['get', 'post'], 'consolidados/acciones', [ConsolidadosController::class, 'acciones'])
        ->name('consolidados.acciones');

    Route::middleware('permiso:modulo_picking')->group(function () {
        Route::get('picking', [PickingController::class, 'index'])->name('picking');
        Route::match(['get', 'post'], 'picking/acciones', [PickingController::class, 'acciones'])->name('picking.acciones');
    });

    Route::middleware('permiso:modulo_personal')->group(function () {
        Route::get('personal', [PersonalController::class, 'index'])->name('personal');
        Route::post('personal/acciones', [PersonalController::class, 'acciones'])->name('personal.acciones');
    });

    Route::middleware('permiso:modulo_historial')->group(function () {
        Route::get('historial', [HistorialController::class, 'index'])->name('historial');
        Route::match(['get', 'post'], 'historial/acciones', [HistorialController::class, 'acciones'])->name('historial.acciones');
    });

    // Los enlaces de los QR (crear los tokens y dibujar el QR). Los usan Picking, el Historial,
    // Cajas y "Generar rótulos": el controlador acepta cualquiera de esos cuatro permisos.
    Route::match(['get', 'post'], 'rotulos/enlaces', RotulosEnlaceController::class)->name('rotulos.enlaces');

    Route::middleware('permiso:modulo_cajas_punto_venta')->group(function () {
        Route::get('cajas-punto-venta', [CajasPuntoVentaController::class, 'index'])->name('cajas_punto_venta');
        Route::match(['get', 'post'], 'cajas-punto-venta/acciones', [CajasPuntoVentaController::class, 'acciones'])->name('cajas_punto_venta.acciones');
    });

    Route::middleware('permiso:modulo_rotulos')->group(function () {
        Route::get('rotulos', [RotulosController::class, 'index'])->name('rotulos');
        Route::match(['get', 'post'], 'rotulos/acciones', [RotulosController::class, 'acciones'])->name('rotulos.acciones');
    });

    Route::middleware('permiso:modulo_ordenes_compra')->group(function () {
        Route::get('ordenes-compra', [OrdenesCompraController::class, 'index'])->name('ordenes_compra');
        Route::match(['get', 'post'], 'ordenes-compra/acciones', [OrdenesCompraController::class, 'acciones'])->name('ordenes_compra.acciones');
    });

    Route::middleware('permiso:modulo_seguimiento')->group(function () {
        Route::get('seguimiento', [SeguimientoController::class, 'index'])->name('seguimiento');
        Route::post('seguimiento/acciones', [SeguimientoController::class, 'acciones'])->name('seguimiento.acciones');
    });

    Route::middleware('permiso:modulo_consolidado_mr')->group(function () {
        Route::get('consolidado-mr', [ConsolidadoMrController::class, 'index'])->name('consolidado_mr');
        // Importar y vaciar: solo con consolidado_mr_editar (el Visitante solo consulta).
        Route::post('consolidado-mr/acciones', [ConsolidadoMrController::class, 'acciones'])
            ->middleware('permiso:consolidado_mr_editar')->name('consolidado_mr.acciones');
    });
});
