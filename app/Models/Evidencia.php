<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Archivo de evidencia: firma, fotografía, documento o acta.
 *
 * Nunca se sirve por URL pública predecible. El acceso pasa por un enlace
 * firmado de vigencia corta y queda registrado en `auditoria_accesos`, porque
 * la fotografía de un receptor es un dato personal, no un recurso estático.
 */
class Evidencia extends Model
{
    use HasUuids;

    protected $table = 'evidencias';

    protected $fillable = [
        'id', 'paquete_id', 'tipo', 'ruta', 'mime', 'bytes',
        'checksum', 'capturada_en', 'sincronizada',
        'entregada_al_cliente_en', 'referencia_externa',
        'intentos_envio', 'ultimo_error_envio',
    ];

    protected function casts(): array
    {
        return [
            'capturada_en' => 'datetime',
            'entregada_al_cliente_en' => 'datetime',
            'recibida_en'  => 'datetime',
            'sincronizada' => 'boolean',
        ];
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class);
    }

    /**
     * Enlace temporal firmado. Vigencia corta por defecto: 10 minutos.
     *
     * Se devuelve RELATIVO, sin esquema ni host. La firma de Laravel para el
     * disco local se valida de forma relativa, así que el enlace funciona sea
     * cual sea la dirección por la que se entró — `localhost`, `127.0.0.1` o la
     * IP del equipo en la red local. Devolverlo absoluto lo ataba a `APP_URL`,
     * y desde el celular de un mensajero apuntaría a un `localhost` que en ese
     * teléfono no existe.
     */
    public function enlaceTemporal(int $minutos = 10): string
    {
        $absoluto = Storage::disk($this->disco())->temporaryUrl(
            $this->ruta,
            now()->addMinutes($minutos)
        );

        $partes = parse_url($absoluto);

        return ($partes['path'] ?? $absoluto).(isset($partes['query']) ? '?'.$partes['query'] : '');
    }

    public function disco(): string
    {
        return config('filesystems.default', 'local');
    }

    /**
     * Verifica que el archivo almacenado sigue siendo el que se recibió. Un
     * checksum que no coincide es un incidente de integridad, no un fallo de
     * lectura.
     */
    public function integridadIntacta(): bool
    {
        if ($this->checksum === null) {
            return true;
        }

        $disco = Storage::disk($this->disco());

        if (! $disco->exists($this->ruta)) {
            return false;
        }

        return hash_equals($this->checksum, hash('sha256', $disco->get($this->ruta)));
    }

    public function etiqueta(): string
    {
        return match ($this->tipo) {
            'firma'          => 'Firma del receptor',
            'foto_receptor'  => 'Fotografía del receptor',
            'foto_indicador' => 'Fotografía del indicador de temperatura',
            'foto_empaque'   => 'Fotografía del empaque',
            'documento'      => 'Documento de identidad',
            'acta'           => 'Acta de entrega',
            default          => ucfirst(str_replace('_', ' ', $this->tipo)),
        };
    }
}
