<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desde qué año transporta Avalia producto farmacéutico.
 *
 * Lo demás que sale en la portada lo calcula la plataforma, pero esto no lo
 * puede saber: la base sabe cuándo se empezó a usar el software, que es
 * bastante después de cuando empezó a rodar el primer vehículo.
 *
 * Nace vacío y mientras lo esté, esa cifra **no se publica**. Es la única
 * manera de que la portada no pueda decir una antigüedad que nadie confirmó,
 * que en una licitación es exactamente la clase de dato que alguien verifica.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('parametros')->where('clave', 'sitio.operacion_desde')->exists()) {
            return;
        }

        DB::table('parametros')->insert([
            'clave'       => 'sitio.operacion_desde',
            'valor'       => '0',
            'tipo'        => 'int',
            'descripcion' => 'Año en que Avalia empezó a transportar producto farmacéutico. '
                .'En cero, la portada no publica la antigüedad.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('parametros')->where('clave', 'sitio.operacion_desde')->delete();
    }
};
