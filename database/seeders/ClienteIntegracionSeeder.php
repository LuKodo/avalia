<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Distribuciones Pharmaser LTDA: el primer cliente con integración.
 *
 * Es el único. Todos los demás quedan en `integracion_activa = false`, que es
 * el valor por omisión de la columna: la integración se activa a mano, cliente
 * por cliente, nunca por arrastre.
 *
 * El token NO se escribe aquí. Es la credencial de un sistema ajeno y este
 * archivo vive en el repositorio; se toma del entorno si está, y si no se deja
 * vacío para capturarlo desde la pantalla de administración. Un secreto
 * versionado es un secreto quemado.
 */
class ClienteIntegracionSeeder extends Seeder
{
    public function run(): void
    {
        $cliente = Cliente::updateOrCreate(
            ['nit' => '806011811'],
            [
                'codigo'                  => 'DPH-003',
                'razon_social'            => 'Distribuciones Pharmaser LTDA',
                'email_notificaciones'    => 'portoperezcarlos1101@hotmail.com',
                'telefono'                => '3006543210',
                'etiqueta_referencia'     => 'Referencia',
                'tipo_referencia_defecto' => 'dispensacion',
                'referencia_obligatoria'  => true,
                'activo'                  => true,

                'integracion_activa' => true,
                'integracion_tipo'   => 'cassandra',
                'integracion_config' => [
                    'api_url' => 'https://cassandra.pharmaser.com.co/api/v1',
                    'bucket'  => 'bucket_cpscan',

                    /*
                     * Lo que se recorta del principio de la referencia para
                     * formar el nombre del archivo: `SSC6564684` viaja como
                     * `6564684`. Es la convención de ESTE cliente; por eso vive
                     * en su configuración y no en el código.
                     */
                    'prefijo_referencia' => 'SSC',
                ],
            ]
        );

        /*
         * El token solo se toca si viene del entorno. Así una siembra repetida
         * no borra el que ya se capturó por pantalla, que es justo lo que
         * pasaría con un `updateOrCreate` que lo incluyera siempre.
         */
        if ($token = env('CASSANDRA_TOKEN')) {
            $cliente->forceFill(['integracion_token' => $token])->save();
            $this->command?->info('Token de Cassandra tomado del entorno.');
        } elseif (! $cliente->integracion_token) {
            $this->command?->warn(
                'Distribuciones Pharmaser quedó con integración activa y SIN token. '
                .'Captúrelo desde Administración → Clientes antes de la primera entrega.'
            );
        }

        Sede::updateOrCreate(
            ['cliente_id' => $cliente->id, 'nombre' => 'Bodega Bloc Port'],
            [
                // El codigo con el que Pharmaser identifica esta bodega en su
                // contabilidad. Viaja con cada soporte que se le envía.
                'codigo_centro_costo' => '002',

                'tipo'      => 'bodega',
                'direccion' => 'Bloc Port Centro Empresarial y Logístico',
                'ciudad'    => 'Cartagena',

                /*
                 * Coordenadas del LUGAR, no del encuadre del mapa.
                 *
                 * En un enlace de Google Maps conviven dos pares: el que sigue
                 * a la `@` es el centro de la vista, y el del tramo `!3d!4d` es
                 * el sitio marcado. Aquí se difieren en unos 280 metros, y con
                 * el radio de validación de entrega en 150 toda entrega hecha
                 * en esta bodega habría salido marcada como incoherente.
                 */
                'latitud'  => 10.3761883,
                'longitud' => -75.5037653,
                'coordenada_verificada' => true,

                'contacto_nombre'   => 'Carlos Baldovino',
                'contacto_telefono' => '3006543210',
                'activo' => true,
            ]
        );

        /*
         * La cuenta con la que entra el cliente al portal.
         *
         * Sin ella el cliente existe en la base pero nadie puede verlo desde
         * dentro: ni radicar, ni consultar sus envíos, ni descargar un soporte.
         * Se creó el cliente y se olvidó la puerta.
         *
         * El correo es el mismo de las notificaciones a propósito: en pruebas
         * conviene que el aviso llegue a la misma bandeja desde la que se entra.
         */
        $usuario = User::firstOrCreate(
            ['email' => 'portoperezcarlos1101@hotmail.com'],
            [
                'nombre'     => 'Carlos Baldovino',
                'password'   => Hash::make('Avalia*2026'),
                'cliente_id' => $cliente->id,
                'telefono'   => '3006543210',
            ]
        );

        $usuario->forceFill([
            'password'   => Hash::make('Avalia*2026'),
            'cliente_id' => $cliente->id,
            'activo'     => true,
        ])->save();

        if (! $usuario->hasRole('cliente')) {
            $usuario->assignRole('cliente');
        }

        $this->command?->info('Distribuciones Pharmaser LTDA, su bodega Bloc Port y la cuenta de Carlos Baldovino, listas.');
    }
}
