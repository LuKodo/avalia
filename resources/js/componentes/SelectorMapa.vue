<script setup>
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import maplibregl from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'

/**
 * Elegir un punto en el mapa, arrastrando.
 *
 * Nace de una observación de operación: las coordenadas se tecleaban a mano.
 * Nadie sabe de memoria que su farmacia está en 10.4052, −75.4655, así que o se
 * copiaban de otro sitio —con el riesgo de pegar el centro del encuadre en vez
 * del lugar, que ya nos pasó— o se dejaban vacías, y una sede sin coordenada
 * obliga a asignar a mano y no se puede validar al entregar.
 *
 * Dos formas de marcar, porque la gente usa las dos sin pensarlo: un clic en
 * cualquier punto, o arrastrar el alfiler. Y la coordenada sigue visible y
 * editable debajo: quien la tenga de una fuente fiable no tiene por qué buscar
 * el sitio a ojo.
 */
const props = defineProps({
    latitud: { type: [Number, String], default: null },
    longitud: { type: [Number, String], default: null },
    alto: { type: String, default: '260px' },
    // Dónde encuadrar cuando todavía no hay nada marcado.
    centroPorDefecto: { type: Array, default: () => [-75.5, 10.4] },
})

const emit = defineEmits(['update:latitud', 'update:longitud'])

const contenedor = ref(null)
const mapa = shallowRef(null)
const alfiler = shallowRef(null)
const listo = ref(false)

/** La configuración de teselas que el servidor dejó incrustada en el HTML. */
function configuracion() {
    const nodo = document.getElementById('config-mapa')

    if (nodo === null) return { teselas: null, credito: '', zoom_max: 19 }

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
                // La licencia de OpenStreetMap lo exige, y MapLibre solo lo
                // muestra si se declara aquí.
                attribution: cfg.credito,
            },
        },
        layers: [{ id: 'base', type: 'raster', source: 'base' }],
    }
}

const hayPunto = () => props.latitud !== null && props.latitud !== ''
    && props.longitud !== null && props.longitud !== ''

function marcar(lon, lat) {
    // Siete decimales son unos 11 mm. Más allá es ruido, y menos no distingue
    // dos portales contiguos.
    emit('update:latitud', Number(lat.toFixed(7)))
    emit('update:longitud', Number(lon.toFixed(7)))
}

function dibujarAlfiler() {
    if (! mapa.value || ! hayPunto()) return

    const punto = [Number(props.longitud), Number(props.latitud)]

    if (alfiler.value) {
        alfiler.value.setLngLat(punto)

        return
    }

    alfiler.value = new maplibregl.Marker({ color: '#004CB9', draggable: true })
        .setLngLat(punto)
        .addTo(mapa.value)

    alfiler.value.on('dragend', () => {
        const { lng, lat } = alfiler.value.getLngLat()
        marcar(lng, lat)
    })
}

onMounted(() => {
    const cfg = configuracion()

    if (! cfg.teselas || ! contenedor.value) return

    mapa.value = new maplibregl.Map({
        container: contenedor.value,
        style: estilo(cfg),
        center: hayPunto() ? [Number(props.longitud), Number(props.latitud)] : props.centroPorDefecto,
        zoom: hayPunto() ? 16 : 12,
        maxZoom: cfg.zoom_max ?? 19,
    })

    mapa.value.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right')

    mapa.value.on('click', (e) => marcar(e.lngLat.lng, e.lngLat.lat))
    mapa.value.on('load', () => { listo.value = true; dibujarAlfiler() })
})

onBeforeUnmount(() => {
    mapa.value?.remove()
    mapa.value = null
})

/*
 * Si la coordenada cambia desde fuera —alguien la escribe a mano— el alfiler
 * la sigue. Sin esto, el mapa y los campos contarían cosas distintas.
 */
watch(() => [props.latitud, props.longitud], () => {
    dibujarAlfiler()

    if (mapa.value && hayPunto() && ! mapa.value.isMoving()) {
        mapa.value.easeTo({ center: [Number(props.longitud), Number(props.latitud)], duration: 300 })
    }
})
</script>

<template>
    <div class="selector">
        <div ref="contenedor" class="selector-mapa" :style="{ height: alto }"></div>

        <p class="selector-pie">
            <template v-if="hayPunto()">
                📍 <span class="num">{{ Number(latitud).toFixed(5) }}, {{ Number(longitud).toFixed(5) }}</span>
                — arrastre el alfiler o pulse en otro sitio para corregirlo.
            </template>
            <template v-else>
                Pulse en el mapa para marcar el punto exacto.
            </template>
        </p>
    </div>
</template>

<style scoped>
.selector { display: block; }

.selector-mapa {
    width: 100%;
    border: 1px solid var(--linea-fuerte, #c9d3e0);
    border-radius: var(--r-md, 8px);
    overflow: hidden;
    background: var(--superficie-2, #eef2f8);
}

.selector-pie { margin: .4rem 0 0; font-size: .78rem; color: var(--ink-3); line-height: 1.45; }
.selector-pie .num { font-weight: 600; color: var(--ink-2); font-variant-numeric: tabular-nums; }
</style>
