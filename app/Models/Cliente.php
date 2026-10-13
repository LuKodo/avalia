<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AutenticablePorToken;
use Illuminate\Contracts\Auth\Authenticatable as ContratoAutenticable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

/**
 * Un cliente de la mensajería: hospital, clínica, EPS, laboratorio o droguería.
 *
 * Es tokenable de Sanctum por sí mismo: los tokens de la API de integración
 * pertenecen al cliente, no a una persona. Así, cuando el analista que montó la
 * integración se va de la institución, el token sigue siendo válido y auditable
 * sin quedar colgando de una cuenta personal desactivada.
 */
class Cliente extends Model implements ContratoAutenticable
{
    /**
     * `Authenticatable` no está aquí para que un cliente «inicie sesión»: no
     * tiene contraseña ni la va a tener. Está porque todo el andamiaje de
     * Laravel —el límite de tasa, el registro de acceso, las políticas— asume
     * que quien viene autenticado sabe responder `getAuthIdentifier()`. Sin
     * esto, un token de integración válido revienta en el middleware de
     * throttle antes de llegar al controlador.
     */
    use AutenticablePorToken, HasApiTokens, HasFactory, SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'codigo', 'razon_social', 'nit', 'email_notificaciones', 'telefono',
        'etiqueta_referencia', 'tipo_referencia_defecto', 'referencia_obligatoria',
        'condiciones_comerciales', 'activo',
        'integracion_activa', 'integracion_tipo', 'integracion_config', 'integracion_token',
    ];

    /**
     * El token de integración no sale nunca de aquí por accidente.
     *
     * `$hidden` lo deja fuera de toda serialización —respuestas de la API,
     * `toArray()`, vuelcos de registro—. Para usarlo hay que pedirlo
     * explícitamente, que es exactamente la fricción que debe tener leer una
     * credencial ajena.
     */
    protected $hidden = ['integracion_token'];

    protected function casts(): array
    {
        return [
            'condiciones_comerciales' => 'array',
            'referencia_obligatoria'  => 'boolean',
            'activo'                  => 'boolean',
            'integracion_activa'      => 'boolean',
            'integracion_config'      => 'array',

            /*
             * Cifrado en columna. Es la primera credencial de un TERCERO que
             * guarda este sistema, y no se parece a una contraseña de usuario:
             * aquella se resume y nunca se recupera, esta hay que poder leerla
             * para usarla. Cifrarla con la clave de la aplicación es lo que
             * evita que un volcado de la base la entregue en claro.
             *
             * Consecuencia que conviene tener presente: si se regenera
             * `APP_KEY`, estos tokens dejan de poder descifrarse y hay que
             * volver a capturarlos.
             */
            'integracion_token'       => 'encrypted',
        ];
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }

    public function ordenes(): HasMany
    {
        return $this->hasMany(Orden::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(WebhookCliente::class);
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(ReferenciaCliente::class);
    }
}
