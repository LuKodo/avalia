<?php

namespace App\Services\Trazabilidad;

use App\Models\EventoPaquete;
use App\Models\SelloDiario;
use Illuminate\Support\Facades\DB;

/**
 * Verificación de la cadena de auditoría.
 *
 * Corre cada noche y alerta ante la primera discrepancia. Verificar la cadena
 * no requiere comparar contra un respaldo: la manipulación de un registro
 * invalida su propio hash y el de todos los posteriores.
 */
class VerificadorCadena
{
    /**
     * @return array{ok:bool, revisados:int, primer_fallo:?array{secuencia:int, id:string, motivo:string}}
     */
    public function verificar(?int $desde = null, ?int $hasta = null): array
    {
        $revisados = 0;
        $fallo     = null;
        $anterior  = null;

        $consulta = EventoPaquete::query()->orderBy('secuencia');

        if ($desde !== null) {
            $consulta->where('secuencia', '>=', $desde);
        }

        if ($hasta !== null) {
            $consulta->where('secuencia', '<=', $hasta);
        }

        foreach ($consulta->lazy(500) as $evento) {
            $revisados++;

            // Hueco en la secuencia: alguien eliminó filas saltándose el
            // disparador, o la cadena se escribió por fuera del registrador.
            if ($anterior !== null && $evento->secuencia !== $anterior->secuencia + 1) {
                $fallo = [
                    'secuencia' => (int) $evento->secuencia,
                    'id'        => $evento->id,
                    'motivo'    => "Hueco en la secuencia: se esperaba {$anterior->secuencia}+1",
                ];
                break;
            }

            if ($anterior !== null && $evento->hash_anterior !== $anterior->hash) {
                $fallo = [
                    'secuencia' => (int) $evento->secuencia,
                    'id'        => $evento->id,
                    'motivo'    => 'El hash anterior no corresponde al eslabón previo',
                ];
                break;
            }

            if (! $evento->verifica()) {
                $fallo = [
                    'secuencia' => (int) $evento->secuencia,
                    'id'        => $evento->id,
                    'motivo'    => 'El contenido del evento no corresponde a su hash',
                ];
                break;
            }

            $anterior = $evento;
        }

        return ['ok' => $fallo === null, 'revisados' => $revisados, 'primer_fallo' => $fallo];
    }

    /**
     * Sello diario: hash raíz de la jornada, para exportar fuera de sede.
     *
     * Es lo que responde a la pregunta que un auditor sí hace: «¿y si alguien
     * con acceso de administrador reescribió la cadena entera?». Con el sello
     * fuera del alcance del servidor, no puede.
     */
    public function sellar(\DateTimeInterface $fecha): ?SelloDiario
    {
        $inicio = (clone $fecha)->setTime(0, 0, 0);
        $fin    = (clone $fecha)->setTime(23, 59, 59);

        // Un sello, una vez hecho, es definitivo. Volver a calcularlo sobre una
        // cadena ya alterada produciría un sello que «cuadra» con los datos
        // manipulados, que es justo lo que el sello existe para impedir. Si el
        // día ya está sellado se devuelve el sello original, sin tocarlo.
        $existente = SelloDiario::where('fecha', $inicio->format('Y-m-d'))->first();

        if ($existente !== null) {
            return $existente;
        }

        $rango = DB::table('eventos_paquete')
            ->whereBetween('registrado_en', [$inicio, $fin])
            ->selectRaw('MIN(secuencia) AS desde, MAX(secuencia) AS hasta, COUNT(*) AS total')
            ->first();

        if (! $rango || $rango->total === 0) {
            return null;
        }

        // Raíz simple: hash de la concatenación ordenada de los hashes del día.
        // Suficiente para detectar cualquier alteración; si más adelante se
        // necesita probar la inclusión de UN evento sin revelar los demás, se
        // sustituye por un árbol de Merkle sin cambiar el resto del flujo.
        $concatenado = DB::table('eventos_paquete')
            ->whereBetween('secuencia', [$rango->desde, $rango->hasta])
            ->orderBy('secuencia')
            ->pluck('hash')
            ->implode('');

        $raiz = hash('sha256', $concatenado);

        return SelloDiario::create([
            'fecha'           => $inicio->format('Y-m-d'),
            'secuencia_desde' => $rango->desde,
            'secuencia_hasta' => $rango->hasta,
            'total_eventos'   => $rango->total,
            'hash_raiz'       => $raiz,
            'firma'           => $this->firmar($raiz),
        ]);
    }

    private function firmar(string $raiz): ?string
    {
        $clave = config('ltp.trazabilidad.sello_clave');

        // Sin clave configurada no se inventa una firma: se deja nula y el
        // panel de operación lo reporta como control pendiente.
        return $clave ? hash_hmac('sha256', $raiz, $clave) : null;
    }
}
