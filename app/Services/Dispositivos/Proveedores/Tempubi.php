<?php

namespace App\Services\Dispositivos\Proveedores;

use App\Services\Dispositivos\AdaptadorDispositivo;
use App\Services\Dispositivos\LecturaNormalizada;

/**
 * Adaptador de Tempubi.
 *
 * ┌───────────────────────────────────────────────────────────────────────────┐
 * │  ATENCIÓN — ESTE MAPEO NO ESTÁ CONFIRMADO CONTRA LA DOCUMENTACIÓN         │
 * │  OFICIAL DE TEMPUBI.                                                      │
 * │                                                                           │
 * │  Los nombres de campo de abajo son una suposición razonable basada en lo  │
 * │  que suelen enviar los dispositivos de esta clase. Antes de conectar un   │
 * │  equipo real hay que:                                                     │
 * │                                                                           │
 * │    1. Pedir a Tempubi la especificación del webhook o de su API.          │
 * │    2. Ajustar `CAMPOS` y `traducir()` a los nombres reales.               │
 * │    3. Confirmar cómo firman el envío (cabecera y algoritmo).              │
 * │    4. Confirmar la zona horaria y el formato de la marca de tiempo.       │
 * │                                                                           │
 * │  Se dejó escrito así, y no inventado como si fuera definitivo, porque un  │
 * │  mapeo que parece correcto y no lo es se descubre el día que se pierde    │
 * │  una excursión térmica de verdad.                                         │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * El resto del sistema no depende de estas suposiciones: solo este archivo.
 */
class Tempubi implements AdaptadorDispositivo
{
    /**
     * Alias tolerados por campo. Se aceptan varios nombres porque los
     * fabricantes cambian la nomenclatura entre versiones de firmware, y no
     * vale la pena que una actualización del proveedor tumbe la ingesta.
     */
    private const CAMPOS = [
        'serial'      => ['serial', 'device_id', 'deviceId', 'imei', 'sn'],
        'medido_en'   => ['timestamp', 'ts', 'measured_at', 'fecha', 'time'],
        'temperatura' => ['temperature', 'temp', 'temperatura', 't'],
        'humedad'     => ['humidity', 'humedad', 'hum', 'h'],
        'latitud'     => ['lat', 'latitude', 'latitud'],
        'longitud'    => ['lon', 'lng', 'longitude', 'longitud'],
        'precision'   => ['accuracy', 'hdop', 'precision'],
        'velocidad'   => ['speed', 'velocidad', 'spd'],
        'bateria'     => ['battery', 'bat', 'bateria', 'soc'],
    ];

    /** Contenedores donde puede venir la lista de lecturas. */
    private const CONTENEDORES = ['readings', 'data', 'records', 'lecturas', 'items', 'payload'];

    public function nombre(): string
    {
        return 'tempubi';
    }

    /**
     * @param  array<string, mixed>  $carga
     * @return array<int, LecturaNormalizada>
     */
    public function traducir(array $carga): array
    {
        $lecturas = [];

        foreach ($this->extraerFilas($carga) as $fila) {
            $lectura = $this->traducirFila($fila, $carga);

            // Se descarta el ruido en vez de guardarlo: una fila sin serial o
            // sin ninguna medida no aporta y ensucia la serie.
            if ($lectura !== null && $lectura->esUtil()) {
                $lecturas[] = $lectura;
            }
        }

        return $lecturas;
    }

