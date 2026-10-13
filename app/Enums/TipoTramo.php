<?php

namespace App\Enums;

/**
 * Qué clase de trayecto es un tramo.
 *
 * No es una etiqueta decorativa: decide qué se le exige al mensajero para
 * poder cerrarlo. Recoger en una farmacia y entregar a un paciente no piden la
 * misma prueba, y mover una caja entre dos bodegas nuestras no pide ninguna de
 * las dos.
 */
enum TipoTramo: string
{
    /** Del cliente hacia nosotros. Firma quien ENTREGA la mercancía. */
    case Recoleccion = 'recoleccion';

    /** Entre puntos nuestros. No hay tercero a quien pedirle firma. */
    case Traslado = 'traslado';

    /** Hacia el destino final. Prueba de entrega completa. */
    case Entrega = 'entrega';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Recoleccion => 'Recolección',
            self::Traslado    => 'Traslado interno',
            self::Entrega     => 'Entrega',
        };
    }

    /**
     * Qué hay que capturar para cerrar este tramo.
     *
     * @return array<int, string>
     */
    public function exige(): array
    {
        return match ($this) {
            // Quien nos entrega firma que nos lo llevamos. Es el comprobante
            // que el cliente no tenía.
            self::Recoleccion => ['firma', 'contraparte'],

            // Custodia interna: basta la foto del estado en que llega. Pedirle
            // firma a un compañero de bodega es papeleo sin valor probatorio.
            self::Traslado    => ['foto'],

            self::Entrega     => ['firma', 'foto', 'contraparte'],
        };
    }

    /** ¿Al cerrarlo el paquete queda entregado al cliente final? */
    public function cierraElEnvio(): bool
    {
        return $this === self::Entrega;
    }
}
