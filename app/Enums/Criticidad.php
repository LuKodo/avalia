<?php

namespace App\Enums;

enum Criticidad: string
{
    case Urgente    = 'urgente';
    case Programado = 'programado';

    /** Factor de recargo sobre la tarifa base de zona. */
    public function recargo(): float
    {
        return match ($this) {
            self::Urgente    => 0.40,
            self::Programado => 0.0,
        };
    }

    /** Prioridad en la bandeja de despacho: a mayor número, sube primero. */
    public function prioridad(): int
    {
        return match ($this) {
            self::Urgente    => 100,
            self::Programado => 10,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Urgente    => 'Urgente',
            self::Programado => 'Programado',
        };
    }
}
