<?php

namespace App\Services\Operacion;

use App\Enums\EstadoPaquete;
use App\Enums\TipoTramo;
use App\Models\Paquete;
use App\Models\Sede;
use App\Models\Tramo;
use App\Services\Trazabilidad\RegistradorEventos;
use Illuminate\Support\Facades\DB;

/**
 * Arma y hace avanzar la cadena de trayectos de un paquete.
 *
 * Dos formas de mover un envío, y la diferencia la decide el operador, no el
 * sistema:
 *
 *   · DIRECTO        un tramo de entrega: origen → destino final.
 *   · CON TRASBORDO  dos: origen → centro de Avalia, y después centro →
 *                    destino. El segundo puede programarse para otro día y
 *                    llevarlo otro mensajero en otro vehículo.
 *
 * Quién manda sobre el estado: el TRAMO dice quién tiene el paquete ahora; el
 * PAQUETE dice qué contarle al cliente. Cerrar un tramo empuja el estado del
 * paquete, nunca al revés — si se dejara al revés, un operador corrigiendo un
 * estado a mano descuadraría la custodia.
 */
class CadenaDeTramos
{
    public function __construct(private readonly RegistradorEventos $registrador)
    {
    }

    /**
     * Crea la cadena inicial de un paquete recién radicado.
     *
     * Si no se indica centro de trasbordo, es un envío directo. Devolver la
     * cadena completa —y no solo el primer tramo— permite al operador ver de
     * entrada cuántas manos va a pasar.
     *
     * @return array<int, Tramo>
     */
    public function armar(Paquete $paquete, ?Sede $centro = null): array
    {
        if ($paquete->tramos()->exists()) {
            return $paquete->tramos()->orderBy('orden')->get()->all();
        }

        $orden   = $paquete->orden;
        $origen  = $orden->origen;
        $destino = $orden->puntoDestino();

        if ($origen === null || $destino === null) {
            return [];
        }

        /*
         * Un trasbordo hacia el mismo sitio donde ya está la carga no es un
         * trayecto: es un tramo de longitud cero que el mensajero tendría que
         * cerrar firmando que se entregó la mercancía a sí mismo.
         *
         * Pasa de verdad cuando el cliente radica desde una bodega nuestra, o
         * cuando el operador elige por descuido el mismo centro. Se degrada a
         * envío directo en silencio, que es lo que el operador quería decir.
         */
        if ($centro !== null && $origen instanceof Sede && $origen->id === $centro->id) {
            $centro = null;
        }

        // Lo mismo por el otro lado: si el destino final YA es el centro, el
        // segundo tramo no llevaría el paquete a ninguna parte.
        if ($centro !== null && $destino instanceof Sede && $destino->id === $centro->id) {
            $centro = null;
        }

        return DB::transaction(function () use ($paquete, $origen, $destino, $centro) {
            $tramos = [];

            if ($centro === null) {
                // Directo: un solo trayecto, y es de entrega.
                $tramos[] = $this->crear($paquete, 1, TipoTramo::Entrega, $origen, $destino);

                return $tramos;
            }

            // Con trasbordo. El primero es de RECOLECCIÓN —firma quien nos
            // entrega—, el segundo de ENTREGA. El segundo nace sin mensajero:
            // se asigna cuando la mercancía ya esté en el centro, que es
            // cuando se sabe con qué vehículo sale.
            $tramos[] = $this->crear($paquete, 1, TipoTramo::Recoleccion, $origen, $centro);
            $tramos[] = $this->crear($paquete, 2, TipoTramo::Entrega, $centro, $destino);

            return $tramos;
        });
    }

