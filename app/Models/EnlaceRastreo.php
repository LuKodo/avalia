<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Enlace público de rastreo.
 *
 * El camino de menor fricción para el cliente: un URL que reenvía por correo o
 * WhatsApp a quien esté preguntando, sin credenciales y sin instalar nada. Se
 * guarda en base de datos —en vez de usar solo un URL firmado— porque un enlace
 * que no se puede revocar antes de su vencimiento no es aceptable cuando expone
 * datos de un envío de salud.
 */
class EnlaceRastreo extends Model
{
    use HasUuids;

    protected $table = 'enlaces_rastreo';

    protected $fillable = ['orden_id', 'token', 'creado_por', 'expira_en'];

    protected function casts(): array
    {
        return [
            'expira_en'        => 'datetime',
            'revocado_en'      => 'datetime',
            'ultima_visita_en' => 'datetime',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class);
    }

    public static function emitir(Orden $orden, ?User $creador = null, int $diasVigencia = 30): self
    {
        return self::create([
            'orden_id'   => $orden->id,
            'token'      => Str::random(48),
            'creado_por' => $creador?->id,
            'expira_en'  => now()->addDays($diasVigencia),
        ]);
    }

    public function vigente(): bool
    {
        return $this->revocado_en === null && $this->expira_en->isFuture();
    }

    public function revocar(): void
    {
        $this->update(['revocado_en' => now()]);
    }

    public function registrarVisita(): void
    {
        $this->increment('visitas');
        $this->update(['ultima_visita_en' => now()]);
    }

    public function url(): string
    {
        return url("/rastreo/{$this->token}");
    }
}
