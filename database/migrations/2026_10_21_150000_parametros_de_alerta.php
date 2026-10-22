<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A quién se avisa de las guías paradas, y a partir de cuántos días.
 *
 * El correo de operación nace **vacío** y eso es deliberado: poner aquí una
 * dirección inventada haría que el aviso se mandara a un buzón que nadie mira,
 * que es peor que no mandarlo —da la sensación de que el control existe—. El
 * comando lo dice claro cuando no está configurado.
 *
 * Admite varias separadas por coma: la alerta la suele querer coordinación y
 * también la persona que despacha, y obligar a reenviar un correo a diario es
 * cómo se deja de reenviar al tercer día.
 *
 * A los clientes no hay que configurarlos: se usa el correo de notificaciones
 * que cada uno ya tiene en su ficha, y a cada uno le llegan solo sus guías.
 */
return new class extends Migration
{
    public function up(): void
    {
        $filas = [
            [
                'clave'       => 'alertas.dias_sin_cerrar',
                'valor'       => '3',
                'tipo'        => 'int',
                'descripcion' => 'Días sin entregarse ni cerrarse a partir de los cuales un envío entra en la alerta diaria.',
            ],
            [
                'clave'       => 'alertas.correo_operacion',
                'valor'       => '',
                'tipo'        => 'string',
                'descripcion' => 'A quién de Avalia se le avisa de los envíos parados. Varias direcciones separadas por coma. '
                    .'Vacío = no se manda el aviso interno (el del cliente sí sale).',
            ],
        ];

        foreach ($filas as $fila) {
            if (DB::table('parametros')->where('clave', $fila['clave'])->exists()) {
                continue;
            }

            DB::table('parametros')->insert($fila + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('parametros')
            ->whereIn('clave', ['alertas.dias_sin_cerrar', 'alertas.correo_operacion'])
            ->delete();
    }
};
