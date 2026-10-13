<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Petición, queja, reclamo o sugerencia radicada desde el sitio público.
 *
 * A diferencia de una solicitud comercial, esto tiene término de ley: la fecha
 * límite se calcula y se guarda AL RADICAR, no se deduce después. Si mañana
 * cambia la política de días, las ya radicadas conservan el plazo con el que se
 * recibieron — que es el que se le prometió a la persona.
 */
class Pqrs extends Model
{
    use SoftDeletes;

    protected $table = 'pqrs';

    protected $fillable = [
        'radicado', 'tipo', 'nombre', 'documento', 'correo', 'telefono',
        'guia_relacionada', 'orden_id', 'descripcion', 'estado',
        'responder_antes_de', 'ip',
    ];

    /*
     * El documento de quien radica no viaja en una serialización por descuido.
     * Es el mismo criterio que rige el documento del receptor en un soporte.
     */
    protected $hidden = ['documento'];

    protected function casts(): array
    {
        return [
            'responder_antes_de' => 'date',
            'respondida_en'      => 'datetime',
        ];
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(PqrsAdjunto::class, 'pqrs_id');
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class);
    }

    public function asignadaA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignada_a');
    }

    // ------------------------------------------------------------------ ámbito

    public function scopeAbiertas(Builder $q): Builder
    {
        return $q->whereIn('estado', ['radicada', 'en_tramite']);
    }

    /** Las que se vencen dentro de los próximos días indicados. */
    public function scopePorVencer(Builder $q, int $dias = 3): Builder
    {
        return $q->abiertas()
            ->whereDate('responder_antes_de', '<=', now()->addDays($dias)->toDateString());
    }

    // ----------------------------------------------------------------- ayudas

    /**
     * Días hábiles que quedan. Negativo si ya se venció.
     *
     * Se cuentan hábiles y no corridos porque así se cuentan los términos: decir
     * «quedan 2 días» un viernes cuando en realidad quedan hasta el martes hace
     * que alguien corra un fin de semana sin necesidad, o al revés.
     */
    public function diasHabilesRestantes(): int
    {
        $hoy = now()->startOfDay();
        $fin = $this->responder_antes_de->copy()->startOfDay();

        $signo = $fin->lt($hoy) ? -1 : 1;
        [$desde, $hasta] = $signo === 1 ? [$hoy, $fin] : [$fin, $hoy];

        $dias = 0;
        $cursor = $desde->copy();

        while ($cursor->lt($hasta)) {
            $cursor->addDay();

            if (! $cursor->isWeekend()) {
                $dias++;
            }
        }

        return $signo * $dias;
    }

    public function vencida(): bool
    {
        return in_array($this->estado, ['radicada', 'en_tramite'], true)
            && $this->responder_antes_de->startOfDay()->lt(now()->startOfDay());
    }

    public function tipoEtiqueta(): string
    {
        return match ($this->tipo) {
            'peticion'         => 'Petición',
            'queja'            => 'Queja',
            'reclamo'          => 'Reclamo',
            'sugerencia'       => 'Sugerencia',
            'datos_personales' => 'Protección de datos personales',
            default            => ucfirst((string) $this->tipo),
        };
    }

    public function estadoEtiqueta(): string
    {
        return match ($this->estado) {
            'radicada'   => 'Radicada',
            'en_tramite' => 'En trámite',
            'respondida' => 'Respondida',
            'cerrada'    => 'Cerrada',
            default      => ucfirst((string) $this->estado),
        };
    }
}
