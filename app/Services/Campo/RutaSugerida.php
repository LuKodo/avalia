<?php

namespace App\Services\Campo;

use App\Models\Mensajero;
use App\Support\Geo;
use Illuminate\Support\Collection;

/**
 * El orden en que conviene hacer las tareas del día.
 *
 * Dos criterios, y el orden entre ellos no es un detalle: **manda la hora
 * comprometida, y la cercanía desempata dentro de cada franja**.
 *
 * Al revés —cercanía primero— el resultado es una ruta preciosa que incumple:
 * el algoritmo encadena las cinco entregas del mismo barrio y deja para el
 * final la urgente del otro lado, que es justamente la que tenía hora. La
 * eficiencia del recorrido no vale nada si lo que se optimiza es llegar tarde
 * a lo que no podía esperar.
 *
 * Dentro de cada franja sí manda la geografía, y ahí se usa el vecino más
 * cercano encadenado: desde donde está el mensajero, la más próxima; desde
 * esa, la siguiente más próxima. No es la ruta óptima —eso es el problema del
 * viajante y no se resuelve en un teléfono— pero es la diferencia entre
 * cruzar la ciudad tres veces y no hacerlo.
 *
 * El resultado es una SUGERENCIA. El mensajero la aplica con un botón y a
 * partir de ahí la cambia a mano: él sabe qué portería cierra a mediodía y por
 * dónde está la obra que tiene cortada la vía, y eso no está en ninguna tabla.
 */
class RutaSugerida
{
    /**
     * Ancho de la franja de compromiso, en minutos.
     *
     * Dos entregas comprometidas a las 10:05 y a las 10:35 son, a efectos de
     * ruta, la misma cosa: lo que no puede pasar es que la de las 10 quede
     * después de la de las 14. Agrupar en franjas de hora evita que un minuto
     * de diferencia mande al mensajero a cruzar la ciudad.
     */
    private const FRANJA_MIN = 60;

    /**
     * Devuelve los identificadores de paquete en el orden sugerido.
     *
     * @param  Collection<int, array<string, mixed>>  $tareas  las que ya pinta la app
     * @return list<int>
     */
    public function ordenar(Collection $tareas, ?Mensajero $mensajero = null): array
    {
        $pendientes = $tareas
            ->filter(fn (array $t) => $t['accion'] !== null)
            ->values();

        if ($pendientes->isEmpty()) {
            return [];
        }

        /*
         * Punto de partida: donde está el mensajero, si se sabe.
         *
         * Si no hay posición —teléfono sin señal, GPS apagado, nadie ha salido
         * todavía— se arranca desde el primer destino de la franja más
         * temprana. Es arbitrario pero no es malo: encadena igual el resto, y
         * lo único que se pierde es acertar con la primera.
         */
        $desde = $mensajero?->ultima_latitud !== null && $mensajero?->ultima_longitud !== null
            ? ['lat' => (float) $mensajero->ultima_latitud, 'lon' => (float) $mensajero->ultima_longitud]
            : null;

        $orden = [];

        foreach ($this->porFranja($pendientes) as $grupo) {
            [$encadenadas, $desde] = $this->encadenar($grupo, $desde);

            foreach ($encadenadas as $t) {
                $orden[] = (int) $t['paquete_id'];
            }
        }

        return $orden;
    }

    /**
     * Agrupa por franja de compromiso, de la más temprana a la más tardía.
     *
     * Las que no tienen compromiso van al FINAL, no al principio. Un envío sin
     * hora pactada es el que puede esperar; ponerlo primero porque su campo
     * está vacío sería dejar que un dato que falta mande sobre uno que sí está.
     *
     * @param  Collection<int, array<string, mixed>>  $tareas
     * @return list<list<array<string, mixed>>>
     */
    private function porFranja(Collection $tareas): array
    {
        $grupos = [];

        foreach ($tareas as $t) {
            $momento = $t['comprometida_en'] ?? null;

            $clave = $momento === null
                ? PHP_INT_MAX
                : (int) floor(strtotime($momento) / (self::FRANJA_MIN * 60));

            $grupos[$clave][] = $t;
        }

        ksort($grupos);

        return array_values($grupos);
    }

    /**
     * Vecino más cercano encadenado dentro de una franja.
     *
     * @param  list<array<string, mixed>>  $grupo
     * @param  array{lat: float, lon: float}|null  $desde
     * @return array{0: list<array<string, mixed>>, 1: array{lat: float, lon: float}|null}
     */
    private function encadenar(array $grupo, ?array $desde): array
    {
        $resto    = $grupo;
        $cadena   = [];
        $posicion = $desde;

        while ($resto !== []) {
            $mejor = null;
            $mejorDistancia = null;

            foreach ($resto as $i => $t) {
                $punto = $this->puntoDe($t);

                /*
                 * Una tarea sin coordenada no se puede encadenar, pero tampoco
                 * se descarta: se deja para el final del grupo. Perderla sería
                 * mucho peor que ponerla en mal sitio.
                 */
                if ($punto === null) {
                    continue;
                }

                if ($posicion === null) {
                    $mejor = $i;
                    break;
                }

                $d = Geo::distanciaKm($posicion['lat'], $posicion['lon'], $punto['lat'], $punto['lon']);

                if ($mejorDistancia === null || $d < $mejorDistancia) {
                    $mejor = $i;
                    $mejorDistancia = $d;
                }
            }

            // Solo quedan tareas sin coordenada: se añaden tal cual y se cierra.
            if ($mejor === null) {
                foreach ($resto as $t) {
                    $cadena[] = $t;
                }

                break;
            }

            $elegida = $resto[$mejor];
            unset($resto[$mejor]);
            $resto = array_values($resto);

            $cadena[] = $elegida;
            $posicion = $this->puntoDe($elegida) ?? $posicion;
        }

        return [$cadena, $posicion];
    }

    /** @return array{lat: float, lon: float}|null */
    private function puntoDe(array $tarea): ?array
    {
        $sede = $tarea['sede'] ?? null;

        if ($sede === null || $sede['latitud'] === null || $sede['longitud'] === null) {
            return null;
        }

        return ['lat' => (float) $sede['latitud'], 'lon' => (float) $sede['longitud']];
    }
}
