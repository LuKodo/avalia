<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoPaquete;
use App\Enums\TipoNovedad;
use App\Models\Mensajero;
use App\Models\Novedad;
use App\Models\Orden;
use App\Models\Paquete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Datos del tablero.
 *
 * Cada respuesta viene ya con la forma que la gráfica necesita —series
 * completas, sin huecos, con etiquetas y unidades—. Un tablero que obliga al
 * frontend a rellenar días faltantes termina dibujando líneas que mienten.
 */
class TableroController extends Controller
{
    public function resumen(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'dias' => ['nullable', 'integer', 'min:7', 'max:180'],
        ]);

        $dias      = $datos['dias'] ?? 30;
        $desde     = now()->subDays($dias - 1)->startOfDay();
        $clienteId = $this->alcanceCliente($request);

        return response()->json([
            'periodo' => [
                'desde' => $desde->toDateString(),
                'hasta' => now()->toDateString(),
                'dias'  => $dias,
            ],
            'indicadores'      => $this->indicadores($desde, $clienteId),
            'serie_entregas'   => $this->serieEntregas($desde, $dias, $clienteId),
            'serie_cumplimiento' => $this->serieCumplimiento($desde, $dias, $clienteId),
            'novedades_por_causa' => $this->novedadesPorCausa($desde, $clienteId),
            'distribucion_estados' => $this->distribucionEstados($clienteId),
            'demanda_por_hora' => $this->demandaPorHora($desde, $clienteId),
            'cadena_frio'      => $this->cadenaFrio($desde, $clienteId),
            'flota'            => $clienteId === null ? $this->flota() : null,
        ]);
    }

    /** Los números que van arriba, sin gráfica: son titulares, no series. */
    private function indicadores(Carbon $desde, ?int $clienteId): array
    {
        $base = Paquete::query()
            ->whereHas('orden', fn ($q) => $q->delCliente($clienteId))
            ->where('created_at', '>=', $desde);

        $total     = (clone $base)->count();
        $entregados = (clone $base)->where('estado', EstadoPaquete::Entregado->value)->count();

        $aTiempo = DB::table('entregas')
            ->join('paquetes', 'paquetes.id', '=', 'entregas.paquete_id')
            ->join('ordenes', 'ordenes.id', '=', 'paquetes.orden_id')
            ->when($clienteId, fn ($q) => $q->where('ordenes.cliente_id', $clienteId))
            ->where('entregas.entregada_en', '>=', $desde)
            ->where('entregas.dentro_de_ventana', true)
            ->count();

        $novedades = Novedad::query()
            ->whereHas('paquete.orden', fn ($q) => $q->delCliente($clienteId))
            ->where('reportada_en', '>=', $desde)
            ->count();

        return [
            'envios_totales' => ['valor' => $total, 'unidad' => 'paquetes'],
            'entregados'     => ['valor' => $entregados, 'unidad' => 'paquetes'],
            'en_curso'       => ['valor' => (clone $base)->enCurso()->count(), 'unidad' => 'paquetes'],
            'cumplimiento'   => [
                'valor'  => $entregados > 0 ? round($aTiempo / $entregados * 100, 1) : null,
                'unidad' => '%',
                'meta'   => 95.0,
            ],
            'tasa_novedades' => [
                'valor'  => $total > 0 ? round($novedades / $total * 100, 1) : 0.0,
                'unidad' => '%',
            ],
            'tiempo_medio_entrega' => [
                'valor'  => $this->tiempoMedioMinutos($desde, $clienteId),
                'unidad' => 'min',
            ],
        ];
    }

    /**
     * Serie diaria completa. Los días sin entregas van en cero explícito, no
     * ausentes: una línea que salta el martes sugiere que ese día no existió.
     */
    private function serieEntregas(Carbon $desde, int $dias, ?int $clienteId): array
    {
        $conteos = DB::table('paquetes')
            ->join('ordenes', 'ordenes.id', '=', 'paquetes.orden_id')
            ->when($clienteId, fn ($q) => $q->where('ordenes.cliente_id', $clienteId))
            ->whereNotNull('paquetes.entregado_en')
            ->where('paquetes.entregado_en', '>=', $desde)
            ->selectRaw('DATE(paquetes.entregado_en) AS dia, COUNT(*) AS total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $creados = DB::table('paquetes')
            ->join('ordenes', 'ordenes.id', '=', 'paquetes.orden_id')
            ->when($clienteId, fn ($q) => $q->where('ordenes.cliente_id', $clienteId))
            ->where('paquetes.created_at', '>=', $desde)
            ->selectRaw('DATE(paquetes.created_at) AS dia, COUNT(*) AS total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $serie = [];

        for ($i = 0; $i < $dias; $i++) {
            $fecha = $desde->copy()->addDays($i)->toDateString();

            $serie[] = [
                'fecha'      => $fecha,
                'radicados'  => (int) ($creados[$fecha] ?? 0),
                'entregados' => (int) ($conteos[$fecha] ?? 0),
            ];
        }

        return $serie;
    }

    private function serieCumplimiento(Carbon $desde, int $dias, ?int $clienteId): array
    {
        $filas = DB::table('entregas')
            ->join('paquetes', 'paquetes.id', '=', 'entregas.paquete_id')
            ->join('ordenes', 'ordenes.id', '=', 'paquetes.orden_id')
            ->when($clienteId, fn ($q) => $q->where('ordenes.cliente_id', $clienteId))
            ->where('entregas.entregada_en', '>=', $desde)
            ->selectRaw('DATE(entregas.entregada_en) AS dia')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN entregas.dentro_de_ventana THEN 1 ELSE 0 END) AS a_tiempo')
            ->groupBy('dia')
            ->get()
            ->keyBy('dia');

        $serie = [];

        for ($i = 0; $i < $dias; $i++) {
            $fecha = $desde->copy()->addDays($i)->toDateString();
            $fila  = $filas[$fecha] ?? null;

            $serie[] = [
                'fecha' => $fecha,
                // Sin entregas ese día el porcentaje es nulo, no cero: cero
                // significaría «todo llegó tarde», que es una afirmación falsa.
                'porcentaje' => $fila && $fila->total > 0
                    ? round($fila->a_tiempo / $fila->total * 100, 1)
                    : null,
                'entregas' => (int) ($fila->total ?? 0),
            ];
        }

        return $serie;
    }

    /** Ordenado de mayor a menor: en un gráfico de causas, el orden es el mensaje. */
    private function novedadesPorCausa(Carbon $desde, ?int $clienteId): array
    {
        $conteos = Novedad::query()
            ->whereHas('paquete.orden', fn ($q) => $q->delCliente($clienteId))
            ->where('reportada_en', '>=', $desde)
            ->selectRaw('tipo, COUNT(*) AS total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        return collect(TipoNovedad::cases())
            ->map(fn (TipoNovedad $t) => [
                'tipo'      => $t->value,
                'etiqueta'  => $t->etiqueta(),
                'total'     => (int) ($conteos[$t->value] ?? 0),
                'severidad' => $t->severidad(),
                'imputable' => $t->esImputableALtp(),
            ])
            ->filter(fn ($f) => $f['total'] > 0)
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function distribucionEstados(?int $clienteId): array
    {
        $conteos = Paquete::query()
            ->whereHas('orden', fn ($q) => $q->delCliente($clienteId))
            ->whereDate('created_at', '>=', now()->subDays(7))
            ->selectRaw('estado, COUNT(*) AS total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return collect(EstadoPaquete::cases())
            ->map(fn (EstadoPaquete $e) => [
                'estado'   => $e->value,
                'etiqueta' => $e->etiqueta(),
                'total'    => (int) ($conteos[$e->value] ?? 0),
                'semaforo' => $e->semaforo(),
                'rango'    => $e->rango(),
            ])
            ->filter(fn ($f) => $f['total'] > 0)
            ->sortBy('rango')
            ->values()
            ->all();
    }

    /**
     * Demanda por día de la semana y hora. Es la vista que dice cuándo hay que
     * tener más mensajeros en turno, que es la decisión operativa con más
     * impacto en el costo.
     */
    private function demandaPorHora(Carbon $desde, ?int $clienteId): array
    {
        $filas = DB::table('ordenes')
            ->when($clienteId, fn ($q) => $q->where('cliente_id', $clienteId))
            ->where('created_at', '>=', $desde)
            ->get(['created_at'])
            ->groupBy(fn ($o) => Carbon::parse($o->created_at)->format('N-G'));

        $celdas = [];

        for ($dia = 1; $dia <= 7; $dia++) {
            for ($hora = 6; $hora <= 20; $hora++) {
                $celdas[] = [
                    'dia'   => $dia,
                    'hora'  => $hora,
                    'total' => ($filas["{$dia}-{$hora}"] ?? collect())->count(),
                ];
            }
        }

        return $celdas;
    }

    private function cadenaFrio(Carbon $desde, ?int $clienteId): array
    {
        $lecturas = DB::table('lecturas_temperatura')
            ->join('paquetes', 'paquetes.id', '=', 'lecturas_temperatura.paquete_id')
            ->join('ordenes', 'ordenes.id', '=', 'paquetes.orden_id')
            ->when($clienteId, fn ($q) => $q->where('ordenes.cliente_id', $clienteId))
            ->where('lecturas_temperatura.registrada_en', '>=', $desde)
            ->get(['lecturas_temperatura.valor', 'lecturas_temperatura.fuera_de_rango', 'lecturas_temperatura.momento']);

        return [
            'total_lecturas'  => $lecturas->count(),
            'excursiones'     => $lecturas->where('fuera_de_rango', true)->count(),
            'valor_min'       => $lecturas->min('valor'),
            'valor_max'       => $lecturas->max('valor'),
            'promedio'        => $lecturas->count() ? round($lecturas->avg('valor'), 2) : null,
            // Histograma en pasos de 1 °C para ver la forma de la distribución,
            // no solo el promedio: dos operaciones con la misma media pueden
            // tener perfiles de riesgo completamente distintos.
            'histograma'      => $this->histograma($lecturas),
        ];
    }

    /**
     * Histograma con los intervalos vacíos incluidos en cero.
     *
     * Omitir un intervalo sin lecturas deja las barras de 9 y 10 °C pegadas a
     * la de 7 °C, como si fueran contiguas, y eso deforma la lectura de la
     * distribución justo en el borde del rango exigido — que es la zona que
     * importa. Un hueco real tiene que verse como un hueco.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $lecturas
     * @return array<int, array{grado:int, total:int}>
     */
    private function histograma($lecturas): array
    {
        if ($lecturas->isEmpty()) {
            return [];
        }

        $conteos = $lecturas
            ->groupBy(fn ($l) => (int) floor($l->valor))
            ->map(fn ($g) => $g->count());

        $desde = (int) $conteos->keys()->min();
        $hasta = (int) $conteos->keys()->max();

        $salida = [];

        for ($grado = $desde; $grado <= $hasta; $grado++) {
            $salida[] = ['grado' => $grado, 'total' => (int) ($conteos[$grado] ?? 0)];
        }

        return $salida;
    }

    private function flota(): array
    {
        $mensajeros = Mensajero::query()->with('vehiculo')->where('activo', true)->get();

        return [
            'total'      => $mensajeros->count(),
            'en_turno'   => $mensajeros->where('en_turno', true)->count(),
            'sin_senal'  => $mensajeros->filter(fn ($m) => $m->en_turno && $m->sinSenal(10))->count(),
            'bateria_baja' => $mensajeros->filter(fn ($m) => $m->bateria !== null && $m->bateria < 20)->count(),
            'refrigerados' => $mensajeros->filter(fn ($m) => $m->vehiculo?->refrigeracion)->count(),
            'ocupacion'  => $mensajeros->where('en_turno', true)->map(fn ($m) => [
                'mensajero' => $m->nombre(),
                'activos'   => $m->paquetesActivos(),
                'carga_kg'  => $m->carga_actual_kg,
                'capacidad_kg' => $m->vehiculo?->capacidad_kg,
            ])->values()->all(),
        ];
    }

    private function tiempoMedioMinutos(Carbon $desde, ?int $clienteId): ?int
    {
        $paquetes = Paquete::query()
            ->whereHas('orden', fn ($q) => $q->delCliente($clienteId))
            ->whereNotNull('recolectado_en')
            ->whereNotNull('entregado_en')
            ->where('entregado_en', '>=', $desde)
            ->get(['recolectado_en', 'entregado_en']);

        if ($paquetes->isEmpty()) {
            return null;
        }

        return (int) round($paquetes->avg(
            fn (Paquete $p) => $p->recolectado_en->diffInMinutes($p->entregado_en)
        ));
    }

    private function alcanceCliente(Request $request): ?int
    {
        $actor = $request->user();

        if ($actor instanceof \App\Models\Cliente) {
            return $actor->id;
        }

        return $actor instanceof \App\Models\User ? $actor->cliente_id : null;
    }
}
