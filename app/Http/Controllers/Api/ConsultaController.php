<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditoriaAcceso;
use App\Models\EnlaceRastreo;
use App\Models\Orden;
use App\Services\Consulta\ConsultaEnvios;
use App\Services\Consulta\Exportador;
use App\Services\Consulta\SoporteEntrega;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * API de consulta para el cliente.
 *
 * Es la cara de Avalia hacia los sistemas del cliente. Todo aquí acepta las dos
 * llaves —la guía Avalia y la referencia que el cliente definió— porque obligarlo
 * a traducir entre ambas es trasladarle un problema nuestro.
 *
 * El alcance por cliente NO es opcional: un token de integración solo ve los
 * envíos de su propio cliente, y eso se aplica en la capa de consulta, nunca en
 * la interfaz.
 */
class ConsultaController extends Controller
{
    public function __construct(
        private ConsultaEnvios $consulta,
        private SoporteEntrega $soporte,
        private Exportador $exportador,
    ) {}

    /**
     * Una sola caja de búsqueda: guía Avalia, referencia del cliente o código de
     * la etiqueta del paquete. El servicio decide cuál es.
     *
     * GET /api/v1/rastreo?q=OC-4471
     */
    public function rastrear(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'q'    => ['required', 'string', 'max:120'],
            'tipo' => ['nullable', 'string', 'max:30'],
        ]);

        $clienteId = $this->alcanceCliente($request);

        $resultado = isset($datos['tipo'])
            ? ['tipo' => 'referencia', 'resultados' => $this->consulta->porReferencia($datos['q'], $datos['tipo'], $clienteId)]
            : $this->consulta->resolver($datos['q'], $clienteId);

        if ($resultado['resultados']->isEmpty()) {
            return response()->json([
                'encontrado' => false,
                'consultado' => $datos['q'],
                'mensaje'    => 'No hay envíos con esa guía ni con esa referencia. '
                    .'Verifique el dato o consulte por el código de la etiqueta del paquete.',
            ], 404);
        }

        // El cliente dueño ve el documento completo de su propio receptor —es el
        // soporte que necesita para cerrar su proceso— y ese acceso queda
        // registrado. Cualquier otro lo ve enmascarado. La decisión se toma por
        // envío, no por consulta: un operador interno puede recibir resultados
        // de varios clientes en la misma búsqueda.
        $envios = $resultado['resultados']->map(function (Orden $o) use ($clienteId) {
            $esDueno = $clienteId !== null && $o->cliente_id === $clienteId;

            if ($esDueno) {
                AuditoriaAcceso::registrar(
                    accion: 'ver_datos_receptor',
                    recursoTipo: 'orden',
                    recursoId: $o->id,
                    justificacion: 'consulta del cliente dueño del envío',
                );
            }

            return $this->soporte->armar($o, incluirDatosReceptor: $esDueno);
        });

        return response()->json([
            'encontrado'   => true,
            'buscado_como' => $resultado['tipo'],
            'total'        => $envios->count(),
            'envios'       => $envios->values(),
        ]);
    }

    /**
     * Soporte de entrega completo de un envío, por guía Avalia.
     *
     * GET /api/v1/envios/{guia}/soporte
     */
    public function soporte(Request $request, string $guia): JsonResponse
    {
        $clienteId = $this->alcanceCliente($request);
        $orden     = $this->consulta->porGuia($guia, $clienteId);

        abort_if($orden === null, 404, 'No existe un envío con esa guía.');

        // El cliente dueño del envío sí ve el documento completo del receptor:
        // es el soporte que necesita para su propia auditoría. Queda registrado.
        $esDueno = $clienteId !== null && $orden->cliente_id === $clienteId;

        AuditoriaAcceso::registrar(
            accion: 'ver_soporte_entrega',
            recursoTipo: 'orden',
            recursoId: $orden->id,
            justificacion: $esDueno ? 'consulta del cliente dueño del envío' : 'consulta interna',
        );

        return response()->json(
            $this->soporte->armar($orden, incluirDatosReceptor: $esDueno, verificarCadena: true)
        );
    }

    /**
     * Consulta en lote. Un hospital con 180 radicados en el mes manda su lista
     * completa y recibe el estado de todos —incluidos los que no encontramos,
     * que son justamente los que le interesan—.
     *
     * POST /api/v1/rastreo/lote  { "claves": ["OC-4471", "AVL-260821-0417", ...] }
     */
    public function lote(Request $request): JsonResponse
    {
        $maximo = (int) config('ltp.integraciones.max_consulta_lote', 200);

        $datos = $request->validate([
            'claves'   => ['required', 'array', "max:{$maximo}"],
            'claves.*' => ['required', 'string', 'max:120'],
            'detalle'  => ['nullable', 'boolean'],
        ]);

        $clienteId = $this->alcanceCliente($request);
        $resultado = $this->consulta->lote($datos['claves'], $clienteId);
        $detalle   = $datos['detalle'] ?? false;

        $envios = [];

        foreach ($resultado['encontrados'] as $clave => $orden) {
            $esDueno = $clienteId !== null && $orden->cliente_id === $clienteId;

            $envios[] = $detalle
                ? ['consultado' => $clave] + $this->soporte->armar($orden, incluirDatosReceptor: $esDueno)
                : [
                    'consultado'      => $clave,
                    'guia_avalia'        => $orden->numero_guia,
                    'estado'          => $orden->estadoConsolidado(),
                    'entregado_en'    => $orden->paquetes->max('entregado_en')?->toIso8601String(),
                    'recibido_por'    => $orden->paquetes->first()?->entrega?->receptor_nombre,
                    'dentro_de_ventana' => $orden->dentroDeVentana(),
                ];
        }

        return response()->json([
            'total_consultado' => $resultado['total'],
            'encontrados'      => count($envios),
            'sin_resultado'    => $resultado['sin_resultado'],
            'envios'           => $envios,
        ]);
    }

    /**
     * Exportación de uno o varios envíos con su soporte.
     *
     * GET /api/v1/envios/exportar?formato=csv&desde=2026-08-01&hasta=2026-08-21
     */
    public function exportar(Request $request)
    {
        $datos = $request->validate([
            'formato'  => ['required', 'in:json,csv'],
            'guias'    => ['nullable', 'array', 'max:1000'],
            'guias.*'  => ['string', 'max:32'],
            'desde'    => ['nullable', 'date'],
            'hasta'    => ['nullable', 'date', 'after_or_equal:desde'],
            'estado'   => ['nullable', 'string', 'max:30'],
        ]);

        $clienteId = $this->alcanceCliente($request);

        $ordenes = Orden::query()
            ->with([
                'cliente', 'origen', 'destino', 'referencias',
                'paquetes.entrega', 'paquetes.mensajero.user', 'paquetes.mensajero.vehiculo',
                'paquetes.temperaturas', 'paquetes.novedades', 'paquetes.evidencias', 'paquetes.eventos',
            ])
            ->delCliente($clienteId)
            ->when($datos['guias'] ?? null, fn ($q, $g) => $q->whereIn('numero_guia', array_map(Orden::normalizarGuia(...), $g)))
            ->when($datos['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($datos['hasta'] ?? null, fn ($q, $h) => $q->whereDate('created_at', '<=', $h))
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get();

        $esDueno = $clienteId !== null;

        $this->exportador->registrar(
            usuario: $request->user() instanceof \App\Models\User ? $request->user() : null,
            clienteId: $clienteId,
            tipo: isset($datos['guias']) ? 'lote' : 'periodo',
            formato: $datos['formato'],
            criterio: $datos,
            registros: $ordenes->count(),
            incluyeSoporte: true,
            incluyeDatosReceptor: $esDueno,
        );

        return $datos['formato'] === 'csv'
            ? $this->exportador->csv($ordenes, $esDueno)
            : response()->json($this->exportador->json($ordenes, $esDueno));
    }

    /**
     * Enlace público de rastreo para reenviar por correo o WhatsApp. Sin
     * credenciales, con vencimiento y revocable.
     *
     * POST /api/v1/envios/{guia}/enlace
     */
    public function emitirEnlace(Request $request, string $guia): JsonResponse
    {
        $datos = $request->validate([
            'dias_vigencia' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $orden = $this->consulta->porGuia($guia, $this->alcanceCliente($request));

        abort_if($orden === null, 404, 'No existe un envío con esa guía.');

        $usuario = $request->user();
        $enlace  = EnlaceRastreo::emitir(
            $orden,
            $usuario instanceof \App\Models\User ? $usuario : null,
            $datos['dias_vigencia'] ?? 30
        );

        return response()->json([
            'url'       => $enlace->url(),
            'expira_en' => $enlace->expira_en->toIso8601String(),
            'aviso'     => 'Cualquiera con este enlace puede ver el estado del envío. '
                .'No incluye el documento completo del receptor.',
        ], 201);
    }

    /**
     * Alcance de datos. Un token de integración pertenece al cliente; un usuario
     * de portal pertenece a su cliente; un operador interno no tiene alcance
     * restringido y ve todo.
     */
    private function alcanceCliente(Request $request): ?int
    {
        $actor = $request->user();

        if ($actor instanceof \App\Models\Cliente) {
            return $actor->id;
        }

        if ($actor instanceof \App\Models\User && $actor->cliente_id !== null) {
            return $actor->cliente_id;
        }

        return null;
    }
}