    /**
     * Un envío puede traer una lectura suelta o un lote. Los dispositivos que
     * estuvieron sin cobertura descargan todo junto al reconectar, así que el
     * lote es el caso normal, no la excepción.
     *
     * @param  array<string, mixed>  $carga
     * @return array<int, array<string, mixed>>
     */
    private function extraerFilas(array $carga): array
    {
        foreach (self::CONTENEDORES as $clave) {
            if (isset($carga[$clave]) && is_array($carga[$clave])) {
                $contenido = $carga[$clave];

                return array_is_list($contenido) ? $contenido : [$contenido];
            }
        }

        return array_is_list($carga) ? $carga : [$carga];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, mixed>  $sobre  El envío completo: el serial suele
     *         venir una sola vez en la cabecera y no repetido en cada lectura.
     */
    private function traducirFila(array $fila, array $sobre): ?LecturaNormalizada
    {
        $serial = $this->valor($fila, 'serial') ?? $this->valor($sobre, 'serial');

        if ($serial === null) {
            return null;
        }

        $medidoEn = $this->fecha($this->valor($fila, 'medido_en') ?? $this->valor($sobre, 'medido_en'));

        if ($medidoEn === null) {
            return null;
        }

        return new LecturaNormalizada(
            serial: (string) $serial,
            medidoEn: $medidoEn,
            temperatura: $this->numero($this->valor($fila, 'temperatura')),
            humedad: $this->numero($this->valor($fila, 'humedad')),
            latitud: $this->numero($this->valor($fila, 'latitud')),
            longitud: $this->numero($this->valor($fila, 'longitud')),
            precisionM: $this->entero($this->valor($fila, 'precision')),
            velocidadKmh: $this->entero($this->valor($fila, 'velocidad')),
            bateria: $this->entero($this->valor($fila, 'bateria')),
            crudo: $fila,
        );
    }

    /** @param array<string, mixed> $origen */
    private function valor(array $origen, string $campo): mixed
    {
        foreach (self::CAMPOS[$campo] as $alias) {
            if (array_key_exists($alias, $origen) && $origen[$alias] !== null && $origen[$alias] !== '') {
                return $origen[$alias];
            }
        }

        return null;
    }

    /**
     * Acepta epoch en segundos, epoch en milisegundos e ISO 8601.
     *
     * Una fecha sin huso se interpreta en la zona de la operación, no en UTC:
     * asumir UTC correría cinco horas cada lectura, y una excursión térmica
     * quedaría atribuida al turno equivocado.
     */
    private function fecha(mixed $valor): ?\DateTimeImmutable
    {
        if ($valor === null) {
            return null;
        }

        try {
            if (is_numeric($valor)) {
                $n = (int) $valor;

                // Más de 10 dígitos: viene en milisegundos.
                return (new \DateTimeImmutable('@'.($n > 99_999_999_999 ? intdiv($n, 1000) : $n)))
                    ->setTimezone(new \DateTimeZone(config('app.timezone')));
            }

            $texto = (string) $valor;
            $huso  = preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $texto)
                ? null
                : new \DateTimeZone(config('app.timezone'));

            return new \DateTimeImmutable($texto, $huso);
        } catch (\Throwable) {
            return null;
        }
    }

    private function numero(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function entero(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    /**
     * HMAC-SHA256 sobre el cuerpo crudo, comparado en tiempo constante.
     *
     * Se acepta con y sin el prefijo `sha256=` porque es la convención más
     * extendida y no cuesta nada tolerar ambas.
     *
     * PENDIENTE DE CONFIRMAR: el algoritmo y la cabecera reales de Tempubi.
     * Si firman distinto —o no firman—, esto se ajusta aquí. Si resultara que
     * no ofrecen firma, hay que restringir la ingesta por IP de origen: dejarla
     * abierta permitiría a cualquiera inyectar lecturas y encubrir una ruptura
     * de cadena de frío.
     */
    public function verificarFirma(string $cuerpoCrudo, string $firmaRecibida, string $secreto): bool
    {
        if ($secreto === '' || $firmaRecibida === '') {
            return false;
        }

        $limpia   = str_starts_with($firmaRecibida, 'sha256=')
            ? substr($firmaRecibida, 7)
            : $firmaRecibida;

        $esperada = hash_hmac('sha256', $cuerpoCrudo, $secreto);

        return hash_equals($esperada, $limpia);
    }
}
