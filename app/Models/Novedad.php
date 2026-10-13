<?php

namespace App\Models;

use App\Enums\TipoNovedad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Novedad extends Model
{
    protected $table = 'novedades';

    protected $fillable = [
        'paquete_id', 'reportada_por', 'tipo', 'severidad', 'descripcion',
        'imputable_ltp', 'estado', 'reportada_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo'          => TipoNovedad::class,
            'imputable_ltp' => 'boolean',
            'reportada_en'  => 'datetime',
            'cerrada_en'    => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $n) {
            $n->severidad      = $n->severidad ?? $n->tipo->severidad();
            $n->imputable_ltp  = $n->imputable_ltp ?? $n->tipo->esImputableALtp();
            $n->reportada_en   = $n->reportada_en ?? now();
        });
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class);
    }

    public function reportante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reportada_por');
    }

    public function scopeAbiertas(Builder $q): Builder
    {
        return $q->whereIn('estado', ['abierta', 'en_gestion']);
    }

    public function scopeCriticas(Builder $q): Builder
    {
        return $q->where('severidad', 'critical');
    }
}
