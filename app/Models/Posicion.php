<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Posicion extends Model
{
    protected $table = 'posiciones';

    public $timestamps = false;

    protected $fillable = [
        'mensajero_id', 'latitud', 'longitud', 'precision_m',
        'velocidad_kmh', 'registrado_en',
    ];

    protected function casts(): array
    {
        return [
            'latitud'       => 'float',
            'longitud'      => 'float',
            'registrado_en' => 'datetime',
            'recibido_en'   => 'datetime',
        ];
    }

    public function mensajero(): BelongsTo
    {
        return $this->belongsTo(Mensajero::class);
    }
}
