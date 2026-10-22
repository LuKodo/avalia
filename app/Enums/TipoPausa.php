<?php

namespace App\Enums;

/**
 * Por qué un mensajero deja de repartir sin dejar de estar en turno.
 *
 * Existe para que la productividad se mida contra el tiempo en que de verdad
 * se podía repartir. Sin esto, una hora de almuerzo y una hora parado bajo un
 * aguacero pesan igual que una hora perdida, y el indicador castiga a quien
 * cumplió. Un número que castiga a quien cumple deja de usarse en una semana.
 *
 * La distinción entre lo previsto y lo imprevisto no es cosmética: el almuerzo
 * se descuenta y ya está, mientras que la lluvia y la avería son material para
 * explicar un día malo ante el cliente.
 */
enum TipoPausa: string
{
    case Almuerzo = 'almuerzo';
    case Lluvia   = 'lluvia';
    case Averia   = 'averia';
    case Otro     = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Almuerzo => 'Almuerzo',
            self::Lluvia   => 'Lluvia',
            self::Averia   => 'Avería del vehículo',
            self::Otro     => 'Otro motivo',
        };
    }

    /**
     * ¿Es una interrupción ajena al mensajero?
     *
     * El almuerzo es tiempo propio y previsto. La lluvia y la avería no las
     * decide nadie, y por eso van al informe como explicación del atraso, no
     * como demérito.
     */
    public function esImprevisto(): bool
    {
        return $this !== self::Almuerzo;
    }

    /** Las que exigen escribir qué pasó. «Otro» sin explicación no explica nada. */
    public function exigeDescripcion(): bool
    {
        return $this === self::Otro;
    }

    /** @return array<int, array{valor:string, etiqueta:string, imprevisto:bool}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $e) => [
            'valor'      => $e->value,
            'etiqueta'   => $e->etiqueta(),
            'imprevisto' => $e->esImprevisto(),
        ], self::cases());
    }
}
