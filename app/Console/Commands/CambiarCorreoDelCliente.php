<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el correo de un cliente: el de acceso y el de avisos.
 *
 * Son dos cosas distintas y se confunden con facilidad:
 *
 *  · **El correo de acceso** es con el que la persona entra a la plataforma.
 *    Cambiarlo cambia su usuario: a partir de ese momento el anterior deja de
 *    servir para iniciar sesión.
 *  · **El correo de avisos** es el del CLIENTE, no el de la persona. Ahí llegan
 *    los cambios de estado, las novedades y la alerta de envíos parados. Vive
 *    en la ficha del cliente y puede ser un buzón compartido que nadie usa para
 *    entrar.
 *
 * Por omisión se cambian los dos, que es lo que casi siempre se quiere cuando
 * una persona deja de ser el contacto. `--solo-avisos` deja intacto el acceso,
 * para cuando lo que cambia es a dónde se notifica y no quién entra.
 *
 *   php artisan avalia:cambiar-correo --cliente="Distribuciones Pharmaser" --a=operacion@pharmaser.com.co
 */
class CambiarCorreoDelCliente extends Command
{
    protected $signature = 'avalia:cambiar-correo
                            {--cliente= : Razón social del cliente, o parte de ella}
                            {--de= : Correo actual, si el cliente tiene varios usuarios}
                            {--a= : Correo nuevo}
                            {--solo-avisos : No toca el acceso; solo a dónde se notifica}';

    protected $description = 'Cambia el correo de acceso y/o de notificaciones de un cliente';

    public function handle(): int
    {
        $nuevo = trim((string) $this->option('a'));

        if (! filter_var($nuevo, FILTER_VALIDATE_EMAIL)) {
            $this->error('Indique --a=correo@valido. Un correo mal escrito aquí deja a alguien sin poder entrar.');

            return self::FAILURE;
        }

        $cliente = $this->cliente();

        if ($cliente === null) {
            return self::FAILURE;
        }

        $this->line('');
        $this->line('  Cliente : '.$cliente->razon_social.' (NIT '.$cliente->nit.')');
        $this->line('  Avisos  : '.($cliente->email_notificaciones ?: '— sin correo —').'  →  '.$nuevo);

        $usuario = null;

        if (! $this->option('solo-avisos')) {
            $usuario = $this->usuario($cliente);

            if ($usuario === false) {
                return self::FAILURE;
            }

            if ($usuario !== null) {
                $this->line('  Acceso  : '.$usuario->email.'  →  '.$nuevo);
            }
        }

        $this->line('');

        /*
         * Se confirma siempre. Cambiar el correo de acceso es irreversible
         * desde el punto de vista de quien entra: si se equivoca uno y nadie
         * se da cuenta hasta el lunes, esa persona pasa el fin de semana sin
         * poder radicar.
         */
        if (! $this->confirm('¿Aplicar el cambio?', false)) {
            $this->line('  No se cambió nada.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cliente, $usuario, $nuevo) {
            $cliente->forceFill(['email_notificaciones' => $nuevo])->save();

            $usuario?->forceFill(['email' => $nuevo])->save();

            /*
             * Los tokens se revocan al cambiar el acceso.
             *
             * Un token emitido para el correo viejo sigue siendo válido hasta
             * que caduque: si el cambio es porque esa persona ya no está, el
             * acceso tiene que cortarse hoy, no cuando expire. Quien siga
             * siendo el titular solo tiene que volver a entrar.
             */
            $usuario?->tokens()->delete();
        });

        $this->line('');
        $this->info('  Hecho.');

        if ($usuario !== null) {
            $this->warn('  La sesión abierta de esa persona dejó de valer: debe entrar de nuevo con el correo nuevo.');
            $this->warn('  La contraseña NO cambió.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function cliente(): ?Cliente
    {
        $aguja = trim((string) $this->option('cliente'));

        if ($aguja === '') {
            $this->error('Indique --cliente="parte de la razón social".');

            return null;
        }

        $encontrados = Cliente::where('razon_social', 'like', '%'.$aguja.'%')->get();

        if ($encontrados->isEmpty()) {
            $this->error("Ningún cliente coincide con «{$aguja}».");

            return null;
        }

        // Dos clientes parecidos y un cambio de correo es justo la combinación
        // en la que conviene parar y preguntar, no elegir por el operador.
        if ($encontrados->count() > 1) {
            $this->error('Hay varios clientes que coinciden. Afine --cliente:');

            foreach ($encontrados as $c) {
                $this->line('   · '.$c->razon_social);
            }

            return null;
        }

        return $encontrados->first();
    }

    /** @return User|null|false  false = hay que parar; null = seguir solo con avisos */
    private function usuario(Cliente $cliente): User|null|false
    {
        $usuarios = User::where('cliente_id', $cliente->id)->get();

        if ($usuarios->isEmpty()) {
            $this->warn('  Ese cliente no tiene usuarios: solo se cambia el correo de avisos.');

            return null;
        }

        $de = trim((string) $this->option('de'));

        if ($de !== '') {
            $elegido = $usuarios->firstWhere('email', $de);

            if ($elegido === null) {
                $this->error("Ese cliente no tiene ningún usuario con el correo «{$de}».");

                return false;
            }

            return $this->libre($elegido) ? $elegido : false;
        }

        if ($usuarios->count() > 1) {
            $this->error('Ese cliente tiene varios usuarios. Indique cuál con --de=:');

            foreach ($usuarios as $u) {
                $this->line('   · '.$u->email.'  ('.$u->nombre.')');
            }

            return false;
        }

        return $this->libre($usuarios->first()) ? $usuarios->first() : false;
    }

    /** Que el correo nuevo no esté ya en uso por otra cuenta. */
    private function libre(User $usuario): bool
    {
        $ocupado = User::where('email', $this->option('a'))
            ->where('id', '!=', $usuario->id)
            ->exists();

        if ($ocupado) {
            $this->error('Ese correo ya pertenece a otra cuenta de la plataforma.');

            return false;
        }

        return true;
    }
}
