<?php

namespace App\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zona extends Model
{
    use HasFactory;

    protected $table = 'zonas';

    protected $fillable = [
        'nombre', 'ciudad', 'poligono', 'tarifa_base', 'tarifa_refrigerado', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'poligono'           => 'array',
            'tarifa_base'        => 'decimal:2',
            'tarifa_refrigerado' => 'decimal:2',
            'activo'             => 'boolean',
        ];
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }

    /**
     * Punto en polígono. Con pocas decenas de zonas por ciudad esto se resuelve
     * en PHP sin costo apreciable; cuando el catálogo crezca, la misma consulta
     * se delega a PostGIS con un índice GiST sin cambiar la interfaz.
     */
    public function contiene(float $lat, float $lon): bool
    {
        return Geo::puntoEnPoligono($lat, $lon, $this->poligono ?? []);
    }

    public function tarifaPara(bool $refrigerado): float
    {
        return (float) ($refrigerado ? $this->tarifa_refrigerado : $this->tarifa_base);
    }
}
