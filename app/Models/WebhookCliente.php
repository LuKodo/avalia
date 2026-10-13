<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * Punto de entrega de notificaciones hacia el sistema del cliente.
 *
 * Con un webhook, el cliente deja de preguntar: su ERP se entera del cambio de
 * estado en el momento en que ocurre. Es la integración que más reduce llamadas
 * a la mesa de servicio.
 */
class WebhookCliente extends Model
{
    protected $table = 'webhooks_cliente';

    protected $fillable = ['cliente_id', 'nombre', 'url', 'secreto', 'eventos', 'activo'];

    protected $hidden = ['secreto'];

    protected function casts(): array
    {
        return [
            'eventos'         => 'array',
            'activo'          => 'boolean',
            'ultimo_exito_en' => 'datetime',
            'desactivado_en'  => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaWebhook::class, 'webhook_id');
    }

    protected function secreto(): Attribute
    {
        return Attribute::make(
            get: fn (?string $v) => $v === null ? null : Crypt::decryptString($v),
            set: fn (string $v) => Crypt::encryptString($v),
        );
    }

    public function escucha(string $evento): bool
    {
        $suscritos = $this->eventos ?? [];

        return $suscritos === [] || in_array($evento, $suscritos, true) || in_array('*', $suscritos, true);
    }

    /**
     * Firma HMAC del cuerpo. El cliente la verifica para saber que la
     * notificación viene de Avalia y no de cualquiera que conozca su URL.
     */
    public function firmar(string $cuerpo): string
    {
        return 'sha256='.hash_hmac('sha256', $cuerpo, $this->secreto ?? '');
    }

    /**
     * Un destino que falla de forma sostenida se desactiva solo. Reintentar
     * indefinidamente contra un endpoint muerto llena la cola y retrasa las
     * notificaciones de los clientes que sí están escuchando.
     */
    /*
     * Los tres campos de abajo —`fallos_consecutivos`, `ultimo_exito_en` y
     * `desactivado_en`— se escriben con `forceFill` y NO están en `$fillable`,
     * a propósito.
     *
     * Son contadores que lleva el sistema, no datos que el cliente pueda fijar:
     * meterlos en `$fillable` permitiría que una petición de la API pusiera sus
     * propios fallos a cero o reactivara un webhook que nosotros apagamos.
     *
     * Hacía falta decirlo porque estos métodos estaban escritos y REVENTABAN:
     * la aplicación corre con `preventSilentlyDiscardingAttributes`, así que
     * `update()` con un campo no rellenable lanza excepción en vez de ignorarlo
     * callando. Nunca se había visto porque nadie disparaba webhooks todavía.
     */
    public function registrarFallo(int $maximo = 20): void
    {
        $this->increment('fallos_consecutivos');

        if ($this->fallos_consecutivos >= $maximo) {
            $this->forceFill(['activo' => false, 'desactivado_en' => now()])->save();
        }
    }

    public function registrarExito(): void
    {
        $this->forceFill(['fallos_consecutivos' => 0, 'ultimo_exito_en' => now()])->save();
    }
}
