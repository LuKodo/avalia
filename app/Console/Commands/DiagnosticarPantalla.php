<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Reproduce en el servidor la petición que falla y enseña POR QUÉ.
 *
 * En producción `APP_DEBUG` está en false —y así debe seguir: con true, una
 * pantalla de error de Laravel muestra la configuración, incluida la clave de
 * la base—. El precio es que el navegador solo dice «Server Error», que no le
 * sirve a nadie.
 *
 * Esto ejecuta la misma ruta, como el mismo usuario, dentro del servidor, y
 * escupe la excepción completa en la consola. Nada sale a internet y no hay
 * que tocar la configuración para averiguarlo.
 *
 *   php artisan avalia:diagnosticar ordenes/catalogo --usuario=correo@cliente.com
 */
class DiagnosticarPantalla extends Command
{
    protected $signature = 'avalia:diagnosticar
                            {ruta=ordenes/catalogo : Ruta de la API, sin «api/v1/»}
                            {--usuario= : Correo del usuario con el que falla}
                            {--metodo=GET : Verbo HTTP}';

    protected $description = 'Ejecuta una ruta de la API como un usuario y muestra el error real, sin activar APP_DEBUG';

    public function handle(): int
    {
        $correo = $this->option('usuario');

        if (! $correo) {
            $this->error('Indique --usuario=correo: el fallo casi siempre depende de los datos de ESE cliente.');

            return self::FAILURE;
        }

        $usuario = User::where('email', $correo)->first();

        if ($usuario === null) {
            $this->error("No hay ningún usuario con el correo «{$correo}».");

            return self::FAILURE;
        }

        $ruta = '/api/v1/'.ltrim($this->argument('ruta'), '/');

        $this->line('');
        $this->line("  Usuario : {$usuario->nombre} <{$usuario->email}>");
        $this->line('  Cliente : '.($usuario->cliente?->razon_social ?? '— interno —'));
        $this->line("  Ruta    : {$this->option('metodo')} {$ruta}");
        $this->line('');

        /*
         * Se emite un token de verdad y se manda en la cabecera.
         *
         * `setUserResolver` no sirve: la ruta pasa por `auth:sanctum`, que
         * resuelve al usuario por su cuenta y devolvía 401 sin llegar nunca al
         * controlador —es decir, diagnosticaba el diagnóstico—. Con un token
         * real se recorre exactamente el mismo camino que recorre el navegador.
         *
         * El token se borra al terminar, pase lo que pase.
         */
        $token = $usuario->createToken('diagnostico')->plainTextToken;

        $peticion = Request::create($ruta, strtoupper($this->option('metodo')));
        $peticion->headers->set('Accept', 'application/json');
        $peticion->headers->set('Authorization', 'Bearer '.$token);

        /*
         * Va por el nucleo HTTP completo, no por `Route::dispatch`.
         *
         * `Route::dispatch` ejecuta solo los middleware de la ruta y se salta
         * los globales y los del grupo `api` —limitador de peticiones, alcance
         * de cliente, manejo de excepciones—. Con el, este comando respondia
         * 200 sobre una pantalla que en el navegador daba 500: diagnosticaba
         * un camino que nadie recorre. El nucleo recorre exactamente el mismo
         * que el navegador.
         */
        try {
            $respuesta = app(\Illuminate\Contracts\Http\Kernel::class)->handle($peticion);

            $codigo = $respuesta->getStatusCode();

            if ($codigo < 400) {
                $this->info("  Respondio {$codigo} — la pantalla funciona para este usuario.");
                $this->line('  '.substr((string) $respuesta->getContent(), 0, 400));
                $this->line('');

                return self::SUCCESS;
            }

            $this->error("  Respondio {$codigo}");
            $this->line('');

            /*
             * El nucleo captura la excepcion y la convierte en respuesta, asi
             * que aqui ya no llega al `catch`. Se saca del manejador, que la
             * guardo al registrarla: es la unica forma de ver el motivo real
             * sin encender APP_DEBUG.
             */
            $this->mostrarUltimoError();

            $this->line('  Cuerpo: '.substr((string) $respuesta->getContent(), 0, 300));
            $this->line('');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('  FALLÓ: '.$e->getMessage());
            $this->line('');
            $this->line('  En: '.$e->getFile().':'.$e->getLine());
            $this->line('');
            $this->line('  Primeras llamadas:');

            foreach (array_slice($e->getTrace(), 0, 8) as $i => $t) {
                $donde = ($t['file'] ?? '?').':'.($t['line'] ?? '?');

                // Las de vendor no dicen nada útil; las del proyecto sí.
                if (str_contains($donde, 'vendor')) {
                    continue;
                }

                $this->line("    #{$i} {$donde}  ".($t['function'] ?? ''));
            }

            $this->line('');

            return self::FAILURE;
        } finally {
            $usuario->tokens()->where('name', 'diagnostico')->delete();
        }
    }

    /**
     * El ultimo error registrado, leido del propio archivo de bitacora.
     *
     * Se lee el fichero y no se intercepta la excepcion porque el nucleo ya la
     * convirtio en una respuesta 500 antes de devolverla: para cuando este
     * comando la ve, el objeto se perdio. Lo que si quedo es la linea del
     * registro, con su traza.
     */
    private function mostrarUltimoError(): void
    {
        $ficheros = glob(storage_path('logs/*.log')) ?: [];

        if ($ficheros === []) {
            $this->warn('  No hay archivos de registro en storage/logs.');

            return;
        }

        usort($ficheros, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        $lineas = @file($ficheros[0]) ?: [];
        $ultimo = null;

        foreach ($lineas as $i => $linea) {
            if (str_contains($linea, '.ERROR:')) {
                $ultimo = $i;
            }
        }

        if ($ultimo === null) {
            $this->warn('  No se encontro ninguna linea ERROR en '.basename($ficheros[0]));

            return;
        }

        $this->line('  Lo que quedo en '.basename($ficheros[0]).':');
        $this->line('');

        foreach (array_slice($lineas, $ultimo, 12) as $linea) {
            $texto = rtrim($linea);

            if (strlen($texto) > 220) {
                $texto = substr($texto, 0, 220).'…';
            }

            $this->line('    '.$texto);
        }

        $this->line('');
    }
}
