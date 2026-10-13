<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditoriaAcceso;
use App\Models\Cliente;
use App\Models\Dispositivo;
use App\Models\InstalacionDispositivo;
use App\Models\Mensajero;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\Zona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Alta y edición del catálogo maestro.
 *
 * Es lo que hay que cargar ANTES de poder radicar nada: clientes, sus sedes,
 * las zonas que fijan la tarifa, los vehículos y los mensajeros. Sin esto el
 * motor de asignación no tiene con qué trabajar y la pantalla de radicación no
 * tiene qué ofrecer.
 *
 * Toda alta y todo cambio deja registro en `auditoria_accesos`: el catálogo
 * define quién puede transportar qué, así que modificarlo es una decisión
 * operativa con consecuencias, no un ajuste cosmético.
 */
class CatalogoController extends Controller
{
    // ======================================================== clientes =======

    public function crearCliente(Request $request): JsonResponse
    {
        $datos = $this->validarCliente($request);

        $cliente = Cliente::create($datos + [
            'codigo' => $datos['codigo'] ?? $this->siguienteCodigo(),
            'activo' => true,
        ]);

        $this->auditar('crear_cliente', 'cliente', $cliente->id, $cliente->razon_social);

        return response()->json($this->cliente($cliente), 201);
    }

    public function actualizarCliente(Request $request, Cliente $cliente): JsonResponse
    {
        $cliente->update($this->validarCliente($request, $cliente));

        $this->auditar('editar_cliente', 'cliente', $cliente->id, $cliente->razon_social);

        return response()->json($this->cliente($cliente->fresh()));
    }

    public function alternarCliente(Cliente $cliente): JsonResponse
    {
        // Desactivar un cliente no borra su historia: sus envíos y sus soportes
        // siguen consultables. Solo deja de poder radicar nuevos.
        $cliente->update(['activo' => ! $cliente->activo]);

        $this->auditar(
            $cliente->activo ? 'activar_cliente' : 'desactivar_cliente',
            'cliente', $cliente->id, $cliente->razon_social
        );

        return response()->json(['activo' => $cliente->activo]);
    }

    /** @return array<string, mixed> */
    private function validarCliente(Request $request, ?Cliente $cliente = null): array
    {
        return $request->validate([
            'codigo'       => ['nullable', 'string', 'max:20', Rule::unique('clientes')->ignore($cliente)],
            'razon_social' => ['required', 'string', 'max:180'],
            'nit'          => ['required', 'string', 'max:30', Rule::unique('clientes')->ignore($cliente)],
            'email_notificaciones' => ['nullable', 'email', 'max:180'],
            'telefono'     => ['nullable', 'string', 'max:30'],
            // Cómo llama el cliente a SU referencia. Se muestra literalmente en
            // el portal y en el acta: si dice «radicado», el sistema dice
            // «radicado», no un genérico nuestro.
            'etiqueta_referencia' => ['required', 'string', 'max:60'],
            'tipo_referencia_defecto' => ['required', 'string', Rule::in(
                array_column(\App\Enums\TipoReferencia::catalogo(), 'valor')
            )],
            'referencia_obligatoria' => ['required', 'boolean'],
        ]);
    }

    // ============================== integración del cliente ================

