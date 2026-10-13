<?php

/**
 * Prepara la base de datos y sus dos usuarios.
 *
 * Lo hace con PDO y no con el cliente `mysql.exe` por un motivo practico: ese
 * cliente no siempre esta en el servidor -sobre todo cuando la base vive en
 * otra maquina- y pedirlo obligaba a instalar algo solo para tres sentencias
 * SQL. PHP con pdo_mysql ya esta ahi, porque sin el la aplicacion tampoco
 * arrancaria.
 *
 * Las credenciales llegan por VARIABLES DE ENTORNO, nunca por argumentos: los
 * argumentos de un proceso los ve cualquiera con la lista de tareas, y ahi
 * quedaria la contrasena de root a la vista.
 *
 * Uso (lo llama instalar.ps1, no hace falta a mano):
 *   AVALIA_HOST, AVALIA_PUERTO, AVALIA_USUARIO, AVALIA_CLAVE,
 *   AVALIA_BASE, AVALIA_CLAVE_APP, AVALIA_CLAVE_ADMIN
 *
 * Devuelve 0 si todo fue bien. Imprime una linea `VERSION=...` para que el
 * instalador la muestre.
 */

function env(string $clave, string $porDefecto = ''): string
{
    $v = getenv($clave);

    return $v === false ? $porDefecto : $v;
}

function morir(string $mensaje): never
{
    fwrite(STDERR, $mensaje.PHP_EOL);
    exit(1);
}

$host    = env('AVALIA_HOST', '127.0.0.1');
$puerto  = (int) env('AVALIA_PUERTO', '3306');
$usuario = env('AVALIA_USUARIO', 'root');
$clave   = env('AVALIA_CLAVE');
$base    = env('AVALIA_BASE', 'avalia');

$claveApp   = env('AVALIA_CLAVE_APP');
$claveAdmin = env('AVALIA_CLAVE_ADMIN');

if ($claveApp === '' || $claveAdmin === '') {
    morir('Faltan las contrasenas generadas para los usuarios de la aplicacion.');
}

// El nombre de la base entra en el SQL sin poder parametrizarse -no se puede
// enlazar un identificador-, asi que se restringe a lo que un nombre valido
// puede contener. Sin esto habria inyeccion por el nombre de la base.
if (! preg_match('/^[A-Za-z0-9_]{1,60}$/', $base)) {
    morir("El nombre de base «{$base}» no es valido: solo letras, digitos y guion bajo.");
}

// ---------------------------------------------------------------- conectar
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$puerto};charset=utf8mb4",
        $usuario,
        $clave,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
    );
} catch (PDOException $e) {
    morir("No se pudo conectar a {$host}:{$puerto} como {$usuario}.".PHP_EOL.'  '.$e->getMessage());
}

$version = $pdo->query('SELECT VERSION()')->fetchColumn();
echo "VERSION={$version}".PHP_EOL;

/*
 * Que controlador de Laravel toca. No es cosmetico: `mariadb` y `mysql` son
 * clases distintas en Laravel 12, y la capa de compatibilidad para motores
 * viejos se engancha sobre `mysql`. Poner el controlador equivocado la deja
 * sin efecto y las migraciones vuelven a pedir columnas JSON.
 */
