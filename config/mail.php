<?php

return [

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

        'smtp' => [
            'transport'  => 'smtp',
            'scheme'     => env('MAIL_SCHEME'),
            'url'        => env('MAIL_URL'),
            'host'       => env('MAIL_HOST', '127.0.0.1'),
            'port'       => env('MAIL_PORT', 2525),
            'username'   => env('MAIL_USERNAME'),
            'password'   => env('MAIL_PASSWORD'),
            'timeout'    => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'log' => [
            'transport' => 'log',
            'channel'   => env('MAIL_LOG_CHANNEL'),
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers'   => ['smtp', 'log'],
            'retry_after' => 60,
        ],

        'array' => [
            'transport' => 'array',
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-responder@avalialogistica.com'),
        'name'    => env('MAIL_FROM_NAME', 'Avalia Logística'),
    ],

    /*
     * Desvio de TODO el correo saliente a una sola direccion.
     *
     * Es la forma de probar un sistema que notifica a clientes reales sin
     * escribirles. Mientras esta puesto, da igual a quien vaya dirigido cada
     * mensaje: todos llegan aqui, y el asunto conserva el destinatario
     * original para que se pueda comprobar que habria ido a quien debia.
     *
     * VACIO en produccion. Dejarlo puesto significa que ningun cliente recibe
     * nada y que las direcciones de todos acaban en un unico buzon.
     */
    'desvio_pruebas' => [
        'address' => env('MAIL_DESVIO_PRUEBAS'),
        'name'    => env('MAIL_DESVIO_NOMBRE', 'Pruebas Avalia'),
    ],
];
