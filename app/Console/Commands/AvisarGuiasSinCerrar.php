<?php

namespace App\Console\Commands;

use App\Enums\EstadoPaquete;
use App\Mail\GuiasSinCerrar;
use App\Models\Paquete;
use App\Models\Parametro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por correo de lo que lleva días parado.
 *
 * La pantalla de despacho ya lo muestra, y aun así hace falta esto: una guía
 * que lleva cinco días quieta es precisamente una que nadie está mirando, y
 * pedirle a la operación que recuerde abrir una pantalla para enterarse de lo
 * que se le está olvidando es pedirle que resuelva el problema que tiene.
 *
 * Se avisa a los dos responsables, y a cada uno lo suyo:
 *
 *  · **Avalia** recibe la lista completa con el nombre de quien tiene cada
 *    caja, porque la pregunta que tiene que poder hacer es «¿a quién le
 *    pregunto?».
 *  · **Cada cliente** recibe solo sus guías y sin el nombre del mensajero:
 *    quién la lleva es dato del trabajador y al cliente no le sirve de nada.
 *    Lo que él necesita es decidir si reprograma con el paciente.
 *
 * Un solo correo por destinatario. Veinte correos a las siete de la mañana se
 * marcan como leídos en bloque, y el día que importe nadie lo abrirá.
 */
class AvisarGuiasSinCerrar extends Command
{
    protected $signature = 'avalia:avisar-sin-cerrar
                            {--dias= : Días sin cerrarse. Por omisión, el parámetro alertas.dias_sin_cerrar}
                            {--seco : Enumera lo que mandaría, sin mandar nada}';

    protected $description = 'Avisa por correo de las guías que llevan días sin entregarse ni cerrarse';

    public function handle(): int
    {
        $dias = (int) ($this->option('dias') ?: Parametro::obtener('alertas.dias_sin_cerrar', 3));
        $dias = max(1, min(60, $dias));

        $paquetes = Paquete::query()
            ->whereNotIn('estado', [
                EstadoPaquete::Entregado->value,
                EstadoPaquete::Devuelto->value,
                EstadoPaquete::Siniestro->value,
                EstadoPaquete::Cancelado->value,
            ])
            ->where('created_at', '<=', now()->subDays($dias))
            ->with(['orden.cliente', 'orden.destino', 'orden.destinatario', 'mensajero.user'])
            ->orderBy('created_at')
            ->get();

        if ($paquetes->isEmpty()) {
            $this->info("No hay envíos con más de {$dias} días sin cerrarse. No se manda nada.");

            return self::SUCCESS;
        }

        $filas = $paquetes->map(fn (Paquete $p) => [
            'guia'       => $p->orden->numero_guia,
            'cliente_id' => $p->orden->cliente_id,
            'cliente'    => $p->orden->cliente->razon_social,
            'correo_cliente' => $p->orden->cliente->email_notificaciones,
            'destino'    => $p->orden->nombreDestino() ?? '—',
            'estado'     => $p->estado->etiqueta(),
            'custodia'   => $p->mensajero?->nombre() ?? 'Sin asignar',
            'dias'       => (int) $p->created_at->diffInDays(now()),
        ]);

        $enviados = 0;

        // --- Avalia: la lista entera, con quién tiene cada caja ------------
        $operacion = (string) Parametro::obtener('alertas.correo_operacion', '');

        if ($operacion !== '') {
            foreach (array_filter(array_map('trim', explode(',', $operacion))) as $correo) {
                $this->mandar($correo, $filas->all(), $dias, paraOperacion: true);
                $enviados++;
            }
        } else {
            $this->warn('No hay destinatario de operación: fije «alertas.correo_operacion» en Administración · Parámetros.');
        }

        // --- Cada cliente: solo lo suyo, sin el nombre del mensajero -------
        foreach ($filas->groupBy('cliente_id') as $suyas) {
            $correo = $suyas->first()['correo_cliente'];

            if (! $correo) {
                continue;
            }

            $this->mandar($correo, $suyas->values()->all(), $dias, paraOperacion: false);
            $enviados++;
        }

        $this->info(($this->option('seco') ? 'Se habrían mandado ' : 'Mandados ')
            ."{$enviados} correo(s) por {$paquetes->count()} envío(s) con más de {$dias} días.");

        return self::SUCCESS;
    }

    /** @param  list<array<string, mixed>>  $guias */
    private function mandar(string $correo, array $guias, int $dias, bool $paraOperacion): void
    {
        $this->line(sprintf(
            '  %-40s %d guía(s)%s',
            $correo,
            count($guias),
            $paraOperacion ? ' · con custodia' : ''
        ));

        if ($this->option('seco')) {
            return;
        }

        /*
         * Un correo que no sale no puede tumbar los demás. Si el servidor de
         * correo del cliente rechaza, el aviso de Avalia —que es el que de
         * verdad hace que alguien actúe— tiene que haber salido igual.
         */
        try {
            Mail::to($correo)->queue(new GuiasSinCerrar($guias, $dias, $paraOperacion));
        } catch (\Throwable $e) {
            Log::warning('No se pudo encolar el aviso de guías sin cerrar', [
                'destino' => $correo,
                'error'   => $e->getMessage(),
            ]);

            $this->warn("    no se pudo encolar: {$e->getMessage()}");
        }
    }
}
