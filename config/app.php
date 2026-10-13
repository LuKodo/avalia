<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [

    'name' => env('APP_NAME', 'Avalia Logística'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |---------------------------------------------------------------------------
    | Zona horaria
    |---------------------------------------------------------------------------
    | La operación es colombiana y la hora de entrega es un dato que el cliente
    | lee en un acta y cruza contra su propio sistema. Si la aplicación corre en
    | UTC, el acta dice que el medicamento llegó a las 18:45 cuando llegó a las
    | 13:45, y eso convierte el soporte en un problema en vez de una prueba.
    |
    | Colombia no tiene horario de verano: `America/Bogota` es UTC−5 todo el año,
    | así que guardar en hora local aquí no introduce las ambigüedades que sí
    | tendría en un país con cambio de hora. Si algún día la operación cruza a
    | otro huso, la salida es guardar en UTC y convertir al mostrar — no cambiar
    | este valor sobre datos ya existentes.
    */
    'timezone' => env('APP_TIMEZONE', 'America/Bogota'),

    'locale' => env('APP_LOCALE', 'es'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'es'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'es_CO'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    /*
    | Claves anteriores. Permiten rotar APP_KEY sin dejar ilegibles los datos ya
    | cifrados —el documento del receptor, entre otros—: se descifra con la
    | clave vieja y se vuelve a cifrar con la nueva.
    */
    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store'  => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    'providers' => ServiceProvider::defaultProviders()->merge([
        // Los propios se registran en bootstrap/providers.php
    ])->toArray(),

    'aliases' => Facade::defaultAliases()->merge([
        //
    ])->toArray(),

];
