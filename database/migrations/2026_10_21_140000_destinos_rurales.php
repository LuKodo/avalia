<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinos rurales.
 *
 * Llevar una caja a un corregimiento no cuesta lo mismo que cruzar la ciudad:
 * son dos horas de trocha, un vehículo que no es el de siempre y un mensajero
 * que pierde la tarde. Hasta ahora esa diferencia la absorbía Avalia en
 * silencio, porque la tarifa salía solo de la zona.
 *
 * Es una marca en el destino y no una zona nueva a propósito: lo rural no es
 * una región del mapa sino una propiedad del punto concreto. Dentro de un mismo
 * municipio hay una sede urbana y una vereda, y obligarlas a estar en zonas
 * distintas rompería la tarifa de las demás.
 *
 * El recargo va por parámetro, no aquí: cuánto más cuesta lo decide quien
 * factura, puede cambiar con el contrato, y no debería hacer falta tocar el
 * software para ajustarlo. Por omisión cero —un recargo que nadie fijó no se
 * cobra—, igual que el de pañales.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sedes', 'destinatarios'] as $tabla) {
            if (Schema::hasColumn($tabla, 'rural')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) {
                $t->boolean('rural')->default(false)->after('ciudad');
            });
        }
    }

    public function down(): void
    {
        foreach (['sedes', 'destinatarios'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'rural')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) {
                $t->dropColumn('rural');
            });
        }
    }
};
