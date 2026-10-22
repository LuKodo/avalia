<?php

namespace App\Enums;

/**
 * Catálogo de novedades. Se tipifica desde el inicio porque es el insumo directo
 * de los indicadores de calidad y de la conversación comercial con el cliente:
 * «12 % de novedades» no sirve para nada; «8 % de receptor ausente en la sede X»
 * sí permite actuar.
 */
enum TipoNovedad: string
{
    case ReceptorAusente     = 'receptor_ausente';
    case DireccionErrada     = 'direccion_errada';
    case RechazoReceptor     = 'rechazo_receptor';
    case RupturaCadenaFrio   = 'ruptura_cadena_frio';
    case EmpaqueDanado       = 'empaque_danado';
    case AccesoRestringido   = 'acceso_restringido';
    case SedeCerrada         = 'sede_cerrada';
    case DocumentoNoCoincide = 'documento_no_coincide';
    case AccidenteVehiculo   = 'accidente_vehiculo';
    case Otro                = 'otro';

    /** Novedades imputables a Avalia: cuentan contra el indicador de cumplimiento. */
    public function esImputableALtp(): bool
    {
        return in_array($this, [
            self::RupturaCadenaFrio,
            self::EmpaqueDanado,
            self::AccidenteVehiculo,
        ], true);
    }

    public function exigeEvidenciaFotografica(): bool
    {
        return in_array($this, [
            self::RupturaCadenaFrio,
            self::EmpaqueDanado,
            self::AccidenteVehiculo,
            self::SedeCerrada,
        ], true);
    }

    /**
     * Novedades tras las cuales el paquete vuelve al centro de distribución.
     *
     * No es una decisión técnica sino de operación: si nadie recibió, la
     * dirección estaba mal o el receptor rechazó el envío, la caja no se queda
     * dando vueltas en el vehículo ni se abandona en la puerta —son
     * medicamentos—. Regresa a Avalia y allí se decide si se reprograma o se
     * devuelve al cliente.
     *
     * Las demás no implican retorno por sí solas: una ruptura de cadena de frío
     * o un empaque dañado pueden seguir su camino o no, y eso lo mira quien
     * sabe de producto, no el enum.
     */
    public function exigeRetornoACentro(): bool
    {
        return in_array($this, [
            self::ReceptorAusente,
            self::DireccionErrada,
            self::RechazoReceptor,
            self::AccesoRestringido,
            self::SedeCerrada,
        ], true);
    }

    /** Severidad para el semáforo de alertas: warning | serious | critical. */
    public function severidad(): string
    {
        return match ($this) {
            self::RupturaCadenaFrio, self::AccidenteVehiculo => 'critical',
            self::EmpaqueDanado, self::DocumentoNoCoincide   => 'serious',
            default                                          => 'warning',
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::ReceptorAusente     => 'Receptor ausente',
            self::DireccionErrada     => 'Dirección errada',
            self::RechazoReceptor     => 'Rechazo del receptor',
            self::RupturaCadenaFrio   => 'Ruptura de cadena de frío',
            self::EmpaqueDanado       => 'Empaque dañado',
            self::AccesoRestringido   => 'Acceso restringido a la sede',
            self::SedeCerrada         => 'Sede cerrada',
            self::DocumentoNoCoincide => 'Documento no coincide',
            self::AccidenteVehiculo   => 'Accidente del vehículo',
            self::Otro                => 'Otro',
        };
    }

    /** @return array<int, array{valor:string, etiqueta:string, severidad:string, imputable:bool}> */
    public static function catalogo(): array
    {
        return array_map(fn (self $e) => [
            'valor'     => $e->value,
            'etiqueta'  => $e->etiqueta(),
            'severidad' => $e->severidad(),
            'imputable' => $e->esImputableALtp(),
        ], self::cases());
    }
}
