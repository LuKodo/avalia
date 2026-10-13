<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un eslabón de la bitácora inmutable.
 *
 * El modelo bloquea update y delete también desde la aplicación, además del
 * disparador en la base de datos. Dos capas para el mismo invariante: una
 * protege del error de programación, la otra del acceso directo a la base.
 */
class EventoPaquete extends Model
{
    protected $table = 'eventos_paquete';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'estado_anterior'         => EstadoPaquete::class,
            'estado_nuevo'            => EstadoPaquete::class,
            'datos'                   => 'array',
            'ocurrido_en_dispositivo' => 'datetime',
            'registrado_en'           => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException(
                'La bitácora de eventos es de solo anexado. Para corregir, registre un evento nuevo.'
            );
        });

        static::deleting(function () {
            throw new \RuntimeException(
                'La bitácora de eventos es de solo anexado. Los eventos no se eliminan.'
            );
        });
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class);
    }

    public function evidencia(): BelongsTo
    {
        return $this->belongsTo(Evidencia::class, 'evidencia_id');
    }

    /**
     * Carga canónica que entra al hash. El orden de las claves es fijo y la
     * serialización determinista: si cambia, toda la cadena deja de verificar,
     * así que este método es intocable una vez que hay datos en producción.
     */
    public function cargaCanonica(): string
    {
        return json_encode([
            'id'            => $this->id,
            'secuencia'     => (int) $this->secuencia,
            'paquete_id'    => (int) $this->paquete_id,
            'estado_ant'    => $this->estado_anterior?->value,
            'estado_nue'    => $this->estado_nuevo->value,
            'tipo'          => $this->tipo_evento,
            'actor_tipo'    => $this->actor_tipo,
            'actor_id'      => $this->actor_id !== null ? (int) $this->actor_id : null,
            'dispositivo'   => $this->ocurrido_en_dispositivo?->toIso8601String(),
            'servidor'      => $this->registrado_en?->toIso8601String(),
            'lat'           => $this->latitud !== null ? round((float) $this->latitud, 7) : null,
            'lon'           => $this->longitud !== null ? round((float) $this->longitud, 7) : null,
            'datos'         => $this->datos,
            'evidencia_id'  => $this->evidencia_id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function hashEsperado(): string
    {
        return hash(
            config('ltp.trazabilidad.algoritmo_hash', 'sha256'),
            ($this->hash_anterior ?? '').$this->cargaCanonica()
        );
    }

    public function verifica(): bool
    {
        return hash_equals($this->hash, $this->hashEsperado());
    }
}
