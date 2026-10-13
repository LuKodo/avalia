<?php

namespace App\Enums;

enum TipoVehiculo: string
{
    case Moto                 = 'moto';
    case Automovil            = 'automovil';
    case CamionetaRefrigerada = 'camioneta_refrigerada';
    case Furgon               = 'furgon';

    public function tieneRefrigeracion(): bool
    {
        return $this === self::CamionetaRefrigerada;
    }

    /** Capacidad nominal de referencia. El valor real vive en cada vehículo. */
    public function capacidadNominalKg(): float
    {
        return match ($this) {
            self::Moto                 => 15.0,
            self::Automovil            => 80.0,
            self::CamionetaRefrigerada => 600.0,
            self::Furgon               => 1500.0,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Moto                 => 'Moto',
            self::Automovil            => 'Automóvil',
            self::CamionetaRefrigerada => 'Camioneta refrigerada',
            self::Furgon               => 'Furgón',
        };
    }
}
