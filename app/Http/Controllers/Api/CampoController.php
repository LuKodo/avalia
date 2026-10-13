<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoPaquete;
use App\Enums\TipoNovedad;
use App\Models\Entrega;
use App\Models\Evidencia;
use App\Models\LecturaTemperatura;
use App\Models\Novedad;
use App\Models\Paquete;
use App\Models\Posicion;
use App\Services\Trazabilidad\RegistradorEventos;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * API de campo: lo que consume la interfaz del mensajero.
 *
 * Todo aquí está diseñado para llegar desde un dispositivo que estuvo sin señal:
 * identificadores generados en el cliente, claves de idempotencia y aceptación
 * de eventos fuera de orden. Un reintento nunca duplica un evento, y un evento
 * tardío nunca retrocede el estado.
 */
class CampoController extends Controller
{
    public function __construct(private RegistradorEventos $registrador) {}

    /**
     * El mensajero de la petición, y que el paquete sea suyo.
     *
     * Faltaba: cualquier mensajero autenticado podía recolectar, entregar o
     * reportar novedad sobre CUALQUIER paquete con solo conocer su id. Y el
     * código que `recolectar` exige no es un secreto —va impreso en la
     * etiqueta—, así que no servía de barrera.
     *
     * Importa más desde que la etiqueta lleva un QR: una foto de una caja
     * ajena no puede convertirse en poder para moverla.
     */
    private function mensajeroDe(Request $request, Paquete $paquete): \App\Models\Mensajero
    {
        $mensajero = $request->user()->mensajero;

        abort_if($mensajero === null, 403, 'La cuenta no está asociada a un mensajero.');

        abort_unless(
            $paquete->mensajero_id === $mensajero->id,
            403,
            'Ese paquete no está asignado a usted.'
        );

        return $mensajero;
    }

    /** Tareas del día del mensajero autenticado. */
    public function tareas(Request $request): JsonResponse
    {
        $mensajero = $request->user()->mensajero;

        abort_if($mensajero === null, 403, 'La cuenta no está asociada a un mensajero.');

        /*
         * Lo que este mensajero tiene ENTRE MANOS, no lo que se radico hace
         * poco.
         *
         * Antes se filtraba por `created_at` de los ultimos dos dias, y eso
         * escondia el trabajo real: una guia radicada la semana pasada y
         * asignada esta manana no aparecia nunca en el telefono. El mensajero
         * veia «No hay tareas asignadas» mientras el operador la daba por
         * despachada, y nadie podia saber donde se habia perdido.
         *
         * Se filtra por estado: lo que sigue abierto se muestra siempre, sin
         * importar su antiguedad, y lo cerrado solo si se cerro hoy -para que
         * la cuenta de «hechas» tenga sentido al final de la jornada-.
         */
        $cerrados = [
            EstadoPaquete::Entregado->value,
            EstadoPaquete::Devuelto->value,
            EstadoPaquete::Siniestro->value,
        ];

        $paquetes = Paquete::query()
            ->where('mensajero_id', $mensajero->id)
            ->where(function ($q) use ($cerrados) {
                $q->whereNotIn('estado', $cerrados)
                    ->orWhereDate('updated_at', now()->toDateString());
            })
            ->with(['orden.origen', 'orden.destino', 'orden.cliente', 'orden.referencias'])
            ->orderBy('estado')
            ->get()
            ->map(fn (Paquete $p) => [
                'paquete_id' => $p->id,
                'codigo'     => $p->codigo,
                'guia'       => $p->orden->numero_guia,
                'referencia' => $p->orden->referencias->first()?->valor,
                'cliente'    => $p->orden->cliente->razon_social,
                'estado'     => $p->estado->value,
                'accion'     => $p->estado->accionDeCampo(),
                'nota'       => $p->estado->notaDeCampo(),
                'estado_etiqueta' => $p->estado->etiqueta(),
                'tipo'       => $p->tipo->value,
                'tipo_etiqueta' => $p->tipo->etiqueta(),
                'criticidad' => $p->orden->criticidad->value,
                'sede'       => $p->estado->accionDeCampo() === 'recoger'
                    ? $this->sede($p->orden->origen)
                    : $this->sede($p->orden->destino),
                'ventana'    => [
                    'desde' => $p->orden->ventana_desde?->format('H:i'),
                    'hasta' => $p->orden->ventana_hasta?->format('H:i'),
                ],
                'peso_kg'    => $p->peso_kg,
                'dimensiones' => "{$p->largo_cm}×{$p->ancho_cm}×{$p->alto_cm} cm",
                'rango_termico' => $p->exigeCadenaFrio() ? $p->rangoTermico() : null,
                // La interfaz de campo lee esta lista en vez de repetir
                // condicionales por tipo de paquete.
                'controles'  => $p->controlesPendientes($p->estado->bajoCustodia() ? 'entrega' : 'recoleccion'),
            ]);

        return response()->json([
            'mensajero'  => $mensajero->nombre(),
            'en_turno'   => $mensajero->en_turno,
            'pendientes' => $paquetes->where('estado', '!=', EstadoPaquete::Entregado->value)->count(),
            'tareas'     => $paquetes->values(),
            'servidor_en' => now()->toIso8601String(),
        ]);
    }

