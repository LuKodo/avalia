<?php

namespace App\Enums;

/**
 * Categoría de la carga. Determina el vehículo, el mensajero habilitado y los
 * controles obligatorios en ruta. Es el primer dato que se captura en una orden
 * justamente porque condiciona todo lo demás.
 */
enum TipoPaquete: string
{
    case CadenaFrio = 'cadena_frio';
    case Controlado = 'controlado';
    case General    = 'general';

    public function exigeRefrigeracion(): bool
    {
        return $this === self::CadenaFrio;
    }

    public function exigeCertificacion(): bool
    {
        return $this === self::Controlado;
    }

    /**
     * Controles que la aplicación del mensajero vuelve obligatorios.
     * El flujo de campo se construye leyendo esta lista, no con condicionales
     * repartidos por la interfaz.
     *
     * @return array<int, string>
     */
    public function controlesRecoleccion(): array
    {
        return match ($this) {
            self::CadenaFrio => ['escaneo', 'temperatura', 'foto_indicador'],
            self::Controlado => ['escaneo', 'acta_custodia'],
            self::General    => ['escaneo'],
        };
    }

    /**
     * Controles de entrega.
     *
     * `foto_receptor` y `firma` aparecen en LOS TRES tipos, sin excepción: toda
     * entrega debe quedar con su soporte capturado. Un envío «general» sin
     * evidencia es exactamente el que no se puede defender cuando el cliente
     * reclama que nunca llegó.
     *
     * @return array<int, string>
     */
    public function controlesEntrega(): array
    {
        return match ($this) {
            self::CadenaFrio => ['escaneo', 'temperatura', 'documento_receptor', 'foto_receptor', 'firma'],
            self::Controlado => ['escaneo', 'documento_receptor', 'foto_receptor', 'firma', 'acta_custodia'],
            self::General    => ['escaneo', 'documento_receptor', 'foto_receptor', 'firma'],
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::CadenaFrio => 'Cadena de frío',
            self::Controlado => 'Control especial',
            self::General    => 'General',
        };
    }

    /** @return array<int, array{valor:string, etiqueta:string}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $e) => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()], self::cases());
    }
}
