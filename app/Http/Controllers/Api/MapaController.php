<?php

namespace App\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use App\Models\Mensajero;
use App\Models\Orden;
use App\Models\Posicion;
use App\Services\Geo\Desvio;
use App\Services\Geo\PlanificadorRuta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Lo que el mapa necesita saber.
 *
 * Dos vistas distintas y con permisos distintos:
 *
 *   · La de UN envío: la ruta trazada, el rastro real y el dictamen de desvío.
 *     La ve el cliente dueño del envío y la operación.
 *   · La de la FLOTA: dónde está cada mensajero ahora. Solo la operación —la
 *     posición de un trabajador no es dato de cliente—.
 */
class MapaController extends Controller
{
    public function __construct(
        private readonly PlanificadorRuta $planificador,
        private readonly Desvio $desvio,
    ) {
    }

    /**
     * GET /api/v1/envios/{guia}/mapa
     */
    public function envio(Request $peticion, string $guia): JsonResponse
    {
        $usuario = $peticion->user();

        $orden = Orden::porGuia($guia)
            ->with(['origen', 'destino', 'destinatario', 'paquetes.mensajero'])
            ->first();

        // Mismo 404 para «no existe» y «no es suyo»: distinguirlos permitiría
        // averiguar qué guías existen probando números.
        if ($orden === null || ! $this->puedeVer($usuario, $orden)) {
            return response()->json(['message' => 'No encontramos ese envío.'], 404);
        }

        return response()->json($this->armarEnvio($orden));
    }

