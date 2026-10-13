<?php

use App\Database\CuentasDeLaApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos del usuario de la aplicación sobre las tablas recién creadas.
 *
 * La cuenta con la que corre la aplicación no tiene permisos generales sobre la
 * base: se conceden tabla por tabla. Por eso una tabla nueva no funciona hasta
 * que alguien decide explícitamente qué puede hacerse con ella — y eso es lo
 * que se quiere. La alternativa, un GRANT ALL cómodo, convierte cualquier
 * inyección en control total.
 *
 * Dos niveles, como en el resto del esquema:
 *
 *   · Lectura y escritura completa para lo que la operación administra.
 *   · Solo consultar y anexar para lo que puede terminar siendo prueba.
 */
return new class extends Migration
{
    /** Tablas que la operación administra de principio a fin. */
    private const COMPLETO = ['destinatarios', 'contactos', 'pqrs'];

    /**
     * Tablas de solo anexado.
     *
     * Un adjunto de una queja puede acabar en un expediente. Si la aplicación
     * pudiera modificarlo o borrarlo, la huella SHA-256 que lo acompaña dejaría
     * de significar nada: siempre coincidiría con lo último que alguien escribió.
     */
    private const ANEXAR = ['pqrs_adjuntos'];

    public function up(): void
    {
        $this->aplicar(
            fn (string $t) => "GRANT SELECT, INSERT, UPDATE, DELETE ON `%s`.`{$t}` TO %s",
            self::COMPLETO
        );

        $this->aplicar(
            fn (string $t) => "GRANT SELECT, INSERT ON `%s`.`{$t}` TO %s",
            self::ANEXAR
        );
    }

    public function down(): void
    {
        $this->aplicar(
            fn (string $t) => "REVOKE ALL PRIVILEGES ON `%s`.`{$t}` FROM %s",
            array_merge(self::COMPLETO, self::ANEXAR)
        );
    }

    /**
     * @param  callable(string): string  $plantilla
     * @param  array<int, string>  $tablas
     */
    private function aplicar(callable $plantilla, array $tablas): void
    {
        $conexion = Schema::getConnection();
        $base     = $conexion->getDatabaseName();
        $usuarios = CuentasDeLaApp::listar($conexion);

        if ($usuarios === []) {
            // Sin cuenta de aplicación no hay nada que conceder. Ocurre en una
            // base recién creada a mano; no es motivo para fallar la migración.
            return;
        }

        foreach ($tablas as $t) {
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