    /**
     * Inserta un trasbordo en un envío que nació directo.
     *
     * Pasa de verdad: se radica directo y a media mañana se decide consolidar
     * en el centro. Solo se admite mientras el tramo siga abierto — una vez
     * entregado no hay nada que reencaminar.
     */
    public function insertarTrasbordo(Paquete $paquete, Sede $centro): array
    {
        $ultimo = $paquete->tramos()->orderByDesc('orden')->first();

        if ($ultimo === null || ! $ultimo->estaAbierto()) {
            throw new \RuntimeException('Este envio ya no tiene tramos abiertos: no hay nada que reencaminar.');
        }

        /*
         * La misma salvaguarda que en `armar`, que aquí faltaba.
         *
         * Si la carga ya sale de ese centro, o ya va hacia él, meter el
         * trasbordo produce un trayecto de longitud cero: el mensajero tendría
         * que cerrarlo firmando que se entregó la mercancía a sí mismo.
         */
        $mismoOrigen  = $ultimo->origen_tipo === 'sede' && $ultimo->origen_id === $centro->id;
        $mismoDestino = $ultimo->destino_tipo === 'sede' && $ultimo->destino_id === $centro->id;

        if ($mismoOrigen || $mismoDestino) {
            throw new \RuntimeException(
                'La carga ya '.($mismoOrigen ? 'sale de' : 'va hacia').' ese centro: el trasbordo no la movería a ninguna parte.'
            );
        }

        return DB::transaction(function () use ($paquete, $centro, $ultimo) {
            $destinoFinal = ['tipo' => $ultimo->destino_tipo, 'id' => $ultimo->destino_id];

            // El tramo abierto pasa a terminar en el centro, y cambia de
            // naturaleza: ya no entrega al cliente, ahora nos trae la carga.
            $ultimo->update([
                'destino_tipo' => 'sede',
                'destino_id'   => $centro->id,
                'tipo'         => TipoTramo::Recoleccion,
            ]);

            $nuevo = Tramo::create([
                'paquete_id'   => $paquete->id,
                'orden'        => $ultimo->orden + 1,
                'tipo'         => TipoTramo::Entrega,
                'origen_tipo'  => 'sede',
                'origen_id'    => $centro->id,
                'destino_tipo' => $destinoFinal['tipo'],
                'destino_id'   => $destinoFinal['id'],
                'estado'       => Tramo::PENDIENTE,
            ]);

            return [$ultimo->fresh(), $nuevo];
        });
    }

    /**
     * Pone un mensajero al frente de un tramo.
     *
     * Es la pieza que une armar con cerrar: sin ella el paquete se queda en
     * «creado» y la máquina de estados —con razón— no deja recogerlo, porque
     * nadie se había hecho responsable.
     *
     * Escribe además `paquetes.mensajero_id`. Esa columna es la que miran hoy
     * el despacho, la aplicación de campo y el mapa; mientras no se migren
     * todos a leer tramos, tiene que seguir apuntando a quien lleva el paquete
     * AHORA. Dejarla desfasada haría que el mapa mostrara al mensajero
     * equivocado en el segundo tramo.
     *
     * @param  \DateTimeInterface|null  $desde  Cuándo puede empezar. Permite
     *         programar el segundo tramo para otro día.
     */
    public function asignar(Tramo $tramo, $mensajero, $desde = null, $hasta = null, $actor = null): Tramo
    {
        return DB::transaction(function () use ($tramo, $mensajero, $desde, $hasta, $actor) {
            $tramo->update([
                'mensajero_id'     => $mensajero->id,
                'estado'           => Tramo::ASIGNADO,
                'programado_desde' => $desde ?? $tramo->programado_desde,
                'programado_hasta' => $hasta ?? $tramo->programado_hasta,
            ]);

            $paquete = $tramo->paquete;

            // Solo el tramo que está en curso manda sobre el paquete. Asignar
            // el segundo por adelantado no debe robarle la custodia al primero,
            // que todavía lleva la mercancía encima.
            if ($paquete->tramoActual()?->id === $tramo->id) {
                $paquete->forceFill(['mensajero_id' => $mensajero->id])->save();

                if ($paquete->estado === EstadoPaquete::Creado) {
                    $this->registrador->registrar($paquete, EstadoPaquete::Asignado, [
                        'actor' => $actor,
                        'datos' => ['tramo_id' => $tramo->id, 'tramo_orden' => $tramo->orden],
                    ]);
                }
            }

            return $tramo->fresh();
        });
    }

