<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un archivo que acompaña una PQRS.
 *
 * Lleva huella SHA-256 como las evidencias de entrega, y por la misma razón: la
 * foto que alguien adjunta a una queja puede terminar siendo prueba, y entonces
 * importa poder demostrar que es el mismo archivo que se recibió.
 */
class PqrsAdjunto extends Model
{
    protected $table = 'pqrs_adjuntos';

    protected $fillable = [
        'pqrs_id', 'nombre_original', 'ruta', 'mime', 'bytes', 'checksum',
    ];

    public function pqrs(): BelongsTo
    {
        return $this->belongsTo(Pqrs::class, 'pqrs_id');
    }

    public function esImagen(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** ¿El archivo sigue siendo el que se recibió? */
    public function integridadIntacta(): bool
    {
        if (! Storage::disk('local')->exists($this->ruta)) {
            return false;
        }

        return hash('sha256', Storage::disk('local')->get($this->ruta)) === $this->checksum;
    }

    public function pesoLegible(): string
    {
        $kb = $this->bytes / 1024;

        return $kb >= 1024
            ? number_format($kb / 1024, 1).' MB'
            : number_format($kb, 0).' KB';
    }
}
