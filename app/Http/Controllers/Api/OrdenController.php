<?php

namespace App\Http\Controllers\Api;

use App\Enums\Criticidad;
use App\Enums\EstadoPaquete;
use App\Enums\TipoPaquete;
use App\Models\Orden;
use App\Models\ReferenciaCliente;
use App\Models\Sede;
use App\Services\Despacho\MotorAsignacion;
use App\Services\Ordenes\GeneradorGuia;
use App\Services\Trazabilidad\RegistradorEventos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class OrdenController extends Controller
{
    public function __construct(
        private GeneradorGuia $guias,
        private RegistradorEventos $registrador,
        private MotorAsignacion $motor,
        private \App\Services\Operacion\CadenaDeTramos $cadena,
    ) {}

    /**
     * Radicación de un envío.
     *
     * Acepta las referencias del cliente en el mismo cuerpo: si su ERP manda el
     * radicado y la orden de compra, ambas quedan asociadas y ambas sirven
     * después para consultar. Pedirle una segunda llamada para eso sería
     * inventarle trabajo.
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            // El operador radica POR un cliente y debe decir cuál. Un usuario de
            // cliente no lo envía: se le impone el suyo, para que no pueda
            // radicar a nombre de otra institución.
            'cliente_id'       => ['nullable', 'integer', 'exists:clientes,id'],
            'sede_origen_id'   => ['required', 'integer', 'exists:sedes,id'],
            'sede_destino_id'  => ['required', 'integer', 'exists:sedes,id', 'different:sede_origen_id'],
            'criticidad'       => ['required', 'string', 'in:urgente,programado'],
            'ventana_desde'    => ['nullable', 'date'],
            'ventana_hasta'    => ['nullable', 'date', 'after:ventana_desde'],
            'contacto_destino_nombre'   => ['nullable', 'string', 'max:120'],
            'contacto_destino_telefono' => ['nullable', 'string', 'max:30'],
            'observaciones'    => ['nullable', 'string', 'max:1000'],

            'referencias'          => ['nullable', 'array', 'max:5'],
            'referencias.*.tipo'   => ['required_with:referencias', 'string', 'max:30'],
            'referencias.*.valor'  => ['required_with:referencias', 'string', 'max:120'],

            'paquetes'                => ['required', 'array', 'min:1', 'max:50'],
            'paquetes.*.tipo'         => ['required', 'string', 'in:cadena_frio,controlado,general'],
            'paquetes.*.descripcion'  => ['nullable', 'string', 'max:500'],
            'paquetes.*.lote'         => ['nullable', 'string', 'max:60'],
            'paquetes.*.peso_kg'      => ['required', 'numeric', 'min:0.01', 'max:2000'],
            'paquetes.*.largo_cm'     => ['nullable', 'integer', 'min:1', 'max:500'],
            'paquetes.*.ancho_cm'     => ['nullable', 'integer', 'min:1', 'max:500'],
            'paquetes.*.alto_cm'      => ['nullable', 'integer', 'min:1', 'max:500'],
            'paquetes.*.temp_min'     => ['nullable', 'numeric', 'min:-80', 'max:40'],
            'paquetes.*.temp_max'     => ['nullable', 'numeric', 'min:-80', 'max:40', 'gte:paquetes.*.temp_min'],

            'asignar_automatico' => ['nullable', 'boolean'],
        ]);

        $cliente = $this->clienteDeLaRadicacion($request, $datos['cliente_id'] ?? null);

        // La sede de destino tiene que ser del mismo cliente: sin esta
        // verificación, un token válido podría radicar envíos hacia sedes ajenas
        // y usar el sistema para descubrir la red de otro cliente.
        $destino = Sede::findOrFail($datos['sede_destino_id']);
        abort_if(
            $destino->cliente_id !== null && $destino->cliente_id !== $cliente->id,
            403,
            'La sede de destino no pertenece a este cliente.'
        );

        /*
         * Los dos extremos tienen que estar APROBADOS.
         *
         * Sin esto, la aprobacion de puntos no sirve de nada: el cliente
         * proponia un punto, lo dejabamos pendiente de revisar, y podia radicar
         * contra el igualmente. La coordenada sin verificar acababa siendo el
         * destino de una ruta trazada y la referencia de un aviso de desvio,
         * que es justo lo que el procedimiento existe para impedir.
         *
         * Se comprueba aqui y no en las reglas de validacion porque el mensaje
         * tiene que decir CUAL de los dos es y en que estado esta; un «campo
         * invalido» generico deja al cliente adivinando.
         */
        $origen = Sede::findOrFail($datos['sede_origen_id']);

        foreach ([['origen', $origen], ['destino', $destino]] as [$rotulo, $punto]) {
            if (($punto->estado_aprobacion ?? 'aprobada') === 'aprobada') {
                continue;
            }

            abort(422, $punto->estado_aprobacion === 'rechazada'
                ? "El punto de {$rotulo} «{$punto->nombre}» fue rechazado: {$punto->motivo_revision}"
                : "El punto de {$rotulo} «{$punto->nombre}» esta pendiente de aprobacion por Avalia.");
        }

        if ($cliente->referencia_obligatoria && empty($datos['referencias'])) {
            abort(422, "Este cliente exige {$cliente->etiqueta_referencia} en cada radicación.");
        }

        /*
         * Radicación idempotente por referencia del cliente.
         *
         * Si alguna de las referencias que llegan ya pertenece a un envío de este
         * cliente, se devuelve ESE envío en vez de crear otro.
         *
         * Sin esto, un reintento —el tiempo de espera se agotó, la red falló, el
         * usuario pulsó dos veces— hacía dos cosas, y la segunda es la grave:
         * creaba una guía duplicada, y además reapuntaba la referencia del
         * cliente al envío nuevo. Resultado: dos despachos reales para el mismo
         * pedido, y el primero imposible de encontrar por el número con el que el
         * cliente lo busca.
         *
         * Se comprueba ANTES de abrir la transacción porque no hay nada que
         * deshacer: es una lectura que decide si se escribe.
         */
        if ($existente = $this->porReferenciaExistente($cliente->id, $datos['referencias'] ?? [])) {
            return response()->json(
                $this->respuesta($existente) + [
                    'ya_existia' => true,
                    'mensaje'    => 'Ya existe un envío con esa referencia. No se creó uno nuevo.',
                ],
                200
            );
        }

        $orden = DB::transaction(function () use ($datos, $cliente, $request) {
            $criticidad = Criticidad::from($datos['criticidad']);

            $orden = Orden::create([
                'numero_guia'      => $this->guias->generar(),
                'cliente_id'       => $cliente->id,
                'sede_origen_id'   => $datos['sede_origen_id'],
                'sede_destino_id'  => $datos['sede_destino_id'],
                'creada_por'       => $request->user() instanceof \App\Models\User ? $request->user()->id : null,
                'criticidad'       => $criticidad,
                'ventana_desde'    => $datos['ventana_desde'] ?? null,
                'ventana_hasta'    => $datos['ventana_hasta'] ?? null,
                'comprometida_en'  => $datos['ventana_hasta'] ?? now()->addHours($criticidad === Criticidad::Urgente ? 3 : 24),
                'contacto_destino_nombre'   => $datos['contacto_destino_nombre'] ?? null,
                'contacto_destino_telefono' => $datos['contacto_destino_telefono'] ?? null,
                'observaciones'    => $datos['observaciones'] ?? null,
            ]);

            foreach ($datos['referencias'] ?? [] as $ref) {
                ReferenciaCliente::updateOrCreate(
                    [
                        'cliente_id'        => $cliente->id,
                        'tipo'              => $ref['tipo'],
                        'valor_normalizado' => ReferenciaCliente::normalizar($ref['valor']),
                    ],
                    ['orden_id' => $orden->id, 'valor' => $ref['valor']]
                );
            }

            foreach (array_values($datos['paquetes']) as $i => $p) {
                $tipo = TipoPaquete::from($p['tipo']);

                $paquete = $orden->paquetes()->create([
                    'codigo'      => $this->guias->codigoPaquete($orden->numero_guia, $i + 1),
                    'consecutivo' => $i + 1,
                    'tipo'        => $tipo,
                    'estado'      => EstadoPaquete::Creado,
                    'descripcion' => $p['descripcion'] ?? null,
                    'lote'        => $p['lote'] ?? null,
                    'peso_kg'     => $p['peso_kg'],
                    'largo_cm'    => $p['largo_cm'] ?? null,
                    'ancho_cm'    => $p['ancho_cm'] ?? null,
                    'alto_cm'     => $p['alto_cm'] ?? null,
                    'temp_min'    => $tipo->exigeRefrigeracion() ? ($p['temp_min'] ?? config('ltp.cadena_frio.rango_defecto.min')) : null,
                    'temp_max'    => $tipo->exigeRefrigeracion() ? ($p['temp_max'] ?? config('ltp.cadena_frio.rango_defecto.max')) : null,
                ]);

                $this->registrador->registrar($paquete, EstadoPaquete::Creado, [
                    'actor' => $request->user() instanceof \App\Models\User ? $request->user() : null,
                    'actor_tipo' => $request->user() instanceof \App\Models\Cliente ? 'integracion' : 'usuario',
                    'datos' => ['origen_radicacion' => $request->user() instanceof \App\Models\Cliente ? 'api' : 'portal'],
                ]);

                /*
                 * Cada paquete nace con su cadena de trayectos, DIRECTA.
                 *
                 * Directa porque al radicar nadie sabe todavia si conviene
                 * consolidar: eso lo decide el operador al ver la carga del
                 * dia. Si decide pasarla por un centro, mete el trasbordo
                 * despues con POST /despacho/paquetes/{p}/trasbordo.
                 *
                 * Armarla aqui y no al asignar evita que existan paquetes sin
                 * tramo: uno sin cadena no se puede asignar ni cerrar, y el
                 * fallo aparecería tarde, en el telefono del mensajero.
                 */
                $this->cadena->armar($paquete);
            }

            $orden->calcularTarifa();

            return $orden;
        });

        /*
         * Quien radica no decide quien lo lleva.
         *
         * El cliente pide un servicio; a quien se le asigna es decision de la
         * operacion, que es la que conoce turnos, cargas y zonas. Por eso la
         * bandera se ignora cuando quien radica es un usuario de cliente,
         * aunque la mande por la API: esconder la casilla de la pantalla no
         * seria una regla, solo un adorno.
         *
         * Y para operador o administrador ya no se asume que si: antes bastaba
         * con omitir el campo para que se asignara solo. Ahora hay que pedirlo
         * expresamente, y lo normal es no hacerlo y despacharlo desde la
         * bandeja.
         */
        $loPideLaOperacion = $this->clienteDe($request) === null
            && ($datos['asignar_automatico'] ?? false);

        if ($loPideLaOperacion) {
            foreach ($orden->paquetes as $paquete) {
                $this->motor->asignarAutomatico($paquete);
            }
        }

        $orden->refresh()->load('paquetes', 'referencias');

        return response()->json($this->respuesta($orden), 201);
    }

    /**
     * Busca un envío del cliente que ya lleve alguna de estas referencias.
     *
     * Basta con que UNA coincida: si el cliente manda el mismo radicado, es el
     * mismo despacho aunque el resto del formulario venga distinto.
     *
     * Se compara por el valor normalizado —sin tildes, sin guiones, en
     * mayúsculas— que es como está indexado; comparar el texto crudo dejaría
     * pasar «RAD-2608-00459» frente a «rad 2608 00459» y el duplicado volvería
     * por la puerta de atrás.
     *
     * @param  array<int, array{tipo: string, valor: string}>  $referencias
     */
    private function porReferenciaExistente(int $clienteId, array $referencias): ?Orden
    {
        $claves = collect($referencias)
            ->pluck('valor')
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => ReferenciaCliente::normalizar($v))
            ->filter()
            ->unique()
            ->all();

        if ($claves === []) {
            return null;
        }

        $ordenId = ReferenciaCliente::where('cliente_id', $clienteId)
            ->whereIn('valor_normalizado', $claves)
            ->whereNotNull('orden_id')
            ->value('orden_id');

        return $ordenId === null ? null : Orden::find($ordenId);
    }

    /** @return array<string, mixed> */
    private function respuesta(Orden $orden): array
    {
        $orden->loadMissing('referencias', 'paquetes');

        return [
            'guia_avalia'    => $orden->numero_guia,
            'estado'      => $orden->estadoConsolidado(),
            'referencias' => $orden->referencias->map(fn ($r) => ['tipo' => $r->tipo->value, 'valor' => $r->valor]),
            'paquetes'    => $orden->paquetes->map(fn ($p) => [
                'codigo' => $p->codigo,
                'tipo'   => $p->tipo->value,
                'estado' => $p->estado->value,
            ]),
            'total'       => $orden->total,
            'comprometida_en' => $orden->comprometida_en?->toIso8601String(),
        ];
    }

    /**
     * Todo lo que necesita la pantalla de radicación, en una sola llamada.
     *
     * Las sedes vienen del catálogo curado con coordenada verificada: escribir
     * direcciones a mano es la principal causa de entregas en la cuadra
     * equivocada, así que la pantalla no ofrece esa opción.
     */
    public function catalogo(Request $request): JsonResponse
    {
        $actor   = $request->user();
        $cliente = $this->clienteDe($request);

        // Un operador radica por cualquier cliente; un usuario de cliente, solo
        // por el suyo. La lista que se le ofrece ya viene filtrada: no se
        // esconde en la interfaz lo que la consulta sí devolvería.
        $clientes = $cliente !== null
            ? \App\Models\Cliente::where('id', $cliente->id)->get()
            : \App\Models\Cliente::where('activo', true)->orderBy('razon_social')->get();

        return response()->json([
            'clientes' => $clientes->map(fn ($c) => [
                'id'                  => $c->id,
                'razon_social'        => $c->razon_social,
                'nit'                 => $c->nit,
                'etiqueta_referencia' => $c->etiqueta_referencia,
                'tipo_referencia'     => $c->tipo_referencia_defecto,
                'referencia_obligatoria' => $c->referencia_obligatoria,
                'sedes' => \App\Models\Sede::where('activo', true)
                    ->where(fn ($q) => $q->where('cliente_id', $c->id)->orWhereNull('cliente_id'))
                    ->orderBy('nombre')
                    ->get()
                    ->map(fn ($s) => [
                        'id'        => $s->id,
                        'nombre'    => $s->nombre,
                        'tipo'      => $s->tipo,
                        'direccion' => $s->direccionCompleta(),
                        'ciudad'    => $s->ciudad,
                        'propia'    => $s->cliente_id !== null,
                        'coordenada_verificada' => $s->coordenada_verificada,
                        'contacto'  => $s->contacto_nombre,
                    ])->values(),
            ])->values(),

            'tipos_paquete'   => \App\Enums\TipoPaquete::catalogo(),
            'tipos_referencia' => \App\Enums\TipoReferencia::catalogo(),
            'criticidades'    => [
                ['valor' => 'urgente', 'etiqueta' => 'Urgente', 'nota' => 'Despacho inmediato · recargo 40 % · compromiso 3 h'],
                ['valor' => 'programado', 'etiqueta' => 'Programado', 'nota' => 'Se agrupa en la ruta del día'],
            ],
            'rango_frio_defecto' => config('ltp.cadena_frio.rango_defecto'),
            'puede_elegir_cliente' => $cliente === null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['nullable', 'string', 'max:30'],
            'desde'  => ['nullable', 'date'],
            'hasta'  => ['nullable', 'date'],
            'buscar' => ['nullable', 'string', 'max:60'],
            'sede'   => ['nullable', 'integer', 'exists:sedes,id'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        $cliente = $this->clienteDe($request);

        $ordenes = Orden::query()
            ->with(['origen', 'destino', 'referencias', 'paquetes.mensajero.user'])
            ->delCliente($cliente?->id)
            ->when($datos['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($datos['hasta'] ?? null, fn ($q, $h) => $q->whereDate('created_at', '<=', $h))
            /*
             * El filtro por estado.
             *
             * Estaba declarado en las reglas de validación y NO se aplicaba: la
             * API aceptaba `?estado=entregado`, respondía 200 y devolvía todo
             * igual. Un integrador habría creído que filtraba sin hacerlo, que
             * es peor que no ofrecer el filtro.
             *
             * El estado vive en el paquete, no en la orden, de ahí el
             * `whereHas`. Acepta un grupo («en_tramite») o un estado exacto.
             */
            ->when($datos['estado'] ?? null, function ($q, $clave) {
                $estados = EstadoPaquete::resolverFiltro($clave);

                // Una clave que no existe no puede devolver todo: eso sería
                // repetir el fallo con otra cara. Devuelve vacío.
                return $q->whereHas('paquetes', fn ($p) => $p->whereIn('estado', $estados ?: ['__ninguno__']));
            })
            /*
             * Búsqueda por guía o por la referencia del cliente.
             *
             * Las dos en una sola caja: el cliente consulta por el número que
             * tiene a mano, y ese número unas veces es nuestro —AVL-260928-0010—
             * y otras suyo —la orden de compra, el radicado—. Obligarle a
             * decidir en qué campo va a buscar antes de buscar es trabajo que
             * puede hacer la consulta.
             *
             * Va en el servidor y no en el navegador porque son cientos de
             * envíos paginados de 25 en 25: filtrar solo la página visible
             * daría resultados que dependen de dónde esté uno.
             */
            ->when($datos['buscar'] ?? null, function ($q, $texto) {
                $texto = trim($texto);

                return $q->where(function ($b) use ($texto) {
                    $b->where('numero_guia', 'like', "%{$texto}%")
                        ->orWhereHas('referencias', fn ($r) => $r->where('valor', 'like', "%{$texto}%"))
                        ->orWhereHas('paquetes', fn ($p) => $p->where('codigo', 'like', "%{$texto}%"));
                });
            })

            // Por sede, mirando ambos extremos: quien filtra por «Farmacia»
            // quiere lo que sale de allí y lo que llega, no una de las dos.
            ->when($datos['sede'] ?? null, fn ($q, $sede) => $q->where(
                fn ($b) => $b->where('sede_origen_id', $sede)->orWhere('sede_destino_id', $sede)
            ))

            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return response()->json($ordenes);
    }

    private function clienteDe(Request $request): ?\App\Models\Cliente
    {
        $actor = $request->user();

        if ($actor instanceof \App\Models\Cliente) {
            return $actor;
        }

        return $actor instanceof \App\Models\User ? $actor->cliente : null;
    }

    /**
     * Decide a nombre de qué cliente se radica.
     *
     * Dos caminos que no se pueden mezclar:
     *
     *   · Quien TIENE alcance de cliente —un usuario del portal o un token de
     *     integración— radica siempre por el suyo. Si manda otro `cliente_id`
     *     se ignora: aceptarlo permitiría radicar a nombre de otra institución.
     *   · Quien NO tiene alcance —operador o administrador— radica POR un
     *     cliente y tiene que decir cuál. Es el caso más común en la operación
     *     real: el cliente llama por teléfono y el operador registra.
     */
    /**
     * Enlace temporal y firmado para imprimir la guía.
     *
     * Aquí se decide quién puede verla: un usuario de cliente solo obtiene
     * enlace para sus propias guías. El enlace lleva una firma del servidor y
     * caduca en diez minutos — tiempo de sobra para abrirlo e imprimir, poco
     * para que sirva si alguien lo reenvía.
     *
     * Se hace así porque la página de impresión la abre el navegador en una
     * pestaña nueva, y ahí no viaja el token de sesión: va en una cabecera,
     * no en una cookie. Con autenticación normal, imprimir daría 401 siempre.
     */
    public function enlaceImpresion(Request $request, string $guia): JsonResponse
    {
        $orden = Orden::where('numero_guia', $guia)->firstOrFail();

        $propio = $this->clienteDe($request);

        abort_if(
            $propio !== null && $propio->id !== $orden->cliente_id,
            403,
            'Esa guía es de otro cliente.'
        );

        return response()->json([
            'url' => URL::temporarySignedRoute(
                'guia.imprimir',
                now()->addMinutes(10),
                ['guia' => $orden->numero_guia]
            ),
            'expira_en_min' => 10,
        ]);
    }

    private function clienteDeLaRadicacion(Request $request, ?int $solicitado): \App\Models\Cliente
    {
        $propio = $this->clienteDe($request);

        if ($propio !== null) {
            return $propio;
        }

        abort_if(
            $solicitado === null,
            422,
            'Indique a nombre de qué cliente se radica el envío.'
        );

        $cliente = \App\Models\Cliente::find($solicitado);

        abort_if($cliente === null || ! $cliente->activo, 422, 'Ese cliente no existe o está desactivado.');

        return $cliente;
    }
}
