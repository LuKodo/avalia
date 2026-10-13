<?php

namespace App\Models;

use App\Enums\TipoReferencia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia definida por el cliente para un envío.
 *
 * El valor se guarda tal como el cliente lo escribió (para mostrarlo igual en el
 * acta) y además normalizado, para que «OC-1234», «oc 1234» y «OC1234»
 * encuentren el mismo envío. Sin esa normalización, la consulta por referencia
 * falla justo cuando más se necesita: cuando alguien la copia de un correo.
 */
class ReferenciaCliente extends Model
{
    use HasFactory;

    protected $table = 'referencias_cliente';

    protected $fillable = ['orden_id', 'cliente_id', 'tipo', 'valor', 'valor_normalizado'];

    protected function casts(): array
    {
        return ['tipo' => TipoReferencia::class];
    }

    protected static function booted(): void
    {
        static::saving(function (self $ref) {
            $ref->valor_normalizado = self::normalizar($ref->valor);
        });
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public static function normalizar(string $valor): string
    {
        $sinAcentos = strtr($valor, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sinAcentos) ?? '');
    }
}
