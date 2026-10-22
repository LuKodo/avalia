<?php

namespace App\Mail;

use App\Models\EventoPaquete;
use App\Models\Paquete;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al cliente de que su envío cambió de estado.
 *
 * Va encolado -ShouldQueue- y no en la petición. Un servidor de correo lento
 * no puede hacer esperar a un mensajero que acaba de firmar una entrega en la
 * calle: el evento se registra, la respuesta sale, y el correo se manda
 * después. Si el servidor de correo está caído, se reintenta sin que nadie en
 * la operación se entere.
 *
 * Se serializa el ID y no el modelo entero: cuando el trabajo se ejecuta,
 * minutos después, lo que importa es el estado de ese momento.
 */
class CambioDeEstado extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Paquete $paquete,
        public readonly EventoPaquete $evento,
    ) {
    }

    public function envelope(): Envelope
    {
        $guia   = $this->paquete->orden->numero_guia;
        $estado = $this->paquete->estado->etiqueta();

        return new Envelope(
            subject: "Envío {$guia}: {$estado}",
        );
    }

    public function content(): Content
    {
        $orden = $this->paquete->orden;

        return new Content(
            markdown: 'correo.cambio-de-estado',
            with: [
                'guia'        => $orden->numero_guia,
                'cliente'     => $orden->cliente->razon_social,
                'estado'      => $this->paquete->estado->etiqueta(),

                /*
                 * Si el aviso es por una novedad, el estado no basta.
                 *
                 * «Con novedad» no le dice al cliente si nadie recibió, si la
                 * dirección estaba mal o si la farmacia había cerrado —y son
                 * tres llamadas distintas las que tiene que hacer—. El motivo
                 * va en el propio evento; aquí solo se saca a la superficie.
                 */
                'novedad'     => $this->evento->tipo_evento !== 'novedad' ? null : [
                    'tipo' => \App\Enums\TipoNovedad::tryFrom(
                        (string) ($this->evento->datos['tipo_novedad'] ?? '')
                    )?->etiqueta(),
                    'descripcion' => $this->evento->datos['descripcion'] ?? null,

                    /*
                     * Que el envío vuelve al centro es lo primero que el cliente
                     * necesita saber: condiciona lo que él hace a continuación
                     * —reprogramar con el paciente, o pedir la devolución—.
                     */
                    'retorna' => \App\Enums\TipoNovedad::tryFrom(
                        (string) ($this->evento->datos['tipo_novedad'] ?? '')
                    )?->exigeRetornoACentro() ?? false,
                ],
                'ocurrido'    => $this->evento->ocurrido_en,
                'origen'      => $orden->origen?->nombre,
                'destino'     => $orden->destino?->nombre,
                /*
                 * `tipo` es un enum, no una cadena: concatenarlo revienta.
                 * Y la etiqueta se lee mejor que el valor interno -«Orden de
                 * compra» en vez de «orden_compra»-, que es justo lo que el
                 * cliente reconoce de sus propios sistemas.
                 */
                'referencias' => $orden->referencias->map(
                    fn ($r) => $r->tipo->etiqueta().': '.$r->valor
                )->all(),
                /*
                 * El enlace lleva la guía dentro, no a la caja de búsqueda.
                 *
                 * Quien recibe este correo ya sabe de qué envío le hablan: se lo
                 * acaba de decir el asunto. Mandarlo a teclear quince caracteres
                 * que ya están en el mensaje es trabajo inventado, y desde el
                 * teléfono —que es donde se leen los correos— además se escribe
                 * mal. La pantalla de rastreo busca sola cuando recibe `?q=`.
                 */
                'rastreo'     => rtrim(config('app.url'), '/').'/rastreo?q='.rawurlencode($orden->numero_guia),
            ],
        );
    }
}
