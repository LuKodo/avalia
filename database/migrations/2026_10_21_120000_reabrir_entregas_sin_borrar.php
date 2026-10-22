<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reabrir una entrega sin borrar la anterior.
 *
 * Pasa: se confirma una entrega y después aparece el acta que faltaba, o se
 * descubre que la firma quedó en blanco. Hasta ahora la única salida era
 * borrar y rehacer, y borrar una evidencia es justo lo que esta plataforma no
 * puede permitirse: la bitácora es de solo anexado precisamente para poder
 * afirmarle a un auditor que nada se tocó.
 *
 * La solución es que las entregas dejen de ser una por paquete. La unicidad
 * sobre `paquete_id` obligaba a sobrescribir; ahora puede haber varias y solo
 * una vigente. La anterior queda ahí, marcada como reemplazada, con quién la
 * reabrió y por qué.
 *
 * Nadie pierde nada y el soporte puede contar la historia completa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas', function (Blueprint $t) {
            if (! Schema::hasColumn('entregas', 'vigente')) {
                $t->boolean('vigente')->default(true)->after('consentimiento_datos');
            }

            if (! Schema::hasColumn('entregas', 'reemplaza_a')) {
                $t->unsignedBigInteger('reemplaza_a')->nullable()->after('vigente');
            }

            if (! Schema::hasColumn('entregas', 'reabierta_en')) {
                $t->timestamp('reabierta_en')->nullable()->after('reemplaza_a');
                $t->unsignedBigInteger('reabierta_por')->nullable()->after('reabierta_en');
                $t->text('motivo_reapertura')->nullable()->after('reabierta_por');
            }
        });

        /*
         * El índice único sobre `paquete_id` es lo que impedía todo esto. Se
         * cambia por uno compuesto que sigue sirviendo para la consulta real
         * —«la entrega vigente de este paquete»— sin prohibir el histórico.
         *
         * Se hace a mano porque `dropUnique` falla si el índice no está, y
         * esta migración tiene que poder correr sobre una base donde ya se
         * aplicó a medias.
         */
        $indices = collect(DB::select('SHOW INDEX FROM entregas'))
            ->pluck('Key_name')->unique();

        /*
         * El compuesto se crea ANTES de soltar el único. La clave foránea
         * `paquete_id` se apoya en algún índice que empiece por esa columna, y
         * MySQL se niega a quedarse sin ninguno: soltar primero daba
         * «Cannot drop index: needed in a foreign key constraint». Con el
         * compuesto ya creado, la foránea se apoya en él y el único sobra.
         */
        if (! $indices->contains('entregas_paquete_vigente')) {
            DB::statement('CREATE INDEX entregas_paquete_vigente ON entregas (paquete_id, vigente)');
        }

        if ($indices->contains('entregas_paquete_id_unique')) {
            DB::statement('ALTER TABLE entregas DROP INDEX entregas_paquete_id_unique');
        }
    }

    public function down(): void
    {
        /*
         * La vuelta atrás solo es posible si no hay histórico: restaurar la
         * unicidad con dos entregas del mismo paquete fallaría, y la salida
         * sería borrar una — exactamente lo que esta migración existe para
         * evitar. En ese caso se deja el índice compuesto y se avisa.
         */
        $duplicados = DB::table('entregas')
            ->select('paquete_id')
            ->groupBy('paquete_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        Schema::table('entregas', function (Blueprint $t) {
            $t->dropColumn(['vigente', 'reemplaza_a', 'reabierta_en', 'reabierta_por', 'motivo_reapertura']);
        });

        if ($duplicados === 0) {
            DB::statement('DROP INDEX entregas_paquete_vigente ON entregas');
            DB::statement('CREATE UNIQUE INDEX entregas_paquete_id_unique ON entregas (paquete_id)');
        }
    }
};
