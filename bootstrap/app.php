<?php

use App\Http\Middleware\AsegurarAlcanceCliente;
use App\Http\Middleware\RegistrarAccesoDatosSensibles;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/salud',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // La API es SIN ESTADO: autentica con token bearer, no con cookie de
        // sesión. Es un requisito de la aplicación de campo, que tiene que
        // seguir autenticada tras cerrarse y reabrirse sin señal — una cookie
        // de sesión no sobrevive a eso. Por lo mismo no se activa el modo SPA
        // de Sanctum (`EnsureFrontendRequestsAreStateful`), que exigiría CSRF.
        $middleware->alias([
            'alcance.cliente' => AsegurarAlcanceCliente::class,
            'auditar.acceso'  => RegistrarAccesoDatosSensibles::class,
            // Propio, no el de spatie: los tokens de integración pertenecen al
            // modelo Cliente, que no tiene roles, y spatie respondía con un 500
            // en vez de un 403. Ver App\Http\Middleware\ExigirRol.
            'rol'             => \App\Http\Middleware\ExigirRol::class,
            'permiso'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);

        // La API es sin estado y con limite de tasa por token, no por IP unicamente:
        // un hospital detras de NAT no puede quedar bloqueado por el trafico de otro.
        $middleware->throttleApi('api');

        /*
         * A un invitado en /api no se le redirige a ninguna parte.
         *
         * Devolver `null` aqui hace que el middleware lance la excepcion de
         * autenticacion en vez de intentar un `route('login')` que no existe
         * -el ingreso vive en el frontend-, y esa excepcion sale como 401 JSON.
         * Antes, cualquier peticion sin token abierta desde un navegador
         * terminaba en 500 «Route [login] not defined»: un integrador probando
         * una direccion leia un error del servidor donde debia leer «no
         * autenticado», y eso manda a buscar la averia al lado equivocado.
         */
        $middleware->redirectGuestsTo(
            fn (\Illuminate\Http\Request $peticion) => $peticion->is('api/*') ? null : '/ingreso'
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Nunca exponer detalle interno en respuestas de API.
        $exceptions->dontReport([
            \App\Exceptions\TransicionInvalidaException::class,
        ]);

        /*
         * Todo lo que cuelgue de /api responde JSON, aunque quien llame no lo
         * haya pedido en la cabecera `Accept`.
         *
         * Sin esto, una petición sin token abierta desde un navegador terminaba
         * en 500: Laravel intenta redirigir al invitado a una ruta llamada
         * `login`, que en esta aplicación no existe —el ingreso vive en el
         * frontend—. Un integrador que prueba una dirección pegándola en la
         * barra veía un error del servidor donde debía leer «no autenticado»,
         * y eso manda a buscar la avería al lado equivocado.
         *
         * Con la cabecera correcta la respuesta ya era 401; esto lo vuelve
         * cierto también sin ella.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (\Illuminate\Http\Request $peticion) => $peticion->is('api/*') || $peticion->expectsJson()
        );
    })->create();
