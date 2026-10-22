<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las referencias del cliente pasan a poder colgar de un paquete.
 *
 * Nacieron colgando de la ORDEN, asumiendo una por envío. La realidad del
 * cliente es otra: una guía puede llevar diez dispensaciones, y cada una es un
 * documento distinto en su sistema. Con la referencia en la orden, el envío del
 * soporte tomaba `referencias->first()` y las otras nueve se perdían en
 * silencio, que es la peor forma de perder algo.
 *
 * Y lo que importa de verdad: una caja puede devolverse mientras las demás se
 * entregan. Si la referencia vive en la orden no hay forma de decir cuáles
 * llegaron; viviendo en el paquete, el estado del paquete ya lo dice.
 *
 * Queda NULA a propósito. Una orden de un solo paquete no necesita repetir el
 * dato, y `Paquete::referenciasEfectivas()` resuelve ese caso cayendo a las de
 * la orden. Así nada de lo radicado hasta hoy cambia de significado.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Repetible a propósito.
         *
         * Esta migración ya falló una vez a mitad de camino —la columna quedó
         * creada y el relleno reventó—, y sin esta guarda el segundo intento
         * muere por columna duplicada sin llegar nunca a la parte que faltaba.
         * Una migración que no se puede reintentar convierte un tropiezón en un
         * arreglo a mano sobre la base de producción.
         */
        if (! Schema::hasColumn('referencias_cliente', 'paquete_id')) {
            Schema::table('referencias_cliente', function (Blueprint $t) {
                $t->foreignId('paquete_id')
                    ->nullable()
                    ->after('orden_id')
                    ->constrained('paquetes')
                    ->nullOnDelete();

                $t->index(['paquete_id', 'tipo'], 'referencias_paquete_tipo');
            });
        }

        /*
         * Las órdenes de UN solo paquete se enlazan solas.
         *
         * No es cosmética: deja el dato dicho de la forma nueva en el 99 % de
         * los casos existentes, y así el código que lee por paquete encuentra
         * lo que espera sin depender siempre del respaldo. Las de varios
         * paquetes se quedan como están —no hay forma de adivinar qué
         * referencia iba en qué caja— y el respaldo las sigue cubriendo.
         */
        /*
         * `havingRaw` y no `having('cuantos', ...)`: MySQL 5.5 no resuelve el
         * alias de un agregado dentro de HAVING —lo toma por un nombre de
         * columna y responde 1463—. MariaDB sí lo acepta, que es justo por lo
         * que esto pasó las pruebas locales y falló en el servidor.
         */
        $unicos = DB::table('paquetes')
            ->select('orden_id', DB::raw('MIN(id) as paquete_id'))
            ->groupBy('orden_id')
            ->havingRaw('COUNT(*) = 1')
            ->get();

        foreach ($unicos->chunk(500) as $tanda) {
            foreach ($tanda as $fila) {
                DB::table('referencias_cliente')
                    ->where('orden_id', $fila->orden_id)
                    ->whereNull('paquete_id')
                    ->update(['paquete_id' => $fila->paquete_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('referencias_cliente', function (Blueprint $t) {
            $t->dropForeign(['paquete_id']);
            $t->dropIndex('referencias_paquete_tipo');
            $t->dropColumn('paquete_id');
        });
    }
};
