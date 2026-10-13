<?php

/**
 * Le pone contrasena a la cuenta root de una MariaDB recien inicializada.
 *
 * Se hace desde PHP y no con el cliente de consola por una razon concreta: en
 * la linea de comandos la clave quedaria visible para cualquiera que liste los
 * procesos de la maquina, y en un archivo .sql temporal quedaria en disco
 * aunque sea un rato. Por variable de entorno no pasa ninguna de las dos.
 *
 * Una MariaDB recien creada tiene root sin clave y solo escuchando en local,
 * asi que esta ventana es corta, pero conviene cerrarla igual.
 */

$host   = getenv('AVALIA_HOST')   ?: '127.0.0.1';
$puerto = (int) (getenv('AVALIA_PUERTO') ?: 3310);
$nueva  = getenv('AVALIA_CLAVE_ROOT');

if (! is_string($nueva) || $nueva === '') {
    fwrite(STDERR, 'No llego la contrasena nueva.'.PHP_EOL);
    exit(1);
}

try {
    // Root todavia no tiene clave: por eso la cadena vacia.
    $pdo = new PDO(
        "mysql:host={$host};port={$puerto};charset=utf8mb4",
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'No pude entrar como root sin clave para fijarla:'.PHP_EOL.'  '.$e->getMessage().PHP_EOL);
    fwrite(STDERR, '  Si ya tenia clave, esto no es un fallo: salte este paso.'.PHP_EOL);
    exit(1);
}

try {
    /*
     * Solo las cuentas locales. MariaDB en Windows crea root@localhost y
     * root@127.0.0.1, y deja ademas unas cuentas anonimas y la base `test`
     * que no hacen falta y solo amplian la superficie de ataque.
     */
    foreach (['localhost', '127.0.0.1', '::1'] as $donde) {
        $quien = "'root'@".$pdo->quote($donde);
        try {
            $pdo->exec("SET PASSWORD FOR {$quien} = PASSWORD(".$pdo->quote($nueva).')');
        } catch (PDOException $e) {
            // Puede que esa variante de root no exista; no es un problema.
        }
    }

    $pdo->exec("DELETE FROM mysql.user WHERE User = ''");
    $pdo->exec('DROP DATABASE IF EXISTS test');
    $pdo->exec('FLUSH PRIVILEGES');
} catch (PDOException $e) {
    fwrite(STDERR, 'Fallo asegurando la instancia: '.$e->getMessage().PHP_EOL);
    exit(1);
}

// Comprobar que de verdad quedo puesta, no dar por hecho que el SET funciono.
try {
    new PDO(
        "mysql:host={$host};port={$puerto};charset=utf8mb4",
        'root',
        $nueva,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'La clave se fijo pero no puedo entrar con ella: '.$e->getMessage().PHP_EOL);
    exit(1);
}

echo 'LISTO'.PHP_EOL;
exit(0);
