<?php

namespace App\Database\Compatibilidad;

use Illuminate\Database\Schema\Grammars\MySqlGrammar;

/**
 * Gramatica de esquema para motores MySQL anteriores a 5.7.8.
 *
 * Los cambios concretos y el porque de cada uno estan en el rasgo
 * AjustesMotorAntiguo. En resumen: JSON pasa a LONGTEXT y TIMESTAMP a DATETIME
 * salvo cuando hace falta CURRENT_TIMESTAMP.
 *
 * Solo se toca la gramatica de ESQUEMA -la que redacta los CREATE TABLE-. La de
 * consultas queda intacta: nada de lo que consulta este sistema le suena raro a
 * un motor viejo, y cambiarla seria alterar el comportamiento de la aplicacion
 * en vez de solo el del instalador.
 *
 * Es una pasarela, no un destino. Al pasar a MySQL 8 se quita la bandera
 * DB_MOTOR_ANTIGUO y se reconstruye el esquema.
 */
class GramaticaEsquemaAntigua extends MySqlGrammar
{
    use AjustesMotorAntiguo;
}
