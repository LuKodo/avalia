<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja la base con datos de demostración recientes, de una sola pasada.
 *
 * Existe por un motivo muy concreto: los datos sembrados envejecen. Una semana
 * después, el tablero muestra un hueco al final de sus series y el mapa de flota
 * pinta a todo el mundo en gris —«sin señal»—, porque la última posición es de
 * hace días. Para grabar una demostración o enseñarle el sistema a alguien, eso
 * se ve como una avería aunque no lo sea.
 *
 * Vacía la operación, la vuelve a sembrar terminando HOY y genera los recorridos
 * del mapa. Las CUENTAS NO SE TOCAN: quien ya tenía usuario sigue entrando con
 * la misma contraseña.
 */
class DatosDemo extends Command
{
    protected $signature = 'avalia:datos-demo
        {--recorridos=25 : Cuántos envíos reciben rastro en el mapa}
        {--forzar : No preguntar antes de borrar}';

    protected $description = 'Rehace los datos de demostración con fechas de hoy (conserva los usuarios)';

    /**
     * Lo que sobrevive: identidades, permisos, migraciones y parámetros.
     * Todo lo demás es dato de ejemplo y se regenera.
     */
    private const CONSERVAR = [
        'users',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
        'migrations',
        'parametros',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando BORRA la operación. No corre en producción.');

            return self::FAILURE;
        }

        if (! $this->option('forzar')) {
            $this->warn('Esto borra envíos, eventos, entregas, telemetría y posiciones.');
            $this->line('Las cuentas de usuario y sus contraseñas NO se tocan.');

            if (! $this->confirm('¿Continuar?', false)) {
                return self::SUCCESS;
            }
        }

        $this->vaciar();
        $this->reiniciarCadena();

        $this->newLine();
        $this->call('db:seed', ['--force' => true]);

        $this->newLine();
        $this->call('avalia:simular-recorridos', [
            '--paquetes' => (int) $this->option('recorridos'),
        ]);

        $this->newLine();
        $this->info('Datos de demostración al día. Listo para grabar.');
        $this->line('  Sitio:      http://localhost:8000');
        $this->line('  Plataforma: http://localhost:8000/ingreso');

        return self::SUCCESS;
    }

    /**
     * TRUNCATE y no DELETE: reinicia los consecutivos —las guías nuevas
     * arrancan en 0001— y no pasa por los disparadores de solo-anexado de la
     * bitácora, que impedirían borrarla.
     *
     * Sobre la conexión administrativa: el usuario de la aplicación tiene
     * permisos por tabla y no puede truncar, que es justo lo que se espera de
     * un usuario de aplicación.
     */
    private function vaciar(): void
    {
        $cx = DB::connection(config('database.admin'));

        $todas = array_map(
            static fn ($fila) => array_values((array) $fila)[0],
            $cx->select('SHOW TABLES')
        );

        $cx->statement('SET FOREIGN_KEY_CHECKS = 0');

        $vaciadas = 0;

        foreach (array_diff($todas, self::CONSERVAR) as $tabla) {
            try {
                $cx->statement("TRUNCATE TABLE `{$tabla}`");
                $vaciadas++;
            } catch (\Throwable $e) {
                $this->warn("  No se pudo vaciar {$tabla}: ".substr($e->getMessage(), 0, 70));
            }
        }

        $cx->statement('SET FOREIGN_KEY_CHECKS = 1');

        $this->line("  {$vaciadas} tablas vaciadas · ".DB::table('users')->count().' cuentas conservadas');
    }

    /**
     * Repone la fila semilla de la cadena de hashes.
     *
     * La crea la migración de la bitácora, así que truncar la tabla la deja sin
     * ella y el registrador de eventos falla en el primer evento que intenta
     * escribir. Es el primer tropiezo que da cualquiera que vacíe la base a mano.
     */
    private function reiniciarCadena(): void
    {
        $cx = DB::connection(config('database.admin'));

        if ($cx->table('cadena_estado')->count() === 0) {
            $cx->table('cadena_estado')->insert([
                'ultima_secuencia' => 0,
                'ultimo_hash'      => null,
                'actualizado_en'   => now(),
            ]);

            $this->line('  Cadena de la bitácora reiniciada en la secuencia 0');
        }
    }
}
