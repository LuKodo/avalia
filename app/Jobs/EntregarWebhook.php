<?php

namespace App\Jobs;

use App\Models\EntregaWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entrega un webhook al sistema del cliente.
 *
 * Es el primer trabajo en cola del proyecto, y existe porque toda la
 * maquinaria de webhooks ya estaba construida —modelo con firma HMAC, tabla de
 * entregas con reintentos y espera creciente, desactivación tras fallos
 * seguidos— y no había nadie que la disparara. Los clientes tenían webhooks
 * configurables que jamás se enviaban.
 *
 * Por qué en cola y no dentro de la petición: el destino es un servidor ajeno
 * que puede tardar, no responder o estar caído. Llamarlo mientras el mensajero
 * espera a que se confirme una entrega convierte la lentitud de un tercero en
 * lentitud nuestra, y un fallo suyo en un fallo de nuestra operación.
 *
 * La espera entre reintentos la lleva ESTE trabajo y no el reintento propio de
 * la cola: el intento y su resultado tienen que quedar escritos en
 * `entregas_webhook`, porque un cliente que dice «no me llegó nada» necesita
 * que le enseñemos cuándo se intentó y qué contestó su servidor.
 */
class EntregarWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * Intentos del TRABAJO. Los reintentos de negocio se cuentan aparte, en la
     * fila de `entregas_webhook`; este número solo protege de un fallo nuestro
     * —una excepción inesperada— y no del servidor del cliente.
     */
    public int $tries = 3;

    /** Un destino que no contesta no puede tener ocupado un trabajador. */
    public int $timeout = 30;

    public function __construct(public int $entregaId)
    {
    }

    public function handle(): void
    {
        $entrega = EntregaWebhook::with('webhook')->find($this->entregaId);

        if ($entrega === null || $entrega->estado !== 'pendiente') {
            return;   // Ya se resolvió, o alguien la borró.
        }

        $webhook = $entrega->webhook;

        if ($webhook === null || ! $webhook->activo) {
            $entrega->update(['estado' => 'descartado', 'respuesta_cuerpo' => 'El webhook fue desactivado.']);

            return;
        }

        $cuerpo = json_encode($entrega->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $intento = $entrega->intento + 1;

        try {
            $respuesta = Http::timeout((int) config('ltp.integraciones.webhook_timeout_seg', 8))
                ->withHeaders([
                    'Content-Type'      => 'application/json',
                    // La firma permite al cliente comprobar que venimos de
                    // nosotros y no de cualquiera que conozca su URL.
                    'X-Avalia-Firma'    => $webhook->firmar($cuerpo),
                    'X-Avalia-Evento'   => $entrega->evento,
                    'X-Avalia-Entrega'  => (string) $entrega->id,
                    'X-Avalia-Intento'  => (string) $intento,
                ])
                ->withBody($cuerpo, 'application/json')
                ->post($webhook->url);
        } catch (\Throwable $e) {
            $this->reprogramar($entrega, $intento, null, substr($e->getMessage(), 0, 500));

            return;
        }

        if ($respuesta->successful()) {
            $entrega->update([
                'estado'           => 'entregado',
                'intento'          => $intento,
                'respuesta_codigo' => $respuesta->status(),
                'entregado_en'     => now(),
                'proximo_intento_en' => null,
            ]);

            $webhook->registrarExito();

            return;
        }

        /*
         * Un 4xx que no sea 408 ni 429 es un rechazo del cliente, no un fallo
         * de red: su servidor entendió y dijo que no. Reintentarlo veinte veces
         * solo repite el mismo «no» y llena su registro de ruido.
         */
        $definitivo = $respuesta->status() >= 400
            && $respuesta->status() < 500
            && ! in_array($respuesta->status(), [408, 429], true);

        if ($definitivo) {
            $entrega->update([
                'estado'           => 'descartado',
                'intento'          => $intento,
                'respuesta_codigo' => $respuesta->status(),
                'respuesta_cuerpo' => substr($respuesta->body(), 0, 500),
            ]);

            return;
        }

        $this->reprogramar($entrega, $intento, $respuesta->status(), substr($respuesta->body(), 0, 500));
    }

    /**
     * Vuelve a intentarlo más tarde, con espera creciente.
     *
     * 30 s, 1 min, 2 min, 4 min… hasta una hora. Un sistema que se cayó rara vez
     * vuelve en el segundo siguiente, y reintentar en bucle cerrado contra un
     * servidor caído es participar en su caída.
     */
    private function reprogramar(EntregaWebhook $entrega, int $intento, ?int $codigo, string $motivo): void
    {
        $maximo = (int) config('ltp.integraciones.webhook_reintentos', 5);

        if ($intento >= $maximo) {
            $entrega->update([
                'estado'           => 'fallido',
                'intento'          => $intento,
                'respuesta_codigo' => $codigo,
                'respuesta_cuerpo' => $motivo,
            ]);

            $entrega->webhook?->registrarFallo();

            Log::warning('Webhook agotó sus reintentos.', [
                'entrega_id' => $entrega->id,
                'webhook_id' => $entrega->webhook_id,
                'codigo'     => $codigo,
            ]);

            return;
        }

        $espera = min(3600, 30 * (2 ** ($intento - 1)));

        $entrega->update([
            'intento'            => $intento,
            'respuesta_codigo'   => $codigo,
            'respuesta_cuerpo'   => $motivo,
            'proximo_intento_en' => now()->addSeconds($espera),
        ]);

        self::dispatch($entrega->id)->delay(now()->addSeconds($espera));
    }
}
