<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El centro de costo de cada sede.
 *
 * Es un código del sistema del CLIENTE, no nuestro: así identifica él sus
 * puntos en su contabilidad. Vive en la sede y no en el cliente porque cambia
 * entre sedes —ese es justamente su propósito— y viaja en la integración junto
 * al soporte, para que al otro lado sepan a qué centro imputar el envío.
 *
 * Se deja como texto y no como número: hoy es `002`, con ceros a la izquierda
 * que un entero se comería, y mañana puede ser `BQ-02`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $t) {
            $t->string('codigo_centro_costo', 30)->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('sedes', function (Blueprint $t) {
            $t->dropColumn('codigo_centro_costo');
        });
    }
};
