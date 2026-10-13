<?php

namespace App\Console\Commands;

use App\Enums\EstadoPaquete;
use App\Models\Paquete;
use App\Models\Posicion;
use App\Services\Geo\PlanificadorRuta;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Genera rastros de posición sobre las rutas ya trazadas.
 *
 * Sirve para poder ver y probar el mapa sin esperar a que un mensajero recorra
 * la ciudad con el teléfono en la mano. Uno de cada cinco recorridos se aparta
 * de la ruta a propósito: sin un desvío real que mirar, no hay forma de saber
 * si el detector funciona o solo dice siempre que todo va bien.
 *
 * NO debe correrse contra datos de producción: escribe posiciones que nadie
 * reportó, y una posición inventada en una tabla que sustenta una entrega es
 * exactamente el tipo de dato que no debe existir. El comando lo verifica.
 */
class SimularRecorridos extends Command
{
    protected $signature = 'avalia:simular-recorridos
        {--paquetes=12 : Cuántos paquetes recorrer}
        {--cada=40 : Segundos entre una posición y la siguiente}';

    protected $description = 'Genera rastros de posición sobre rutas trazadas, para ver el mapa con datos';

    public function handle(PlanificadorRuta $planificador): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando no corre en producción: escribe posiciones que nadie reportó.');

