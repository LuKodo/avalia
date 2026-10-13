<?php

namespace App\Services\Trazabilidad;

use App\Enums\EstadoPaquete;
use App\Exceptions\TransicionInvalidaException;
use App\Mail\CambioDeEstado;
use App\Models\EventoPaquete;
use App\Models\Paquete;
use App\Models\User;
use App\Services\Integraciones\DespachadorWebhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Único punto por el que entra un evento a la bitácora.
 *
 * Nada en el sistema escribe en `eventos_paquete` directamente. Ese es el
 * invariante que hace que la cadena de hash sirva para algo: si hubiera dos
 * caminos de escritura, uno de ellos terminaría olvidando el encadenamiento.
 */
class RegistradorEventos
{
    /**
     * Registra un evento y, si corresponde, avanza el estado del paquete.
     *
     * @param  array{
     *     tipo?: string,
     *     actor?: User|null,
     *     actor_tipo?: string,
     *     id?: string,
     *     ocurrido_en?: \DateTimeInterface|null,
     *     lat?: float|null,
     *     lon?: float|null,
     *     precision_m?: int|null,
     *     datos?: array<string, mixed>,
     *     evidencia_id?: string|null,
     *     ip?: string|null,
     *     dispositivo?: string|null,
     *     forzar_historico?: bool
     * }  $opciones
     *
     * @throws TransicionInvalidaException
     */
    public function registrar(Paquete $paquete, EstadoPaquete $nuevo, array $opciones = []): EventoPaquete
    {
        $tipo   = $opciones['tipo'] ?? 'transicion';
        $actor  = $opciones['actor'] ?? null;
        $actual = $paquete->estado;

        // Lo que decide si el estado avanza es que el evento PROPONGA un estado
        // distinto, no la etiqueta del tipo de evento. Atarlo a `tipo` hacía que
        // reportar una novedad dejara el paquete en su estado anterior: el
        // registro decía «con novedad» y el paquete decía «en sitio».
        $cambiaEstado = $nuevo !== $actual;
        $valida       = $actual->puedePasarA($nuevo);

        // Evento llegado tarde desde un dispositivo sin señal: propone un estado
        // que la máquina ya no admite y que además quedó atrás. Se guarda como
        // histórico —nada se descarta en silencio— pero no retrocede el estado.
        //
        // El rango NO se usa para decidir por sí solo: una transición legítima
        // hacia atrás en el flujo, como reprogramado → asignado, es válida y
        // debe avanzar aunque su rango sea menor.
        $esHistorico = ($opciones['forzar_historico'] ?? false)
            || ($cambiaEstado && ! $valida && $nuevo->rango() <= $actual->rango());

        if ($cambiaEstado && ! $esHistorico && ! $valida) {
            throw new TransicionInvalidaException(
                "El paquete {$paquete->codigo} no puede pasar de «{$actual->etiqueta()}» a «{$nuevo->etiqueta()}»."
            );
        }

        $evento = DB::transaction(function () use ($paquete, $nuevo, $actual, $tipo, $actor, $opciones, $esHistorico, $cambiaEstado) {
            // Bloqueo de la cadena. Serializa las inserciones: con ~0,2 eventos
            // por segundo el costo es irrelevante frente a tener una sola cadena
            // global, mucho más difícil de manipular sin que se note.
            $cadena = DB::table('cadena_estado')->lockForUpdate()->first();

            $ahora = now();

            $evento = new EventoPaquete([
                'id'                      => $opciones['id'] ?? (string) Str::uuid7(),
                'secuencia'               => $cadena->ultima_secuencia + 1,
                'paquete_id'              => $paquete->id,
                'estado_anterior'         => $actual,
                'estado_nuevo'            => $nuevo,
                'tipo_evento'             => $tipo,
                'actor_tipo'              => $opciones['actor_tipo'] ?? ($actor ? 'usuario' : 'sistema'),
                'actor_id'                => $actor?->id,
                // El nombre se congela: el acta debe decir quién hizo la entrega
                // ese día, no cómo se llama esa persona en el sistema hoy.
                'actor_nombre'            => $actor?->nombre,
                'ocurrido_en_dispositivo' => $opciones['ocurrido_en'] ?? null,
                'registrado_en'           => $ahora,
                'desfase_seg'             => $this->desfase($opciones['ocurrido_en'] ?? null, $ahora),
                'latitud'                 => $opciones['lat'] ?? null,
                'longitud'                => $opciones['lon'] ?? null,
                'precision_m'             => $opciones['precision_m'] ?? null,
                'datos'                   => $this->datosConContexto($opciones, $esHistorico),
                'evidencia_id'            => $opciones['evidencia_id'] ?? null,
                'hash_anterior'           => $cadena->ultimo_hash,
                'ip'                      => $opciones['ip'] ?? request()?->ip(),
                'dispositivo'             => $opciones['dispositivo'] ?? null,
            ]);

            // El hash se calcula sobre la carga ya completa, incluidos secuencia
            // y hash anterior. Cambiar `cargaCanonica()` invalida toda la cadena,
            // por eso ese método es intocable una vez hay datos en producción.
            $evento->hash = $evento->hashEsperado();
            $evento->save();

            DB::table('cadena_estado')->where('id', $cadena->id)->update([
                'ultima_secuencia' => $evento->secuencia,
                'ultimo_hash'      => $evento->hash,
                'actualizado_en'   => $ahora,
            ]);

            if ($cambiaEstado && ! $esHistorico) {
                $this->avanzarPaquete($paquete, $nuevo, $ahora);
            }

            return $evento;
        });

        /*
         * La notificación al cliente se encola DESPUÉS de cerrar la transacción,
         * nunca dentro.
         *
         * Dentro, el trabajo podría salir a la cola y ser recogido por un
         * trabajador antes de que la transacción confirme: leería una fila que
         * todavía no existe. Y si la transacción acabara revirtiendo, habríamos
         * avisado de un evento que nunca ocurrió.
         *
         * Solo se avisa de cambios de estado reales. Las anotaciones y las
         * correcciones históricas no son noticia para el sistema del cliente.
         */
        if ($cambiaEstado && ! $esHistorico) {
            app(DespachadorWebhooks::class)->porEvento($evento);
            $this->avisarPorCorreo($paquete, $evento);
        }

        return $evento;
    }

