<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SelloDiario extends Model
{
    protected $table = 'sellos_diarios';

    protected $fillable = [
        'fecha', 'secuencia_desde', 'secuencia_hasta', 'total_eventos',
        'hash_raiz', 'firma', 'exportado', 'exportado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha'        => 'date',
            'exportado'    => 'boolean',
            'exportado_en' => 'datetime',
        ];
    }

    /**
     * Un sello sin firma o sin exportar no cumple su función: la protección real
     * viene de estar fuera del alcance del servidor que podría ser manipulado.
     */
    public function protegeDeVerdad(): bool
    {
        return $this->firma !== null && $this->exportado;
    }
}
