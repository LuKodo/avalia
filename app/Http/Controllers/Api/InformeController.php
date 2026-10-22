<?php

namespace App\Http\Controllers\Api;

use App\Models\Cliente;
use App\Models\Mensajero;
use App\Services\Informes\ArmadorInformes;
use App\Services\Informes\RangoInforme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Los informes del administrador.
 *
 * Una sola entrada —`index`— devuelve los cinco cortes a la vez, porque las
 * cinco preguntas del documento de verificación se hacen sobre el mismo rango y
 * se leen una al lado de la otra. Pedirlas por separado obligaría a la pantalla
 * a hacer cinco viajes para pintar una página, y a la base a repetir cinco
 * veces el mismo recorrido.
 *
 * La exportación comparte filtros con la consulta: lo que el administrador
 * descarga es exactamente lo que está viendo, y eso solo se garantiza si
 * ambas pasan por el mismo armador.
 */
class InformeController extends Controller
{
    public function __construct(private ArmadorInformes $armador) {}

    public function index(Request $request): JsonResponse
    {
        $rango = RangoInforme::desdePeticion($request);

        return response()->json($this->armador->armar(
            $rango,
            $this->enteros($request, 'mensajeros'),
            $this->enteros($request, 'clientes'),
        ));
    }

    /**
     * Los sujetos sobre los que se puede filtrar.
     *
     * Van en su propia entrada porque la pantalla los necesita para pintar los
     * selectores ANTES de tener un rango, y porque cambian de mes en mes, no de
     * consulta en consulta.
     */
    public function filtros(): JsonResponse
    {
        return response()->json([
            'mensajeros' => Mensajero::with('user')->orderBy('codigo')->get()
                ->map(fn (Mensajero $m) => [
                    'id'     => $m->id,
                    'nombre' => $m->nombre(),
                    'codigo' => $m->codigo,
                    'activo' => (bool) $m->activo,
                ])->values(),

            'clientes' => Cliente::orderBy('razon_social')->get()
                ->map(fn (Cliente $c) => [
                    'id'     => $c->id,
                    'nombre' => $c->razon_social,
                    'nit'    => $c->nit,
                    'activo' => (bool) $c->activo,
                ])->values(),
        ]);
    }

    /** @return list<int> */
    private function enteros(Request $request, string $clave): array
    {
        $crudo = $request->query($clave, []);

        // Tolera tanto `clientes[]=1&clientes[]=2` como `clientes=1,2`: el
        // primero lo manda la pantalla, el segundo lo escribe quien arma el
        // enlace a mano para compartir un informe.
        if (is_string($crudo)) {
            $crudo = explode(',', $crudo);
        }

        return collect((array) $crudo)
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
