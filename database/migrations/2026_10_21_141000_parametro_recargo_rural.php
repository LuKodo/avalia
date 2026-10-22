<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El número del recargo rural, para que se pueda fijar sin tocar el software.
 *
 * Nace en cero y eso es deliberado: hasta que alguien de la parte comercial
 * decida cuánto más cuesta un corregimiento, un destino rural cuesta lo mismo
 * que el resto. Poner un número inventado aquí sería facturar de más desde el
 * día del despliegue y que nadie supiera de dónde salió.
 *
 * Es una fracción sobre la tarifa base, igual que la urgencia y el empaque:
 * 0,35 son treinta y cinco por ciento. Se cambia en Administración ·
 * Parámetros.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('parametros')->where('clave', 'tarifa.recargo_rural')->exists();

        if ($existe) {
            return;
        }

        DB::table('parametros')->insert([
            'clave'       => 'tarifa.recargo_rural',
            'valor'       => '0',
            'tipo'        => 'float',
            'descripcion' => 'Recargo sobre la tarifa base para destinos rurales '
                .'(fracción: 0,35 = 35 %). En cero no se cobra nada.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('parametros')->where('clave', 'tarifa.recargo_rural')->delete();
    }
};
