<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * El panel de inicio: lo primero que se ve después de entrar. Uno solo para todos los roles; lo
 * que cambia entre roles son los módulos del menú.
 */
class InicioController extends Controller
{
    public function __invoke()
    {
        require_once app_path('Servicios/consolidados/model_consolidados.php');
        $pdo = DB::connection()->getPdo();

        // El estado real del sistema: TODO lo pendiente, sea de una carga del Consolidado o de diez.
        $porCedi = consolidadoPorCedi($pdo);
        $estado  = null;

        if ($porCedi) {
            $totales = ['unidades' => 0, 'cajas' => 0];
            foreach ($porCedi as $filas) {
                $t = totalesDelGrupo($filas);
                $totales['unidades'] += $t['unidades'];
                $totales['cajas']    += $t['cajas'];
            }
            $estado = [
                'cedis'       => count($porCedi),
                'unidades'    => $totales['unidades'],
                'cajas'       => $totales['cajas'],
                'sin_maestro' => pluSinMaestro($pdo),
            ];
        }

        $usuario = auth()->user();

        return view('inicio.panel', [
            'estado'        => $estado,
            'nombreUsuario' => $usuario->nombre_usuario ?? 'Usuario',
            'rolUsuario'    => $usuario->nombreRol() ?? 'Rol no asignado',
            // La foto de campaña de la cabecera; sin ella, el logo sobre negro.
            'hero'          => imagenDeMarca('bienvenida.jpg'),
        ]);
    }
}
