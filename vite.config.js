import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

/**
 * La aplicación de campo del mensajero es una PWA instalable: se abre desde el
 * escritorio del teléfono, funciona sin señal y sincroniza al reconectar. Esa
 * fue la decisión que evitó construir y publicar dos aplicaciones nativas.
 *
 * Límite honesto que hay que tener presente: en iOS una PWA no puede emitir
 * posición con la pantalla apagada. El seguimiento en vivo funciona con la
 * aplicación abierta; fuera de eso, la trazabilidad la sostienen los eventos de
 * recolección y entrega, que sí quedan completos.
 */
export default defineConfig({
    /**
     * Las direcciones de los fragmentos que se cargan bajo demanda, relativas.
     *
     * Vite hornea la base en tiempo de compilación, y por omisión queda
     * absoluta: `/build/assets/Ingreso-xxxx.css`. Los archivos de entrada no
     * sufren —esos los inserta Laravel con `@vite`, que sí sabe dónde está
     * montada la aplicación—, pero cada componente que se carga al navegar se
     * pide a la raíz del dominio. En un despliegue que cuelga de una
     * subcarpeta eso es un 404 y la pantalla se queda a medias.
     *
     * Con `relative: true` cada fragmento se resuelve respecto al que lo
     * importa, así que el mismo paquete compilado sirve en la raíz de un
     * dominio y en /Avalia sin recompilar.
     */
    experimental: {
        renderBuiltUrl: () => ({ relative: true }),
    },

    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            registerType: 'autoUpdate',
            manifest: {
                name: 'Avalia Logística',
                short_name: 'Avalia',
                description: 'Recolección, transporte y prueba de entrega de medicamentos e insumos médicos',
                lang: 'es-CO',
                start_url: '/campo',
                display: 'standalone',
                background_color: '#0D1333',
                theme_color: '#0D1333',
                orientation: 'portrait',

                /* El fondo del manifiesto es el azul noche de la marca, no un
                   gris claro: es el color que el sistema pinta en la pantalla
                   de arranque mientras la aplicación abre, y tiene que ser el
                   mismo de la pantalla de carga del HTML. Si no coinciden, el
                   arranque parpadea de claro a oscuro. */
                icons: [
                    { src: '/marca/icono-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/marca/icono-512.png', sizes: '512x512', type: 'image/png' },
                    {
                        // `maskable` deja que Android recorte el ícono a la
                        // forma del lanzador sin comerse el monograma: la pieza
                        // ya trae margen suficiente alrededor.
                        src: '/marca/icono-512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
            },
            workbox: {
                globPatterns: ['**/*.{js,css,svg,woff2}'],

                /*
                 * Sin sustituto de navegación. Es la corrección de un defecto
                 * real, no una preferencia.
                 *
                 * Por omisión el complemento registra la navegación contra
                 * `index.html`, que es lo correcto en un sitio estático y aquí
                 * no existe: el HTML lo genera Blade en cada petición. Con el
                 * worker activo, TODA navegación pasaba por un manejador atado a
                 * un recurso inexistente, y el resultado eran pantallas en blanco
                 * y errores de JavaScript por trozos que no encajaban entre sí.
                 *
                 * Ahora las navegaciones van al servidor, que devuelve el
                 * cascarón con los nombres de archivo correctos de ESTE build.
                 * Los recursos siguen precacheados, así que la aplicación ya
                 * abierta sigue funcionando sin señal —que es el caso del
                 * mensajero: entra con cobertura y la pierde en ruta—. Lo que no
                 * se sostiene es abrirla por primera vez estando ya sin señal.
                 */
                navigateFallback: null,

                // Que un worker viejo no siga sirviendo trozos de un build
                // anterior: es justo lo que provocaba el error.
                cleanupOutdatedCaches: true,
                skipWaiting: true,
                clientsClaim: true,
                runtimeCaching: [
                    {
                        // Las tareas del día se sirven desde caché al instante y
                        // se refrescan por detrás: abrir la aplicación en un
                        // sótano no puede quedarse en blanco.
                        urlPattern: /\/api\/v1\/campo\/tareas/,
                        handler: 'StaleWhileRevalidate',
                        options: { cacheName: 'ltp-tareas' },
                    },
                    {
                        // Nada que escriba se cachea: las entregas van a la
                        // bandeja de salida local, no al caché del navegador.
                        urlPattern: /\/api\/v1\/campo\/paquetes\/.*\/(recolectar|entregar|novedad)/,
                        handler: 'NetworkOnly',
                    },
                ],
            },
        }),
    ],
    build: {
        target: 'es2022',
        chunkSizeWarningLimit: 700,
        rollupOptions: {
            output: {
                manualChunks: {
                    // El mapa pesa; que no lo descargue quien solo entra al
                    // tablero o a consultar una guía.
                    mapa: ['maplibre-gl'],
                },
            },
        },
    },
    server: {
        host: '0.0.0.0',   // para probar en un teléfono real de la red local
        port: 5173,
    },
})
