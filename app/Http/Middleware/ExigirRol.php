<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que quien llama sea una PERSONA con uno de los roles indicados.
 *
 * Reemplaza al middleware de roles de spatie en las rutas de Avalia por una razón
 * concreta: los tokens de integración pertenecen al modelo `Cliente`, que no
 * tiene roles ni los tendrá. Al pasarle un cliente, spatie lanzaba un error de
 * tipo y la respuesta salía como un 500 con traza en vez de un 403 limpio.
 *
 * Bloquear estaba bien; la forma de bloquear, no. Un 500 le dice a quien está
 * probando la API que rompió algo, cuando lo que pasó es que pidió lo que no
 * le corresponde.
 */
class ExigirRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $actor = $request->user();

        if ($actor === null) {
            return response()->json([
                'error'   => 'no_autenticado',
                'mensaje' => 'Esta operación exige iniciar sesión.',
            ], 401);
        }

        // Un token de integración es una máquina: nunca opera el despacho ni la
        // administración, por diseño y no por descuido.
        if (! $actor instanceof User) {
            return response()->json([
                'error'   => 'operacion_no_disponible_para_integraciones',
                'mensaje' => 'Los tokens de integración solo permiten consultar envíos y radicar. '
                    .'Las operaciones internas exigen una cuenta de usuario.',
            ], 403);
        }

        if (! $actor->activo) {
            return response()->json([
                'error'   => 'cuenta_desactivada',
                'mensaje' => 'Esta cuenta está desactivada. Comuníquese con el área de TIC.',
            ], 403);
        }

        // Un rol puede venir como «administrador|operador» desde la ruta.
        $permitidos = collect($roles)
            ->flatMap(fn (string $r) => explode('|', $r))
            ->filter()
            ->all();

        if ($permitidos !== [] && ! $actor->hasAnyRole($permitidos)) {
            return response()->json([
                'error'   => 'sin_permiso',
                'mensaje' => 'Su rol no tiene acceso a esta operación.',
                'requiere' => $permitidos,
            ], 403);
        }

        return $next($request);
    }
}