    /**
     * GET /api/v1/despacho/mapa
     *
     * Dónde está cada mensajero con jornada abierta.
     */
    public function flota(): JsonResponse
    {
        $vigencia = (int) config('ltp.mapa.posicion_vigencia_minutos', 10);
        $corte    = Carbon::now()->subHours(12);

        $mensajeros = Mensajero::with('user', 'vehiculo')->where('activo', true)->get();

        // Una sola consulta para la última posición de cada uno. Pedirla dentro
        // del bucle sería una consulta por mensajero en una pantalla que se
        // refresca cada veinte segundos.
        $ultimas = Posicion::whereIn('mensajero_id', $mensajeros->pluck('id'))
            ->where('registrado_en', '>=', $corte)
            ->orderByDesc('registrado_en')
            ->get()
            ->unique('mensajero_id')
            ->keyBy('mensajero_id');

        $ahora = Carbon::now();

        $puntos = $mensajeros
            ->map(function (Mensajero $m) use ($ultimas, $ahora, $vigencia) {
                $p = $ultimas->get($m->id);

                if ($p === null) {
                    return null;
                }

                /*
                 * Restando marcas de tiempo y no con `diffInMinutes`.
                 *
                 * En Carbon 3 esa diferencia devuelve un flotante CON SIGNO que
                 * depende del orden de los operandos, y salía a pantalla como
                 * «hace -19.158363633333334 min». Aquí solo interesa cuánto hace,
                 * en minutos enteros, sin importar de qué lado quede la fecha.
                 */
                $antiguedad = intdiv(abs($ahora->getTimestamp() - $p->registrado_en->getTimestamp()), 60);

                return [
                    'mensajero_id' => $m->id,
                    'nombre'       => $m->user?->nombre ?? 'Mensajero',
                    'vehiculo'     => $m->vehiculo?->placa,
                    'lat'          => (float) $p->latitud,
                    'lon'          => (float) $p->longitud,
                    'velocidad_kmh' => $p->velocidad_kmh,
                    'precision_m'  => $p->precision_m,
                    'momento'      => $p->registrado_en->toIso8601String(),
                    'minutos'      => $antiguedad,
                    // El mapa pinta distinto lo vigente y lo rezagado. Un punto
                    // de hace media hora dibujado igual que uno de hace treinta
                    // segundos es información falsa.
                    'vigente'      => $antiguedad <= $vigencia,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'mensajeros'   => $puntos,
            'sin_senal'    => $mensajeros->count() - $puntos->count(),
            'generado_en'  => $ahora->toIso8601String(),
            'refresco_seg' => (int) config('ltp.mapa.refresco_segundos', 20),
        ]);
    }

    // -----------------------------------------------------------------------

    private function armarEnvio(Orden $orden): array
    {
        $ruta    = $this->planificador->paraOrden($orden);
        $destino = $orden->puntoDestino();

        $paquete   = $orden->paquetes->first();
        $mensajero = $paquete?->mensajero;

        $rastro = $mensajero === null ? [] : $this->rastro($orden, $mensajero->id);

        $dictamen = null;

        // El desvío solo se juzga contra una ruta por carretera. Sobre una línea
        // recta cualquier calle real «se desvía», y el aviso no significaría nada.
        if ($ruta !== null && $ruta->esPorCarretera() && $rastro !== []) {
            $dictamen = $this->desvio->evaluar(
                array_map(static fn (array $p): array => [
                    'lat'     => $p['lat'],
                    'lon'     => $p['lon'],
                    'momento' => Carbon::parse($p['momento']),
                ], $rastro),
                $ruta->geometria,
            );

            $dictamen['desde'] = $dictamen['desde']?->toIso8601String();
            $dictamen['umbral_metros'] = (int) config('ltp.mapa.desvio_metros', 250);
        }

        return [
            'guia'   => $orden->numero_guia,
            'estado' => $paquete?->estado?->value,

            'origen' => $this->punto($orden->origen, 'Origen'),
            'destino' => $this->punto($destino, 'Destino'),

            'ruta' => $ruta === null ? null : [
                'geometria'      => $ruta->geometria,
                'distancia_km'   => $ruta->kilometros(),
                'minutos'        => $ruta->minutos(),
                'por_carretera'  => $ruta->esPorCarretera(),
                'calculada_en'   => $ruta->calculada_en->toIso8601String(),
            ],

            // Por qué NO hay ruta, cuando no la hay. Un mapa vacío sin
            // explicación se lee como una falla del sistema.
            'sin_ruta_porque' => $ruta !== null ? null : $this->motivoSinRuta($orden, $destino),

            'rastro'    => $rastro,
            'mensajero' => $mensajero === null ? null : [
                'nombre'   => $mensajero->user?->nombre,
                'vehiculo' => $mensajero->vehiculo?->placa,
            ],

            'desvio'       => $dictamen,
            'refresco_seg' => (int) config('ltp.mapa.refresco_segundos', 20),
        ];
    }

    /**
     * El rastro del mensajero durante ESTE envío.
     *
     * Acotado entre la recolección y la entrega. Sin ese recorte se mostraría la
     * jornada completa —incluidos otros clientes— y eso es exponer la operación
     * de terceros a quien no le corresponde.
     *
     * El tope de horas NO es un detalle de afinación. Un paquete recolectado que
     * nunca se cerró deja `entregado_en` en nulo para siempre, y sin tope la
     * ventana se extiende «hasta ahora»: semanas de posiciones de ese mensajero,
     * de todos sus demás clientes, mostradas a quien abrió una guía de hace un
     * mes. Además arruina el dictamen de desvío, que acabaría midiendo recorridos
     * ajenos contra una ruta que terminó hace días.
     */
    private function rastro(Orden $orden, int $mensajeroId): array
    {
        $paquete = $orden->paquetes->first();

        if ($paquete === null) {
            return [];
        }

        $desde = $paquete->recolectado_en ?? $orden->created_at;

        $tope = Carbon::parse($desde)->addHours((int) config('ltp.mapa.rastro_horas_max', 24));

        // `->min()` de instancia: en Carbon `min` NO es un método estático de
        // dos fechas, y llamarlo así revienta en cualquier envío todavía en
        // curso —justo el caso que más se consulta—.
        $hasta = $paquete->entregado_en ?? Carbon::now()->min($tope);

        return Posicion::where('mensajero_id', $mensajeroId)
            ->whereBetween('registrado_en', [$desde, $hasta])
            ->orderBy('registrado_en')
            ->get()
            ->map(static fn (Posicion $p): array => [
                'lat'     => (float) $p->latitud,
                'lon'     => (float) $p->longitud,
                'momento' => $p->registrado_en->toIso8601String(),
            ])
            ->all();
    }

    private function punto(?object $lugar, string $rotulo): ?array
    {
        if ($lugar === null || ! $lugar->tieneCoordenada()) {
            return null;
        }

        return [
            'rotulo'    => $rotulo,
            'nombre'    => $lugar->nombre ?? $rotulo,
            'direccion' => $lugar->direccion ?? null,
            'lat'       => (float) $lugar->latitud,
            'lon'       => (float) $lugar->longitud,
        ];
    }

    private function motivoSinRuta(Orden $orden, ?object $destino): string
    {
        if ($orden->origen === null || ! $orden->origen->tieneCoordenada()) {
            return 'La sede de origen no tiene coordenada registrada.';
        }

        if ($destino === null || ! $destino->tieneCoordenada()) {
            return 'El destino no tiene coordenada registrada.';
        }

        return 'No fue posible trazar la ruta.';
    }

    /**
     * Un cliente solo ve sus envíos; la operación los ve todos.
     */
    private function puedeVer(?object $usuario, Orden $orden): bool
    {
        if ($usuario === null) {
            return false;
        }

        if ($usuario->hasRole(['administrador', 'operador', 'mensajero'])) {
            return true;
        }

        return $usuario->cliente_id !== null
            && $usuario->cliente_id === $orden->cliente_id;
    }
}
