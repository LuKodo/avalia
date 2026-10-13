<?php

namespace App\Database\Compatibilidad;

use Illuminate\Database\MariaDbConnection;

/**
 * Conexion MariaDB que construye el esquema sin columnas JSON.
 *
 * Para MariaDB anterior a 10.2. Igual que su equivalente de MySQL, solo cambia
 * la gramatica de esquema; la de consultas se deja intacta.
 */
class ConexionMariadbAntigua extends MariaDbConnection
{
    protected function getDefaultSchemaGrammar()
    {
        return new GramaticaEsquemaAntiguaMariadb($this);
    }
}
