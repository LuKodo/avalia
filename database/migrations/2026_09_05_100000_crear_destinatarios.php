<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinatarios: puntos de entrega que NO son sedes.
 *
 * Un afiliado en su casa, un paciente, un tercero autorizado. Hasta ahora el
 * destino de una guía tenía que ser una sede, así que entregarle a un paciente
 * habría exigido inventarle una «sede» — y eso está mal por dos razones, la
 * segunda de ellas seria:
 *
 *   · El catálogo maestro se llenaría de miles de direcciones de un solo uso,
 *     mezcladas con bodegas y clínicas reales.
 *
 *   · Los datos del paciente quedarían en una tabla de infraestructura, sin
 *     finalidad declarada, sin retención acotada y sin forma de suprimirlos.
 *     Los datos de un paciente y los de una bodega no se gobiernan igual.
 *
 * Por eso esta tabla lleva su propio ciclo de vida de datos personales:
 * finalidad, autorización y fecha de supresión. El régimen aplicable lo define
 * el área de Protección de Datos; el modelo solo deja el lugar para aplicarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinatarios', function (Blueprint $t) {
            $t->id();

            /*
             * El destinatario pertenece al CLIENTE, no a LTP. Es su afiliado, y
             * es él quien responde por la autorización de tratamiento. El
             * aislamiento por cliente que ya rige los envíos rige aquí también.
             */
            $t->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            // afiliado | paciente | tercero — quién es respecto del cliente.
            $t->string('tipo', 20)->default('afiliado');

            // --- identificación ------------------------------------------------
            $t->string('nombre');
            $t->string('tipo_documento', 10)->nullable();
            $t->string('documento', 30)->nullable();
            $t->string('telefono', 30)->nullable();
            $t->string('email')->nullable();

            // --- dónde entregar --------------------------------------------------
            $t->foreignId('zona_id')->nullable()->constrained('zonas')->nullOnDelete();
            $t->string('direccion');
            // «Apto 502, torre B, portería exige llamar antes»: lo que evita una
            // entrega fallida y una segunda visita.
            $t->string('detalle_acceso')->nullable();
            $t->string('ciudad', 80);
            $t->decimal('latitud', 10, 7)->nullable();
            $t->decimal('longitud', 10, 7)->nullable();
            $t->boolean('coordenada_verificada')->default(false);

            /*
             * Ventanas de atención. Un paciente no está en su casa todo el día, y
             * llegar fuera de su horario es la causa más común de reintento en
             * domiciliaria. En sedes este dato ya existe; aquí pesa más.
             */
            $t->json('ventanas_atencion')->nullable();

            // --- procedencia y permanencia ---------------------------------------
            // formulario | api | carga_masiva | directorio
            $t->string('origen_dato', 20)->default('formulario');

            /*
             * Un destinatario puntual se crea para UNA guía y se suprime al vencer
             * su retención. Uno persistente vive en el padrón que el cliente
             * mantiene. Distinguirlos es lo que permite borrar lo puntual sin
             * romper el padrón.
             */
            $t->boolean('persistente')->default(false);

            // --- tratamiento de datos personales ---------------------------------
            $t->timestamp('autorizacion_en')->nullable();
            $t->string('finalidad', 120)->nullable();
            // Fecha a partir de la cual el dato debe suprimirse o anonimizarse.
            $t->date('suprimir_desde')->nullable();

            $t->boolean('activo')->default(true);

            $t->timestamps();
            $t->softDeletes();

            // Buscar «los afiliados de este cliente» es la consulta de siempre.
            $t->index(['cliente_id', 'activo']);
            // Evitar duplicados al radicar dos veces al mismo paciente.
            $t->index(['cliente_id', 'documento']);
            // La barrida de supresión recorre por fecha.
            $t->index('suprimir_desde');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinatarios');
    }
};
