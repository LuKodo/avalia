<?php

namespace App\Http\Middleware;

use App\Models\AuditoriaAcceso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja rastro de cada acceso a datos personales sensibles.
 *
 * Se aplica a las rutas que devuelven documento del receptor, evidencias
 * fotográficas o exportaciones. Es el registro que responde a la pregunta que sí
 * hace una auditoría de protección de datos: quién consultó qué y cuándo.
 */
class RegistrarAccesoDatosSensibles
{
    public function handle(Request $request, Closure $next, string $accion = 'consulta'): Response
    {
        $respuesta = $next($request);

        // Solo se registra el acceso efectivo: un 403 o un 404 no expusieron
        // nada, y anotarlos como acceso ensucia la bitácora que sí importa.
        if ($respuesta->isSuccessful()) {
            AuditoriaAcceso::registrar(
                accion: $accion,
                recursoTipo: $request->route()?->getName() ?? $request->path(),
                recursoId: null,
                justificacion: $request->input('justificacion'),
            );
        }

        return $respuesta;
    }
}
