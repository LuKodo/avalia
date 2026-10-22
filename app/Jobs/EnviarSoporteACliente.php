<?php

namespace App\Jobs;

use App\Models\Evidencia;
use App\Services\Integraciones\Cassandra;
use App\Services\Trazabilidad\RegistradorEventos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lleva la foto del soporte de entrega al repositorio del cliente.
 *
 * Va en cola y no dentro de la petición que cierra la entrega por una razón
 * concreta: el destino es un servidor ajeno que puede tardar, no responder o
 * estar caído, y el mensajero está de pie en la puerta de una farmacia
 * esperando a que la pantalla le diga que terminó. Convertir la lentitud de un
 * tercero en lentitud nuestra es inaceptable ahí.
 *
 * Y si la integración falla del todo, la entrega sigue siendo válida: el
 * soporte vive en Avalia y eso es lo que vale como prueba. Esto es una
 * conveniencia para el cliente, no parte de la cadena de custodia. Conviene no
 * perderlo de vista el día que alguien proponga bloquear entregas porque el
 * repositorio del cliente no contesta.
 */
class EnviarSoporteACliente implements ShouldQueue
{
    use Queueable;

    /**
     * Seis intentos repartidos en algo más de cinco horas.
     *
     * Un sistema caído no se arregla porque insistamos cada treinta segundos, y
     * la insistencia corta sí puede agravar el problema del otro lado. La
     * espera crece hasta las cuatro horas, que es tiempo de sobra para que
     * alguien se dé cuenta y lo levante.
     */
    public int $tries = 6;

    public int $timeout = 60;

    /** @return array<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function __construct(public string $evidenciaId) {}

    public function handle(RegistradorEventos $registrador): void
    {
        $evidencia = Evidencia::with([
            'paquete.orden.cliente',
            'paquete.orden.referencias',
            // Las de la caja, que son las que mandan cuando las hay.
            'paquete.referencias',
            // La sede de origen trae el centro de costo que viaja con el envío.
            'paquete.orden.origen',
            // Las cajas hermanas hacen falta para saber si esta es la primera,
            // que es lo que decide si el nombre lleva sufijo.
            'paquete.orden.paquetes',
        ])->find($this->evidenciaId);

        if (! $evidencia || $evidencia->entregada_al_cliente_en !== null) {
            // Borrada, o ya acusada por un intento anterior. Nada que hacer, y
            // no es un error: la cola puede repetir un trabajo legítimamente.
            return;
        }

        $cliente = $evidencia->paquete?->orden?->cliente;

        if (! $cliente?->integracion_activa) {
            return;
        }

        $servicio = Cassandra::desdeCliente($cliente->integracion_config ?? [], $cliente->integracion_token);

        if (! $servicio) {
            /*
             * Decir cuál de las tres falta, no las tres.
             *
             * Esta anotación se le enseña al operador en la pantalla de
             * despacho, y un mensaje que enumera todo lo que PODRÍA estar mal
             * le deja el mismo trabajo que no decirle nada. El punto de esa
             * pantalla es que sepa qué arreglar sin abrir la consola.
             */
            $this->anotarFallo($evidencia, $this->queFaltaEnLaIntegracion($cliente));

