<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deja constancia de cómo se cerró una pausa.
 *
 * Hacía falta porque la pausa va a poder cerrarse sola: si el mensajero
 * registra una entrega con el almuerzo todavía corriendo, el almuerzo terminó
 * —lo diga él o no— y el sistema lo cierra en ese instante.
 *
 * Pero cerrarlo en silencio sería escribir un dato que nadie declaró. Esta
 * marca separa las dos cosas: «la cerró él» y «la cerró el sistema porque
 * volvió a trabajar». La diferencia importa cuando alguien revisa un informe
 * de productividad y pregunta por qué el almuerzo duró doce minutos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pausas_jornada', 'cerrada_automaticamente')) {
            return;
        }

        Schema::table('pausas_jornada', function (Blueprint $t) {
            $t->boolean('cerrada_automaticamente')->default(false)->after('duracion_seg');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pausas_jornada', 'cerrada_automaticamente')) {
            return;
        }

        Schema::table('pausas_jornada', function (Blueprint $t) {
            $t->dropColumn('cerrada_automaticamente');
        });
    }
};