    /**
     * Avisa al cliente por correo del cambio de estado.
     *
     * Un fallo aquí NO puede tumbar la operación. El evento ya está grabado y
     * la cadena de la bitácora cerrada; que el servidor de correo no conteste
     * es un problema del correo, no del envío. Se registra y se sigue: un
     * mensajero que acaba de firmar una entrega en la calle no puede quedarse
     * esperando a un SMTP.
     *
     * El destinatario es la dirección de notificaciones del cliente. Si no la
     * tiene configurada, no hay a quién escribir y no se intenta.
     */
    private function avisarPorCorreo(Paquete $paquete, EventoPaquete $evento): void
    {
        try {
            $destino = $paquete->orden?->cliente?->email_notificaciones;

            if (! $destino) {
                return;
            }

            $paquete->loadMissing('orden.cliente', 'orden.origen', 'orden.destino', 'orden.referencias');

            Mail::to($destino)->queue(new CambioDeEstado($paquete, $evento));
        } catch (\Throwable $e) {
            Log::warning('No se pudo encolar el aviso de cambio de estado', [
                'paquete' => $paquete->codigo,
                'estado'  => $paquete->estado->value,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Desfase entre el reloj del dispositivo y el del servidor. Un valor
     * anómalo levanta alerta: el reloj de un teléfono se puede cambiar, y para
     * efectos legales la marca válida es siempre la del servidor.
     */
    private function desfase(?\DateTimeInterface $dispositivo, \DateTimeInterface $servidor): int
    {
        return $dispositivo === null
            ? 0
            : $dispositivo->getTimestamp() - $servidor->getTimestamp();
    }

    /** @param array<string, mixed> $opciones */
    private function datosConContexto(array $opciones, bool $esHistorico): array
    {
        $datos = $opciones['datos'] ?? [];

        if ($esHistorico) {
            $datos['_historico'] = true;
            $datos['_motivo']    = 'evento recibido fuera de orden; el estado no retrocede';
        }

        return $datos;
    }

    private function avanzarPaquete(Paquete $paquete, EstadoPaquete $nuevo, \DateTimeInterface $ahora): void
    {
        $paquete->estado = $nuevo;

        if ($nuevo === EstadoPaquete::Recolectado && $paquete->recolectado_en === null) {
            $paquete->recolectado_en = $ahora;
        }

        if ($nuevo === EstadoPaquete::Entregado && $paquete->entregado_en === null) {
            $paquete->entregado_en = $ahora;
        }

        $paquete->save();

        $this->sincronizarTramo($paquete, $nuevo, $ahora);
    }

    /**
     * Mantiene la cadena de tramos al dia cuando el estado se mueve por fuera.
     *
     * Hace falta porque hay DOS caminos que avanzan un paquete: el nuevo, por
     * tramos, y el que usa hoy la aplicacion de campo -`recolectar` y
     * `entregar` sobre el paquete-. Sin esto los dos divergen: el paquete
     * quedaria entregado y su tramo seguiria abierto para siempre, con el
     * mensajero viendo en su lista una tarea que ya hizo.
     *
     * Marca el tramo como cerrado SIN exigirle la prueba, y deja constancia de
     * por donde se cerro. Es deliberado y hay que saberlo: por esta via NO se
     * captura la firma de la recoleccion. Mientras la aplicacion de campo no
     * cierre tramos directamente, ese comprobante solo existe si el cierre pasa
     * por `POST /campo/tramos/{t}/cerrar`.
     */
    private function sincronizarTramo(Paquete $paquete, EstadoPaquete $nuevo, \DateTimeInterface $ahora): void
    {
        $cierra = match ($nuevo) {
            EstadoPaquete::Recolectado, EstadoPaquete::Entregado,
            EstadoPaquete::Devuelto, EstadoPaquete::Siniestro => true,
            default => false,
        };

        if (! $cierra) {
            return;
        }

        $tramo = $paquete->tramos()->abiertos()->orderBy('orden')->first();

        if ($tramo === null) {
            return;
        }

        // Recolectado cierra el tramo solo si ese tramo ERA la recogida. Si el
        // envio va directo, recolectar no termina el trayecto: la carga sigue
        // en camino al destino final.
        if ($nuevo === EstadoPaquete::Recolectado && $tramo->tipo->cierraElEnvio()) {
            $tramo->forceFill(['estado' => \App\Models\Tramo::EN_CURSO, 'iniciado_en' => $tramo->iniciado_en ?? $ahora])->save();

            return;
        }

        $tramo->forceFill([
            'estado'        => \App\Models\Tramo::CERRADO,
            'cerrado_en'    => $ahora,
            'vehiculo_id'   => $tramo->vehiculo_id ?? $tramo->mensajero?->vehiculo_id,
            'observaciones' => trim(($tramo->observaciones ?? '')."
Cerrado desde la aplicacion de campo (sin prueba de tramo)."),
        ])->save();
    }

    /** Evento que no es una transición: lectura de temperatura, acceso, nota. */
    public function registrarAnotacion(Paquete $paquete, string $tipo, array $datos, array $opciones = []): EventoPaquete
    {
        return $this->registrar($paquete, $paquete->estado, array_merge($opciones, [
            'tipo'  => $tipo,
            'datos' => $datos,
        ]));
    }
}
