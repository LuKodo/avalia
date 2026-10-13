<?php

namespace App\Models;

use App\Enums\TipoTramo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un trayecto del paquete: de un punto a otro, con un mensajero y su prueba.
 *
 * Ver la migración `crear_tramos` para el porqué del modelo.
 */
class Tramo extends Model
{
    public const PENDIENTE  = 'pendiente';
    public const ASIGNADO   = 'asignado';
    public const EN_CURSO   = 'en_curso';
    public const CERRADO    = 'cerrado';
    public const CANCELADO  = 'cancelado';

    protected $fillable = [
        'paquete_id', 'orden', 'tipo',
        'origen_tipo', 'origen_id', 'destino_tipo', 'destino_id',
        'mensajero_id', 'vehiculo_id', 'estado',
        'programado_desde', 'programado_hasta',
        'iniciado_en', 'cerrado_en',
        'firma_id', 'foto_id', 'contraparte_nombre', 'contraparte_documento',
        'cierre_latitud', 'cierre_longitud', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'tipo'             => TipoTramo::class,
            'orden'            => 'integer',
            'programado_desde' => 'datetime',
            'programado_hasta' => 'datetime',
            'iniciado_en'      => 'datetime',
            'cerrado_en'       => 'datetime',
            'cierre_latitud'   => 'float',
            'cierre_longitud'  => 'float',
        ];
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class);
    }

    public function mensajero(): BelongsTo
    {
        return $this->belongsTo(Mensajero::class);
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    /**
     * El punto de partida, sea sede o destinatario.
     *
     * No es una relación de Eloquent —`morphTo` exigiría renombrar columnas a
     * la convención `*_type` con el nombre completo de la clase— y aquí se
     * guarda un nombre corto y legible en la base. Se resuelve a mano, que para
     * dos tablas posibles es más simple que arrastrar la convención.
     */
    public function origen(): Sede|Destinatario|null
    {
        return $this->resolver($this->origen_tipo, $this->origen_id);
    }

    public function destino(): Sede|Destinatario|null
    {
        return $this->resolver($this->destino_tipo, $this->destino_id);
    }

    private function resolver(?string $tipo, ?int $id): Sede|Destinatario|null
    {
        if ($tipo === null || $id === null) {
            return null;
        }

        return match ($tipo) {
            'sede'         => Sede::find($id),
            'destinatario' => Destinatario::find($id),
            default        => null,
        };
    }

    /** Lo que falta capturar para poder cerrarlo. */
    public function controlesPendientes(): array
    {
        $tiene = [
            'firma'       => $this->firma_id !== null,
            'foto'        => $this->foto_id !== null,
            'contraparte' => ! empty($this->contraparte_nombre),
        ];

        return array_values(array_filter(
            $this->tipo->exige(),
            fn (string $c) => ($tiene[$c] ?? true) === false
        ));
    }

    public function estaAbierto(): bool
    {
        return in_array($this->estado, [self::PENDIENTE, self::ASIGNADO, self::EN_CURSO], true);
    }

    /**
     * ¿Se puede trabajar ya, o está programado para más adelante?
     *
     * Un tramo programado para el jueves no debe aparecer hoy en la lista del
     * mensajero: verlo antes de tiempo solo consigue que lo cierre antes de
     * tiempo.
     */
    public function disponible(): bool
    {
        return $this->programado_desde === null || $this->programado_desde->isPast();
    }

    public function scopeAbiertos(Builder $q): Builder
    {
        return $q->whereIn('estado', [self::PENDIENTE, self::ASIGNADO, self::EN_CURSO]);
    }

    public function scopeDisponibles(Builder $q): Builder
    {
        return $q->where(fn ($s) => $s->whereNull('programado_desde')->orWhere('programado_desde', '<=', now()));
    }
}
