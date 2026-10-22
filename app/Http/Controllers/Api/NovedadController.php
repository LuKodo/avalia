<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoPaquete;
use App\Models\Novedad;
use App\Models\Paquete;
use App\Services\Trazabilidad\RegistradorEventos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Novedades pendientes de decisión.
 *
 * Una novedad es el mensajero diciendo «fui y no pude»: nadie recibía, la
 * dirección estaba mal, el servicio farmacéutico cerró. El paquete sigue en sus
 * manos y la máquina de estados solo admite tres salidas —reprogramar, devolver
 * o declarar siniestro—, ninguna de las cuales le corresponde a él.
 *
 * Esas transiciones estaban definidas desde el principio y no las exponía nada.
 * El resultado es que un paquete que entraba en novedad se quedaba ahí para
 * siempre: ni el mensajero podía moverlo —y cuando lo intentaba, el registrador
 * guardaba el evento como histórico sin avisar a nadie— ni operación tenía
 * dónde decidir. Esto es ese «dónde».
 */
class NovedadController extends Controller
{
    public function __construct(private RegistradorEventos $registrador) {}

    /**
     * Lo que espera una decisión.
     *
     * Se listan los PAQUETES en novedad y no las novedades sueltas: lo que hay
     * que resolver es el paquete, y un mismo paquete puede acumular varias
     * —fue dos veces y las dos falló—. La última es la que explica dónde está
     * parado hoy; las anteriores son contexto.
     */
    public function index(): JsonResponse
    {
        $paquetes = Paquete::query()
            ->where('estado', EstadoPaquete::Novedad->value)
            ->with(['orden.cliente', 'orden.origen', 'orden.destino', 'orden.destinatario', 'mensajero.usuario'])
            ->get()
            ->map(function (Paquete $p) {
                $novedad = Novedad::where('paquete_id', $p->id)
                    ->orderByDesc('reportada_en')
                    ->first();

                return [
                    'paquete_id' => $p->id,
                    'codigo'     => $p->codigo,
                    'guia'       => $p->orden->numero_guia,
                    'cliente'    => $p->orden->cliente->razon_social,
                    'origen'     => $p->orden->origen?->nombre,
                    'destino'    => $p->orden->nombreDestino(),
                    'mensajero'  => $p->mensajero?->nombre(),
                    'tipo'       => $novedad?->tipo->etiqueta(),
                    'severidad'  => $novedad?->severidad,
                    'descripcion' => $novedad?->descripcion,
                    'reportada_en' => $novedad?->reportada_en?->toIso8601String(),
                    'veces'      => Novedad::where('paquete_id', $p->id)->count(),
                ];
            });

        return response()->json([
            'total'     => $paquetes->count(),
            'novedades' => $paquetes->values(),
        ]);
    }

    /**
     * La decisión de operación.
     *
     * Las tres salidas no son equivalentes y por eso no comparten camino:
     *
     *   · `reprogramar` devuelve el paquete a la bandeja de despacho y le quita
     *     el mensajero. Hay que volver a asignarlo, probablemente a otro o a
     *     otra hora, y esa reasignación es una decisión aparte que merece su
     *     propio registro.
     *   · `devolver` lo cierra: vuelve al origen y ahí termina el servicio.
     *   · `siniestro` lo cierra también, pero significa pérdida o daño. Se
     *     separa de la devolución porque lo que viene después —el reclamo, el
     *     seguro, la reposición— no se parece en nada.
     *
     * La nota es obligatoria. Una decisión sin motivo escrito es una decisión
     * que nadie puede auditar tres meses después, que es justo cuando alguien
     * pregunta por qué este envío no llegó.
     */
    public function resolver(Request $request, Paquete $paquete): JsonResponse
    {
        abort_unless(
            $paquete->estado === EstadoPaquete::Novedad,
            409,
            "El paquete {$paquete->codigo} está en «{$paquete->estado->etiqueta()}», no tiene novedad abierta."
        );

        $datos = $request->validate([
            'accion' => ['required', 'in:reprogramar,devolver,siniestro'],
            'nota'   => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $destino = match ($datos['accion']) {
            'reprogramar' => EstadoPaquete::Reprogramado,
            'devolver'    => EstadoPaquete::Devuelto,
            'siniestro'   => EstadoPaquete::Siniestro,
        };

        return DB::transaction(function () use ($datos, $destino, $paquete, $request) {
            $evento = $this->registrador->registrar($paquete, $destino, [
                'actor' => $request->user(),
                'tipo'  => 'resolucion_novedad',
                'datos' => [
                    'accion' => $datos['accion'],
                    'nota'   => $datos['nota'],
                ],
            ]);

            /*
             * Reprogramar suelta al mensajero: el paquete vuelve a la bandeja y
             * se asigna de nuevo. Dejárselo colgado haría que apareciera en sus
             * tareas un envío sobre el que ya no puede actuar, que es la misma
             * confusión que esto vino a resolver.
             */
            if ($destino === EstadoPaquete::Reprogramado) {
                $paquete->forceFill(['mensajero_id' => null])->save();
            }

            Novedad::where('paquete_id', $paquete->id)
                ->where('estado', '!=', 'cerrada')
                ->update([
                    'estado'      => 'cerrada',
                    'resolucion'  => $datos['accion'].': '.$datos['nota'],
                    'cerrada_por' => $request->user()->id,
                    'cerrada_en'  => now(),
                ]);

            return response()->json([
                'estado'    => $paquete->fresh()->estado->value,
                'etiqueta'  => $paquete->fresh()->estado->etiqueta(),
                'evento_id' => $evento->id,
            ]);
        });
    }
}
