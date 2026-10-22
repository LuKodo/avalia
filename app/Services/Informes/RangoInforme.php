<?php

namespace App\Services\Informes;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * El periodo que cubre un informe y cómo se agrupan sus columnas.
 *
 * Existe aparte porque los cuatro informes del administrador —productividad,
 * estados, consolidado y clientes— se piden con exactamente los mismos
 * parámetros, y porque la agrupación por día, semana o mes tiene que producir
 * la MISMA clave en los cuatro: si el informe diario y el de clientes llaman
 * «semana 42» a semanas distintas, compararlos deja de significar algo.
 *
 * La semana es la ISO (lunes a domingo, `YEARWEEK(..., 3)`), que es la que usa
 * el resto del país para hablar de «la semana pasada».
 */
final class RangoInforme
{
    private function __construct(
        public readonly CarbonImmutable $desde,
        public readonly CarbonImmutable $hasta,
        public readonly string $agrupar,
    ) {}

    /**
     * Lee el rango de la petición.
     *
     * Por omisión, los últimos treinta días: es el horizonte con el que se
     * trabaja una operación de mensajería, y evita que abrir la pantalla sin
     * filtros barra la tabla entera.
     */
    public static function desdePeticion(Request $request): self
    {
        $agrupar = in_array($request->query('agrupar'), ['dia', 'semana', 'mes'], true)
            ? $request->query('agrupar')
            : 'dia';

        $hasta = $request->filled('hasta')
            ? CarbonImmutable::parse($request->query('hasta'))->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $desde = $request->filled('desde')
            ? CarbonImmutable::parse($request->query('desde'))->startOfDay()
            : $hasta->subDays(29)->startOfDay();

        // Un rango invertido no es un error del usuario que valga la pena
        // devolverle: casi siempre es que escribió las fechas al revés.
        if ($desde->greaterThan($hasta)) {
            [$desde, $hasta] = [$hasta->startOfDay(), $desde->endOfDay()];
        }

        return new self($desde, $hasta, $agrupar);
    }

    /** La expresión SQL que convierte una columna de fecha en la clave del periodo. */
    public function expresion(string $columna): string
    {
        return match ($this->agrupar) {
            'semana' => "DATE_FORMAT(DATE_SUB($columna, INTERVAL WEEKDAY($columna) DAY), '%Y-%m-%d')",
            'mes'    => "DATE_FORMAT($columna, '%Y-%m-01')",
            default  => "DATE($columna)",
        };
    }

    /** Cómo se lee ese periodo en pantalla. */
    public function etiqueta(string $clave): string
    {
        $f = CarbonImmutable::parse($clave);

        return match ($this->agrupar) {
            'semana' => 'Sem. '.$f->isoWeek().' · '.$f->translatedFormat('d M'),
            'mes'    => ucfirst($f->translatedFormat('F Y')),
            default  => $f->translatedFormat('d M'),
        };
    }

    /**
     * Todos los periodos del rango, incluso los que no tienen una sola fila.
     *
     * Un informe que salta los días sin entregas miente por omisión: el lunes
     * festivo en que no se movió nada desaparece, y la línea del gráfico une el
     * viernes con el martes como si fueran consecutivos.
     *
     * @return list<string>
     */
    public function periodos(): array
    {
        $paso = match ($this->agrupar) {
            'semana' => '1 week',
            'mes'    => '1 month',
            default  => '1 day',
        };

        $cursor = match ($this->agrupar) {
            'semana' => $this->desde->startOfWeek(),
            'mes'    => $this->desde->startOfMonth(),
            default  => $this->desde->startOfDay(),
        };

        $claves = [];

        while ($cursor->lessThanOrEqualTo($this->hasta)) {
            $claves[] = $cursor->format('Y-m-d');
            $cursor   = $cursor->add($paso);
        }

        return $claves;
    }

    /** @return array{desde:string, hasta:string, agrupar:string} */
    public function aArreglo(): array
    {
        return [
            'desde'    => $this->desde->toDateString(),
            'hasta'    => $this->hasta->toDateString(),
            'agrupar'  => $this->agrupar,
        ];
    }
}
