<?php

namespace App\Http\Controllers\Api;

use App\Models\Sede;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Puntos de recogida y entrega que el cliente propone y Avalia aprueba.
 *
 * El cliente pone la coordenada exacta; nosotros la revisamos. Mientras no
 * esté aprobada no se puede radicar contra ella, y esa es toda la gracia: una
 * coordenada que nadie miró convierte el trazado de ruta y el aviso de desvío
 * en adivinanza.
 */
class PuntoPactadoController extends Controller
{
    public const PENDIENTE = 'pendiente';
    public const APROBADA  = 'aprobada';
    public const RECHAZADA = 'rechazada';

    /**
     * GET /puntos
     *
     * Los del cliente que pregunta. La operación los ve todos.
     */
    public function index(Request $peticion): JsonResponse
    {
        $usuario = $peticion->user();
        $esOperacion = $usuario->hasRole(['administrador', 'operador']);

        $puntos = Sede::query()
            ->with('cliente:id,razon_social')
            ->when(! $esOperacion, fn ($q) => $q->where('cliente_id', $usuario->cliente_id))
            ->when($peticion->query('estado'), fn ($q, $e) => $q->where('estado_aprobacion', $e))
            ->orderByRaw("FIELD(estado_aprobacion, 'pendiente', 'rechazada', 'aprobada')")
            ->orderBy('nombre')
            ->get()
            ->map(fn (Sede $s) => $this->pintar($s))
            ->all();

        return response()->json(['puntos' => $puntos]);
    }

    /**
     * POST /puntos
     *
     * El cliente propone uno nuevo. Nace PENDIENTE y desactivado: no se puede
     * radicar contra él hasta que alguien de Avalia lo mire.
     */
    public function proponer(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'nombre'            => ['required', 'string', 'max:120'],
            'direccion'         => ['required', 'string', 'max:200'],
            'ciudad'            => ['required', 'string', 'max:80'],
            // Obligatorias, no opcionales. El punto entero existe para tener la
            // coordenada: admitirlo sin ella seria volver al problema.
            'latitud'           => ['required', 'numeric', 'between:-90,90'],
            'longitud'          => ['required', 'numeric', 'between:-180,180'],
            'detalle_acceso'    => ['nullable', 'string', 'max:255'],
            'contacto_nombre'   => ['nullable', 'string', 'max:120'],
            'contacto_telefono' => ['nullable', 'string', 'max:40'],
        ]);

        $usuario = $peticion->user();
        $clienteId = $usuario->cliente_id;

        // La operación puede proponer a nombre de un cliente; un cliente, solo
        // del suyo. Sin esto, un cliente podría crearle puntos a otro.
        if ($clienteId === null) {
            $datos2 = $peticion->validate(['cliente_id' => ['required', 'integer', 'exists:clientes,id']]);
            $clienteId = $datos2['cliente_id'];
        }

        /*
         * Los campos de revision se escriben con `forceFill` y NO estan en
         * `$fillable`. No es una comodidad: si `estado_aprobacion` fuera
         * rellenable, bastaria con que el cliente mandara
         * `"estado_aprobacion": "aprobada"` en este mismo cuerpo para
         * APROBARSE SU PROPIO PUNTO y saltarse la revision entera.
         *
         * Lo que el cliente propone va por `$datos`, ya validado; lo que
         * decide Avalia se pone aqui y solo aqui.
         */
        $punto = new Sede($datos);

        $punto->forceFill([
            'cliente_id'            => $clienteId,
            'tipo'                  => 'punto',
            'activo'                => false,
            'coordenada_verificada' => false,
            'estado_aprobacion'     => self::PENDIENTE,
            'propuesta_por'         => $usuario->id,
        ])->save();

        return response()->json($this->pintar($punto->load('cliente:id,razon_social')), 201);
    }

    /**
     * POST /puntos/{sede}/revisar
     *
     * Avalia aprueba o rechaza. Rechazar EXIGE motivo: un «no» sin explicación
     * deja al cliente sin saber qué corregir, y volverá a mandar lo mismo.
     */
    public function revisar(Request $peticion, Sede $sede): JsonResponse
    {
        $datos = $peticion->validate([
            'decision' => ['required', 'in:aprobada,rechazada'],
            'motivo'   => ['required_if:decision,rechazada', 'nullable', 'string', 'max:255'],
        ]);

        abort_if(
            $sede->estado_aprobacion === self::APROBADA && $datos['decision'] === self::APROBADA,
            422,
            'Ese punto ya estaba aprobado.'
        );

        $aprobada = $datos['decision'] === self::APROBADA;

        $sede->forceFill([
            'estado_aprobacion'     => $datos['decision'],
            // Aprobar el punto ES verificar su coordenada: son el mismo acto.
            'coordenada_verificada' => $aprobada,
            'activo'                => $aprobada,
            'revisada_por'          => $peticion->user()->id,
            'revisada_en'           => now(),
            'motivo_revision'       => $datos['motivo'] ?? null,
        ])->save();

        return response()->json($this->pintar($sede->fresh()->load('cliente:id,razon_social')));
    }

    private function pintar(Sede $s): array
    {
        return [
            'id'        => $s->id,
            'nombre'    => $s->nombre,
            'direccion' => $s->direccion,
            'ciudad'    => $s->ciudad,
            'latitud'   => $s->latitud === null ? null : (float) $s->latitud,
            'longitud'  => $s->longitud === null ? null : (float) $s->longitud,
            'detalle_acceso'    => $s->detalle_acceso,
            'contacto_nombre'   => $s->contacto_nombre,
            'contacto_telefono' => $s->contacto_telefono,
            'cliente'   => $s->cliente?->razon_social,
            'estado'    => $s->estado_aprobacion,
            'motivo'    => $s->motivo_revision,
            'revisada_en' => $s->revisada_en?->toIso8601String(),
            'usable'    => $s->estado_aprobacion === self::APROBADA && $s->activo,
        ];
    }
}
