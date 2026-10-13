<?php

namespace App\Policies;

use App\Models\Paquete;
use App\Models\User;

class PaquetePolicy
{
    public function view(User $usuario, Paquete $paquete): bool
    {
        return app(OrdenPolicy::class)->view($usuario, $paquete->orden);
    }

    /** Solo el mensajero que tiene el paquete asignado puede operarlo en campo. */
    public function operarEnCampo(User $usuario, Paquete $paquete): bool
    {
        return $usuario->hasRole('mensajero')
            && $usuario->mensajero !== null
            && $paquete->mensajero_id === $usuario->mensajero->id;
    }

    public function asignar(User $usuario, Paquete $paquete): bool
    {
        return $usuario->hasAnyRole(['administrador', 'operador'])
            && ! $paquete->estado->esTerminal();
    }
}
