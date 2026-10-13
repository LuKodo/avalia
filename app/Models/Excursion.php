<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Episodio de temperatura fuera de rango.
 *
 * Es un episodio, no una lectura: diez lecturas seguidas a 9 °C son un solo
 * problema de veinte minutos, no diez problemas. Contar lecturas en vez de
 * episodios infla el indicador y hace que nadie lo mire.
 */
class Excursion extends Model
{
    protected $table = 'excursiones';

    protected $fillable = [
        'dispositivo_id', 'vehiculo_id', 'inicio', 'fin', 'duracion_seg',
        'valor_min', 'valor_max', 'lecturas', 'paquetes_afectados',
        'estado', 'atendida_por', 'resolucion', 'atendida_en',
    ];

    protected function casts(): array
    {
        return [
            'inicio'             => 'datetime',
            'fin'                => 'datetime',
            'atendida_en'        => 'datetime',
            'valor_min'          => 'float',
            'valor_max'          => 'float',
            'paquetes_afectados' => 'array',
        ];
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function scopeAbiertas(Builder $q): Builder
    {
        return $q->where('estado', 'abierta');
    }

    public function duracionMinutos(): int
    {
        $fin = $this->fin ?? now();

        return (int) ceil($this->inicio->diffInSeconds($fin) / 60);
    }

    /**
     * ¿Supera la tolerancia? Una excursión de dos minutos al abrir la puerta no
     * es lo mismo que media hora con el equipo apagado, y tratarlas igual haría
     * que la operación aprenda a ignorar la alerta.
     */
    public function superaTolerancia(): bool
    {
        return $this->duracionMinutos() >= (int) config('ltp.cadena_frio.tolerancia_excursion_minutos', 20);
    }

    public function severidad(): string
    {
        if (! $this->superaTolerancia()) {
            return 'warning';
        }

        return $this->duracionMinutos() >= 60 ? 'critical' : 'serious';
    }
}
