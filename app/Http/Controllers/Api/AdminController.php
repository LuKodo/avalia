<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditoriaAcceso;
use App\Models\Cliente;
use App\Models\EventoPaquete;
use App\Models\Exportacion;
use App\Models\Mensajero;
use App\Models\Novedad;
use App\Models\Paquete;
use App\Models\Parametro;
use App\Models\SelloDiario;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Trazabilidad\VerificadorCadena;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

/**
 * Administración del sistema.
 *
 * Lo que el operador NO ve y el administrador sí: el estado de los controles
 * que sostienen la trazabilidad, quién tiene acceso a qué, y los parámetros que
 * gobiernan el motor de asignación.
 *
 * La distinción no es de jerarquía sino de oficio. El operador despacha; el
 * administrador responde por que el sistema siga siendo auditable. Son dos
 * trabajos distintos y por eso son dos pantallas distintas.
 */
class AdminController extends Controller
{
    public function __construct(private VerificadorCadena $verificador) {}

    /**
     * Panel de administración: la salud de los controles, no la operación.
     */
    public function resumen(): JsonResponse
    {
        return response()->json([
            'integridad'  => $this->integridad(),
            'accesos'     => $this->accesos(),
            'personas'    => $this->personas(),
            'flota'       => $this->saludFlota(),
            'operacion'   => $this->operacion(),
            'almacen'     => $this->almacen(),
            'pendientes'  => $this->controlesPendientes(),
        ]);
    }

    /**
     * Estado de la cadena de auditoría. Es el indicador más importante de esta
     * pantalla: si la cadena está rota, nada de lo que diga el sistema sirve
     * como prueba.
     */
    private function integridad(): array
    {
        $total = EventoPaquete::count();

        // Se verifica el tramo reciente en la carga de pantalla; la verificación
        // completa corre de noche. Recorrer 3 millones de eventos cada vez que
        // alguien abre el panel no es viable ni necesario.
        $desde = max(1, $total - 500);
        $r     = $this->verificador->verificar($desde);

        $ultimoSello = SelloDiario::orderByDesc('fecha')->first();

        return [
            'eventos_totales'   => $total,
            'tramo_verificado'  => $r['revisados'],
            'integra'           => $r['ok'],
            'primer_fallo'      => $r['primer_fallo'],
            'algoritmo'         => config('ltp.trazabilidad.algoritmo_hash'),
            'sellos' => [
                'total'          => SelloDiario::count(),
                'firmados'       => SelloDiario::whereNotNull('firma')->count(),
                'exportados'     => SelloDiario::where('exportado', true)->count(),
                'ultimo'         => $ultimoSello?->fecha->toDateString(),
                'ultimo_firmado' => (bool) $ultimoSello?->firma,
            ],
        ];
    }

