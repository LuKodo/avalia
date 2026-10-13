<?php

namespace App\Enums;

/**
 * Máquina de estados del paquete.
 *
 * Regla invariante: las transiciones son monotónicas. Un paquete nunca retrocede
 * de estado. Una corrección se modela como NOVEDAD + REPROGRAMADO, con evento
 * propio en la bitácora. Esta regla es la que permite resolver conflictos de
 * sincronización sin ambigüedad: si llega un evento tardío desde un dispositivo
 * que estuvo sin señal, se registra como histórico pero no retrocede el estado.
 */
enum EstadoPaquete: string
{
    case Creado            = 'creado';
    case Asignado          = 'asignado';
    case EnRutaRecoleccion = 'en_ruta_recoleccion';
    case Recolectado       = 'recolectado';
    case EnTransito        = 'en_transito';
    case EnSitio           = 'en_sitio';
    case Entregado         = 'entregado';
    case Novedad           = 'novedad';
    case Reprogramado      = 'reprogramado';
    case Devuelto          = 'devuelto';
    case Siniestro         = 'siniestro';

    /** Estados desde los que ya no hay movimiento posible. */
    public function esTerminal(): bool
    {
        return in_array($this, [self::Entregado, self::Devuelto, self::Siniestro], true);
    }

    /** Estados en los que el paquete está bajo custodia de un mensajero. */
    public function bajoCustodia(): bool
    {
        return in_array($this, [self::Recolectado, self::EnTransito, self::EnSitio], true);
    }

    /**
     * Qué puede hacer el mensajero con un paquete en este estado: `recoger`,
     * `entregar`, o nada.
     *
     * Antes la aplicación lo deducía con un binario —«si está bajo custodia,
     * entregar; si no, recoger»— y eso dejaba fuera los estados en los que la
     * respuesta correcta es NINGUNA de las dos. Un paquete con novedad está en
     * manos del mensajero esperando que operaciones decida: reprogramar,
     * devolver o declarar siniestro. Ofrecerle «Recolectar» le hacía registrar
     * eventos que la máquina de estados rechazaba por dentro mientras la
     * pantalla le decía que todo había ido bien.
     */
    public function accionDeCampo(): ?string
    {
        return match ($this) {
            self::Creado, self::Asignado, self::EnRutaRecoleccion, self::Reprogramado => 'recoger',
            self::Recolectado, self::EnTransito, self::EnSitio, self::Novedad => 'entregar',
            self::Entregado, self::Devuelto, self::Siniestro => null,
        };
    }

    /** Lo que hay que decirle al mensajero sobre este estado, si hay algo. */
    public function notaDeCampo(): ?string
    {
        return match ($this) {
            self::Novedad    => 'Con novedad',
            self::Entregado  => 'Ya entregado.',
            self::Devuelto   => 'Devuelto al origen.',
            self::Siniestro  => 'Declarado siniestro.',
            default          => null,
        };
    }

    /**
     * Orden de avance. Decide si un evento que llega tarde desde un dispositivo
     * sin señal debe mover el estado o quedar solo como histórico.
     */
    public function rango(): int
    {
        return match ($this) {
            self::Creado            => 0,
            self::Asignado          => 1,
            self::EnRutaRecoleccion => 2,
            self::Recolectado       => 3,
            self::EnTransito        => 4,
            self::EnSitio           => 5,
            self::Novedad           => 6,
            self::Reprogramado      => 6,
            self::Entregado, self::Devuelto, self::Siniestro => 9,
        };
    }

