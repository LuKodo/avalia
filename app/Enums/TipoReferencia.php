<?php

namespace App\Enums;

/**
 * Referencias que define el CLIENTE al radicar.
 *
 * Son la llave con la que el cliente consulta desde sus propios sistemas: en el
 * momento de radicar, el hospital no conoce la guía Avalia — conoce su número de
 * radicado, su orden de compra o su remisión. Una orden puede llevar varias, y
 * cualquiera de ellas debe servir para recuperar el envío completo con su
 * soporte de entrega.
 */
enum TipoReferencia: string
{
    case Radicado    = 'radicado';
    case OrdenCompra = 'orden_compra';
    case Remision    = 'remision';
    case Factura     = 'factura';
    case Historia    = 'historia';
    case Dispensacion = 'dispensacion';
    case Interno     = 'interno';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Radicado    => 'Número de radicado',
            self::OrdenCompra => 'Orden de compra',
            self::Remision    => 'Remisión',
            self::Factura     => 'Factura',
            self::Historia    => 'Historia clínica',
            self::Dispensacion => 'Dispensación',
            self::Interno     => 'Referencia interna',
        };
    }

    /** @return array<int, array{valor:string, etiqueta:string}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $e) => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()], self::cases());
    }
}
