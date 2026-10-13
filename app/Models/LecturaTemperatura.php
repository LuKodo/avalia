<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LecturaTemperatura extends Model
{
    protected $table = 'lecturas_temperatura';

    protected $fillable = [
        'paquete_id', 'valor', 'momento', 'fuente',
        'fuera_de_rango', 'evidencia_id', 'registrada_en',
    ];

    protected function casts(): array
    {
        return [
            'valor'          => 'float',
            'fuera_de_rango' => 'boolean',
            'registrada_en'  => 'datetime',
        ];
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class);
    }

    public function evidencia(): BelongsTo
    {
        return $this->belongsTo(Evidencia::class, 'evidencia_id');
    }

    public function momentoEtiqueta(): string
    {
        return match ($this->momento) {
            'recoleccion' => 'Recolección',
            'transito'    => 'En tránsito',
            'entrega'     => 'Entrega',
            default       => ucfirst($this->momento),
        };
    }
}