    /**
     * Recolección. Sin escaneo válido no hay recolección: el código escaneado
     * tiene que coincidir con el del paquete, punto.
     */
    public function recolectar(Request $request, Paquete $paquete): JsonResponse
    {
        $this->mensajeroDe($request, $paquete);

        $datos = $request->validate([
            'evento_id'   => ['required', 'uuid'],       // generado en el dispositivo
            'codigo'      => ['required', 'string', 'max:32'],
            'ocurrido_en' => ['required', 'date'],
            'latitud'     => ['nullable', 'numeric', 'between:-90,90'],
            'longitud'    => ['nullable', 'numeric', 'between:-180,180'],
            'precision_m' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'temperatura' => ['nullable', 'numeric', 'between:-80,60'],
        ]);

        // Idempotencia: si el evento ya entró, el reintento devuelve lo mismo
        // en vez de duplicar. Es el caso normal cuando la señal va y viene.
        if ($previo = \App\Models\EventoPaquete::find($datos['evento_id'])) {
            return response()->json(['duplicado' => true, 'estado' => $previo->estado_nuevo->value]);
        }

        $paquete->loadMissing('orden.origen', 'orden.destino');

        abort_unless(
            strcasecmp(trim($datos['codigo']), $paquete->codigo) === 0,
            422,
            'El código escaneado no corresponde a este paquete.'
        );

        $this->verificarTransicion($paquete, EstadoPaquete::Recolectado, $datos['ocurrido_en']);

        if ($paquete->exigeCadenaFrio() && ! isset($datos['temperatura'])) {
            abort(422, 'Este paquete exige registro de temperatura en la recolección.');
        }

        return DB::transaction(function () use ($datos, $paquete, $request) {
            if (isset($datos['temperatura'])) {
                $this->registrarTemperatura($paquete, (float) $datos['temperatura'], 'recoleccion', $datos['ocurrido_en']);
            }

            $evento = $this->registrador->registrar($paquete, EstadoPaquete::Recolectado, [
                'id'          => $datos['evento_id'],
                'actor'       => $request->user(),
                'ocurrido_en' => $this->momentoDelDispositivo($datos['ocurrido_en']),
                'lat'         => $datos['latitud'] ?? null,
                'lon'         => $datos['longitud'] ?? null,
                'precision_m' => $datos['precision_m'] ?? null,
                'datos'       => [
                    'escaneo_valido' => true,
                    'temperatura'    => $datos['temperatura'] ?? null,
                ],
            ]);

            return response()->json([
                'estado'        => $paquete->fresh()->estado->value,
                'evento_id'     => $evento->id,
                'secuencia'     => (int) $evento->secuencia,
                'registrado_en' => $evento->registrado_en->toIso8601String(),
            ], 201);
        });
    }

