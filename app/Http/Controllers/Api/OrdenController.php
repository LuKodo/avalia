<?php

namespace App\Http\Controllers\Api;

use App\Enums\Criticidad;
use App\Enums\EstadoPaquete;
use App\Enums\TipoPaquete;
use App\Models\Orden;
use App\Models\Paquete;
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
            /*
             * Sede O dirección libre, nunca las dos ni ninguna.
             *
             * La sede sirve para lo pactado —una clínica, una bodega— y la
             * dirección libre para los domicilios, donde dar de alta la casa de
             * cada paciente como sede no tiene ningún sentido.
             */
            'sede_destino_id'  => ['nullable', 'integer', 'exists:sedes,id', 'different:sede_origen_id', 'required_without:destinatario'],

            /*
             * Domicilios: una persona con su dirección, no una sede.
             *
             * La sede sirve para lo pactado —una clínica, una bodega—: se visita
             * cien veces y conviene tenerla registrada, verificada y con zona.
             * La casa de un paciente se visita una vez, y darla de alta como
             * sede solo ensucia el catálogo.
             */
            'destinatario'            => ['nullable', 'array', 'required_without:sede_destino_id'],
            'destinatario.nombre'     => ['required_with:destinatario', 'string', 'max:160'],
            'destinatario.direccion'  => ['required_with:destinatario', 'string', 'max:200'],
            'destinatario.ciudad'     => ['required_with:destinatario', 'string', 'max:80'],
            'destinatario.rural'      => ['nullable', 'boolean'],
            'destinatario.telefono'   => ['nullable', 'string', 'max:30'],
            'destinatario.documento'  => ['nullable', 'string', 'max:30'],
            'destinatario.tipo_documento' => ['nullable', 'string', 'max:10'],
            'destinatario.detalle_acceso' => ['nullable', 'string', 'max:200'],
            'destinatario.latitud'    => ['nullable', 'numeric', 'between:-90,90'],
            'destinatario.longitud'   => ['nullable', 'numeric', 'between:-180,180'],
            'criticidad'       => ['required', 'string', 'in:urgente,programado'],
            'ventana_desde'    => ['nullable', 'date'],
            'ventana_hasta'    => ['nullable', 'date', 'after:ventana_desde'],
            'contacto_destino_nombre'   => ['nullable', 'string', 'max:120'],
            'contacto_destino_telefono' => ['nullable', 'string', 'max:30'],
            'observaciones'    => ['nullable', 'string', 'max:1000'],

            // El tope sube de 5 a 100: una caja de dispensaciones las lleva a
            // decenas, y cinco era un límite pensado para un radicado suelto.
            'referencias'          => ['nullable', 'array', 'max:100'],
            'referencias.*.tipo'   => ['required_with:referencias', 'string', 'max:30'],
            'referencias.*.valor'  => ['required_with:referencias', 'string', 'max:120'],

            'paquetes'                => ['required', 'array', 'min:1', 'max:50'],
            'paquetes.*.tipo'         => ['required', 'string', 'in:cadena_frio,controlado,general'],
            'paquetes.*.descripcion'  => ['nullable', 'string', 'max:500'],
            'paquetes.*.lote'         => ['nullable', 'string', 'max:60'],
            'paquetes.*.empaque'      => ['nullable', 'string', 'in:bolsa,caja,panales'],

            // Lo declara el cliente, que es quien sabe lo que mete en la caja.
            'paquetes.*.alto_costo'   => ['nullable', 'boolean'],
            // Opcional: quien radica diez dispensaciones en una caja no sabe
            // el peso de cada una, y un número inventado es peor que ninguno.
            'paquetes.*.peso_kg'      => ['nullable', 'numeric', 'min:0.01', 'max:2000'],
            'paquetes.*.largo_cm'     => ['nullable', 'integer', 'min:1', 'max:500'],
            'paquetes.*.ancho_cm'     => ['nullable', 'integer', 'min:1', 'max:500'],
            'paquetes.*.alto_cm'      => ['nullable', 'integer', 'min:1', 'max:500'],
            'paquetes.*.temp_min'     => ['nullable', 'numeric', 'min:-80', 'max:40'],
            'paquetes.*.temp_max'     => ['nullable', 'numeric', 'min:-80', 'max:40', 'gte:paquetes.*.temp_min'],

            /*
             * Las referencias de CADA caja.
             *
             * Una caja puede llevar varias dispensaciones y el recibido es uno
             * solo: se firma la caja, no cada documento. Por eso las referencias
             * cuelgan del paquete y no del envío —así, cuando una caja se
             * devuelve y las demás se entregan, se sabe exactamente qué SSC
             * llegaron—.
             *
             * El tope de 100 no es arbitrario: es lo que cabe en una caja de
             * dispensaciones sin que nadie se plantee si eso sigue siendo una
             * caja. Las de la orden siguen aceptandose para no romper lo que ya
             * integra por API.
             */
            'paquetes.*.referencias'        => ['nullable', 'array', 'max:100'],
            'paquetes.*.referencias.*.tipo'  => ['required_with:paquetes.*.referencias', 'string', 'max:30'],
            'paquetes.*.referencias.*.valor' => ['required_with:paquetes.*.referencias', 'string', 'max:120'],

            'asignar_automatico' => ['nullable', 'boolean'],
        ]);

        $cliente = $this->clienteDeLaRadicacion($request, $datos['cliente_id'] ?? null);

        // La sede de destino tiene que ser del mismo cliente: sin esta
        // verificación, un token válido podría radicar envíos hacia sedes ajenas
        // y usar el sistema para descubrir la red de otro cliente.
        $destino = isset($datos['sede_destino_id']) ? Sede::findOrFail($datos['sede_destino_id']) : null;

        abort_if(
            $destino && $destino->cliente_id !== null && $destino->cliente_id !== $cliente->id,
            403,
            'La sede de destino no pertenece a este cliente.'
        );

        abort_if(
            $destino !== null && filled($datos['destinatario'] ?? null),
            422,
            'Indique una sede de destino o un destinatario a domicilio, no los dos.'
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

        // Un domicilio no pasa por aprobación: no es un punto pactado, es la
        // casa de alguien y la escribe el cliente para un envío concreto.
        foreach (array_filter([['origen', $origen], ['destino', $destino]], fn ($par) => $par[1] !== null) as [$rotulo, $punto]) {
            if (($punto->estado_aprobacion ?? 'aprobada') === 'aprobada') {
                continue;
            }

            abort(422, $punto->estado_aprobacion === 'rechazada'
                ? "El punto de {$rotulo} «{$punto->nombre}» fue rechazado: {$punto->motivo_revision}"
                : "El punto de {$rotulo} «{$punto->nombre}» esta pendiente de aprobacion por Avalia.");
        }

        /*
         * La referencia obligatoria vale en CUALQUIERA de los dos niveles.
         *
         * Desde que las referencias cuelgan de la caja —una guía con tres SSC
         * necesita saber cuál iba en cuál—, hay radicaciones perfectamente
         * identificadas que no llevan ninguna a nivel de envío. Mirar solo ahí
         * las rechazaba todas, y con ellas la radicación masiva entera, cuyo
         * modelo es justamente «una fila, un SSC, dentro de su caja».
         */
        $hayReferenciaDeCaja = collect($datos['paquetes'] ?? [])
            ->contains(fn (array $p) => ! empty($p['referencias']));

        if ($cliente->referencia_obligatoria && empty($datos['referencias']) && ! $hayReferenciaDeCaja) {
            abort(422, "Este cliente exige {$cliente->etiqueta_referencia} en cada radicación, "
                .'en el envío o en alguna de sus cajas.');
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

        /*
         * El destinatario a domicilio se crea ANTES de la orden.
         *
         * Nace como no persistente: pertenece a esta guía, no al padrón del
         * cliente. Un paciente que recibe una vez no tiene por qué quedarse
         * guardado para siempre, y la tabla ya trae la política de retención
         * que lo suprime cuando vence.
         */
        $destinatarioId = null;

        if (filled($datos['destinatario'] ?? null)) {
            $d = $datos['destinatario'];

            $destinatarioId = \App\Models\Destinatario::create([
                'cliente_id' => $cliente->id,
                'tipo'       => 'paciente',
                'nombre'     => $d['nombre'],
                'tipo_documento' => $d['tipo_documento'] ?? null,
                'documento'  => $d['documento'] ?? null,
                'telefono'   => $d['telefono'] ?? null,
                'direccion'  => $d['direccion'],
                'detalle_acceso' => $d['detalle_acceso'] ?? null,
                'ciudad'     => $d['ciudad'],
                'rural'      => (bool) ($d['rural'] ?? false),
                'latitud'    => $d['latitud'] ?? null,
                'longitud'   => $d['longitud'] ?? null,
                // Verificada solo si vino marcada en el mapa. Una dirección
                // escrita a mano no es una coordenada comprobada.
                'coordenada_verificada' => isset($d['latitud'], $d['longitud']),
                'origen_dato' => $request->user() instanceof \App\Models\Cliente ? 'api' : 'formulario',
                'persistente' => false,
            ])->id;
        }

        $orden = DB::transaction(function () use ($datos, $cliente, $request, $destinatarioId) {
            $criticidad = Criticidad::from($datos['criticidad']);

            $orden = Orden::create([
                'numero_guia'      => $this->guias->generar(),
                'cliente_id'       => $cliente->id,
                'sede_origen_id'   => $datos['sede_origen_id'],
                'sede_destino_id'  => $datos['sede_destino_id'] ?? null,
                'destinatario_id'  => $destinatarioId,
                'creada_por'       => $request->user() instanceof \App\Models\User ? $request->user()->id : null,
                'criticidad'       => $criticidad,
                'ventana_desde'    => $datos['ventana_desde'] ?? null,
                'ventana_hasta'    => $datos['ventana_hasta'] ?? null,
                /*
                 * El compromiso cuenta desde que ABRE la ventana, no desde que
                 * se radica.
                 *
                 * Antes se contaba siempre desde `now()`, y eso producía
                 * compromisos incoherentes: una guia urgente radicada a las 8
                 * para recoger a las 14 prometía estar entregada a las 11, tres
                 * horas antes de que el mensajero pudiera siquiera recogerla.
                 *
                 * Nunca por debajo de ahora: una ventana que abrió ayer no puede
                 * generar un compromiso ya vencido en el momento de radicar.
                 */
                'comprometida_en'  => $datos['ventana_hasta']
                    ?? $this->compromisoDesde($datos['ventana_desde'] ?? null, $criticidad),
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
                    'empaque'     => \App\Enums\TipoEmpaque::tryFrom($p['empaque'] ?? '') ?? \App\Enums\TipoEmpaque::Caja,
                    'alto_costo'  => (bool) ($p['alto_costo'] ?? false),
                    'peso_kg'     => $p['peso_kg'] ?? null,
                    'largo_cm'    => $p['largo_cm'] ?? null,
                    'ancho_cm'    => $p['ancho_cm'] ?? null,
                    'alto_cm'     => $p['alto_cm'] ?? null,
                    'temp_min'    => $tipo->exigeRefrigeracion() ? ($p['temp_min'] ?? config('ltp.cadena_frio.rango_defecto.min')) : null,
                    'temp_max'    => $tipo->exigeRefrigeracion() ? ($p['temp_max'] ?? config('ltp.cadena_frio.rango_defecto.max')) : null,
                ]);

                foreach ($p['referencias'] ?? [] as $ref) {
                    ReferenciaCliente::updateOrCreate(
                        [
                            'cliente_id'        => $cliente->id,
                            'tipo'              => $ref['tipo'],
                            'valor_normalizado' => ReferenciaCliente::normalizar($ref['valor']),
                        ],
                        [
                            'orden_id'   => $orden->id,
                            'paquete_id' => $paquete->id,
                            'valor'      => $ref['valor'],
                        ]
                    );
                }

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
    /**
     * A qué hora queda comprometida una guía sin hora de cierre.
     *
     * Las horas van por criticidad: tres para una urgente, veinticuatro para
     * una programada. Es la regla que el cliente ya conoce y la que el
     * formulario propone en pantalla; aquí se repite porque una radicación por
     * API no pasa por el formulario y tiene que obtener lo mismo.
     */
    private function compromisoDesde(?string $ventanaDesde, Criticidad $criticidad): \Illuminate\Support\Carbon
    {
        $horas = $criticidad === Criticidad::Urgente ? 3 : 24;
        $inicio = $ventanaDesde ? \Illuminate\Support\Carbon::parse($ventanaDesde) : now();

        return $inicio->lt(now())
            ? now()->addHours($horas)
            : $inicio->copy()->addHours($horas);
    }

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
                        // Para avisar al escogerla: un destino rural cuesta
                        // distinto, y enterarse en la factura es tarde.
                        'rural'     => (bool) $s->rural,
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
            ->with(['origen', 'destino', 'destinatario', 'referencias', 'paquetes.mensajero.user'])
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

    /**
     * Cancela una guía radicada por error.
     *
     * Cancelada, no borrada. Una guía que desaparece se lleva por delante sus
     * eventos, y con ellos la afirmación de que la bitácora no se toca —que es
     * lo único que convierte esta plataforma en algo que sirve ante un
     * auditor—. Así que la guía se queda, marcada, con quién la canceló,
     * cuándo y por qué.
     *
     * **Solo antes de que alguien la tome.** En cuanto hay un mensajero
     * asignado, la caja entró en la operación de otra persona: puede estar ya
     * en una ruta, o recogida. A partir de ahí no se cancela desde la pantalla
     * del cliente; se reporta y lo resuelve operación, que es quien sabe dónde
     * está la caja.
     */
    public function cancelar(Request $request, string $guia): JsonResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $orden = Orden::where('numero_guia', Orden::normalizarGuia($guia))
            ->with('paquetes')
            ->firstOrFail();

        $cliente = $this->clienteDe($request);

        // Un cliente solo cancela lo suyo. La operación puede cancelar
        // cualquiera, que para eso responde por todas.
        abort_if(
            $cliente !== null && $orden->cliente_id !== $cliente->id,
            403,
            'Esa guía no es suya.'
        );

        $cancelables = $orden->paquetes->filter(
            fn (Paquete $p) => $p->estado === EstadoPaquete::Creado
        );

        abort_if(
            $cancelables->isEmpty(),
            409,
            'Esta guía ya está en manos de la operación y no se puede cancelar desde aquí. '
            .'Comuníquese con Avalia para reportarla.'
        );

        return DB::transaction(function () use ($orden, $cancelables, $datos, $request) {
            foreach ($cancelables as $paquete) {
                $this->registrador->registrar($paquete, EstadoPaquete::Cancelado, [
                    'tipo'  => 'cancelacion',
                    'actor' => $request->user(),
                    'datos' => ['motivo' => $datos['motivo']],
                ]);
            }

            /*
             * Si se cancelaron TODAS las cajas, la guía queda cerrada. Si solo
             * algunas —porque las demás ya iban en ruta— la guía sigue viva:
             * cerrarla entera dejaría cajas en la calle sin guía que las
             * explique.
             */
            if ($cancelables->count() === $orden->paquetes->count()) {
                $orden->forceFill([
                    'estado_comercial' => 'cancelada',
                    'cerrada_en'       => now(),
                ])->save();
            }

            return response()->json([
                'guia'       => $orden->numero_guia,
                'canceladas' => $cancelables->count(),
                'de'         => $orden->paquetes->count(),
                'mensaje'    => $cancelables->count() === $orden->paquetes->count()
                    ? 'Guía cancelada. Queda registrada como cancelada, no borrada.'
                    : 'Se cancelaron las cajas que todavía no habían salido. El resto sigue en ruta.',
            ]);
        });
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
