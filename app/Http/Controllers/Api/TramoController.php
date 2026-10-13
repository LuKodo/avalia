<?php

namespace App\Http\Controllers\Api;

use App\Models\Mensajero;
use App\Models\Paquete;
use App\Models\Sede;
use App\Models\Tramo;
use App\Services\Operacion\CadenaDeTramos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Los mandos de la cadena de trayectos.
 *
 * Quién usa qué: la operación arma, reencamina, asigna y programa; el mensajero
 * solo cierra el tramo que lleva encima. Esa separación está en las rutas, no
 * aquí — ver `routes/api.php`.
 */
class TramoController extends Controller
{
    public function __construct(private readonly CadenaDeTramos $cadena)
    {
    }

    /**
     * GET /despacho/catalogo
     *
     * Lo que los mandos de la cadena necesitan para ofrecer opciones: centros
     * propios y mensajeros. Va aparte del catalogo de radicacion porque
     * responde a otra pregunta -aquel dice «que puedo radicar», este «a quien
     * puedo asignarlo»- y mezclarlos obligaria a traer sedes de todos los
     * clientes solo para pintar dos desplegables.
     */
    public function catalogo(): JsonResponse
    {
        return response()->json([
            // Solo sedes NUESTRAS: consolidar en la bodega de otro cliente
            // seria dejarle la carga a un tercero.
            'centros' => Sede::whereNull('cliente_id')
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'ciudad', 'direccion'])
                ->all(),

            'mensajeros' => Mensajero::with('user:id,nombre', 'vehiculo:id,placa')
                ->where('activo', true)
                ->get()
                ->map(fn (Mensajero $m) => [
                    'id'       => $m->id,
                    'nombre'   => $m->user?->nombre ?? 'Mensajero '.$m->id,
                    'vehiculo' => $m->vehiculo?->placa,
                ])
                ->sortBy('nombre')
                ->values()
                ->all(),
        ]);
    }

    /** GET /despacho/paquetes/{paquete}/tramos */
    public function index(Paquete $paquete): JsonResponse
    {
        return response()->json([
            'paquete' => [
                'codigo' => $paquete->codigo,
                'guia'   => $paquete->orden->numero_guia,
                'estado' => $paquete->estado->value,
            ],
            'tramos' => $paquete->tramos()->with('mensajero.user', 'vehiculo')->get()
                ->map(fn (Tramo $t) => $this->pintar($t))->all(),
        ]);
    }

    /**
     * POST /despacho/paquetes/{paquete}/tramos
     *
     * Arma la cadena. Sin centro es directo; con centro pasa por nuestra sede.
     */
    public function armar(Request $peticion, Paquete $paquete): JsonResponse
    {
        $datos = $peticion->validate([
            'centro_id' => ['nullable', 'integer', 'exists:sedes,id'],
        ]);

        $centro = isset($datos['centro_id']) ? Sede::find($datos['centro_id']) : null;

        // Un centro de trasbordo tiene que ser una sede NUESTRA. Consolidar en
        // la bodega de otro cliente sería dejarle la carga a un tercero.
        if ($centro !== null && $centro->cliente_id !== null) {
            abort(422, 'El centro de trasbordo debe ser una sede propia de Avalia.');
        }

        $tramos = $this->cadena->armar($paquete, $centro);

        if ($tramos === []) {
            abort(422, 'No se pudo armar la cadena: al origen o al destino les falta coordenada.');
        }

        return response()->json([
            'tramos' => array_map(fn (Tramo $t) => $this->pintar($t), $tramos),
        ], 201);
    }

    /**
     * POST /despacho/paquetes/{paquete}/trasbordo
     *
     * Mete un centro por medio en un envío que ya iba directo.
     */
    public function trasbordo(Request $peticion, Paquete $paquete): JsonResponse
    {
        $datos = $peticion->validate([
            'centro_id' => ['required', 'integer', 'exists:sedes,id'],
        ]);

        $centro = Sede::findOrFail($datos['centro_id']);

        if ($centro->cliente_id !== null) {
            abort(422, 'El centro de trasbordo debe ser una sede propia de Avalia.');
        }

        try {
            $tramos = $this->cadena->insertarTrasbordo($paquete, $centro);
        } catch (\RuntimeException $e) {
            // El servicio explica el motivo exacto. Antes todos los rechazos
            // se contaban con la misma frase y mandaban al operador a buscar
            // el problema donde no estaba.
            abort(422, $e->getMessage());
        }

        return response()->json(['tramos' => array_map(fn (Tramo $t) => $this->pintar($t), $tramos)]);
    }

    /**
     * POST /despacho/tramos/{tramo}/asignar
     *
     * Pone mensajero y, si hace falta, lo programa para otro día. Es lo que
     * permite que el segundo tramo salga mañana y con otro vehículo.
     */
    public function asignar(Request $peticion, Tramo $tramo): JsonResponse
    {
        $datos = $peticion->validate([
            'mensajero_id'     => ['required', 'integer', 'exists:mensajeros,id'],
            'programado_desde' => ['nullable', 'date'],
            'programado_hasta' => ['nullable', 'date', 'after_or_equal:programado_desde'],
        ]);

        abort_unless($tramo->estaAbierto(), 422, 'Ese tramo ya está cerrado.');

        $mensajero = Mensajero::findOrFail($datos['mensajero_id']);

        abort_unless($mensajero->activo, 422, 'Ese mensajero está desactivado.');

        $tramo = $this->cadena->asignar(
            $tramo,
            $mensajero,
            isset($datos['programado_desde']) ? Carbon::parse($datos['programado_desde']) : null,
            isset($datos['programado_hasta']) ? Carbon::parse($datos['programado_hasta']) : null,
            $peticion->user(),
        );

        return response()->json($this->pintar($tramo->load('mensajero.user', 'vehiculo')));
    }

    /**
     * POST /campo/tramos/{tramo}/cerrar
     *
     * El mensajero cierra el trayecto que lleva encima, con su prueba.
     */
    public function cerrar(Request $peticion, Tramo $tramo): JsonResponse
    {
        $datos = $peticion->validate([
            'firma_id'              => ['nullable', 'uuid', 'exists:evidencias,id'],
            'foto_id'               => ['nullable', 'uuid', 'exists:evidencias,id'],
            'contraparte_nombre'    => ['nullable', 'string', 'max:120'],
            'contraparte_documento' => ['nullable', 'string', 'max:30'],
            'latitud'               => ['nullable', 'numeric', 'between:-90,90'],
            'longitud'              => ['nullable', 'numeric', 'between:-180,180'],
            'observaciones'         => ['nullable', 'string', 'max:500'],
            'ocurrido_en'           => ['nullable', 'date'],
        ]);

        // Solo quien lo lleva puede cerrarlo. Sin esto, un mensajero podría
        // firmar la entrega de un paquete que nunca tuvo en las manos.
        abort_unless(
            $tramo->mensajero_id === $peticion->user()->mensajero?->id,
            403,
            'Este tramo no está asignado a usted.'
        );

        abort_unless($tramo->estaAbierto(), 422, 'Ese tramo ya está cerrado.');

        abort_unless(
            $tramo->disponible(),
            422,
            'Este tramo está programado para más adelante y todavía no se puede cerrar.'
        );

        try {
            $tramo = $this->cadena->cerrar($tramo, [
                ...$datos,
                'ocurrido_en' => isset($datos['ocurrido_en']) ? Carbon::parse($datos['ocurrido_en']) : null,
            ], $peticion->user());
        } catch (\RuntimeException $e) {
            // Falta un control obligatorio del tipo de tramo. Es un 422 de
            // negocio, no un error del servidor.
            abort(422, $e->getMessage());
        }

        $paquete = $tramo->paquete->fresh();

        return response()->json([
            'tramo'   => $this->pintar($tramo),
            'paquete' => ['codigo' => $paquete->codigo, 'estado' => $paquete->estado->value],
            'siguiente' => $paquete->tramoActual() === null
                ? null
                : $this->pintar($paquete->tramoActual()),
        ]);
    }

    private function pintar(Tramo $t): array
    {
        $origen  = $t->origen();
        $destino = $t->destino();

        return [
            'id'      => $t->id,
            'orden'   => $t->orden,
            'tipo'    => $t->tipo->value,
            'tipo_etiqueta' => $t->tipo->etiqueta(),
            'estado'  => $t->estado,
            'origen'  => $origen === null ? null : ['nombre' => $origen->nombre, 'direccion' => $origen->direccion],
            'destino' => $destino === null ? null : ['nombre' => $destino->nombre, 'direccion' => $destino->direccion],
            'mensajero' => $t->mensajero?->user?->nombre,
            'vehiculo'  => $t->vehiculo?->placa ?? $t->mensajero?->vehiculo?->placa,
            'programado_desde' => $t->programado_desde?->toIso8601String(),
            'programado_hasta' => $t->programado_hasta?->toIso8601String(),
            'cerrado_en'       => $t->cerrado_en?->toIso8601String(),
            // Lo que le falta para poder cerrarse. La aplicación de campo lo usa
            // para pedir exactamente eso y no más.
            'exige'  => $t->tipo->exige(),
            'faltan' => $t->controlesPendientes(),
            'disponible' => $t->disponible(),
        ];
    }
}
