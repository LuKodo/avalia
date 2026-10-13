<?php

namespace App\Database\Compatibilidad;

use Illuminate\Database\Schema\Grammars\MariaDbGrammar;

/**
 * La misma compatibilidad, para el controlador `mariadb`.
 *
 * Hace falta una clase aparte porque el resolutor de conexiones de Laravel
 * escoge por nombre de controlador, y `mysql` y `mariadb` son dos ramas
 * distintas. Aplica a MariaDB anterior a 10.2.
 *
 * Los cambios y su justificacion viven en el rasgo AjustesMotorAntiguo.
 */
class GramaticaEsquemaAntiguaMariadb extends MariaDbGrammar
{
    use AjustesMotorAntiguo;
}
