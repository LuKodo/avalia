<?php

namespace App\Policies;

use App\Models\Orden;
use App\Models\User;

/**
 * Aislamiento por cliente.
 *
 * La regla es simple y no admite matices: un usuario de un cliente ve
 * exclusivamente las órdenes de su cliente. Se verifica en el modelo y no en la
 * interfaz, porque una interfaz que oculta no es una interfaz que protege.
 */
class OrdenPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->activo;
    }

    public function view(User $usuario, Orden $orden): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        if ($usuario->hasAnyRole(['administrador', 'operador'])) {
            return true;
        }

        if ($usuario->esCliente()) {
            return $usuario->cliente_id === $orden->cliente_id;
        }

        // El mensajero solo ve órdenes con paquetes asignados a él, y solo
        // mientras estén en curso. No tiene por qué conocer el histórico.
        if ($usuario->hasRole('mensajero')) {
            return $orden->paquetes()
                ->where('mensajero_id', $usuario->mensajero?->id)
                ->exists();
        }

        return false;
    }

    public function create(User $usuario): bool
    {
        return $usuario->activo
            && ($usuario->esCliente() || $usuario->hasAnyRole(['administrador', 'operador']));
    }

    /** Nadie edita una orden entregada: se corrige con una novedad, no borrando. */
    public function update(User $usuario, Orden $orden): bool
    {
        return $usuario->hasAnyRole(['administrador', 'operador'])
            && ! $orden->estaEntregada();
    }

    /** El documento completo del receptor solo lo ve el cliente dueño del envío. */
    public function verDatosReceptor(User $usuario, Orden $orden): bool
    {
        return ($usuario->esCliente() && $usuario->cliente_id === $orden->cliente_id)
            || $usuario->hasRole('administrador');
    }

    public function exportar(User $usuario, Orden $orden): bool
    {
        return $this->view($usuario, $orden);
    }
}
