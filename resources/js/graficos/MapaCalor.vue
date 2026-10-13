<script setup>
import { computed, ref } from 'vue'
import MarcoGrafico from './MarcoGrafico.vue'
import { SECUENCIAL, diaSemana, numero, pasoSecuencial } from './util'

/**
 * Demanda por día de la semana y hora.
 *
 * Es la vista que responde la decisión operativa con más impacto en el costo:
 * cuántos mensajeros poner en turno y a qué hora. Un promedio diario esconde
 * exactamente eso — que el martes a las 10 no se parece en nada al sábado a las
 * 17—, y esconderlo cuesta dinero todos los días.
 *
 * Escala secuencial de un solo matiz, claro a oscuro. Un arcoíris aquí haría
 * que «amarillo» pareciera una categoría distinta de «verde» en vez de un
 * valor mayor.
 */
const props = defineProps({
    titulo: { type: String, default: 'Demanda por día y hora' },
    subtitulo: { type: String, default: '' },
    /** [{ dia: 1-7, hora: 0-23, total }] */
    datos: { type: Array, required: true },
})

const ancho = ref(640)
const activo = ref(null)

const ETIQUETA_IZQ = 34
const ALTO_FILA = 22
const CAB = 18

const horas = computed(() => [...new Set(props.datos.map((d) => d.hora))].sort((a, b) => a - b))
const alto = computed(() => CAB + 7 * ALTO_FILA + 8)

const maximo = computed(() => Math.max(1, ...props.datos.map((d) => d.total)))

const celdas = computed(() => {
    const n = horas.value.length || 1
    const w = Math.max(120, ancho.value - ETIQUETA_IZQ - 6)
    const anchoCelda = w / n

    return props.datos.map((d) => ({
        ...d,
        x: ETIQUETA_IZQ + horas.value.indexOf(d.hora) * anchoCelda,
        y: CAB + (d.dia - 1) * ALTO_FILA,
        w: Math.max(1, anchoCelda - 2),
        h: ALTO_FILA - 2,
        color: pasoSecuencial(d.total, maximo.value),
    }))
})

const cadaHora = computed(() => {
    const anchoCelda = (ancho.value - ETIQUETA_IZQ) / (horas.value.length || 1)
    return Math.max(1, Math.ceil(26 / anchoCelda))
})

const tabla = computed(() => ({
    columnas: ['Día', ...horas.value.map((h) => `${h}:00`)],
    filas: [1, 2, 3, 4, 5, 6, 7].map((dia) => [
        diaSemana(dia),
        ...horas.value.map((h) => numero(props.datos.find((d) => d.dia === dia && d.hora === h)?.total ?? 0)),
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
        <svg
            :width="ancho" :height="alto" class="lienzo"
            role="img" :aria-label="`${titulo}. Máximo ${numero(maximo)} radicaciones en una franja`"
        >
            <g class="eje">
                <template v-for="(h, i) in horas" :key="`h${h}`">
                    <text
                        v-if="i % cadaHora === 0"
                        :x="ETIQUETA_IZQ + (i + 0.5) * ((ancho - ETIQUETA_IZQ - 6) / horas.length)"
                        :y="11" text-anchor="middle"
                    >{{ h }}</text>
                </template>

                <text
                    v-for="dia in 7" :key="`d${dia}`"
                    :x="ETIQUETA_IZQ - 8" :y="CAB + (dia - 1) * ALTO_FILA + ALTO_FILA / 2 + 3"
                    text-anchor="end"
                >{{ diaSemana(dia) }}</text>
            </g>

            <rect
                v-for="c in celdas"
                :key="`${c.dia}-${c.hora}`"
                :x="c.x" :y="c.y" :width="c.w" :height="c.h"
                :fill="c.color" rx="2"
                :stroke="activo && activo.dia === c.dia && activo.hora === c.hora ? 'var(--ink)' : 'none'"
                stroke-width="1.5"
                @pointerenter="activo = c"
                @pointerleave="activo = null"
            />
        </svg>

        <div class="pie">
            <div class="escala">
                <span class="escala-lbl">0</span>
                <span v-for="(c, i) in SECUENCIAL" :key="i" class="escala-paso" :style="{ background: c }" />
                <span class="escala-lbl num">{{ numero(maximo) }}</span>
            </div>

            <p v-if="activo" class="lectura">
                <strong>{{ diaSemana(activo.dia) }} {{ activo.hora }}:00</strong>
                · {{ numero(activo.total) }} radicaciones
            </p>
            <p v-else class="lectura atenuado">Pase el cursor sobre una franja para ver el detalle</p>
        </div>

        <template #nota><slot name="nota" /></template>
    </MarcoGrafico>
</template>

<style scoped>
.lienzo { display: block; }

.eje text {
    font-family: var(--mono);
    font-size: 9.5px;
    fill: var(--ink-3);
}

.pie {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: .5rem 1rem;
    margin-top: .6rem;
}

.escala { display: flex; align-items: center; gap: 2px; }

.escala-paso {
    width: 20px;
    height: 9px;
    border-radius: 1px;
}

.escala-lbl {
    font-family: var(--mono);
    font-size: .64rem;
    color: var(--ink-3);
    margin: 0 .35rem;
}

.lectura {
    font-size: .76rem;
    color: var(--ink-2);
    margin: 0;
}

.lectura.atenuado { color: var(--ink-3); }
</style>
