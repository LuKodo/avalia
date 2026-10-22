<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Ingreso y cierre de sesión.
 *
 * El token que se emite lleva las habilidades del rol, no un permiso universal:
 * un token de mensajero no puede consultar el tablero ni la bandeja de despacho
 * aunque alguien lo extraiga del dispositivo.
 */
class AutenticacionController extends Controller
{
    public function ingresar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'dispositivo' => ['nullable', 'string', 'max:120'],
        ]);

        // Límite por combinación de correo e IP. Solo por IP dejaría fuera a
        // toda una clínica detrás del mismo NAT cuando una persona se equivoca;
        // solo por correo permitiría repartir el ataque entre muchas IP.
        $llave = 'ingreso:'.mb_strtolower($datos['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($llave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Espere '
                    .RateLimiter::availableIn($llave).' segundos.',
            ])->status(429);
        }

        $usuario = User::where('email', $datos['email'])->first();

        // Mensaje idéntico en ambos casos: distinguir «no existe» de «clave
        // incorrecta» le confirma a un atacante qué correos son válidos.
        if ($usuario === null || ! Hash::check($datos['password'], $usuario->password)) {
            RateLimiter::hit($llave, 300);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        if (! $usuario->activo) {
            throw ValidationException::withMessages([
                'email' => 'Esta cuenta está desactivada. Comuníquese con el área de TIC.',
            ]);
        }

        RateLimiter::clear($llave);

        $usuario->forceFill([
            'ultimo_acceso_en' => now(),
            'ultima_ip'        => $request->ip(),
        ])->save();

        // Un token por dispositivo: revocar el teléfono perdido de un mensajero
        // no puede obligarlo a volver a entrar en todos los demás.
        $nombreToken = $datos['dispositivo'] ?? 'navegador';

        $usuario->tokens()->where('name', $nombreToken)->delete();

        $token = $usuario->createToken($nombreToken, $this->habilidades($usuario), now()->addDays(30));

        return response()->json([
            'token'   => $token->plainTextToken,
            'expira'  => $token->accessToken->expires_at?->toIso8601String(),
            'usuario' => $this->perfil($usuario),
        ]);
    }

    public function yo(Request $request): JsonResponse
    {
        return response()->json(['usuario' => $this->perfil($request->user())]);
    }

    public function salir(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['mensaje' => 'Sesión cerrada.']);
    }

    /**
     * Habilidades del token según el rol. El principio de menor privilegio se
     * aplica también al token, no solo a la sesión.
     *
     * @return array<int, string>
     */
    private function habilidades(User $usuario): array
    {
        return match (true) {
            $usuario->hasRole('administrador') => ['*'],
            $usuario->hasRole('operador')      => ['despacho:*', 'consulta:*', 'tablero:ver'],
            $usuario->hasRole('mensajero')     => ['campo:*'],
            $usuario->hasRole('cliente')       => ['consulta:*', 'ordenes:crear', 'tablero:ver'],
            default                            => [],
        };
    }

    /** @return array<string, mixed> */
    private function perfil(User $usuario): array
    {
        $usuario->loadMissing('cliente', 'mensajero');

        $rol = $usuario->getRoleNames()->first();

        return [
            'id'           => $usuario->id,
            'nombre'       => $usuario->nombre,
            'email'        => $usuario->email,
            'rol'          => $rol,
            'cliente'      => $usuario->cliente?->razon_social,
            'cliente_id'   => $usuario->cliente_id,
            // La etiqueta que el cliente le da a SU referencia. La interfaz la
            // usa literalmente: si el hospital dice «radicado», la caja de
            // búsqueda dice «radicado».
            'etiqueta_referencia' => $usuario->cliente?->etiqueta_referencia,
            'mensajero_id' => $usuario->mensajero?->id,
            'inicio'       => match ($rol) {
                // Al tablero, no a la lista: responde antes la pregunta con la
                // que uno abre la aplicación, que es adónde va ahora.
                'mensajero' => '/jornada',
                'cliente'   => '/rastreo',
                default     => '/tablero',
            },
        ];
    }
}