echo 'MOTOR='.(stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql').PHP_EOL;

/*
 * La version, antes de crear nada.
 *
 * El esquema usa catorce columnas de tipo JSON -geometrias de ruta, poligonos
 * de zona, cargas de webhook-. Ese tipo no existe en MySQL hasta 5.7.8 ni en
 * MariaDB hasta 10.2. En un motor mas viejo las migraciones fallan a mitad y
 * dejan la base a medias, que es mucho peor que no empezar.
 */
$esMaria = stripos($version, 'mariadb') !== false;
$numero  = preg_replace('/[^0-9.].*$/', '', $version);
$minimo  = $esMaria ? '10.3' : '5.7.8';

$antiguo = version_compare($numero, $minimo, '<');

/*
 * Un motor viejo se admite solo si quien instala lo pidio expresamente.
 *
 * Con la bandera puesta, el esquema se construye con LONGTEXT en lugar de JSON
 * y con cadenas indexadas de 191 caracteres, que es lo que InnoDB de esa epoca
 * aguanta. Funciona, pero es una pasarela hacia un motor moderno: MySQL 5.5
 * lleva sin parches de seguridad desde diciembre de 2018.
 */
$permitirAntiguo = filter_var(env('AVALIA_MOTOR_ANTIGUO', '0'), FILTER_VALIDATE_BOOLEAN);

if ($antiguo && ! $permitirAntiguo) {
    morir(
        "El motor es {$version} y hace falta al menos ".($esMaria ? 'MariaDB 10.3' : 'MySQL 5.7.8').'.'.PHP_EOL.
        '  Este sistema guarda rutas, zonas y webhooks en columnas JSON, que ese'.PHP_EOL.
        '  motor no tiene. Use MariaDB 10.4+ o MySQL 8.'.PHP_EOL.
        '  Para pruebas puede forzarlo con -MotorAntiguo, que sustituye esas'.PHP_EOL.
        '  columnas por texto.'
    );
}

if ($antiguo) {
    echo 'MODO=antiguo'.PHP_EOL;
    fwrite(STDERR, "AVISO: {$version} esta por debajo del minimo. Se instalara en modo".PHP_EOL);
    fwrite(STDERR, '       compatible: columnas de texto en vez de JSON, juego de'.PHP_EOL);
    fwrite(STDERR, '       caracteres utf8 en vez de utf8mb4 (sin emojis).'.PHP_EOL);
}

/*
 * utf8mb4 gasta cuatro bytes por caracter, y InnoDB en 5.5 no indexa claves de
 * mas de 767 bytes. Una columna de 225 caracteres -las hay- se pasa. Con utf8,
 * de tres bytes, cabe. El precio es no poder guardar emojis, que en direcciones
 * y nombres de sede no hace falta.
 */
$juego     = $antiguo ? 'utf8'                : 'utf8mb4';
$cotejo    = $antiguo ? 'utf8_unicode_ci'     : 'utf8mb4_unicode_ci';

// ------------------------------------------------------------------ crear
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$base}` CHARACTER SET {$juego} COLLATE {$cotejo}");

    /*
     * Dos usuarios con trabajos distintos, y no es ceremonia:
     *
     *   avalia_app    lo usa la aplicacion. Sin permisos de esquema: si alguien
     *                 logra inyectar SQL no puede alterar tablas ni leer otras
     *                 bases.
     *   avalia_admin  solo migraciones y mantenimiento. La web nunca lo usa.
     *
     * Se crean para 'localhost' y '127.0.0.1' porque MySQL los trata como
     * cuentas DISTINTAS, y conceder sobre una sola deja la otra sin permisos
     * con un error que no lo explica. Si la base es remota, ademas para el
     * comodin del host que se indique.
     */
    $anfitriones = ['localhost', '127.0.0.1'];

    if (! in_array($host, $anfitriones, true)) {
        // La aplicacion se conecta desde el servidor web, no desde el de base.
        $anfitriones[] = '%';
    }

    /*
     * Sin sentencias preparadas aqui, a proposito: en CREATE USER y ALTER USER
     * el nombre de cuenta es un IDENTIFICADOR, y MySQL no admite enlazar
     * parametros en esa posicion. Se escapa con quote(), que es lo correcto
     * para este caso.
     *
     * Los nombres de cuenta son constantes nuestras y los anfitriones salen de
     * la lista de arriba; lo unico que viene de fuera es la contrasena, que va
     * escapada.
     */
    /*
     * Crear o actualizar la cuenta, por dos caminos segun el motor.
     *
     * MySQL 5.5 no conoce `CREATE USER IF NOT EXISTS` (llego en 5.7.6) ni
     * `ALTER USER ... IDENTIFIED BY` (5.6). Hay que preguntar primero si la
     * cuenta existe y, si existe, cambiar la clave con `SET PASSWORD`.
     *
     * Y al reves: `SET PASSWORD = PASSWORD(...)` no sirve en MySQL 8, donde la
     * funcion PASSWORD() se elimino. Por eso son dos ramas y no una sola que
     * intente valer para todo.
     */
    $existe = $pdo->prepare('SELECT 1 FROM mysql.user WHERE User = ? AND Host = ?');

    foreach ([['avalia_app', $claveApp], ['avalia_admin', $claveAdmin]] as [$cuenta, $secreto]) {
        foreach ($anfitriones as $donde) {
            $quien = "'{$cuenta}'@".$pdo->quote($donde);
            $pass  = $pdo->quote($secreto);

            if (! $antiguo) {
                $pdo->exec("CREATE USER IF NOT EXISTS {$quien} IDENTIFIED BY {$pass}");
                $pdo->exec("ALTER USER {$quien} IDENTIFIED BY {$pass}");

                continue;
            }

            $existe->execute([$cuenta, $donde]);

            if ($existe->fetchColumn()) {
                $pdo->exec("SET PASSWORD FOR {$quien} = PASSWORD({$pass})");
            } else {
                $pdo->exec("CREATE USER {$quien} IDENTIFIED BY {$pass}");
            }
        }
    }

    foreach ($anfitriones as $donde) {
        // GRANT no admite parametros enlazados: el usuario y el host son
        // identificadores. Van de constantes nuestras y de la lista de arriba,
        // nunca de la entrada del operador.
        $d = $pdo->quote($donde);
        /*
         * WITH GRANT OPTION, y solo sobre esta base.
         *
         * Las migraciones ajustan los permisos del usuario de la aplicacion
         * tabla por tabla -esa es la razon de que la aplicacion no pueda
         * modificar la bitacora aunque quiera-. Para conceder algo hay que
         * poder concederlo, asi que avalia_admin necesita esta opcion. Queda
         * acotada a `{$base}`: no puede repartir permisos sobre ninguna otra
         * base del servidor.
         */
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$base}`.* TO 'avalia_admin'@{$d} WITH GRANT OPTION");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$base}`.* TO 'avalia_app'@{$d}");
    }

    $pdo->exec('FLUSH PRIVILEGES');
} catch (PDOException $e) {
    morir('Fallo preparando la base: '.$e->getMessage());
}

