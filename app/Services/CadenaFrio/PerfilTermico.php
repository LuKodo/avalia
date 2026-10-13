<?php

namespace App\Services\CadenaFrio;

use App\Models\Paquete;
use Illuminate\Support\Collection;

/**
 * Análisis térmico del trayecto: MKT, tiempo fuera de rango y dictamen.
 *
 * Por qué existe: el sistema sabía decir «hubo excursión» o «no hubo excursión».
 * Binario. Pero cuando a un químico farmacéutico le llega una caja que estuvo
 * doce minutos a 9,1 °C, la pregunta que tiene que responder no es esa: es si el
 * producto sigue siendo apto. Un pico de 9 °C durante cuatro minutos y una hora
 * sostenida a 8,5 °C disparan hoy la misma alarma, y son cosas distintas.
 *
 * Dos cálculos responden esa pregunta:
 *
 *   MKT — Temperatura Cinética Media. La ecuación de Arrhenius colapsa todo el
 *   perfil del viaje en UNA temperatura equivalente: la que el producto
 *   realmente «sintió». Es el indicador que usan USP <1079> y el Anexo 9 de la
 *   OMS, y pondera por tiempo, no por número de lecturas.
 *
 *   TOR — Tiempo fuera de rango acumulado, contra el presupuesto que permite el
 *   estudio de estabilidad del producto. Es lo que convierte «se salió» en «se
 *   salió y le quedan 197 minutos de margen».
 *
 * LÍMITE IMPORTANTE, y la razón de que el frío se trate aparte: el MKT NO mide
 * daño por congelación. Arrhenius modela degradación acelerada por calor. Una
 * vacuna que tocó −1 °C puede quedar inservible y arrojar igual un MKT
 * impecable, porque el promedio cinético se lo come. Tratar frío y calor con el
 * mismo número sería un error con consecuencias sanitarias, así que aquí la
 * excursión fría se cuenta, se reporta y bloquea el dictamen por su cuenta.
 */
class PerfilTermico
{
    /**
     * Constante de Arrhenius: ΔH / R.
     *
     * Con la energía de activación convencional para producto farmacéutico
     * (83,144 kJ/mol) y la constante de los gases (0,0083144 kJ/mol·K) el
     * cociente da exactamente 10 000 K. Ese es el valor por defecto de
     * USP <1079>; se deja configurable porque un laboratorio puede declarar una
     * energía de activación propia respaldada por su estudio de estabilidad.
     */
    private float $deltaHSobreR;

    private const CERO_ABSOLUTO = 273.15;

    /**
     * Tope de subdivisiones al refinar un tramo. Un trayecto largo converge
     * mucho antes de este límite; el tope solo evita que una lectura perdida a
     * ocho horas de la siguiente genere medio millar de escalones inútiles.
     */
    private const MAX_ESCALONES_POR_TRAMO = 240;

    public function __construct()
    {
        $this->deltaHSobreR = (float) config('ltp.cadena_frio.mkt.delta_h_sobre_r', 10_000.0);
    }

