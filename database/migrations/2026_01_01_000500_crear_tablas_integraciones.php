<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integraciones con los sistemas del cliente.
 *
 * El cliente no debería tener que entrar al portal para saber dónde va su envío.
 * Estas tablas soportan cuatro caminos, en orden de esfuerzo creciente para el
 * cliente:
 *
 *   1. Enlace público de rastreo — un URL firmado que se reenvía por correo o
 *      WhatsApp; sin credenciales, sin instalar nada.
 *   2. Consulta por API — por guía LTP o por la referencia que el cliente definió.
 *   3. Webhooks — LTP avisa al ERP del cliente en cada cambio de estado, sin que
 *      nadie tenga que preguntar.
 *   4. Radicación masiva — el cliente carga un lote de envíos por archivo o API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->string('url', 500);
            $table->text('secreto');                        // cifrado; firma HMAC del cuerpo
            $table->json('eventos');                        // ['paquete.entregado','paquete.novedad']
            $table->boolean('activo')->default(true);

            $table->unsignedSmallInteger('fallos_consecutivos')->default(0);
            $table->timestamp('desactivado_en')->nullable();
            $table->timestamp('ultimo_exito_en')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'activo']);
        });

        Schema::create('entregas_webhook', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained('webhooks_cliente')->cascadeOnDelete();
            $table->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();
            $table->string('evento', 60);
            $table->json('payload');

            $table->string('estado', 20)->default('pendiente'); // pendiente|entregado|fallido|descartado
            $table->unsignedTinyInteger('intento')->default(0);
            $table->unsignedSmallInteger('respuesta_codigo')->nullable();
            $table->text('respuesta_cuerpo')->nullable();
            $table->timestamp('proximo_intento_en')->nullable();
            $table->timestamp('entregado_en')->nullable();
            $table->timestamps();

            $table->index(['estado', 'proximo_intento_en']);
            $table->index(['webhook_id', 'created_at']);
        });

        // Enlaces públicos de rastreo. Se guardan para poder revocarlos: un URL
        // firmado sin registro no se puede invalidar antes de su vencimiento.
        Schema::create('enlaces_rastreo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('orden_id')->constrained('ordenes')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expira_en');
            $table->timestamp('revocado_en')->nullable();
            $table->unsignedInteger('visitas')->default(0);
            $table->timestamp('ultima_visita_en')->nullable();
            $table->timestamps();

            $table->index(['orden_id', 'revocado_en']);
        });

        // Radicación masiva: el cliente sube un archivo o llama a la API en lote.
        // Se procesa de forma asíncrona y con reporte fila por fila, porque en un
        // lote de 200 envíos siempre hay tres con la sede mal escrita.
        Schema::create('cargas_masivas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('subida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origen', 20)->default('archivo'); // archivo|api|sftp
            $table->string('nombre_archivo')->nullable();
            $table->string('estado', 20)->default('recibida'); // recibida|procesando|completada|fallida

            $table->unsignedInteger('filas_totales')->default(0);
            $table->unsignedInteger('filas_ok')->default(0);
            $table->unsignedInteger('filas_error')->default(0);
            $table->json('errores')->nullable();              // [{fila:12, campo:'sede_destino', mensaje:'...'}]

            $table->timestamp('procesada_en')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'created_at']);
        });

        // Registro de exportaciones. Toda descarga de datos con información del
        // receptor deja rastro: es un acceso a datos personales sensibles.
        Schema::create('exportaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 30);                       // orden|lote|periodo
            $table->string('formato', 10);                    // json|csv|pdf|zip
            $table->json('criterio');                         // qué se pidió exactamente
            $table->unsignedInteger('registros')->default(0);
            $table->boolean('incluye_soporte')->default(false);
            $table->boolean('incluye_datos_receptor')->default(false);
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'created_at']);
        });

        // Parámetros ajustables sin desplegar código: pesos del motor de
        // asignación, umbrales de alerta, tolerancias.
        Schema::create('parametros', function (Blueprint $table) {
            $table->string('clave', 80)->primary();
            $table->text('valor');
            $table->string('tipo', 20)->default('string');    // string|int|float|bool|json
            $table->string('descripcion')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros');
        Schema::dropIfExists('exportaciones');
        Schema::dropIfExists('cargas_masivas');
        Schema::dropIfExists('enlaces_rastreo');
        Schema::dropIfExists('entregas_webhook');
        Schema::dropIfExists('webhooks_cliente');
    }
};
