<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un punto de entrega que no es una sede: un afiliado, un paciente, un tercero.
 *
 * Existe separado de `sedes` porque los datos de una persona y los de una
 * bodega no se gobiernan igual. Una sede se administra; un paciente se protege:
 * su dato tiene finalidad declarada, autorización y fecha de supresión, y este
 * modelo es el que sostiene esas tres cosas.
 */
class Destinatario extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'destinatarios';

    protected $fillable = [
        'cliente_id', 'tipo', 'nombre', 'tipo_documento', 'documento',
        'telefono', 'email', 'zona_id', 'direccion', 'detalle_acceso',
        'ciudad', 'rural', 'latitud', 'longitud', 'coordenada_verificada',
        'ventanas_atencion', 'origen_dato', 'persistente',
        'autorizacion_en', 'finalidad', 'suprimir_desde', 'activo',
    ];

    /*
     * El documento y el teléfono no viajan en una serialización por descuido.
     * Quien los necesite tiene que pedirlos explícitamente, y ese acceso queda
     * en la bitácora: es el mismo criterio que ya rige el documento del receptor
     * en el soporte de entrega.
     */
    protected $hidden = ['documento', 'telefono', 'email'];

    protected function casts(): array
    {
        return [
            'coordenada_verificada' => 'boolean',
            'rural'                 => 'boolean',
            'persistente'           => 'boolean',
            'activo'                => 'boolean',
            'ventanas_atencion'     => 'array',
            'autorizacion_en'       => 'datetime',
            'suprimir_desde'        => 'date',
            'latitud'               => 'float',
            'longitud'              => 'float',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    public function ordenes(): HasMany
    {
        return $this->hasMany(Orden::class);
    }

    // ------------------------------------------------------------------ ámbito

    public function scopeDelCliente(Builder $q, int $clienteId): Builder
    {
        return $q->where('cliente_id', $clienteId);
    }

    /** El padrón que el cliente mantiene, para elegir al radicar. */
    public function scopeDelPadron(Builder $q): Builder
    {
        return $q->where('persistente', true)->where('activo', true);
    }

    /**
     * Los que ya cumplieron su retención.
     *
     * No los borra: los enumera. La supresión de un dato personal se ejecuta con
     * una decisión detrás, no como efecto colateral de una consulta.
     */
    public function scopePorSuprimir(Builder $q): Builder
    {
        return $q->whereNotNull('suprimir_desde')->whereDate('suprimir_desde', '<=', now());
    }

    // ----------------------------------------------------------------- ayudas

    public function tieneCoordenada(): bool
    {
        return $this->latitud !== null && $this->longitud !== null;
    }

    public function direccionCompleta(): string
    {
        return trim(implode(' · ', array_filter([
            $this->direccion,
            $this->detalle_acceso,
            $this->ciudad,
        ])));
    }

    /**
     * El documento enmascarado, que es lo que se muestra por defecto.
     *
     * El valor completo solo se entrega a quien tiene por qué verlo, y esa
     * consulta deja registro.
     */
    public function documentoEnmascarado(): ?string
    {
        if ($this->documento === null) {
            return null;
        }

        return str_repeat('*', max(0, strlen($this->documento) - 4))
            .substr($this->documento, -4);
    }

    public function etiquetaTipo(): string
    {
        return match ($this->tipo) {
            'afiliado' => 'Afiliado',
            'paciente' => 'Paciente',
            'tercero'  => 'Tercero autorizado',
            default    => ucfirst((string) $this->tipo),
        };
    }

    /**
     * ¿Se puede entregar a esta hora?
     *
     * Devuelve null cuando el destinatario no declaró ventanas: no es lo mismo
     * «no atiende ahora» que «no dijo cuándo atiende», y el despacho tiene que
     * poder distinguirlo antes de culpar a nadie por una entrega fallida.
     *
     * Formato esperado: [{"dia": 1..7, "desde": "08:00", "hasta": "12:00"}]
     */
    public function atiendeEn(\DateTimeInterface $momento): ?bool
    {
        $ventanas = $this->ventanas_atencion;

        if (empty($ventanas)) {
            return null;
        }

        $dia  = (int) $momento->format('N');
        $hora = $momento->format('H:i');

        foreach ($ventanas as $v) {
            if ((int) ($v['dia'] ?? 0) !== $dia) {
                continue;
            }

            if ($hora >= ($v['desde'] ?? '00:00') && $hora <= ($v['hasta'] ?? '23:59')) {
                return true;
            }
        }

        return false;
    }
}