    /**
     * Análisis completo de un paquete.
     *
     * @return array<string, mixed>|null  null si el paquete no exige cadena de frío.
     */
    public function analizar(Paquete $paquete): ?array
    {
        if (! $paquete->exigeCadenaFrio()) {
            return null;
        }

        $rango = $paquete->rangoTermico();

        $puntos = $this->consolidar(
            $paquete->temperaturas
                ->filter(fn ($l) => $l->registrada_en !== null)
                ->sortBy(fn ($l) => $l->registrada_en->getTimestamp())
                ->values(),
            $rango
        );

        if ($puntos->isEmpty()) {
            return $this->sinDatos($rango);
        }

        // Un solo perfil alimenta ambos indicadores. Que compartan la fuente es
        // lo que garantiza que no puedan contradecirse.
        $perfil = $this->perfil($puntos);

        $mkt         = $this->mkt($perfil);
        $fuera       = $this->tiempoFueraDeRango($perfil, $rango);
        $cobertura   = $this->cobertura($paquete, $puntos);
        $presupuesto = $this->presupuestoMinutos($paquete);

        $consumido = $presupuesto > 0
            ? min(100.0, round($fuera['total_min'] / $presupuesto * 100, 1))
            : null;

        return [
            'rango_exigido' => $rango,

            'mkt' => $mkt === null ? null : [
                'valor_c'         => round($mkt, 2),
                'en_rango'        => $mkt >= $rango['min'] && $mkt <= $rango['max'],
                'delta_h_sobre_r' => $this->deltaHSobreR,
                'norma'           => 'USP <1079> · OMS Anexo 9',
            ],

            'tiempo_fuera_de_rango' => $fuera,

            'presupuesto_estabilidad' => [
                'minutos'        => $presupuesto,
                'consumidos_min' => $fuera['total_min'],
                'consumido_pct'  => $consumido,
                'restante_min'   => max(0, $presupuesto - $fuera['total_min']),
                'excedido'       => $presupuesto > 0 && $fuera['total_min'] > $presupuesto,
            ],

            'cobertura' => $cobertura,

            'lecturas' => [
                'total'    => $puntos->count(),
                'fuera'    => $puntos->where('fuera_de_rango', true)->count(),
                'minima_c' => round((float) $puntos->min('valor'), 2),
                'maxima_c' => round((float) $puntos->max('valor'), 2),
                'desde'    => $puntos->first()->registrada_en->toIso8601String(),
                'hasta'    => $puntos->last()->registrada_en->toIso8601String(),
            ],

            'dictamen' => $this->dictamen($mkt, $fuera, $rango, $presupuesto, $cobertura),
        ];
    }

    /**
     * Colapsa las lecturas que comparten el mismo instante, quedándose con la
     * más desfavorable.
     *
     * Sin esto, dos lecturas del mismo segundo se separan por un intervalo de
     * cero, que el refinado acota a un segundo: la primera recibe un segundo de
     * peso y desaparece del cálculo. Se vio con un paquete cuya lectura manual
     * de recolección marcaba 11 °C y coincidía al segundo con la del sensor —
     * el soporte mostraba «Recolección 11 °C» junto a «0 min de calor», que es
     * la clase de contradicción que hace que nadie vuelva a creerle al informe.
     *
     * Coincidir así ocurre de verdad: un registro manual y uno automático en el
     * mismo momento, o una carga duplicada desde el dispositivo. Cuando dos
     * fuentes discrepan sobre el mismo instante se conserva la que está más
     * lejos del centro del rango. Es la elección conservadora, y en cadena de
     * frío equivocarse hacia la alarma cuesta una revisión; equivocarse hacia
     * el silencio cuesta un lote.
     *
     * @param  Collection<int, \App\Models\LecturaTemperatura>  $puntos
     * @param  array{min: float, max: float}  $rango
     * @return Collection<int, \App\Models\LecturaTemperatura>
     */
    private function consolidar(Collection $puntos, array $rango): Collection
    {
        $centro = ($rango['min'] + $rango['max']) / 2;

        return $puntos
            ->groupBy(fn ($l) => $l->registrada_en->getTimestamp())
            ->map(fn (Collection $grupo) => $grupo->sortByDesc(
                fn ($l) => abs((float) $l->valor - $centro)
            )->first())
            ->values();
    }

