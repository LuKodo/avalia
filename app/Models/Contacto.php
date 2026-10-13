<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una solicitud comercial llegada por el sitio público.
 *
 * No es un cliente todavía: es alguien que preguntó. Se guarda para poder
 * responderle y para saber qué sección del sitio trae interesados.
 */
class Contacto extends Model
{
    use SoftDeletes;

    protected $table = 'contactos';

    protected $fillable = [
        'nombre', 'institucion', 'correo', 'telefono', 'servicio', 'detalle',
        'estado', 'origen', 'ip', 'agente',
    ];

    protected function casts(): array
    {
        return ['atendido_en' => 'datetime'];
    }

    public function atendidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendido_por');
    }

    public function estadoEtiqueta(): string
    {
        return match ($this->estado) {
            'nuevo'      => 'Nuevo',
            'contactado' => 'Contactado',
            'cotizado'   => 'Cotizado',
            'ganado'     => 'Ganado',
            'perdido'    => 'Perdido',
            default      => ucfirst((string) $this->estado),
        };
    }
}
