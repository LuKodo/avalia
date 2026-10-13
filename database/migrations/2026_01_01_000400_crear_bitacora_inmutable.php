<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora inmutable de eventos.
 *
 * «Inmutable» en una base relacional no es una propiedad mágica: se construye
 * con tres capas que se refuerzan entre sí.
 *
 *   1. Solo anexado — se aplica EN LA BASE DE DATOS, no en la aplicación. Un
 *      error en el código no puede reescribir historia.
 *   2. Encadenamiento criptográfico — hash = SHA256(hash_anterior + carga
 *      canónica). Alterar un registro invalida todos los posteriores.
 *   3. Sellado externo diario — el hash raíz del día se firma y se exporta
 *      fuera del servidor, de modo que ni un administrador de la base puede
 *      reescribir la cadena completa sin que se note.
 *
 * La cadena es GLOBAL, no por paquete. Con ~18.000 eventos diarios (0,2/s) el
 * costo de serializar las inserciones es irrelevante frente a lo que se gana:
 * una sola cadena que verificar y una manipulación mucho más difícil de ocultar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_paquete', function (Blueprint $table) {
            $table->uuid('id')->primary();                    // UUID v7 generado en el dispositivo
            $table->unsignedBigInteger('secuencia')->unique(); // posición en la cadena global

            $table->foreignId('paquete_id')->constrained('paquetes')->restrictOnDelete();
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30);
            $table->string('tipo_evento', 40);                // transicion|lectura|novedad|acceso|sistema

            $table->string('actor_tipo', 20);                 // usuario|sistema|integracion
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_nombre')->nullable();       // congelado: el nombre de hoy, no el de mañana

            // Dos marcas de tiempo SIEMPRE. El reloj del teléfono se puede
            // cambiar; para efectos legales y de auditoría la marca válida es la
            // del servidor, y el desfase queda registrado para alertar anomalías.
            $table->timestamp('ocurrido_en_dispositivo')->nullable();
            $table->timestamp('registrado_en')->useCurrent();
            $table->integer('desfase_seg')->default(0);

            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->unsignedSmallInteger('precision_m')->nullable();

            $table->json('datos')->nullable();                // carga específica del tipo de evento
            $table->uuid('evidencia_id')->nullable();

            $table->string('hash_anterior', 64)->nullable();
            $table->string('hash', 64);

            $table->string('ip', 45)->nullable();
            $table->string('dispositivo', 120)->nullable();

            $table->index(['paquete_id', 'secuencia']);
            $table->index(['tipo_evento', 'registrado_en']);
            $table->index('registrado_en');
        });

        // Contador de la cadena. Una sola fila, bloqueada con SELECT ... FOR
        // UPDATE al registrar un evento: garantiza secuencia y hash_anterior
        // consistentes aun con inserciones concurrentes.
        Schema::create('cadena_estado', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ultima_secuencia')->default(0);
            $table->string('ultimo_hash', 64)->nullable();
            $table->timestamp('actualizado_en')->nullable();
        });

        Schema::getConnection()->table('cadena_estado')->insert([
            'ultima_secuencia' => 0,
            'ultimo_hash'      => null,
            'actualizado_en'   => now(),
        ]);

        // Sello diario: hash raíz de la jornada, firmado y exportado fuera de
        // sede. Es lo que responde a «¿y si alguien con acceso de administrador
        // reescribió la cadena entera?».
        Schema::create('sellos_diarios', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->unsignedBigInteger('secuencia_desde');
            $table->unsignedBigInteger('secuencia_hasta');
            $table->unsignedInteger('total_eventos');
            $table->string('hash_raiz', 64);
            $table->text('firma')->nullable();
            $table->boolean('exportado')->default(false);
            $table->timestamp('exportado_en')->nullable();
            $table->timestamps();
        });

        // Auditoría de acceso a datos sensibles (documento del receptor, fotos,
        // exportaciones). Separada de la bitácora de eventos: distinto propósito
        // y distinta política de retención.
        Schema::create('auditoria_accesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 60);                 // ver_documento|exportar|descargar_evidencia
            $table->string('recurso_tipo', 60);
            $table->unsignedBigInteger('recurso_id')->nullable();
            $table->string('justificacion')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('agente')->nullable();
            $table->timestamp('ocurrido_en')->useCurrent();

            $table->index(['user_id', 'ocurrido_en']);
            $table->index(['recurso_tipo', 'recurso_id']);
        });

        $this->protegerSoloAnexado();
    }

    /**
     * Capa 1: la tabla no admite UPDATE ni DELETE. Se aplica con disparadores en
     * la base de datos. Complementariamente, en producción el rol que usa la
     * aplicación recibe únicamente INSERT y SELECT sobre esta tabla
     * (ver scripts/permisos_bitacora.sql).
     */
    private function protegerSoloAnexado(): void
    {
        // La conexión de la migración, no la de la aplicación. Con usuarios
        // separados —la aplicación sin permiso de TRIGGER, como debe ser— usar
        // la conexión por defecto haría fallar la creación del disparador.
        $conexion = Schema::getConnection();
        $driver   = $conexion->getDriverName();

        if ($driver === 'pgsql') {
            $conexion->unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION ltp_bloquear_modificacion()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'La bitacora de eventos es de solo anexado: % no esta permitido', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER eventos_paquete_sin_update
                    BEFORE UPDATE ON eventos_paquete
                    FOR EACH ROW EXECUTE FUNCTION ltp_bloquear_modificacion();

                CREATE TRIGGER eventos_paquete_sin_delete
                    BEFORE DELETE ON eventos_paquete
                    FOR EACH ROW EXECUTE FUNCTION ltp_bloquear_modificacion();
            SQL);
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $conexion->unprepared(<<<'SQL'
                CREATE TRIGGER eventos_paquete_sin_update
                    BEFORE UPDATE ON eventos_paquete
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'La bitacora de eventos es de solo anexado: UPDATE no esta permitido';
            SQL);

            $conexion->unprepared(<<<'SQL'
                CREATE TRIGGER eventos_paquete_sin_delete
                    BEFORE DELETE ON eventos_paquete
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'La bitacora de eventos es de solo anexado: DELETE no esta permitido';
            SQL);
        }

        // SQLite (pruebas) no aplica la protección: los tests verifican la regla
        // a nivel de servicio, y el disparador se prueba en el entorno real.
    }

    public function down(): void
    {
        $conexion = Schema::getConnection();
        $driver   = $conexion->getDriverName();

        if ($driver === 'pgsql') {
            $conexion->unprepared('DROP TRIGGER IF EXISTS eventos_paquete_sin_update ON eventos_paquete');
            $conexion->unprepared('DROP TRIGGER IF EXISTS eventos_paquete_sin_delete ON eventos_paquete');
            $conexion->unprepared('DROP FUNCTION IF EXISTS ltp_bloquear_modificacion()');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $conexion->unprepared('DROP TRIGGER IF EXISTS eventos_paquete_sin_update');
            $conexion->unprepared('DROP TRIGGER IF EXISTS eventos_paquete_sin_delete');
        }

        Schema::dropIfExists('auditoria_accesos');
        Schema::dropIfExists('sellos_diarios');
        Schema::dropIfExists('cadena_estado');
        Schema::dropIfExists('eventos_paquete');
    }
};
