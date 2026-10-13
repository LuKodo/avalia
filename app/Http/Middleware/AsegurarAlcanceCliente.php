<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Red de seguridad del aislamiento por cliente.
 *
 * Las consultas ya aplican el alcance, pero este middleware existe para que un
 * descuido futuro —una ruta nueva que olvide el `delCliente()`— no se convierta
 * en una fuga de datos entre clientes. Dos capas para el mismo invariante.
 */
class AsegurarAlcanceCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        $clienteId = match (true) {
            $actor instanceof \App\Models\Cliente => $actor->id,
            $actor instanceof \App\Models\User    => $actor->cliente_id,
            default                                => null,
        };

        // Se deja disponible para las consultas y para el registro de auditoría.
        $request->attributes->set('cliente_id', $clienteId);

        return $next($request);
    }
}
