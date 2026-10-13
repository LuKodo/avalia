<?php

use Illuminate\Support\Str;

/**
 * Conexiones de base de datos.
 *
 * Dos usuarios distintos, deliberadamente:
 *
 *   · `mariadb`       — el que usa la aplicación en marcha. Privilegio mínimo:
 *                       sin DDL, y sobre la bitácora solo SELECT e INSERT. Ni
 *                       siquiera puede intentar reescribir un evento, ni
 *                       eliminar el disparador que lo impide.
 *   · `mariadb_admin` — solo para migraciones y mantenimiento. Nunca lo usa el
 *                       código de la aplicación.
 *   · `mysql_admin`   — lo mismo, para servidores MySQL. El instalador elige
 *                       uno u otro según lo que reporte el motor.
 *
 * Con un único usuario con todos los permisos, la inmutabilidad depende solo de
 * un disparador que ese mismo usuario podría eliminar. Separarlos es lo que
 * convierte el control en algo que resiste una auditoría.
 *
 *     php artisan migrate --database=mariadb_admin
 */
return [

    'default' => env('DB_CONNECTION', 'mariadb'),

    /*
     * La conexion administrativa que corresponde al motor en uso.
     *
     * Se deriva de la de la aplicacion en vez de fijarse a mano para que no
     * haya forma de apuntar a un controlador distinto del real. Cada sitio que
     * necesite permisos de esquema -migraciones, datos de demostracion- lee de
     * aqui en lugar de escribir el nombre.
     */
    'admin' => env('DB_ADMIN_CONNECTION', env('DB_CONNECTION', 'mariadb').'_admin'),

    'connections' => [

        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DB_URL'),
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout'            => null,
            'journal_mode'            => null,
            'synchronous'             => null,
        ],

        'mariadb' => [
            'driver'         => 'mariadb',
            'url'            => env('DB_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'ltp'),
            'username'       => env('DB_USERNAME'),
            'password'       => env('DB_PASSWORD', ''),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => env('DB_CHARSET', 'utf8mb4'),
            'collation'      => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
            'options'        => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // Misma base, usuario administrativo. Solo para migraciones.
        'mariadb_admin' => [
            'driver'         => 'mariadb',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'ltp'),
            'username'       => env('DB_ADMIN_USERNAME', env('DB_USERNAME')),
            'password'       => env('DB_ADMIN_PASSWORD', env('DB_PASSWORD', '')),
            'charset'        => env('DB_CHARSET', 'utf8mb4'),
            'collation'      => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
        ],

        'mysql' => [
            'driver'         => 'mysql',
            'url'            => env('DB_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'ltp'),
            'username'       => env('DB_USERNAME'),
            'password'       => env('DB_PASSWORD', ''),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => env('DB_CHARSET', 'utf8mb4'),
            'collation'      => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
        ],

        /*
         * Igual que `mariadb_admin`, pero para servidores MySQL de verdad.
         *
         * Hacen falta las dos y no vale una sola: en Laravel 12 `mysql` y
         * `mariadb` son controladores distintos, con clases de conexion
         * distintas. Correr las migraciones de un MySQL a traves del
         * controlador de MariaDB funciona de casualidad en lo comun y falla en
         * lo que no lo es -por ejemplo, saltandose la capa de compatibilidad
         * para motores antiguos, que se engancha por controlador-.
         */
        'mysql_admin' => [
            'driver'         => 'mysql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'ltp'),
            'username'       => env('DB_ADMIN_USERNAME', env('DB_USERNAME')),
            'password'       => env('DB_ADMIN_PASSWORD', env('DB_PASSWORD', '')),
            'charset'        => env('DB_CHARSET', 'utf8mb4'),
            'collation'      => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
        ],

        'pgsql' => [
            'driver'         => 'pgsql',
            'url'            => env('DB_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'ltp'),
            'username'       => env('DB_USERNAME'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => env('DB_CHARSET', 'utf8'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => 'public',
            'sslmode'        => 'prefer',
        ],

        'pgsql_admin' => [
            'driver'         => 'pgsql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'ltp'),
            'username'       => env('DB_ADMIN_USERNAME', env('DB_USERNAME')),
            'password'       => env('DB_ADMIN_PASSWORD', env('DB_PASSWORD', '')),
            'charset'        => env('DB_CHARSET', 'utf8'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => 'public',
            'sslmode'        => 'prefer',
        ],
    ],

    'migrations' => [
        'table'                  => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix'  => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'ltp'), '_').'_database_'),
        ],

        'default' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],
];
