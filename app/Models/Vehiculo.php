<?php

namespace App\Models;

use App\Enums\TipoVehiculo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehiculo extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vehiculos';

    protected $fillable = [
        'placa', 'tipo', 'refrigeracion', 'capacidad_kg', 'capacidad_m3',
        'soat_vence', 'tecnomecanica_vence', 'certificado_frio_vence', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo'                   => TipoVehiculo::class,
            'refrigeracion'          => 'boolean',
            'capacidad_kg'           => 'float',
            'capacidad_m3'           => 'float',
            'soat_vence'             => 'date',
            'tecnomecanica_vence'    => 'date',
            'certificado_frio_vence' => 'date',
            'activo'                 => 'boolean',
        ];
    }

    public function mensajeros(): HasMany
    {
        return $this->hasMany(Mensajero::class);
    }

    /**
     * Un vehículo con documentos vencidos no puede recibir asignación. No es una
     * advertencia: es parte del filtro duro del motor de despacho.
     */
    public function documentosAlDia(): bool
    {
        $hoy = now()->startOfDay();

        foreach (['soat_vence', 'tecnomecanica_vence'] as $campo) {
            if ($this->{$campo} !== null && $this->{$campo}->lt($hoy)) {
                return false;
            }
        }

        if ($this->refrigeracion
            && $this->certificado_frio_vence !== null
            && $this->certificado_frio_vence->lt($hoy)) {
            return false;
        }

        return true;
    }

    /** @return array<int, string> */
    public function documentosVencidos(): array
    {
        $hoy    = now()->startOfDay();
        $vencidos = [];

        $mapa = [
            'soat_vence'             => 'SOAT',
            'tecnomecanica_vence'    => 'Tecnomecánica',
            'certificado_frio_vence' => 'Certificado de cadena de frío',
        ];

        foreach ($mapa as $campo => $etiqueta) {
            if ($this->{$campo} !== null && $this->{$campo}->lt($hoy)) {
                $vencidos[] = $etiqueta;
            }
        }

        return $vencidos;
    }
}
