<?php

namespace App\Http\Controllers\Api;

use App\Models\Mensajero;
use App\Models\Paquete;
use App\Services\Despacho\MotorAsignacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DespachoController extends Controller
{
    public function __construct(private MotorAsignacion $motor) {}

    /**
     * Bandeja de despacho.
     *
     * Ordenada por criticidad y por holgura frente a la ventana, no por hora de
     * creación: lo que está por vencer sube solo. Un operador con siete envíos
     * pendientes no debería tener que calcular cuál atender primero.
     */
    public function bandeja(Request $request): JsonResponse
    {
        $paquetes = Paquete::query()
            ->sinAsignar()
            ->with(['orden.cliente', 'orden.origen', 'orden.destino', 'orden.referencias'])
            ->get()
            ->map(function (Paquete $p) {
                $tope    = $p->orden->comprometida_en;
                $holgura = $tope === null ? null : (int) now()->diffInMinutes($tope, false);

                return [
                    'paquete_id'   => $p->id,
                    'codigo'       => $p->codigo,
                    'guia'         => $p->orden->numero_guia,
                    'referencias'  => $p->orden->referencias->map(fn ($r) => $r->valor)->all(),
                    'cliente'      => $p->orden->cliente->razon_social,
                    'tipo'         => $p->tipo->value,
                    'tipo_etiqueta' => $p->tipo->etiqueta(),
                    'criticidad'   => $p->orden->criticidad->value,
                    'origen'       => $p->orden->origen?->nombre,
                    'destino'      => $p->orden->destino?->nombre,
                    'ventana'      => [
                        'desde' => $p->orden->ventana_desde?->format('H:i'),
                        'hasta' => $p->orden->ventana_hasta?->format('H:i'),
                    ],
                    'holgura_min'  => $holgura,
                    'peso_kg'      => $p->peso_kg,

                    /*
                     * Cuando entro la solicitud.
                     *
                     * Va en ISO 8601 y no formateada: quien la pinta decide si
                     * muestra fecha, hora o ambas, y ordenar cadenas ISO da el
                     * mismo resultado que ordenar fechas. Formatearla aqui
                     * obligaria a reconstruirla en el navegador para poder
                     * ordenar, que es el camino largo para llegar al mismo
                     * sitio.
                     */
                    'registrado_en' => $p->orden->created_at?->toIso8601String(),
                    // La sugerencia viaja en la fila: el operador no debería
                    // tener que abrir un modal para saber a quién asignarlo.
                    'sugerencia'   => $this->sugerencia($p),
                    '_orden_prioridad' => $p->orden->criticidad->prioridad() * 1000 - ($holgura ?? 9999),
                ];
            })
            ->sortByDesc('_orden_prioridad')
            ->values()
            ->map(fn ($fila) => collect($fila)->except('_orden_prioridad'));

        return response()->json(['total' => $paquetes->count(), 'paquetes' => $paquetes]);
    }

    /** Candidatos con el desglose del puntaje: el sistema explica, no decide solo. */
    public function candidatos(Paquete $paquete): JsonResponse
    {
        $diagnostico = $this->motor->diagnostico($paquete);
        $candidatos  = $this->motor->candidatos($paquete, 5);

        return response()->json([
            'paquete' => [
                'codigo' => $paquete->codigo,
                'tipo'   => $paquete->tipo->value,
                'peso_kg' => $paquete->peso_kg,
                'rango_termico' => $paquete->exigeCadenaFrio() ? $paquete->rangoTermico() : null,
            ],
            'filtro_duro' => [
                'total_mensajeros' => $diagnostico['total'],
                'viables'          => $diagnostico['viables'],
                'exigencias'       => array_values(array_filter([
                    $paquete->exigeCadenaFrio() ? 'Vehículo refrigerado' : null,
                    $paquete->tipo->exigeCertificacion() ? 'Certificación en control especial' : null,
                    "Capacidad disponible ≥ {$paquete->peso_kg} kg",
                    'Turno activo y señal reciente',
                ])),

                /*
                 * Por que quedo fuera cada uno, con su cuenta.
                 *
                 * Sin esto la pantalla solo podia enumerar los requisitos y
                 * dejar que el operador adivinara cual fallo. Con la bandeja
                 * entera sin candidatos, esa adivinanza cuesta horas.
                 */
                'descartes' => $diagnostico['descartes'],
            ],
            'candidatos' => $candidatos->map(fn ($c) => [
                'mensajero_id' => $c['mensajero']->id,
                'nombre'       => $c['mensajero']->nombre(),
                'vehiculo'     => $c['mensajero']->vehiculo?->placa,
                'tipo_vehiculo' => $c['mensajero']->vehiculo?->tipo->etiqueta(),
                'paquetes_activos' => $c['mensajero']->paquetesActivos(),
                'distancia_km' => $c['distancia_km'],
                'puntaje'      => $c['puntaje'],
                'desglose'     => $c['desglose'],
            ]),
        ]);
    }

    public function asignar(Request $request, Paquete $paquete): JsonResponse
    {
        $datos = $request->validate([
            'mensajero_id' => ['required', 'integer', 'exists:mensajeros,id'],
            'motivo'       => ['nullable', 'string', 'max:255'],
        ]);

        $mensajero = Mensajero::findOrFail($datos['mensajero_id']);

        // Nunca se salta el filtro duro, ni siquiera por decisión del operador:
        // un paquete refrigerado en una moto no es una mala asignación, es una
        // imposibilidad física que además rompe el cumplimiento normativo.
        $viables = $this->motor->filtroDuro($paquete);

        abort_unless(
            $viables->contains('id', $mensajero->id),
            422,
            'Ese mensajero no cumple los requisitos obligatorios para este paquete.'
        );

        $evaluacion = $this->motor->evaluar($mensajero, $paquete);
        $sugerido   = $this->motor->candidatos($paquete, 1)->first();
        $esAnulacion = $sugerido !== null && $sugerido['mensajero']->id !== $mensajero->id;

        // Si el operador contradice la sugerencia, el motivo es obligatorio:
        // esos motivos son la materia prima para calibrar los pesos del motor.
        if ($esAnulacion && empty($datos['motivo'])) {
            abort(422, 'Indique el motivo por el que descarta la sugerencia del sistema.');
        }

        $asignacion = $this->motor->asignar(
            paquete: $paquete,
            mensajero: $mensajero,
            operador: $request->user(),
            origen: 'manual',
            puntaje: $evaluacion['puntaje'],
            desglose: $evaluacion['desglose'],
            motivo: $datos['motivo'] ?? null,
        );

        return response()->json([
            'asignacion_id' => $asignacion->id,
            'mensajero'     => $mensajero->nombre(),
            'puntaje'       => $evaluacion['puntaje'],
            'contradijo_sugerencia' => $esAnulacion,
        ], 201);
    }

    /** @return array<string, mixed>|null */
    private function sugerencia(Paquete $paquete): ?array
    {
        $mejor = $this->motor->candidatos($paquete, 1)->first();

        return $mejor === null ? null : [
            'mensajero_id' => $mejor['mensajero']->id,
            'nombre'       => $mejor['mensajero']->nombre(),
            'puntaje'      => $mejor['puntaje'],
        ];
    }
}
