<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntregaWebhook extends Model
{
    protected $table = 'entregas_webhook';

    protected $fillable = [
        'webhook_id', 'orden_id', 'evento', 'payload', 'estado',
        'intento', 'respuesta_codigo', 'respuesta_cuerpo',
        'proximo_intento_en', 'entregado_en',
    ];

    protected function casts(): array
    {
        return [
            'payload'            => 'array',
            'proximo_intento_en' => 'datetime',
            'entregado_en'       => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(WebhookCliente::class, 'webhook_id');
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->where('estado', 'pendiente')
            ->where(fn ($s) => $s->whereNull('proximo_intento_en')->orWhere('proximo_intento_en', '<=', now()));
    }

    /** Reintento exponencial con techo: 1, 4, 9, 16, 25 minutos. */
    public function programarReintento(int $maximo = 5): void
    {
        $this->intento++;

        if ($this->intento >= $maximo) {
            $this->estado = 'fallido';
            $this->save();

            return;
        }

        $this->proximo_intento_en = now()->addMinutes($this->intento ** 2);
        $this->save();
    }
}
