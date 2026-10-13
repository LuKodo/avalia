<?php

namespace App\Database\Compatibilidad;

use Illuminate\Database\MySqlConnection;

/**
 * Conexion MySQL que construye el esquema con la gramatica de motores
 * anteriores a 5.7.8.
 *
 * Solo afecta a la gramatica de ESQUEMA -la que redacta los CREATE TABLE-. La
 * gramatica de consultas se deja intacta a proposito: las consultas del sistema
 * no usan nada que 5.5 no entienda, y tocarlas seria cambiar el comportamiento
 * de la aplicacion en vez de solo el del instalador.
 *
 * Se activa desde AppServiceProvider cuando DB_MOTOR_ANTIGUO esta en true.
 */
class ConexionMysqlAntigua extends MySqlConnection
{
    protected function getDefaultSchemaGrammar()
    {
        return new GramaticaEsquemaAntigua($this);
    }
}
