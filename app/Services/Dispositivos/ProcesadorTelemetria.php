<?php

namespace App\Services\Dispositivos;

use App\Enums\EstadoPaquete;
use App\Models\Dispositivo;
use App\Models\Excursion;
use App\Models\Paquete;
use App\Models\Telemetria;
use App\Services\Trazabilidad\RegistradorEventos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué hace Avalia con una lectura de sonda.
 *
 * Tres trabajos, en este orden:
 *
 *   1. Guardar la lectura tal cual llegó, con su marca de tiempo original.
 *   2. Abrir, extender o cerrar el EPISODIO de excursión térmica. No cada
 *      lectura fuera de rango: el episodio completo con su duración.
 *   3. Cuando el episodio supera la tolerancia, anotarlo en la bitácora de
 *      cada paquete que iba a bordo. Ahí es donde la sonda deja de ser un
 *      aparato y se vuelve parte del soporte de entrega.
 */
class ProcesadorTelemetria
{
    public function __construct(private RegistradorEventos $registrador) {}

    /**
     * @param  array<int, LecturaNormalizada>  $lecturas
     * @return array{aceptadas:int, descartadas:int, excursiones_abiertas:int, excursiones_cerradas:int}
     */
    public function procesar(Dispositivo $dispositivo, array $lecturas): array
    {
        $resumen = ['aceptadas' => 0, 'descartadas' => 0, 'excursiones_abiertas' => 0, 'excursiones_cerradas' => 0];

        // En orden cronológico: un lote descargado tras perder cobertura puede
        // llegar desordenado, y procesarlo así abriría y cerraría episodios de
        // forma incoherente.
        usort($lecturas, fn ($a, $b) => $a->medidoEn <=> $b->medidoEn);

        foreach ($lecturas as $lectura) {
            if ($lectura->serial !== $dispositivo->serial) {
                $resumen['descartadas']++;

                continue;
            }

            $resultado = DB::transaction(fn () => $this->guardar($dispositivo, $lectura));

            $resumen['aceptadas']++;
            $resumen['excursiones_abiertas'] += $resultado['abrio'] ? 1 : 0;
            $resumen['excursiones_cerradas'] += $resultado['cerro'] ? 1 : 0;
        }

        return $resumen;
    }

    /** @return array{abrio:bool, cerro:bool} */
    private function guardar(Dispositivo $dispositivo, LecturaNormalizada $lectura): array
    {
        $fuera = $lectura->tieneTemperatura()
            && $dispositivo->mide_temperatura
            && ! $dispositivo->enRango($lectura->temperatura);

        Telemetria::create([
            'dispositivo_id' => $dispositivo->id,
            'temperatura'    => $lectura->temperatura,
            'humedad'        => $lectura->humedad,
            'latitud'        => $lectura->latitud,
            'longitud'       => $lectura->longitud,
            'precision_m'    => $lectura->precisionM,
            'velocidad_kmh'  => $lectura->velocidadKmh,
            'bateria'        => $lectura->bateria,
            'medido_en'      => $lectura->medidoEn,
            'fuera_de_rango' => $fuera,
            'crudo'          => $lectura->crudo,
        ]);

        $this->actualizarUltima($dispositivo, $lectura);

        return $this->gestionarExcursion($dispositivo, $lectura, $fuera);
    }

    /**
     * El «último valor» solo avanza con lecturas más nuevas. Sin esta guarda,
     * un lote atrasado que llega después de una lectura reciente dejaría el
     * tablero mostrando una temperatura vieja como si fuera la actual.
     */
    private function actualizarUltima(Dispositivo $dispositivo, LecturaNormalizada $lectura): void
    {
        if ($dispositivo->ultima_lectura_en !== null
            && $dispositivo->ultima_lectura_en->getTimestamp() >= $lectura->medidoEn->getTimestamp()) {
            return;
        }

        $dispositivo->forceFill(array_filter([
            'ultima_lectura_en'  => $lectura->medidoEn,
            'ultima_temperatura' => $lectura->temperatura,
            'ultima_latitud'     => $lectura->latitud,
            'ultima_longitud'    => $lectura->longitud,
            'ultima_bateria'     => $lectura->bateria,
        ], fn ($v) => $v !== null))->save();
    }

