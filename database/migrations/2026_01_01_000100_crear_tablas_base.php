<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Infraestructura de Laravel -------------------------------------
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->unique();
            $table->timestamp('email_verificado_en')->nullable();
            $table->string('password');
            $table->string('documento', 30)->nullable();
            $table->string('telefono', 30)->nullable();

            // Alcance de datos. Un usuario de rol Cliente solo existe dentro de
            // un cliente; el aislamiento se aplica en la capa de consulta, no en
            // la interfaz.
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();

            $table->boolean('activo')->default(true);
            $table->boolean('doble_factor')->default(false);
            $table->string('doble_factor_secreto')->nullable();
            $table->timestamp('ultimo_acceso_en')->nullable();
            $table->string('ultima_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cliente_id', 'activo']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // `personal_access_tokens` la crea Sanctum con su propia migración. Las
        // columnas que LTP necesita de más se agregan aparte, en
        // 2026_01_01_000600, para no competir con el paquete por la misma tabla.
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
