<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integración opcional por cliente, y el acuse de cada evidencia enviada.
 *
 * La configuración vive CON el cliente y no en el `.env`. El `.env` sirve para
 * una integración; aquí puede haber tantas como clientes, cada una con su
 * dirección, su bucket y su credencial. Meterlas en el entorno obligaría a
 * reinstalar el sistema cada vez que entra un cliente nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            /*
             * El interruptor no es cosmético: decide si el sistema habla con un
             * tercero cada vez que se cierra una entrega. Un cliente sin
             * integración no debe pagar el coste —ni el riesgo— de una llamada
             * que no va a ninguna parte.
             */
            $t->boolean('integracion_activa')->default(false)->after('activo');

            // Hoy solo existe `cassandra`, pero habrá más y cada una habla su
            // propio protocolo. El tipo es lo que decide qué servicio atiende.
            $t->string('integracion_tipo', 40)->nullable()->after('integracion_activa');

            /*
             * Lo no secreto: dirección de la API, bucket y el prefijo que hay
             * que recortar de la referencia. Va en texto porque MySQL 5.5 no
             * tiene tipo JSON; la capa de compatibilidad ya traduce `json()` a
             * `longtext`, y aquí se deja explícito para que nadie lo cambie
             * creyendo que gana algo.
             */
            $t->longText('integracion_config')->nullable()->after('integracion_tipo');

            /*
             * Lo secreto, aparte y cifrado en columna.
             *
             * Separarlo de la configuración no es manía: permite enseñar la
             * configuración en pantalla y en los registros sin arrastrar la
             * credencial, y permite reemplazar el token sin tocar el resto.
             * El texto cifrado abulta bastante más que el original, de ahí el
             * tamaño.
             */
            $t->text('integracion_token')->nullable()->after('integracion_config');
        });

        Schema::table('evidencias', function (Blueprint $t) {
            /*
             * El acuse va POR EVIDENCIA, no por paquete.
             *
             * Una entrega lleva foto y firma, y puede llegar una y fallar la
             * otra. Un acuse único dejaría al operador sin saber cuál de las
             * dos falta, que es justo lo que necesita saber para arreglarlo.
             */
            $t->timestamp('entregada_al_cliente_en')->nullable()->after('sincronizada');

            /*
             * El nombre con el que quedó el archivo en el repositorio del
             * cliente. En esa API no hay identificador: los archivos se
             * direccionan por nombre, así que el nombre ES la llave, y es lo
             * que permite reconstruir su enlace de descarga y demostrar que el
             * envío ocurrió.
             */
            $t->string('referencia_externa')->nullable()->after('entregada_al_cliente_en');

            // Para diagnosticar sin abrir los registros del servidor. Un fallo
            // que solo se ve en `laravel.log` es un fallo que nadie ve.
            $t->unsignedSmallInteger('intentos_envio')->default(0)->after('referencia_externa');
            $t->text('ultimo_error_envio')->nullable()->after('intentos_envio');

            // La pantalla de operación pregunta justo esto: qué evidencias de
            // clientes con integración siguen sin acuse.
            $t->index(['entregada_al_cliente_en', 'tipo'], 'idx_evidencias_acuse');
        });
    }

    public function down(): void
    {
        Schema::table('evidencias', function (Blueprint $t) {
            $t->dropIndex('idx_evidencias_acuse');
            $t->dropColumn([
                'entregada_al_cliente_en', 'referencia_externa',
                'intentos_envio', 'ultimo_error_envio',
            ]);
        });

        Schema::table('clientes', function (Blueprint $t) {
            $t->dropColumn([
                'integracion_activa', 'integracion_tipo',
                'integracion_config', 'integracion_token',
            ]);
        });
    }
};