/*
 * Comprobar que la aplicacion puede entrar de verdad.
 *
 * Crear la cuenta y que PHP pueda usarla no son lo mismo. MySQL 8 autentica
 * por omision con `caching_sha2_password`, y segun como este compilado el
 * cliente de PHP la conexion se cae con un mensaje que no menciona ni la
 * cuenta ni la clave -habla de un «plugin desconocido»-. Si eso pasa aqui,
 * pasaria despues en cada peticion de la web, pero alli el error saldria como
 * un 500 sin pista alguna.
 *
 * Asi que se prueba ahora, y si el motor lo permite se retrocede al metodo
 * anterior. Es menos moderno, pero la alternativa es una instalacion que
 * termina «bien» y no arranca.
 */
function autenticar(string $host, int $puerto, string $base, string $cuenta, string $secreto): ?string
{
    try {
        new PDO(
            "mysql:host={$host};port={$puerto};dbname={$base};charset=utf8mb4",
            $cuenta,
            $secreto,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
        );

        return null;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

$fallo = autenticar($host, $puerto, $base, 'avalia_app', $claveApp);

if ($fallo !== null) {
    $esPlugin = ! $antiguo
        && stripos($fallo, 'plugin') !== false
        || stripos($fallo, 'authentication method') !== false
        || str_contains($fallo, '2054');

    if (! $esPlugin) {
        morir('La cuenta avalia_app quedo creada pero no pude entrar con ella:'.PHP_EOL.'  '.$fallo);
    }

    fwrite(STDERR, 'AVISO: el cliente de PHP no entiende el metodo de autenticacion'.PHP_EOL);
    fwrite(STDERR, '       por omision de este motor. Cambiando a mysql_native_password.'.PHP_EOL);

    try {
        foreach ([['avalia_app', $claveApp], ['avalia_admin', $claveAdmin]] as [$cuenta, $secreto]) {
            foreach ($anfitriones as $donde) {
                $quien = "'{$cuenta}'@".$pdo->quote($donde);
                $pdo->exec("ALTER USER {$quien} IDENTIFIED WITH mysql_native_password BY ".$pdo->quote($secreto));
            }
        }
        $pdo->exec('FLUSH PRIVILEGES');
    } catch (PDOException $e) {
        morir(
            'El motor no acepta mysql_native_password y PHP no habla el metodo nuevo.'.PHP_EOL.
            '  Detalle: '.$e->getMessage().PHP_EOL.
            '  Habilite la extension correcta de PHP o use MariaDB.'
        );
    }

    $fallo = autenticar($host, $puerto, $base, 'avalia_app', $claveApp);

    if ($fallo !== null) {
        morir('Aun cambiando el metodo, avalia_app no puede entrar:'.PHP_EOL.'  '.$fallo);
    }
}

echo "JUEGO={$juego}".PHP_EOL;
echo "COTEJO={$cotejo}".PHP_EOL;
echo 'LISTO'.PHP_EOL;
exit(0);
