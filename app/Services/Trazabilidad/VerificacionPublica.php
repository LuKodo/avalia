<?php

namespace App\Services\Trazabilidad;

use App\Models\Orden;
use App\Models\Paquete;
use App\Models\SelloDiario;
use Illuminate\Support\Carbon;

/**
 * Verificación de un soporte de entrega por parte de un tercero.
 *
 * El problema que resuelve: Avalia calcula una cadena de hashes y la sella a
 * diario, y hasta ahora nadie fuera del sistema podía verla. Un soporte que solo
 * Avalia puede validar no es una prueba — es una afirmación. El auditor de una
 * clínica, un revisor de INVIMA o el área de calidad del cliente tenían que
 * creernos porque sí.
 *
 * Aquí se abre esa verificación al público, con dos restricciones que la hacen
 * segura:
 *
 *   1. Hace falta el CÓDIGO impreso en el certificado. Sin él no se puede
 *      consultar, así que nadie enumera guías ajenas ni descubre qué le envía un
 *      laboratorio a qué clínica. El código es un HMAC del envío con la clave
 *      del sistema: no se adivina y no se deriva del número de guía.
 *
 *   2. La respuesta es deliberadamente pobre. Confirma integridad y poco más.
 *      Ni razón social, ni destino, ni quién recibió, ni qué se transportaba.
 *      Un verificador que de paso revele el negocio del cliente sería peor que
 *      no tenerlo.
 */
class VerificacionPublica
{
    public function __construct(private VerificadorCadena $verificador) {}

    /**
     * Código que se imprime en el certificado.
     *
     * Se deriva del identificador interno y no del número de guía visible: si
     * saliera de la guía, cualquiera que viera una podría calcular su código y
     * la primera restricción se caería sola.
     */
    public function codigoDe(Orden $orden): string
    {
        return strtoupper(substr(
            hash_hmac('sha256', "verificacion:{$orden->id}:{$orden->numero_guia}", $this->clave()),
            0,
            12
        ));
    }

    /**
     * Comprueba un certificado.
     *
     * @return array<string, mixed>
     */
    public function verificar(string $guia, string $codigo): array
    {
        $orden = Orden::where('numero_guia', trim($guia))->first();

        /*
         * Guía inexistente y código equivocado devuelven exactamente la misma
         * respuesta. Distinguirlas convertiría el verificador en un oráculo que
         * confirma qué números de guía existen, que es justo lo que la primera
         * restricción quiere evitar.
         */
        if ($orden === null || ! hash_equals($this->codigoDe($orden), strtoupper(trim($codigo)))) {
            return [
                'valido' => false,
                'motivo' => 'No hay ningún soporte que corresponda a esa guía y ese código de verificación.',
            ];
        }

        $orden->loadMissing(['paquetes.eventos', 'paquetes.entrega']);

        $secuencias = $orden->paquetes
            ->flatMap(fn (Paquete $p) => $p->eventos->pluck('secuencia'))
            ->filter();

        if ($secuencias->isEmpty()) {
            return [
                'valido' => false,
                'motivo' => 'El envío existe pero no tiene eventos registrados en la bitácora.',
            ];
        }

        $resultado = $this->verificador->verificar($secuencias->min(), $secuencias->max());
        $entregada = $orden->paquetes->map(fn (Paquete $p) => $p->entrega?->entregada_en)->filter()->max();

        return [
            'valido' => true,

            // Lo mínimo que permite al auditor confirmar que tiene el documento
            // correcto en la mano, sin describir el envío.
            'guia'          => $orden->numero_guia,
            'entregado_el'  => $entregada?->format('Y-m-d'),
            'paquetes'      => $orden->paquetes->count(),

            'cadena' => [
                'integra'   => $resultado['ok'],
                'eventos'   => $resultado['revisados'],
                'desde'     => (int) $secuencias->min(),
                'hasta'     => (int) $secuencias->max(),
                'algoritmo' => config('ltp.trazabilidad.algoritmo_hash'),
                // Solo se dice DÓNDE se rompió, nunca qué decía el evento roto.
                'primer_fallo' => $resultado['primer_fallo'],
            ],

            'sello' => $this->sello($entregada),

            'verificado_en' => now()->toIso8601String(),

            'como_funciona' => 'Cada evento de la bitácora encadena el hash del anterior. '
                .'Alterar uno rompe todos los siguientes, y el sello diario fija la cadena '
                .'con una firma que no depende de los datos que protege.',
        ];
    }

    /**
     * Estado del sello del día en que se entregó.
     *
     * Es la segunda capa: la cadena por sí sola demuestra que nadie tocó un
     * eslabón suelto, pero alguien con acceso total podría recalcularla entera.
     * El sello, firmado con una clave que vive fuera de la base, es lo que cierra
     * esa puerta — y por eso interesa saber si existe y si ya se exportó.
     *
     * @return array<string, mixed>|null
     */
    private function sello(?Carbon $entregada): ?array
    {
        if ($entregada === null) {
            return null;
        }

        $sello = SelloDiario::whereDate('fecha', $entregada->toDateString())->first();

        if ($sello === null) {
            return [
                'existe' => false,
                'nota'   => 'La jornada de la entrega todavía no ha sido sellada. '
                    .'El sello se calcula al cierre del día.',
            ];
        }

        return [
            'existe'        => true,
            'fecha'         => $sello->fecha->format('Y-m-d'),
            'total_eventos' => $sello->total_eventos,
            'hash_raiz'     => $sello->hash_raiz,
            'firmado'       => $sello->firma !== null,
            'exportado'     => (bool) $sello->exportado,
            // Sin exportar fuera del servidor, el sello protege menos de lo que
            // parece. Decirlo es más honesto que mostrar un visto bueno.
            'proteccion_completa' => $sello->protegeDeVerdad(),
        ];
    }

    private function clave(): string
    {
        return (string) (config('ltp.trazabilidad.sello_clave') ?: config('app.key'));
    }
}
