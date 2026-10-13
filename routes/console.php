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

// Purga de posiciones antiguas: es la tabla que más crece y casi nunca se
// consulta más allá de la jornada. La trazabilidad de los eventos no se toca.
Schedule::call(function () {
    \App\Models\Posicion::where('registrado_en', '<', now()->subDays(90))->delete();
})->weeklyOn(7, '03:00')->name('purgar-posiciones');