    /**
     * Perfil térmico refinado: la serie de lecturas convertida en escalones
     * finos de temperatura interpolada.
     *
     * Existe para resolver una incoherencia que tenía este servicio: el MKT
     * trataba cada lectura como un escalón constante hasta la siguiente,
     * mientras el tiempo fuera de rango interpolaba una rampa entre ambas. Dos
     * modelos distintos del MISMO perfil, y por tanto dos respuestas que no se
     * podían conciliar — un perfil que bajaba de 4 °C a −3 °C reportaba 107
     * minutos de congelación cuando la lectura fría duraba treinta.
     *
     * La salida es un único perfil que ambos cálculos consumen igual. Entre dos
     * lecturas se interpola linealmente en escalones de a lo sumo un minuto: es
     * la hipótesis físicamente razonable —la temperatura de una caja no salta,
     * transita— y al ser común a los dos indicadores garantiza que nunca más
     * se contradigan. La última lectura no tiene sucesora, así que sostiene su
     * valor: extrapolar hacia adelante sería inventar.
     *
     * @param  Collection<int, \App\Models\LecturaTemperatura>  $puntos
     * @return array<int, array{valor: float, segundos: float}>
     */
    private function perfil(Collection $puntos): array
    {
        $lista = $puntos->all();
        $n     = count($lista);

        if ($n === 1) {
            return [['valor' => (float) $lista[0]->valor, 'segundos' => 60.0]];
        }

        $perfil = [];

        for ($i = 0; $i < $n - 1; $i++) {
            $a = (float) $lista[$i]->valor;
            $b = (float) $lista[$i + 1]->valor;
            $d = max(1.0, (float) $lista[$i]->registrada_en->diffInSeconds($lista[$i + 1]->registrada_en, true));

            // Un escalón por minuto, con tope por tramo: un trayecto de ocho
            // horas no necesita medio millar de subdivisiones para que el
            // resultado converja, y sí encarece el cálculo sin ganar nada.
            $pasos = (int) min(self::MAX_ESCALONES_POR_TRAMO, max(1, ceil($d / 60)));
            $dt    = $d / $pasos;

            for ($k = 0; $k < $pasos; $k++) {
                // Punto medio del escalón: centrar en vez de tomar el extremo
                // evita sesgar el promedio hacia el inicio del tramo.
                $u = ($k + 0.5) / $pasos;

                $perfil[] = ['valor' => $a + ($b - $a) * $u, 'segundos' => $dt];
            }
        }

        // La última lectura sostiene su valor durante el intervalo típico de
        // muestreo, para que pese como una más y no se pierda del análisis.
        $ultimoIntervalo = max(1.0, (float) $lista[$n - 2]->registrada_en
            ->diffInSeconds($lista[$n - 1]->registrada_en, true));

        $perfil[] = ['valor' => (float) $lista[$n - 1]->valor, 'segundos' => $ultimoIntervalo];

        return $perfil;
    }

    /**
     * Temperatura Cinética Media, ponderada por tiempo.
     *
     *              ΔH/R
     *   MKT = ─────────────────────────────────
     *          −ln( Σ(tᵢ·e^(−(ΔH/R)/Tᵢ)) / Σtᵢ )
     *
     * Se calcula en Kelvin y se devuelve en Celsius. Ponderar por tiempo y no
     * por número de lecturas es el punto entero del indicador: diez lecturas
     * tomadas en el mismo minuto no pueden pesar lo que una hora de tránsito.
     *
     * @param  array<int, array{valor: float, segundos: float}>  $tramos
     */
    public function mkt(array $tramos): ?float
    {
        if ($tramos === []) {
            return null;
        }

        $sumaPesada = 0.0;
        $sumaTiempo = 0.0;

        foreach ($tramos as $t) {
            $kelvin = $t['valor'] + self::CERO_ABSOLUTO;

            // Por debajo del cero absoluto la exponencial no tiene sentido
            // físico; una lectura así es un sensor averiado, no un dato.
            if ($kelvin <= 0) {
                continue;
            }

            $sumaPesada += $t['segundos'] * exp(-$this->deltaHSobreR / $kelvin);
            $sumaTiempo += $t['segundos'];
        }

        if ($sumaTiempo <= 0.0) {
            return null;
        }

        $promedio = $sumaPesada / $sumaTiempo;

        if ($promedio <= 0.0) {
            return null;
        }

        return ($this->deltaHSobreR / -log($promedio)) - self::CERO_ABSOLUTO;
    }

