<script setup>
import { computed, ref } from 'vue'
import MarcoGrafico from './MarcoGrafico.vue'
import { colorSemaforo, escalaLineal, numero } from './util'

/**
 * Barras horizontales para comparar magnitudes entre categorías.
 *
 * Horizontales y no verticales por una razón práctica: las etiquetas —«ruptura
 * de cadena de frío», «documento no coincide»— no caben debajo de una barra
 * vertical sin inclinarse, y una etiqueta inclinada es una etiqueta que nadie
 * lee. Van ordenadas de mayor a menor, porque en un gráfico de causas el orden
 * ES el mensaje.
 */
const props = defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    /** [{ etiqueta, total, semaforo?, nota? }] */
    datos: { type: Array, required: true },
    unidad: { type: String, default: '' },
    /** Colorea por severidad en vez de un solo color de acento. */
    porSemaforo: { type: Boolean, default: false },
    color: { type: String, default: 'var(--serie-1)' },
    maximo: { type: Number, default: 10 },
})

const ancho = ref(640)
const activo = ref(null)

const ALTO_BARRA = 26
const SEPARACION = 10
const IZQ = 168    // ancho reservado a las etiquetas de categoría
const DER = 56     // ancho reservado a la etiqueta de valor

const filas = computed(() =>
    [...props.datos].sort((a, b) => b.total - a.total).slice(0, props.maximo)
)

const alto = computed(() => Math.max(80, filas.value.length * (ALTO_BARRA + SEPARACION) + 8))

const g = computed(() => {
    const w = Math.max(60, ancho.value - IZQ - DER)
    const max = Math.max(1, ...filas.value.map((f) => f.total))
    const x = escalaLineal([0, max], [IZQ, IZQ + w])

    return { x, max, w }
})

/** Barra con el extremo de dato redondeado y la base recta sobre el eje. */
function ruta(valor, y) {
    const x0 = IZQ
    const x1 = g.value.x(valor)
    const r = Math.min(4, Math.max(0, (x1 - x0) / 2))
    const yb = y + ALTO_BARRA

    if (x1 - x0 < 1) return ''

    return [
        `M${x0},${y}`,
        `H${x1 - r}`,
        `Q${x1},${y} ${x1},${y + r}`,
        `V${yb - r}`,
        `Q${x1},${yb} ${x1 - r},${yb}`,
        `H${x0}`,
        'Z',
    ].join(' ')
}

function colorDe(fila) {
    return props.porSemaforo ? colorSemaforo(fila.semaforo ?? 'neutral') : props.color
}

const tabla = computed(() => ({
    columnas: ['Causa', 'Total', 'Severidad'],
    filas: filas.value.map((f) => [f.etiqueta, numero(f.total), f.semaforo ?? '—']),
}))
</script>

<template>
    <MarcoGrafico
        :titulo="titulo"
        :subtitulo="subtitulo"
        :tabla="tabla"
        :alto="alto"
        @update:ancho="ancho = $event"
    >
        <svg
            :width="ancho" :height="alto" class="lienzo"
            role="img" :aria-label="`${titulo}. ${subtitulo}`"
        >
            <g
                v-for="(f, i) in filas"
                :key="f.etiqueta"
                @pointerenter="activo = i"
                @pointerleave="activo = null"
            >
                <!-- zona sensible más grande que la marca: acertarle a una
                     barra de 26 px con el dedo no debería ser un reto -->
                <rect
                    :x="0" :y="i * (ALTO_BARRA + SEPARACION)"
                    :width="ancho" :height="ALTO_BARRA + SEPARACION"
                    fill="transparent"
                />

                <rect
                    v-if="activo === i"
                    :x="IZQ - 6" :y="i * (ALTO_BARRA + SEPARACION) - 2"
                    :width="g.w + 12" :height="ALTO_BARRA + 4"
                    fill="var(--superficie-2)" rx="3"
                />

                <text
                    class="cat"
                    :x="IZQ - 12"
                    :y="i * (ALTO_BARRA + SEPARACION) + ALTO_BARRA / 2 + 4"
                    text-anchor="end"
                >{{ f.etiqueta }}</text>

                <path :d="ruta(f.total, i * (ALTO_BARRA + SEPARACION))" :fill="colorDe(f)" />

                <!-- etiqueta directa: con diez barras, un tooltip por barra
                     obliga a pasar el cursor diez veces para leer diez números -->
                <text
                    class="val"
                    :x="g.x(f.total) + 8"
                    :y="i * (ALTO_BARRA + SEPARACION) + ALTO_BARRA / 2 + 4"
                >{{ numero(f.total) }}{{ unidad }}</text>
            </g>
        </svg>

        <div v-if="activo !== null && filas[activo]?.nota" class="nota-flotante">
            {{ filas[activo].nota }}
        </div>

        <template #nota><slot name="nota" /></template>
    </MarcoGrafico>
</template>

<style scoped>
.lienzo { display: block; }

.cat {
    font-size: 11.5px;
    fill: var(--ink-2);
}

.val {
    font-family: var(--mono);
    font-variant-numeric: tabular-nums;
    font-size: 11px;
    font-weight: 500;
    fill: var(--ink);
}

.nota-flotante {
    font-size: .74rem;
    color: var(--ink-2);
    background: var(--superficie-2);
    border-left: 3px solid var(--linea-fuerte);
    padding: .4rem .6rem;
    margin-top: .5rem;
    border-radius: 0 var(--r-sm) var(--r-sm) 0;
}
</style>
