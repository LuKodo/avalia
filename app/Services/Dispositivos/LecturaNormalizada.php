<?php

namespace App\Services\Dispositivos;

/**
 * Una lectura ya traducida al lenguaje de Avalia.
 *
 * Todo lo que entra de un proveedor pasa por aquí antes de tocar la base de
 * datos. Es lo que permite que el resto del sistema no sepa —ni le importe—
 * de qué marca es la sonda: cambiar de Tempubi a otro fabricante es escribir
 * un adaptador nuevo, no tocar el procesador, ni los modelos, ni la interfaz.
 */
final class LecturaNormalizada
{
    public function __construct(
        public readonly string $serial,
        public readonly \DateTimeImmutable $medidoEn,
        public readonly ?float $temperatura = null,
        public readonly ?float $humedad = null,
        public readonly ?float $latitud = null,
        public readonly ?float $longitud = null,
        public readonly ?int $precisionM = null,
        public readonly ?int $velocidadKmh = null,
        public readonly ?int $bateria = null,
        public readonly array $crudo = [],
    ) {}

    public function tieneUbicacion(): bool
    {
        return $this->latitud !== null && $this->longitud !== null;
    }

    public function tieneTemperatura(): bool
    {
        return $this->temperatura !== null;
    }

    /**
     * Descarta lo que no sirve para nada: una lectura sin serial, sin fecha o
     * sin ninguna medida no es un dato incompleto, es ruido.
     */
    public function esUtil(): bool
    {
        return $this->serial !== ''
            && ($this->tieneTemperatura() || $this->tieneUbicacion());
    }
}
