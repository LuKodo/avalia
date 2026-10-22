<?php

namespace App\Http\Controllers\Api;

use App\Jobs\EnviarSoporteACliente;
use App\Models\Evidencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Lo que no llegó al repositorio del cliente.
 *
 * Existe porque agotados los reintentos el trabajo cae en `failed_jobs` y
 * **nadie mira `failed_jobs`**. Una integración que falla en silencio es peor
 * que no tenerla: el cliente cree que recibe los soportes, nosotros creemos que
 * los mandamos, y la discrepancia aparece meses después en una auditoría.
 *
 * Esto es la pantalla que hace visible el fallo y permite arreglarlo sin entrar
 * por consola.
 */
class IntegracionController extends Controller
{
    /**
     * Soportes de clientes con integración que todavía no tienen acuse.
     *
     * Se listan los que ya se intentaron al menos una vez, con o sin acuse. Una
     * evidencia recién capturada que todavía está en cola no es un problema, y
     * mezclarla aquí convertiría esta pantalla en ruido que nadie mira —
     * exactamente el destino de `failed_jobs`.
     */
    public function pendientes(): JsonResponse
    {
        $filas = Evidencia::query()
            /*
             * Se listan también los CONFIRMADOS, no solo los que fallaron.
             *
             * Un acuse no es una garantía: su API puede responder 201 y el
             * archivo no aparecer del otro lado. Pasó. Si esta pantalla solo
             * enseñara los fallos, el único caso que no se puede detectar desde
             * fuera sería además el único que no se puede reparar desde aquí.
             */
            ->where(fn ($q) => $q->whereNotNull('entregada_al_cliente_en')->orWhere('intentos_envio', '>', 0))
            ->whereHas('paquete.orden.cliente', fn ($q) => $q->where('integracion_activa', true))
            ->with('paquete.orden.cliente', 'paquete.orden.referencias')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(fn (Evidencia $e) => [
                'evidencia_id' => $e->id,
                'tipo'         => $e->tipo,
                'guia'         => $e->paquete->orden->numero_guia,
                'codigo'       => $e->paquete->codigo,
                'cliente'      => $e->paquete->orden->cliente->razon_social,
                'referencia'   => $e->paquete->orden->referencias->first()?->valor,
                'intentos'     => (int) $e->intentos_envio,
                'ultimo_error' => $e->ultimo_error_envio,
                'desde'        => $e->updated_at?->toIso8601String(),
                'enviada'      => $e->entregada_al_cliente_en !== null,
                'enviada_en'   => $e->entregada_al_cliente_en?->toIso8601String(),
                'nombre_externo' => $e->referencia_externa,
            ]);

        return response()->json([
            'total'      => $filas->count(),
            'pendientes' => $filas->values(),
        ]);
    }

    /**
     * Vuelve a encolar un envío.
     *
     * Sin condiciones sobre el motivo del fallo anterior: quien pulsa esto
     * acaba de arreglar algo —la credencial, el bucket, el servidor del
     * cliente— y sabe más que nosotros sobre si tiene sentido reintentar.
     */
    public function reenviar(Evidencia $evidencia): JsonResponse
    {
        /*
         * Reenviar uno ya confirmado es legítimo y hay que poder hacerlo.
         *
         * El acuse dice que su servidor contestó que sí, no que el archivo esté
         * donde debe. Cuando el cliente avisa de que nunca lo recibió, la
         * respuesta no puede ser «pues consta como enviado»: hay que poder
         * repetirlo. Se borra el acuse primero para que el trabajo no se salte
         * a sí mismo —comprueba precisamente eso para no duplicar—.
         *
         * El archivo sube con el mismo nombre, así que reemplaza al anterior
         * en el bucket en vez de dejar una copia suelta.
         */
        if ($evidencia->entregada_al_cliente_en !== null) {
            $evidencia->forceFill([
                'entregada_al_cliente_en' => null,
                'ultimo_error_envio' => null,
            ])->save();
        }

        EnviarSoporteACliente::dispatch($evidencia->id);

        return response()->json(['encolado' => true]);
    }
}
