<?php

namespace Database\Seeders;

use App\Models\Dispositivo;
use App\Models\InstalacionDispositivo;
use App\Models\Vehiculo;
use App\Services\Dispositivos\LecturaNormalizada;
use App\Services\Dispositivos\ProcesadorTelemetria;
use Illuminate\Database\Seeder;

/**
 * Sondas Tempubi con 48 horas de telemetría simulada.
 *
 * Las lecturas no se insertan a mano: se pasan por el mismo procesador que usa
 * la ingesta real. Así el seeder ejercita el camino de producción —detección de
 * episodios, congelado de paquetes afectados, anotación en bitácora— en vez de
 * fabricar un resultado bonito que nunca se probó.
 */
class DispositivosSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('DispositivosSeeder no se ejecuta en producción.');

            return;
        }

        $procesador = app(ProcesadorTelemetria::class);

        // Solo los vehículos refrigerados llevan sonda: instrumentar una moto
        // que nunca transporta frío sería gastar un equipo.
        $vehiculos = Vehiculo::where('refrigeracion', true)->orderBy('placa')->get();

        if ($vehiculos->isEmpty()) {
            $this->command->warn('No hay vehículos refrigerados: no se instalan sondas.');

            return;
        }

        $barra = $this->command->getOutput()->createProgressBar($vehiculos->count());

        foreach ($vehiculos as $i => $vehiculo) {
            $dispositivo = Dispositivo::updateOrCreate(
                ['serial' => sprintf('TPB-%s-%04d', strtoupper(substr($vehiculo->placa, 0, 3)), 1000 + $i)],
                [
                    'proveedor'        => 'tempubi',
                    'modelo'           => 'Tempubi TU-300',
                    'alias'            => "Cava {$vehiculo->placa}",
                    'vehiculo_id'      => $vehiculo->id,
                    'mide_temperatura' => true,
                    'mide_ubicacion'   => true,
                    'rango_min'        => 2.0,
                    'rango_max'        => 8.0,
                    'intervalo_seg'    => 300,
                    'activo'           => true,
                ]
            );

            if ($dispositivo->secreto_ingesta === null) {
                $dispositivo->generarSecreto();
            }

            InstalacionDispositivo::firstOrCreate(
                ['dispositivo_id' => $dispositivo->id, 'vehiculo_id' => $vehiculo->id, 'hasta' => null],
                ['desde' => now()->subMonths(3)]
            );

            $procesador->procesar($dispositivo, $this->simular($dispositivo, $i));

            $barra->advance();
        }

        $barra->finish();
        $this->command->newLine(2);
        $this->command->info(
            Dispositivo::count().' sonda(s) Tempubi instaladas · '
            .\App\Models\Telemetria::count().' lecturas · '
            .\App\Models\Excursion::count().' excursión(es) detectada(s)'
        );
    }

    /**
     * 48 horas de lecturas cada 5 minutos.
     *
     * La curva no es plana: oscila suavemente alrededor de 5 °C, que es como se
     * comporta una cava real, y a uno de cada tres equipos se le mete una
     * excursión de unos 35 minutos —la puerta abierta demasiado tiempo en un
     * muelle de carga—. Una serie perfecta no permitiría probar nada.
     *
     * @return array<int, LecturaNormalizada>
     */
    private function simular(Dispositivo $dispositivo, int $indice): array
    {
        $lecturas = [];
        $inicio   = now()->subHours(48);
        $pasos    = (48 * 60) / 5;

        // Recorrido aproximado por Medellín: la sonda también da ubicación, así
        // que la carga se puede seguir en el mapa aunque el teléfono del
        // mensajero se quede sin batería.
        $lat = 6.2447;
        $lon = -75.5812;

        $conExcursion = $indice % 3 === 0;
        $desdePaso    = (int) ($pasos * 0.62);
        $hastaPaso    = $desdePaso + 7;   // 7 lecturas × 5 min = 35 minutos

        for ($p = 0; $p < $pasos; $p++) {
            $momento = (clone $inicio)->addMinutes($p * 5);

            // Oscilación suave alrededor de 5 °C, con algo de ruido.
            $base = 5.0 + sin($p / 14) * 1.15 + (mt_rand(-25, 25) / 100);

            if ($conExcursion && $p >= $desdePaso && $p <= $hastaPaso) {
                // Sube por encima de 8 °C: puerta abierta en el muelle.
                $base = 8.6 + (($p - $desdePaso) * 0.28) + (mt_rand(-12, 12) / 100);
            }

            $lat += mt_rand(-14, 14) / 10000;
            $lon += mt_rand(-14, 14) / 10000;

            $lecturas[] = new LecturaNormalizada(
                serial: $dispositivo->serial,
                medidoEn: \DateTimeImmutable::createFromMutable($momento->toDateTime()),
                temperatura: round($base, 2),
                humedad: round(48 + (mt_rand(-60, 60) / 10), 1),
                latitud: round($lat, 7),
                longitud: round($lon, 7),
                precisionM: mt_rand(4, 22),
                velocidadKmh: mt_rand(0, 48),
                bateria: max(12, 100 - (int) ($p / 12)),
                crudo: ['origen' => 'seeder', 'paso' => $p],
            );
        }

        return $lecturas;
    }
}