    /** Quién ha mirado datos personales sensibles y con qué frecuencia. */
    private function accesos(): array
    {
        $desde = now()->subDays(30);

        return [
            'ultimos_30_dias' => AuditoriaAcceso::where('ocurrido_en', '>=', $desde)->count(),
            'por_accion' => AuditoriaAcceso::where('ocurrido_en', '>=', $desde)
                ->selectRaw('accion, COUNT(*) AS total')
                ->groupBy('accion')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($a) => ['accion' => $a->accion, 'total' => (int) $a->total])
                ->all(),
            'recientes' => AuditoriaAcceso::with('usuario')
                ->orderByDesc('ocurrido_en')
                ->limit(12)
                ->get()
                ->map(fn ($a) => [
                    'accion'   => $a->accion,
                    'recurso'  => $a->recurso_tipo,
                    'usuario'  => $a->usuario?->nombre ?? 'sistema',
                    'ip'       => $a->ip,
                    'cuando'   => $a->ocurrido_en->toIso8601String(),
                ])->all(),
            'exportaciones_30d' => Exportacion::where('created_at', '>=', $desde)->count(),
            'exportaciones_con_datos_receptor' => Exportacion::where('created_at', '>=', $desde)
                ->where('incluye_datos_receptor', true)->count(),
        ];
    }

    private function personas(): array
    {
        // Se cuenta contra la tabla pivote y no con `Role::withCount('users')`.
        // Esa relación de spatie resuelve el modelo a partir del guard, y bajo
        // `auth:sanctum` el guard queda registrado sin `provider`: la relación
        // termina apuntando a una clase nula. Contar el pivote es además una
        // consulta menos y no depende de esa resolución.
        $conteos = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_type', User::class)
            ->selectRaw('roles.name AS rol, COUNT(*) AS total')
            ->groupBy('roles.name')
            ->pluck('total', 'rol');

        // Los roles sin nadie asignado también se listan: un rol vacío es
        // información, no una fila que sobra.
        $porRol = Role::orderBy('name')->pluck('name')
            ->map(fn (string $rol) => ['rol' => $rol, 'total' => (int) ($conteos[$rol] ?? 0)])
            ->all();

        return [
            'por_rol'          => $porRol,
            'activos'          => User::where('activo', true)->count(),
            'inactivos'        => User::where('activo', false)->count(),
            'sin_doble_factor' => User::where('activo', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['administrador', 'operador']))
                ->where('doble_factor', false)
                ->count(),
            'clientes'         => Cliente::where('activo', true)->count(),
            'tokens_activos'   => PersonalAccessToken::where(fn ($q) => $q
                ->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'sin_acceso_90d'   => User::where('activo', true)
                ->where(fn ($q) => $q->whereNull('ultimo_acceso_en')
                    ->orWhere('ultimo_acceso_en', '<', now()->subDays(90)))
                ->count(),
        ];
    }

    /**
     * Salud de la flota desde el ángulo del administrador: no dónde está cada
     * mensajero, sino qué le impide operar.
     */
    private function saludFlota(): array
    {
        $vehiculos = Vehiculo::where('activo', true)->get();
        $limite    = now()->addDays(30);

        return [
            'vehiculos'          => $vehiculos->count(),
            'documentos_vencidos' => $vehiculos->filter(fn ($v) => ! $v->documentosAlDia())->count(),
            'por_vencer_30d'     => $vehiculos->filter(function (Vehiculo $v) use ($limite) {
                if (! $v->documentosAlDia()) {
                    return false;
                }

                return collect([$v->soat_vence, $v->tecnomecanica_vence, $v->certificado_frio_vence])
                    ->filter()
                    ->contains(fn ($f) => $f->lte($limite));
            })->count(),
            'refrigerados'       => $vehiculos->where('refrigeracion', true)->count(),
            'detalle_vencidos'   => $vehiculos->filter(fn ($v) => ! $v->documentosAlDia())
                ->map(fn ($v) => [
                    'placa'     => $v->placa,
                    'tipo'      => $v->tipo->etiqueta(),
                    'vencidos'  => $v->documentosVencidos(),
                ])->values()->all(),
            'mensajeros'         => Mensajero::where('activo', true)->count(),
            'certificados'       => Mensajero::where('activo', true)->where('certificado_controlados', true)->count(),
            'certificacion_por_vencer' => Mensajero::where('activo', true)
                ->whereNotNull('certificado_vence')
                ->whereBetween('certificado_vence', [now(), $limite])
                ->count(),
        ];
    }

    private function operacion(): array
    {
        return [
            'paquetes_totales' => Paquete::count(),
            'en_curso'         => Paquete::enCurso()->count(),
            'sin_asignar'      => Paquete::sinAsignar()->count(),
            'novedades_abiertas' => Novedad::abiertas()->count(),
            'novedades_criticas' => Novedad::abiertas()->criticas()->count(),
        ];
    }

    /** Crecimiento de las tablas que más crecen: lo que va a doler primero. */
    private function almacen(): array
    {
        return [
            ['tabla' => 'eventos_paquete', 'filas' => EventoPaquete::count(), 'nota' => 'Se particiona por mes en producción'],
            ['tabla' => 'posiciones',      'filas' => DB::table('posiciones')->count(), 'nota' => 'Se purga a los 90 días'],
            ['tabla' => 'paquetes',        'filas' => Paquete::count(), 'nota' => 'Crece con la operación'],
            ['tabla' => 'auditoria_accesos', 'filas' => AuditoriaAcceso::count(), 'nota' => 'Solo anexado'],
            ['tabla' => 'evidencias',      'filas' => DB::table('evidencias')->count(), 'nota' => 'Los archivos pesan, no las filas'],
        ];
    }

    /**
     * Controles que están a medias. Se calculan, no se escriben a mano: un
     * listado estático de pendientes deja de ser cierto en una semana.
     *
     * @return array<int, array{control:string, estado:string, detalle:string}>
     */
    private function controlesPendientes(): array
    {
        $pendientes = [];

        if (! config('ltp.trazabilidad.sello_clave')) {
            $pendientes[] = [
                'control'  => 'Firma del sello diario',
                'estado'   => 'critical',
                'detalle'  => 'LTP_SELLO_CLAVE no está configurada: los sellos se generan sin firmar.',
            ];
        }

        $sinExportar = SelloDiario::where('exportado', false)->count();

        if ($sinExportar > 0) {
            $pendientes[] = [
                'control'  => 'Exportación del sello fuera de sede',
                'estado'   => 'warning',
                'detalle'  => "{$sinExportar} sello(s) sin exportar. Mientras el sello viva en el mismo servidor que la cadena, no protege de un administrador de base de datos.",
            ];
        }

        $sin2fa = User::where('activo', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['administrador', 'operador']))
            ->where('doble_factor', false)->count();

        if ($sin2fa > 0) {
            $pendientes[] = [
                'control'  => 'Doble factor en roles administrativos',
                'estado'   => 'warning',
                'detalle'  => "{$sin2fa} cuenta(s) administrativa(s) sin doble factor activo.",
            ];
        }

        if (config('app.debug')) {
            $pendientes[] = [
                'control'  => 'Modo depuración',
                'estado'   => 'critical',
                'detalle'  => 'APP_DEBUG está activo: las trazas de error exponen detalle interno. Debe apagarse antes de exponer el sistema.',
            ];
        }

        if (config('database.default') === 'sqlite') {
            $pendientes[] = [
                'control'  => 'Motor de base de datos',
                'estado'   => 'critical',
                'detalle'  => 'Sobre SQLite no se aplican los disparadores de solo-anexado de la bitácora.',
            ];
        }

        return $pendientes;
    }

    // ====================================================== usuarios =========

    public function usuarios(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'rol'    => ['nullable', 'string', 'max:30'],
        ]);

        $usuarios = User::query()
            ->with(['roles', 'cliente', 'mensajero.vehiculo'])
            ->when($datos['buscar'] ?? null, fn ($q, $b) => $q->where(fn ($s) => $s
                ->where('nombre', 'like', "%{$b}%")->orWhere('email', 'like', "%{$b}%")))
            ->when($datos['rol'] ?? null, fn ($q, $r) => $q->whereHas('roles', fn ($s) => $s->where('name', $r)))
            ->orderBy('nombre')
            ->get()
            ->map(fn (User $u) => [
                'id'          => $u->id,
                'nombre'      => $u->nombre,
                'email'       => $u->email,
                'rol'         => $u->getRoleNames()->first(),
                'activo'      => $u->activo,
                'doble_factor' => $u->doble_factor,
                'cliente'     => $u->cliente?->razon_social,
                'mensajero'   => $u->mensajero?->codigo,
                'vehiculo'    => $u->mensajero?->vehiculo?->placa,
                'ultimo_acceso' => $u->ultimo_acceso_en?->toIso8601String(),
                'ultima_ip'   => $u->ultima_ip,
                'tokens'      => $u->tokens()->count(),
            ]);

        return response()->json([
            'total'    => $usuarios->count(),
            'roles'    => Role::pluck('name'),
            'usuarios' => $usuarios,
        ]);
    }

    /**
     * Activa o desactiva una cuenta.
     *
     * Desactivar revoca además todos sus tokens: dejar una cuenta «inactiva»
     * con sesiones abiertas es no haberla desactivado.
     */
    public function alternarUsuario(Request $request, User $usuario): JsonResponse
    {
        abort_if(
            $usuario->id === $request->user()->id,
            422,
            'No puede desactivar su propia cuenta: quedaría sin quién vuelva a activarla.'
        );

        $usuario->activo = ! $usuario->activo;
        $usuario->save();

        $revocados = 0;

        if (! $usuario->activo) {
            $revocados = $usuario->tokens()->delete();
        }

        AuditoriaAcceso::registrar(
            accion: $usuario->activo ? 'activar_usuario' : 'desactivar_usuario',
            recursoTipo: 'usuario',
            recursoId: $usuario->id,
        );

        return response()->json([
            'activo'           => $usuario->activo,
            'tokens_revocados' => $revocados,
        ]);
    }

    /** Restablece la contraseña y devuelve una temporal, una sola vez. */
    public function restablecerClave(Request $request, User $usuario): JsonResponse
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $clave    = '';

        for ($i = 0; $i < 16; $i++) {
            $clave .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        $clave .= '#7';

        $usuario->forceFill(['password' => Hash::make($clave)])->save();

        // Las sesiones anteriores dejan de servir: una clave nueva que no cierra
        // lo abierto no cambia nada para quien ya tenía acceso.
        $revocados = $usuario->tokens()->delete();

        AuditoriaAcceso::registrar(
            accion: 'restablecer_clave',
            recursoTipo: 'usuario',
            recursoId: $usuario->id,
            justificacion: $request->input('justificacion'),
        );

        return response()->json([
            'clave_temporal'   => $clave,
            'tokens_revocados' => $revocados,
            'aviso' => 'Entréguela por un canal seguro. No queda registrada: si se pierde, hay que generar otra.',
        ]);
    }

    // ==================================================== parámetros =========

    public function parametros(): JsonResponse
    {
        $parametros = Parametro::orderBy('clave')->get()
            ->map(fn (Parametro $p) => [
                'clave'       => $p->clave,
                'valor'       => $p->convertido(),
                'tipo'        => $p->tipo,
                'descripcion' => $p->descripcion,
                'grupo'       => explode('.', $p->clave)[0],
            ])
            ->groupBy('grupo');

        return response()->json([
            'grupos' => $parametros,
            'aviso'  => 'Los pesos del motor de asignación deben sumar 100. '
                .'El sistema no lo impone: normalizar en silencio escondería un error de configuración.',
        ]);
    }

    public function guardarParametros(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'parametros'         => ['required', 'array', 'max:60'],
            'parametros.*.clave' => ['required', 'string', 'max:80', 'exists:parametros,clave'],
            'parametros.*.valor' => ['required'],
        ]);

        $cambios = [];

        foreach ($datos['parametros'] as $p) {
            $parametro = Parametro::find($p['clave']);
            $anterior  = $parametro->convertido();
            $nuevo     = is_array($p['valor']) ? json_encode($p['valor']) : (string) $p['valor'];

            if ((string) $parametro->valor === $nuevo) {
                continue;
            }

            $parametro->update([
                'valor'           => $nuevo,
                'actualizado_por' => $request->user()->id,
            ]);

            $cambios[] = ['clave' => $p['clave'], 'antes' => $anterior, 'ahora' => $parametro->fresh()->convertido()];
        }

        if ($cambios !== []) {
            AuditoriaAcceso::registrar(
                accion: 'cambiar_parametros',
                recursoTipo: 'parametros',
                justificacion: count($cambios).' parámetro(s) modificado(s)',
            );
        }

        return response()->json(['cambios' => $cambios, 'total' => count($cambios)]);
    }

    // ===================================================== auditoría =========

    public function auditoria(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        return response()->json([
            'sellos' => SelloDiario::orderByDesc('fecha')->limit(30)->get()
                ->map(fn (SelloDiario $s) => [
                    'fecha'       => $s->fecha->toDateString(),
                    'eventos'     => $s->total_eventos,
                    'desde'       => $s->secuencia_desde,
                    'hasta'       => $s->secuencia_hasta,
                    'hash'        => $s->hash_raiz,
                    'firmado'     => (bool) $s->firma,
                    'exportado'   => $s->exportado,
                    'protege'     => $s->protegeDeVerdad(),
                ]),

            'accesos' => AuditoriaAcceso::with('usuario')
                ->when($datos['desde'] ?? null, fn ($q, $d) => $q->whereDate('ocurrido_en', '>=', $d))
                ->when($datos['hasta'] ?? null, fn ($q, $h) => $q->whereDate('ocurrido_en', '<=', $h))
                ->orderByDesc('ocurrido_en')
                ->limit(100)
                ->get()
                ->map(fn (AuditoriaAcceso $a) => [
                    'cuando'        => $a->ocurrido_en->toIso8601String(),
                    'usuario'       => $a->usuario?->nombre ?? 'sistema',
                    'email'         => $a->usuario?->email,
                    'accion'        => $a->accion,
                    'recurso'       => $a->recurso_tipo,
                    'recurso_id'    => $a->recurso_id,
                    'justificacion' => $a->justificacion,
                    'ip'            => $a->ip,
                ]),

            'exportaciones' => Exportacion::with('usuario', 'cliente')
                ->orderByDesc('created_at')->limit(30)->get()
                ->map(fn (Exportacion $e) => [
                    'cuando'    => $e->created_at->toIso8601String(),
                    'usuario'   => $e->usuario?->nombre ?? 'integración',
                    'cliente'   => $e->cliente?->razon_social,
                    'formato'   => $e->formato,
                    'registros' => $e->registros,
                    'con_datos_receptor' => $e->incluye_datos_receptor,
                    'ip'        => $e->ip,
                ]),
        ]);
    }

    /** Verificación completa de la cadena, a demanda. Puede tardar. */
    public function verificarCadena(): JsonResponse
    {
        $inicio = microtime(true);
        $r      = $this->verificador->verificar();

        AuditoriaAcceso::registrar(
            accion: 'verificar_cadena',
            recursoTipo: 'eventos_paquete',
            justificacion: 'verificación completa a demanda',
        );

        return response()->json([
            'integra'      => $r['ok'],
            'revisados'    => $r['revisados'],
            'primer_fallo' => $r['primer_fallo'],
            'duracion_ms'  => (int) round((microtime(true) - $inicio) * 1000),
        ]);
    }

    // ======================================================= clientes ========

    public function clientes(): JsonResponse
    {
        return response()->json([
            'clientes' => Cliente::withCount(['sedes', 'ordenes', 'usuarios'])
                ->orderBy('razon_social')->get()
                ->map(fn (Cliente $c) => [
                    'id'           => $c->id,
                    'codigo'       => $c->codigo,
                    'razon_social' => $c->razon_social,
                    'nit'          => $c->nit,
                    'email'        => $c->email_notificaciones,
                    'telefono'     => $c->telefono,
                    'etiqueta_referencia' => $c->etiqueta_referencia,
                    'referencia_obligatoria' => $c->referencia_obligatoria,
                    'activo'       => $c->activo,

                    /*
                     * El token NO viaja, ni siquiera recortado. Lo que la
                     * pantalla necesita saber es si HAY uno configurado, para
                     * poder decir «configurado» y ofrecer reemplazarlo. Mandar
                     * un trozo del secreto para «dar pistas» es regalar
                     * entropía a cambio de nada.
                     */
                    'integracion'  => [
                        'activa'      => (bool) $c->integracion_activa,
                        'tipo'        => $c->integracion_tipo,
                        'api_url'     => $c->integracion_config['api_url'] ?? null,
                        'bucket'      => $c->integracion_config['bucket'] ?? null,
                        'prefijo'     => $c->integracion_config['prefijo_referencia'] ?? null,
                        'tiene_token' => filled($c->integracion_token),
                    ],

                    'sedes'        => $c->sedes_count,
                    'ordenes'      => $c->ordenes_count,
                    'usuarios'     => $c->usuarios_count,
                ]),

            'sedes' => \App\Models\Sede::with('cliente', 'zona')->orderBy('nombre')->get()
                ->map(fn ($s) => [
                    'id'        => $s->id,
                    'nombre'    => $s->nombre,
                    'codigo_centro_costo' => $s->codigo_centro_costo,
                    'cliente'   => $s->cliente?->razon_social ?? 'Propia de Avalia',
                    'tipo'      => $s->tipo,
                    'direccion' => $s->direccionCompleta(),
                    'ciudad'    => $s->ciudad,
                    'zona'      => $s->zona?->nombre,
                    'coordenada_verificada' => $s->coordenada_verificada,
                    'tiene_coordenada' => $s->tieneCoordenada(),
                    'ventanas'  => count($s->ventanas_recepcion ?? []),
                    'activo'    => $s->activo,

                    /*
                     * Los valores crudos, para rellenar el formulario de
                     * edición. Van aparte de los de arriba porque aquellos
                     * están compuestos para leerse —`direccion` es la dirección
                     * completa con ciudad, `cliente` es la razón social— y
                     * devolverlos al formulario guardaría basura.
                     */
                    'datos' => [
                        'cliente_id' => $s->cliente_id,
                        'zona_id'    => $s->zona_id,
                        'nombre'     => $s->nombre,
                        'codigo_centro_costo' => $s->codigo_centro_costo,
                        'tipo'       => $s->tipo,
                        'direccion'  => $s->direccion,
                        'detalle_acceso' => $s->detalle_acceso,
                        'ciudad'     => $s->ciudad,
                        'latitud'    => $s->latitud,
                        'longitud'   => $s->longitud,
                        'coordenada_verificada' => (bool) $s->coordenada_verificada,
                        'contacto_nombre'   => $s->contacto_nombre,
                        'contacto_telefono' => $s->contacto_telefono,
                    ],
                ]),

            'zonas' => \App\Models\Zona::withCount('sedes')->orderBy('nombre')->get()
                ->map(fn ($z) => [
                    'id'       => $z->id,
                    'nombre'   => $z->nombre,
                    'ciudad'   => $z->ciudad,
                    'tarifa_base' => (float) $z->tarifa_base,
                    'tarifa_refrigerado' => (float) $z->tarifa_refrigerado,
                    'sedes'    => $z->sedes_count,
                    'activo'   => $z->activo,
                ]),
        ]);
    }

    // ========================================================== flota ========

    public function flota(): JsonResponse
    {
        return response()->json([
            'vehiculos' => Vehiculo::withCount('mensajeros')->orderBy('placa')->get()
                ->map(fn (Vehiculo $v) => [
                    'id'            => $v->id,
                    'placa'         => $v->placa,
                    'tipo'          => $v->tipo->value,
                    'tipo_etiqueta' => $v->tipo->etiqueta(),
                    'refrigeracion' => $v->refrigeracion,
                    'capacidad_kg'  => $v->capacidad_kg,
                    'soat'          => $v->soat_vence?->toDateString(),
                    'tecnomecanica' => $v->tecnomecanica_vence?->toDateString(),
                    'certificado_frio' => $v->certificado_frio_vence?->toDateString(),
                    'al_dia'        => $v->documentosAlDia(),
                    'vencidos'      => $v->documentosVencidos(),
                    'mensajeros'    => $v->mensajeros_count,
                    'activo'        => $v->activo,
                ]),

            'mensajeros' => Mensajero::with('user', 'vehiculo')->orderBy('codigo')->get()
                ->map(fn (Mensajero $m) => [
                    'id'          => $m->id,
                    'codigo'      => $m->codigo,
                    'nombre'      => $m->nombre(),
                    'vehiculo'    => $m->vehiculo?->placa,
                    'tipo_vehiculo' => $m->vehiculo?->tipo->etiqueta(),
                    'refrigeracion' => (bool) $m->vehiculo?->refrigeracion,
                    'certificado' => $m->certificado_controlados,
                    'certificado_vence' => $m->certificado_vence?->toDateString(),
                    'certificacion_vigente' => $m->certificacionVigente(),
                    'en_turno'    => $m->en_turno,
                    'sin_senal'   => $m->sinSenal(10),
                    'bateria'     => $m->bateria,
                    'carga_kg'    => $m->carga_actual_kg,
                    'activos'     => $m->paquetesActivos(),
                    'cumplimiento' => $m->indice_cumplimiento,
                    'entregas'    => $m->entregas_totales,
                    'novedades'   => $m->novedades_totales,
                ]),
        ]);
    }
}
