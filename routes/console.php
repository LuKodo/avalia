<?php

use App\Services\Trazabilidad\VerificadorCadena;
use Illuminate\Support\Facades\Schedule;

/*
|---------------------------------------------------------------------------
| Tareas programadas
|---------------------------------------------------------------------------
| La verificación nocturna de la cadena y el sellado diario no son opcionales:
| una bitácora que nadie verifica es una bitácora en la que nadie ha comprobado
| que se pueda confiar.
*/

Schedule::call(function (VerificadorCadena $verificador) {
    $resultado = $verificador->verificar();

    if (! $resultado['ok']) {
        logger()->critical('Cadena de auditoría comprometida', $resultado['primer_fallo']);
    }
})->dailyAt('02:00')->name('verificar-cadena')->withoutOverlapping();

Schedule::call(function (VerificadorCadena $verificador) {
    $verificador->sellar(now()->subDay());
})->dailyAt('02:30')->name('sellar-jornada')->withoutOverlapping();

/*
 * Aviso de lo que lleva días parado.
 *
 * A las 07:00, antes de que arranque el despacho: el objetivo es que la lista
 * esté en la bandeja cuando se toman las decisiones del día, no a media tarde
 * cuando ya no se puede hacer nada con ella.
 *
 * Entre semana y no a diario: un correo el domingo por la mañana diciendo que
 * hay cuatro guías paradas no lo lee nadie, y enseña a ignorar el del lunes.
 */
Schedule::command('avalia:avisar-sin-cerrar')
    ->weekdays()
    ->at('07:00')
    ->name('avisar-guias-sin-cerrar')
    ->withoutOverlapping();

// Purga de posiciones antiguas: es la tabla que más crece y casi nunca se
// consulta más allá de la jornada. La trazabilidad de los eventos no se toca.
Schedule::call(function () {
    \App\Models\Posicion::where('registrado_en', '<', now()->subDays(90))->delete();
})->weeklyOn(7, '03:00')->name('purgar-posiciones');
