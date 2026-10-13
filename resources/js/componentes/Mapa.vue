<script setup>
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import maplibregl from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'

/**
 * El mapa de operación.
 *
 * Dibuja hasta cuatro cosas, y cada una responde una pregunta distinta:
 *
 *   · La RUTA TRAZADA (línea azul continua): por dónde debería ir.
 *   · El RASTRO REAL (línea de puntos): por dónde fue de verdad.
 *   · Los EXTREMOS: origen y destino.
 *   · Los MENSAJEROS: dónde están ahora.
 *
 * Ruta y rastro se pintan distinto a propósito. Con un solo estilo, un desvío
 * se ve como una ruta rara en vez de como lo que es: la diferencia entre lo
 * planeado y lo ocurrido.
 *
 * El mapa se queda en claro también con el tema oscuro. MapLibre pinta las
 * teselas y las líneas en el MISMO lienzo, así que el filtro de inversión con
 * el que suele oscurecerse un mapa se llevaría por delante los colores de la
 * ruta y del rastro —que son justamente lo que hay que leer—.
 */
const props = defineProps({
    // Polilínea planeada: pares [lon, lat].
    ruta:       { type: Array,  default: () => [] },
    // Rastro real: [{ lat, lon, momento }]
    rastro:     { type: Array,  default: () => [] },
    origen:     { type: Object, default: null },
    destino:    { type: Object, default: null },
    // Modo flota: [{ lat, lon, nombre, vigente, ... }]
    mensajeros: { type: Array,  default: () => [] },
    // Si la ruta es apenas una línea recta, se dibuja discontinua: prometer
    // una vía que nadie calculó sería mentir con una línea.
    aproximada: { type: Boolean, default: false },
    alto:       { type: String,  default: '420px' },
})

const contenedor = ref(null)
const mapa = shallowRef(null)
const listo = ref(false)
const marcadores = []

/** La configuración que el servidor dejó incrustada en el HTML. */
function configuracion() {
    const nodo = document.getElementById('config-mapa')

    if (nodo === null) {
        return { teselas: null, credito: '', zoom_max: 19 }
    }

    try {
        return JSON.parse(nodo.textContent)
    } catch {
        return { teselas: null, credito: '', zoom_max: 19 }
    }
}

function estilo(cfg) {
    return {
        version: 8,
        sources: {
            base: {
                type: 'raster',
                tiles: [cfg.teselas],
                tileSize: 256,
                // El crédito no es cortesía: la licencia de OpenStreetMap lo
                // exige, y MapLibre solo lo muestra si se declara aquí.
                attribution: cfg.credito,
            },
        },
        layers: [{ id: 'base', type: 'raster', source: 'base' }],
    }
}

function limpiarMarcadores() {
    while (marcadores.length > 0) {
        marcadores.pop().remove()
    }
}

/** Un punto con su rótulo. El color lo decide quien llama. */
function marcador(lon, lat, color, titulo, detalle) {
    const el = document.createElement('div')
    el.className = 'pin'
    el.style.background = color
    el.setAttribute('role', 'img')
    el.setAttribute('aria-label', titulo)

    const m = new maplibregl.Marker({ element: el })
        .setLngLat([lon, lat])
        .setPopup(new maplibregl.Popup({ offset: 14, closeButton: false })
            .setHTML(`<strong>${escapar(titulo)}</strong>${detalle ? `<br><span>${escapar(detalle)}</span>` : ''}`))
        .addTo(mapa.value)

    marcadores.push(m)
}

/**
 * Los textos vienen de la base —nombres de sede, de mensajero— y entran a un
 * popup por innerHTML. Sin escapar, un nombre con `<script>` se ejecutaría.
 */
function escapar(t) {
    const d = document.createElement('div')
    d.textContent = String(t ?? '')

    return d.innerHTML
}

function fuente(id, geojson) {
    const m = mapa.value

    if (m.getSource(id)) {
        m.getSource(id).setData(geojson)

        return false
    }

    m.addSource(id, { type: 'geojson', data: geojson })

    return true
}

function linea(coordenadas) {
    return { type: 'Feature', geometry: { type: 'LineString', coordinates: coordenadas } }
}

