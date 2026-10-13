<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ruta que el envío DEBERÍA tomar.
 *
 * Se guarda, y no se recalcula al vuelo, por una razón que es todo el sentido
 * de la función: si la ruta se volviera a pedir en cada consulta, se recalcularía
 * desde donde está el mensajero ahora, y entonces la ruta seguiría al vehículo en
 * lugar de juzgarlo. Nunca habría desvío. Una referencia que se mueve con lo que
 * mide no mide nada.
 *
 * Se traza una vez, al asignar, y queda congelada. El desvío es la distancia
 * entre el rastro real y esa línea fija.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rutas_planeadas', function (Blueprint $table) {
            $table->id();

            // Una ruta por orden. Si el destino cambia, se reemplaza la fila
            // entera y queda constancia en `motivo_recalculo`.
            $table->foreignId('orden_id')->unique()->constrained('ordenes')->cascadeOnDelete();

            $table->decimal('origen_lat', 10, 7);
            $table->decimal('origen_lon', 10, 7);
            $table->decimal('destino_lat', 10, 7);
            $table->decimal('destino_lon', 10, 7);

            /*
             * La polilínea, como lista de pares [longitud, latitud].
             *
             * En ese orden y no al revés porque es el de GeoJSON, que es lo que
             * consume el mapa del navegador. Invertirlo aquí obligaría a darle
             * la vuelta en cada lectura, y ese es exactamente el tipo de detalle
             * que un día se olvida y pone los envíos en mitad del océano.
             */
            $table->json('geometria');

            $table->unsignedInteger('distancia_m');
            $table->unsignedInteger('duracion_seg');

            /*
             * Quién trazó esto: el servicio de ruteo o el respaldo en línea
             * recta. Se guarda porque cambia lo que la pantalla puede afirmar:
             * sobre una línea recta el «desvío» no significa nada —ninguna
             * calle es recta— y la interfaz tiene que decirlo en vez de acusar
             * a un mensajero que va por donde debe.
             */
            $table->string('proveedor', 20);

            $table->timestamp('calculada_en');
            $table->string('motivo_recalculo', 120)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rutas_planeadas');
    }
};
