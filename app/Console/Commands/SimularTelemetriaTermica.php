<?php

namespace App\Console\Commands;

use App\Models\LecturaTemperatura;
use App\Models\Paquete;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Genera un perfil térmico continuo simulado para un paquete.
 *
 * Existe para poder ejercitar y demostrar el análisis térmico mientras los
 * dispositivos Tempubi no estén enviando datos. Todo lo que inserta queda
 * marcado con fuente «simulacion», de modo que nunca pueda confundirse con una
 * lectura de un sensor real: un perfil inventado que se vea igual que uno medido
 * sería exactamente la clase de dato que arruina un soporte ante una auditoría.
 *
 * No es un seeder de producción y no debe correrse sobre datos de clientes.
 */
class SimularTelemetriaTermica extends Command
{
    protected $signature = 'ltp:simular-telemetria
        {paquete : Código del paquete, por ejemplo AVL-260709-0002-P01}
        {--intervalo=10 : Minutos entre lecturas}
        {--excursion= : Perfil a inducir: calor, congelacion o ninguno}
        {--limpiar : Borra las lecturas simuladas previas del paquete}';

    protected $description = 'Genera lecturas de temperatura simuladas para probar el análisis de cadena de frío';

    public function handle(): int
    {
        $paquete = Paquete::where('codigo', $this->argument('paquete'))->first();

        if ($paquete === null) {
            $this->error("No existe el paquete {$this->argument('paquete')}.");

            return self::FAILURE;
        }

        if (! $paquete->exigeCadenaFrio()) {
            $this->error('Ese paquete no exige cadena de frío; no tiene perfil térmico que simular.');

            return self::FAILURE;
        }

        $inicio = $paquete->recolectado_en;
        $fin    = $paquete->entrega?->entregada_en;

        if ($inicio === null || $fin === null) {
            $this->error('El paquete necesita recolección y entrega registradas para acotar el trayecto.');

            return self::FAILURE;
        }

        if ($this->option('limpiar')) {
            $borradas = LecturaTemperatura::where('paquete_id', $paquete->id)
                ->where('fuente', 'simulacion')
                ->delete();

            $this->line("Lecturas simuladas anteriores borradas: {$borradas}");
        }

        $rango     = $paquete->rangoTermico();
        $intervalo = max(1, (int) $this->option('intervalo'));
        $minutos   = (int) ceil($inicio->diffInMinutes($fin, true));

        if ($minutos < $intervalo * 3) {
            // Un trayecto de veinte minutos no da para un registro continuo; se
            // extiende la ventana hacia atrás para que la demostración tenga
            // forma, y se avisa de que eso se hizo.
            $minutos = $intervalo * 24;
            $inicio  = $fin->copy()->subMinutes($minutos);

            $this->warn("El trayecto real es muy corto; se simula una ventana de {$minutos} min terminando en la entrega.");
        }

        $perfil = $this->option('excursion');
        $filas  = [];

        /*
         * Se arranca UN intervalo despues de la recoleccion y se corta antes de
         * la entrega. Sin ese margen la primera lectura simulada caia en el
         * mismo segundo que la manual de recoleccion, y dos lecturas del mismo
         * instante son un dato degenerado que el analisis tiene que resolver a
         * la fuerza. El simulador no debe fabricar esa ambiguedad.
         */
        for ($m = $intervalo; $m < $minutos; $m += $intervalo) {
            $filas[] = $this->lectura($paquete, $inicio->copy()->addMinutes($m), $m, $minutos, $rango, $perfil);
        }

        LecturaTemperatura::insert($filas);

        $this->info(sprintf(
            'Insertadas %d lecturas simuladas cada %d min sobre %s.',
            count($filas),
            $intervalo,
            $paquete->codigo
        ));

        $fuera = collect($filas)->where('fuera_de_rango', true)->count();
        $this->line("Lecturas fuera del rango {$rango['min']}–{$rango['max']} °C: {$fuera}");
        $this->line('Consulte la guía en /rastreo para ver el dictamen.');

        return self::SUCCESS;
    }

    /**
     * @param  array{min: float, max: float}  $rango
     * @return array<string, mixed>
     */
    private function lectura(Paquete $paquete, Carbon $cuando, int $m, int $total, array $rango, ?string $perfil): array
    {
        $centro = ($rango['min'] + $rango['max']) / 2;
        $u      = $total > 0 ? $m / $total : 0.0;

        // Deriva suave más ruido pequeño: una caja térmica real oscila, no
        // dibuja una recta.
        $valor = $centro
            + sin($u * M_PI * 3) * 0.55
            + (mt_rand(-25, 25) / 100);

        /*
         * Los episodios se inducen en el tercio central del trayecto, que es
         * donde de verdad ocurren: el vehículo en ruta, lejos de una nevera y
         * sin nadie mirando el indicador.
         */
        if ($perfil === 'calor' && $u > 0.42 && $u < 0.58) {
            $valor = $rango['max'] + 1.2 + sin(($u - 0.42) / 0.16 * M_PI) * 1.6;
        }

        if ($perfil === 'congelacion' && $u > 0.46 && $u < 0.54) {
            $valor = $rango['min'] - 1.0 - sin(($u - 0.46) / 0.08 * M_PI) * 3.0;
        }

        $valor = round($valor, 2);

        return [
            'paquete_id'     => $paquete->id,
            'valor'          => $valor,
            'momento'        => 'transito',
            // La marca que impide confundir esto con un sensor.
            'fuente'         => 'simulacion',
            'fuera_de_rango' => $valor < $rango['min'] || $valor > $rango['max'],
            'registrada_en'  => $cuando,
            'created_at'     => now(),
            'updated_at'     => now(),
        ];
    }
}
