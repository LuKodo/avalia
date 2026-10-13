{{--
    El aviso de cambio de estado.

    En Markdown y no en HTML a mano: los clientes de correo son un campo
    minado -Outlook renderiza con el motor de Word- y la plantilla de Laravel
    ya resuelve las tablas anidadas y los estilos en línea que hacen falta
    para que se vea igual en todos.
--}}
<x-mail::message>
# Envío {{ $guia }}

**{{ $estado }}**{{ $ocurrido ? ' · '.$ocurrido->format('d/m/Y H:i') : '' }}

@if ($origen || $destino)
{{ $origen ?? '—' }} → {{ $destino ?? '—' }}
@endif

@if (count($referencias))
**Sus referencias:**
@foreach ($referencias as $r)
- {{ $r }}
@endforeach
@endif

<x-mail::button :url="$rastreo">
Ver el detalle del envío
</x-mail::button>

Este aviso se genera solo al cambiar el estado. Si algo no cuadra, responda a
este correo y lo revisamos.

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
