<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de uso de la API de integración.
 *
 * Sanctum registra cuándo se usó un token por última vez, pero no desde dónde
 * ni cuántas veces. Para una API que expone datos de salud, saber que un token
 * empezó a llamarse desde una IP nueva —o que multiplicó su volumen— es la
 * señal más temprana de que algo se salió de lo previsto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ultima_ip', 45)->nullable()->after('last_used_at');
            $table->unsignedBigInteger('llamadas')->default(0)->after('ultima_ip');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ultima_ip', 'llamadas']);
        });
    }
};
