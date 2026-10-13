<?php

namespace App\Services\Dispositivos;

/**
 * Contrato de un proveedor de telemetría.
 *
 * Un adaptador tiene un solo trabajo: traducir la carga del fabricante a
 * `LecturaNormalizada`. No guarda nada, no decide nada, no conoce el dominio
 * de Avalia. Esa frontera es lo que hace que integrar un segundo proveedor sea un
 * archivo nuevo y no una cirugía.
 */
interface AdaptadorDispositivo
{
    /** Identificador del proveedor, tal como se guarda en `dispositivos.proveedor`. */
    public function nombre(): string;

    /**
     * Traduce la carga entrante. Un envío puede traer varias lecturas: los
     * dispositivos que estuvieron sin cobertura descargan en lote.
     *
     * @param  array<string, mixed>  $carga
     * @return array<int, LecturaNormalizada>
     */
    public function traducir(array $carga): array;

    /**
     * Verifica que el envío venga de quien dice venir.
     *
     * Recibe el cuerpo crudo —no el arreglo decodificado— porque la firma se
     * calcula sobre los bytes exactos: reserializar el JSON cambia espacios y
     * orden de claves, y la firma deja de coincidir.
     */
    public function verificarFirma(string $cuerpoCrudo, string $firmaRecibida, string $secreto): bool;
}
