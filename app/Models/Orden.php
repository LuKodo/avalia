<?php

namespace App\Models;

use App\Enums\Criticidad;
use App\Enums\EstadoPaquete;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La orden es el acuerdo comercial con el cliente; el paquete es el objeto
 * físico que se mueve y se rastrea. Confundirlos impide entregas parciales y
 * rompe la auditoría, así que la separación se mantiene en todo el sistema.
 */
class Orden extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ordenes';

    protected $fillable = [
        'numero_guia', 'cliente_id', 'sede_origen_id', 'sede_destino_id',
        'destinatario_id', 'creada_por',
        'criticidad', 'ventana_desde', 'ventana_hasta', 'comprometida_en',
        'contacto_destino_nombre', 'contacto_destino_telefono', 'observaciones',
        'tarifa_base', 'recargo', 'total', 'estado_comercial',
    ];

    protected function casts(): array
    {
        return [
            'criticidad'      => Criticidad::class,
            'ventana_desde'   => 'datetime',
            'ventana_hasta'   => 'datetime',
            'comprometida_en' => 'datetime',
            'cerrada_en'      => 'datetime',
            'tarifa_base'     => 'decimal:2',
            'recargo'         => 'decimal:2',
            'total'           => 'decimal:2',
        ];
    }

    // ---------------------------------------------------------------- relaciones

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_origen_id');
    }

    /** La sede de destino, cuando el envío va a una sede. */
    public function destino(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_destino_id');
    }

    /** El destinatario, cuando el envío va a una persona y no a una sede. */
    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(Destinatario::class);
    }

    /**
     * La ruta que este envío debería seguir.
     *
     * Se traza una vez y queda congelada: es la referencia contra la que se
     * mide el desvío. Ver la migración `crear_rutas_planeadas`.
     */
    public function rutaPlaneada(): HasOne
    {
        return $this->hasOne(RutaPlaneada::class);
    }

    /**
     * A dónde va este envío, sea sede o persona.
     *
     * Todo lo que solo necesita «la dirección y el contacto del destino» debería
     * pasar por aquí: el despacho, la validación de la coordenada de entrega y
     * el soporte no tienen por qué saber cuál de las dos modalidades es. La base
     * garantiza que hay exactamente una, así que este método nunca devuelve
     * nulo en una orden válida.
     */
    public function puntoDestino(): Sede|Destinatario|null
    {
        return $this->destino ?? $this->destinatario;
    }

    /** ¿Va a domicilio de una persona en vez de a una sede? */
    public function esDomiciliaria(): bool
    {
        return $this->destinatario_id !== null;
    }

    public function paquetes(): HasMany
    {
        return $this->hasMany(Paquete::class);
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaCliente::class);
    }

    public function enlacesRastreo(): HasMany
    {
        return $this->hasMany(EnlaceRastreo::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    // ------------------------------------------------------------------ consulta

    /**
     * Búsqueda por la guía interna de Avalia. Es la llave de la recogida y el envío,
     * así que se acepta con o sin separadores y sin distinguir mayúsculas:
     * quien la teclea desde un correo no debe pelear con el formato.
     */
    public function scopePorGuia(Builder $q, string $guia): Builder
    {
        return $q->where('numero_guia', self::normalizarGuia($guia));
    }

    /**
     * Búsqueda por la referencia que definió el CLIENTE. Cuando el hospital
     * radica, conoce su número de radicado, no la guía Avalia; esta es la entrada
     * natural desde sus sistemas.
     */
    public function scopePorReferencia(Builder $q, string $valor, ?string $tipo = null, ?int $clienteId = null): Builder
    {
        return $q->whereHas('referencias', function (Builder $r) use ($valor, $tipo, $clienteId) {
            $r->where('valor_normalizado', ReferenciaCliente::normalizar($valor));

            if ($tipo !== null) {
                $r->where('tipo', $tipo);
            }

            if ($clienteId !== null) {
                $r->where('cliente_id', $clienteId);
            }
        });
    }

    /** Aislamiento estricto por cliente, aplicado en la capa de consulta. */
    public function scopeDelCliente(Builder $q, ?int $clienteId): Builder
    {
        return $clienteId === null ? $q : $q->where('cliente_id', $clienteId);
    }

    public static function normalizarGuia(string $guia): string
    {
        $limpia = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $guia) ?? '');

        // AVL2608210417 -> AVL-260821-0417
        if (preg_match('/^([A-Z]{2,4})(\d{6})(\d{3,6})$/', $limpia, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return strtoupper(trim($guia));
    }

    // ------------------------------------------------------------------- estado

    /**
     * Estado consolidado de la orden a partir de sus paquetes. Con un solo
     * paquete es su estado; con varios, refleja lo que un humano diría:
     * «entregada» solo cuando todos llegaron, «parcial» cuando faltan.
     */
    public function estadoConsolidado(): string
    {
        $this->loadMissing('paquetes');

        // `estado` viene casteado a enum, no a cadena: trabajar con instancias
        // y convertir a valor solo al devolver evita comparar peras con manzanas.
        $estados = $this->paquetes->map(fn (Paquete $p) => $p->estado);

        if ($estados->isEmpty()) {
            return EstadoPaquete::Creado->value;
        }

        $entregados = $estados->filter(fn (EstadoPaquete $e) => $e === EstadoPaquete::Entregado)->count();

        if ($entregados === $estados->count()) {
            return EstadoPaquete::Entregado->value;
        }

        if ($estados->contains(fn (EstadoPaquete $e) => $e === EstadoPaquete::Siniestro)) {
            return EstadoPaquete::Siniestro->value;
        }

        if ($estados->contains(fn (EstadoPaquete $e) => $e === EstadoPaquete::Novedad)) {
            return EstadoPaquete::Novedad->value;
        }

        if ($entregados > 0) {
            return 'entregado_parcial';
        }

        // El menos avanzado manda: la orden no va más rápido que su paquete más lento.
        return $estados->sortBy(fn (EstadoPaquete $e) => $e->rango())->first()->value;
    }

    public function estaEntregada(): bool
    {
        return $this->estadoConsolidado() === EstadoPaquete::Entregado->value;
    }

    /**
     * Tarifa de la orden: base de la zona de destino más recargo por criticidad.
     *
     * Un solo paquete refrigerado hace refrigerada toda la orden, porque el
     * vehículo que la mueve es el mismo: no se cobra por el promedio de la carga,
     * se cobra por lo que la carga obliga a usar.
     */
    public function calcularTarifa(): self
    {
        $this->loadMissing('destino.zona', 'paquetes');

        $zona = $this->destino?->zona;

        $refrigerado = $this->paquetes->contains(fn (Paquete $p) => $p->exigeCadenaFrio());

        $base    = $zona?->tarifaPara($refrigerado) ?? 0.0;
        $recargo = $base * $this->criticidad->recargo();

        $this->forceFill([
            'tarifa_base' => round($base, 2),
            'recargo'     => round($recargo, 2),
            'total'       => round($base + $recargo, 2),
        ])->save();

        return $this;
    }

    public function dentroDeVentana(): ?bool
    {
        if (! $this->estaEntregada() || $this->comprometida_en === null) {
            return null;
        }

        $ultima = $this->paquetes->max('entregado_en');

        return $ultima !== null && $ultima <= $this->comprometida_en;
    }
}
