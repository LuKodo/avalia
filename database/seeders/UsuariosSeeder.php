<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Mensajero;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Usuarios de prueba, uno por rol y por escenario.
 *
 * Separado de DemoSeeder a propósito: la operación simulada se puede volver a
 * generar o borrar cuantas veces haga falta sin perder las cuentas con las que
 * se está probando. Correr esto solo —`php artisan db:seed --class=UsuariosSeeder`—
 * deja el sistema utilizable aunque no haya un solo envío cargado.
 *
 * ADVERTENCIA: las contraseñas de abajo son de DEMOSTRACIÓN LOCAL. El seeder se
 * niega a correr en producción, pero eso no basta: en cuanto el entorno deje de
 * ser el equipo de desarrollo, cámbielas y active el doble factor real para los
 * roles administrativos.
 */
class UsuariosSeeder extends Seeder
{
    private const CLAVE = 'Avalia*2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('UsuariosSeeder no se ejecuta en producción.');

            return;
        }

        foreach (['administrador', 'operador', 'cliente', 'mensajero'] as $rol) {
            // Guard explicito: ver la nota en CatalogoController.
            Role::findOrCreate($rol, 'web');
        }

        $this->internos();
        $this->deClientes();
        $this->deCampo();

        $this->tabla();
    }

    /** Cuentas internas de Avalia: administración y mesa de despacho. */
    private function internos(): void
    {
        $this->crear(
            'admin@ltp.local',
            'Administración Avalia',
            'administrador',
            ['documento' => '43112900', 'telefono' => '604 000 0001', 'doble_factor' => true]
        );

        $this->crear(
            'operador@ltp.local',
            'Andrea Gómez',
            'operador',
            ['documento' => '1017234567', 'telefono' => '604 000 0002', 'doble_factor' => true]
        );

        // Segundo operador: sirve para probar que dos personas trabajando la
        // misma bandeja no se pisan, que es el caso real de una mesa de despacho.
        $this->crear(
            'operador2@ltp.local',
            'Ricardo Mesa',
            'operador',
            ['documento' => '71654321', 'telefono' => '604 000 0003', 'doble_factor' => true]
        );
    }

    /**
     * Cuentas de cliente. Se crean DOS clientes distintos a propósito: sin un
     * segundo cliente no hay forma de comprobar el aislamiento de datos, que es
     * el control de seguridad más importante del portal.
     */
    private function deClientes(): void
    {
        $clinica = Cliente::firstOrCreate(
            ['nit' => '890.900.123-4'],
            [
                'codigo'                  => 'CLN-001',
                'razon_social'            => 'Clínica del Norte S.A.S.',
                'email_notificaciones'    => 'farmacia@clinicadelnorte.example',
                'telefono'                => '604 444 1200',
                'etiqueta_referencia'     => 'Radicado',
                'tipo_referencia_defecto' => 'radicado',
                'referencia_obligatoria'  => true,
            ]
        );

        $hospital = Cliente::firstOrCreate(
            ['nit' => '811.020.567-1'],
            [
                'codigo'                  => 'HSR-002',
                'razon_social'            => 'Hospital San Rafael E.S.E.',
                'email_notificaciones'    => 'suministros@sanrafael.example',
                'telefono'                => '604 555 8800',
                // Este cliente llama «orden de compra» a su referencia. El
                // portal y el acta usan ese nombre, no uno genérico nuestro.
                'etiqueta_referencia'     => 'Orden de compra',
                'tipo_referencia_defecto' => 'orden_compra',
                'referencia_obligatoria'  => false,
            ]
        );

        $this->crear(
            'farmacia@clinicadelnorte.example',
            'Diana Restrepo',
            'cliente',
            ['cliente_id' => $clinica->id, 'documento' => '43881204', 'telefono' => '604 444 1230']
        );

        $this->crear(
            'suministros@sanrafael.example',
            'Óscar Yepes',
            'cliente',
            ['cliente_id' => $hospital->id, 'documento' => '8341220', 'telefono' => '604 555 8812']
        );
    }

    /**
     * Cuentas de campo. Tres perfiles distintos porque el filtro duro del motor
     * de asignación se prueba justamente con las diferencias: quién tiene
     * refrigeración, quién está certificado en control especial y quién no
     * puede tomar ninguno de los dos.
     */
    private function deCampo(): void
    {
        $perfiles = [
            [
                'correo'      => 'julian.ramirez@ltp.local',
                'nombre'      => 'Julián Ramírez',
                'codigo'      => 'MSJ-001',
                'placa'       => 'WKN482',
                'tipo'        => 'camioneta_refrigerada',
                'capacidad'   => 600,
                'refrigera'   => true,
                'certificado' => true,
                'nota'        => 'refrigerado + control especial',
            ],
            [
                'correo'      => 'marcela.ospina@ltp.local',
                'nombre'      => 'Marcela Ospina',
                'codigo'      => 'MSJ-002',
                'placa'       => 'TQL115',
                'tipo'        => 'camioneta_refrigerada',
                'capacidad'   => 600,
                'refrigera'   => true,
                'certificado' => false,
                'nota'        => 'refrigerado, SIN certificación',
            ],
            [
                'correo'      => 'carlos.bedoya@ltp.local',
                'nombre'      => 'Carlos Bedoya',
                'codigo'      => 'MSJ-003',
                'placa'       => 'JHR29D',
                'tipo'        => 'moto',
                'capacidad'   => 15,
                'refrigera'   => false,
                'certificado' => false,
                'nota'        => 'moto: no puede tomar frío ni controlados',
            ],
        ];

        foreach ($perfiles as $p) {
            $vehiculo = Vehiculo::firstOrCreate(
                ['placa' => $p['placa']],
                [
                    'tipo'                   => $p['tipo'],
                    'refrigeracion'          => $p['refrigera'],
                    'capacidad_kg'           => $p['capacidad'],
                    'soat_vence'             => now()->addMonths(8),
                    'tecnomecanica_vence'    => now()->addMonths(5),
                    'certificado_frio_vence' => $p['refrigera'] ? now()->addMonths(10) : null,
                ]
            );

            $usuario = $this->crear($p['correo'], $p['nombre'], 'mensajero', [
                'documento' => (string) random_int(10_000_000, 99_999_999),
            ]);

            Mensajero::firstOrCreate(
                ['codigo' => $p['codigo']],
                [
                    'user_id'                 => $usuario->id,
                    'vehiculo_id'             => $vehiculo->id,
                    'certificado_controlados' => $p['certificado'],
                    'certificado_vence'       => $p['certificado'] ? now()->addYear() : null,
                    'en_turno'                => true,
                    'turno_inicio'            => now()->startOfDay()->addHours(6),
                    // Con posición reciente: un mensajero sin señal en los
                    // últimos 15 minutos queda fuera del filtro duro y no
                    // aparecería como candidato al probar el despacho.
                    'ultima_latitud'          => 6.2447 + random_int(-300, 300) / 10000,
                    'ultima_longitud'         => -75.5812 + random_int(-300, 300) / 10000,
                    'ultima_posicion_en'      => now()->subMinutes(2),
                    'bateria'                 => random_int(45, 95),
                    'indice_cumplimiento'     => random_int(920, 995) / 10,
                ]
            );
        }
    }

    /** @param array<string, mixed> $extra */
    private function crear(string $correo, string $nombre, string $rol, array $extra = []): User
    {
        $usuario = User::firstOrCreate(
            ['email' => $correo],
            array_merge(['nombre' => $nombre, 'password' => Hash::make(self::CLAVE)], $extra)
        );

        // Si la cuenta ya existía, se restablece la clave: el sentido de este
        // seeder es que las cuentas de prueba siempre sirvan para entrar.
        $usuario->forceFill(['password' => Hash::make(self::CLAVE), 'activo' => true])->save();

        if (! $usuario->hasRole($rol)) {
            $usuario->assignRole($rol);
        }

        return $usuario;
    }

    private function tabla(): void
    {
        $this->command->newLine();
        $this->command->info('Usuarios de prueba listos. Contraseña para todos: '.self::CLAVE);
        $this->command->newLine();

        $this->command->table(
            ['Rol', 'Correo', 'Para probar'],
            [
                ['Administrador', 'admin@ltp.local', 'Configuración, tarifas, parámetros del motor'],
                ['Operador',      'operador@ltp.local', 'Bandeja de despacho, asignación, flota'],
                ['Operador',      'operador2@ltp.local', 'Dos operadores sobre la misma bandeja'],
                ['Cliente',       'farmacia@clinicadelnorte.example', 'Portal, consulta por radicado, exportación'],
                ['Cliente',       'suministros@sanrafael.example', 'Aislamiento: NO debe ver envíos del otro cliente'],
                ['Mensajero',     'julian.ramirez@ltp.local', 'Refrigerado + certificado en control especial'],
                ['Mensajero',     'marcela.ospina@ltp.local', 'Refrigerado, sin certificación de controlados'],
                ['Mensajero',     'carlos.bedoya@ltp.local', 'Moto: el filtro duro debe descartarlo del frío'],
            ]
        );

        $this->command->newLine();
        $this->command->warn('Credenciales de demostración local. Cámbielas antes de exponer el sistema a la red:');
        $this->command->warn('una clave que contiene el nombre de la empresa es de las primeras que prueba un atacante.');
    }
}
