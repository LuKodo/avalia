<?php

namespace App\Services\Integraciones;

use App\Jobs\EntregarWebhook;
use App\Models\EntregaWebhook;
use App\Models\EventoPaquete;
use App\Models\WebhookCliente;
use Illuminate\Support\Facades\Log;

/**
 * Avisa a los sistemas de los clientes cuando cambia algo de su envío.
 *
 * Lo que aquí se hace es CREAR la fila de entrega y encolar el trabajo; el
 * envío ocurre fuera de la petición. La fila se escribe siempre, aunque el
 * cliente esté caído: es el registro de que lo intentamos, y es lo que se
 * enseña cuando alguien dice «a mí nunca me avisaron».
 *
 * Nunca lanza. Un fallo notificando no puede tumbar la entrega que lo provocó:
 * el mensajero ya hizo su trabajo y el paquete ya está entregado. Si esto
 * revienta, se anota y la operación sigue.
 */
class DespachadorWebhooks
{
    /**
     * Notifica el cambio de estado de un paquete.
     *
     * Solo a los webhooks del cliente dueño del envío, y solo a los que
     * declararon escuchar ese evento.
     */
    public function porEvento(EventoPaquete $evento): void
    {
        try {
            $paquete = $evento->paquete()->with('orden.cliente', 'orden.referencias')->first();

            if ($paquete === null || $paquete->orden === null) {
                return;
            }

            $nombreEvento = 'paquete.'.$evento->estado_nuevo->value;

            $webhooks = WebhookCliente::where('cliente_id', $paquete->orden->cliente_id)
                ->where('activo', true)
                ->get()
                ->filter(fn (WebhookCliente $w) => $w->escucha($nombreEvento));

            if ($webhooks->isEmpty()) {
                return;
            }

            $payload = $this->carga($evento, $paquete, $nombreEvento);

            foreach ($webhooks as $webhook) {
                $entrega = EntregaWebhook::create([
                    'webhook_id' => $webhook->id,
                    'orden_id'   => $paquete->orden->id,
                    'evento'     => $nombreEvento,
                    'payload'    => $payload,
                    'estado'     => 'pendiente',
                    'intento'    => 0,
                ]);

                EntregarWebhook::dispatch($entrega->id);
            }
        } catch (\Throwable $e) {
            // Deliberadamente tragado: notificar es secundario a operar.
            Log::error('No se pudo encolar la notificación del evento.', [
                'evento_id' => $evento->id ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lo que se le manda al cliente.
     *
     * Sin datos del receptor ni comerciales: un webhook viaja a un servidor de
     * un tercero y no es el lugar para el documento de quien firmó ni para la
     * tarifa. Quien necesite el soporte completo lo pide por la API, con token
     * y quedando registrado quién lo consultó.
     */
    private function carga(EventoPaquete $evento, $paquete, string $nombreEvento): array
    {
        return [
            'evento'     => $nombreEvento,
            'emitido_en' => now()->toIso8601String(),
            'envio' => [
                'guia_avalia'  => $paquete->orden->numero_guia,
                'codigo'       => $paquete->codigo,
                'estado'       => $evento->estado_nuevo->value,
                'estado_previo' => $evento->estado_anterior?->value,
                'referencias'  => $paquete->orden->referencias->map(fn ($r) => [
                    'tipo'  => $r->tipo,
                    'valor' => $r->valor,
                ])->values()->all(),
            ],
            'ocurrido_en' => ($evento->ocurrido_en_dispositivo ?? $evento->registrado_en)?->toIso8601String(),
            // La secuencia permite al cliente ordenar y detectar si se perdió
            // algo, sin depender del orden de llegada de los avisos.
            'secuencia'   => (int) $evento->secuencia,
        ];
    }
}
