<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El aviso de las guías que llevan días sin cerrarse.
 *
 * Una por destinatario y no una por guía. Veinte correos a las siete de la
 * mañana no son veinte avisos: son una bandeja que se marca como leída en
 * bloque, y el día que de verdad importe nadie lo va a abrir.
 *
 * El mismo correo sirve para Avalia y para el cliente; lo que cambia es la
 * lista que se le pasa. Al cliente solo llegan sus guías, y por eso la
 * selección se hace fuera: un correo que decide a quién le toca qué es un
 * correo que tarde o temprano manda los datos de un cliente a otro.
 */
class GuiasSinCerrar extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $guias
     */
    public function __construct(
        public readonly array $guias,
        public readonly int $dias,
        public readonly bool $paraOperacion,
    ) {}

    public function envelope(): Envelope
    {
        $n = count($this->guias);

        return new Envelope(
            subject: $n === 1
                ? "1 envío lleva más de {$this->dias} días sin cerrarse"
                : "{$n} envíos llevan más de {$this->dias} días sin cerrarse",
        );
    }

    /**
     * Tope de filas en la tabla del correo.
     *
     * Una tabla de doscientas filas en un correo no se lee: se cierra. Van las
     * mas antiguas —que son las graves— y el pie dice cuantas quedan fuera y
     * donde verlas enteras. Decir el total y ensenar una parte es honesto;
     * ensenarlas todas y que nadie pase de la tercera, no.
     */
    private const TOPE_FILAS = 40;

    public function content(): Content
    {
        return new Content(
            markdown: 'correo.guias-sin-cerrar',
            with: [
                /*
                 * `filas` y no `guias`: la propiedad publica `$guias` del
                 * propio Mailable tambien llega a la vista, y gana sobre lo
                 * que se pasa aqui. El correo decia «se listan las 53 mas
                 * antiguas» mientras ensenaba 40.
                 */
                'filas' => array_slice($this->guias, 0, self::TOPE_FILAS),
                'ocultas' => max(0, count($this->guias) - self::TOPE_FILAS),
                'total' => count($this->guias),
                'dias'  => $this->dias,
                /*
                 * Avalia ve quién tiene cada caja; el cliente no.
                 *
                 * El nombre del mensajero es dato del trabajador, y al cliente
                 * no le sirve de nada: él no le va a llamar. Lo que él necesita
                 * saber es cuáles de sus envíos están parados, para decidir si
                 * reprograma con el paciente.
                 */
                'conCustodia' => $this->paraOperacion,
            ],
        );
    }
}
