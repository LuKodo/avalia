<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos de telemetría (Tempubi y equivalentes).
 *
 * El dispositivo mide EL VEHÍCULO, no el paquete. Esa distinción gobierna todo
 * el módulo: una sonda en la cava reporta la temperatura del compartimento, y
 * los paquetes que van dentro heredan esa lectura mientras estén bajo custodia
 * de ese vehículo. Modelarlo al revés —una sonda por paquete— obligaría a un
 * dispositivo por caja, que no es como opera nadie.
 *
 * De ahí se sigue lo importante: para saber a qué envíos afecta una excursión
 * térmica hay que cruzar la hora de la lectura con qué paquetes iban en ese
 * vehículo en ese momento. Por eso la telemetría guarda su propia marca de
 * tiempo y no se resuelve «en vivo».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos', function (Blueprint $table) {
            $table->id();

            $table->string('serial', 64)->unique();
            $table->string('proveedor', 30)->default('tempubi');  // tempubi|generico
            $table->string('modelo', 60)->nullable();
            $table->string('alias', 80)->nullable();              // «Cava camioneta 1»

            // Un dispositivo vive instalado en un vehículo. Puede reasignarse,
            // y el histórico de esas reasignaciones queda en `instalaciones`.
            $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->nullOnDelete();

            $table->boolean('mide_temperatura')->default(true);
            $table->boolean('mide_ubicacion')->default(true);

            // Rango que vigila la sonda. Es del COMPARTIMENTO, no del producto:
            // el rango exigido por cada paquete vive en `paquetes`.
            $table->decimal('rango_min', 5, 2)->nullable();
            $table->decimal('rango_max', 5, 2)->nullable();

            // Cada dispositivo firma su ingesta con su propio secreto: si uno se
            // ve comprometido, se rota ese y no los de toda la flota.
            $table->text('secreto_ingesta')->nullable();

            $table->unsignedSmallInteger('intervalo_seg')->default(60);
            $table->boolean('activo')->default(true);

            $table->timestamp('ultima_lectura_en')->nullable();
            $table->decimal('ultima_temperatura', 5, 2)->nullable();
            $table->decimal('ultima_latitud', 10, 7)->nullable();
            $table->decimal('ultima_longitud', 10, 7)->nullable();
            $table->unsignedTinyInteger('ultima_bateria')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['proveedor', 'activo']);
            $table->index('vehiculo_id');
        });

        // Histórico de instalaciones. Sin esto no se puede responder «¿qué
        // sonda vigilaba ese vehículo el 8 de julio?», que es exactamente lo
        // que pregunta una auditoría de cadena de frío.
        Schema::create('instalaciones_dispositivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispositivo_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->foreignId('vehiculo_id')->constrained('vehiculos')->cascadeOnDelete();
            $table->foreignId('instalada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('desde');
            $table->timestamp('hasta')->nullable();
            $table->string('motivo_retiro')->nullable();
            $table->timestamps();

            $table->index(['vehiculo_id', 'desde']);
            $table->index(['dispositivo_id', 'hasta']);
        });

        Schema::create('telemetria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispositivo_id')->constrained('dispositivos')->cascadeOnDelete();

            $table->decimal('temperatura', 5, 2)->nullable();
            $table->decimal('humedad', 5, 2)->nullable();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->unsignedSmallInteger('precision_m')->nullable();
            $table->unsignedSmallInteger('velocidad_kmh')->nullable();
            $table->unsignedTinyInteger('bateria')->nullable();

            // Dos marcas de tiempo, igual que en la bitácora: la del aparato y
            // la del servidor. Un dispositivo sin cobertura acumula y descarga
            // en lote, y sin ambas no se distingue una lectura vieja recién
            // recibida de una lectura nueva.
            $table->timestamp('medido_en');
            $table->timestamp('recibido_en')->useCurrent();

            $table->boolean('fuera_de_rango')->default(false);
            $table->json('crudo')->nullable();   // la carga original del proveedor

            $table->index(['dispositivo_id', 'medido_en']);
            $table->index('fuera_de_rango');
            $table->index('medido_en');
        });

        // Excursiones térmicas: no cada lectura fuera de rango, sino el EPISODIO
        // completo con su duración. Diez lecturas seguidas a 9 °C son un solo
        // problema de veinte minutos, no diez problemas.
        Schema::create('excursiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispositivo_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->nullOnDelete();

            $table->timestamp('inicio');
            $table->timestamp('fin')->nullable();
            $table->unsignedInteger('duracion_seg')->nullable();

            $table->decimal('valor_min', 5, 2)->nullable();
            $table->decimal('valor_max', 5, 2)->nullable();
            $table->unsignedSmallInteger('lecturas')->default(1);

            // Paquetes que iban a bordo durante el episodio. Se congela al
            // cerrarlo: reconstruirlo después exigiría rehacer el cruce, y para
            // entonces los paquetes ya se entregaron.
            $table->json('paquetes_afectados')->nullable();

            $table->string('estado', 20)->default('abierta');  // abierta|cerrada|descartada
            $table->foreignId('atendida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolucion')->nullable();
            $table->timestamp('atendida_en')->nullable();

            $table->timestamps();

            $table->index(['estado', 'inicio']);
            $table->index(['vehiculo_id', 'inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excursiones');
        Schema::dropIfExists('telemetria');
        Schema::dropIfExists('instalaciones_dispositivo');
        Schema::dropIfExists('dispositivos');
    }
};
