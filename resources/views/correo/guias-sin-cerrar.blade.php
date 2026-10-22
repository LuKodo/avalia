{{--
    Aviso diario de envíos parados.

    En Markdown como el resto de correos: Outlook renderiza con el motor de
    Word y la plantilla de Laravel ya resuelve las tablas y los estilos en
    línea que hacen falta para que se vea igual en todas partes.

    Las dos tablas están escritas enteras, una con la columna de custodia y
    otra sin ella, en vez de intercalar condicionales dentro de las filas. Un
    `@if` a mitad de una fila de Markdown rompe la tabla en cuanto alguien la
    toca, y una tabla rota en un correo se ve como texto suelto con barras.
--}}
<x-mail::message>
# Envíos sin cerrar

{{ $total }} {{ $total === 1 ? 'envío lleva' : 'envíos llevan' }} más de {{ $dias }} días sin entregarse ni cerrarse.

@if ($conCustodia)
La última columna dice a quién hay que preguntarle.

<x-mail::table>
| Guía | Cliente | Destino | Estado | Días | En custodia de |
|:-----|:--------|:--------|:-------|-----:|:---------------|
@foreach ($filas as $g)
| {{ $g['guia'] }} | {{ $g['cliente'] }} | {{ $g['destino'] }} | {{ $g['estado'] }} | {{ $g['dias'] }} | {{ $g['custodia'] }} |
@endforeach
</x-mail::table>

@if ($ocultas)
Se listan las {{ count($filas) }} más antiguas; quedan **{{ $ocultas }} más** en la pantalla de despacho.
@endif

Las que dicen **Sin asignar** son las más graves: no es que el mensajero no haya podido, es que nunca salieron de la bandeja de despacho.

<x-mail::button :url="rtrim(config('app.url'), '/').'/despacho'">
Abrir el despacho
</x-mail::button>
@else
<x-mail::table>
| Guía | Destino | Estado | Días |
|:-----|:--------|:-------|-----:|
@foreach ($filas as $g)
| {{ $g['guia'] }} | {{ $g['destino'] }} | {{ $g['estado'] }} | {{ $g['dias'] }} |
@endforeach
</x-mail::table>

@if ($ocultas)
Se listan los {{ count($filas) }} más antiguos; quedan **{{ $ocultas }} más** en *Mis solicitudes*.
@endif

Si alguno de estos envíos ya no debe entregarse, avísenos y lo cerramos: una guía abierta que nadie va a mover solo sirve para ensuciar sus indicadores.

<x-mail::button :url="rtrim(config('app.url'), '/').'/solicitudes'">
Ver mis solicitudes
</x-mail::button>
@endif

Este aviso sale una vez al día. Si prefiere no recibirlo, dígaselo al área de TIC de Avalia: se configura, no se filtra.

Avalia Logística
</x-mail::message>