    /**
     * Configura, o apaga, la integración de un cliente.
     *
     * Va en su propio endpoint y no dentro de «actualizar cliente» por dos
     * razones: aquí se maneja una credencial —y eso merece su propia entrada en
     * la auditoría, distinta de «alguien corrigió el teléfono»— y porque el
     * token tiene una regla propia: solo se escribe si viene.
     *
     * Esa regla importa más de lo que parece. La pantalla nunca muestra el
     * token, así que no puede devolverlo al guardar; si lo escribiéramos
     * siempre, cada vez que alguien corrigiera el bucket borraría la
     * credencial sin enterarse, y la integración dejaría de funcionar por un
     * cambio que no tenía nada que ver.
     */
    public function guardarIntegracion(Request $request, Cliente $cliente): JsonResponse
    {
        $datos = $request->validate([
            'activa'  => ['required', 'boolean'],
            'tipo'    => ['nullable', 'string', Rule::in(['cassandra'])],
            'api_url' => ['nullable', 'url', 'max:255'],
            'bucket'  => ['nullable', 'string', 'max:120'],
            'prefijo' => ['nullable', 'string', 'max:20'],
            'token'   => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * Activarla exige tener con qué. Una integración encendida y a medio
         * configurar no falla al guardarse: falla cuatro horas después, en un
         * trabajo de cola, cuando ya nadie recuerda qué se tocó.
         */
        if ($datos['activa']) {
            $faltan = [];

            if (blank($datos['api_url'] ?? null)) { $faltan[] = 'la dirección de la API'; }
            if (blank($datos['bucket'] ?? null))  { $faltan[] = 'el bucket'; }
            if (blank($datos['token'] ?? null) && blank($cliente->integracion_token)) { $faltan[] = 'la credencial'; }

            if ($faltan) {
                abort(422, 'Para activar la integración falta '.implode(', ', $faltan).'.');
            }
        }

        $cliente->integracion_activa = $datos['activa'];
        $cliente->integracion_tipo   = $datos['tipo'] ?? 'cassandra';
        $cliente->integracion_config = [
            'api_url' => $datos['api_url'] ?? null,
            'bucket'  => $datos['bucket'] ?? null,
            'prefijo_referencia' => $datos['prefijo'] ?? null,
        ];

        if (filled($datos['token'] ?? null)) {
            $cliente->integracion_token = $datos['token'];
        }

        $cliente->save();

        /*
         * Se audita que se tocó y si se reemplazó la credencial. Nunca su valor:
         * el registro de auditoría es consultable, y un secreto escrito ahí
         * está tan expuesto como uno escrito en el código.
         */
        $this->auditar(
            'configurar_integracion_cliente',
            'cliente',
            $cliente->id,
            $cliente->razon_social
                .($datos['activa'] ? ' · activa' : ' · inactiva')
                .(filled($datos['token'] ?? null) ? ' · credencial reemplazada' : '')
        );

        return response()->json([
            'activa'      => (bool) $cliente->integracion_activa,
            'tipo'        => $cliente->integracion_tipo,
            'api_url'     => $cliente->integracion_config['api_url'] ?? null,
            'bucket'      => $cliente->integracion_config['bucket'] ?? null,
            'prefijo'     => $cliente->integracion_config['prefijo_referencia'] ?? null,
            'tiene_token' => filled($cliente->integracion_token),
        ]);
    }

    private function siguienteCodigo(): string
    {
        return sprintf('CLI-%03d', Cliente::withTrashed()->count() + 1);
    }

    // ============================== tokens de integración del cliente ========

    /**
     * Tokens de API de un cliente.
     *
     * Pertenecen al CLIENTE, no a una persona. Es la diferencia que importa:
     * cuando el analista que montó la integración se va de la institución, su
     * cuenta se desactiva y el token de la integración sigue vivo. Atarlo a una
     * persona es garantizar que un día la integración se caiga sola.
     */
    public function tokens(Cliente $cliente): JsonResponse
    {
        return response()->json([
            'cliente' => $cliente->razon_social,
            'tokens'  => $cliente->tokens()->orderByDesc('created_at')->get()
                ->map(fn ($t) => [
                    'id'        => $t->id,
                    'nombre'    => $t->name,
                    'habilidades' => $t->abilities,
                    'creado'    => $t->created_at?->toIso8601String(),
                    'expira'    => $t->expires_at?->toIso8601String(),
                    'vencido'   => $t->expires_at !== null && $t->expires_at->isPast(),
                    'ultimo_uso' => $t->last_used_at?->toIso8601String(),
                    'ultima_ip' => $t->ultima_ip,
                    'llamadas'  => $t->llamadas,
                ]),
            'url_base' => url('/api/v1'),
        ]);
    }

    public function crearToken(Request $request, Cliente $cliente): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'dias'   => ['nullable', 'integer', 'min:1', 'max:1095'],
            'permite_radicar' => ['required', 'boolean'],
        ]);

        // Menor privilegio también para las máquinas: un token que solo consulta
        // no debería poder crear envíos. Se decide al emitirlo, no después.
        $habilidades = ['consulta:*'];

        if ($datos['permite_radicar']) {
            $habilidades[] = 'ordenes:crear';
        }

