<?php

namespace App\Services\Geo;

/**
 * Cuánto se apartó el vehículo de la ruta trazada.
 *
 * La medida es la distancia del punto al SEGMENTO más cercano de la polilínea,
 * no al vértice más cercano. La diferencia importa: en un tramo recto de tres
 * kilómetros sin vértices intermedios, medir contra vértices daría kilómetros
 * de «desvío» a un vehículo que va justo por el centro de la vía.
 *
 * Un desvío solo se declara cuando se sostiene en el tiempo. Un punto suelto
 * fuera de la ruta es, casi siempre, un rebote del GPS entre edificios —no un
 * camión que se fue por otro lado—.
 */
class Desvio
{
    /** Radio terrestre medio, en metros. */
    private const RADIO_TIERRA_M = 6_371_000.0;

    /**
     * Distancia en metros de un punto a la polilínea.
     *
     * @param list<array{float, float}> $geometria Pares [longitud, latitud].
     */
    public function distanciaARuta(float $lat, float $lon, array $geometria): float
    {
        if (count($geometria) < 2) {
            return 0.0;
        }

        /*
         * Se trabaja en un plano local en metros en vez de sobre la esfera.
         *
         * A las distancias de una ciudad el error es despreciable, y evita
         * resolver trigonometría esférica en cada uno de los cientos de puntos
         * de una ruta por cada posición recibida. El truco está en encoger la
         * longitud por el coseno de la latitud: sin eso, un grado de longitud
         * en Cartagena valdría lo mismo que uno de latitud, y no vale.
         */
        $escalaLon = cos(deg2rad($lat)) * self::RADIO_TIERRA_M * M_PI / 180.0;
        $escalaLat = self::RADIO_TIERRA_M * M_PI / 180.0;

        $px = $lon * $escalaLon;
        $py = $lat * $escalaLat;

        $minimo = INF;

        for ($i = 0, $n = count($geometria) - 1; $i < $n; $i++) {
            $ax = $geometria[$i][0]     * $escalaLon;
            $ay = $geometria[$i][1]     * $escalaLat;
            $bx = $geometria[$i + 1][0] * $escalaLon;
            $by = $geometria[$i + 1][1] * $escalaLat;

            $d = $this->distanciaASegmento($px, $py, $ax, $ay, $bx, $by);

            if ($d < $minimo) {
                $minimo = $d;

                // Ya está dentro de la ruta: ningún otro segmento va a dar
                // menos que esto y recorrer el resto es trabajo perdido.
                if ($minimo < 1.0) {
                    break;
                }
            }
        }

        return $minimo === INF ? 0.0 : $minimo;
    }

    /**
     * Evalúa un rastro completo y dice si hay desvío sostenido.
     *
     * @param  list<array{lat: float, lon: float, momento: \DateTimeInterface}>  $rastro
     * @param  list<array{float, float}>  $geometria
     * @return array{desviado: bool, metros_max: float, minutos_fuera: float, desde: ?\DateTimeInterface}
     */
    public function evaluar(array $rastro, array $geometria): array
    {
        $umbral  = (float) config('ltp.mapa.desvio_metros', 250);
        $minutos = (float) config('ltp.mapa.desvio_minutos', 4);

        $metrosMax  = 0.0;
        $desde      = null;
        $fueraDesde = null;
        $peorTramo  = 0.0;

        foreach ($rastro as $punto) {
            $d = $this->distanciaARuta($punto['lat'], $punto['lon'], $geometria);
            $metrosMax = max($metrosMax, $d);

            if ($d > $umbral) {
                // Arranca el cronómetro en el primer punto fuera de la ruta.
                $fueraDesde ??= $punto['momento'];

                $transcurrido = ($punto['momento']->getTimestamp() - $fueraDesde->getTimestamp()) / 60;

                if ($transcurrido > $peorTramo) {
                    $peorTramo = $transcurrido;
                    $desde     = $fueraDesde;
                }
            } else {
                // Volvió a la ruta: el cronómetro se reinicia. Lo que ya pasó
                // queda registrado en `$peorTramo`.
                $fueraDesde = null;
            }
        }

        return [
            'desviado'      => $peorTramo >= $minutos,
            'metros_max'    => round($metrosMax),
            'minutos_fuera' => round($peorTramo, 1),
            'desde'         => $peorTramo >= $minutos ? $desde : null,
        ];
    }

    /** Distancia de un punto a un segmento, ya en coordenadas planas. */
    private function distanciaASegmento(
        float $px, float $py,
        float $ax, float $ay,
        float $bx, float $by,
    ): float {
        $dx = $bx - $ax;
        $dy = $by - $ay;

        $largo2 = $dx * $dx + $dy * $dy;

        // Segmento degenerado: los dos extremos coinciden.
        if ($largo2 <= 0.0) {
            return hypot($px - $ax, $py - $ay);
        }

        // Proyección del punto sobre la recta, recortada al segmento: sin el
        // recorte se mediría contra la prolongación infinita de la vía.
        $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $largo2;
        $t = max(0.0, min(1.0, $t));

        return hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
    }
}
