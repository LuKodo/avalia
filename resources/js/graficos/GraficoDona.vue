<script setup>
import { computed, ref } from 'vue'
import MarcoGrafico from './MarcoGrafico.vue'
import { arco, colorSerie, numero } from './util'

/**
 * Reparto de un total entre pocas categorías.
 *
 * Se usa aquí y no en cualquier parte: una dona sirve para «de qué está hecho
 * este total», con seis categorías como mucho. Para comparar magnitudes entre
 * sí, las barras ganan siempre — el ojo compara longitudes mucho mejor que
 * ángulos. El total va en el centro porque es el número que da sentido a las
 * proporciones.
 */
const props = defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    /** [{ etiqueta, total, color? }] */
    datos: { type: Array, required: true },
    etiquetaTotal: { type: String, default: 'total' },
    alto: { type: Number, default: 260 },
})

const ancho = ref(400)
const activo = ref(null)

const SEPARADOR = 0.014   // ~0,8° de superficie entre segmentos

const total = computed(() => props.datos.reduce((s, d) => s + d.total, 0))

const segmentos = computed(() => {
    if (total.value === 0) return []

    const cx = Math.min(ancho.value, 320) / 2
    const cy = props.alto / 2
    const rExt = Math.min(cx, cy) - 12
    const rInt = rExt * 0.62

    let angulo = 0

    return props.datos.map((d, i) => {
        const fraccion = d.total / total.value
        const desde = angulo + SEPARADOR / 2
        const hasta = angulo + fraccion * Math.PI * 2 - SEPARADOR / 2
        angulo += fraccion * Math.PI * 2

        return {
            ...d,
            color: d.color ?? colorSerie(i),
            fraccion,
            porcentaje: fraccion * 100,
            d: arco(cx, cy, rExt, rInt, desde, Math.max(desde + 0.002, hasta)),
            cx, cy, rInt,
        }
    })
})

const centro = computed(() => segmentos.value[0] ?? { cx: 150, cy: props.alto / 2 })

const tabla = computed(() => ({
    columnas: ['Estado', 'Paquetes', 'Participación'],
    filas: props.datos.map((d) => [
        d.etiqueta,
        numero(d.total),
        `${((d.total / (total.value || 1)) * 100).toFixed(1).replace('.', ',')} %`,
    ]),
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
        <div class="dona">
            <svg
                :width="Math.min(ancho, 320)" :height="alto" class="lienzo"
                role="img" :aria-label="`${titulo}: reparto de ${numero(total)} paquetes`"
            >
                <g
                    v-for="(s, i) in segmentos"
                    :key="s.etiqueta"
                    @pointerenter="activo = i"
                    @pointerleave="activo = null"
                >
                    <path
                        :d="s.d" :fill="s.color"
                        :opacity="activo === null || activo === i ? 1 : .35"
                        stroke="var(--superficie)" stroke-width="1.5"
                    />
                </g>

                <text v-if="activo === null" class="centro-num" :x="centro.cx" :y="centro.cy - 2" text-anchor="middle">
                    {{ numero(total) }}
                </text>
                <text v-if="activo === null" class="centro-lbl" :x="centro.cx" :y="centro.cy + 16" text-anchor="middle">
                    {{ etiquetaTotal }}
                </text>

                <template v-else>
                    <text class="centro-num" :x="centro.cx" :y="centro.cy - 2" text-anchor="middle">
                        {{ segmentos[activo].porcentaje.toFixed(1).replace('.', ',') }} %
                    </text>
                    <text class="centro-lbl" :x="centro.cx" :y="centro.cy + 16" text-anchor="middle">
                        {{ numero(segmentos[activo].total) }} paquetes
                    </text>
                </template>
            </svg>

            <!-- La leyenda lleva el número al lado: obligar a pasar el cursor
                 por cada segmento para conocer su valor es esconder el dato. -->
            <ul class="detalle">
                <li
                    v-for="(s, i) in segmentos"
                    :key="s.etiqueta"
                    :class="{ atenuado: activo !== null && activo !== i }"
                    @pointerenter="activo = i"
                    @pointerleave="activo = null"
                >
                    <span class="marca" :style="{ background: s.color }" aria-hidden="true" />
                    <span class="nombre">{{ s.etiqueta }}</span>
                    <span class="cifra num">{{ numero(s.total) }}</span>
                    <span class="pct num">{{ s.porcentaje.toFixed(0) }} %</span>
                </li>
            </ul>
        </div>

        <template #nota><slot name="nota" /></template>
    </MarcoGrafico>
</template>

<style scoped>
.dona {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
}

.lienzo { display: block; flex: none; }

.detalle {
    list-style: none;
    margin: 0;
    padding: 0;
    flex: 1 1 200px;
    min-width: 190px;
    display: flex;
    flex-direction: column;
    gap: .1rem;
}

.detalle li {
    display: grid;
    grid-template-columns: 10px 1fr auto auto;
    align-items: center;
    gap: .5rem;
    font-size: .78rem;
    padding: .22rem .3rem;
    border-radius: var(--r-sm);
    cursor: default;
}

.detalle li:hover { background: var(--superficie-2); }
.detalle li.atenuado { opacity: .45; }

.marca { width: 10px; height: 10px; border-radius: 2px; }
.nombre { color: var(--ink-2); }
.cifra { color: var(--ink); font-weight: 500; font-size: .8rem; }
.pct { color: var(--ink-3); font-size: .72rem; min-width: 34px; text-align: right; }

.centro-num {
    font-family: var(--mono);
    font-size: 22px;
    font-weight: 600;
    fill: var(--ink);
    letter-spacing: -.03em;
}

.centro-lbl {
    font-family: var(--mono);
    font-size: 9.5px;
    letter-spacing: .12em;
    text-transform: uppercase;
    fill: var(--ink-3);
}
</style>
