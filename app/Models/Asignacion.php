<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asignacion extends Model
{
    protected $table = 'asignaciones';

    protected $fillable = [
        'paquete_id', 'mensajero_id', 'asignada_por', 'origen',
        'puntaje', 'desglose_puntaje', 'motivo_anulacion', 'sustituye_a', 'asignada_en',
    ];

    protected function casts(): array
    {
        return [
            'desglose_puntaje' => 'array',
            'asignada_en'      => 'datetime',
            'liberada_en'      => 'datetime',
        ];
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class);
    }

    public function mensajero(): BelongsTo
    {
        return $this->belongsTo(Mensajero::class);
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignada_por');
    }

    public function fueManual(): bool
    {
        return $this->origen === 'manual';
    }

    /**
     * Una asignación manual que sustituye a una sugerencia automática es la
     * señal más valiosa que produce el sistema: dice dónde el motor se equivoca.
     */
    public function contradiceAlMotor(): bool
    {
        return $this->fueManual() && $this->sustituye_a !== null;
    }
}
