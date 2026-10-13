<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El destino de una guía deja de ser obligatoriamente una sede.
 *
 * Hasta aquí `sede_destino_id` era NOT NULL: un envío iba siempre de sede a
 * sede. Eso dejaba fuera el caso más pedido —recoger en la sede del cliente y
 * entregar en casa del afiliado— y no había forma de expresarlo sin falsear el
 * catálogo de sedes.
 *
 * Ahora hay dos columnas y EXACTAMENTE UNA debe estar llena. Se eligió un par
 * de claves foráneas en lugar de una relación polimórfica a propósito: una
 * columna «destino_tipo» con un id suelto habría perdido la integridad
 * referencial justo sobre el dato que define a dónde va un medicamento. Aquí la
 * base sigue garantizando que el destino existe.
 *
 * La regla «exactamente uno» la impone un disparador, no la aplicación: una
 * guía sin destino, o con dos, no es un error de interfaz que se pueda corregir
 * después — es una guía que nadie sabe dónde entregar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $t) {
            $t->foreignId('destinatario_id')
                ->nullable()
                ->after('sede_destino_id')
                ->constrained('destinatarios')
                ->restrictOnDelete();
        });

        // Las guías que ya existen van todas a sede: se quedan como están.
        Schema::table('ordenes', function (Blueprint $t) {
            $t->foreignId('sede_destino_id')->nullable()->change();
        });

        $conexion = Schema::getConnection();

        /*
         * El mensaje del disparador tiene que ser legible en un log a las tres
         * de la mañana: dice qué regla se violó, no un código.
         */
        $mensaje = 'Una orden debe tener exactamente un destino: sede_destino_id o destinatario_id.';

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $sufijo => $momento) {
            $conexion->unprepared("
                DROP TRIGGER IF EXISTS ordenes_destino_unico_{$sufijo}
            ");

            $conexion->unprepared("
                CREATE TRIGGER ordenes_destino_unico_{$sufijo}
                BEFORE {$momento} ON ordenes
                FOR EACH ROW
                BEGIN
                    IF (NEW.sede_destino_id IS NULL AND NEW.destinatario_id IS NULL)
                       OR (NEW.sede_destino_id IS NOT NULL AND NEW.destinatario_id IS NOT NULL) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = '{$mensaje}';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        $conexion = Schema::getConnection();

        foreach (['insert', 'update'] as $sufijo) {
            $conexion->unprepared("DROP TRIGGER IF EXISTS ordenes_destino_unico_{$sufijo}");
        }

        Schema::table('ordenes', function (Blueprint $t) {
            $t->dropForeign(['destinatario_id']);
            $t->dropColumn('destinatario_id');
        });

        // Solo se puede volver a NOT NULL si ninguna guía quedó sin sede.
        if (DB::table('ordenes')->whereNull('sede_destino_id')->doesntExist()) {
            Schema::table('ordenes', function (Blueprint $t) {
                $t->foreignId('sede_destino_id')->nullable(false)->change();
            });
        }
    }
};
