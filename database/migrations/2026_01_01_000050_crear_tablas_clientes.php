<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();          // PHR-001
            $table->string('razon_social');
            $table->string('nit', 30)->unique();
            $table->string('email_notificaciones')->nullable();
            $table->string('telefono', 30)->nullable();

            // Cómo llama el cliente a SU referencia. Se muestra literalmente en
            // el portal y en el acta: si el hospital dice «radicado», el sistema
            // dice «radicado», no «referencia externa».
            $table->string('etiqueta_referencia', 60)->default('Referencia');
            $table->string('tipo_referencia_defecto', 30)->default('radicado');
            $table->boolean('referencia_obligatoria')->default(false);

            $table->json('condiciones_comerciales')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('zonas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80);
            $table->string('ciudad', 80);
            // Polígono en GeoJSON. Sobre PostgreSQL con PostGIS se puede añadir
            // después una columna geography y un índice GiST; el punto-en-polígono
            // del MVP se resuelve en PHP sobre un conjunto pequeño de zonas.
            $table->json('poligono')->nullable();
            $table->decimal('tarifa_base', 12, 2)->default(0);
            $table->decimal('tarifa_refrigerado', 12, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['ciudad', 'activo']);
        });

        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('zona_id')->nullable()->constrained('zonas')->nullOnDelete();

            $table->string('nombre');
            $table->string('tipo', 30)->default('hospital'); // hospital|clinica|farmacia|bodega
            $table->string('direccion');
            $table->string('detalle_acceso')->nullable();    // «Muelle 2», «Piso 3, portería»
            $table->string('ciudad', 80);

            // Coordenada VERIFICADA en campo. Las direcciones de sedes de salud
            // rara vez geocodifican bien: el catálogo curado evita el reintento
            // de geocoding en cada orden y las entregas en la cuadra equivocada.
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->boolean('coordenada_verificada')->default(false);

            $table->json('ventanas_recepcion')->nullable();  // [{dia:1,desde:"08:00",hasta:"17:00"}]
            $table->string('contacto_nombre')->nullable();
            $table->string('contacto_telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cliente_id', 'activo']);
            $table->index(['latitud', 'longitud']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sedes');
        Schema::dropIfExists('zonas');
        Schema::dropIfExists('clientes');
    }
};
