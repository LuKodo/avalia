<?php

namespace App\Services\Informes;

use App\Enums\EstadoPaquete;
use App\Models\Cliente;
use App\Models\Mensajero;
use App\Models\Parametro;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los informes del administrador, armados sobre una sola lectura de la base.
 *
 * Todas las preguntas del documento de verificación —cuánto entregó cada
 * mensajero, en qué estados está su carga, cómo se reparte por cliente, cuánto
 * costaron las urgentes, cuántas guías generó cada cliente— son cortes de la
 * misma tabla. Por eso no hay una consulta por informe sino cuatro consultas
 * agrupadas al máximo detalle, y el resto se arma en memoria: pedir lo mismo
 * cinco veces con distintos `GROUP BY` multiplica el trabajo de la base por
 * cinco y hace que los números de dos pantallas puedan no cuadrar.
 *
 * Dos ejes, y la pantalla los nombra para que nadie los sume:
 *
 *  - **Trabajo hecho**: se cuenta por `entregado_en` dentro del rango. Responde
 *    «¿cuánto se entregó esta semana?», sin importar cuándo se radicó.
 *  - **Carga**: es una foto por estado de los paquetes radicados en el rango.
 *    Responde «¿qué quedó en manos de quién?».
 *
 * Nada de ventanas ni CTE: esto tiene que correr igual en el MySQL 5.5 del
 * servidor del cliente.
 */
class ArmadorInformes
{
    /** Tope de paquetes cuyos eventos se leen para medir el tiempo entre gestiones. */
    private const TOPE_EVENTOS = 20000;

    /**
     * @param  list<int>  $mensajeros  vacío = todos
     * @param  list<int>  $clientes    vacío = todos
     * @return array<string, mixed>
     */
    public function armar(RangoInforme $rango, array $mensajeros = [], array $clientes = []): array
    {
        $entregas = $this->entregas($rango, $mensajeros, $clientes);
        $carga    = $this->carga($rango, $mensajeros, $clientes);
        $guias    = $this->guiasGeneradas($rango, $clientes);
        $ritmo    = $this->tiempoEntreGestiones($rango, $mensajeros);
        $dias     = $this->diasActivos($rango, $mensajeros, $clientes);

        $nombresMensajero = $this->nombresMensajero();
        $nombresCliente   = $this->nombresCliente();

        return [
            'rango'       => $rango->aArreglo(),
            'periodos'    => $this->periodosConEtiqueta($rango),

            'mensajeros'  => $this->filasMensajero($entregas, $carga, $ritmo, $dias, $nombresMensajero),
            'series'      => $this->serie($rango, $entregas, 'mensajero_id', $nombresMensajero),

            'estados'     => $this->matrizEstados($carga, $nombresMensajero),
            'consolidado' => $this->consolidado($entregas, $carga, $nombresMensajero, $nombresCliente),

            'clientes'       => $this->filasCliente($entregas, $carga, $guias, $nombresCliente),
            'series_cliente' => $this->serie($rango, $entregas, 'cliente_id', $nombresCliente),
            'series_guias'   => $this->serieGuias($rango, $guias, $nombresCliente),

            'urgentes'    => $this->urgentes($rango, $mensajeros, $clientes),
            'franjas'     => $this->porFranja($rango, $mensajeros, $clientes, $nombresMensajero),
        ];
    }

    // ------------------------------------------------------------- consultas

