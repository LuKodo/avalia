<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use App\Enums\TipoEmpaque;
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
        'lote', 'empaque', 'alto_costo', 'peso_kg', 'largo_cm', 'ancho_cm', 'alto_cm',
        'temp_min', 'temp_max', 'presupuesto_estabilidad_min', 'mensajero_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo'            => TipoPaquete::class,
            'estado'          => EstadoPaquete::class,
            'empaque'         => TipoEmpaque::class,
            'alto_costo'      => 'boolean',
            'peso_kg'         => 'float',
            'temp_min'        => 'float',
            'temp_max'        => 'float',
            'recolectado_en'  => 'datetime',
            'entregado_en'    => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relaciones

    /** Las referencias dichas para ESTA caja. */
    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaCliente::class);
    }

    /**
     * Las referencias que le corresponden a esta caja, de verdad.
     *
     * Primero las suyas. Si no tiene ninguna, las del envío entero: una orden
     * de un solo paquete no necesita repetir el dato, y todo lo radicado antes
     * de que las referencias pudieran colgar del paquete está en ese caso.
     *
     * El respaldo solo aplica cuando la caja no tiene ninguna propia. Si tiene
     * tres, son esas tres y no se mezclan con las de la orden: lo contrario
     * mandaría a un cliente soportes de dispensaciones que iban en otra caja.
     *
     * @return \Illuminate\Support\Collection<int, ReferenciaCliente>
     */
    public function referenciasEfectivas(): \Illuminate\Support\Collection
    {
        $propias = $this->relationLoaded('referencias')
            ? $this->referencias
            : $this->referencias()->get();

        if ($propias->isNotEmpty()) {
            return $propias;
        }

        $orden = $this->relationLoaded('orden') ? $this->orden : $this->orden()->first();

        if (! $orden) {
            return collect();
        }

        // Carga explicita sobre la orden, no sobre el paquete: si quien llama ya
        // trajo la orden pero sin sus referencias, `loadMissing('orden.…')` la
        // da por resuelta y el acceso siguiente seria una carga perezosa.
        $orden->loadMissing('referencias');

        return $orden->referencias;
    }

    /**
     * El peso que cuenta para la carga del mensajero.
     *
     * Un peso desconocido vale cero. No es que pese cero: es que no sabemos
     * cuánto pesa, y la única alternativa —tratarlo como infinito, o impedir
     * la asignación— bloquearía el despacho de un envío perfectamente normal
     * por un dato que casi nunca decide nada. Cuando sí decide —refrigerados
     * voluminosos, moto contra furgón— el operador lo ve en pantalla y asigna
     * a mano.
     */
    public function pesoParaCapacidad(): float
    {
        return (float) ($this->peso_kg ?? 0);
    }

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

    /**
     * La entrega vigente.
     *
     * El filtro no es decorativo: desde que una entrega se puede reabrir sin
     * borrar la anterior, un paquete puede tener varias filas en `entregas`, y
     * sin el `where` esta relación devolvería la primera que encuentre la base
     * —normalmente la equivocada, porque es la más vieja—.
     */
    public function entrega(): HasOne
    {
        return $this->hasOne(Entrega::class)->where('vigente', true);
    }

    /** Todas, incluida la reemplazada. Para el soporte y la auditoría. */
    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class)->orderBy('entregada_en');
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