            return;
        }

        $nombre = $this->nombreDeArchivo($evidencia);

        if ($nombre === null) {
            $this->anotarFallo($evidencia, 'El envío no tiene referencia del cliente, y la referencia es el nombre del archivo.');

            return;
        }

        $contenido = $this->contenido($evidencia);

        if ($contenido === null) {
            $this->anotarFallo($evidencia, "No se encontró el archivo de la evidencia en el almacenamiento: {$evidencia->ruta}");

            return;
        }

        /*
         * El centro de costo viaja como casilla aparte, no dentro del nombre.
         *
         * Se toma de la sede de ORIGEN: es desde donde el cliente despacha, y
         * por tanto a qué centro imputa el envío en su contabilidad. La de
         * destino identificaría al que recibe, que es otra pregunta.
         */
        $campos = [
            'centrocosto' => $evidencia->paquete->orden->origen?->codigo_centro_costo,
        ];

        $r = $servicio->subir($contenido, $nombre, $evidencia->mime ?: 'image/jpeg', $campos);

        if ($r['ok']) {
            /*
             * No basta con que diga que sí: hay que mirar.
             *
             * Esta API devolvió 201 tres veces seguidas subiendo a un bucket que
             * no existía. Si nos quedáramos en el código de respuesta, el
             * sistema seguiría afirmando que el cliente tiene unos soportes que
             * nunca recibió, y nadie se enteraría hasta que alguien los pidiera.
             */
            $v = $servicio->confirmar($nombre);

            if (! $v['confirmado']) {
                $this->anotarFallo($evidencia, $v['motivo'] ?? 'No se pudo confirmar el archivo en el bucket.');

                Log::warning('Subida aceptada pero no confirmada en el bucket', [
                    'evidencia' => $evidencia->id,
                    'nombre'    => $nombre,
                    'respuesta_subida' => $r['cuerpo'],
                    'motivo'    => $v['motivo'],
                ]);

                /*
                 * No se reintenta. Un bucket inexistente o mal nombrado no se
                 * arregla insistiendo; se arregla cambiando la configuración o
                 * hablando con el cliente. Queda visible en la pantalla de
                 * operación con el motivo, que es donde alguien puede actuar.
                 */
                return;
            }

            /*
             * Se guarda lo que contestó su servidor, no solo que dijo que sí.
             *
             * Un 201 significa «recibido», no «guardado donde usted cree». Ya
             * pasó: la subida respondió 201 y el archivo no apareció del otro
             * lado. Sin el cuerpo de la respuesta no hay forma de saber si
             * devolvió una ruta distinta, un aviso, o nada. Con él se puede
             * preguntar al cliente enseñándole lo que dijo su propia API.
             */
            Log::info('Soporte enviado al repositorio del cliente', [
                'evidencia' => $evidencia->id,
                'nombre'    => $nombre,
                'campos'    => $campos,
                'estado'    => $r['estado'],
                'respuesta' => $r['cuerpo'],
            ]);

            $this->anotarExito($evidencia, $nombre, $registrador, $r['cuerpo']);

            return;
        }

        $this->anotarFallo($evidencia, $r['mensaje'] ?? 'Error desconocido');

        /*
         * Solo se relanza —y por tanto se reintenta— lo que puede mejorar con
         * el tiempo. Un rechazo definitivo se queda anotado en la evidencia,
         * visible en la pantalla de operación, y no vuelve a la cola: seguir
         * intentándolo no arregla una credencial inválida y sí retrasa lo que
         * sí podría salir.
         */
        if ($r['reintentable']) {
            throw new \RuntimeException($r['mensaje'] ?? 'Fallo reintentable al subir el soporte');
        }
    }

    /** @param \App\Models\Cliente $cliente */
    private function queFaltaEnLaIntegracion($cliente): string
    {
        $config = $cliente->integracion_config ?? [];
        $faltan = [];

        if (blank($config['api_url'] ?? null)) { $faltan[] = 'la dirección de la API'; }
        if (blank($config['bucket'] ?? null))  { $faltan[] = 'el bucket'; }
        if (blank($cliente->integracion_token)) { $faltan[] = 'la credencial'; }

        return 'La integración de este cliente está activa pero le falta '
            .implode(' y ', $faltan)
            .'. Se configura en Administración → Clientes → Integración.';
    }

    /**
     * El nombre es la referencia del cliente sin su prefijo.
     *
     *     SSC6564684  ->  6564684.jpg
     *
     * El prefijo se configura por cliente porque es SU convención: el siguiente
     * usará otra o ninguna. Se recorta sin distinguir mayúsculas, y lo que no
     * empiece por el prefijo se deja intacto —alguien puede radicar el número
     * pelado y debe funcionar igual—.
     *
     * El sufijo del paquete merece explicación. En esa API los archivos se
     * direccionan por NOMBRE, no por un identificador que devuelva el servidor:
     * el nombre es la llave. Un envío de varias cajas tiene una sola referencia
     * y varios soportes, así que el nombre limpio solo puede valer para uno. La
     * primera caja lo conserva —que es el caso normal y el que el cliente
     * espera— y de la segunda en adelante se añade el código de paquete. Sin
     * esto, el segundo soporte pisaría al primero sin error visible, que es la
     * peor clase de fallo.
     */
    private function nombreDeArchivo(Evidencia $evidencia): ?string
    {
        $referencias = $evidencia->paquete->referenciasEfectivas();

        if ($referencias->isEmpty()) {
            return null;
        }

        /*
         * Una sola referencia: el nombre es esa referencia, sin su prefijo.
         *
         * Es el caso habitual y el que el cliente espera ver en su repositorio.
         */
        if ($referencias->count() === 1) {
            return $this->sinPrefijo(
                (string) $referencias->first()->valor,
                $evidencia->paquete->orden->cliente
            ).$this->extension($evidencia->mime);
        }

        /*
         * Varias: el nombre es el código de la caja.
         *
         * Una caja puede llevar diez dispensaciones y el recibido es UNO —se
         * firma la caja, no cada documento—. Esa única foto no puede llamarse
         * como las diez a la vez, y elegir una sería mentir sobre las otras
         * nueve. El código del paquete nombra exactamente lo que la foto
         * retrata: esa caja.
         *
         * El vínculo con cada SSC no se pierde: va en el registro del
         * documento, que apunta a esta misma URL tantas veces como
         * dispensaciones lleve la caja.
         */
        return $evidencia->paquete->codigo.$this->extension($evidencia->mime);
    }

    /** Recorta el prefijo que ese cliente usa en sus referencias. */
    private function sinPrefijo(string $referencia, $cliente): string
    {
        $prefijo = (string) ($cliente->integracion_config['prefijo_referencia'] ?? '');
        $base = trim($referencia);

        return $prefijo !== '' && str_starts_with(mb_strtolower($base), mb_strtolower($prefijo))
            ? mb_substr($base, mb_strlen($prefijo))
            : $base;
    }

    private function extension(?string $mime): string
    {
        return match ($mime) {
            'image/png'  => '.png',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            default      => '.jpg',
        };
    }

    private function contenido(Evidencia $evidencia): ?string
    {
        return Storage::exists($evidencia->ruta)
            ? Storage::get($evidencia->ruta)
            : null;
    }

    /**
     * El acuse se pone con la respuesta del cliente en la mano, nunca al
     * encolar. Un trabajo encolado no es un trabajo hecho, y confundir las dos
     * cosas es exactamente cómo se construye un panel que dice que todo salió
     * bien mientras nada salió.
     */
    private function anotarExito(Evidencia $evidencia, string $nombre, RegistradorEventos $registrador, mixed $respuesta = null): void
    {
        $evidencia->forceFill([
            'entregada_al_cliente_en' => now(),
            'referencia_externa' => $nombre,
            'intentos_envio' => $evidencia->intentos_envio + 1,
            'ultimo_error_envio' => null,
        ])->save();

        /*
         * Queda en la bitácora del paquete. Si mañana el cliente dice que no
         * recibió el soporte, la respuesta no puede ser «pues nosotros lo
         * mandamos»: tiene que poder enseñarse cuándo, con qué nombre y qué
         * contestó su servidor.
         */
        try {
            $registrador->registrarAnotacion($evidencia->paquete, 'integracion_cliente', [
                'resultado' => 'entregado',
                'evidencia' => $evidencia->tipo,
                'nombre_externo' => $nombre,
                // Lo que contestó su API, tal cual. Es la única prueba de qué
                // dijo el día que alguien discuta si el archivo llegó.
                'respuesta' => is_array($respuesta) ? $respuesta : (string) $respuesta,
            ]);
        } catch (\Throwable $e) {
            // La anotación es importante pero no puede tumbar un envío que ya
            // llegó. Si falla, el acuse en la evidencia sigue siendo cierto.
            Log::warning('No se pudo anotar el envío del soporte en la bitácora', [
                'evidencia' => $evidencia->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function anotarFallo(Evidencia $evidencia, string $motivo): void
    {
        $evidencia->forceFill([
            'intentos_envio' => $evidencia->intentos_envio + 1,
            'ultimo_error_envio' => mb_substr($motivo, 0, 1000),
        ])->save();

        Log::warning('Fallo al enviar el soporte al cliente', [
            'evidencia' => $evidencia->id,
            'paquete' => $evidencia->paquete_id,
            'motivo' => $motivo,
        ]);
    }
}
