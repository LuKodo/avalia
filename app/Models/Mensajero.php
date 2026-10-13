<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mensajero extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mensajeros';

    protected $fillable = [
        'user_id', 'vehiculo_id', 'codigo', 'certificado_controlados',
        'certificado_vence', 'zonas_habilitadas', 'en_turno', 'turno_inicio',
        'ultima_latitud', 'ultima_longitud', 'ultima_posicion_en', 'bateria',
        'carga_actual_kg', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'certificado_controlados' => 'boolean',
            'certificado_vence'       => 'date',
            'zonas_habilitadas'       => 'array',
            'en_turno'                => 'boolean',
            'turno_inicio'            => 'datetime',
            'ultima_posicion_en'      => 'datetime',
            'ultima_latitud'          => 'float',
            'ultima_longitud'         => 'float',
            'carga_actual_kg'         => 'float',
            'indice_cumplimiento'     => 'float',
            'activo'                  => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function paquetes(): HasMany
    {
        return $this->hasMany(Paquete::class);
    }

    public function posiciones(): HasMany
    {
        return $this->hasMany(Posicion::class);
    }

    public function scopeDisponibles(Builder $q): Builder
    {
        return $q->where('activo', true)->where('en_turno', true);
    }

    /**
     * El nombre se pide desde muchos sitios —bitácora, actas, tablero— y no
     * todos precargan la relación. `loadMissing` es la red de seguridad: si ya
     * viene cargada no cuesta nada, y si no, no revienta contra la protección
     * de carga perezosa que corre en desarrollo.
     */
    public function nombre(): string
    {
        $this->loadMissing('user');

        return $this->user?->nombre ?? $this->codigo;
    }

    public function tieneCoordenada(): bool
    {
        return $this->ultima_latitud !== null && $this->ultima_longitud !== null;
    }

    public function distanciaKm(float $lat, float $lon): ?float
    {
        return $this->tieneCoordenada()
            ? Geo::distanciaKm($this->ultima_latitud, $this->ultima_longitud, $lat, $lon)
            : null;
    }

    public function certificacionVigente(): bool
    {
        return $this->certificado_controlados
            && ($this->certificado_vence === null || $this->certificado_vence->gte(now()->startOfDay()));
    }

    public function habilitadoEnZona(?int $zonaId): bool
    {
        if ($zonaId === null) {
            return true;
        }

        $zonas = $this->zonas_habilitadas ?? [];

        return $zonas === [] || in_array($zonaId, $zonas, true);
    }

    /** Paquetes que el mensajero tiene realmente encima o en camino a recoger. */
    public function paquetesActivos(): int
    {
        return $this->paquetes()
            ->whereIn('estado', [
                EstadoPaquete::Asignado->value,
                EstadoPaquete::EnRutaRecoleccion->value,
                EstadoPaquete::Recolectado->value,
                EstadoPaquete::EnTransito->value,
                EstadoPaquete::EnSitio->value,
            ])->count();
    }

    public function capacidadDisponibleKg(): float
    {
        $capacidad = (float) ($this->vehiculo?->capacidad_kg ?? 0);

        return max(0, $capacidad - (float) $this->carga_actual_kg);
    }

    /**
     * Sin reporte de posición durante demasiado tiempo. Un teléfono apagado
     * significa un paquete sin trazabilidad, así que esto es una alerta real,
     * no un dato de diagnóstico.
     */
    public function sinSenal(int $umbralMinutos = 10): bool
    {
        return $this->ultima_posicion_en === null
            || $this->ultima_posicion_en->diffInMinutes(now()) > $umbralMinutos;
    }
}
