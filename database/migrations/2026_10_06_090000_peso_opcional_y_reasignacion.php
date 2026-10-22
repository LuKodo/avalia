<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El peso deja de ser obligatorio.
 *
 * Se exigía desde el primer día y la realidad lo desmintió: quien radica diez
 * dispensaciones en una caja no tiene a mano el peso de cada una, y obligarle a
 * inventarlo produce un dato peor que el dato ausente. Un «1 kg» tecleado para
 * salir del paso ensucia la capacidad del mensajero y nadie puede distinguirlo
 * de un peso real.
 *
 * Quedarse en nulo tiene consecuencias que conviene saber, y están resueltas en
 * `Paquete::pesoParaCapacidad()`: un peso desconocido cuenta como cero al
 * calcular la carga del mensajero. Es deliberado. La alternativa —bloquear el
 * despacho hasta que alguien lo sepa— castiga al cliente por no saber algo que
 * casi nunca decide nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes', function (Blueprint $t) {
            $t->decimal('peso_kg', 8, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Volver atrás exige que no haya nulos, o el motor rechaza la columna.
         * Se ponen a cero antes de estrechar: cero es falso, pero es lo que ya
         * asumía el cálculo de capacidad, así que no cambia ningún resultado.
         */
        \DB::table('paquetes')->whereNull('peso_kg')->update(['peso_kg' => 0]);

        Schema::table('paquetes', function (Blueprint $t) {
            $t->decimal('peso_kg', 8, 3)->nullable(false)->change();
        });
    }
};
