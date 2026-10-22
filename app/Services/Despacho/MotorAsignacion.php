<?php

namespace App\Services\Despacho;

use App\Enums\EstadoPaquete;
use App\Models\Asignacion;
use App\Models\Mensajero;
use App\Models\Parametro;
use App\Models\Paquete;
use App\Models\User;
use App\Services\Trazabilidad\RegistradorEventos;
use App\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asignación de servicios a mensajeros.
 *
 * Dos niveles, en este orden y no al revés:
 *
 *   1. FILTRO DURO — descarta a quien simplemente no puede hacer el servicio.
 *      No es ponderable ni negociable: un paquete refrigerado en una moto no es
 *      una mala asignación, es una imposibilidad.
 *   2. PUNTAJE PONDERADO — ordena entre los que sí pueden. Los pesos son
 *      configurables desde el panel porque la operación los va a ajustar en las
 *      primeras semanas y eso no puede exigir un despliegue.
 *
 * El nivel 3 (optimización global con OR-Tools sobre el lote completo del día)
 * llega en la fase 3, cuando existan tres a seis meses de datos reales. Sin esa
 * línea base no hay forma de saber si el optimizador mejora algo.
 */
class MotorAsignacion
{
    public function __construct(private RegistradorEventos $registrador) {}

    /**
     * Candidatos ordenados por puntaje.
     *
     * @return Collection<int, array{mensajero: Mensajero, puntaje: int, desglose: array<string, int>, distancia_km: float|null}>
     */
    public function candidatos(Paquete $paquete, int $limite = 5): Collection
    {
        $this->precargar($paquete);

        return $this->filtroDuro($paquete)
            ->map(fn (Mensajero $m) => $this->evaluar($m, $paquete))
            ->sortByDesc('puntaje')
            ->take($limite)
            ->values();
    }

    /**
     * Filtro duro. Cada condición aquí responde a una restricción física, legal
     * o contractual — nunca a una preferencia.
     *
     * @return Collection<int, Mensajero>
     */
    public function filtroDuro(Paquete $paquete): Collection
    {
        $this->precargar($paquete);

        $origen  = $paquete->orden->origen;
        $zonaId  = $paquete->orden->zonaDestino()?->id;
        $radio   = (float) Parametro::obtener('asignacion.radio_km', config('ltp.asignacion.radio_busqueda_km', 15));

        $consulta = Mensajero::query()
            ->disponibles()
            ->with('vehiculo', 'user');

        // Prefiltro por caja envolvente: descarta la gran mayoría de filas con
        // un índice común, y el haversine solo corre sobre las que quedan.
        if ($origen?->tieneCoordenada()) {
            $caja = Geo::caja($origen->latitud, $origen->longitud, $radio);

            $consulta->whereBetween('ultima_latitud', [$caja['lat_min'], $caja['lat_max']])
                ->whereBetween('ultima_longitud', [$caja['lon_min'], $caja['lon_max']]);
        }

        return $consulta->get()
            ->filter(fn (Mensajero $m) => $this->motivoDescarte($m, $paquete, $zonaId) === null)
            ->values();
    }

    /**
     * Cuantos mensajeros descarta cada condicion, y por que.
     *
     * Existe porque decir «0 de 6 pueden hacerlo» sin decir por que obliga a
     * adivinar. La pantalla mostraba una lista fija de sospechosos -turnos,
     * documentos, refrigeracion- que no tenia por que incluir la causa real,
     * y mandaba a revisar donde no era.
     *
     * @return array{total:int, viables:int, descartes:array<int, array{motivo:string, cuantos:int}>}
     */
    public function diagnostico(Paquete $paquete): array
    {
        $this->precargar($paquete);

        $zonaId  = $paquete->orden->zonaDestino()?->id;
        $enTurno = Mensajero::disponibles()->with('vehiculo', 'user')->get();

        $motivos = [];
        $viables = 0;

        foreach ($enTurno as $m) {
            $motivo = $this->motivoDescarte($m, $paquete, $zonaId);

            if ($motivo === null) {
                $viables++;

                continue;
            }

            $motivos[$motivo] = ($motivos[$motivo] ?? 0) + 1;
        }

        // De mayor a menor: lo primero que se lee es lo que mas estorba.
        arsort($motivos);

        return [
            'total'     => $enTurno->count(),
            'viables'   => $viables,
            'descartes' => array_map(
                fn ($motivo, $cuantos) => ['motivo' => $motivo, 'cuantos' => $cuantos],
                array_keys($motivos),
                array_values($motivos)
            ),
        ];
    }

