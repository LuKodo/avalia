<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Prueba de entrega: quién recibió, cuándo, dónde y con qué evidencia.
 *
 * Nombre y documento del receptor son datos personales de categoría sensible en
 * el contexto de salud, así que se cifran en columna. Para poder buscar sin
 * descifrar toda la tabla se guarda además un hash con sal del documento.
 */
class Entrega extends Model
{
    protected $table = 'entregas';

    protected $fillable = [
        'paquete_id', 'receptor_nombre', 'receptor_documento', 'receptor_cargo',
        'latitud', 'longitud', 'precision_m', 'distancia_a_sede_m',
        'geolocalizacion_coherente', 'entregada_en', 'entregada_en_dispositivo',
        'desfase_reloj_seg', 'dentro_de_ventana', 'firma_id', 'foto_receptor_id',
        'consentimiento_datos',
    ];

    protected function casts(): array
    {
        return [
            'entregada_en'              => 'datetime',
            'entregada_en_dispositivo'  => 'datetime',
            'geolocalizacion_coherente' => 'boolean',
            'dentro_de_ventana'         => 'boolean',
            'consentimiento_datos'      => 'boolean',
            'latitud'                   => 'float',
            'longitud'                  => 'float',
        ];
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class);
    }

    public function firma(): BelongsTo
    {
        return $this->belongsTo(Evidencia::class, 'firma_id');
    }

    public function fotoReceptor(): BelongsTo
    {
        return $this->belongsTo(Evidencia::class, 'foto_receptor_id');
    }

    protected function receptorNombre(): Attribute
    {
        return Attribute::make(
            get: fn (?string $v) => $v === null ? null : self::descifrar($v),
            set: fn (?string $v) => $v === null ? null : Crypt::encryptString($v),
        );
    }

    protected function receptorDocumento(): Attribute
    {
        return Attribute::make(
            get: fn (?string $v) => $v === null ? null : self::descifrar($v),
            set: function (?string $v) {
                if ($v === null) {
                    return null;
                }

                return [
                    'receptor_documento'      => Crypt::encryptString($v),
                    'receptor_documento_hash' => self::hashDocumento($v),
                ];
            },
        );
    }

    /**
     * Documento enmascarado para las vistas de operación. El valor completo solo
     * se revela en el soporte de entrega del propio cliente y deja registro en
     * `auditoria_accesos`.
     */
    public function documentoEnmascarado(): string
    {
        $doc = $this->receptor_documento ?? '';
        $n   = strlen($doc);

        return $n <= 4 ? str_repeat('*', $n) : str_repeat('*', $n - 4).substr($doc, -4);
    }

    public static function hashDocumento(string $documento): string
    {
        $limpio = preg_replace('/[^0-9A-Za-z]/', '', $documento) ?? '';

        return hash_hmac('sha256', strtoupper($limpio), (string) config('app.key'));
    }

    private static function descifrar(string $valor): string
    {
        try {
            return Crypt::decryptString($valor);
        } catch (\Throwable) {
            // Un valor que no descifra es un dato corrupto o cifrado con otra
            // clave. Se reporta como tal en vez de romper la pantalla completa.
            return '[no descifrable]';
        }
    }
}
