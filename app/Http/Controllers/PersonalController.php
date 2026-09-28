<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestión del personal de alistamiento: agregar, editar y eliminar. Las reglas (documento único,
 * no asignar entregas a un inactivo, qué pasa con las entregas al eliminar) están en
 * app/Servicios/personal/model_personal.php, traídas tal cual del sistema anterior.
 */
class PersonalController extends Controller
{
    public function __construct()
    {
        require_once app_path('Servicios/personal/model_personal.php');
    }

    public function index()
    {
        $personal = listarPersonal(DB::connection()->getPdo());

        return view('personal.personal', [
            'personal' => $personal,
            'activos'  => count(array_filter($personal, fn ($p) => $p['estado'] === 'Activo')),
            'conCarga' => count(array_filter($personal, fn ($p) => (int) $p['entregas_asignadas'] > 0)),
        ]);
    }

    public function acciones(Request $request)
    {
        $pdo = DB::connection()->getPdo();

        switch ($request->input('accion', '')) {
            case 'crear':
                $resultado = crearPersona($pdo, $request->input('nombre', ''),
                    $request->input('documento', ''), $request->input('cargo', ''));
                break;

            case 'editar':
                $resultado = editarPersona($pdo, $request->input('id_personal', 0),
                    $request->input('nombre', ''), $request->input('documento', ''),
                    $request->input('cargo', ''), $request->input('estado', 'Activo'));
                break;

            case 'eliminar':
                $resultado = eliminarPersona($pdo, $request->input('id_personal', 0));
                break;

            default:
                return redirect()->route('personal');
        }

        // El mensaje trae el nombre de la persona: va como texto, no como código del catálogo.
        guardarMensajeFlashTexto($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);

        return redirect()->route('personal');
    }
}
