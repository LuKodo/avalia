<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los trayectos de un paquete.
 *
 * Hasta ahora un paquete tenía UN origen, UN destino y UN mensajero, y su
 * estado saltaba de «recolectado» a «entregado». Eso no describe la operación
 * real: casi siempre se recoge en la sede del cliente, se lleva al centro de
 * Avalia, y de ahí sale otro día —o con otro vehículo, o con otro mensajero—
 * hacia el destino final. Sin tramos no había forma de decir quién tenía el
 * paquete en cada momento, ni de cambiar de vehículo a mitad de camino.
 *
 * Un paquete tiene uno o dos tramos, y el segundo puede nacer más tarde:
 *
 *   · DIRECTO        un tramo:  origen → destino final
 *   · CON TRASBORDO  dos:       origen → centro Avalia → destino final
 *
 * El estado del PAQUETE sigue siendo la verdad de cara al cliente; el del
 * tramo dice qué toca hacer ahora y quién lo tiene. Se mantienen los dos
 * porque responden preguntas distintas: el cliente pregunta «¿llegó?» y la
 * operación pregunta «¿quién lo lleva y en qué punto va?».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('paquete_id')->constrained('paquetes')->cascadeOnDelete();

            // 1, 2, … El orden en que se recorren. No es el identificador: un
            // tramo puede nacer después de que el primero ya esté cerrado.
            $table->unsignedTinyInteger('orden');

            /*
             * Qué clase de trayecto es. Cambia lo que se le exige al mensajero
             * al cerrarlo:
             *
             *   recoleccion  del cliente hacia nosotros — firma de quien ENTREGA
             *   traslado     entre sedes nuestras — sin firma de tercero
             *   entrega      hacia el destino final — prueba de entrega completa
             */
            $table->string('tipo', 20);

            /*
             * Extremos polimórficos: una sede o un destinatario.
             *
             * Se guarda el tipo además del identificador porque `sedes` y
             * `destinatarios` son tablas distintas y un mismo número existe en
             * las dos. Sin el tipo, el id 7 sería ambiguo.
             */
            $table->string('origen_tipo', 20);
            $table->unsignedBigInteger('origen_id');
            $table->string('destino_tipo', 20);
            $table->unsignedBigInteger('destino_id');

            $table->foreignId('mensajero_id')->nullable()->constrained('mensajeros')->nullOnDelete();

            // El vehículo se congela al cerrar el tramo, y no se lee del
            // mensajero al consultarlo: si mañana le cambian la moto, el acta
            // debe seguir diciendo en qué vehículo viajó ESE día.
            $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->nullOnDelete();

            $table->string('estado', 20)->default('pendiente');

            // Cuándo se puede hacer. Permite programar el segundo tramo para
            // otro día sin que aparezca hoy en la bandeja del mensajero.
            $table->timestamp('programado_desde')->nullable();
            $table->timestamp('programado_hasta')->nullable();

            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('cerrado_en')->nullable();

            /*
             * La prueba del traspaso, tramo a tramo.
             *
             * Esto es lo que faltaba en la recolección: el cliente no tenía
             * comprobante firmado de que nos llevamos la mercancía. Ahora cada
             * tramo cierra con su propio acta, no solo el último.
             */
            $table->uuid('firma_id')->nullable();
            $table->uuid('foto_id')->nullable();
            $table->string('contraparte_nombre', 120)->nullable();
            $table->string('contraparte_documento', 30)->nullable();

            $table->decimal('cierre_latitud', 10, 7)->nullable();
            $table->decimal('cierre_longitud', 10, 7)->nullable();

            $table->text('observaciones')->nullable();

            $table->timestamps();

            // Un paquete no puede tener dos tramos en la misma posición.
            $table->unique(['paquete_id', 'orden']);

            // La consulta caliente: «qué tramos tiene abierto este mensajero».
            $table->index(['mensajero_id', 'estado']);
            $table->index(['estado', 'programado_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramos');
    }
};
