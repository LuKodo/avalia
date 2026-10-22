<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de alto costo en el paquete.
 *
 * Hay medicamentos cuyo valor hace que un extravío no sea un incidente sino un
 * problema serio: oncológicos, biológicos, terapias de alto costo. No pesan
 * más, no exigen frío necesariamente, y hoy viajaban indistinguibles del resto.
 *
 * Es una marca y no un rango de precios a propósito. El sistema no conoce el
 * valor del contenido —ni debe: es información clínica y comercial del
 * cliente— pero sí puede transportar la advertencia de quien sí lo sabe.
 *
 * Por omisión falso. Lo contrario convertiría la marca en ruido, que es como
 * mueren todas las advertencias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes', function (Blueprint $t) {
            $t->boolean('alto_costo')->default(false)->after('empaque');

            /*
             * Indexada porque la pregunta que se hace la operación es
             * «¿qué de alto costo hay hoy sin asignar?», y esa consulta filtra
             * por esta columna sobre la tabla entera.
             */
            $t->index(['alto_costo', 'estado'], 'paquetes_alto_costo');
        });
    }

    public function down(): void
    {
        Schema::table('paquetes', function (Blueprint $t) {
            $t->dropIndex('paquetes_alto_costo');
            $t->dropColumn('alto_costo');
        });
    }
};
