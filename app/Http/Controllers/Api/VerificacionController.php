<?php

namespace App\Http\Controllers\Api;

use App\Services\Trazabilidad\VerificacionPublica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificación de un soporte por un tercero, sin sesión.
 *
 * Es el único punto de la API que responde sin autenticar, y por eso está
 * deliberadamente estrecho: exige el código impreso en el certificado, responde
 * lo mínimo y va limitado por frecuencia. La puerta abierta más pequeña posible
 * que aun así sirva para lo que existe.
 */
class VerificacionController
{
    public function __construct(private VerificacionPublica $verificacion) {}

    public function verificar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'guia'   => ['required', 'string', 'max:40'],
            'codigo' => ['required', 'string', 'max:32'],
        ]);

        $resultado = $this->verificacion->verificar($datos['guia'], $datos['codigo']);

        /*
         * Un certificado que no cuadra devuelve 404, no 200 con «valido: false».
         * Los códigos de estado son parte del contrato: una integración que
         * revise solo el estado HTTP no puede terminar dando por bueno un
         * documento que no lo es.
         */
        return response()->json($resultado, $resultado['valido'] ? 200 : 404);
    }
}
