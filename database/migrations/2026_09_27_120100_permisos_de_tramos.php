<?php

use App\Database\CuentasDeLaApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos del usuario de la aplicación sobre `tramos`.
 *
 * La cuenta con la que corre la aplicación no tiene permisos generales sobre la
 * base: se conceden tabla por tabla. Una tabla nueva no funciona hasta que
 * alguien decide explícitamente qué puede hacerse con ella, y eso es lo que se
 * quiere.
 *
 * Aquí van los cuatro permisos: un tramo se crea al radicar, se asigna, se cierra
 * y puede cancelarse. La prueba del traspaso que guarda —firma y foto— vive en
 * `evidencias`, que sí está bajo el régimen de solo anexado; aquí solo queda la
 * referencia a ella.
 */
return new class extends Migration
{
    private const TABLAS = ['tramos'];

    public function up(): void
    {
        $this->aplicar(fn (string $t) => "GRANT SELECT, INSERT, UPDATE, DELETE ON `%s`.`{$t}` TO %s");
    }

    public function down(): void
    {
        $this->aplicar(fn (string $t) => "REVOKE ALL PRIVILEGES ON `%s`.`{$t}` FROM %s");
    }

    /** @param callable(string): string $plantilla */
    private function aplicar(callable $plantilla): void
    {
        $conexion = Schema::getConnection();
        $base     = $conexion->getDatabaseName();
        $usuarios = CuentasDeLaApp::listar($conexion);

        if ($usuarios === []) {
            // Sin cuenta de aplicación no hay nada que conceder. Ocurre en una
            // base recién creada a mano; no es motivo para fallar la migración.
            return;
        }

        foreach (self::TABLAS as $t) {
            foreach ($usuarios as $u) {
                try {
                    $conexion->unprepared(sprintf($plantilla($t), $base, $u));
                } catch (\Throwable $e) {
                    logger()->warning("No se pudo ajustar el permiso sobre {$t} para {$u}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        /*
         * Aqui no va FLUSH PRIVILEGES.
         *
         * GRANT y REVOKE ya actualizan los permisos en memoria; el vaciado solo
         * hace falta tras tocar a mano las tablas del catalogo. A cambio exige
         * el privilegio RELOAD, que es de servidor entero y que la cuenta de
         * migraciones no tiene ni debe tener: pedirlo obligaria a ampliarle los
         * permisos para una llamada que no cambia nada.
         */
    }

};
