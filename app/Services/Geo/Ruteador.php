<?php

namespace App\Services\Geo;

use App\Support\Geo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Traza la ruta por carretera entre dos puntos.
 *
 * Habla con un servicio OSRM. Si el servicio no responde, responde mal o tarda
 * más de la cuenta, devuelve la línea recta MARCADA COMO TAL. Nunca inventa una
 * ruta: la diferencia entre «esta es la vía» y «esto es apenas la dirección
 * general» cambia lo que la pantalla tiene derecho a afirmar, y con ella el
 * sentido de la palabra «desvío».
 */
class Ruteador
{
    public const POR_CARRETERA = 'osrm';
    public const LINEA_RECTA   = 'linea_recta';

    /**
     * @return array{geometria: list<array{float, float}>, distancia_m: int, duracion_seg: int, proveedor: string}
     */
    public function trazar(float $origenLat, float $origenLon, float $destinoLat, float $destinoLon): array
    {
        $base = rtrim((string) config('ltp.mapa.ruteador'), '/');

        if ($base !== '') {
            $ruta = $this->pedirAlServicio($base, $origenLat, $origenLon, $destinoLat, $destinoLon);

            if ($ruta !== null) {
                return $ruta;
            }
        }

        return $this->lineaRecta($origenLat, $origenLon, $destinoLat, $destinoLon);
    }

    /**
     * @return array{geometria: list<array{float, float}>, distancia_m: int, duracion_seg: int, proveedor: string}|null
     */
    private function pedirAlServicio(
        string $base,
        float $origenLat,
        float $origenLon,
        float $destinoLat,
        float $destinoLon,
    ): ?array {
        // OSRM espera longitud,latitud —al revés de como se dicen las
        // coordenadas en voz alta—. Es la equivocación clásica con este
        // servicio y por eso va escrita.
        $puntos = sprintf('%F,%F;%F,%F', $origenLon, $origenLat, $destinoLon, $destinoLat);

        try {
            $respuesta = Http::timeout((int) config('ltp.mapa.ruteo_timeout_seg', 6))
                ->get("{$base}/route/v1/driving/{$puntos}", [
                    'overview'     => 'full',
                    'geometries'   => 'geojson',
                    'alternatives' => 'false',
                    'steps'        => 'false',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Ruteo no disponible, se usa línea recta.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $respuesta->successful()) {
            Log::warning('El servicio de ruteo respondió con error.', ['estado' => $respuesta->status()]);

            return null;
        }

        $cuerpo = $respuesta->json();
        $ruta   = $cuerpo['routes'][0] ?? null;

        if ($ruta === null || ($cuerpo['code'] ?? '') !== 'Ok') {
            return null;
        }

        $coordenadas = $ruta['geometry']['coordinates'] ?? [];

        // Una ruta de menos de dos puntos no es una ruta. Mejor la línea recta,
        // que al menos va etiquetada como aproximación.
        if (count($coordenadas) < 2) {
            return null;
        }

        return [
            'geometria'    => array_map(
                static fn (array $par): array => [(float) $par[0], (float) $par[1]],
                $coordenadas
            ),
            'distancia_m'  => (int) round($ruta['distance'] ?? 0),
            'duracion_seg' => (int) round($ruta['duration'] ?? 0),
            'proveedor'    => self::POR_CARRETERA,
        ];
    }

    /**
     * @return array{geometria: list<array{float, float}>, distancia_m: int, duracion_seg: int, proveedor: string}
     */
    private function lineaRecta(float $origenLat, float $origenLon, float $destinoLat, float $destinoLon): array
    {
        $metros = Geo::distanciaMetros($origenLat, $origenLon, $destinoLat, $destinoLon);

        return [
            'geometria'    => [[$origenLon, $origenLat], [$destinoLon, $destinoLat]],
            'distancia_m'  => $metros,
            // Se estima con la velocidad urbana que ya usa el motor de
            // asignación, para no tener dos ideas distintas de «cuánto tarda».
            'duracion_seg' => Geo::minutosEstimados($metros / 1000) * 60,
            'proveedor'    => self::LINEA_RECTA,
        ];
    }
}
