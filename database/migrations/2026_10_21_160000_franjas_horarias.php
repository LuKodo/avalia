<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las franjas en que se parte el día para los informes.
 *
 * Se guardan como los CORTES, no como los nombres: `12:00,14:00` significa
 * «de la entrada a las 12, de 12 a 14, y de 14 a la salida». Guardar los
 * nombres obligaría a mantener dos listas coherentes entre sí —tres nombres
 * para dos cortes— y la primera vez que alguien añadiera una franja sin añadir
 * su nombre, el informe mostraría una columna en blanco.
 *
 * El inicio no es un corte: es la **hora de entrada del mensajero**, que ahora
 * se registra de verdad desde la aplicación de campo. Por eso la primera
 * franja no empieza a una hora fija: empieza cuando esa persona empezó, que
 * es lo que hace comparable a dos mensajeros con turnos distintos.
 *
 * Mediodía a las 12 y tarde a las 14 son el reparto habitual de una operación
 * de mensajería en Colombia. Se cambia en Administración · Parámetros sin
 * tocar el software, que es lo que se pidió.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('parametros')->where('clave', 'jornada.cortes_franja')->exists()) {
            return;
        }

        DB::table('parametros')->insert([
            'clave'       => 'jornada.cortes_franja',
            'valor'       => '12:00,14:00',
            'tipo'        => 'string',
            'descripcion' => 'Horas en que se parte la jornada para los informes, separadas por coma. '
                .'«12:00,14:00» da tres franjas: de la entrada a las 12, de 12 a 14, y de 14 en adelante.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('parametros')->where('clave', 'jornada.cortes_franja')->delete();
    }
};
