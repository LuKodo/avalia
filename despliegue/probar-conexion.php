<?php

/**
 * Prueba una credencial contra un motor de base de datos. No crea ni modifica
 * nada: conecta, lee la version y mira que permisos tiene la cuenta.
 *
 * Existe para responder una pregunta concreta antes de instalar: "esta cuenta
 * entra en este motor, y puede crear la base?". Averiguarlo con el instalador
 * es caro, porque para cuando falla ya intento hacer cosas.
 *
 * Las credenciales entran por variables de entorno, nunca por argumentos: los
 * argumentos de un proceso los ve cualquiera que liste las tareas.
 */

$host    = getenv('AVALIA_HOST')    ?: '127.0.0.1';
$puerto  = (int) (getenv('AVALIA_PUERTO') ?: 3306);
$usuario = getenv('AVALIA_USUARIO') ?: 'root';
$clave   = getenv('AVALIA_CLAVE');
$clave   = $clave === false ? '' : $clave;

function linea(string $estado, string $texto): void
{
    printf("  [%-5s] %s%s", $estado, $texto, PHP_EOL);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$puerto};charset=utf8mb4",
        $usuario,
        $clave,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
    );
} catch (PDOException $e) {
    linea('NO', "No entra en {$host}:{$puerto} como {$usuario}.");
    echo '         '.$e->getMessage().PHP_EOL;

    /*
     * Los tres tropiezos habituales, separados porque piden respuestas
     * distintas y el mensaje crudo de MySQL no los distingue bien.
     */
    $m = $e->getMessage();

    if (str_contains($m, '1045')) {
        echo PHP_EOL;
        echo '         La cuenta o la clave no son. El motor respondio, o sea que'.PHP_EOL;
        echo '         el puerto si es el correcto.'.PHP_EOL;
    } elseif (str_contains($m, '2002') || stripos($m, 'refused') !== false || stripos($m, 'timed out') !== false) {
        echo PHP_EOL;
        echo '         Nadie contesta en ese puerto. Revise el numero, o si el'.PHP_EOL;
        echo '         servicio esta detenido.'.PHP_EOL;
    } elseif (stripos($m, 'plugin') !== false || str_contains($m, '2054')) {
        echo PHP_EOL;
        echo '         El motor pidio un metodo de autenticacion que este cliente no'.PHP_EOL;
        echo '         maneja. Ojo: algunos motores responden esto mismo cuando la'.PHP_EOL;
        echo '         cuenta no existe o la clave no es, asi que no descarte lo'.PHP_EOL;
        echo '         simple. Pruebe primero con otra clave; si el mensaje persiste'.PHP_EOL;
        echo '         con una que sabe correcta, entonces si es el metodo.'.PHP_EOL;
    }

    exit(1);
}

$version = $pdo->query('SELECT VERSION()')->fetchColumn();
linea('OK', "Entra. Motor: {$version}");

// ------------------------------------------------------------ sirve o no
$esMaria = stripos($version, 'mariadb') !== false;
preg_match('/\d+(\.\d+)+/', $version, $m);
$numero = $m[0] ?? '';
$minimo = $esMaria ? '10.3' : '5.7.8';

if ($numero !== '' && version_compare($numero, $minimo, '<')) {
    linea('NO', 'Esa version no corre este sistema (hace falta '.($esMaria ? 'MariaDB 10.3' : 'MySQL 5.7.8').'+).');
} else {
    linea('OK', 'La version sirve.');
}

// ------------------------------------------------------------- permisos
try {
    $concesiones = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $texto       = implode(' | ', $concesiones);

    $todo = stripos($texto, 'ALL PRIVILEGES ON *.*') !== false
        || stripos($texto, 'GRANT ALL PRIVILEGES ON `%`') !== false;

    if ($todo || stripos($texto, 'CREATE') !== false) {
        linea('OK', 'La cuenta puede crear bases y usuarios.');
    } else {
        linea('AVISO', 'No veo permiso de CREATE. El instalador podria no poder crear la base.');
        foreach ($concesiones as $g) {
            echo '         '.$g.PHP_EOL;
        }
    }
} catch (PDOException $e) {
    linea('AVISO', 'No pude leer los permisos: '.$e->getMessage());
}

echo PHP_EOL;
exit(0);
