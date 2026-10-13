<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puntos de recogida propuestos por el cliente y aprobados por Avalia.
 *
 * Hasta ahora solo un administrador podía crear sedes, así que un cliente que
 * abría un punto nuevo tenía que pedirlo por teléfono y esperar. Y el campo
 * `coordenada_verificada` existía desde el principio, arrancaba en `false`
 * y NADIE lo aprobaba nunca: estaba escrito el control y no el procedimiento.
 *
 * Ahora el cliente propone con coordenada exacta, y hasta que Avalia no la
 * aprueba ese punto no se puede usar para radicar. Ese doble paso es lo que
 * hace que el destino sea fiable para el trazado de ruta y para el aviso de
 * desvío: una coordenada que nadie miró convierte el mapa en adivinanza.
 *
 * Se guarda en `sedes` y no en una tabla aparte a propósito: un punto pactado
 * ES una sede: lo único que cambia es si ya pasó revisión.
 */
return new class extends Migration
{
    /**
     * Idempotente a proposito.
     *
     * Un primer intento dejo las columnas creadas y fallo al llegar al indice,
     * asi que la migracion no quedo registrada y al repetirla chocaba con sus
     * propias columnas. Una migracion que no se puede reintentar deja la base a
     * medias y obliga a arreglarla a mano, que es justo lo que no se quiere en
     * un servidor de produccion.
     */
    public function up(): void
    {
        $nuevas = [];

        Schema::table('sedes', function (Blueprint $table) use (&$nuevas) {
            /*
             * `coordenada_verificada` es un booleano y no alcanza: «no
             * aprobada» y «rechazada» son cosas distintas. La primera espera;
             * la segunda necesita que el cliente corrija algo y vuelva a
             * mandarla. Con un solo booleano las dos se veían igual y el
             * cliente no sabía si esperar o actuar.
             */
            if (! Schema::hasColumn('sedes', 'estado_aprobacion')) {
                $table->string('estado_aprobacion', 20)->default('aprobada')->after('coordenada_verificada');
            }

            if (! Schema::hasColumn('sedes', 'propuesta_por')) {
                $table->foreignId('propuesta_por')->nullable()->after('estado_aprobacion')
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('sedes', 'revisada_por')) {
                $table->foreignId('revisada_por')->nullable()->after('propuesta_por')
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('sedes', 'revisada_en')) {
                $table->timestamp('revisada_en')->nullable()->after('revisada_por');
            }
            if (! Schema::hasColumn('sedes', 'motivo_revision')) {
                $table->string('motivo_revision', 255)->nullable()->after('revisada_en');
            }

            // Lo que el cliente cuenta de su punto: horarios, a quién
            // preguntar, por dónde se entra. Es lo que evita que el mensajero
            // llegue y se quede en la puerta.
            if (! Schema::hasColumn('sedes', 'contacto_nombre')) {
                $table->string('contacto_nombre', 120)->nullable()->after('motivo_revision');
            }
            if (! Schema::hasColumn('sedes', 'contacto_telefono')) {
                $table->string('contacto_telefono', 40)->nullable()->after('contacto_nombre');
            }


        });

        $yaHay = collect(Schema::getConnection()->select('SHOW INDEX FROM sedes'))
            ->pluck('Key_name')->contains('sedes_estado_aprobacion_cliente_id_index');

        if (! $yaHay) {
            Schema::table('sedes', fn (Blueprint $t) => $t->index(['estado_aprobacion', 'cliente_id']));
        }

        /*
         * Las sedes que ya existían quedan aprobadas.
         *
         * Las creó un administrador, que es justamente la revisión que este
         * procedimiento formaliza. Marcarlas como pendientes rompería la
         * radicación de todos los clientes de golpe.
         */
        Schema::getConnection()->table('sedes')->update([
            'estado_aprobacion' => 'aprobada',
        ]);
    }

    public function down(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->dropForeign(['propuesta_por']);
            $table->dropForeign(['revisada_por']);
            $table->dropIndex(['estado_aprobacion', 'cliente_id']);
            $table->dropColumn([
                'estado_aprobacion', 'propuesta_por', 'revisada_por',
                'revisada_en', 'motivo_revision',
                'contacto_nombre', 'contacto_telefono',
            ]);
        });
    }
};
