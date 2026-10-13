<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();
            $table->string('placa', 10)->unique();
            $table->string('tipo', 30);                       // TipoVehiculo
            $table->boolean('refrigeracion')->default(false);
            $table->decimal('capacidad_kg', 8, 2);
            $table->decimal('capacidad_m3', 8, 3)->nullable();

            // Documentos con vencimiento. Un vehículo con SOAT vencido no puede
            // recibir asignación: el filtro duro lo excluye.
            $table->date('soat_vence')->nullable();
            $table->date('tecnomecanica_vence')->nullable();
            $table->date('certificado_frio_vence')->nullable();

            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tipo', 'activo']);
            $table->index(['refrigeracion', 'activo']);
        });

        Schema::create('mensajeros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->nullOnDelete();

            $table->string('codigo', 20)->unique();
            $table->boolean('certificado_controlados')->default(false);
            $table->date('certificado_vence')->nullable();
            $table->json('zonas_habilitadas')->nullable();    // [1,2,5]

            // Estado operativo en vivo.
            $table->boolean('en_turno')->default(false);
            $table->timestamp('turno_inicio')->nullable();
            $table->decimal('ultima_latitud', 10, 7)->nullable();
            $table->decimal('ultima_longitud', 10, 7)->nullable();
            $table->timestamp('ultima_posicion_en')->nullable();
            $table->unsignedTinyInteger('bateria')->nullable();
            $table->decimal('carga_actual_kg', 8, 2)->default(0);

            // Desempeño acumulado: alimenta el 5 % del puntaje de asignación.
            $table->decimal('indice_cumplimiento', 5, 2)->default(100);
            $table->unsignedInteger('entregas_totales')->default(0);
            $table->unsignedInteger('novedades_totales')->default(0);

            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['en_turno', 'activo']);
            $table->index(['ultima_latitud', 'ultima_longitud']);
        });

        // Rastro de posiciones. Se particiona por mes en producción: es la tabla
        // que más crece y casi nunca se consulta más allá de la jornada en curso.
        Schema::create('posiciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mensajero_id')->constrained('mensajeros')->cascadeOnDelete();
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->unsignedSmallInteger('precision_m')->nullable();
            $table->unsignedSmallInteger('velocidad_kmh')->nullable();
            $table->timestamp('registrado_en');
            $table->timestamp('recibido_en')->useCurrent();

            $table->index(['mensajero_id', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posiciones');
        Schema::dropIfExists('mensajeros');
        Schema::dropIfExists('vehiculos');
    }
};
