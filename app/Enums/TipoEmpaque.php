<?php

namespace App\Enums;

/**
 * En qué viaja el paquete.
 *
 * Es un eje DISTINTO de `TipoPaquete`. Aquel dice qué contiene —general,
 * cadena de frío, control especial— y de él dependen los controles
 * obligatorios y el vehículo. Este dice en qué va metido, y de él depende el
 * volumen que ocupa y por tanto lo que cuesta moverlo.
 *
 * Mezclarlos habría sido tentador y falso: una bolsa puede llevar control
 * especial, y una caja de pañales no exige refrigeración pero ocupa el doble
 * que todo lo demás junto.
 */
enum TipoEmpaque: string
{
    case Bolsa   = 'bolsa';
    case Caja    = 'caja';
    case Panales = 'panales';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bolsa   => 'Bolsa',
            self::Caja    => 'Caja',
            self::Panales => 'Pañales',
        };
    }

    /**
     * La clave con la que se busca su recargo en los parámetros.
     *
     * El recargo NO vive aquí porque es un precio, y los precios cambian sin
     * que cambie el software. Vive en `Administración → Parámetros`, donde
     * alguien de la operación lo ajusta sin esperar a un despliegue.
     */
    public function claveRecargo(): string
    {
        return "tarifa.recargo_empaque.{$this->value}";
    }

    /** @return array<int, array{valor:string, etiqueta:string}> */
    public static function catalogo(): array
    {
        return array_map(
            fn (self $e) => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()],
            self::cases()
        );
    }
}
