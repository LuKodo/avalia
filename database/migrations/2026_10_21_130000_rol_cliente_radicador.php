<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El perfil de cliente que solo radica.
 *
 * En un servicio farmacéutico quien teclea las guías rara vez es quien responde
 * por ellas: lo hace auxiliar de farmacia, un practicante, alguien de turno. Lo
 * que el cliente pidió es poder darles acceso sin darles también el botón de
 * cancelar, porque una guía cancelada por error a las dos de la tarde es un
 * medicamento que no sale ese día.
 *
 * Es un rol y no un permiso suelto a propósito: los roles ya deciden qué
 * pantallas se ven y qué rutas responden, y añadir un segundo mecanismo en
 * paralelo —permisos para unas cosas, roles para otras— es como se acaba sin
 * saber quién puede qué.
 *
 * `cliente_radicador` hace todo lo que `cliente` menos cancelar. La diferencia
 * vive en el middleware de la ruta de cancelación, que no lo incluye.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existe = DB::table('roles')
            ->where('name', 'cliente_radicador')
            ->where('guard_name', 'web')
            ->exists();

        if ($existe) {
            return;
        }

        DB::table('roles')->insert([
            'name'       => 'cliente_radicador',
            // Guard explícito: el resto de roles se creó con 'web' y spatie
            // trata dos guards distintos como dos roles sin avisar.
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        /*
         * Solo se retira si no lo tiene nadie. Borrarlo con usuarios asignados
         * los dejaría sin rol ninguno —es decir, sin poder entrar— y una vuelta
         * atrás no debería poder echar a nadie de la plataforma.
         */
        $rol = DB::table('roles')
            ->where('name', 'cliente_radicador')
            ->where('guard_name', 'web')
            ->first();

        if ($rol === null) {
            return;
        }

        $asignados = DB::table('model_has_roles')->where('role_id', $rol->id)->count();

        if ($asignados === 0) {
            DB::table('roles')->where('id', $rol->id)->delete();
        }
    }
};
