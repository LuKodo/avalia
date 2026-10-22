<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoPaquete;
use App\Models\Mensajero;
use App\Models\Paquete;
use App\Services\Despacho\MotorAsignacion;
use App\Services\Trazabilidad\RegistradorEventos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class DespachoController extends Controller
{
    public function __construct(
        private MotorAsignacion $motor,
        private RegistradorEventos $registrador,
    ) {}

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
            ->with(['orden.cliente', 'orden.origen', 'orden.destino', 'orden.destinatario', 'orden.referencias'])
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
                    'destino'      => $p->orden->nombreDestino(),
                    'ventana'      => [
                        'desde' => $p->orden->ventana_desde?->format('H:i'),
                        'hasta' => $p->orden->ventana_hasta?->format('H:i'),
                    ],
                    'holgura_min'  => $holgura,
                    'peso_kg'      => $p->peso_kg,
                    // La advertencia viaja con el paquete a todas las pantallas
                    // donde alguien decide quién lo lleva.
                    'alto_costo'   => (bool) $p->alto_costo,

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

    /**
     * Lo que ya va en manos de alguien.
     *
     * La bandeja de despacho solo enseña lo que está sin asignar —es su
     * oficio— y por eso no había forma de tocar un envío que ya salió. Pero la
     * calle no respeta el reparto: una moto se avería, alguien se enferma, una
     * ruta se desborda. Esta lista es la puerta para corregirlo.
     *
     * Se excluyen los terminales: un entregado o un devuelto ya no cambia de
     * manos, y tenerlos aquí solo sería ruido.
     */
    public function enCurso(): JsonResponse
    {
        $cerrados = [
            EstadoPaquete::Entregado->value,
            EstadoPaquete::Devuelto->value,
            EstadoPaquete::Siniestro->value,
        ];

        $paquetes = Paquete::query()
            ->whereNotNull('mensajero_id')
            ->whereNotIn('estado', $cerrados)
            ->with(['orden.cliente', 'orden.origen', 'orden.destino', 'orden.destinatario', 'orden.referencias', 'mensajero.user'])
            ->get()
            ->map(function (Paquete $p) {
                $tope    = $p->orden->comprometida_en;
                $holgura = $tope === null ? null : (int) now()->diffInMinutes($tope, false);

                return [
                    'paquete_id'  => $p->id,
                    'codigo'      => $p->codigo,
                    'guia'        => $p->orden->numero_guia,
                    'cliente'     => $p->orden->cliente->razon_social,
                    'referencias' => $p->orden->referencias->map(fn ($r) => $r->valor)->all(),
                    'tipo'        => $p->tipo->value,
                    'tipo_etiqueta' => $p->tipo->etiqueta(),
                    'estado'      => $p->estado->value,
                    'estado_etiqueta' => $p->estado->etiqueta(),
                    'criticidad'  => $p->orden->criticidad->value,
                    'mensajero'   => $p->mensajero?->nombre(),
                    'mensajero_id' => $p->mensajero_id,
                    'destino'     => $p->orden->nombreDestino(),
                    'holgura_min' => $holgura,
                    'peso_kg'     => $p->peso_kg,
                ];
            })
            ->sortBy(fn ($f) => $f['holgura_min'] ?? PHP_INT_MAX)
            ->values();

        return response()->json([
            'total'    => $paquetes->count(),
            'paquetes' => $paquetes,
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

        /*
         * Reasignar también exige motivo, y por una razón distinta.
         *
         * Quitarle un paquete a un mensajero que ya lo tenía —o que ya lo
         * recogió— no es despachar: es corregir algo. Se averió la moto, se
         * enfermó, la ruta se desbordó, el cliente pidió otro. Ese porqué es lo
         * único que explica después por qué un envío cambió de manos a mitad de
         * camino, y sin él la bitácora cuenta el qué sin el motivo.
         */
        $reasignacion = $paquete->mensajero_id !== null
            && $paquete->mensajero_id !== $mensajero->id;

        if ($reasignacion && empty($datos['motivo'])) {
            abort(422, 'Indique por qué reasigna este paquete a otro mensajero.');
        }

        abort_if(
            $paquete->mensajero_id === $mensajero->id,
            422,
            'Ese paquete ya está asignado a ese mensajero.'
        );

        abort_if(
            $paquete->estado->esTerminal(),
            409,
            "El paquete está en «{$paquete->estado->etiqueta()}» y ya no se puede reasignar."
        );

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

    /**
     * Lo que lleva más de N días sin cerrarse, y en manos de quién.
     *
     * No es lo mismo que «vencida». Una guía vencida incumplió su hora y sigue
     * su curso; una guía con cuatro días encima es otra cosa: nadie la está
     * moviendo, y en algún punto dejó de aparecer en la lista de nadie. Son las
     * que acaban descubriéndose porque llama el cliente.
     *
     * El dato que falta siempre en esa llamada es quién la tiene. Por eso la
     * custodia va en primer plano: la pregunta no es «¿cuántas hay?» sino
     * «¿a quién le pregunto?».
     */
    public function enCustodiaProlongada(Request $request): JsonResponse
    {
        $dias = max(1, min(60, (int) $request->query('dias', 3)));
        $corte = now()->subDays($dias);

        $paquetes = Paquete::query()
            ->whereNotIn('estado', [
                EstadoPaquete::Entregado->value,
                EstadoPaquete::Devuelto->value,
                EstadoPaquete::Siniestro->value,
                EstadoPaquete::Cancelado->value,
            ])
            ->where('created_at', '<=', $corte)
            ->with(['orden.cliente', 'orden.destino', 'orden.destinatario', 'mensajero.user'])
            ->orderBy('created_at')
            ->limit(500)
            ->get()
            ->map(fn (Paquete $p) => [
                'paquete_id' => $p->id,
                'codigo'     => $p->codigo,
                'guia'       => $p->orden->numero_guia,
                'cliente'    => $p->orden->cliente->razon_social,
                'destino'    => $p->orden->nombreDestino(),
                'estado'     => $p->estado->value,
                'estado_etiqueta' => $p->estado->etiqueta(),
                /*
                 * «Sin asignar» no es un hueco: es la respuesta. Un paquete de
                 * cinco días que nunca salió de la bandeja es el caso más grave
                 * de todos, y dejarlo en blanco lo haría parecer un dato que
                 * falta en vez de un problema.
                 */
                'custodia'   => $p->mensajero?->nombre() ?? 'Sin asignar · sigue en la bandeja',
                'mensajero_id' => $p->mensajero_id,
                'radicada_en' => $p->created_at?->toIso8601String(),
                'dias'       => (int) $p->created_at?->diffInDays(now()),
                'recolectado_en' => $p->recolectado_en?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'dias_umbral' => $dias,
            'total'       => $paquetes->count(),
            'paquetes'    => $paquetes,
        ]);
    }

    /**
     * Reabre una entrega cerrada, sin borrar nada.
     *
     * Pasa con más frecuencia de la que nadie quisiera: se confirma la entrega
     * y después aparece el acta que faltaba, o la firma salió en blanco porque
     * el dedo resbaló. La salida obvia —borrar y rehacer— es justo la que esta
     * plataforma no puede ofrecer: la bitácora es de solo anexado para poder
     * afirmarle a un auditor que nada se tocó, y un botón que borra evidencias
     * acaba con esa afirmación, no solo para el caso en que se usó.
     *
     * Así que no se borra. La entrega anterior se marca como reemplazada —con
     * quién la reabrió, cuándo y por qué—, el paquete vuelve a «en sitio» para
     * que el mensajero complete la captura, y la reapertura queda en la cadena
     * como un evento más. En marzo se puede explicar qué pasó.
     */
    public function reabrirEntrega(Request $request, Paquete $paquete): JsonResponse
    {
        $datos = $request->validate([
            // Veinte caracteres no es burocracia: «error» no explica nada a
            // quien lea esto dentro de seis meses, y esta es la unica
            // anotacion que va a quedar sobre por que se reabrio.
            'motivo' => ['required', 'string', 'min:20', 'max:500'],
        ]);

        abort_unless(
            $paquete->estado === EstadoPaquete::Entregado,
            409,
            "El paquete está en «{$paquete->estado->etiqueta()}»: solo se reabre una entrega confirmada."
        );

        $entrega = $paquete->entrega;

        abort_if($entrega === null, 409, 'Ese paquete no tiene una entrega registrada.');

        return DB::transaction(function () use ($paquete, $entrega, $datos, $request) {
            $entrega->forceFill([
                'vigente'           => false,
                'reabierta_en'      => now(),
                'reabierta_por'     => $request->user()->id,
                'motivo_reapertura' => $datos['motivo'],
            ])->save();

            /*
             * `entregado_en` en el paquete es una copia de conveniencia para
             * los listados; el dato bueno vive en `entregas`. Se limpia porque
             * si no, el paquete seguiria contando como entregado en todos los
             * informes mientras la entrega esta reabierta.
             */
            $paquete->forceFill(['entregado_en' => null])->save();

            $evento = $this->registrador->registrar($paquete, EstadoPaquete::EnSitio, [
                'tipo'  => 'reapertura',
                'actor' => $request->user(),
                'datos' => [
                    'motivo'              => $datos['motivo'],
                    'entrega_reemplazada' => $entrega->id,
                    'entregada_en'        => $entrega->entregada_en?->toIso8601String(),
                ],
            ]);

            /*
             * La entrega dejó de contar, así que el contador del mensajero
             * tampoco debe seguir contándola.
             *
             * El `> 0` no es paranoia: la columna es sin signo y un `- 1` sobre
             * cero no deja el contador en cero, revienta la consulta entera con
             * «BIGINT UNSIGNED out of range» y se lleva por delante la
             * reapertura. Un contador desincronizado —datos migrados, cargas
             * de prueba— no puede impedir corregir una entrega.
             */
            if ($paquete->mensajero && $paquete->mensajero->entregas_totales > 0) {
                $paquete->mensajero->decrement('entregas_totales');
            }

            return response()->json([
                'estado'    => EstadoPaquete::EnSitio->value,
                'evento_id' => $evento->id,
                'entrega_reemplazada' => $entrega->id,
                'mensaje'   => 'Entrega reabierta. La evidencia anterior se conserva marcada como reemplazada.',
            ]);
        });
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