    /**
     * Entrega con prueba completa. Se valida que la coordenada corresponda a la
     * sede de destino: no bloquea la entrega —hay sótanos sin GPS— pero queda
     * marcada como incoherente y visible en el soporte.
     */
    public function entregar(Request $request, Paquete $paquete): JsonResponse
    {
        $this->mensajeroDe($request, $paquete);

        $datos = $request->validate([
            'evento_id'          => ['required', 'uuid'],
            'codigo'             => ['required', 'string', 'max:32'],
            'ocurrido_en'        => ['required', 'date'],
            'receptor_nombre'    => ['required', 'string', 'max:120'],
            'receptor_documento' => ['required', 'string', 'max:30'],
            'receptor_cargo'     => ['nullable', 'string', 'max:120'],
            'consentimiento'     => ['required', 'accepted'],
            'latitud'            => ['nullable', 'numeric', 'between:-90,90'],
            'longitud'           => ['nullable', 'numeric', 'between:-180,180'],
            'precision_m'        => ['nullable', 'integer', 'min:0', 'max:5000'],
            'temperatura'        => ['nullable', 'numeric', 'between:-80,60'],
            'firma_id'           => ['nullable', 'uuid', 'exists:evidencias,id'],
            'foto_receptor_id'   => ['nullable', 'uuid', 'exists:evidencias,id'],
        ]);

        if ($previo = \App\Models\EventoPaquete::find($datos['evento_id'])) {
            return response()->json(['duplicado' => true, 'estado' => $previo->estado_nuevo->value]);
        }

        $paquete->loadMissing('orden.destino', 'mensajero');

        abort_unless(
            strcasecmp(trim($datos['codigo']), $paquete->codigo) === 0,
            422,
            'El código escaneado no corresponde a este paquete.'
        );

        $this->verificarTransicion($paquete, EstadoPaquete::Entregado, $datos['ocurrido_en']);
        $this->verificarControles($paquete, $datos);

        return DB::transaction(function () use ($datos, $paquete, $request) {
            $destino  = $paquete->orden->destino;
            $distancia = null;

            if (isset($datos['latitud'], $datos['longitud']) && $destino?->tieneCoordenada()) {
                $distancia = Geo::distanciaMetros(
                    (float) $datos['latitud'], (float) $datos['longitud'],
                    $destino->latitud, $destino->longitud
                );
            }

            $radio     = (int) config('ltp.entrega.radio_validacion_metros', 150);
            $coherente = $distancia === null || $distancia <= $radio;

            $ocurrido = $this->momentoDelDispositivo($datos['ocurrido_en']);
            $ahora    = now();

            if (isset($datos['temperatura'])) {
                $this->registrarTemperatura($paquete, (float) $datos['temperatura'], 'entrega', $datos['ocurrido_en']);
            }

            Entrega::create([
                'paquete_id'         => $paquete->id,
                'receptor_nombre'    => $datos['receptor_nombre'],
                'receptor_documento' => $datos['receptor_documento'],
                'receptor_cargo'     => $datos['receptor_cargo'] ?? null,
                'latitud'            => $datos['latitud'] ?? null,
                'longitud'           => $datos['longitud'] ?? null,
                'precision_m'        => $datos['precision_m'] ?? null,
                'distancia_a_sede_m' => $distancia,
                'geolocalizacion_coherente' => $coherente,
                'entregada_en'       => $ahora,
                'entregada_en_dispositivo' => $ocurrido,
                'desfase_reloj_seg'  => $ocurrido->getTimestamp() - $ahora->getTimestamp(),
                'dentro_de_ventana'  => $paquete->orden->comprometida_en === null
                    || $ahora->lte($paquete->orden->comprometida_en),
                'firma_id'           => $datos['firma_id'] ?? null,
                'foto_receptor_id'   => $datos['foto_receptor_id'] ?? null,
                'consentimiento_datos' => true,
            ]);

            $evento = $this->registrador->registrar($paquete, EstadoPaquete::Entregado, [
                'id'          => $datos['evento_id'],
                'actor'       => $request->user(),
                'ocurrido_en' => $ocurrido,
                'lat'         => $datos['latitud'] ?? null,
                'lon'         => $datos['longitud'] ?? null,
                'precision_m' => $datos['precision_m'] ?? null,
                'datos'       => [
                    // El nombre del receptor entra a la bitácora; el documento
                    // no: vive cifrado en `entregas` y su acceso deja registro.
                    'receptor'          => $datos['receptor_nombre'],
                    'distancia_sede_m'  => $distancia,
                    'geo_coherente'     => $coherente,
                    'temperatura_final' => $datos['temperatura'] ?? null,
                ],
            ]);

            if ($paquete->mensajero) {
                $paquete->mensajero->decrement('carga_actual_kg', $paquete->peso_kg);
                $paquete->mensajero->increment('entregas_totales');
            }

            $this->enviarSoporteSiProcede($paquete, $datos['foto_receptor_id'] ?? null);

            return response()->json([
                'estado'        => 'entregado',
                'evento_id'     => $evento->id,
                'entregada_en'  => $ahora->toIso8601String(),
                'geolocalizacion_coherente' => $coherente,
                'distancia_a_sede_m' => $distancia,
            ], 201);
        });
    }

