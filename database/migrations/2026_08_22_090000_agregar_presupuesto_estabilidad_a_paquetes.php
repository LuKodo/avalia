<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuesto de estabilidad declarado por el cliente.
 *
 * Cuántos minutos fuera de rango tolera ESTE producto acumulados en toda la
 * cadena. Es dato del estudio de estabilidad del fabricante y varía por
 * molécula: una insulina y un reactivo de diagnóstico no aguantan lo mismo.
 * Sin la columna, el análisis térmico tendría que aplicarle a todo el mismo
 * número de configuración, que es precisamente el atajo que vuelve inútil el
 * indicador — o manda a cuarentena producto sano, o deja pasar producto
 * comprometido.
 *
 * Nulo significa «el cliente no lo declaró»; en ese caso se usa el valor por
 * defecto y el dictamen lo advierte, para que nadie confunda un supuesto
 * nuestro con un dato del laboratorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes', function (Blueprint $tabla) {
            $tabla->unsignedSmallInteger('presupuesto_estabilidad_min')
                ->nullable()
                ->after('temp_max');
        });
    }

    public function down(): void
    {
        Schema::table('paquetes', function (Blueprint $tabla) {
            $tabla->dropColumn('presupuesto_estabilidad_min');
        });
    }
};