    /**
     * Lo entregado en el rango, al máximo detalle: periodo × mensajero × cliente.
     *
     * De aquí salen el informe diario, el promedio, el consolidado y la serie
     * por cliente. Una sola pasada.
     */
    private function entregas(RangoInforme $rango, array $mensajeros, array $clientes): Collection
    {
        $periodo = $rango->expresion('p.entregado_en');

        $q = DB::table('paquetes as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereNull('o.deleted_at')
            ->whereNotNull('p.entregado_en')
            ->whereBetween('p.entregado_en', [$rango->desde, $rango->hasta])
            ->selectRaw("$periodo as periodo")
            ->selectRaw('p.mensajero_id, o.cliente_id')
            ->selectRaw('COUNT(*) as entregadas')
            ->selectRaw("SUM(CASE WHEN o.criticidad = 'urgente' THEN 1 ELSE 0 END) as urgentes")
            /*
             * «A tiempo» solo se puede afirmar de lo que tenía compromiso. Las
             * guías sin `comprometida_en` no cuentan ni a favor ni en contra:
             * meterlas como incumplidas castigaría al mensajero por un dato que
             * no se capturó, y como cumplidas inflaría el indicador.
             */
            ->selectRaw('SUM(CASE WHEN o.comprometida_en IS NOT NULL THEN 1 ELSE 0 END) as con_compromiso')
            ->selectRaw('SUM(CASE WHEN o.comprometida_en IS NOT NULL AND p.entregado_en <= o.comprometida_en THEN 1 ELSE 0 END) as a_tiempo')
            ->selectRaw('SUM(CASE WHEN p.recolectado_en IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, p.recolectado_en, p.entregado_en) ELSE 0 END) as minutos_transito')
            ->selectRaw('SUM(CASE WHEN p.recolectado_en IS NOT NULL THEN 1 ELSE 0 END) as con_transito')
            ->groupByRaw("$periodo, p.mensajero_id, o.cliente_id");

        $this->acotar($q, $mensajeros, $clientes);

        return collect($q->get());
    }

    /** Foto por estado de lo radicado en el rango: estado × mensajero × cliente. */
    private function carga(RangoInforme $rango, array $mensajeros, array $clientes): Collection
    {
        $q = DB::table('paquetes as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereNull('o.deleted_at')
            ->whereBetween('p.created_at', [$rango->desde, $rango->hasta])
            ->selectRaw('p.estado, p.mensajero_id, o.cliente_id, COUNT(*) as cuantos')
            ->groupByRaw('p.estado, p.mensajero_id, o.cliente_id');

        $this->acotar($q, $mensajeros, $clientes);

        return collect($q->get());
    }

    /** Guías —no paquetes— generadas por cliente y periodo. Es la pregunta D11. */
    private function guiasGeneradas(RangoInforme $rango, array $clientes): Collection
    {
        $periodo = $rango->expresion('o.created_at');

        $q = DB::table('ordenes as o')
            ->whereNull('o.deleted_at')
            ->whereBetween('o.created_at', [$rango->desde, $rango->hasta])
            ->selectRaw("$periodo as periodo, o.cliente_id, COUNT(*) as guias")
            ->groupByRaw("$periodo, o.cliente_id");

        if ($clientes !== []) {
            $q->whereIn('o.cliente_id', $clientes);
        }

        return collect($q->get());
    }

    /** Días distintos en que cada mensajero entregó algo. Denominador del promedio. */
    private function diasActivos(RangoInforme $rango, array $mensajeros, array $clientes): Collection
    {
        $q = DB::table('paquetes as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereNull('o.deleted_at')
            ->whereNotNull('p.entregado_en')
            ->whereBetween('p.entregado_en', [$rango->desde, $rango->hasta])
            ->selectRaw('p.mensajero_id, COUNT(DISTINCT DATE(p.entregado_en)) as dias')
            ->groupBy('p.mensajero_id');

        $this->acotar($q, $mensajeros, $clientes);

        return collect($q->get())->keyBy('mensajero_id');
    }

    /**
     * Minutos promedio entre una gestión y la siguiente, por mensajero. (D3)
     *
     * Se calcula sobre la bitácora, que es donde está el dato real, y no sobre
     * los hitos del paquete: entre «recolectado» y «entregado» pueden haber
     * ocurrido tres novedades, y el promedio de hitos las borraría.
     *
     * El cálculo de la diferencia entre eventos consecutivos se hace en PHP
     * porque MySQL 5.5 no tiene funciones de ventana. A cambio se traen solo
     * dos columnas y con tope: un informe no puede tumbar la operación.
     */
    private function tiempoEntreGestiones(RangoInforme $rango, array $mensajeros): Collection
    {
        $q = DB::table('eventos_paquete as e')
            ->join('paquetes as p', 'p.id', '=', 'e.paquete_id')
            ->whereBetween('e.registrado_en', [$rango->desde, $rango->hasta])
            ->whereNotNull('p.mensajero_id')
            ->selectRaw('e.paquete_id, p.mensajero_id, e.registrado_en')
            ->orderBy('e.paquete_id')
            ->orderBy('e.registrado_en')
            ->limit(self::TOPE_EVENTOS);

        if ($mensajeros !== []) {
            $q->whereIn('p.mensajero_id', $mensajeros);
        }

        $acumulado = [];
        $anterior  = null;

        foreach ($q->get() as $fila) {
            if ($anterior !== null && $anterior->paquete_id === $fila->paquete_id) {
                $minutos = (strtotime($fila->registrado_en) - strtotime($anterior->registrado_en)) / 60;

                /*
                 * Se descartan los saltos de más de un día. No son el ritmo de
                 * trabajo de nadie: son el paquete que se quedó en bodega el
                 * fin de semana, y un solo caso así desplaza el promedio de
                 * toda la semana.
                 */
                if ($minutos >= 0 && $minutos <= 1440) {
                    $id = (int) $fila->mensajero_id;
                    $acumulado[$id]['minutos'] = ($acumulado[$id]['minutos'] ?? 0) + $minutos;
                    $acumulado[$id]['saltos']  = ($acumulado[$id]['saltos'] ?? 0) + 1;
                }
            }

            $anterior = $fila;
        }

        return collect($acumulado)->map(fn ($a) => $a['saltos'] > 0
            ? round($a['minutos'] / $a['saltos'], 1)
            : null);
    }

    /**
     * Las urgentes entregadas en el rango, una por una. (D9)
     *
     * Van como detalle y no como cifra porque la petición es que aparezcan
     * «como observación»: lo que se quiere saber no es cuántas hubo sino
     * cuáles fueron y qué tiempo costaron.
     */
    private function urgentes(RangoInforme $rango, array $mensajeros, array $clientes): array
    {
        $q = DB::table('paquetes as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->join('clientes as c', 'c.id', '=', 'o.cliente_id')
            ->leftJoin('mensajeros as m', 'm.id', '=', 'p.mensajero_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->whereNull('o.deleted_at')
            ->where('o.criticidad', 'urgente')
            ->whereNotNull('p.entregado_en')
            ->whereBetween('p.entregado_en', [$rango->desde, $rango->hasta])
            ->selectRaw('o.numero_guia, p.codigo, c.razon_social, u.nombre as mensajero')
            ->selectRaw('o.created_at as radicada_en, o.comprometida_en, p.entregado_en')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, o.created_at, p.entregado_en) as minutos_totales')
            ->selectRaw('TIMESTAMPDIFF(MINUTE, p.recolectado_en, p.entregado_en) as minutos_transito')
            ->orderByDesc('p.entregado_en')
            ->limit(500);

        $this->acotar($q, $mensajeros, $clientes);

        return collect($q->get())->map(fn ($u) => [
            'guia'              => $u->numero_guia,
            'codigo'            => $u->codigo,
            'cliente'           => $u->razon_social,
            'mensajero'         => $u->mensajero ?? 'Sin asignar',
            'radicada_en'       => $u->radicada_en,
            'comprometida_en'   => $u->comprometida_en,
            'entregada_en'      => $u->entregado_en,
            'minutos_totales'   => (int) $u->minutos_totales,
            'minutos_transito'  => $u->minutos_transito === null ? null : (int) $u->minutos_transito,
            'a_tiempo'          => $u->comprometida_en === null
                ? null
                : strtotime($u->entregado_en) <= strtotime($u->comprometida_en),
        ])->values()->all();
    }

    /**
     * Entregas por franja horaria y mensajero. (D4)
     *
     * Las franjas salen de un parámetro —`jornada.cortes_franja`— y no del
     * código: cuándo es «mediodía» lo decide la operación, cambia con el
     * contrato y no debería hacer falta un despliegue para moverlo.
     *
     * Se corta por la hora del reloj y no por horas transcurridas desde la
     * entrada de cada uno. Las dos cosas son defendibles, pero la pregunta que
     * se hace quien lee el informe es «¿qué se entregó antes de almorzar?», y
     * esa es una hora del reloj: con el otro criterio, dos mensajeros que
     * entraron a las 6 y a las 9 tendrían «mediodía» a horas distintas y la
     * suma de la columna no significaría nada.
     *
     * @return array{columnas: list<array{clave:string, etiqueta:string}>, filas: list<array<string, mixed>>}
     */
    private function porFranja(RangoInforme $rango, array $mensajeros, array $clientes, Collection $nombres): array
    {
        $cortes = $this->cortesDeFranja();

        /*
         * Un CASE por franja en vez de traer cada entrega y clasificarla en
         * PHP. El motor ya está recorriendo esas filas para los otros cortes;
         * hacerle una pasada más es más barato que traer veinte mil horas.
         */
        $q = DB::table('paquetes as p')
            ->join('ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereNull('o.deleted_at')
            ->whereNotNull('p.entregado_en')
            ->whereBetween('p.entregado_en', [$rango->desde, $rango->hasta])
            ->selectRaw('p.mensajero_id')
            ->selectRaw('COUNT(*) as total');

        foreach ($this->franjas($cortes) as $i => $f) {
            $condiciones = [];

            if ($f['desde'] !== null) {
                $condiciones[] = "TIME(p.entregado_en) >= '{$f['desde']}:00'";
            }

            if ($f['hasta'] !== null) {
                $condiciones[] = "TIME(p.entregado_en) < '{$f['hasta']}:00'";
            }

            $donde = $condiciones === [] ? '1=1' : implode(' AND ', $condiciones);

            $q->selectRaw("SUM(CASE WHEN $donde THEN 1 ELSE 0 END) as f$i");
        }

        $q->groupBy('p.mensajero_id');

        $this->acotar($q, $mensajeros, $clientes);

        $franjas = $this->franjas($cortes);

        $filas = collect($q->get())->map(function ($r) use ($franjas, $nombres) {
            $porFranja = [];

            foreach ($franjas as $i => $f) {
                $porFranja['f'.$i] = (int) ($r->{'f'.$i} ?? 0);
            }

            return [
                'mensajero_id' => $r->mensajero_id === null ? null : (int) $r->mensajero_id,
                'mensajero'    => $r->mensajero_id === null
                    ? 'Sin asignar'
                    : ($nombres[(int) $r->mensajero_id] ?? 'Mensajero '.$r->mensajero_id),
                'por_franja'   => $porFranja,
                'total'        => (int) $r->total,
            ];
        })->sortByDesc('total')->values()->all();

        return [
            'columnas' => array_map(
                fn (array $f, int $i) => ['clave' => 'f'.$i, 'etiqueta' => $f['etiqueta']],
                $franjas,
                array_keys($franjas),
            ),
            'filas' => $filas,
        ];
    }

    /**
     * Los cortes configurados, saneados.
     *
     * Se validan aquí y no se confía en el parámetro: es un campo de texto que
     * edita una persona, y un «12;00» mal escrito no puede acabar dentro de una
     * consulta. Si no queda ninguno válido, se usa el reparto por omisión.
     *
     * @return list<string>
     */
    private function cortesDeFranja(): array
    {
        $crudo = (string) Parametro::obtener('jornada.cortes_franja', '12:00,14:00');

        $cortes = collect(explode(',', $crudo))
            ->map(fn (string $c) => trim($c))
            ->filter(fn (string $c) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $c) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $cortes === [] ? ['12:00', '14:00'] : $cortes;
    }

    /**
     * Los tramos entre cortes, con su nombre.
     *
     * @param  list<string>  $cortes
     * @return list<array{desde: ?string, hasta: ?string, etiqueta: string}>
     */
    private function franjas(array $cortes): array
    {
        $franjas = [];
        $anterior = null;

        foreach ($cortes as $corte) {
            $franjas[] = [
                'desde'    => $anterior,
                'hasta'    => $corte,
                'etiqueta' => $anterior === null
                    ? "Hasta las $corte"
                    : "$anterior – $corte",
            ];

            $anterior = $corte;
        }

        $franjas[] = [
            'desde'    => $anterior,
            'hasta'    => null,
            'etiqueta' => $anterior === null ? 'Todo el día' : "Desde las $anterior",
        ];

        return $franjas;
    }

    private function acotar($q, array $mensajeros, array $clientes): void
    {
        if ($mensajeros !== []) {
            $q->whereIn('p.mensajero_id', $mensajeros);
        }

        if ($clientes !== []) {
            $q->whereIn('o.cliente_id', $clientes);
        }
    }

    // ----------------------------------------------------------- composición

    /** @return list<array{clave:string, etiqueta:string}> */
    private function periodosConEtiqueta(RangoInforme $rango): array
    {
        return array_map(
            fn (string $c) => ['clave' => $c, 'etiqueta' => $rango->etiqueta($c)],
            $rango->periodos()
        );
    }

    /** Una fila por mensajero: lo entregado, el ritmo y lo que tiene encima. (D1, D2, D3) */
    private function filasMensajero(
        Collection $entregas,
        Collection $carga,
        Collection $ritmo,
        Collection $dias,
        Collection $nombres,
    ): array {
        $ids = $entregas->pluck('mensajero_id')
            ->merge($carga->pluck('mensajero_id'))
            ->filter()
            ->unique();

        return $ids->map(function ($id) use ($entregas, $carga, $ritmo, $dias, $nombres) {
            $id   = (int) $id;
            $suyo = $entregas->where('mensajero_id', $id);
            $suya = $carga->where('mensajero_id', $id);

            $entregadas     = (int) $suyo->sum('entregadas');
            $conCompromiso  = (int) $suyo->sum('con_compromiso');
            $aTiempo        = (int) $suyo->sum('a_tiempo');
            $conTransito    = (int) $suyo->sum('con_transito');
            $diasActivos    = (int) ($dias->get($id)->dias ?? 0);

            return [
                'mensajero_id'   => $id,
                'mensajero'      => $nombres[$id] ?? 'Mensajero '.$id,
                'entregadas'     => $entregadas,
                'urgentes'       => (int) $suyo->sum('urgentes'),
                'dias_activos'   => $diasActivos,
                'promedio_dia'   => $diasActivos > 0 ? round($entregadas / $diasActivos, 1) : 0.0,
                'con_compromiso' => $conCompromiso,
                'a_tiempo'       => $aTiempo,
                'cumplimiento'   => $conCompromiso > 0 ? round($aTiempo * 100 / $conCompromiso, 1) : null,
                'minutos_transito' => $conTransito > 0
                    ? round($suyo->sum('minutos_transito') / $conTransito, 1)
                    : null,
                'minutos_entre_gestiones' => $ritmo->get($id),
                'asignadas'      => (int) $suya->sum('cuantos'),
                'en_curso'       => (int) $suya->whereIn('estado', $this->estadosEnCurso())->sum('cuantos'),
                'con_novedad'    => (int) $suya->where('estado', EstadoPaquete::Novedad->value)->sum('cuantos'),
                'devueltas'      => (int) $suya->where('estado', EstadoPaquete::Devuelto->value)->sum('cuantos'),
            ];
        })->sortByDesc('entregadas')->values()->all();
    }

    /** Una fila por cliente: lo que generó y lo que se le cumplió. (D11, D12, D13) */
    private function filasCliente(
        Collection $entregas,
        Collection $carga,
        Collection $guias,
        Collection $nombres,
    ): array {
        $ids = $entregas->pluck('cliente_id')
            ->merge($carga->pluck('cliente_id'))
            ->merge($guias->pluck('cliente_id'))
            ->filter()
            ->unique();

        return $ids->map(function ($id) use ($entregas, $carga, $guias, $nombres) {
            $id   = (int) $id;
            $suyo = $entregas->where('cliente_id', $id);
            $suya = $carga->where('cliente_id', $id);

            $conCompromiso = (int) $suyo->sum('con_compromiso');
            $aTiempo       = (int) $suyo->sum('a_tiempo');
            $conTransito   = (int) $suyo->sum('con_transito');

            return [
                'cliente_id'   => $id,
                'cliente'      => $nombres[$id] ?? 'Cliente '.$id,
                'guias'        => (int) $guias->where('cliente_id', $id)->sum('guias'),
                'paquetes'     => (int) $suya->sum('cuantos'),
                'entregadas'   => (int) $suyo->sum('entregadas'),
                'urgentes'     => (int) $suyo->sum('urgentes'),
                'en_curso'     => (int) $suya->whereIn('estado', $this->estadosEnCurso())->sum('cuantos'),
                'con_novedad'  => (int) $suya->where('estado', EstadoPaquete::Novedad->value)->sum('cuantos'),
                'devueltas'    => (int) $suya->where('estado', EstadoPaquete::Devuelto->value)->sum('cuantos'),
                'cumplimiento' => $conCompromiso > 0 ? round($aTiempo * 100 / $conCompromiso, 1) : null,
                'minutos_transito' => $conTransito > 0
                    ? round($suyo->sum('minutos_transito') / $conTransito, 1)
                    : null,
            ];
        })->sortByDesc('guias')->values()->all();
    }

    /**
     * La serie temporal, lista para pintar. (D1, D11, D13)
     *
     * Devuelve una entrada por sujeto —mensajero o cliente— con un valor por
     * periodo, incluidos los periodos en cero. La comparación de varios
     * clientes a la vez es exactamente esto con más de una entrada.
     */
    private function serie(RangoInforme $rango, Collection $datos, string $clave, Collection $nombres): array
    {
        $periodos = $rango->periodos();

        return $datos->pluck($clave)->filter()->unique()->map(function ($id) use ($datos, $clave, $periodos, $nombres) {
            $id    = (int) $id;
            $suyos = $datos->where($clave, $id)->groupBy('periodo');

            return [
                'id'      => $id,
                'nombre'  => $nombres[$id] ?? '#'.$id,
                'valores' => array_map(
                    fn (string $p) => (int) ($suyos[$p] ?? collect())->sum('entregadas'),
                    $periodos
                ),
                'total'   => (int) $datos->where($clave, $id)->sum('entregadas'),
            ];
        })->sortByDesc('total')->values()->all();
    }

    /** La serie de guías generadas por cliente, que no se cuenta por entregas. */
    private function serieGuias(RangoInforme $rango, Collection $guias, Collection $nombres): array
    {
        $periodos = $rango->periodos();

        return $guias->pluck('cliente_id')->filter()->unique()->map(function ($id) use ($guias, $periodos, $nombres) {
            $id    = (int) $id;
            $suyos = $guias->where('cliente_id', $id)->groupBy('periodo');

            return [
                'id'      => $id,
                'nombre'  => $nombres[$id] ?? '#'.$id,
                'valores' => array_map(
                    fn (string $p) => (int) ($suyos[$p] ?? collect())->sum('guias'),
                    $periodos
                ),
                'total'   => (int) $guias->where('cliente_id', $id)->sum('guias'),
            ];
        })->sortByDesc('total')->values()->all();
    }

    /** Mensajero × estado: en qué quedó lo que se le entregó a cada uno. (D7) */
    private function matrizEstados(Collection $carga, Collection $nombres): array
    {
        $columnas = array_map(
            fn (EstadoPaquete $e) => ['clave' => $e->value, 'etiqueta' => $e->etiqueta()],
            EstadoPaquete::cases()
        );

        $filas = $carga->pluck('mensajero_id')->unique()->map(function ($id) use ($carga, $nombres) {
            $suya = $carga->where('mensajero_id', $id);

            $porEstado = [];

            foreach (EstadoPaquete::cases() as $estado) {
                $porEstado[$estado->value] = (int) $suya->where('estado', $estado->value)->sum('cuantos');
            }

            return [
                'mensajero_id' => $id === null ? null : (int) $id,
                // El nulo es real y tiene que verse: son los paquetes radicados
                // en el rango que nadie recogió todavía.
                'mensajero'    => $id === null ? 'Sin asignar' : ($nombres[(int) $id] ?? 'Mensajero '.$id),
                'por_estado'   => $porEstado,
                'total'        => (int) $suya->sum('cuantos'),
            ];
        })->sortByDesc('total')->values()->all();

        return ['columnas' => $columnas, 'filas' => $filas];
    }

    /** Mensajero × cliente, que es la pregunta de quién le trabaja a quién. (D8) */
    private function consolidado(
        Collection $entregas,
        Collection $carga,
        Collection $nombresMensajero,
        Collection $nombresCliente,
    ): array {
        $pares = $entregas->map(fn ($e) => [$e->mensajero_id, $e->cliente_id])
            ->merge($carga->map(fn ($c) => [$c->mensajero_id, $c->cliente_id]))
            ->unique(fn ($par) => $par[0].'|'.$par[1]);

        return $pares->map(function ($par) use ($entregas, $carga, $nombresMensajero, $nombresCliente) {
            [$mid, $cid] = $par;

            $suyo = $entregas->where('mensajero_id', $mid)->where('cliente_id', $cid);
            $suya = $carga->where('mensajero_id', $mid)->where('cliente_id', $cid);

            return [
                'mensajero'   => $mid === null ? 'Sin asignar' : ($nombresMensajero[(int) $mid] ?? 'Mensajero '.$mid),
                'cliente'     => $nombresCliente[(int) $cid] ?? 'Cliente '.$cid,
                'asignadas'   => (int) $suya->sum('cuantos'),
                'entregadas'  => (int) $suyo->sum('entregadas'),
                'urgentes'    => (int) $suyo->sum('urgentes'),
                'con_novedad' => (int) $suya->where('estado', EstadoPaquete::Novedad->value)->sum('cuantos'),
            ];
        })->sortByDesc('entregadas')->values()->all();
    }

    /** @return list<string> */
    private function estadosEnCurso(): array
    {
        return collect(EstadoPaquete::cases())
            ->reject(fn (EstadoPaquete $e) => $e->esTerminal() || $e === EstadoPaquete::Novedad)
            ->map(fn (EstadoPaquete $e) => $e->value)
            ->all();
    }

    private function nombresMensajero(): Collection
    {
        return Mensajero::withTrashed()->with('user')->get()
            ->mapWithKeys(fn (Mensajero $m) => [$m->id => $m->nombre()]);
    }

    private function nombresCliente(): Collection
    {
        return Cliente::withTrashed()->get()
            ->mapWithKeys(fn (Cliente $c) => [$c->id => $c->razon_social]);
    }
}
