<?php

namespace App\Database;

use Illuminate\Database\Connection;

/**
 * Las cuentas con las que la aplicacion se conecta a la base.
 *
 * Existe para que las migraciones que conceden permisos tabla por tabla no
 * tengan que averiguarlo cada una por su cuenta. Antes habia tres copias del
 * mismo metodo, con el mismo fallo en las tres.
 */
class CuentasDeLaApp
{
    /**
     * Devuelve las cuentas en forma `usuario`@`anfitrion`, listas para un GRANT.
     *
     * Se consulta mysql.user porque el mismo usuario puede estar declarado para
     * varios anfitriones -para el motor, 'x'@'localhost' y 'x'@'127.0.0.1' son
     * cuentas DISTINTAS- y conceder sobre uno solo deja el otro sin permisos,
     * con un error que no explica por que.
     *
     * Pero esa consulta puede estar prohibida, y con razon: la cuenta que corre
     * las migraciones tiene permisos sobre la base de la aplicacion y nada mas,
     * y mysql.user guarda los hashes de contrasena de TODO el servidor. Cuando
     * el motor la rechaza no es un fallo que haya que arreglar dando mas
     * permisos: se cae a la lista de anfitriones habituales y sigue. Los GRANT
     * que sobren fallaran uno a uno, y de eso ya se encarga quien llama.
     *
     * @return array<int, string>
     */
    public static function listar(Connection $conexion): array
    {
        /*
         * La cuenta sale de la conexion de la APLICACION, y esa se deduce del
         * nombre de la conexion que nos pasan quitandole el sufijo `_admin`.
         *
         * No vale `database.default`: `migrate --database=mysql_admin` cambia
         * la conexion por defecto a la administrativa mientras dura el comando,
         * asi que preguntarle a ella devuelve `avalia_admin` -la cuenta
         * equivocada- y los permisos se concederian a quien no toca.
         *
         * Tampoco vale fijar `mariadb` a mano, porque en un servidor MySQL la
         * conexion se llama `mysql`.
         */
        $nombreApp = preg_replace('/_admin$/', '', $conexion->getName());
        $cuenta    = config("database.connections.{$nombreApp}.username");

        if (! $cuenta) {
            return [];
        }

        try {
            $filas = $conexion->select(
                'SELECT User, Host FROM mysql.user WHERE User = ?',
                [$cuenta]
            );

            if ($filas !== []) {
                return array_map(
                    static fn ($f) => sprintf('`%s`@`%s`', $f->User, $f->Host),
                    $filas
                );
            }
        } catch (\Throwable $e) {
            logger()->info(
                'No se pudo leer mysql.user para enumerar las cuentas de la aplicacion; '.
                'se usaran los anfitriones habituales.',
                ['error' => $e->getMessage()]
            );
        }

        return array_map(
            static fn (string $anfitrion) => sprintf('`%s`@`%s`', $cuenta, $anfitrion),
            ['localhost', '127.0.0.1']
        );
    }
}