    /** @return array{abrio:bool, cerro:bool} */
    private function gestionarExcursion(Dispositivo $dispositivo, LecturaNormalizada $lectura, bool $fuera): array
    {
        $abierta = $dispositivo->excursionAbierta();

        // --- dentro de rango: si había episodio abierto, se cierra ----------
        if (! $fuera) {
            if ($abierta === null) {
                return ['abrio' => false, 'cerro' => false];
            }

            $abierta->fill([
                'fin'          => $lectura->medidoEn,
                'duracion_seg' => $abierta->inicio->diffInSeconds($lectura->medidoEn),
                'estado'       => 'cerrada',
            ])->save();

            $this->anotarEnBitacora($dispositivo, $abierta, 'cierre');

            return ['abrio' => false, 'cerro' => true];
        }

        // --- fuera de rango: se extiende el episodio o se abre uno ----------
        if ($abierta !== null) {
            $abierta->fill([
                'lecturas'     => $abierta->lecturas + 1,
                'valor_min'    => min($abierta->valor_min ?? $lectura->temperatura, $lectura->temperatura),
                'valor_max'    => max($abierta->valor_max ?? $lectura->temperatura, $lectura->temperatura),
                'duracion_seg' => $abierta->inicio->diffInSeconds($lectura->medidoEn),
            ])->save();

            // La anotación en bitácora se hace UNA vez, al cruzar la tolerancia.
            // Anotar cada lectura llenaría la trazabilidad de ruido y haría
            // ilegible justo el envío que hay que revisar.
            if ($abierta->superaTolerancia() && ! ($abierta->paquetes_afectados ?? [])) {
                $this->congelarAfectados($dispositivo, $abierta);
                $this->anotarEnBitacora($dispositivo, $abierta, 'confirmada');
            }

            return ['abrio' => false, 'cerro' => false];
        }

        Excursion::create([
            'dispositivo_id' => $dispositivo->id,
            'vehiculo_id'    => $dispositivo->vehiculo_id,
            'inicio'         => $lectura->medidoEn,
            'valor_min'      => $lectura->temperatura,
            'valor_max'      => $lectura->temperatura,
            'lecturas'       => 1,
            'estado'         => 'abierta',
        ]);

        return ['abrio' => true, 'cerro' => false];
    }

    /**
     * Paquetes que iban a bordo cuando ocurrió el episodio.
     *
     * Se congela en el momento, no se calcula después: para cuando alguien
     * revise la excursión, esos paquetes ya se entregaron y el cruce daría
     * vacío. Es la diferencia entre poder responderle al cliente y no poder.
     */
    private function congelarAfectados(Dispositivo $dispositivo, Excursion $excursion): void
    {
        $paquetes = $this->paquetesABordo($dispositivo);

        $excursion->paquetes_afectados = $paquetes->map(fn (Paquete $p) => [
            'paquete_id' => $p->id,
            'codigo'     => $p->codigo,
            'guia'       => $p->orden->numero_guia,
            'tipo'       => $p->tipo->value,
            'rango'      => $p->rangoTermico(),
        ])->values()->all();

        $excursion->save();
    }

    /** @return Collection<int, Paquete> */
    private function paquetesABordo(Dispositivo $dispositivo): Collection
    {
        if ($dispositivo->vehiculo_id === null) {
            return collect();
        }

        return Paquete::query()
            ->whereIn('estado', [
                EstadoPaquete::Recolectado->value,
                EstadoPaquete::EnTransito->value,
                EstadoPaquete::EnSitio->value,
            ])
            ->whereHas('mensajero', fn ($q) => $q->where('vehiculo_id', $dispositivo->vehiculo_id))
            ->with('orden')
            ->get();
    }

    /**
     * Anota el episodio en la bitácora de cada paquete afectado.
     *
     * Es una anotación, no una transición: el paquete sigue en tránsito. Pero
     * queda en su trazabilidad para siempre y sale en el soporte de entrega,
     * que es donde el cliente necesita verlo.
     */
    private function anotarEnBitacora(Dispositivo $dispositivo, Excursion $excursion, string $momento): void
    {
        $afectados = $excursion->paquetes_afectados ?? [];

        if ($afectados === []) {
            return;
        }

        foreach ($afectados as $fila) {
            $paquete = Paquete::find($fila['paquete_id']);

            if ($paquete === null) {
                continue;
            }

            $this->registrador->registrarAnotacion($paquete, 'excursion_termica', [
                'momento'        => $momento,
                'excursion_id'   => $excursion->id,
                'dispositivo'    => $dispositivo->serial,
                'proveedor'      => $dispositivo->proveedor,
                'vehiculo'       => $dispositivo->vehiculo?->placa,
                'rango_vigilado' => $dispositivo->rangoVigilado(),
                'valor_min'      => $excursion->valor_min,
                'valor_max'      => $excursion->valor_max,
                'duracion_min'   => $excursion->duracionMinutos(),
                'severidad'      => $excursion->severidad(),
            ], ['actor_tipo' => 'integracion']);
        }
    }
}
