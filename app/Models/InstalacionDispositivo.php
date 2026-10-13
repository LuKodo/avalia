<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de en qué vehículo estuvo instalada cada sonda.
 *
 * Sin esta tabla no se puede responder «¿qué sonda vigilaba ese vehículo el 8
 * de julio?», que es justo lo que pregunta una auditoría de cadena de frío
 * meses después de la entrega.
 */
class InstalacionDispositivo extends Model
{
    protected $table = 'instalaciones_dispositivo';

    protected $fillable = [
        'dispositivo_id', 'vehiculo_id', 'instalada_por', 'desde', 'hasta', 'motivo_retiro',
    ];

    protected function casts(): array
    {
        return [
            'desde' => 'datetime',
            'hasta' => 'datetime',
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

    public function vigente(): bool
    {
        return $this->hasta === null;
    }
}
