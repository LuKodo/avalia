<?php

namespace App\Models;

use App\Enums\TipoPausa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una interrupción de la jornada del mensajero.
 *
 * Se cierra con `cerrar()` y no tocando los campos a mano: la duración tiene
 * que quedar escrita en el mismo momento en que se fija el fin, o acaban
 * existiendo filas cerradas sin duración que ningún informe sabe sumar.
 */
class PausaJornada extends Model
{
    protected $table = 'pausas_jornada';

    protected $fillable = [
        'mensajero_id', 'tipo', 'inicio', 'fin', 'duracion_seg',
        'descripcion', 'latitud', 'longitud', 'cerrada_automaticamente',
    ];

    protected function casts(): array
    {
        return [
            'tipo'      => TipoPausa::class,
            'inicio'    => 'datetime',
            'fin'       => 'datetime',
            'latitud'   => 'float',
            'longitud'  => 'float',
            'cerrada_automaticamente' => 'boolean',
        ];
    }

    public function mensajero(): BelongsTo
    {
        return $this->belongsTo(Mensajero::class);
    }

    /** La que sigue corriendo. Solo puede haber una por mensajero. */
    public function scopeAbierta(Builder $q): Builder
    {
        return $q->whereNull('fin');
    }

    public function scopeDelDia(Builder $q, ?string $dia = null): Builder
    {
        return $q->whereDate('inicio', $dia ?? now()->toDateString());
    }

    public function estaAbierta(): bool
    {
        return $this->fin === null;
    }

    /**
     * Cierra la pausa y deja escrita su duración.
     *
     * Tolera que ya esté cerrada: el mensajero puede pulsar «reanudar» dos
     * veces si la primera no pareció responder, y eso no debe alargar ni
     * reescribir una pausa que ya terminó.
     */
    public function cerrar(?float $lat = null, ?float $lon = null, bool $automatica = false): self
    {
        if (! $this->estaAbierta()) {
            return $this;
        }

        $fin = now();

        $this->forceFill([
            'fin' => $fin,
            /*
             * El orden importa: `$inicio->diffInSeconds($fin)`, no al revés.
             *
             * Carbon 3 devuelve la diferencia CON SIGNO, así que invertir los
             * operandos daba un número negativo que el `max(0, ...)` convertía
             * en cero. Una pausa de catorce minutos se guardaba como cero y el
             * informe de productividad no habría descontado nada —sin que
             * nadie lo notara, porque cero es un valor perfectamente creible—.
             */
            'duracion_seg' => (int) max(0, round($this->inicio->diffInSeconds($fin))),

            /*
             * Quien la cerro. No es cosmetica: una pausa que cerro el sistema
             * porque el mensajero volvio a trabajar no significa lo mismo que
             * una que el cerro al terminar de comer, y en un informe de
             * productividad esa diferencia es la que explica por que el
             * almuerzo aparece con doce minutos.
             */
            'cerrada_automaticamente' => $automatica,
            // La coordenada del cierre solo se guarda si no había ninguna: la
            // del inicio dice dónde se detuvo, que es la pregunta interesante.
            'latitud'  => $this->latitud ?? $lat,
            'longitud' => $this->longitud ?? $lon,
        ])->save();

        return $this;
    }

    /** Minutos que lleva, esté abierta o cerrada. Para pintarlo sin calcular fuera. */
    public function minutos(): int
    {
        return (int) round(
            ($this->duracion_seg ?? max(0, $this->inicio->diffInSeconds(now()))) / 60
        );
    }
}
