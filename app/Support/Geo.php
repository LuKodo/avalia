<?php

namespace App\Support;

/**
 * Utilidades geográficas sin dependencia de PostGIS.
 *
 * Decisión deliberada: el MVP resuelve distancia y punto-en-polígono en PHP
 * sobre conjuntos pequeños (los candidatos que sobreviven al filtro duro son
 * unos pocos, y las zonas por ciudad son decenas). Eso permite correr el
 * sistema sobre PostgreSQL sin PostGIS o incluso sobre MySQL, que es lo que
 * hace falta para arrancar. Cuando el volumen lo justifique, estas mismas
 * funciones se sustituyen por ST_Distance y ST_Contains con índice GiST sin
 * tocar a quien las llama.
 */
final class Geo
{
    private const RADIO_TIERRA_KM = 6371.0088;

    /** Distancia de círculo máximo (haversine) en kilómetros. */
    public static function distanciaKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::RADIO_TIERRA_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    public static function distanciaMetros(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        return (int) round(self::distanciaKm($lat1, $lon1, $lat2, $lon2) * 1000);
    }

    /**
     * Caja envolvente para prefiltrar en SQL antes de calcular la distancia real.
     * Un índice común sobre (latitud, longitud) basta: la caja descarta el 99 %
     * de las filas y el haversine solo corre sobre las que quedan.
     *
     * @return array{lat_min:float, lat_max:float, lon_min:float, lon_max:float}
     */
    public static function caja(float $lat, float $lon, float $radioKm): array
    {
        $deltaLat = $radioKm / 111.32;
        $cos      = cos(deg2rad($lat));
        $deltaLon = $radioKm / (111.32 * ($cos == 0.0 ? 1e-6 : abs($cos)));

        return [
            'lat_min' => $lat - $deltaLat,
            'lat_max' => $lat + $deltaLat,
            'lon_min' => $lon - $deltaLon,
            'lon_max' => $lon + $deltaLon,
        ];
    }

    /**
     * Punto en polígono por el algoritmo de cruce de rayos.
     *
     * @param  array<int, array{0:float,1:float}>|array<string, mixed>  $poligono
     *         Anillo GeoJSON: lista de pares [longitud, latitud].
     */
    public static function puntoEnPoligono(float $lat, float $lon, array $poligono): bool
    {
        $anillo = $poligono['coordinates'][0] ?? $poligono;

        if (! is_array($anillo) || count($anillo) < 3) {
            return false;
        }

        $dentro = false;
        $n      = count($anillo);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) $anillo[$i][0];  // longitud
            $yi = (float) $anillo[$i][1];  // latitud
            $xj = (float) $anillo[$j][0];
            $yj = (float) $anillo[$j][1];

            $cruza = (($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);

            if ($cruza) {
                $dentro = ! $dentro;
            }
        }

        return $dentro;
    }

    /** Tiempo estimado de recorrido, en minutos, a velocidad media urbana. */
    public static function minutosEstimados(float $distanciaKm, float $velocidadKmh = 22.0): int
    {
        if ($velocidadKmh <= 0) {
            return 0;
        }

        // Factor 1,35: la distancia en línea recta subestima el recorrido real
        // por calle. Se reemplaza por el tiempo real de la API de rutas cuando
        // hay conexión; este valor es el respaldo cuando no la hay.
        return (int) ceil(($distanciaKm * 1.35 / $velocidadKmh) * 60);
    }
}
