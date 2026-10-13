<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'nombre', 'email', 'password', 'documento', 'telefono',
        'cliente_id', 'activo', 'doble_factor',
    ];

    protected $hidden = [
        'password', 'remember_token', 'doble_factor_secreto',
    ];

    protected function casts(): array
    {
        return [
            'email_verificado_en' => 'datetime',
            'ultimo_acceso_en'    => 'datetime',
            'password'            => 'hashed',
            'activo'              => 'boolean',
            'doble_factor'        => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function mensajero(): HasOne
    {
        return $this->hasOne(Mensajero::class);
    }

    /** Los roles administrativos exigen doble factor sin excepción. */
    public function requiereDobleFactor(): bool
    {
        return $this->hasAnyRole(['administrador', 'operador']);
    }

    public function esCliente(): bool
    {
        return $this->cliente_id !== null;
    }
}
