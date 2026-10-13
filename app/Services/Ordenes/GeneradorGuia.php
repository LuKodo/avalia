<?php

namespace App\Services\Ordenes;

use Illuminate\Support\Facades\DB;

/**
 * Generación del número de guía Avalia.
 *
 * Formato AVL-AAMMDD-NNNN. Se lee en voz alta por teléfono sin ambigüedad, cabe
 * en una etiqueta pequeña y su prefijo de fecha permite ubicar un envío en el
 * tiempo sin consultar nada. El consecutivo es diario, no global: reiniciarlo
 * cada día mantiene el número corto durante años.
 */
class GeneradorGuia
{
    public function generar(?\DateTimeInterface $fecha = null): string
    {
        $fecha    = $fecha ?? now();
        $prefijo  = config('ltp.guia.prefijo', 'AVL');
        $sello    = $fecha->format('ymd');
        $longitud = (int) config('ltp.guia.longitud_consecutivo', 4);

        // El consecutivo se calcula dentro de una transacción con bloqueo sobre
        // el máximo del día: dos órdenes creadas en el mismo milisegundo no
        // pueden recibir la misma guía.
        return DB::transaction(function () use ($prefijo, $sello, $longitud) {
            $ultima = DB::table('ordenes')
                ->where('numero_guia', 'like', "{$prefijo}-{$sello}-%")
                ->lockForUpdate()
                ->orderByDesc('numero_guia')
                ->value('numero_guia');

            $consecutivo = $ultima === null
                ? 1
                : ((int) substr($ultima, strrpos($ultima, '-') + 1)) + 1;

            return sprintf('%s-%s-%0'.$longitud.'d', $prefijo, $sello, $consecutivo);
        });
    }

    /**
     * Código de la etiqueta física de un paquete. Deriva de la guía para que,
     * leyendo el código de la caja, se sepa de inmediato a qué envío pertenece
     * incluso sin conexión.
     */
    public function codigoPaquete(string $guia, int $consecutivo): string
    {
        return sprintf('%s-P%02d', $guia, $consecutivo);
    }
}