    /**
     * El motivo por el que este mensajero no puede llevar este paquete, o null
     * si si puede.
     *
     * Una sola copia de las reglas, consultada por el filtro y por el
     * diagnostico: si se duplicaran, un dia dirian cosas distintas.
     */
    private function motivoDescarte(Mensajero $m, Paquete $paquete, ?int $zonaId): ?string
    {
        $vehiculo = $m->vehiculo;

        if ($vehiculo === null) {
            return 'Sin vehículo asignado';
        }

        if (! $vehiculo->activo) {
            return 'Vehículo inactivo';
        }

        if (! $vehiculo->documentosAlDia()) {
            return 'Documentos del vehículo vencidos';
        }

        if ($paquete->exigeCadenaFrio() && ! $vehiculo->refrigeracion) {
            return 'Vehículo sin refrigeración';
        }

        if ($paquete->tipo->exigeCertificacion() && ! $m->certificacionVigente()) {
            return 'Sin certificación de control especial';
        }

        if ($m->capacidadDisponibleKg() < $paquete->pesoParaCapacidad()) {
            return 'Sin capacidad disponible';
        }

        if (! $m->habilitadoEnZona($zonaId)) {
            return 'No habilitado en la zona de destino';
        }

        /*
         * Un telefono sin reporte reciente es un mensajero al que no podemos
         * hacerle seguimiento: no recibe carga nueva.
         *
         * El umbral sale del panel y no del codigo. Estaba fijo en 15 minutos,
         * y eso deja la bandeja entera sin candidatos en cuanto nadie lleva la
         * aplicacion de campo abierta -un entorno de pruebas, un domingo, o el
         * dia que el GPS no funciona porque el sitio aun no tiene HTTPS-.
         * Subirlo es una decision de operacion, no un cambio de programa.
         */
        $umbral = (int) Parametro::obtener('asignacion.sin_senal_min', 15);

        if ($umbral > 0 && $m->sinSenal($umbral)) {
            return "Sin reporte GPS en los últimos {$umbral} min";
        }

        return null;
    }

    /**
     * @return array{mensajero: Mensajero, puntaje: int, desglose: array<string, int>, distancia_km: float|null}
     */
    /**
     * El motor recibe paquetes desde caminos muy distintos —la bandeja, la
     * creación de una orden, el seeder— y no todos llegan con sus relaciones
     * cargadas. Precargar aquí evita tanto el N+1 como la excepción de carga
     * perezosa que `preventLazyLoading` levanta en desarrollo.
     */
    private function precargar(Paquete $paquete): void
    {
        $paquete->loadMissing(['orden.origen', 'orden.destino.zona', 'orden.destinatario.zona']);
    }

    public function evaluar(Mensajero $mensajero, Paquete $paquete): array
    {
        $this->precargar($paquete);

        $pesos  = config('ltp.asignacion.pesos');
        $origen = $paquete->orden->origen;
        $radio  = (float) config('ltp.asignacion.radio_busqueda_km', 15);

        $distancia = $origen?->tieneCoordenada() && $mensajero->tieneCoordenada()
            ? $mensajero->distanciaKm($origen->latitud, $origen->longitud)
            : null;

        $sub = [
            'cercania'  => $this->puntajeCercania($distancia, $radio),
            'holgura'   => $this->puntajeHolgura($paquete, $distancia),
            'carga'     => $this->puntajeCarga($mensajero),
            'ruta'      => $this->puntajeRuta($mensajero, $paquete),
            'desempeno' => (int) round(min(100, max(0, $mensajero->indice_cumplimiento))),
        ];

        $total = 0;
        foreach ($sub as $factor => $valor) {
            $total += $valor * ($pesos[$factor] ?? 0) / 100;
        }

        return [
            'mensajero'    => $mensajero,
            'puntaje'      => (int) round($total),
            'desglose'     => $sub,
            'distancia_km' => $distancia === null ? null : round($distancia, 2),
        ];
    }

    /** Cercanía al origen: decae linealmente hasta el radio de búsqueda. */
    private function puntajeCercania(?float $distanciaKm, float $radio): int
    {
        if ($distanciaKm === null) {
            return 50;   // sin coordenada no se premia ni se castiga
        }

        return (int) round(max(0, 100 * (1 - min(1, $distanciaKm / max(0.1, $radio)))));
    }

    /**
     * Holgura frente a la ventana comprometida. Un servicio que llega justo no
     * vale lo mismo que uno que llega con margen: si algo sale mal en el camino,
     * el margen es lo único que evita el incumplimiento.
     */
    private function puntajeHolgura(Paquete $paquete, ?float $distanciaKm): int
    {
        $tope = $paquete->orden->comprometida_en ?? $paquete->orden->ventana_hasta;

        if ($tope === null) {
            return 70;
        }

        $velocidad = (float) config('ltp.asignacion.velocidad_media_kmh', 22);
        $viaje     = Geo::minutosEstimados($distanciaKm ?? 5.0, $velocidad);
        $restantes = now()->diffInMinutes($tope, false);
        $holgura   = $restantes - $viaje;

        if ($holgura <= 0) {
            return 0;      // no alcanza: el puntaje lo dice, el operador decide
        }

        // 90 minutos de holgura ya es margen de sobra; más no suma.
        return (int) round(min(100, ($holgura / 90) * 100));
    }

