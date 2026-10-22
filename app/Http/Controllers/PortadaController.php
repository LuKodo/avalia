<?php

namespace App\Http\Controllers;

use App\Enums\TipoPaquete;
use App\Models\Parametro;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * El sitio público, con sus cifras sacadas de la operación real.
 *
 * Las cifras de una portada son una promesa: quien las lee decide a quién le
 * entrega su cadena de frío por lo que dicen. Escribirlas a mano tiene dos
 * problemas, y el segundo es el grave: envejecen —nadie vuelve a editarlas— y
 * nadie puede comprobarlas. Aquí salen de la misma base que responde cuando un
 * auditor pregunta, así que son ciertas el día que se leen.
 *
 * **Una cifra sin respaldo suficiente no se muestra.** Un «100 % de
 * cumplimiento» calculado sobre siete entregas es verdad aritmética y mentira
 * comercial; por debajo del mínimo, el bloque simplemente no aparece. Es
 * preferible una portada con tres cifras a una con cuatro de las que una no se
 * sostiene.
 */
class PortadaController extends Controller
{
    /**
     * Entregas mínimas para que un porcentaje signifique algo.
     *
     * Doscientas no es un número mágico: es el orden de magnitud a partir del
     * cual una entrega mal registrada deja de mover el indicador medio punto.
     */
    private const MINIMO_PARA_PORCENTAJE = 200;

    /** Envíos mínimos para publicar el volumen de cadena de frío. */
    private const MINIMO_PARA_VOLUMEN = 100;

    public function __invoke(): View
    {
        return view('sitio', ['cifras' => $this->cifrasSeguras()]);
    }

