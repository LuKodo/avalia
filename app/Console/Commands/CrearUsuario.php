<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\Mensajero;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

/**
 * Alta de usuarios desde la consola.
 *
 * Existe porque el seeder de pruebas no sirve para el día a día: cuando entra
 * un mensajero nuevo o un cliente pide una cuenta más, hace falta un camino que
 * no implique reejecutar datos de demostración ni escribir SQL a mano.
 *
 * La contraseña NUNCA se pasa por argumento: quedaría en el historial de la
 * terminal y en los registros del sistema. Se pide de forma oculta, o se genera
 * una temporal que el usuario debe cambiar al primer ingreso.
 */
class CrearUsuario extends Command
{
    protected $signature = 'ltp:usuario
                            {correo : Correo electrónico de la cuenta}
                            {--nombre= : Nombre completo}
                            {--rol=operador : administrador|operador|cliente|mensajero}
                            {--cliente= : Código o NIT del cliente, obligatorio para el rol cliente}
                            {--vehiculo= : Placa del vehículo, para el rol mensajero}
                            {--generar : Genera una contraseña temporal en vez de pedirla}';

    protected $description = 'Crea o actualiza un usuario de Avalia con su rol';

    public function handle(): int
    {
        $correo = strtolower(trim($this->argument('correo')));
        $rol    = $this->option('rol');

        $validador = Validator::make(
            ['correo' => $correo, 'rol' => $rol],
            [
                'correo' => ['required', 'email:rfc'],
                'rol'    => ['required', 'in:administrador,operador,cliente,mensajero'],
            ]
        );

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $mensaje) {
                $this->error($mensaje);
            }

            return self::FAILURE;
        }

        $nombre = $this->option('nombre') ?: $this->ask('Nombre completo');

        // --- contraseña ------------------------------------------------------
        if ($this->option('generar')) {
            $clave     = $this->claveTemporal();
            $temporal  = true;
        } else {
            $clave = $this->secret('Contraseña (no se muestra al escribir)');

            $reglas = Validator::make(['clave' => $clave], [
                'clave' => ['required', Password::min(12)->letters()->numbers()->symbols()->uncompromised()],
            ]);

            if ($reglas->fails()) {
                $this->error('La contraseña no cumple la política:');
                foreach ($reglas->errors()->get('clave') as $mensaje) {
                    $this->line('  · '.$mensaje);
                }

                return self::FAILURE;
            }

            $temporal = false;
        }

        // --- alcance por cliente ---------------------------------------------
        $clienteId = null;

        if ($rol === 'cliente') {
            $referencia = $this->option('cliente') ?: $this->ask('Código o NIT del cliente');

            $cliente = Cliente::where('codigo', $referencia)->orWhere('nit', $referencia)->first();

            if ($cliente === null) {
                $this->error("No existe un cliente con código o NIT «{$referencia}».");

                return self::FAILURE;
            }

            $clienteId = $cliente->id;
        }

        // --- alta -------------------------------------------------------------
        $usuario = User::firstOrNew(['email' => $correo]);
        $existia = $usuario->exists;

        $usuario->fill([
            'nombre'     => $nombre,
            'password'   => Hash::make($clave),
            'cliente_id' => $clienteId,
            'activo'     => true,
            // Los roles administrativos exigen doble factor sin excepción.
            'doble_factor' => in_array($rol, ['administrador', 'operador'], true),
        ])->save();

        // Guard explicito: ver la nota en CatalogoController.
        Role::findOrCreate($rol, 'web');

        $usuario->syncRoles([$rol]);

        if ($rol === 'mensajero') {
            $this->perfilDeCampo($usuario);
        }

        $this->newLine();
        $this->info($existia ? "Usuario actualizado: {$correo}" : "Usuario creado: {$correo}");
        $this->line("  Rol: {$rol}");

        if ($temporal) {
            $this->newLine();
            $this->warn("Contraseña temporal: {$clave}");
            $this->line('  Entréguela por un canal seguro y exija el cambio en el primer ingreso.');
            $this->line('  No queda registrada en ninguna bitácora del sistema.');
        }

        if ($usuario->doble_factor) {
            $this->newLine();
            $this->line('  Este rol exige doble factor: el usuario debe configurarlo al entrar.');
        }

        return self::SUCCESS;
    }

    /** Perfil de campo mínimo para que la cuenta pueda operar de inmediato. */
    private function perfilDeCampo(User $usuario): void
    {
        $placa = $this->option('vehiculo');

        $vehiculo = $placa
            ? Vehiculo::where('placa', strtoupper($placa))->first()
            : null;

        if ($placa && $vehiculo === null) {
            $this->warn("No existe el vehículo «{$placa}». El mensajero queda sin vehículo asignado ");
            $this->warn('y el filtro duro lo descartará de toda asignación hasta que se le asigne uno.');
        }

        $consecutivo = Mensajero::count() + 1;

        Mensajero::firstOrCreate(
            ['user_id' => $usuario->id],
            [
                'codigo'      => sprintf('MSJ-%03d', $consecutivo),
                'vehiculo_id' => $vehiculo?->id,
                'en_turno'    => false,
                'activo'      => true,
            ]
        );
    }

    /**
     * Contraseña temporal legible en voz alta: sin caracteres que se confundan
     * al dictarla por teléfono, que es como termina llegando a un mensajero en
     * la calle. La entropía la aporta la longitud, no la ambigüedad.
     */
    private function claveTemporal(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $clave    = '';

        for ($i = 0; $i < 16; $i++) {
            $clave .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $clave.'#7';
    }
}
