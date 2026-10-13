<!DOCTYPE html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0D1333">
    <meta name="description" content="Avalia Logística — recolección, transporte y prueba de entrega de medicamentos e insumos médicos.">

    {{-- La interfaz de campo se usa a la intemperie: que el navegador no
         reinterprete números de guía como teléfonos y los vuelva enlaces. --}}
    <meta name="format-detection" content="telephone=no">

    <title>Avalia Logística · Plataforma</title>

    <link rel="icon" href="/marca/icono.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/marca/icono-180.png">

    {{-- Las fuentes de marca se sirven desde este mismo dominio (ver app.css).
         Se precargan porque el titular las necesita en el primer pintado; sin
         esto el texto salta de la fuente del sistema a la de marca y la página
         se mueve entera. --}}
    <link rel="preload" href="{{ asset('fuentes/work-sans.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fuentes/league-spartan.woff2') }}" as="font" type="font/woff2" crossorigin>

    {{--
        Las @font-face van aqui y no en app.css porque la direccion necesita la
        ruta base de la aplicacion: absoluta se rompe en cuanto cuelga de una
        subcarpeta, y relativa la intentaria resolver Vite al compilar. asset()
        sabe donde esta montada.

        Ambas son variables -un solo archivo cubre de 100 a 900- y entre las dos
        pesan 98 kB. `swap` para que el texto se lea desde el primer cuadro con
        la fuente del sistema en vez de dejar la pantalla en blanco.
    --}}
    <style>
        @font-face {
            font-family: "League Spartan";
            src: url("{{ asset('fuentes/league-spartan.woff2') }}") format("woff2-variations");
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: "Work Sans";
            src: url("{{ asset('fuentes/work-sans.woff2') }}") format("woff2-variations");
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }
    </style>

    {{--
        Pantalla de carga.

        Va en el HTML y con su estilo en línea, no en un componente ni en la
        hoja de Vite, por una razón concreta: tiene que estar pintada en el
        primer cuadro. Cualquier cosa que dependa del JavaScript o de una hoja
        externa llega tarde, y lo que se ve mientras tanto es el fondo en
        blanco que precisamente hay que evitar.

        La quita `main.js` cuando la aplicación ya montó.
    --}}
    <style>
        #cargando {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 28px;
            background: #0D1333;
            color: #FFFFFF;
            /* Bloquea de verdad: nada de la aplicación es alcanzable —ni con el
               ratón ni con el tabulador— mientras esto esté encima. */
            touch-action: none;
        }

        #cargando .pieza {
            /* Ancho contenido y proporción exacta del archivo (8.75:1). La
               altura sale de la proporción, no de un valor inventado, para que
               el logotipo nunca se estire. */
            width: min(280px, 62vw);
            height: auto;
            opacity: 0;
            animation: cargando-entra .5s ease-out .05s forwards;
        }

        /* Barra indeterminada: no promete un porcentaje que no sabemos. */
        #cargando .barra {
            width: min(280px, 62vw);
            height: 2px;
            overflow: hidden;
            border-radius: 2px;
            background: rgba(255, 255, 255, .16);
        }

        #cargando .barra i {
            display: block;
            width: 40%;
            height: 100%;
            border-radius: 2px;
            background: #D2E3F3;
            animation: cargando-barra 1.15s ease-in-out infinite;
        }

        #cargando .aviso {
            max-width: 34ch;
            margin: 0;
            font: 400 13px/1.5 "Segoe UI", system-ui, sans-serif;
            color: #93AFD8;
            text-align: center;
        }

        #cargando .aviso a { color: #D2E3F3; }

        @keyframes cargando-entra {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: none; }
        }

        @keyframes cargando-barra {
            0%   { transform: translateX(-100%); }
            100% { transform: translateX(250%); }
        }

        /* Quien pidió menos movimiento ve el logotipo quieto y una barra que
           solo late. La pantalla sigue cumpliendo su función. */
        @media (prefers-reduced-motion: reduce) {
            #cargando .pieza { opacity: 1; animation: none; }
            #cargando .barra i {
                width: 100%;
                animation: cargando-late 1.6s ease-in-out infinite;
            }
            @keyframes cargando-late { 0%, 100% { opacity: .3 } 50% { opacity: 1 } }
        }

        /* La salida: se desvanece y deja de interceptar el puntero. */
        #cargando.se-va {
            opacity: 0;
            pointer-events: none;
            transition: opacity .28s ease;
        }
    </style>

    {{--
        Donde vive la aplicacion dentro del servidor: vacio en la raiz de un
        dominio, "/Avalia" si cuelga de una subcarpeta. El frontend lo lee de
        aqui para construir sus direcciones, porque el mismo paquete compilado
        debe servir en ambos casos.
    --}}
    <meta name="base-app" content="{{ rtrim(request()->getBaseUrl(), '/') }}">

    @vite(['resources/css/app.css', 'resources/js/main.js'])
</head>
<body>
    {{-- `aria-busy` y el rol de estado hacen que un lector de pantalla anuncie
         que se está cargando en vez de leer una pantalla vacía. --}}
    <div id="cargando" role="status" aria-live="polite" aria-label="Cargando Avalia Logística">
        <div class="pieza">{!! file_get_contents(public_path('marca/avalia-principal.svg')) !!}</div>
        <div class="barra"><i></i></div>

        {{-- Sale solo si la aplicación tardó demasiado. Un velo que no se va
             nunca es peor que la pantalla en blanco que vino a resolver: al
             menos aquí queda dicho qué hacer. --}}
        <p class="aviso" id="cargando-demora" hidden>
            Esto está tardando más de lo normal.
            <a href="/">Reintentar</a> o revise su conexión.
        </p>
    </div>

    <div id="app"></div>

    {{-- Configuración del mapa base.

         Viaja en el HTML y no en una llamada aparte para que el mapa pueda
         pintarse en cuanto se abre la pantalla, sin esperar un viaje al
         servidor solo para saber de dónde bajar las teselas.

         El arreglo se arma en un bloque @php y no dentro de @json: el analizador
         de directivas de Blade cuenta los paréntesis del argumento y un arreglo
         repartido en varias líneas lo rompe. Aquí solo van las tres claves que
         el mapa necesita, no la sección entera de configuración. --}}
    @php($configMapa = [
        'teselas'  => config('ltp.mapa.teselas'),
        'credito'  => config('ltp.mapa.credito'),
        'zoom_max' => config('ltp.mapa.zoom_max'),
    ])
    <script type="application/json" id="config-mapa">@json($configMapa)</script>

    @isset($rastreo)
        {{-- Rastreo público: los datos vienen incrustados para que la página
             sirva algo útil incluso antes de que arranque el JavaScript. --}}
        <script type="application/json" id="datos-rastreo">@json($rastreo)</script>
    @endisset

    <script>
        // Si a los 12 segundos la aplicación no ha montado, algo falló: un
        // paquete que no llegó, JavaScript desactivado, una versión vieja del
        // service worker. Mejor decirlo que dejar el logotipo girando.
        setTimeout(function () {
            var aviso = document.getElementById('cargando-demora')
            if (aviso && document.getElementById('cargando')) aviso.hidden = false
        }, 12000)
    </script>
</body>
</html>