        $token = $cliente->createToken(
            $datos['nombre'],
            $habilidades,
            now()->addDays($datos['dias'] ?? 365)
        );

        $this->auditar('crear_token_integracion', 'cliente', $cliente->id, $datos['nombre']);

        return response()->json([
            'token'       => $token->plainTextToken,
            'nombre'      => $datos['nombre'],
            'habilidades' => $habilidades,
            'expira'      => $token->accessToken->expires_at?->toIso8601String(),
            'url_base'    => url('/api/v1'),
            'aviso' => 'Este token no se vuelve a mostrar. Entrégueselo al cliente por un canal '
                .'seguro y guárdelo en su gestor de secretos, nunca en el código.',
        ], 201);
    }

    public function revocarToken(Cliente $cliente, int $token): JsonResponse
    {
        $borrados = $cliente->tokens()->where('id', $token)->delete();

        abort_if($borrados === 0, 404, 'Ese token no pertenece a este cliente.');

        $this->auditar('revocar_token_integracion', 'cliente', $cliente->id, "token #{$token}");

        return response()->json([
            'revocado' => true,
            'aviso'    => 'La integración del cliente dejará de funcionar de inmediato.',
        ]);
    }

    // =========================================================== sedes =======

    public function crearSede(Request $request): JsonResponse
    {
        $datos = $this->validarSede($request);

        $sede = Sede::create($datos + ['activo' => true]);

        $this->auditar('crear_sede', 'sede', $sede->id, $sede->nombre);

        return response()->json($this->sede($sede->fresh(['cliente', 'zona'])), 201);
    }

    public function actualizarSede(Request $request, Sede $sede): JsonResponse
    {
        $sede->update($this->validarSede($request, $sede));

        $this->auditar('editar_sede', 'sede', $sede->id, $sede->nombre);

        return response()->json($this->sede($sede->fresh(['cliente', 'zona'])));
    }

    public function alternarSede(Sede $sede): JsonResponse
    {
        $sede->update(['activo' => ! $sede->activo]);

        return response()->json(['activo' => $sede->activo]);
    }

    /** @return array<string, mixed> */
    private function validarSede(Request $request, ?Sede $sede = null): array
    {
        $datos = $request->validate([
            'cliente_id'   => ['nullable', 'integer', 'exists:clientes,id'],
            'zona_id'      => ['nullable', 'integer', 'exists:zonas,id'],
            'nombre'       => ['required', 'string', 'max:180'],

            // Código del sistema del cliente, no del nuestro. Texto y no
            // número: hoy es `002`, con ceros que un entero se comería.
            'codigo_centro_costo' => ['nullable', 'string', 'max:30'],
            'tipo'         => ['required', Rule::in(['hospital', 'clinica', 'farmacia', 'bodega', 'consultorio', 'otro'])],
            'direccion'    => ['required', 'string', 'max:200'],
            'detalle_acceso' => ['nullable', 'string', 'max:200'],
            'ciudad'       => ['required', 'string', 'max:80'],
            /*
             * La coordenada es OBLIGATORIA. Era opcional, y eso dejaba entrar
             * sedes que después no se podían usar para nada que dependiera del
             * mapa: el motor de asignación no calcula cercanía sin ella, la
             * entrega no se puede contrastar contra el destino, y el envío deja
             * de ser trazable justo en el punto que importa.
             *
             * Una sede sin punto en el mapa es una dirección escrita, no un
             * destino: mejor no dejarla entrar que descubrirlo en ruta.
             */
            'latitud'      => ['required', 'numeric', 'between:-90,90'],
            'longitud'     => ['required', 'numeric', 'between:-180,180'],
            'coordenada_verificada' => ['nullable', 'boolean'],
            'contacto_nombre'   => ['nullable', 'string', 'max:120'],
            'contacto_telefono' => ['nullable', 'string', 'max:30'],
            'ventanas_recepcion'          => ['nullable', 'array', 'max:7'],
            'ventanas_recepcion.*.dia'    => ['required_with:ventanas_recepcion', 'integer', 'between:1,7'],
            'ventanas_recepcion.*.desde'  => ['required_with:ventanas_recepcion', 'date_format:H:i'],
            'ventanas_recepcion.*.hasta'  => ['required_with:ventanas_recepcion', 'date_format:H:i'],
        ]);

        // Marcar como verificada una sede sin coordenada sería una afirmación
        // falsa que después nadie revisa.
        if (empty($datos['latitud']) || empty($datos['longitud'])) {
            $datos['coordenada_verificada'] = false;
        }

        return $datos;
    }

    // =========================================================== zonas =======

    public function crearZona(Request $request): JsonResponse
    {
        $zona = Zona::create($this->validarZona($request) + ['activo' => true]);

        $this->auditar('crear_zona', 'zona', $zona->id, $zona->nombre);

        return response()->json($this->zona($zona), 201);
    }

    public function actualizarZona(Request $request, Zona $zona): JsonResponse
    {
        $antes = ['base' => (float) $zona->tarifa_base, 'frio' => (float) $zona->tarifa_refrigerado];

        $zona->update($this->validarZona($request, $zona));

        // El cambio de tarifa se audita con los valores: es un dato con efecto
        // económico directo sobre lo que se le cobra al cliente.
        $this->auditar('editar_zona', 'zona', $zona->id, sprintf(
            '%s · tarifa %s→%s, frío %s→%s',
            $zona->nombre, $antes['base'], $zona->tarifa_base, $antes['frio'], $zona->tarifa_refrigerado
        ));

        return response()->json($this->zona($zona->fresh()));
    }

    /** @return array<string, mixed> */
    private function validarZona(Request $request, ?Zona $zona = null): array
    {
        return $request->validate([
            'nombre'    => ['required', 'string', 'max:80'],
            'ciudad'    => ['required', 'string', 'max:80'],
            'tarifa_base'        => ['required', 'numeric', 'min:0', 'max:99999999'],
            'tarifa_refrigerado' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);
    }

    // ======================================================= vehículos =======

    public function crearVehiculo(Request $request): JsonResponse
    {
        $datos = $this->validarVehiculo($request);

        $vehiculo = Vehiculo::create($datos + ['activo' => true]);

        $this->auditar('crear_vehiculo', 'vehiculo', $vehiculo->id, $vehiculo->placa);

        return response()->json($this->vehiculo($vehiculo), 201);
    }

    public function actualizarVehiculo(Request $request, Vehiculo $vehiculo): JsonResponse
    {
        $vehiculo->update($this->validarVehiculo($request, $vehiculo));

        $this->auditar('editar_vehiculo', 'vehiculo', $vehiculo->id, $vehiculo->placa);

        return response()->json($this->vehiculo($vehiculo->fresh()));
    }

    public function alternarVehiculo(Vehiculo $vehiculo): JsonResponse
    {
        $vehiculo->update(['activo' => ! $vehiculo->activo]);

        return response()->json(['activo' => $vehiculo->activo]);
    }

    /** @return array<string, mixed> */
    private function validarVehiculo(Request $request, ?Vehiculo $vehiculo = null): array
    {
        $datos = $request->validate([
            'placa' => ['required', 'string', 'max:10', Rule::unique('vehiculos')->ignore($vehiculo)],
            'tipo'  => ['required', Rule::in(array_map(
                fn ($t) => $t->value, \App\Enums\TipoVehiculo::cases()
            ))],
            'capacidad_kg' => ['required', 'numeric', 'min:1', 'max:40000'],
            'capacidad_m3' => ['nullable', 'numeric', 'min:0'],
            'soat_vence'   => ['nullable', 'date'],
            'tecnomecanica_vence' => ['nullable', 'date'],
            'certificado_frio_vence' => ['nullable', 'date'],
        ]);

        // La refrigeración la determina el tipo, no una casilla aparte: dos
        // fuentes de verdad para el mismo hecho terminan contradiciéndose, y
        // aquí la contradicción sería un paquete de frío en una moto.
        $tipo = \App\Enums\TipoVehiculo::from($datos['tipo']);
        $datos['refrigeracion'] = $tipo->tieneRefrigeracion();

        return $datos;
    }

    // ====================================================== mensajeros =======

    /**
     * Alta de mensajero.
     *
     * Crea la cuenta de acceso junto con el perfil: un mensajero sin usuario no
     * puede entrar a la aplicación de campo, así que separarlo en dos pasos
     * solo produce mensajeros a medio crear que nadie termina.
     */
    public function crearMensajero(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre'      => ['required', 'string', 'max:120'],
            'email'       => ['required', 'email', 'max:180', 'unique:users,email'],
            'documento'   => ['nullable', 'string', 'max:30'],
            'telefono'    => ['nullable', 'string', 'max:30'],
            'vehiculo_id' => ['nullable', 'integer', 'exists:vehiculos,id'],
            'certificado_controlados' => ['required', 'boolean'],
            'certificado_vence'       => ['nullable', 'date', 'required_if:certificado_controlados,true'],
            'zonas_habilitadas'   => ['nullable', 'array'],
            'zonas_habilitadas.*' => ['integer', 'exists:zonas,id'],
        ]);

        $clave = $this->claveTemporal();

        $resultado = DB::transaction(function () use ($datos, $clave) {
            $usuario = User::create([
                'nombre'    => $datos['nombre'],
                'email'     => strtolower($datos['email']),
                'password'  => Hash::make($clave),
                'documento' => $datos['documento'] ?? null,
                'telefono'  => $datos['telefono'] ?? null,
                'activo'    => true,
            ]);

            // El guard va explicito. Sin el, spatie lo deduce del guard ACTIVO
            // en la peticion —aqui «sanctum»— y crea un rol paralelo con el
            // mismo nombre. Asi nacio un «mensajero» duplicado que el panel
            // contaba dos veces.
            Role::findOrCreate('mensajero', 'web');
            $usuario->assignRole('mensajero');

            $mensajero = Mensajero::create([
                'user_id'     => $usuario->id,
                'codigo'      => sprintf('MSJ-%03d', Mensajero::withTrashed()->count() + 1),
                'vehiculo_id' => $datos['vehiculo_id'] ?? null,
                'certificado_controlados' => $datos['certificado_controlados'],
                'certificado_vence'       => $datos['certificado_vence'] ?? null,
                'zonas_habilitadas'       => $datos['zonas_habilitadas'] ?? null,
                'en_turno'    => false,
                'activo'      => true,
            ]);

            return compact('usuario', 'mensajero');
        });

        $this->auditar('crear_mensajero', 'mensajero', $resultado['mensajero']->id, $datos['nombre']);

        return response()->json([
            'mensajero' => $this->mensajero($resultado['mensajero']->fresh(['user', 'vehiculo'])),
            'clave_temporal' => $clave,
            'aviso' => 'Entréguela por un canal seguro. No queda registrada: si se pierde, hay que generar otra.',
        ], 201);
    }

    public function actualizarMensajero(Request $request, Mensajero $mensajero): JsonResponse
    {
        $datos = $request->validate([
            'nombre'      => ['required', 'string', 'max:120'],
            'telefono'    => ['nullable', 'string', 'max:30'],
            'vehiculo_id' => ['nullable', 'integer', 'exists:vehiculos,id'],
            'certificado_controlados' => ['required', 'boolean'],
            'certificado_vence'       => ['nullable', 'date'],
            'zonas_habilitadas'   => ['nullable', 'array'],
            'zonas_habilitadas.*' => ['integer', 'exists:zonas,id'],
            'en_turno'    => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($mensajero, $datos) {
            $mensajero->user?->update([
                'nombre'   => $datos['nombre'],
                'telefono' => $datos['telefono'] ?? null,
            ]);

            $mensajero->update([
                'vehiculo_id' => $datos['vehiculo_id'] ?? null,
                'certificado_controlados' => $datos['certificado_controlados'],
                'certificado_vence'       => $datos['certificado_vence'] ?? null,
                'zonas_habilitadas'       => $datos['zonas_habilitadas'] ?? null,
                'en_turno'    => $datos['en_turno'] ?? $mensajero->en_turno,
            ]);
        });

        $this->auditar('editar_mensajero', 'mensajero', $mensajero->id, $mensajero->nombre());

        return response()->json($this->mensajero($mensajero->fresh(['user', 'vehiculo'])));
    }

    // ===================================================== dispositivos ======

    public function crearDispositivo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'serial'      => ['required', 'string', 'max:64', 'unique:dispositivos,serial'],
            'proveedor'   => ['required', 'string', 'max:30'],
            'modelo'      => ['nullable', 'string', 'max:60'],
            'alias'       => ['nullable', 'string', 'max:80'],
            'vehiculo_id' => ['nullable', 'integer', 'exists:vehiculos,id'],
            'rango_min'   => ['required', 'numeric', 'between:-80,40'],
            'rango_max'   => ['required', 'numeric', 'between:-80,40', 'gt:rango_min'],
            'intervalo_seg' => ['required', 'integer', 'min:10', 'max:3600'],
        ]);

        $dispositivo = Dispositivo::create($datos + [
            'mide_temperatura' => true,
            'mide_ubicacion'   => true,
            'activo'           => true,
        ]);

        // El secreto se muestra UNA vez. Guardarlo cifrado y no volver a
        // revelarlo es lo que hace que sirva: un secreto consultable a
        // voluntad es un secreto de todos.
        $secreto = $dispositivo->generarSecreto();

        if ($dispositivo->vehiculo_id) {
            InstalacionDispositivo::create([
                'dispositivo_id' => $dispositivo->id,
                'vehiculo_id'    => $dispositivo->vehiculo_id,
                'instalada_por'  => request()->user()->id,
                'desde'          => now(),
            ]);
        }

        $this->auditar('crear_dispositivo', 'dispositivo', $dispositivo->id, $dispositivo->serial);

        return response()->json([
            'dispositivo' => $this->dispositivo($dispositivo->fresh('vehiculo')),
            'secreto_ingesta' => $secreto,
            'url_ingesta' => url("/api/v1/telemetria/{$dispositivo->proveedor}"),
            'aviso' => 'Este secreto no se vuelve a mostrar. Configúrelo en la plataforma del proveedor '
                .'como firma HMAC-SHA256 del cuerpo, en la cabecera X-Firma.',
        ], 201);
    }

    /**
     * Reasignación a otro vehículo, cerrando la instalación anterior.
     *
     * El histórico importa: sin él no se puede responder «¿qué sonda vigilaba
     * ese vehículo el 8 de julio?», que es lo que pregunta una auditoría de
     * cadena de frío meses después.
     */
    public function instalarDispositivo(Request $request, Dispositivo $dispositivo): JsonResponse
    {
        $datos = $request->validate([
            'vehiculo_id' => ['nullable', 'integer', 'exists:vehiculos,id'],
            'motivo'      => ['nullable', 'string', 'max:200'],
        ]);

        DB::transaction(function () use ($dispositivo, $datos, $request) {
            InstalacionDispositivo::where('dispositivo_id', $dispositivo->id)
                ->whereNull('hasta')
                ->update(['hasta' => now(), 'motivo_retiro' => $datos['motivo'] ?? 'reasignación']);

            $dispositivo->update(['vehiculo_id' => $datos['vehiculo_id'] ?? null]);

            if ($datos['vehiculo_id'] ?? null) {
                InstalacionDispositivo::create([
                    'dispositivo_id' => $dispositivo->id,
                    'vehiculo_id'    => $datos['vehiculo_id'],
                    'instalada_por'  => $request->user()->id,
                    'desde'          => now(),
                ]);
            }
        });

        $this->auditar('instalar_dispositivo', 'dispositivo', $dispositivo->id, $dispositivo->serial);

        return response()->json($this->dispositivo($dispositivo->fresh('vehiculo')));
    }

    public function rotarSecreto(Dispositivo $dispositivo): JsonResponse
    {
        $secreto = $dispositivo->generarSecreto();

        $this->auditar('rotar_secreto_dispositivo', 'dispositivo', $dispositivo->id, $dispositivo->serial);

        return response()->json([
            'secreto_ingesta' => $secreto,
            'aviso' => 'El secreto anterior dejó de servir: la ingesta fallará hasta que actualice '
                .'la configuración en la plataforma del proveedor.',
        ]);
    }

    // ==================================================== presentación =======

    /** @return array<string, mixed> */
    private function cliente(Cliente $c): array
    {
        return [
            'id' => $c->id, 'codigo' => $c->codigo, 'razon_social' => $c->razon_social,
            'nit' => $c->nit, 'email' => $c->email_notificaciones, 'telefono' => $c->telefono,
            'etiqueta_referencia' => $c->etiqueta_referencia,
            'tipo_referencia_defecto' => $c->tipo_referencia_defecto,
            'referencia_obligatoria' => $c->referencia_obligatoria,
            'activo' => $c->activo,
        ];
    }

    /** @return array<string, mixed> */
    private function sede(Sede $s): array
    {
        return [
            'id' => $s->id, 'nombre' => $s->nombre, 'tipo' => $s->tipo,
            'codigo_centro_costo' => $s->codigo_centro_costo,
            'cliente_id' => $s->cliente_id, 'cliente' => $s->cliente?->razon_social ?? 'Propia de Avalia',
            'zona_id' => $s->zona_id, 'zona' => $s->zona?->nombre,
            'direccion' => $s->direccionCompleta(), 'ciudad' => $s->ciudad,
            'latitud' => $s->latitud, 'longitud' => $s->longitud,
            'coordenada_verificada' => $s->coordenada_verificada,
            'tiene_coordenada' => $s->tieneCoordenada(),
            'ventanas' => count($s->ventanas_recepcion ?? []),
            'activo' => $s->activo,
        ];
    }

    /** @return array<string, mixed> */
    private function zona(Zona $z): array
    {
        return [
            'id' => $z->id, 'nombre' => $z->nombre, 'ciudad' => $z->ciudad,
            'tarifa_base' => (float) $z->tarifa_base,
            'tarifa_refrigerado' => (float) $z->tarifa_refrigerado,
            'activo' => $z->activo,
        ];
    }

    /** @return array<string, mixed> */
    private function vehiculo(Vehiculo $v): array
    {
        return [
            'id' => $v->id, 'placa' => $v->placa, 'tipo' => $v->tipo->value,
            'tipo_etiqueta' => $v->tipo->etiqueta(), 'refrigeracion' => $v->refrigeracion,
            'capacidad_kg' => (float) $v->capacidad_kg,
            'soat' => $v->soat_vence?->toDateString(),
            'tecnomecanica' => $v->tecnomecanica_vence?->toDateString(),
            'certificado_frio' => $v->certificado_frio_vence?->toDateString(),
            'al_dia' => $v->documentosAlDia(), 'vencidos' => $v->documentosVencidos(),
            'activo' => $v->activo,
        ];
    }

    /** @return array<string, mixed> */
    private function mensajero(Mensajero $m): array
    {
        return [
            'id' => $m->id, 'codigo' => $m->codigo, 'nombre' => $m->nombre(),
            'email' => $m->user?->email, 'vehiculo_id' => $m->vehiculo_id,
            'vehiculo' => $m->vehiculo?->placa,
            'certificado' => $m->certificado_controlados,
            'certificado_vence' => $m->certificado_vence?->toDateString(),
            'certificacion_vigente' => $m->certificacionVigente(),
            'zonas_habilitadas' => $m->zonas_habilitadas ?? [],
            'en_turno' => $m->en_turno, 'activo' => $m->activo,
        ];
    }

    /** @return array<string, mixed> */
    private function dispositivo(Dispositivo $d): array
    {
        return [
            'id' => $d->id, 'serial' => $d->serial, 'proveedor' => $d->proveedor,
            'modelo' => $d->modelo, 'alias' => $d->alias,
            'vehiculo_id' => $d->vehiculo_id, 'vehiculo' => $d->vehiculo?->placa,
            'rango' => $d->rangoVigilado(), 'intervalo_seg' => $d->intervalo_seg,
            'activo' => $d->activo,
        ];
    }

    private function claveTemporal(): string
    {
        // Sin caracteres que se confundan al dictarla por teléfono, que es como
        // termina llegando a un mensajero en la calle. La entropía la da la
        // longitud, no la ambigüedad.
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $clave    = '';

        for ($i = 0; $i < 16; $i++) {
            $clave .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $clave.'#7';
    }

    private function auditar(string $accion, string $tipo, int $id, string $detalle): void
    {
        AuditoriaAcceso::registrar(
            accion: $accion,
            recursoTipo: $tipo,
            recursoId: $id,
            justificacion: $detalle,
        );
    }
}