    /**
     * Cierra un tramo y empuja el estado del paquete.
     *
     * @param  array{firma_id?: ?string, foto_id?: ?string, contraparte_nombre?: ?string,
     *               contraparte_documento?: ?string, latitud?: ?float, longitud?: ?float,
     *               observaciones?: ?string, ocurrido_en?: \DateTimeInterface}  $prueba
     *
     * @throws \RuntimeException  Si falta un control obligatorio del tipo de tramo.
     */
    public function cerrar(Tramo $tramo, array $prueba, $actor = null): Tramo
    {
        $tramo->fill(array_filter([
            'firma_id'              => $prueba['firma_id'] ?? null,
            'foto_id'               => $prueba['foto_id'] ?? null,
            'contraparte_nombre'    => $prueba['contraparte_nombre'] ?? null,
            'contraparte_documento' => $prueba['contraparte_documento'] ?? null,
            'cierre_latitud'        => $prueba['latitud'] ?? null,
            'cierre_longitud'       => $prueba['longitud'] ?? null,
            'observaciones'         => $prueba['observaciones'] ?? null,
        ], fn ($v) => $v !== null));

        $faltan = $tramo->controlesPendientes();

        if ($faltan !== []) {
            throw new \RuntimeException(
                'Faltan controles para cerrar este tramo: '.implode(', ', $faltan).'.'
            );
        }

        return DB::transaction(function () use ($tramo, $prueba, $actor) {
            $tramo->estado = Tramo::CERRADO;
            $tramo->cerrado_en = now();

            // El vehículo se congela aquí: el acta debe decir en qué vehículo
            // viajó ese día, no en cuál anda el mensajero hoy.
            $tramo->vehiculo_id ??= $tramo->mensajero?->vehiculo_id;
            $tramo->save();

            $siguiente = $tramo->paquete->tramos()
                ->where('orden', '>', $tramo->orden)
                ->orderBy('orden')
                ->first();

            /*
             * El estado del paquete sale de DÓNDE quedó la cadena:
             *
             *   · Queda tramo por delante → el paquete está recolectado y bajo
             *     nuestra custodia, no entregado. Decirle «entregado» al
             *     cliente cuando apenas llegó a nuestra bodega sería mentirle.
             *   · Era el último y es de entrega → entregado.
             */
            $nuevoEstado = $siguiente !== null
                ? EstadoPaquete::Recolectado
                : ($tramo->tipo->cierraElEnvio() ? EstadoPaquete::Entregado : EstadoPaquete::Recolectado);

            if ($tramo->paquete->estado !== $nuevoEstado) {
                $this->registrador->registrar($tramo->paquete, $nuevoEstado, [
                    'actor'       => $actor,
                    'ocurrido_en' => $prueba['ocurrido_en'] ?? null,
                    'lat'         => $prueba['latitud'] ?? null,
                    'lon'         => $prueba['longitud'] ?? null,
                    'datos'       => [
                        'tramo_id'    => $tramo->id,
                        'tramo_orden' => $tramo->orden,
                        'tramo_tipo'  => $tramo->tipo->value,
                    ],
                ]);
            }

            // El siguiente queda listo para que el operador lo programe.
            if ($siguiente !== null && $siguiente->estado === Tramo::PENDIENTE) {
                $siguiente->touch();
            }

            return $tramo->fresh();
        });
    }

    private function crear(Paquete $paquete, int $orden, TipoTramo $tipo, $origen, $destino): Tramo
    {
        return Tramo::create([
            'paquete_id'   => $paquete->id,
            'orden'        => $orden,
            'tipo'         => $tipo,
            'origen_tipo'  => $origen instanceof Sede ? 'sede' : 'destinatario',
            'origen_id'    => $origen->id,
            'destino_tipo' => $destino instanceof Sede ? 'sede' : 'destinatario',
            'destino_id'   => $destino->id,
            // El primero hereda el mensajero que ya tuviera el paquete, para no
            // romper lo que hoy asigna el despacho.
            'mensajero_id' => $orden === 1 ? $paquete->mensajero_id : null,
            'estado'       => $orden === 1 && $paquete->mensajero_id !== null
                ? Tramo::ASIGNADO
                : Tramo::PENDIENTE,
            'programado_desde' => $orden === 1 ? $paquete->orden->ventana_desde : null,
            'programado_hasta' => $orden === 1 ? $paquete->orden->ventana_hasta : null,
        ]);
    }
}