function pintar() {
    const m = mapa.value

    if (m === null || ! listo.value) {
        return
    }

    limpiarMarcadores()

    const puntos = []

    // --- ruta trazada ----------------------------------------------------
    if (props.ruta.length >= 2) {
        if (fuente('ruta', linea(props.ruta))) {
            m.addLayer({
                id: 'ruta',
                type: 'line',
                source: 'ruta',
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: {
                    'line-color': '#004CB9',
                    'line-width': 5,
                    'line-opacity': 0.85,
                },
            })
        }

        m.setPaintProperty('ruta', 'line-dasharray', props.aproximada ? [1.5, 1.5] : [1])
        props.ruta.forEach((c) => puntos.push(c))
    } else if (m.getLayer('ruta')) {
        m.setLayoutProperty('ruta', 'visibility', 'none')
    }

    // --- rastro real -----------------------------------------------------
    const trazo = props.rastro.map((p) => [p.lon, p.lat])

    if (trazo.length >= 2) {
        if (fuente('rastro', linea(trazo))) {
            m.addLayer({
                id: 'rastro',
                type: 'line',
                source: 'rastro',
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: {
                    'line-color': '#0D1333',
                    'line-width': 3,
                    'line-dasharray': [0.6, 1.4],
                },
            })
        }

        trazo.forEach((c) => puntos.push(c))
    }

    // --- extremos --------------------------------------------------------
    if (props.origen) {
        marcador(props.origen.lon, props.origen.lat, '#0D1333', props.origen.nombre, props.origen.direccion)
        puntos.push([props.origen.lon, props.origen.lat])
    }

    if (props.destino) {
        marcador(props.destino.lon, props.destino.lat, '#004CB9', props.destino.nombre, props.destino.direccion)
        puntos.push([props.destino.lon, props.destino.lat])
    }

    // --- posición actual del envío ---------------------------------------
    if (trazo.length > 0) {
        const ultimo = props.rastro[props.rastro.length - 1]
        marcador(ultimo.lon, ultimo.lat, '#0CA30C', 'Posición actual',
            new Date(ultimo.momento).toLocaleString('es-CO'))
    }

    // --- flota -----------------------------------------------------------
    for (const m2 of props.mensajeros) {
        // Gris para el que lleva rato sin reportar: pintarlo igual que uno en
        // vivo afirmaría que está ahí, y solo se sabe que estaba.
        marcador(
            m2.lon, m2.lat,
            m2.vigente ? '#0CA30C' : '#69738C',
            m2.nombre,
            m2.vigente
                ? `${m2.vehiculo ?? 'Sin vehículo'} · hace ${m2.minutos} min`
                : `Sin señal hace ${m2.minutos} min`,
        )
        puntos.push([m2.lon, m2.lat])
    }

    encuadrar(puntos)
}

/** Encuadra todo lo dibujado. */
function encuadrar(puntos) {
    if (puntos.length === 0) {
        return
    }

    if (puntos.length === 1) {
        mapa.value.jumpTo({ center: puntos[0], zoom: 14 })

        return
    }

    const caja = puntos.reduce(
        (b, c) => b.extend(c),
        new maplibregl.LngLatBounds(puntos[0], puntos[0]),
    )

    mapa.value.fitBounds(caja, { padding: 48, maxZoom: 16, duration: 0 })
}

onMounted(() => {
    const cfg = configuracion()

    if (! cfg.teselas) {
        return
    }

    mapa.value = new maplibregl.Map({
        container: contenedor.value,
        style: estilo(cfg),
        center: [-75.57, 6.24],   // Medellín, hasta que haya datos que encuadrar
        zoom: 11,
        maxZoom: cfg.zoom_max ?? 19,
        // Sin rotación: en una pantalla de operación, un mapa girado por un
        // gesto accidental cuesta más de lo que aporta.
        dragRotate: false,
        pitchWithRotate: false,
        attributionControl: { compact: true },
    })

    mapa.value.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right')
    mapa.value.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left')

    mapa.value.on('load', () => {
        listo.value = true
        pintar()
    })
})

onBeforeUnmount(() => {
    limpiarMarcadores()
    mapa.value?.remove()
    mapa.value = null
})

watch(() => [props.ruta, props.rastro, props.mensajeros, props.origen, props.destino], pintar, { deep: true })
</script>

<template>
    <div class="mapa-caja" :style="{ height: alto }">
        <div ref="contenedor" class="lienzo" />

        <p v-if="! listo" class="cargando">Cargando el mapa…</p>
    </div>
</template>

<style scoped>
.mapa-caja {
    position: relative;
    width: 100%;
    border-radius: var(--r-lg);
    overflow: hidden;
    border: 1px solid var(--linea);
    background: var(--superficie-2);
}

.lienzo { position: absolute; inset: 0; }

.cargando {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    margin: 0;
    font-size: .84rem;
    color: var(--ink-3);
}

/* El pin va en :deep porque se crea con createElement y no lleva el atributo
   de alcance que Vue pone en la plantilla. */
:deep(.pin) {
    width: 15px;
    height: 15px;
    border-radius: 50%;
    border: 2.5px solid #FFFFFF;
    box-shadow: 0 1px 5px rgba(13, 19, 51, .5);
    cursor: pointer;
}

:deep(.maplibregl-popup-content) {
    font-family: var(--sans);
    font-size: .8rem;
    border-radius: var(--r-md);
    padding: .5rem .7rem;
}
</style>
