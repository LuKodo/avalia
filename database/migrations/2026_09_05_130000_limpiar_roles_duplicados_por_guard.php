<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retira los roles duplicados que nacieron bajo un guard distinto del oficial.
 *
 * De dónde salieron: `Role::findOrCreate('mensajero')` sin indicar guard, dentro
 * de una petición autenticada con `auth:sanctum`. Spatie deduce el guard del que
 * esté activo, así que creaba un «mensajero» paralelo con guard `sanctum` junto
 * al de `web` que ya existía.
 *
 * Consecuencias observadas: el panel de administración contaba «Mensajero» dos
 * veces, con ocho usuarios cada uno cuando en realidad eran los mismos ocho. Y
 * la trampa de fondo: una comprobación de rol hecha bajo otro guard habría
 * mirado un rol vacío y negado el acceso sin motivo aparente.
 *
 * El origen ya está corregido —los tres puntos que crean roles pasan el guard
 * explícito—; esto limpia lo que quedó.
 *
 * Solo borra lo que es demostrablemente inofensivo: un duplicado por nombre,
 * con guard distinto del oficial y SIN un solo usuario ni permiso asignado. Un
 * duplicado con gente dentro no se toca: fusionarlo a ciegas podría dejar a
 * alguien sin acceso, y eso lo decide una persona, no una migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $oficial = config('auth.defaults.guard', 'web');

        $sospechosos = DB::table('roles as r')
            ->where('r.guard_name', '!=', $oficial)
            // Que exista otro rol con el mismo nombre bajo el guard oficial: sin
            // eso no es un duplicado, es un rol legítimo de otro guard.
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('roles as o')
                ->whereColumn('o.name', 'r.name')
                ->where('o.guard_name', $oficial))
            ->pluck('r.id');

        foreach ($sospechosos as $id) {
            $usuarios  = DB::table('model_has_roles')->where('role_id', $id)->count();
            $permisos  = DB::table('role_has_permissions')->where('role_id', $id)->count();

            if ($usuarios > 0 || $permisos > 0) {
                logger()->warning(
                    "Rol duplicado #{$id} conservado: tiene {$usuarios} usuario(s) y {$permisos} permiso(s). ".
                    'Requiere decisión manual antes de fusionarlo.'
                );

                continue;
            }

            DB::table('roles')->where('id', $id)->delete();
        }
    }

    public function down(): void
    {
        // No se recrean. Eran un accidente, y volver a fabricarlos reintroduciría
        // exactamente el problema que esta migración vino a quitar.
    }
};
