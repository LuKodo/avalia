<?php

namespace App\Http\Controllers\Api;

use App\Models\Dispositivo;
use App\Models\Excursion;
use App\Models\Telemetria;
use App\Services\Dispositivos\ProcesadorTelemetria;
use App\Services\Dispositivos\RegistroProveedores;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Telemetría de dispositivos (Tempubi y equivalentes).
 *
 * Dos caras: la ingesta, que consume el dispositivo o la plataforma del
 * proveedor, y la consulta, que consume el panel de operación.
 */
class TelemetriaController extends Controller
{
    public function __construct(
        private RegistroProveedores $proveedores,
        private ProcesadorTelemetria $procesador,
    ) {}

    /**
     * Ingesta. Ruta sin sesión: quien llama es un aparato, no una persona.
     *
     * La autenticación es la firma HMAC del cuerpo con el secreto propio del
     * dispositivo. Por eso hay que identificar primero el dispositivo (por el
     * serial que viene en la carga) y verificar después: el secreto es de cada
     * aparato, no compartido por toda la flota.
     */
    public function ingesta(Request $request, string $proveedor): JsonResponse
    {
        try {
            $adaptador = $this->proveedores->para($proveedor);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'proveedor_desconocido', 'mensaje' => $e->getMessage()], 404);
        }

        $crudo = $request->getContent();
        $carga = json_decode($crudo, true);

        if (! is_array($carga)) {
            return response()->json(['error' => 'carga_invalida', 'mensaje' => 'El cuerpo no es JSON válido.'], 400);
        }

        $lecturas = $adaptador->traducir($carga);

        if ($lecturas === []) {
            return response()->json([
                'error'   => 'sin_lecturas',
                'mensaje' => 'No se reconoció ninguna lectura utilizable en la carga. '
                    .'Verifique el mapeo de campos del adaptador.',
            ], 422);
        }

        $serial      = $lecturas[0]->serial;
        $dispositivo = Dispositivo::where('serial', $serial)->where('proveedor', $adaptador->nombre())->first();

        // Mensaje deliberadamente parco: confirmar qué seriales existen le
        // serviría a quien esté tanteando cuáles son válidos.
        if ($dispositivo === null || ! $dispositivo->activo) {
            return response()->json(['error' => 'no_autorizado'], 401);
        }

        $firma = $request->header('X-Firma')
            ?? $request->header('X-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? '';

        if (! $adaptador->verificarFirma($crudo, $firma, $dispositivo->secreto_ingesta ?? '')) {
            logger()->warning('Telemetría con firma inválida', [
                'serial'    => $serial,
                'proveedor' => $adaptador->nombre(),
                'ip'        => $request->ip(),
            ]);

            return response()->json(['error' => 'firma_invalida'], 401);
        }

        $resumen = $this->procesador->procesar($dispositivo, $lecturas);

        return response()->json($resumen, 202);
    }

    /** Estado de la flota instrumentada: lo que alimenta el mapa en vivo. */
    public function enVivo(): JsonResponse
    {
        $dispositivos = Dispositivo::with('vehiculo')->where('activo', true)->get();

        return response()->json([
            'dispositivos' => $dispositivos->map(fn (Dispositivo $d) => [
                'id'           => $d->id,
                'serial'       => $d->serial,
                'alias'        => $d->alias,
                'proveedor'    => $d->proveedor,
                'vehiculo'     => $d->vehiculo?->placa,
                'tipo_vehiculo' => $d->vehiculo?->tipo->etiqueta(),
                'refrigerado'  => (bool) $d->vehiculo?->refrigeracion,
                'temperatura'  => $d->ultima_temperatura,
                'rango'        => $d->rangoVigilado(),
                'en_rango'     => $d->ultima_temperatura === null || $d->enRango($d->ultima_temperatura),
                'latitud'      => $d->ultima_latitud,
                'longitud'     => $d->ultima_longitud,
                'bateria'      => $d->ultima_bateria,
                'ultima_lectura' => $d->ultima_lectura_en?->toIso8601String(),
                // Un dispositivo callado es tan grave como uno fuera de rango:
                // en ambos casos se perdió la vigilancia.
                'silencioso'   => $d->silencioso(),
                'excursion_abierta' => (bool) $d->excursionAbierta(),
            ]),

            'resumen' => [
                'total'        => $dispositivos->count(),
                'reportando'   => $dispositivos->filter(fn ($d) => ! $d->silencioso())->count(),
                'silenciosos'  => $dispositivos->filter(fn ($d) => $d->silencioso())->count(),
                'fuera_rango'  => $dispositivos->filter(fn ($d) => $d->ultima_temperatura !== null
                    && ! $d->enRango($d->ultima_temperatura))->count(),
                'bateria_baja' => $dispositivos->filter(fn ($d) => $d->ultima_bateria !== null
                    && $d->ultima_bateria < 20)->count(),
            ],
        ]);
    }

    /** Serie de un dispositivo: la curva que se adjunta al soporte de entrega. */
    public function serie(Request $request, Dispositivo $dispositivo): JsonResponse
    {
        $datos = $request->validate([
            'horas' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $desde = now()->subHours($datos['horas'] ?? 24);

        $lecturas = Telemetria::where('dispositivo_id', $dispositivo->id)
            ->where('medido_en', '>=', $desde)
            ->orderBy('medido_en')
            ->limit(3000)
            ->get();

        return response()->json([
            'dispositivo' => [
                'serial'    => $dispositivo->serial,
                'alias'     => $dispositivo->alias,
                'vehiculo'  => $dispositivo->vehiculo?->placa,
                'rango'     => $dispositivo->rangoVigilado(),
            ],
            'serie' => $lecturas->map(fn (Telemetria $t) => [
                'medido_en'   => $t->medido_en->toIso8601String(),
                'temperatura' => $t->temperatura,
                'latitud'     => $t->latitud,
                'longitud'    => $t->longitud,
                'bateria'     => $t->bateria,
                'fuera'       => $t->fuera_de_rango,
            ]),
            'estadisticas' => [
                'lecturas'  => $lecturas->count(),
                'fuera'     => $lecturas->where('fuera_de_rango', true)->count(),
                'minima'    => $lecturas->whereNotNull('temperatura')->min('temperatura'),
                'maxima'    => $lecturas->whereNotNull('temperatura')->max('temperatura'),
                'promedio'  => $lecturas->whereNotNull('temperatura')->count()
                    ? round($lecturas->whereNotNull('temperatura')->avg('temperatura'), 2)
                    : null,
            ],
        ]);
    }

    public function excursiones(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['nullable', 'in:abierta,cerrada,descartada'],
        ]);

        $excursiones = Excursion::with('dispositivo', 'vehiculo')
            ->when($datos['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->orderByDesc('inicio')
            ->limit(100)
            ->get();

        return response()->json([
            'abiertas'    => Excursion::abiertas()->count(),
            'excursiones' => $excursiones->map(fn (Excursion $e) => [
                'id'          => $e->id,
                'dispositivo' => $e->dispositivo?->serial,
                'alias'       => $e->dispositivo?->alias,
                'vehiculo'    => $e->vehiculo?->placa,
                'inicio'      => $e->inicio->toIso8601String(),
                'fin'         => $e->fin?->toIso8601String(),
                'duracion_min' => $e->duracionMinutos(),
                'valor_min'   => $e->valor_min,
                'valor_max'   => $e->valor_max,
                'lecturas'    => $e->lecturas,
                'estado'      => $e->estado,
                'severidad'   => $e->severidad(),
                'supera_tolerancia' => $e->superaTolerancia(),
                'paquetes'    => $e->paquetes_afectados ?? [],
                'resolucion'  => $e->resolucion,
            ]),
        ]);
    }

    /** Cierre con decisión documentada. La excursión no se «borra»: se resuelve. */
    public function atenderExcursion(Request $request, Excursion $excursion): JsonResponse
    {
        $datos = $request->validate([
            'resolucion' => ['required', 'string', 'min:10', 'max:1000'],
            'descartar'  => ['nullable', 'boolean'],
        ]);

        $excursion->fill([
            'estado'       => ($datos['descartar'] ?? false) ? 'descartada' : 'cerrada',
            'resolucion'   => $datos['resolucion'],
            'atendida_por' => $request->user()->id,
            'atendida_en'  => now(),
            'fin'          => $excursion->fin ?? now(),
        ])->save();

        return response()->json([
            'estado'     => $excursion->estado,
            'atendida_en' => $excursion->atendida_en->toIso8601String(),
        ]);
    }
}
