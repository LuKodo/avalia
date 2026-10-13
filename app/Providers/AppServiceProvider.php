<?php

namespace App\Providers;

use App\Database\Compatibilidad\ConexionMariadbAntigua;
use App\Database\Compatibilidad\ConexionMysqlAntigua;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder as ConstructorEsquema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registrarMotorAntiguo();
    }

    /**
     * Soporte para MySQL anterior a 5.7.8 (entorno de pruebas).
     *
     * Dos ajustes, y ninguno se aplica salvo que DB_MOTOR_ANTIGUO lo pida:
     *
     *   1. El tipo `json` pasa a LONGTEXT, porque en esos motores no existe.
     *   2. Las cadenas indexadas se limitan a 191 caracteres. InnoDB en 5.5 no
     *      admite claves de mas de 767 bytes, y una cadena de 255 en utf8mb4
     *      son 1020. Con utf8 de tres bytes 255 cabria justo -765-, pero fijar
     *      el limite aqui hace que funcione con cualquiera de los dos juegos de
     *      caracteres en vez de depender de cual quedo configurado.
     *
     * Va en register() y no en boot() porque el resolutor tiene que estar
     * puesto antes de que se resuelva la primera conexion.
     *
     * Es una pasarela, no un destino: MySQL 5.5 lleva sin soporte desde
     * diciembre de 2018. Al pasar a un motor moderno se quita la bandera y el
     * esquema vuelve a columnas JSON reales.
     */
    /**
     * Desvía todo el correo saliente a una sola dirección, si se configuró.
     *
     * Sirve para probar un sistema que notifica a clientes reales sin
     * escribirles: mientras `MAIL_DESVIO_PRUEBAS` tenga valor, da igual a quién
     * vaya dirigido cada mensaje. Laravel lo resuelve con `alwaysTo`, que
     * reescribe el destinatario del sobre y deja constancia del original en el
     * asunto.
     *
     * Sin valor —producción— no hace nada en absoluto.
     */
    private function desviarCorreoDePruebas(): void
    {
        $destino = config('mail.desvio_pruebas.address');

        if (! $destino) {
            return;
        }

        Mail::alwaysTo($destino, config('mail.desvio_pruebas.name'));
    }

    private function registrarMotorAntiguo(): void
    {
        if (! filter_var(env('DB_MOTOR_ANTIGUO', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        /*
         * Los dos controladores, no solo uno.
         *
         * El resolutor se elige por nombre de controlador, y este sistema
         * define conexiones con ambos: `mysql`/`mysql_admin` y
         * `mariadb`/`mariadb_admin`. Registrar solo uno deja al otro
         * construyendo el esquema con columnas JSON, y el fallo aparece a
         * mitad de las migraciones y no al arrancar.
         */
        Connection::resolverFor('mysql', function ($conexion, $base, $prefijo, $config) {
            return new ConexionMysqlAntigua($conexion, $base, $prefijo, $config);
        });

        Connection::resolverFor('mariadb', function ($conexion, $base, $prefijo, $config) {
            return new ConexionMariadbAntigua($conexion, $base, $prefijo, $config);
        });

        // La clase directamente y no la fachada Schema: la fachada resolveria
        // el gestor de esquema, y con el una conexion a la base, dentro de
        // register(). Conectarse a la base durante el registro de servicios
        // rompe comandos que deben funcionar sin base disponible.
        ConstructorEsquema::defaultStringLength(191);
    }

    public function boot(): void
    {
        // En desarrollo, un acceso perezoso a una relación no cargada es casi
        // siempre un N+1 esperando a producción. Que falle aquí y no allá.
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        $this->desviarCorreoDePruebas();

        // Una consulta lenta en el panel de despacho se nota; una en la API de
        // integración no, porque nadie la está mirando. Por eso se registra.
        DB::whenQueryingForLongerThan(500, function ($conexion, $consulta) {
            logger()->warning('Consulta lenta', [
                'sql'      => $consulta->sql,
                'tiempo_ms' => $consulta->time,
            ]);
        });
    }
}
