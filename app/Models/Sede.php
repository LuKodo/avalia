<?php

namespace App\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sede extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sedes';

    protected $fillable = [
        'cliente_id', 'zona_id', 'nombre', 'codigo_centro_costo', 'tipo', 'direccion', 'detalle_acceso',
        'ciudad', 'latitud', 'longitud', 'coordenada_verificada',
        'contacto_nombre', 'contacto_telefono',
        'ventanas_recepcion', 'contacto_nombre', 'contacto_telefono', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'ventanas_recepcion'    => 'array',
            'coordenada_verificada' => 'boolean',
            // Sin este casteo, `revisada_en` vuelve de la base como cadena y
            // cualquier intento de formatearla revienta. El dato se guardaba
            // bien; lo que fallaba era leerlo.
            'revisada_en'           => 'datetime',
            'activo'                => 'boolean',
            'latitud'               => 'float',
            'longitud'              => 'float',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    public function tieneCoordenada(): bool
    {
        return $this->latitud !== null && $this->longitud !== null;
    }

    public function distanciaKm(float $lat, float $lon): ?float
    {
        return $this->tieneCoordenada()
            ? Geo::distanciaKm($this->latitud, $this->longitud, $lat, $lon)
            : null;
    }

    /**
     * ¿La sede recibe a esta hora? Un envío que llega a una farmacia hospitalaria
     * fuera de su ventana de recepción es una novedad garantizada.
     */
    public function recibeEn(\DateTimeInterface $momento): bool
    {
        $ventanas = $this->ventanas_recepcion ?? [];

        if ($ventanas === []) {
            return true;
        }

        $dia  = (int) $momento->format('N');   // 1 = lunes
        $hora = $momento->format('H:i');

        foreach ($ventanas as $v) {
            if ((int) ($v['dia'] ?? 0) === $dia
                && $hora >= ($v['desde'] ?? '00:00')
                && $hora <= ($v['hasta'] ?? '23:59')) {
                return true;
            }
        }

        return false;
    }

    public function direccionCompleta(): string
    {
        return trim($this->direccion.($this->detalle_acceso ? ' · '.$this->detalle_acceso : '').', '.$this->ciudad);
    }
}
