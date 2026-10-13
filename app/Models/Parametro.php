<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Parámetros ajustables sin desplegar código.
 *
 * Los pesos del motor de asignación, los umbrales de alerta y las tolerancias
 * viven aquí porque la operación los va a mover en las primeras semanas. Pedir
 * un despliegue para cambiar un umbral garantiza que nadie lo cambie nunca.
 */
class Parametro extends Model
{
    protected $table = 'parametros';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor', 'tipo', 'descripcion', 'actualizado_por'];

    protected static function booted(): void
    {
        static::saved(fn (self $p) => Cache::forget("parametro:{$p->clave}"));
        static::deleted(fn (self $p) => Cache::forget("parametro:{$p->clave}"));
    }

    public static function obtener(string $clave, mixed $defecto = null): mixed
    {
        return Cache::remember("parametro:{$clave}", 300, function () use ($clave, $defecto) {
            $p = self::find($clave);

            return $p === null ? $defecto : $p->convertido();
        });
    }

    public function convertido(): mixed
    {
        return match ($this->tipo) {
            'int'   => (int) $this->valor,
            'float' => (float) $this->valor,
            'bool'  => filter_var($this->valor, FILTER_VALIDATE_BOOL),
            'json'  => json_decode($this->valor, true),
            default => $this->valor,
        };
    }
}