    /** @return array<int, self> */
    public function siguientesPosibles(): array
    {
        return match ($this) {
            self::Creado            => [self::Asignado, self::Novedad],
            // Desde «asignado» se admite ir directo a «recolectado». La
            // aplicación de campo no obliga al mensajero a marcar «voy en
            // camino»: exigirle un toque de pantalla que no aporta evidencia
            // solo consigue que lo marque tarde, o que no lo marque. El estado
            // intermedio sigue disponible para cuando la app sí lo emita.
            self::Asignado          => [self::EnRutaRecoleccion, self::Recolectado, self::Novedad],
            self::EnRutaRecoleccion => [self::Recolectado, self::Novedad],
            // `en_transito` y `en_sitio` los emite la automatización de
            // seguimiento, que hoy no existe: la aplicación de campo pasa de
            // recolectar a entregar. Exigir esos pasos intermedios hacía que
            // toda entrega desde campo fuera rechazada, aunque el mensajero ya
            // hubiera capturado foto y firma. Siguen disponibles para cuando el
            // seguimiento los emita, pero no bloquean el camino real.
            self::Recolectado       => [self::EnTransito, self::EnSitio, self::Entregado, self::Novedad, self::Siniestro],
            self::EnTransito        => [self::EnSitio, self::Entregado, self::Novedad, self::Siniestro],
            self::EnSitio           => [self::Entregado, self::Novedad],
            /*
             * Una novedad es «no pude ahora», no «se acabó».
             *
             * El paquete sigue en manos del mensajero y muchas veces el propio
             * recorrido lo resuelve: fue a las diez, no había nadie, volvió a
             * las tres y entregó. Obligar a que operación reprograme en medio
             * no añade control: añade trámite, y el trámite acaba en entregas
             * que ocurren y no se registran.
             *
             * Las tres salidas de operación siguen ahí para cuando de verdad no
             * se puede continuar.
             */
            self::Novedad           => [self::EnSitio, self::Entregado, self::Reprogramado, self::Devuelto, self::Siniestro],
            self::Reprogramado      => [self::Asignado, self::EnRutaRecoleccion, self::EnTransito],
            self::Entregado, self::Devuelto, self::Siniestro => [],
        };
    }

    public function puedePasarA(self $destino): bool
    {
        return in_array($destino, $this->siguientesPosibles(), true);
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Creado            => 'Creado',
            self::Asignado          => 'Asignado',
            self::EnRutaRecoleccion => 'En ruta a recolección',
            self::Recolectado       => 'Recolectado',
            self::EnTransito        => 'En tránsito',
            self::EnSitio           => 'En sitio de entrega',
            self::Entregado         => 'Entregado',
            self::Novedad           => 'Con novedad',
            self::Reprogramado      => 'Reprogramado',
            self::Devuelto          => 'Devuelto',
            self::Siniestro         => 'Siniestro',
        };
    }

    /** Semáforo para la interfaz: good | warning | serious | critical | neutral. */
    public function semaforo(): string
    {
        return match ($this) {
            self::Entregado                   => 'good',
            self::Novedad, self::Reprogramado => 'warning',
            self::Devuelto                    => 'serious',
            self::Siniestro                   => 'critical',
            default                           => 'neutral',
        };
    }

    /** @return array<int, array{valor:string, etiqueta:string, semaforo:string}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $e) => [
            'valor'    => $e->value,
            'etiqueta' => $e->etiqueta(),
            'semaforo' => $e->semaforo(),
        ], self::cases());
    }

    /**
     * Agrupaciones para filtrar, que NO son estados.
     *
     * Un cliente no pregunta «enséñame lo que está en_ruta_recoleccion»:
     * pregunta si su envío sigue en trámite o ya se cerró. Con once estados en
     * un desplegable, el filtro no lo usa nadie. Estas cuatro son las preguntas
     * que de verdad se hacen; el estado exacto sigue visible en cada fila, que
     * es donde importa el detalle.
     *
     * @return array<string, array{etiqueta: string, estados: array<int, string>}>
     */
    public static function grupos(): array
    {
        return [
            'en_tramite' => [
                'etiqueta' => 'En trámite',
                'estados'  => [
                    self::Creado->value, self::Asignado->value,
                    self::EnRutaRecoleccion->value, self::Recolectado->value,
                    self::EnTransito->value, self::EnSitio->value,
                    self::Reprogramado->value,
                ],
            ],
            'entregadas' => [
                'etiqueta' => 'Entregadas',
                'estados'  => [self::Entregado->value],
            ],
            'con_novedad' => [
                'etiqueta' => 'Con novedad',
                'estados'  => [self::Novedad->value],
            ],
            'cerradas_sin_entrega' => [
                'etiqueta' => 'Cerradas sin entrega',
                'estados'  => [self::Devuelto->value, self::Siniestro->value],
            ],
        ];
    }

    /**
     * Traduce lo que llegue del filtro a una lista de estados.
     *
     * Acepta tanto un grupo como un estado suelto, para no romper a quien ya
     * consulte por estado exacto desde la API.
     *
     * @return array<int, string>  Vacío si no corresponde a nada conocido.
     */
    public static function resolverFiltro(string $clave): array
    {
        $grupos = self::grupos();

        if (isset($grupos[$clave])) {
            return $grupos[$clave]['estados'];
        }

        return self::tryFrom($clave) !== null ? [$clave] : [];
    }
}
