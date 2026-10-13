<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que llega desde el sitio público: solicitudes comerciales y PQRS.
 *
 * Son dos tablas y no una con un campo «tipo», porque son dos objetos con vidas
 * distintas. Una solicitud comercial es un interesado que se atiende cuando se
 * pueda; una PQRS tiene número de radicado, término legal de respuesta y
 * consecuencias si se vence. Meterlas juntas obligaría a que la una arrastre las
 * columnas de la otra y a que el término de ley se vigile con un WHERE.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------- solicitudes comerciales
        Schema::create('contactos', function (Blueprint $t) {
            $t->id();

            $t->string('nombre');
            $t->string('institucion')->nullable();
            $t->string('correo');
            $t->string('telefono', 40)->nullable();
            $t->string('servicio', 60)->nullable();
            $t->text('detalle')->nullable();

            // nuevo | contactado | cotizado | ganado | perdido
            $t->string('estado', 20)->default('nuevo');
            $t->foreignId('atendido_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('atendido_en')->nullable();
            $t->text('nota_interna')->nullable();

            /*
             * De dónde vino y desde dónde. Sirve para dos cosas distintas: saber
             * qué sección del sitio trae interesados, y tener con qué defenderse
             * si alguien inunda el formulario.
             */
            $t->string('origen', 40)->default('sitio_web');
            $t->ipAddress('ip')->nullable();
            $t->string('agente', 255)->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['estado', 'created_at']);
            $t->index('correo');
        });

        // ---------------------------------------------------------------- PQRS
        Schema::create('pqrs', function (Blueprint $t) {
            $t->id();

            /*
             * El radicado es lo que el ciudadano cita al reclamar y lo que la
             * autoridad pide en una visita. Único, y se entrega en el acto: una
             * PQRS sin número desde el primer segundo es una PQRS que nadie
             * puede rastrear.
             */
            $t->string('radicado', 24)->unique();

            // peticion | queja | reclamo | sugerencia | datos_personales
            $t->string('tipo', 24);

            $t->string('nombre');
            $t->string('documento', 30)->nullable();
            $t->string('correo');
            $t->string('telefono', 40)->nullable();

            // La guía o la referencia del cliente, si el caso es sobre un envío.
            $t->string('guia_relacionada', 40)->nullable();
            $t->foreignId('orden_id')->nullable()->constrained('ordenes')->nullOnDelete();

            $t->text('descripcion');

            // radicada | en_tramite | respondida | cerrada
            $t->string('estado', 20)->default('radicada');

            /*
             * El término se calcula y se guarda al radicar, no se deduce después.
             * Si mañana cambia la política de días, las PQRS ya radicadas
             * conservan el plazo con el que se recibieron — que es el que se le
             * prometió a la persona.
             */
            $t->date('responder_antes_de');

            $t->foreignId('asignada_a')->nullable()->constrained('users')->nullOnDelete();
            $t->text('respuesta')->nullable();
            $t->timestamp('respondida_en')->nullable();

            $t->ipAddress('ip')->nullable();

            $t->timestamps();
            $t->softDeletes();

            // La consulta de todos los días: qué está por vencerse.
            $t->index(['estado', 'responder_antes_de']);
            $t->index('correo');
        });

        // --------------------------------------------------------- adjuntos PQRS
        Schema::create('pqrs_adjuntos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pqrs_id')->constrained('pqrs')->cascadeOnDelete();

            $t->string('nombre_original');
            $t->string('ruta');
            $t->string('mime', 100);
            $t->unsignedInteger('bytes');

            /*
             * La misma huella que ya llevan las evidencias de entrega. Un adjunto
             * de una queja puede terminar siendo prueba, y entonces importa poder
             * demostrar que es el mismo archivo que se recibió.
             */
            $t->string('checksum', 64);

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pqrs_adjuntos');
        Schema::dropIfExists('pqrs');
        Schema::dropIfExists('contactos');
    }
};