    /**
     * Minutos fuera de rango, separando calor de frío.
     *
     * Suma directa sobre el perfil refinado. Antes esta función interpolaba el
     * cruce del límite por su cuenta, con un modelo de rampa que el MKT no
     * compartía: los dos indicadores describían perfiles distintos a partir de
     * los mismos datos. La interpolación vive ahora en perfil(), una sola vez y
     * para ambos, de modo que aquí solo queda contar.
     *
     * @param  array<int, array{valor: float, segundos: float}>  $perfil
     * @param  array{min: float, max: float}  $rango
     * @return array<string, mixed>
     */
    public function tiempoFueraDeRango(array $perfil, array $rango): array
    {
        $calor = 0.0;
        $frio  = 0.0;

        foreach ($perfil as $t) {
            if ($t['valor'] > $rango['max']) {
                $calor += $t['segundos'];
            } elseif ($t['valor'] < $rango['min']) {
                $frio += $t['segundos'];
            }
        }

        $calorMin = round($calor / 60, 1);
        $frioMin  = round($frio / 60, 1);

        return [
            'calor_min' => $calorMin,
            'frio_min'  => $frioMin,
            'total_min' => round($calorMin + $frioMin, 1),
            // La congelación se señala aparte porque el MKT no la ve.
            'hubo_congelacion' => $frioMin > 0,
        ];
    }

    /**
     * Qué tanto del tiempo bajo custodia está realmente cubierto por lecturas.
     *
     * Es el dato que impide vender humo. Un MKT calculado sobre dos lecturas
     * —una al recoger y otra al entregar— no es lo mismo que uno sobre un
     * registro continuo cada cinco minutos, y presentarlos con la misma cara
     * sería engañoso. Aquí se dice cuánto se midió de verdad.
     *
     * @param  Collection<int, \App\Models\LecturaTemperatura>  $puntos
     * @return array<string, mixed>
     */
    private function cobertura(Paquete $paquete, Collection $puntos): array
    {
        $inicio = $paquete->recolectado_en;
        $fin    = $paquete->entrega?->entregada_en ?? now();

        $custodiaMin = $inicio === null ? null : round($inicio->diffInSeconds($fin, true) / 60, 1);

        $medidoMin = round(
            $puntos->first()->registrada_en->diffInSeconds($puntos->last()->registrada_en, true) / 60,
            1
        );

        $pct = ($custodiaMin !== null && $custodiaMin > 0)
            ? min(100.0, round($medidoMin / $custodiaMin * 100, 1))
            : null;

        $intervaloMedio = $puntos->count() > 1 && $medidoMin > 0
            ? round($medidoMin / ($puntos->count() - 1), 1)
            : null;

        return [
            'custodia_min'        => $custodiaMin,
            'medido_min'          => $medidoMin,
            'cobertura_pct'       => $pct,
            'intervalo_medio_min' => $intervaloMedio,
            'lecturas'            => $puntos->count(),
            'calidad'             => $this->calidadDelMuestreo($puntos->count(), $pct),
        ];
    }

    /**
     * Qué tan defendible es el análisis. No es una nota estética: define si el
     * dictamen puede sostenerse solo o necesita que alguien lo mire.
     */
    private function calidadDelMuestreo(int $lecturas, ?float $pct): string
    {
        if ($lecturas >= 12 && ($pct ?? 0) >= 80) {
            return 'continua';
        }

        if ($lecturas >= 4 && ($pct ?? 0) >= 50) {
            return 'periodica';
        }

        return 'puntual';
    }

    /**
     * Presupuesto de estabilidad en minutos.
     *
     * Lo correcto es que venga del estudio de estabilidad del producto, que es
     * dato del laboratorio y varía por molécula. Mientras el cliente no lo
     * declare se usa el de configuración, y el dictamen lo dice para que nadie
     * confunda un valor por defecto con un dato del fabricante.
     */
    private function presupuestoMinutos(Paquete $paquete): int
    {
        return (int) ($paquete->presupuesto_estabilidad_min
            ?? config('ltp.cadena_frio.presupuesto_estabilidad_minutos', 240));
    }

