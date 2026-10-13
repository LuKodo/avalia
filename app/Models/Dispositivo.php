<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Sonda de temperatura y ubicación instalada en un vehículo.
 *
 * Mide el compartimento, no el paquete. Los paquetes que van a bordo heredan
 * sus lecturas mientras estén bajo custodia de ese vehículo.
 */
class Dispositivo extends Model
{
    use SoftDeletes;

    protected $table = 'dispositivos';

    protected $fillable = [
        'serial', 'proveedor', 'modelo', 'alias', 'vehiculo_id',
        'mide_temperatura', 'mide_ubicacion', 'rango_min', 'rango_max',
        'intervalo_seg', 'activo',
    ];

    protected $hidden = ['secreto_ingesta'];

    protected function casts(): array
    {
        return [
            'mide_temperatura'   => 'boolean',
            'mide_ubicacion'     => 'boolean',
            'activo'             => 'boolean',
            'rango_min'          => 'float',
            'rango_max'          => 'float',
            'ultima_temperatura' => 'float',
            'ultima_latitud'     => 'float',
            'ultima_longitud'    => 'float',
            'ultima_lectura_en'  => 'datetime',
        ];
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function telemetria(): HasMany
    {
        return $this->hasMany(Telemetria::class);
    }

    public function excursiones(): HasMany
    {
        return $this->hasMany(Excursion::class);
    }

    public function instalaciones(): HasMany
    {
        return $this->hasMany(InstalacionDispositivo::class);
    }

    protected function secretoIngesta(): Attribute
    {
        return Attribute::make(
            get: fn (?string $v) => $v === null ? null : Crypt::decryptString($v),
            set: fn (?string $v) => $v === null ? null : Crypt::encryptString($v),
        );
    }

    public function generarSecreto(): string
    {
        $secreto = Str::random(48);

        $this->secreto_ingesta = $secreto;
        $this->save();

        return $secreto;
    }

    /**
     * Firma esperada de una carga entrante. El dispositivo —o la plataforma del
     * proveedor que reenvía por webhook— debe acompañar cada envío con esta
     * firma: sin ella, cualquiera que conozca el serial podría inyectar
     * lecturas falsas y encubrir una ruptura de cadena de frío.
     */
    public function firmar(string $cuerpo): string
    {
        return hash_hmac('sha256', $cuerpo, $this->secreto_ingesta ?? '');
    }

    public function rangoVigilado(): array
    {
        $defecto = config('ltp.cadena_frio.rango_defecto');

        return [
            'min' => $this->rango_min ?? $defecto['min'],
            'max' => $this->rango_max ?? $defecto['max'],
        ];
    }

    public function enRango(float $valor): bool
    {
        $r = $this->rangoVigilado();

        return $valor >= $r['min'] && $valor <= $r['max'];
    }

    /**
     * Un dispositivo que dejó de reportar es tan grave como uno que reporta
     * fuera de rango: en ambos casos se perdió la vigilancia. El umbral es
     * tres veces su intervalo, para no alarmar por un reporte que se demoró.
     */
    public function silencioso(): bool
    {
        if ($this->ultima_lectura_en === null) {
            return true;
        }

        return $this->ultima_lectura_en->diffInSeconds(now()) > ($this->intervalo_seg * 3);
    }

    public function excursionAbierta(): ?Excursion
    {
        return $this->excursiones()->where('estado', 'abierta')->latest('inicio')->first();
    }
}
