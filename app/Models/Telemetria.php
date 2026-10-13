<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Telemetria extends Model
{
    protected $table = 'telemetria';

    public $timestamps = false;

    protected $fillable = [
        'dispositivo_id', 'temperatura', 'humedad', 'latitud', 'longitud',
        'precision_m', 'velocidad_kmh', 'bateria', 'medido_en', 'fuera_de_rango', 'crudo',
    ];

    protected function casts(): array
    {
        return [
            'temperatura'    => 'float',
            'humedad'        => 'float',
            'latitud'        => 'float',
            'longitud'       => 'float',
            'fuera_de_rango' => 'boolean',
            'crudo'          => 'array',
            'medido_en'      => 'datetime',
            'recibido_en'    => 'datetime',
        ];
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class);
    }

    /**
     * Desfase entre el reloj del aparato y el del servidor. Un dispositivo que
     * acumula sin cobertura descarga en lote: sin esta diferencia no se
     * distingue una lectura vieja recién recibida de una lectura nueva.
     */
    public function desfaseSegundos(): int
    {
        return $this->medido_en->diffInSeconds($this->recibido_en);
    }
}