    /** Reparte trabajo: quien va más cargado baja en la lista. */
    private function puntajeCarga(Mensajero $mensajero): int
    {
        $capacidad = (float) ($mensajero->vehiculo?->capacidad_kg ?: 1);
        $ocupacion = min(1, (float) $mensajero->carga_actual_kg / $capacidad);
        $porPiezas = min(1, $mensajero->paquetesActivos() / 12);

        return (int) round(100 * (1 - max($ocupacion, $porPiezas)));
    }

    /**
     * Compatibilidad de ruta: cuánto desvío agrega el nuevo servicio respecto a
     * lo que el mensajero ya tiene en curso. Sin entregas activas, el servicio
     * no desvía nada, así que puntúa alto.
     */
    private function puntajeRuta(Mensajero $mensajero, Paquete $paquete): int
    {
        $destino = $paquete->orden->destino;

        if (! $destino?->tieneCoordenada()) {
            return 60;
        }

        $activos = $mensajero->paquetes()
            ->enCurso()
            ->with('orden.destino', 'orden.destinatario')
            ->get()
            ->map(fn (Paquete $p) => $p->orden->destino)
            ->filter(fn ($s) => $s?->tieneCoordenada());

        if ($activos->isEmpty()) {
            return 85;
        }

        $minima = $activos->min(fn ($s) => Geo::distanciaKm(
            $s->latitud, $s->longitud, $destino->latitud, $destino->longitud
        ));

        // A menos de 2 km de un destino que ya tenía, es prácticamente gratis.
        return (int) round(max(0, 100 * (1 - min(1, $minima / 8))));
    }

    /**
     * Ejecuta la asignación y deja registro. Si es manual y sustituye a una
     * sugerencia automática, el motivo es obligatorio: esos motivos son la
     * materia prima para calibrar los pesos.
     */
    public function asignar(
        Paquete $paquete,
        Mensajero $mensajero,
        ?User $operador = null,
        string $origen = 'automatica',
        ?int $puntaje = null,
        ?array $desglose = null,
        ?string $motivo = null,
    ): Asignacion {
        return DB::transaction(function () use ($paquete, $mensajero, $operador, $origen, $puntaje, $desglose, $motivo) {
            $anterior = $paquete->asignaciones()->whereNull('liberada_en')->latest('asignada_en')->first();

            if ($anterior) {
                $anterior->update(['liberada_en' => now()]);
                $anterior->mensajero->decrement('carga_actual_kg', $paquete->pesoParaCapacidad());
            }

            $asignacion = Asignacion::create([
                'paquete_id'       => $paquete->id,
                'mensajero_id'     => $mensajero->id,
                'asignada_por'     => $operador?->id,
                'origen'           => $origen,
                'puntaje'          => $puntaje,
                'desglose_puntaje' => $desglose,
                'motivo_anulacion' => $motivo,
                'sustituye_a'      => $anterior?->id,
                'asignada_en'      => now(),
            ]);

            $paquete->mensajero_id = $mensajero->id;
            $paquete->save();

            $mensajero->increment('carga_actual_kg', $paquete->pesoParaCapacidad());

            $this->registrador->registrar($paquete, EstadoPaquete::Asignado, [
                'actor' => $operador,
                'datos' => [
                    'mensajero_id'     => $mensajero->id,
                    'mensajero_nombre' => $mensajero->nombre(),
                    'origen'           => $origen,
                    'puntaje'          => $puntaje,
                    'desglose'         => $desglose,
                    'motivo_anulacion' => $motivo,
                ],
            ]);

            return $asignacion;
        });
    }

    /** Asignación automática: toma el mejor candidato si supera el umbral. */
    public function asignarAutomatico(Paquete $paquete, int $umbralMinimo = 45): ?Asignacion
    {
        $mejor = $this->candidatos($paquete, 1)->first();

        if ($mejor === null || $mejor['puntaje'] < $umbralMinimo) {
            return null;   // sin candidato viable: queda en la bandeja del operador
        }

        return $this->asignar(
            paquete: $paquete,
            mensajero: $mejor['mensajero'],
            origen: 'automatica',
            puntaje: $mejor['puntaje'],
            desglose: $mejor['desglose'],
        );
    }
}
