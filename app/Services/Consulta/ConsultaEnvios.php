<?php

namespace App\Services\Consulta;

use App\Models\Orden;
use App\Models\ReferenciaCliente;
use Illuminate\Support\Collection;

/**
 * Búsqueda de envíos desde el punto de vista del cliente.
 *
 * El cliente tiene dos llaves y las usa indistintamente:
 *
 *   · La GUÍA AVALIA — la llave de la recogida y el envío, que le devolvemos al
 *     radicar y que viaja impresa en la etiqueta.
 *   · SU PROPIA REFERENCIA — el radicado, la orden de compra o la remisión. Es
 *     la que tiene a mano cuando alguien de su equipo pregunta por un envío,
 *     porque es la que vive en su sistema, no en el nuestro.
 *
 * Exigirle al cliente que sepa cuál de las dos está tecleando es trasladarle un
 * problema nuestro. `resolver()` acepta cualquiera de las dos y decide sola.
 */
class ConsultaEnvios
{
    /** Relaciones que todo resultado de consulta necesita cargadas. */
    private const RELACIONES = [
        'cliente',
        'origen',
        'destino',
        'referencias',
        'paquetes.entrega',
        'paquetes.mensajero.user',
        'paquetes.temperaturas',
        'paquetes.novedades',
    ];

    public function porGuia(string $guia, ?int $clienteId = null): ?Orden
    {
        return Orden::query()
            ->with(self::RELACIONES)
            ->porGuia($guia)
            ->delCliente($clienteId)
            ->first();
    }

    /**
     * Una referencia puede repetirse legítimamente entre clientes distintos
     * (dos hospitales con el radicado «1234»), por eso devuelve una colección y
     * el alcance por cliente es obligatorio en la API pública.
     *
     * @return Collection<int, Orden>
     */
    public function porReferencia(string $valor, ?string $tipo = null, ?int $clienteId = null): Collection
    {
        return Orden::query()
            ->with(self::RELACIONES)
            ->porReferencia($valor, $tipo, $clienteId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    /**
     * Una sola caja de búsqueda. Prueba primero como guía —es la llave unívoca—
     * y cae a referencia si no encuentra nada.
     *
     * @return array{tipo: string, resultados: Collection<int, Orden>}
     */
    public function resolver(string $clave, ?int $clienteId = null): array
    {
        $clave = trim($clave);

        if ($clave === '') {
            return ['tipo' => 'vacio', 'resultados' => collect()];
        }

        if ($orden = $this->porGuia($clave, $clienteId)) {
            return ['tipo' => 'guia', 'resultados' => collect([$orden])];
        }

        $porReferencia = $this->porReferencia($clave, null, $clienteId);

        if ($porReferencia->isNotEmpty()) {
            return ['tipo' => 'referencia', 'resultados' => $porReferencia];
        }

        // Último intento: el código de la etiqueta física de un paquete. Quien
        // tiene la caja en la mano lee ese código, no la guía.
        $porPaquete = Orden::query()
            ->with(self::RELACIONES)
            ->whereHas('paquetes', fn ($q) => $q->where('codigo', strtoupper($clave)))
            ->delCliente($clienteId)
            ->get();

        return [
            'tipo'       => $porPaquete->isNotEmpty() ? 'paquete' : 'sin_resultado',
            'resultados' => $porPaquete,
        ];
    }

    /**
     * Consulta en lote. Un hospital que radicó 180 envíos en el mes no va a
     * consultarlos de a uno: manda su lista de radicados y recibe el estado de
     * todos, incluidos los que no encontramos —que son justamente los que le
     * interesan—.
     *
     * @param  array<int, string>  $claves
     * @return array{encontrados: array<string, Orden>, sin_resultado: array<int, string>, total: int}
     */
    public function lote(array $claves, ?int $clienteId = null): array
    {
        $maximo = (int) config('ltp.integraciones.max_consulta_lote', 200);
        $claves = array_values(array_unique(array_filter(array_map('trim', $claves))));
        $claves = array_slice($claves, 0, $maximo);

        $encontrados  = [];
        $sinResultado = [];

        // Una sola consulta por guías y una sola por referencias: no N consultas.
        $guias = Orden::query()
            ->with(self::RELACIONES)
            ->whereIn('numero_guia', array_map(Orden::normalizarGuia(...), $claves))
            ->delCliente($clienteId)
            ->get()
            ->keyBy('numero_guia');

        $normalizadas = array_map(ReferenciaCliente::normalizar(...), $claves);

        $referencias = ReferenciaCliente::query()
            ->whereIn('valor_normalizado', $normalizadas)
            ->when($clienteId, fn ($q) => $q->where('cliente_id', $clienteId))
            ->with(['orden' => fn ($q) => $q->with(self::RELACIONES)])
            ->get()
            ->groupBy('valor_normalizado');

        foreach ($claves as $clave) {
            $guia = Orden::normalizarGuia($clave);

            if (isset($guias[$guia])) {
                $encontrados[$clave] = $guias[$guia];

                continue;
            }

            $grupo = $referencias[ReferenciaCliente::normalizar($clave)] ?? null;

            if ($grupo && $grupo->first()?->orden) {
                $encontrados[$clave] = $grupo->first()->orden;

                continue;
            }

            $sinResultado[] = $clave;
        }

        return [
            'encontrados'   => $encontrados,
            'sin_resultado' => $sinResultado,
            'total'         => count($claves),
        ];
    }
}
