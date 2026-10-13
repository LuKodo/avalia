<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exportacion extends Model
{
    protected $table = 'exportaciones';

    protected $fillable = [
        'cliente_id', 'user_id', 'tipo', 'formato', 'criterio',
        'registros', 'incluye_soporte', 'incluye_datos_receptor', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'criterio'               => 'array',
            'incluye_soporte'        => 'boolean',
            'incluye_datos_receptor' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
