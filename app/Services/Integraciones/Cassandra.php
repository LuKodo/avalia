<?php

namespace App\Services\Integraciones;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Subida de archivos a Cassandra, el repositorio de un cliente.
 *
 * API en Go sobre buckets de Oracle Cloud. De toda su superficie aquí se usa
 * una sola operación —subir— y la clase no sabe nada más: ni de entregas, ni de
 * evidencias, ni de paquetes. Recibe un archivo y un nombre, y devuelve lo que
 * contestó el servidor. Todo lo que signifique algo en el negocio lo decide
 * quien la llama.
 *
 * Esa frontera es deliberada. Una clase que supiera de entregas y de HTTP a la
 * vez solo podría probarse con las dos cosas montadas, y la que acabaría
 * sufriendo es la parte de negocio.
 */
class Cassandra
{
    public function __construct(
        private string $urlApi,
        private string $bucket,
        private string $token,
    ) {}

    /**
     * Arma el servicio desde la configuración guardada con el cliente.
     *
     * Devuelve `null` si falta algo en vez de reventar: un cliente a medio
     * configurar es un error de datos que debe verse en pantalla, no una
     * excepción en un trabajo de cola a las tres de la mañana.
     */
    public static function desdeCliente(array $config, ?string $token): ?self
    {
        $url = trim((string) ($config['api_url'] ?? ''));
        $bucket = trim((string) ($config['bucket'] ?? ''));

        if ($url === '' || $bucket === '' || ! $token) {
            return null;
        }

        return new self(rtrim($url, '/'), $bucket, $token);
    }

    /**
     * Sube el contenido con el nombre indicado.
     *
     * @param  string  $contenido  bytes del archivo, ya leídos
     * @param  array<string, string>  $campos  casillas adicionales del formulario
     * @return array{ok: bool, estado: int, cuerpo: mixed, reintentable: bool, mensaje: ?string}
     *
     * No lanza excepciones por un fallo del otro lado: devuelve el resultado
     * descrito, incluido si merece la pena reintentar. El llamador necesita
     * esa distinción para decidir, y envolverla en excepciones obligaría a
     * clasificarlas de nuevo ahí.
     *
     * Sobre `$campos`: la especificación publicada de Cassandra declara un
     * único campo, `file`. Las casillas extra se mandan igual —en un
     * `multipart` sobran sin romper nada— porque el acuerdo con el cliente
     * incluye el centro de costo y su documentación va por detrás de lo que
     * su API acepta. Si resultara que no la lee, el dato se pierde en
     * silencio: conviene confirmarlo con ellos y no darlo por hecho.
     */
    public function subir(string $contenido, string $nombre, string $mime = 'image/jpeg', array $campos = []): array
    {
        try {
            $peticion = Http::withToken($this->token)
                ->timeout(30)
                ->connectTimeout(10)
                ->attach('file', $contenido, $nombre, ['Content-Type' => $mime]);

            $respuesta = $peticion->post(
                "{$this->urlApi}/buckets/{$this->bucket}/files/upload",
                array_filter($campos, fn ($v) => $v !== null && $v !== '')
            );
        } catch (ConnectionException $e) {
            /*
             * No llegó a haber respuesta: red caída, DNS, tiempo agotado. Es el
             * caso más reintentable que existe y hay que distinguirlo de un
             * rechazo, porque un rechazo no mejora esperando.
             */
            return [
                'ok' => false,
                'estado' => 0,
                'cuerpo' => null,
                'reintentable' => true,
                'mensaje' => 'Sin conexión con el repositorio del cliente: '.$e->getMessage(),
            ];
        }

        return $respuesta->successful()
            ? $this->exito($respuesta)
            : $this->fallo($respuesta);
    }

    private function exito(Response $r): array
    {
        return [
            'ok' => true,
            'estado' => $r->status(),
            'cuerpo' => $r->json() ?? $r->body(),
            'reintentable' => false,
            'mensaje' => null,
        ];
    }

    /**
     * Qué merece otro intento y qué no.
     *
     * Se reintenta lo que puede mejorar con el tiempo: un 5xx es un servidor
     * con un mal rato, un 429 es él pidiendo que bajemos el ritmo. No se
     * reintenta lo que no: un 401 con la credencial equivocada seguirá siendo
     * 401 dentro de cuatro horas, y un 400 por formato rechazado también.
     * Insistir sobre esos dos solo llena la cola y retrasa lo que sí podría
     * salir.
     *
     * Es la misma distinción que aplica la cola del dispositivo móvil en
     * `resources/js/api/cliente.js`, y conviene que siga siendo la misma: dos
     * criterios distintos para el mismo problema es una fuente de sorpresas.
     */
    private function fallo(Response $r): array
    {
        $estado = $r->status();

        return [
            'ok' => false,
            'estado' => $estado,
            'cuerpo' => $r->json() ?? $r->body(),
            'reintentable' => $estado === 429 || $estado >= 500,
            'mensaje' => "El repositorio del cliente respondió {$estado}: ".mb_substr($r->body(), 0, 300),
        ];
    }
}
