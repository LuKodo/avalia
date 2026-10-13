<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id();

            // Guía interna LTP: la llave de la recogida y el envío. Es única en
            // todo el sistema y es lo que el cliente usa para consultar y
            // exportar. Formato LTP-AAMMDD-NNNN.
            $table->string('numero_guia', 24)->unique();

            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('sede_origen_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('sede_destino_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('criticidad', 20);                 // Criticidad
            $table->timestamp('ventana_desde')->nullable();
            $table->timestamp('ventana_hasta')->nullable();
            $table->timestamp('comprometida_en')->nullable(); // hora tope pactada

            $table->string('contacto_destino_nombre')->nullable();
            $table->string('contacto_destino_telefono', 30)->nullable();
            $table->text('observaciones')->nullable();

            $table->decimal('tarifa_base', 12, 2)->default(0);
            $table->decimal('recargo', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->string('estado_comercial', 20)->default('abierta'); // abierta|cerrada|facturada|anulada
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cliente_id', 'created_at']);
            $table->index(['estado_comercial', 'comprometida_en']);
        });

        // Referencias definidas POR EL CLIENTE. Una orden puede llevar varias
        // (radicado + orden de compra + remisión) y cualquiera de ellas debe
        // servir para recuperar el envío completo con su soporte de entrega.
        Schema::create('referencias_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('tipo', 30);                       // TipoReferencia
            $table->string('valor', 120);
            // Normalizado en mayúsculas y sin separadores, para que «OC-1234»,
            // «oc 1234» y «OC1234» encuentren el mismo envío.
            $table->string('valor_normalizado', 120);
            $table->timestamps();

            $table->unique(['cliente_id', 'tipo', 'valor_normalizado'], 'referencias_cliente_unica');
            $table->index('valor_normalizado');
            $table->index(['orden_id', 'tipo']);
        });

        Schema::create('paquetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();

            // Código de la etiqueta física que se escanea en origen y destino.
            $table->string('codigo', 32)->unique();
            $table->unsignedSmallInteger('consecutivo')->default(1); // 1 de 3

            $table->string('tipo', 30);                       // TipoPaquete
            $table->string('estado', 30)->default('creado');  // EstadoPaquete
            $table->text('descripcion')->nullable();
            $table->string('lote', 60)->nullable();

            $table->decimal('peso_kg', 8, 3);
            $table->unsignedSmallInteger('largo_cm')->nullable();
            $table->unsignedSmallInteger('ancho_cm')->nullable();
            $table->unsignedSmallInteger('alto_cm')->nullable();

            // Rango térmico POR PAQUETE: no todo producto refrigerado comparte
            // el mismo rango, así que no puede ser una constante del sistema.
            $table->decimal('temp_min', 5, 2)->nullable();
            $table->decimal('temp_max', 5, 2)->nullable();

            $table->foreignId('mensajero_id')->nullable()->constrained('mensajeros')->nullOnDelete();
            $table->timestamp('recolectado_en')->nullable();
            $table->timestamp('entregado_en')->nullable();

            $table->timestamps();

            $table->index(['estado', 'created_at']);
            $table->index(['mensajero_id', 'estado']);
            $table->index('tipo');
        });

        Schema::create('asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();
            $table->foreignId('mensajero_id')->constrained('mensajeros')->restrictOnDelete();
            $table->foreignId('asignada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('origen', 20)->default('automatica'); // automatica|manual
            $table->unsignedTinyInteger('puntaje')->nullable();
            $table->json('desglose_puntaje')->nullable();

            // Toda anulación de la sugerencia exige motivo. Esos motivos son la
            // materia prima para calibrar los pesos del motor de asignación.
            $table->string('motivo_anulacion')->nullable();
            $table->foreignId('sustituye_a')->nullable()->constrained('asignaciones')->nullOnDelete();

            $table->timestamp('asignada_en')->useCurrent();
            $table->timestamp('liberada_en')->nullable();
            $table->timestamps();

            $table->index(['mensajero_id', 'asignada_en']);
            $table->index(['paquete_id', 'liberada_en']);
        });

        Schema::create('evidencias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();
            $table->string('tipo', 30);   // firma|foto_receptor|foto_indicador|foto_empaque|documento|acta
            $table->string('ruta');       // almacenamiento compatible con S3, nunca URL pública
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->string('checksum', 64)->nullable();  // SHA-256 del archivo

            $table->timestamp('capturada_en')->nullable();      // reloj del dispositivo
            $table->timestamp('recibida_en')->useCurrent();     // reloj del servidor
            $table->boolean('sincronizada')->default(true);

            $table->timestamps();
            $table->index(['paquete_id', 'tipo']);
        });

        // Datos del receptor. Categoría sensible: cifrados en columna y con
        // política de retención propia (ver App\Models\Entrega).
        Schema::create('entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();

            $table->text('receptor_nombre');        // cifrado
            $table->text('receptor_documento');     // cifrado
            $table->string('receptor_documento_hash', 64)->index(); // búsqueda sin descifrar
            $table->string('receptor_cargo')->nullable();

            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->unsignedSmallInteger('precision_m')->nullable();
            $table->unsignedSmallInteger('distancia_a_sede_m')->nullable();
            $table->boolean('geolocalizacion_coherente')->default(true);

            $table->timestamp('entregada_en');           // marca válida: servidor
            $table->timestamp('entregada_en_dispositivo')->nullable();
            $table->integer('desfase_reloj_seg')->default(0);
            $table->boolean('dentro_de_ventana')->default(true);

            $table->uuid('firma_id')->nullable();
            $table->uuid('foto_receptor_id')->nullable();
            $table->boolean('consentimiento_datos')->default(false);

            $table->timestamps();
            $table->unique('paquete_id');
        });

        Schema::create('lecturas_temperatura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();
            $table->decimal('valor', 5, 2);
            $table->string('momento', 20);            // recoleccion|transito|entrega
            $table->string('fuente', 20)->default('manual'); // manual|datalogger
            $table->boolean('fuera_de_rango')->default(false);
            $table->uuid('evidencia_id')->nullable();
            $table->timestamp('registrada_en');
            $table->timestamps();

            $table->index(['paquete_id', 'registrada_en']);
            $table->index('fuera_de_rango');
        });

        Schema::create('novedades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();
            $table->foreignId('reportada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 40);               // TipoNovedad
            $table->string('severidad', 20);
            $table->text('descripcion')->nullable();
            $table->boolean('imputable_ltp')->default(false);

            $table->string('estado', 20)->default('abierta'); // abierta|en_gestion|cerrada
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolucion')->nullable();
            $table->timestamp('cerrada_en')->nullable();

            $table->timestamp('reportada_en');
            $table->timestamps();

            $table->index(['estado', 'severidad']);
            $table->index(['tipo', 'reportada_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('novedades');
        Schema::dropIfExists('lecturas_temperatura');
        Schema::dropIfExists('entregas');
        Schema::dropIfExists('evidencias');
        Schema::dropIfExists('asignaciones');
        Schema::dropIfExists('paquetes');
        Schema::dropIfExists('referencias_cliente');
        Schema::dropIfExists('ordenes');
    }
};
