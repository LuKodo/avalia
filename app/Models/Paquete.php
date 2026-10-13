<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use App\Enums\TipoPaquete;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Paquete extends Model
{
    use HasFactory;

    protected $table = 'paquetes';

    protected $fillable = [
        'orden_id', 'codigo', 'consecutivo', 'tipo', 'estado', 'descripcion',
        'lote', 'peso_kg', 'largo_cm', 'ancho_cm', 'alto_cm',
        'temp_min', 'temp_max', 'presupuesto_estabilidad_min', 'mensajero_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo'            => TipoPaquete::class,
            'estado'          => EstadoPaquete::class,
            'peso_kg'         => 'float',
            'temp_min'        => 'float',
            'temp_max'        => 'float',
            'recolectado_en'  => 'datetime',
            'entregado_en'    => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relaciones

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class);
    }

    public function mensajero(): BelongsTo
    {
        return $this->belongsTo(Mensajero::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoPaquete::class)->orderBy('secuencia');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class);
    }

    public function entrega(): HasOne
    {
        return $this->hasOne(Entrega::class);
    }

    public function temperaturas(): HasMany
    {
        return $this->hasMany(LecturaTemperatura::class);
    }

    public function novedades(): HasMany
    {
        return $this->hasMany(Novedad::class);
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(Asignacion::class);
    }

    /**
     * Los trayectos del paquete, en orden de recorrido.
     *
     * Uno si va directo, dos si pasa por un centro de Avalia. Ver el servicio
     * `CadenaDeTramos`.
     */
    public function tramos(): HasMany
    {
        return $this->hasMany(Tramo::class)->orderBy('orden');
    }

    /**
     * El tramo en el que está ahora: el primero abierto.
     *
     * Es la respuesta a «¿quién tiene el paquete en este momento?», que antes
     * no se podía contestar porque el paquete solo guardaba UN mensajero.
     */
    public function tramoActual(): ?Tramo
    {
        return $this->tramos()->abiertos()->orderBy('orden')->first();
    }

    // ------------------------------------------------------------------- ámbitos

    public function scopeSinAsignar(Builder $q): Builder
    {
        return $q->where('estado', EstadoPaquete::Creado->value)->whereNull('mensajero_id');
    }

    public function scopeEnCurso(Builder $q): Builder
    {
        return $q->whereIn('estado', [
            EstadoPaquete::Asignado->value,
            EstadoPaquete::EnRutaRecoleccion->value,
            EstadoPaquete::Recolectado->value,
            EstadoPaquete::EnTransito->value,
            EstadoPaquete::EnSitio->value,
        ]);
    }

    // ------------------------------------------------------------------ dominio

    public function exigeCadenaFrio(): bool
    {
        return $this->tipo->exigeRefrigeracion();
    }

    public function rangoTermico(): array
    {
        $defecto = config('ltp.cadena_frio.rango_defecto');

        return [
            'min' => $this->temp_min ?? $defecto['min'],
            'max' => $this->temp_max ?? $defecto['max'],
        ];
    }

    public function temperaturaEnRango(float $valor): bool
    {
        $r = $this->rangoTermico();

        return $valor >= $r['min'] && $valor <= $r['max'];
    }

    public function volumenM3(): ?float
    {
        if (! $this->largo_cm || ! $this->ancho_cm || ! $this->alto_cm) {
            return null;
        }

        return round(($this->largo_cm * $this->ancho_cm * $this->alto_cm) / 1_000_000, 4);
    }

    /**
     * Controles que la interfaz de campo debe exigir en este momento del flujo.
     * La app lee esta lista en vez de repetir condicionales por tipo de paquete.
     *
     * @return array<int, string>
     */
    public function controlesPendientes(string $momento): array
    {
        return $momento === 'recoleccion'
            ? $this->tipo->controlesRecoleccion()
            : $this->tipo->controlesEntrega();
    }

    public function tuvoExcursionTermica(): bool
    {
        return $this->temperaturas()->where('fuera_de_rango', true)->exists();
    }
}