            return self::FAILURE;
        }

        $cuantos = max(1, (int) $this->option('paquetes'));
        $cada    = max(10, (int) $this->option('cada'));

        // Solo paquetes que ya tienen mensajero y momento de recolección: sin
        // eso no hay ni quién reporte ni desde cuándo.
        $paquetes = Paquete::with('orden.origen', 'orden.destino', 'orden.destinatario')
            ->whereNotNull('mensajero_id')
            ->whereNotNull('recolectado_en')
            ->whereIn('estado', [
                EstadoPaquete::EnTransito->value,
                EstadoPaquete::EnSitio->value,
                EstadoPaquete::Entregado->value,
            ])
            ->orderByDesc('recolectado_en')
            ->limit($cuantos)
            ->get();

        if ($paquetes->isEmpty()) {
            $this->warn('No hay paquetes recolectados con mensajero asignado.');

            return self::SUCCESS;
        }

        $totalPuntos = 0;
        $conDesvio   = 0;
        $sinRuta     = 0;

        $barra = $this->output->createProgressBar($paquetes->count());
        $barra->start();

        $solapados = 0;

        foreach ($paquetes as $i => $paquete) {
            // Un mensajero no puede estar en dos sitios a la vez.
            //
            // Si ya tiene posiciones dentro de esta ventana —porque llevaba
            // otro paquete al mismo tiempo—, generar un segundo recorrido
            // produce dos rastros entrelazados sobre la misma persona. El
            // resultado es un rastro que salta entre dos rutas, y el detector
            // de desvío se pasa el día viendo puntos sueltos fuera de ruta que
            // no son un desvío sino un dato imposible.
            if ($this->yaTieneRastro($paquete)) {
                $solapados++;
                $barra->advance();

                continue;
            }

            $ruta = $planificador->paraOrden($paquete->orden);

            if ($ruta === null) {
                $sinRuta++;
                $barra->advance();

                continue;
            }

            // Uno de cada cinco se sale de la ruta.
            $desviar = ($i % 5) === 3;

            $puntos = $this->recorrer($paquete, $ruta->geometria, $cada, $desviar);

            if ($puntos !== []) {
                // `insert` en bloque y no un modelo por punto: son cientos de
                // filas por paquete y una consulta por fila tarda minutos.
                foreach (array_chunk($puntos, 500) as $lote) {
                    Posicion::insert($lote);
                }

                $totalPuntos += count($puntos);
                $conDesvio   += $desviar ? 1 : 0;
            }

            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);

        $this->info("{$totalPuntos} posiciones sobre {$paquetes->count()} recorridos.");
        $this->line("  · {$conDesvio} con desvío deliberado, para poder probar la detección.");

        if ($sinRuta > 0) {
            $this->warn("  · {$sinRuta} sin ruta: les falta coordenada en el origen o el destino.");
        }

        if ($solapados > 0) {
            $this->line("  · {$solapados} omitidos: su mensajero ya tenía rastro en esa franja.");
        }

        return self::SUCCESS;
    }

    /**
     * ¿Este mensajero ya reportó posiciones durante la ventana del paquete?
     *
     * El margen de un minuto a cada lado evita que dos recorridos que se tocan
     * justo en el borde queden ambos aceptados y produzcan un salto brusco en
     * el rastro.
     */
    private function yaTieneRastro(Paquete $paquete): bool
    {
        $desde = Carbon::parse($paquete->recolectado_en)->subMinute();
        $hasta = ($paquete->entregado_en
            ? Carbon::parse($paquete->entregado_en)
            : Carbon::parse($paquete->recolectado_en)->addMinutes(45))->addMinute();

        return Posicion::where('mensajero_id', $paquete->mensajero_id)
            ->whereBetween('registrado_en', [$desde, $hasta])
            ->exists();
    }

    /**
     * Camina la polilínea repartiendo posiciones en el tiempo del envío.
     *
     * @param  list<array{float, float}>  $geometria
     * @return list<array<string, mixed>>
     */
    private function recorrer(Paquete $paquete, array $geometria, int $cada, bool $desviar): array
    {
        if (count($geometria) < 2) {
            return [];
        }

        $desde = Carbon::parse($paquete->recolectado_en);
        $hasta = $paquete->entregado_en
            ? Carbon::parse($paquete->entregado_en)
            : $desde->copy()->addMinutes(45);

        // `abs`: en Carbon 3 la diferencia lleva signo y según el orden de los
        // operandos sale negativa, con lo que el recorrido entero se descartaba
        // en silencio.
        $segundos = max($cada, (int) abs($hasta->diffInSeconds($desde)));
        $cuantos  = min(400, (int) floor($segundos / $cada));

        if ($cuantos < 2) {
            return [];
        }

        // El tramo donde se sale de la ruta: entre el 45 % y el 70 % del
        // trayecto. Ni al principio ni al final, para que el desvío quede en
        // medio del recorrido y se vea como lo que es.
        $desvioDesde = (int) floor($cuantos * 0.45);
        $desvioHasta = (int) floor($cuantos * 0.70);

        $filas = [];

        for ($i = 0; $i < $cuantos; $i++) {
            $avance = $i / ($cuantos - 1);
            [$lon, $lat] = $this->sobreLaRuta($geometria, $avance);

            // Ruido del GPS urbano: unas decenas de metros. Sin él, un rastro
            // perfectamente pegado a la ruta se ve sintético.
            $lat += (mt_rand(-25, 25) / 100000);
            $lon += (mt_rand(-25, 25) / 100000);

            if ($desviar && $i >= $desvioDesde && $i <= $desvioHasta) {
                /*
                 * Se aparta hasta ~600 m PERPENDICULARMENTE a la vía, entrando
                 * y saliendo suavemente para que no parezca un salto de señal.
                 *
                 * Perpendicular y no en una diagonal fija: lo que mide el
                 * detector es la distancia transversal a la ruta, y un
                 * desplazamiento paralelo a la vía —por larga que sea la
                 * distancia recorrida— no separa nada. Con una diagonal fija,
                 * el desvío solo se notaba en las rutas que iban en la
                 * dirección contraria; en las demás quedaba disimulado.
                 */
                $tramo = ($i - $desvioDesde) / max(1, $desvioHasta - $desvioDesde);
                $apart = sin($tramo * M_PI) * 0.0055;

                [$rumboLat, $rumboLon] = $this->rumbo($geometria, $avance);

                // Girar el rumbo 90°: (dx, dy) -> (-dy, dx).
                $lat += $apart * $rumboLon;
                $lon -= $apart * $rumboLat;
            }

            $momento = $desde->copy()->addSeconds((int) round($avance * $segundos));

            $filas[] = [
                'mensajero_id'  => $paquete->mensajero_id,
                'latitud'       => round($lat, 7),
                'longitud'      => round($lon, 7),
                'precision_m'   => mt_rand(6, 28),
                'velocidad_kmh' => mt_rand(0, 48),
                // Formato explícito: `insert` no pasa por los casts del modelo,
                // así que aquí no hay nadie que convierta un Carbon por
                // nosotros. Es el mismo detalle que ya costó un desfase de
                // horas en el reporte de posiciones del campo.
                'registrado_en' => $momento->format('Y-m-d H:i:s'),
                'recibido_en'   => $momento->format('Y-m-d H:i:s'),
            ];
        }

        return $filas;
    }

    /**
     * La dirección de la vía en ese punto, como vector unitario [lat, lon].
     *
     * @param  list<array{float, float}>  $geometria
     * @return array{float, float}
     */
    private function rumbo(array $geometria, float $avance): array
    {
        // Se mira un poco más adelante y un poco más atrás para no tomar la
        // dirección de un microsegmento, que en una curva apunta a cualquier
        // lado.
        [$aLon, $aLat] = $this->sobreLaRuta($geometria, max(0.0, $avance - 0.02));
        [$bLon, $bLat] = $this->sobreLaRuta($geometria, min(1.0, $avance + 0.02));

        $dLat = $bLat - $aLat;
        $dLon = $bLon - $aLon;
        $largo = hypot($dLat, $dLon);

        if ($largo <= 0.0) {
            return [1.0, 0.0];
        }

        return [$dLat / $largo, $dLon / $largo];
    }

    /**
     * El punto de la polilínea al `avance` indicado (0 a 1), repartido por
     * longitud recorrida y no por número de vértices: una ruta tiene los
     * vértices apretados en las curvas y separados en las rectas, y repartir
     * por vértice dejaría el vehículo casi quieto en cada esquina.
     *
     * @param  list<array{float, float}>  $geometria
     * @return array{float, float}
     */
    private function sobreLaRuta(array $geometria, float $avance): array
    {
        $largos = [];
        $total  = 0.0;

        for ($i = 0, $n = count($geometria) - 1; $i < $n; $i++) {
            $d = hypot(
                $geometria[$i + 1][0] - $geometria[$i][0],
                $geometria[$i + 1][1] - $geometria[$i][1],
            );
            $largos[] = $d;
            $total   += $d;
        }

        if ($total <= 0.0) {
            return $geometria[0];
        }

        $objetivo = $avance * $total;
        $acumulado = 0.0;

        foreach ($largos as $i => $d) {
            if ($acumulado + $d >= $objetivo) {
                $t = $d > 0 ? ($objetivo - $acumulado) / $d : 0;

                return [
                    $geometria[$i][0] + $t * ($geometria[$i + 1][0] - $geometria[$i][0]),
                    $geometria[$i][1] + $t * ($geometria[$i + 1][1] - $geometria[$i][1]),
                ];
            }

            $acumulado += $d;
        }

        return $geometria[count($geometria) - 1];
    }
}
