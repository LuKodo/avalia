<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // El orden importa: los usuarios y los clientes existen antes de que
        // DemoSeeder genere operación sobre ellos. Así se puede volver a
        // generar la demostración sin perder las cuentas de prueba.
        // El orden importa: los dispositivos se instalan sobre vehículos que ya
        // existen, y su telemetría cruza contra paquetes que ya están en ruta.
        $this->call([
            ParametrosSeeder::class,
            UsuariosSeeder::class,
            DemoSeeder::class,
            DispositivosSeeder::class,

            // Va al final a propósito: es el único cliente con integración y
            // conviene que el aviso sobre su credencial pendiente quede como
            // última línea de la siembra, donde se lee.
            ClienteIntegracionSeeder::class,
        ]);
    }
}