    /**
     * El dictamen.
     *
     * Tres estados y una regla que los ordena: cualquier motivo de bloqueo pesa
     * más que todos los indicadores buenos juntos. Un producto congelado no se
     * salva porque el MKT haya salido precioso.
     *
     * @param  array<string, mixed>  $fuera
     * @param  array{min: float, max: float}  $rango
     * @param  array<string, mixed>  $cobertura
     * @return array<string, mixed>
     */
    private function dictamen(?float $mkt, array $fuera, array $rango, int $presupuesto, array $cobertura): array
    {
        $motivos = [];
        $nivel   = 'apto';

        // --- Bloqueantes -------------------------------------------------
        if ($fuera['hubo_congelacion']) {
            $nivel = 'no_apto';
            $motivos[] = sprintf(
                'Excursión por debajo de %s °C durante %s min. El daño por congelación es irreversible en biológicos y el MKT no lo detecta.',
                $rango['min'],
                $fuera['frio_min']
            );
        }

        if ($presupuesto > 0 && $fuera['total_min'] > $presupuesto) {
            $nivel = 'no_apto';
            $motivos[] = sprintf(
                'Presupuesto de estabilidad excedido: %s min fuera de rango contra %s min permitidos.',
                $fuera['total_min'],
                $presupuesto
            );
        }

        if ($mkt !== null && ($mkt < $rango['min'] || $mkt > $rango['max'])) {
            $nivel = 'no_apto';
            $motivos[] = sprintf(
                'MKT de %s °C fuera del rango exigido %s – %s °C.',
                round($mkt, 2),
                $rango['min'],
                $rango['max']
            );
        }

        // --- Requieren criterio humano -----------------------------------
        if ($nivel === 'apto' && $fuera['total_min'] > 0) {
            $nivel = 'evaluar';
            $motivos[] = sprintf(
                'Hubo %s min fuera de rango, dentro del presupuesto (%s %% consumido).',
                $fuera['total_min'],
                $presupuesto > 0 ? round($fuera['total_min'] / $presupuesto * 100, 1) : '—'
            );
        }

        if ($mkt === null) {
            $nivel = 'evaluar';
            $motivos[] = 'No hay lecturas suficientes para calcular el MKT.';
        } elseif ($cobertura['calidad'] === 'puntual' && $nivel === 'apto') {
            $nivel = 'evaluar';
            $motivos[] = sprintf(
                'Muestreo puntual: %d lectura(s) cubriendo %s %% de la custodia. El MKT es indicativo, no concluyente.',
                $cobertura['lecturas'],
                $cobertura['cobertura_pct'] ?? '—'
            );
        }

        return [
            'nivel'    => $nivel,
            'etiqueta' => match ($nivel) {
                'apto'    => 'Producto apto',
                'evaluar' => 'Requiere evaluación de Calidad',
                'no_apto' => 'No apto · cuarentena',
            },
            'semaforo' => match ($nivel) {
                'apto'    => 'good',
                'evaluar' => 'warning',
                'no_apto' => 'critical',
            },
            'motivos' => $motivos === [] ? ['Todo el trayecto transcurrió dentro del rango exigido.'] : $motivos,

            /*
             * Este descargo viaja pegado al dictamen y no se quita. El motor
             * calcula; el criterio de aptitud lo fija Calidad. Un cálculo que se
             * presente como decisión regulatoria propia es justo lo que no debe
             * salir de aquí.
             */
            'descargo' => 'Cálculo automático sobre el perfil térmico registrado. '
                .'No sustituye la evaluación del área de Calidad ni el criterio del titular del registro sanitario.',
        ];
    }

    /**
     * @param  array{min: float, max: float}  $rango
     * @return array<string, mixed>
     */
    private function sinDatos(array $rango): array
    {
        return [
            'rango_exigido' => $rango,
            'mkt'           => null,
            'tiempo_fuera_de_rango' => [
                'calor_min' => 0.0, 'frio_min' => 0.0, 'total_min' => 0.0, 'hubo_congelacion' => false,
            ],
            'presupuesto_estabilidad' => null,
            'cobertura' => ['lecturas' => 0, 'calidad' => 'sin_datos', 'cobertura_pct' => 0.0],
            'lecturas'  => ['total' => 0],
            'dictamen'  => [
                'nivel'    => 'evaluar',
                'etiqueta' => 'Sin registro térmico',
                'semaforo' => 'warning',
                'motivos'  => ['El paquete exige cadena de frío y no tiene ninguna lectura registrada.'],
                'descargo' => 'Cálculo automático sobre el perfil térmico registrado. '
                    .'No sustituye la evaluación del área de Calidad ni el criterio del titular del registro sanitario.',
            ],
        ];
    }
}
