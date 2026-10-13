<?php

namespace App\Services\Geo;

use App\Models\Orden;
use App\Models\RutaPlaneada;
use Illuminate\Support\Carbon;

/**
 * Decide cuándo hay que trazar la ruta de un envío, y la guarda.
 *
 * La regla de oro está en la migración: una ruta ya trazada NO se vuelve a
 * calcular porque sí. Solo se rehace si cambiaron los extremos —el cliente
 * corrigió una dirección, el envío se redirigió— y entonces queda escrito por
 * qué. Recalcular sin motivo borraría la evidencia del desvío que se quería
 * detectar.
 */
class PlanificadorRuta
{
    public function __construct(private readonly Ruteador $ruteador)
    {
    }

    /**
     * Devuelve la ruta del envío, trazándola si hace falta.
     *
     * Devuelve `null` cuando falta alguna coordenada. Eso no es un fallo: una
     * sede sin coordenada verificada existe, y la pantalla debe decir «no hay
     * ruta trazada» en lugar de dibujar una línea inventada desde el centro de
     * la ciudad.
     */
    public function paraOrden(Orden $orden, bool $forzar = false): ?RutaPlaneada
    {
        $origen  = $orden->origen;
        $destino = $orden->puntoDestino();

        if ($origen === null || $destino === null) {
            return null;
        }

        if (! $origen->tieneCoordenada() || ! $destino->tieneCoordenada()) {
            return null;
        }

        $existente = RutaPlaneada::where('orden_id', $orden->id)->first();

        if ($existente !== null && ! $forzar) {
            $motivo = $this->cambiaronLosExtremos($existente, $origen, $destino);

            if ($motivo === null) {
                return $existente;
            }

            return $this->trazarYGuardar($orden, $origen, $destino, $motivo, $existente);
        }

        return $this->trazarYGuardar(
            $orden,
            $origen,
            $destino,
            $forzar && $existente !== null ? 'Recálculo solicitado' : null,
            $existente
        );
    }

    /**
     * ¿Se movió alguno de los dos extremos lo suficiente para invalidar la ruta?
     *
     * El margen de 25 m absorbe el redondeo decimal y las correcciones mínimas
     * de una coordenada; por debajo de eso la ruta trazada sigue siendo válida y
     * rehacerla solo gastaría una llamada al servicio de ruteo.
     */
    private function cambiaronLosExtremos(RutaPlaneada $ruta, $origen, $destino): ?string
    {
        $desplazamientoOrigen = \App\Support\Geo::distanciaMetros(
            $ruta->origen_lat, $ruta->origen_lon, $origen->latitud, $origen->longitud
        );

        if ($desplazamientoOrigen > 25) {
            return "El origen se movió {$desplazamientoOrigen} m";
        }

        $desplazamientoDestino = \App\Support\Geo::distanciaMetros(
            $ruta->destino_lat, $ruta->destino_lon, $destino->latitud, $destino->longitud
        );

        if ($desplazamientoDestino > 25) {
            return "El destino se movió {$desplazamientoDestino} m";
        }

        return null;
    }

    private function trazarYGuardar(
        Orden $orden,
        $origen,
        $destino,
        ?string $motivo,
        ?RutaPlaneada $existente,
    ): RutaPlaneada {
        $trazo = $this->ruteador->trazar(
            $origen->latitud, $origen->longitud,
            $destino->latitud, $destino->longitud,
        );

        $datos = [
            'origen_lat'       => $origen->latitud,
            'origen_lon'       => $origen->longitud,
            'destino_lat'      => $destino->latitud,
            'destino_lon'      => $destino->longitud,
            'geometria'        => $trazo['geometria'],
            'distancia_m'      => $trazo['distancia_m'],
            'duracion_seg'     => $trazo['duracion_seg'],
            'proveedor'        => $trazo['proveedor'],
            'calculada_en'     => Carbon::now(),
            'motivo_recalculo' => $motivo,
        ];

        if ($existente !== null) {
            $existente->update($datos);

            return $existente->refresh();
        }

        return RutaPlaneada::create($datos + ['orden_id' => $orden->id]);
    }
}
