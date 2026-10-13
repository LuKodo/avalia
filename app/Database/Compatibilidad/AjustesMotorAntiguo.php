<?php

namespace App\Database\Compatibilidad;

use Illuminate\Support\Fluent;

/**
 * Los ajustes de tipo que necesitan los motores anteriores a MySQL 5.7.8.
 *
 * Va en un rasgo porque hacen falta identicos en las dos gramaticas -la de
 * MySQL y la de MariaDB- y duplicarlos garantizaba que un dia solo se corrija
 * una.
 */
trait AjustesMotorAntiguo
{
    /**
     * JSON no existe antes de MySQL 5.7.8 ni de MariaDB 10.2.
     *
     * LONGTEXT es equivalente para este sistema: ninguna consulta usa
     * operadores JSON del motor, las columnas se leen y escriben enteras y de
     * convertirlas a arreglo se encarga el cast de Eloquent. MariaDB hace
     * exactamente esto, donde JSON no es mas que un alias de LONGTEXT.
     */
    protected function typeJson(Fluent $column)
    {
        return 'longtext';
    }

    protected function typeJsonb(Fluent $column)
    {
        return 'longtext';
    }

    /**
     * TIMESTAMP pasa a DATETIME salvo que necesite CURRENT_TIMESTAMP.
     *
     * Aqui esta la trampa de MySQL 5.5, y muerde de dos formas distintas:
     *
     *   - La PRIMERA columna TIMESTAMP de una tabla, si es NOT NULL y no
     *     declara valor por omision, recibe DEFAULT CURRENT_TIMESTAMP ON
     *     UPDATE CURRENT_TIMESTAMP automaticamente. Si la tabla ya tiene otra
     *     con useCurrent(), son dos, y el motor responde
     *     «1293 there can be only one TIMESTAMP column with CURRENT_TIMESTAMP».
     *
     *   - Si no es la primera, hereda '0000-00-00 00:00:00', que bajo
     *     NO_ZERO_DATE en modo estricto -el que pone Laravel- da
     *     «1067 Invalid default value».
     *
     * DATETIME no tiene ninguno de los dos comportamientos: no se inicializa
     * solo ni hereda fecha cero. Las que si necesitan CURRENT_TIMESTAMP se
     * quedan como TIMESTAMP, porque en 5.5 DATETIME no admite ese valor por
     * omision (eso llego en 5.6). Como solo hay una de esas por tabla, no se
     * vuelve a chocar con el limite.
     *
     * A efectos de la aplicacion el cambio es transparente: Eloquent trata
     * ambos tipos igual. La diferencia real es que DATETIME guarda la hora tal
     * cual y TIMESTAMP la convierte a UTC; como el sistema trabaja entero en
     * America/Bogota y nunca cambia la zona del servidor, no se nota.
     */
    protected function typeTimestamp(Fluent $column)
    {
        if ($column->useCurrent || $column->useCurrentOnUpdate) {
            // La rama de arriba necesita que el padre registre el valor por
            // omision ademas de devolver el tipo, asi que se delega en el.
            return parent::typeTimestamp($column);
        }

        // Sin precision: DATETIME(n) y TIMESTAMP(n) son de MySQL 5.6 en
        // adelante, y pedirlos aqui volveria a romper lo que se viene a
        // arreglar.
        return 'datetime';
    }

    /**
     * La introspeccion de columnas, sin pedir `generation_expression`.
     *
     * Laravel consulta information_schema.columns para responder a
     * Schema::hasColumn(), y entre los campos que pide esta
     * `generation_expression`, que guarda la formula de una columna generada.
     * Esa clase de columna llego en MySQL 5.7, y con ella el campo: en 5.5 el
     * motor responde «1054 Unknown column 'generation_expression'» y se lleva
     * por delante cualquier migracion que compruebe si una columna existe.
     *
     * Se devuelve NULL en su lugar, que es lo que valdria de todos modos: en un
     * motor sin columnas generadas ninguna columna tiene formula. El resto de
     * la consulta se deja igual para que Laravel encuentre lo que espera.
     */
    public function compileColumns($schema, $table)
    {
        return sprintf(
            'select column_name as `name`, data_type as `type_name`, column_type as `type`, '
            .'collation_name as `collation`, is_nullable as `nullable`, '
            .'column_default as `default`, column_comment as `comment`, '
            .'null as `expression`, extra as `extra` '
            .'from information_schema.columns where table_schema = %s and table_name = %s '
            .'order by ordinal_position asc',
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }
}
