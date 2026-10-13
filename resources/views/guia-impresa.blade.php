<!DOCTYPE html>
<html lang="es-CO">
<head>
<meta charset="utf-8">
<title>Guía {{ $orden->numero_guia }} · Avalia Logística</title>
<style>
    /* Etiqueta cuadrada de 10 × 10 cm, una por paquete: cada caja viaja con la
       suya pegada.

       Es la misma guía de siempre —los mismos campos, en el mismo orden— a la
       medida del adhesivo cuadrado. Caben todos, pero el cuerpo de letra baja a
       5–6 pt: está pensada para leerse con la caja delante, no desde un metro.

       Medidas en milímetros y no en píxeles porque el destino es papel. Así la
       etiqueta sale igual desde una impresora térmica que desde una hoja A4,
       que es lo que habrá en una bodega un sábado. */
    @page { size: 100mm 100mm; margin: 0; }
    * { box-sizing: border-box; }

    body { margin: 0; font-family: "Work Sans", "Segoe UI", system-ui, sans-serif; color: #0D1333; background: #eef2f8; font-size: 6.5pt; }

    .hoja {
        width: 100mm; height: 100mm;
        margin: 0 auto 6mm;
        padding: 3.5mm;
        background: #fff;
        border: 1px solid #0D1333;
        page-break-after: always;
        display: flex; flex-direction: column;
    }
    .hoja:last-of-type { page-break-after: auto; }

    .cab { display: flex; justify-content: space-between; align-items: flex-start; gap: 2mm; border-bottom: 1.5pt solid #0D1333; padding-bottom: 1.8mm; }

    /* El logotipo va incrustado, no como <img>: sus piezas se pintan con
       `currentColor` y dentro de una imagen ese color no hereda nada —saldría
       negro—. Incrustarlo además evita que la impresión dependa de que el
       navegador alcance a descargar un archivo aparte antes de pintar. */
    .marca { color: #0D1333; }

    /* Solo se fija la altura: el ancho lo calcula el navegador con el viewBox.
       Fijar las dos medidas es lo que deforma un logotipo. */
    .marca svg { display: block; width: auto; height: 4.4mm; }

    .lema { font-size: 4.2pt; letter-spacing: .12em; text-transform: uppercase; color: #667788; margin-top: .7mm; }
    .guia-num { font-family: ui-monospace, Consolas, monospace; font-size: 11pt; font-weight: 700; text-align: right; line-height: 1; letter-spacing: -.02em; }
    .guia-et { font-size: 4.2pt; letter-spacing: .12em; text-transform: uppercase; color: #667788; text-align: right; margin-bottom: .6mm; }

    .cuerpo { display: flex; gap: 2.5mm; margin-top: 2mm; flex: 1 1 auto; min-height: 0; }
    .datos { flex: 1 1 auto; min-width: 0; }
    .codigos { flex: 0 0 29mm; text-align: center; }

    .par { display: flex; gap: 1.5mm; margin-bottom: 1.1mm; }
    .et { flex: 0 0 13mm; font-size: 4.6pt; letter-spacing: .07em; text-transform: uppercase; color: #667788; line-height: 1.4; padding-top: .2mm; }
    .va { flex: 1; min-width: 0; font-size: 6.5pt; font-weight: 500; line-height: 1.3; }

    .ruta { background: #f4f7fb; border-left: 2pt solid #004CB9; padding: 1.1mm 1.6mm; margin: 1.5mm 0; }
    .ruta .de { font-size: 4.6pt; text-transform: uppercase; letter-spacing: .07em; color: #667788; line-height: 1.4; }
    .ruta .sitio { font-size: 7pt; font-weight: 600; line-height: 1.2; }
    .dir { font-size: 5.8pt; line-height: 1.3; }

    .tipo { display: inline-block; padding: .4mm 1.4mm; border-radius: .8mm; font-size: 5pt; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
    .tipo--frio { background: #d6ecff; color: #0b4f87; }
    .tipo--ctrl { background: #ffe6d6; color: #8a3b00; }
    .tipo--gen { background: #e8ecf3; color: #40506b; }

    .qr svg { width: 27mm; height: 27mm; display: block; margin: 0 auto; }
    .qr-pie { font-size: 4.2pt; color: #667788; margin-top: .7mm; line-height: 1.25; }
    .cod { font-family: ui-monospace, Consolas, monospace; font-size: 6.5pt; font-weight: 700; margin-top: .6mm; }
    .barras svg { width: 27mm; height: 7mm; display: block; margin: 1.2mm auto 0; }

    .refs { margin: 0; padding-left: 2.6mm; font-size: 6pt; line-height: 1.35; }

    .pie { margin-top: 1.5mm; padding-top: 1.2mm; border-top: .75pt solid #d7dfea; font-size: 4.4pt; color: #667788; display: flex; justify-content: space-between; gap: 1mm; }

    .imprimir { position: fixed; top: 12px; right: 12px; padding: 10px 20px; background: #004CB9; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; }

    @media print {
        body { background: #fff; }
        .imprimir { display: none; }
        .hoja { border: none; margin: 0; }
    }
</style>
</head>
<body>

<button class="imprimir" onclick="window.print()">Imprimir</button>

@foreach ($paquetes as $p)
    @php($paquete = $p['modelo'])
    <div class="hoja">
        <div class="cab">
            <div>
                <div class="marca">{!! $marca !!}</div>
                <div class="lema">Conectamos destinos, respaldamos confianza</div>
            </div>
            <div>
                <div class="guia-et">Guía Avalia</div>
                <div class="guia-num">{{ $orden->numero_guia }}</div>
            </div>
        </div>

        <div class="cuerpo">
            <div class="datos">
                <div class="par">
                    <div class="et">Cliente</div>
                    <div class="va">{{ $orden->cliente->razon_social }}</div>
                </div>

                <div class="ruta">
                    <div class="de">Recoger en</div>
                    <div class="sitio">{{ $orden->origen?->nombre ?? '—' }}</div>
                    @if ($orden->origen?->direccion)
                        <div class="dir">{{ $orden->origen->direccion }} · {{ $orden->origen->ciudad }}</div>
                    @endif
                </div>

                <div class="ruta" style="border-left-color:#0D1333">
                    <div class="de">Entregar en</div>
                    <div class="sitio">{{ $orden->destino?->nombre ?? '—' }}</div>
                    @if ($orden->destino?->direccion)
                        <div class="dir">{{ $orden->destino->direccion }} · {{ $orden->destino->ciudad }}</div>
                    @endif
                    @if ($orden->contacto_destino_nombre)
                        <div class="dir">Contacto: {{ $orden->contacto_destino_nombre }}</div>
                    @endif
                </div>

                <div class="par">
                    <div class="et">Tipo</div>
                    <div class="va">
                        @php($clase = match($paquete->tipo->value) { 'cadena_frio' => 'tipo--frio', 'controlado' => 'tipo--ctrl', default => 'tipo--gen' })
                        <span class="tipo {{ $clase }}">{{ $paquete->tipo->etiqueta() }}</span>
                        @if ($orden->criticidad->value === 'urgente')
                            <span class="tipo tipo--ctrl">Urgente</span>
                        @endif
                    </div>
                </div>

                <div class="par">
                    <div class="et">Peso</div>
                    <div class="va">{{ $paquete->peso_kg }} kg</div>
                </div>

                @if ($orden->ventana_desde || $orden->ventana_hasta)
                    <div class="par">
                        <div class="et">Ventana</div>
                        <div class="va">{{ $orden->ventana_desde?->format('d/m/Y H:i') ?? '—' }} a {{ $orden->ventana_hasta?->format('d/m/Y H:i') ?? '—' }}</div>
                    </div>
                @endif

                @if ($paquete->descripcion)
                    <div class="par">
                        <div class="et">Contenido</div>
                        <div class="va">{{ $paquete->descripcion }}</div>
                    </div>
                @endif

                @if ($orden->referencias->count())
                    <div class="par">
                        <div class="et">{{ $orden->cliente->etiqueta_referencia ?: 'Referencias' }}</div>
                        <div class="va">
                            <ul class="refs">
                                @foreach ($orden->referencias as $r)
                                    <li>{{ $r->tipo->etiqueta() }}: <strong>{{ $r->valor }}</strong></li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                @if ($orden->observaciones)
                    <div class="par">
                        <div class="et">Observaciones</div>
                        <div class="va" style="font-weight:400">{{ $orden->observaciones }}</div>
                    </div>
                @endif
            </div>

            <div class="codigos">
                <div class="qr">{!! $p['qr'] !!}</div>
                <div class="qr-pie">El mensajero escanea este código<br>para confirmar la recolección</div>
                <div class="cod">{{ $paquete->codigo }}</div>
                <div class="barras">{!! $p['barras'] !!}</div>
            </div>
        </div>

        <div class="pie">
            <span>Radicado el {{ $orden->created_at->format('d/m/Y H:i') }}</span>
            <span>Paquete {{ $loop->iteration }} de {{ count($paquetes) }}</span>
            <span>Operación con bitácora de solo anexado</span>
        </div>
    </div>
@endforeach

</body>
</html>