    /**
     * Las cifras, o ninguna, pero la portada SIEMPRE se pinta.
     *
     * Esta es la primera página que ve un cliente y la que sale en los
     * buscadores: no puede depender de que la base responda. Pasó de verdad
     * —un 500 en `/sitio` tras copiar el código y antes de correr las
     * migraciones, porque la columna `vigente` todavía no existía— y ese es
     * exactamente el momento en que menos se puede permitir.
     *
     * Así que cualquier fallo al calcularlas se registra y la página sale sin
     * ellas. Una portada sin cifras sigue vendiendo; una portada rota, no.
     *
     * @return array<int, array{valor: string, etiqueta: string}>
     */
    private function cifrasSeguras(): array
    {
        try {
            return $this->cifras();
        } catch (\Throwable $e) {
            Log::warning('No se pudieron calcular las cifras de la portada', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Las cuatro cifras de la portada, o las que haya.
     *
     * En caché seis horas: la portada la visita gente, no un sistema, y tres
     * consultas agregadas sobre la tabla de paquetes en cada visita serían un
     * gasto que nadie pidió. Seis horas es más fresco de lo que cualquier
     * portada necesita.
     *
     * @return array<int, array{valor: string, etiqueta: string}>
     */
    private function cifras(): array
    {
        return Cache::remember('sitio.cifras', now()->addHours(6), function () {
            $cifras = [];

            /*
             * Las comparaciones van con `!== null` y no a secas.
             *
             * Un cero es un valor legítimo y es falso en PHP: con la
             * comprobación corta, un cumplimiento del 0 % desaparecería de la
             * portada por el mismo camino por el que desaparece un dato que no
             * se pudo calcular, y son dos cosas muy distintas. Que no se
             * publique un cero es una decisión —está abajo, escrita— no un
             * descuido del operador booleano.
             */
            $cumplimiento = $this->cumplimiento();

            if ($cumplimiento !== null) {
                $cifras[] = [
                    'valor'    => $cumplimiento.' %',
                    'etiqueta' => 'Entregas dentro de la ventana comprometida',
                ];
            }

            $frio = $this->envíosConFrio();

            if ($frio !== null) {
                $cifras[] = [
                    'valor'    => number_format($frio, 0, ',', '.'),
                    'etiqueta' => 'Envíos con cadena de frío en los últimos doce meses',
                ];
            }

            $evidencia = $this->conEvidencia();

            if ($evidencia !== null) {
                $cifras[] = [
                    'valor'    => $evidencia.' %',
                    'etiqueta' => 'Entregas con foto y firma del receptor',
                ];
            }

            $anios = $this->aniosDeOperacion();

            if ($anios !== null) {
                $cifras[] = [
                    'valor'    => (string) $anios,
                    'etiqueta' => $anios === 1
                        ? 'Año transportando producto farmacéutico'
                        : 'Años transportando producto farmacéutico',
                ];
            }

            return $cifras;
        });
    }

    /**
     * Porcentaje de entregas dentro del compromiso, en doce meses.
     *
     * Solo cuentan las que TENÍAN compromiso. Incluir las que no lo tenían
     * como cumplidas inflaría el número con envíos que nunca se midieron, que
     * es la forma más común de que un indicador de cumplimiento mienta sin que
     * nadie haya escrito una mentira.
     */
    private function cumplimiento(): ?float
    {
        $base = DB::table('paquetes as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereNull('o.deleted_at')
            ->whereNotNull('p.entregado_en')
            ->whereNotNull('o.comprometida_en')
            ->where('p.entregado_en', '>=', now()->subYear());

        $total = (clone $base)->count();

        if ($total < self::MINIMO_PARA_PORCENTAJE) {
            return null;
        }

        $aTiempo = (clone $base)->whereRaw('p.entregado_en <= o.comprometida_en')->count();

        $porcentaje = round($aTiempo * 100 / $total, 1);

        // Mismo criterio que la evidencia: la portada afirma, el informe mide.
        return $porcentaje >= 90 ? $porcentaje : null;
    }

    /** Envíos con cadena de frío en los últimos doce meses. */
    private function envíosConFrio(): ?int
    {
        $n = DB::table('paquetes')
            ->where('tipo', TipoPaquete::CadenaFrio->value)
            ->where('created_at', '>=', now()->subYear())
            ->count();

        return $n >= self::MINIMO_PARA_VOLUMEN ? $n : null;
    }

    /**
     * Porcentaje de entregas con foto del receptor Y firma.
     *
     * Las dos cosas, no una. La aplicación de campo exige ambas para confirmar
     * una entrega, así que en una operación sana esto da 100 %; si alguna vez
     * baja, lo que hay que hacer es mirar por qué, no redondear hacia arriba.
     */
    private function conEvidencia(): ?float
    {
        $base = DB::table('entregas')->where('vigente', true);

        $total = (clone $base)->count();

        if ($total < self::MINIMO_PARA_PORCENTAJE) {
            return null;
        }

        $completas = (clone $base)
            ->whereNotNull('firma_id')
            ->whereNotNull('foto_receptor_id')
            ->count();

        $porcentaje = round($completas * 100 / $total, 1);

        /*
         * Por debajo del 95 % no se publica, y no es por disimular.
         *
         * Una portada afirma; un informe mide. «71 % de entregas con foto y
         * firma» no es un argumento comercial, es un hallazgo de auditoría
         * interna, y su sitio es la pantalla de Informes —donde se muestra
         * siempre, bueno o malo— no la primera pantalla que ve un cliente
         * potencial. Si este número baja, lo que hay que hacer es mirar por
         * qué, no maquillarlo ni enseñarlo como logro.
         */
        return $porcentaje >= 95 ? $porcentaje : null;
    }

    /**
     * Años transportando producto farmacéutico.
     *
     * Este NO sale de la base: la plataforma no sabe cuándo empezó a operar la
     * empresa, solo cuándo empezó a usarse el software, que es bastante
     * después. Sale de un parámetro que fija quien sí lo sabe, y mientras esté
     * vacío la cifra no se publica.
     */
    private function aniosDeOperacion(): ?int
    {
        $desde = (int) Parametro::obtener('sitio.operacion_desde', 0);

        if ($desde < 1900 || $desde > (int) now()->year) {
            return null;
        }

        return max(1, (int) now()->year - $desde);
    }
}
