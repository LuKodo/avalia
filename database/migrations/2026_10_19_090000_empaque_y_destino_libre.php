<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El empaque del paquete.
 *
 * Hasta ahora un paquete solo declaraba qué contiene —general, cadena de frío,
 * control especial—. Pero lo que cuesta moverlo depende también de en qué
 * viaja: una caja de pañales ocupa el doble que todo lo demás junto y se cobra
 * distinto.
 *
 * Son dos ejes y conviene que sigan siéndolo: una bolsa puede llevar control
 * especial, y unos pañales no exigen refrigeración pero sí espacio.
 *
 * ---
 *
 * Esta migración llegó a incluir unas columnas `destino_libre*` en `ordenes`
 * para los domicilios. Fueron un error: ese caso YA estaba modelado en
 * `destinatarios` —con dirección, coordenada, zona, ventanas de atención y
 * política de retención— y un disparador en `ordenes` exige precisamente que
 * haya sede o destinatario, nunca ambos ni ninguno. Lo que faltaba no era
 * modelo sino camino para crearlo al radicar, y eso vive en el controlador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes', function (Blueprint $t) {
            // Por omisión caja: es lo que venía siendo todo hasta hoy, así que
            // nada de lo radicado cambia de precio por esta migración.
            $t->string('empaque', 20)->default('caja')->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('paquetes', function (Blueprint $t) {
            $t->dropColumn('empaque');
        });
    }
};
