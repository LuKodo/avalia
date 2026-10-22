<?php

namespace Database\Seeders;

use App\Models\Parametro;
use Illuminate\Database\Seeder;

/**
 * Parámetros de operación con sus valores iniciales.
 *
 * Todo lo que la operación vaya a ajustar en las primeras semanas entra aquí.
 * Los pesos del motor de asignación son el caso claro: se van a mover, y pedir
 * un despliegue para cambiar un peso garantiza que nadie lo cambie nunca.
 */
class ParametrosSeeder extends Seeder
{
    public function run(): void
    {
        $parametros = [
            ['asignacion.peso.cercania',  '35',  'int',   'Peso de la cercanía al origen en el puntaje de asignación'],
            ['asignacion.peso.holgura',   '25',  'int',   'Peso de la holgura frente a la ventana comprometida'],
            ['asignacion.peso.carga',     '20',  'int',   'Peso de la carga actual del mensajero'],
            ['asignacion.peso.ruta',      '15',  'int',   'Peso de la compatibilidad con la ruta en curso'],
            ['asignacion.peso.desempeno', '5',   'int',   'Peso del desempeño histórico del mensajero'],
            ['asignacion.umbral_automatico', '45', 'int', 'Puntaje mínimo para asignar sin intervención del operador'],
            ['asignacion.radio_km',       '15',  'float', 'Radio de búsqueda de mensajeros alrededor del origen'],

            ['alerta.sin_senal_min',      '10',  'int',   'Minutos sin reporte de posición antes de alertar'],
            ['alerta.bateria_min',        '20',  'int',   'Porcentaje de batería por debajo del cual se alerta'],
            ['alerta.desvio_km',          '1.5', 'float', 'Desvío del corredor previsto que dispara alerta'],
            ['alerta.riesgo_ventana_min', '20',  'int',   'Minutos de margen proyectado antes de alertar incumplimiento'],

            ['entrega.radio_validacion_m', '150', 'int',  'Distancia máxima entre la entrega y la sede para considerarla coherente'],
            ['frio.tolerancia_min',       '20',  'int',   'Minutos de excursión térmica tolerados antes de escalar'],

            /*
             * Recargo por empaque, como fracción de la tarifa base: 0.30 son un
             * 30 % más. Nacen en cero a propósito —un precio que nadie fijó no
             * se cobra— y se ajustan en Administración → Parámetros sin tocar
             * el software, que es donde deben decidirse los precios.
             */
            ['tarifa.recargo_empaque.bolsa',   '0',   'float', 'Recargo sobre la tarifa base para envíos en bolsa'],
            ['tarifa.recargo_empaque.caja',    '0',   'float', 'Recargo sobre la tarifa base para envíos en caja'],
            ['tarifa.recargo_empaque.panales', '0',   'float', 'Recargo sobre la tarifa base para pañales (ocupan más volumen)'],

            ['integracion.max_lote',      '200', 'int',   'Máximo de referencias por consulta en lote'],
            ['integracion.webhook_reintentos', '5', 'int', 'Reintentos de una notificación antes de darla por fallida'],
        ];

        foreach ($parametros as [$clave, $valor, $tipo, $descripcion]) {
            Parametro::updateOrCreate(
                ['clave' => $clave],
                ['valor' => $valor, 'tipo' => $tipo, 'descripcion' => $descripcion]
            );
        }
    }
}
