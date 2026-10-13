<?php

namespace App\Models;

use App\Services\Geo\Ruteador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RutaPlaneada extends Model
{
    protected $table = 'rutas_planeadas';

    protected $fillable = [
        'orden_id', 'origen_lat', 'origen_lon', 'destino_lat', 'destino_lon',
        'geometria', 'distancia_m', 'duracion_seg', 'proveedor',
        'calculada_en', 'motivo_recalculo',
    ];

    protected function casts(): array
    {
        return [
            'origen_lat'   => 'float',
            'origen_lon'   => 'float',
            'destino_lat'  => 'float',
            'destino_lon'  => 'float',
            'geometria'    => 'array',
            'distancia_m'  => 'integer',
            'duracion_seg' => 'integer',
            'calculada_en' => 'datetime',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class);
    }

    /**
     * ¿Es una ruta de verdad o apenas la línea entre los dos puntos?
     *
     * Lo consulta la interfaz antes de hablar de desvío: sobre una línea recta,
     * apartarse es lo normal —ninguna calle lo es— y acusar de desvío a quien va
     * por la única vía posible destruye la confianza en el aviso.
     */
    public function esPorCarretera(): bool
    {
        return $this->proveedor === Ruteador::POR_CARRETERA;
    }

    public function kilometros(): float
    {
        return round($this->distancia_m / 1000, 1);
    }

    public function minutos(): int
    {
        return (int) round($this->duracion_seg / 60);
    }
}
