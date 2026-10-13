<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensancha `distancia_a_sede_m` para que quepa cualquier distancia real.
 *
 * La columna nació como `unsignedSmallInteger`: tope 65.535 metros. La hipótesis
 * implícita era que una entrega ocurre cerca de la sede y lo que se mide es el
 * desvío —decenas o cientos de metros—. Es razonable y resultó falsa.
 *
 * El primer caso real la rompió: una entrega registrada en Cartagena contra una
 * sede de Medellín dio 456.740 metros, siete veces el tope. MySQL rechazó el
 * INSERT con un 1264, la petición devolvió 500, y el teléfono se quedó
 * reintentando la misma entrega cada treinta segundos. El mensajero había hecho
 * su trabajo —foto, firma, coordenada— y el sistema no se lo pudo guardar.
 *
 * Lo irónico es que el cálculo estaba bien: la distancia se midió correctamente
 * y `geolocalizacion_coherente` quedó en falso, que es justo lo que debía pasar.
 * Lo que faltaba era poder ESCRIBIR esa anomalía. Una columna que solo admite
 * los valores esperados no puede registrar lo inesperado, que es precisamente lo
 * que una bitácora tiene que capturar.
 *
 * `unsignedInteger` da hasta 4.294.967.295 metros. Es más de lo que cualquier
 * entrega terrestre necesitará nunca, y esa holgura es deliberada: el coste de
 * cuatro bytes por fila frente al de volver a perder una entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas', function (Blueprint $t) {
            $t->unsignedInteger('distancia_a_sede_m')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * La vuelta atrás trunca: cualquier distancia por encima de 65.535 ya no
         * cabe. Se dejan en nulo antes de estrechar la columna, para que el motor
         * no falle ni guarde un número inventado. Perder el dato es malo; que la
         * bitácora diga que una entrega ocurrió a 65 km cuando fueron 457 es peor.
         */
        \DB::table('entregas')
            ->where('distancia_a_sede_m', '>', 65535)
            ->update(['distancia_a_sede_m' => null]);

        Schema::table('entregas', function (Blueprint $t) {
            $t->unsignedSmallInteger('distancia_a_sede_m')->nullable()->change();
        });
    }
};
