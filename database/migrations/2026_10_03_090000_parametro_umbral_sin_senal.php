<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El umbral de señal GPS pasa a ser un parámetro del panel.
 *
 * Estaba fijo en 15 minutos dentro del motor de asignación. Eso deja la bandeja
 * entera sin candidatos en cuanto nadie lleva la aplicación de campo abierta
 * —un entorno de pruebas, un festivo, o el tiempo que el sitio tarde en tener
 * HTTPS, que es lo que el navegador exige para entregar la ubicación—.
 *
 * Subirlo o bajarlo es una decisión de operación: quien la toma es quien
 * responde por los envíos, no quien despliega el código.
 */
return new class extends Migration
{
    private const CLAVE = 'asignacion.sin_senal_min';

    public function up(): void
    {
        // Sin pisar el valor si alguien ya lo ajustó: una reinstalación no
        // debería devolver un parámetro de operación a su valor de fábrica.
        if (DB::table('parametros')->where('clave', self::CLAVE)->exists()) {
            return;
        }

        DB::table('parametros')->insert([
            'clave'       => self::CLAVE,
            'valor'       => '15',
            'tipo'        => 'int',
            'descripcion' => 'Minutos sin reporte GPS tras los cuales un mensajero deja de recibir carga nueva. '
                .'0 desactiva la exigencia: úselo solo en pruebas, porque significa asignar a quien no se puede seguir.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('parametros')->where('clave', self::CLAVE)->delete();
    }
};