    /** Registro de novedad: el camino cuando la entrega no se puede completar. */
    public function novedad(Request $request, Paquete $paquete): JsonResponse
    {
        $this->mensajeroDe($request, $paquete);

        $datos = $request->validate([
            'evento_id'   => ['required', 'uuid'],
            'tipo'        => ['required', 'string'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'ocurrido_en' => ['required', 'date'],
            'latitud'     => ['nullable', 'numeric', 'between:-90,90'],
            'longitud'    => ['nullable', 'numeric', 'between:-180,180'],
            'evidencia_id' => ['nullable', 'uuid', 'exists:evidencias,id'],
        ]);

        $tipo = TipoNovedad::from($datos['tipo']);

        if ($tipo->exigeEvidenciaFotografica() && empty($datos['evidencia_id'])) {
            abort(422, "La novedad «{$tipo->etiqueta()}» exige evidencia fotográfica.");
        }

        return DB::transaction(function () use ($datos, $tipo, $paquete, $request) {
            Novedad::create([
                'paquete_id'    => $paquete->id,
                'reportada_por' => $request->user()->id,
                'tipo'          => $tipo,
                'descripcion'   => $datos['descripcion'] ?? null,
                'reportada_en'  => now(),
            ]);

            $evento = $this->registrador->registrar($paquete, EstadoPaquete::Novedad, [
                'id'          => $datos['evento_id'],
                'actor'       => $request->user(),
                'tipo'        => 'novedad',
                'ocurrido_en' => $this->momentoDelDispositivo($datos['ocurrido_en']),
                'lat'         => $datos['latitud'] ?? null,
                'lon'         => $datos['longitud'] ?? null,
                'evidencia_id' => $datos['evidencia_id'] ?? null,
                'datos'       => [
                    'tipo_novedad' => $tipo->value,
                    'severidad'    => $tipo->severidad(),
                    'descripcion'  => $datos['descripcion'] ?? null,
                ],
            ]);

            return response()->json(['estado' => 'novedad', 'evento_id' => $evento->id], 201);
        });
    }

    /** Emisión de posición. Se acepta en lote: el dispositivo acumula sin señal. */
    public function posiciones(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'posiciones'                 => ['required', 'array', 'max:500'],
            'posiciones.*.latitud'       => ['required', 'numeric', 'between:-90,90'],
            'posiciones.*.longitud'      => ['required', 'numeric', 'between:-180,180'],
            'posiciones.*.registrado_en' => ['required', 'date'],
            'posiciones.*.precision_m'   => ['nullable', 'integer'],
            'posiciones.*.velocidad_kmh' => ['nullable', 'integer'],
            'bateria'                    => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $mensajero = $request->user()->mensajero;
        abort_if($mensajero === null, 403);

        /*
         * La marca se convierte antes de insertar, y no es un detalle menor:
         * `insert()` escribe directamente por el constructor de consultas y no
         * pasa por los `casts` del modelo, así que la cadena ISO del teléfono
         * —«2026-08-25T00:49:05-05:00»— llegaba tal cual a MariaDB, que la
         * rechazaba con un error de formato.
         *
         * El resultado era que NINGÚN reporte de posición se guardaba. Y como el
         * filtro duro descarta a los mensajeros sin señal reciente, el motor de
         * asignación se quedaba sin candidatos para todo: la bandeja de despacho
         * mostraba «Sin candidato viable» en cada fila y nadie sabía por qué.
         */
        $filas = collect($datos['posiciones'])->map(fn ($p) => [
            'mensajero_id'  => $mensajero->id,
            'latitud'       => $p['latitud'],
            'longitud'      => $p['longitud'],
            'precision_m'   => $p['precision_m'] ?? null,
            'velocidad_kmh' => $p['velocidad_kmh'] ?? null,
            'registrado_en' => $this->momentoDelDispositivo($p['registrado_en']),
            'recibido_en'   => now(),
        ])->all();

        Posicion::insert($filas);

        $ultima = collect($datos['posiciones'])
            ->sortByDesc(fn ($p) => $this->momentoDelDispositivo($p['registrado_en'])->getTimestamp())
            ->first();

        $mensajero->update([
            'ultima_latitud'     => $ultima['latitud'],
            'ultima_longitud'    => $ultima['longitud'],
            'ultima_posicion_en' => now(),
            'bateria'            => $datos['bateria'] ?? $mensajero->bateria,
        ]);

        return response()->json(['recibidas' => count($filas)]);
    }

    /** Subida de evidencia. El checksum permite detectar corrupción en tránsito. */
    public function evidencia(Request $request, Paquete $paquete): JsonResponse
    {
        $this->mensajeroDe($request, $paquete);

        $datos = $request->validate([
            'id'           => ['required', 'uuid'],
            'tipo'         => ['required', 'in:firma,foto_receptor,foto_indicador,foto_empaque,documento,acta'],
            'archivo'      => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'],
            'capturada_en' => ['nullable', 'date'],
        ]);

        if ($existente = Evidencia::find($datos['id'])) {
            return response()->json(['duplicado' => true, 'evidencia_id' => $existente->id]);
        }

        $paquete->loadMissing('orden');

        $archivo   = $request->file('archivo');
        $contenido = file_get_contents($archivo->getRealPath());

        $ruta = $archivo->store("evidencias/{$paquete->orden->numero_guia}");

        $evidencia = Evidencia::create([
            'id'           => $datos['id'],
            'paquete_id'   => $paquete->id,
            'tipo'         => $datos['tipo'],
            'ruta'         => $ruta,
            'mime'         => $archivo->getClientMimeType(),
            'bytes'        => $archivo->getSize(),
            'checksum'     => hash('sha256', $contenido),
            'capturada_en' => $datos['capturada_en'] ?? null,
        ]);

        return response()->json([
            'evidencia_id' => $evidencia->id,
            'checksum'     => $evidencia->checksum,
        ], 201);
    }

    // ------------------------------------------------------------------ apoyo

    private function registrarTemperatura(Paquete $paquete, float $valor, string $momento, string $cuando): void
    {
        LecturaTemperatura::create([
            'paquete_id'     => $paquete->id,
            'valor'          => $valor,
            'momento'        => $momento,
            'fuente'         => 'manual',
            'fuera_de_rango' => $paquete->exigeCadenaFrio() && ! $paquete->temperaturaEnRango($valor),
            'registrada_en'  => $cuando,
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function verificarControles(Paquete $paquete, array $datos): void
    {
        $mapa = [
            'temperatura'        => isset($datos['temperatura']),
            'documento_receptor' => ! empty($datos['receptor_documento']),
            'foto_receptor'      => ! empty($datos['foto_receptor_id']),
            'firma'              => ! empty($datos['firma_id']),
        ];

        foreach ($paquete->controlesPendientes('entrega') as $control) {
            if (array_key_exists($control, $mapa) && $mapa[$control] === false) {
                abort(422, "Falta un control obligatorio para este tipo de paquete: {$control}.");
            }
        }
    }

    /**
     * Pone en cola el envío del soporte al repositorio del cliente.
     *
     * Solo si ese cliente tiene integración. Quien no la tiene no debe pagar
     * el coste —ni el riesgo— de una llamada a un tercero que no existe.
     *
     * Encola, nunca ejecuta: el destino es un servidor ajeno y el mensajero
     * está esperando en la puerta de una farmacia a que la pantalla le diga
     * que terminó.
     *
     * Y va envuelto, como el aviso por correo: que no se pueda encolar es un
     * problema nuestro de infraestructura, no motivo para tumbar una entrega
     * que ya ocurrió y cuyo soporte ya está guardado aquí.
     */
    private function enviarSoporteSiProcede(Paquete $paquete, ?string $fotoId): void
    {
        if (! $fotoId) {
            return;
        }

        try {
            $paquete->loadMissing('orden.cliente');

            if (! $paquete->orden?->cliente?->integracion_activa) {
                return;
            }

            \App\Jobs\EnviarSoporteACliente::dispatch($fotoId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo encolar el envío del soporte al cliente', [
                'paquete' => $paquete->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rechaza una acción que el estado actual no admite.
     *
     * No basta con la máquina de estados: el registrador, al recibir un estado
     * que no encaja, lo guarda como HISTÓRICO y no mueve el paquete. Eso es
     * correcto para un evento que llega tarde desde un teléfono que estuvo sin
     * señal —nada se descarta—, pero es desastroso cuando el mensajero está
     * actuando ahora mismo: el servidor contesta 201, la pantalla dice que todo
     * fue bien, y el estado no se movió. Pasó: un paquete con novedad recibió
     * dos recolecciones en dos minutos porque nadie le dijo al mensajero que la
     * primera no había servido de nada.
     *
     * La distinción es la hora del dispositivo. Si el evento ocurrió ANTES del
     * último movimiento del paquete, viene con retraso y se acepta como
     * histórico. Si ocurrió después, el mensajero está trabajando sobre
     * información vieja y hay que decírselo, no dejarle creer que registró algo.
     *
     * Se responde 409 y no 422: el dato es válido, lo que no encaja es el
     * momento. Y 409 no se reintenta en el dispositivo, que es lo que evita el
     * bucle.
     */
    private function verificarTransicion(Paquete $paquete, EstadoPaquete $destino, string $ocurridoEn): void
    {
        if ($paquete->estado === $destino || $paquete->estado->puedePasarA($destino)) {
            return;
        }

        $ultimo = $paquete->eventos()->orderByDesc('secuencia')->value('ocurrido_en_dispositivo');
        $tarde  = $ultimo !== null
            && $this->momentoDelDispositivo($ocurridoEn)->lt($ultimo);

        if ($tarde) {
            return;
        }

        abort(409, trim(
            "Este paquete está en «{$paquete->estado->etiqueta()}» y esa acción ya no corresponde. "
            .($paquete->estado->notaDeCampo() ?? '')
        ));
    }

    /** @return array<string, mixed>|null */
    private function sede($sede): ?array
    {
        return $sede === null ? null : [
            'nombre'    => $sede->nombre,
            'direccion' => $sede->direccionCompleta(),
            'latitud'   => $sede->latitud,
            'longitud'  => $sede->longitud,
            'contacto'  => $sede->contacto_nombre,
            'telefono'  => $sede->contacto_telefono,
        ];
    }

    /**
     * Convierte la marca de tiempo que envía el teléfono a la zona del sistema.
     *
     * El teléfono manda ISO-8601 en UTC. Guardarla tal cual dejaba la columna
     * `ocurrido_en_dispositivo` en UTC mientras `registrado_en` iba en hora de
     * Bogotá: dos zonas horarias conviviendo en la misma tabla, y un
     * seguimiento que le mostraba al cliente su entrega cinco horas más tarde
     * de lo que ocurrió.
     *
     * El instante no cambia —es el mismo momento físico—, solo se expresa en la
     * zona en la que el resto del sistema lee, compara y presenta.
     */
    private function momentoDelDispositivo(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso)->setTimezone(config('app.timezone'));
    }
}
