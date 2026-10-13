<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Se intentó mover un paquete a un estado que la máquina de estados no permite.
 *
 * No se reporta como error de sistema: casi siempre es un doble envío desde un
 * dispositivo con conexión intermitente, y el mensaje debe explicar qué pasó
 * sin exponer detalle interno.
 */
class TransicionInvalidaException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error'   => 'transicion_invalida',
            'mensaje' => $this->getMessage(),
        ], 422);
    }
}
