<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tiempo en que el mensajero está en turno pero no puede repartir.
 *
 * Sin esto la productividad se mide contra el reloj de pared, y el reloj de
 * pared no distingue una hora de almuerzo de una hora parado bajo un aguacero
 * ni de una hora perdida. El indicador acaba castigando a quien cumplió, y un
 * número que castiga a quien cumple deja de usarse en una semana.
 *
 * Se guarda inicio y fin en vez de solo una duración porque la pregunta real
 * casi nunca es «cuánto», sino «a qué hora»: una avería a las diez de la mañana
 * explica un día entero, la misma avería a las seis de la tarde no explica
 * nada.
 *
 * `fin` es nulo mientras la pausa sigue abierta. Es lo que permite saber que un
 * mensajero lleva hora y media de almuerzo sin cerrar, que es un dato que
 * alguien quiere ver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pausas_jornada', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mensajero_id')->constrained('mensajeros')->cascadeOnDelete();

            // almuerzo | lluvia | averia | otro
            $t->string('tipo', 20);

            $t->timestamp('inicio');
            $t->timestamp('fin')->nullable();

            /*
             * La duración se guarda calculada al cerrar.
             *
             * Podría deducirse de inicio y fin cada vez, pero los informes la
             * suman sobre miles de filas y un cálculo por fila en MySQL 5.5 se
             * nota. Al cerrarse una pausa el dato ya no cambia, así que
             * guardarlo no crea dos verdades.
             */
            $t->unsignedInteger('duracion_seg')->nullable();

            $t->string('descripcion', 300)->nullable();

            // Dónde se detuvo. Un atasco o un aguacero tienen sitio, y verlo en
            // el mapa es la diferencia entre creer una explicación y comprobarla.
            $t->decimal('latitud', 10, 7)->nullable();
            $t->decimal('longitud', 10, 7)->nullable();

            $t->timestamps();

            // Las dos preguntas que se hacen siempre: qué pasa ahora con este
            // mensajero, y qué pasó en tal día.
            $t->index(['mensajero_id', 'inicio'], 'pausas_mensajero_inicio');
            $t->index(['mensajero_id', 'fin'], 'pausas_abiertas');
        });

        /*
         * El permiso de la cuenta de la aplicación, en la misma migración.
         *
         * Esa cuenta no tiene permisos generales sobre la base: se conceden
         * tabla por tabla, a propósito. Una tabla nueva no funciona hasta que
         * alguien decide qué puede hacerse con ella —y eso es lo que se
         * quiere—. Pero si el GRANT viaja en otra migración, la tabla se crea
         * y el sistema falla con un 1142 que no parece tener nada que ver con
         * lo último que se tocó. Van juntos.
         *
         * Lectura y escritura completa: una pausa se abre, se cierra y se
         * corrige. No es material de prueba como la bitácora.
         */
        $this->conceder('GRANT SELECT, INSERT, UPDATE, DELETE ON `%s`.`pausas_jornada` TO %s');
    }


    public function down(): void
    {
        $this->conceder('REVOKE ALL PRIVILEGES ON `%s`.`pausas_jornada` FROM %s');

        Schema::dropIfExists('pausas_jornada');
    }

    private function conceder(string $plantilla): void
    {
        $conexion = Schema::getConnection();
        $usuarios = \App\Database\CuentasDeLaApp::listar($conexion);

        foreach ($usuarios as $u) {
            try {
                $conexion->unprepared(sprintf($plantilla, $conexion->getDatabaseName(), $u));
            } catch (\Throwable $e) {
                logger()->warning('No se pudo ajustar el permiso sobre pausas_jornada', [
                    'usuario' => $u,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
};
