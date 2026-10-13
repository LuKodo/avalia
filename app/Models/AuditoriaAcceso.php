<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de acceso a datos personales sensibles.
 *
 * Separado de la bitácora de eventos: distinto propósito y distinta política de
 * retención. Aquí queda quién miró el documento de un receptor, quién descargó
 * una fotografía y quién exportó un lote — que es exactamente lo que pregunta
 * una auditoría de protección de datos.
 */
class AuditoriaAcceso extends Model
{
    protected $table = 'auditoria_accesos';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'accion', 'recurso_tipo', 'recurso_id',
        'justificacion', 'ip', 'agente', 'ocurrido_en',
    ];

    protected function casts(): array
    {
        return ['ocurrido_en' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function registrar(
        string $accion,
        string $recursoTipo,
        ?int $recursoId = null,
        ?string $justificacion = null,
    ): self {
        return self::create([
            'user_id'       => auth()->id(),
            'accion'        => $accion,
            'recurso_tipo'  => $recursoTipo,
            'recurso_id'    => $recursoId,
            'justificacion' => $justificacion,
            'ip'            => request()?->ip(),
            'agente'        => substr((string) request()?->userAgent(), 0, 255),
            'ocurrido_en'   => now(),
        ]);
    }
}
