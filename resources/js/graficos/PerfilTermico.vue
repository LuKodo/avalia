<script setup>
import { computed, ref } from 'vue'
import MarcoGrafico from './MarcoGrafico.vue'
import { escalaLineal, rutaLinea, ticksAgradables } from './util'

/**
 * Perfil térmico de un trayecto.
 *
 * Reemplaza la lista vertical de lecturas que había antes. Con dos lecturas la
 * lista se leía bien; con telemetría continua se volvía una columna de treinta
 * números que nadie recorre y en la que un pico de 11 °C pasa igual de
 * desapercibido que si no estuviera. Una serie temporal responde de un vistazo
 * lo que la lista escondía: cuándo se salió, cuánto duró y qué tan lejos llegó.
 *
 * La banda del rango exigido es el elemento principal, no un adorno: lo que se
 * juzga no es la temperatura en sí, sino si estuvo dentro. Por eso se dibuja
 * primero y detrás de todo, como el terreno sobre el que corre la línea.
 */
const props = defineProps({
    /** [{ valor_c, registrada_en, etiqueta, fuera_de_rango, fuente }] */
    lecturas: { type: Array, required: true },
    /** { min, max } */
    rango: { type: Object, required: true },
    /** Temperatura cinética media, para dibujarla como referencia. */
    mkt: { type: Number, default: null },
    alto: { type: Number, default: 200 },
})

const ancho = ref(560)
const activo = ref(null)

const M = { arriba: 10, derecha: 12, abajo: 24, izquierda: 40 }

const puntos = computed(() =>
    props.lecturas
        .filter((l) => l.valor_c !== null && l.registrada_en)
        .map((l) => ({ ...l, t: Date.parse(l.registrada_en) }))
        .sort((a, b) => a.t - b.t)
)

const g = computed(() => {
    const w = Math.max(80, ancho.value - M.izquierda - M.derecha)
    const h = Math.max(60, props.alto - M.arriba - M.abajo)
    const p = puntos.value

    if (p.length === 0) return null

    /*
     * El dominio vertical siempre abarca la banda completa, aunque las lecturas
     * no la toquen. Recortarlo a los datos haría que un trayecto impecable se
     * dibujara pegado a los bordes, dando la impresión visual de estar al
     * límite justo cuando no lo está.
     */
    const valores = p.map((d) => d.valor_c)
    const lo = Math.min(props.rango.min, ...valores)
    const hi = Math.max(props.rango.max, ...valores)
    const margen = Math.max(0.5, (hi - lo) * 0.12)

    const t0 = p[0].t
    const t1 = p[p.length - 1].t
    const span = Math.max(1, t1 - t0)

    const x = escalaLineal([0, span], [M.izquierda, M.izquierda + w])
    const y = escalaLineal([lo - margen, hi + margen], [M.arriba + h, M.arriba])

    const coords = p.map((d) => ({ ...d, cx: x(d.t - t0), cy: y(d.valor_c) }))

    return {
        w,
        h,
        x,
        y,
        t0,
        span,
        coords,
        linea: rutaLinea(coords.map((c) => ({ x: c.cx, y: c.cy }))),
        bandaY: y(props.rango.max),
        bandaAlto: Math.max(1, y(props.rango.min) - y(props.rango.max)),
        mktY: props.mkt === null ? null : y(props.mkt),
        ticks: ticksAgradables(lo - margen, hi + margen, 4),
    }
})

/** Solo se marcan las lecturas fuera de rango: son las que hay que mirar. */
const fuera = computed(() => (g.value?.coords ?? []).filter((c) => c.fuera_de_rango))

function alMover(evento) {
    const geo = g.value
    if (!geo) return

    const caja = evento.currentTarget.getBoundingClientRect()
    const px = ((evento.clientX - caja.left) / caja.width) * ancho.value

    let mejor = null
    let dist = Infinity

    for (const c of geo.coords) {
        const d = Math.abs(c.cx - px)
        if (d < dist) {
            dist = d
            mejor = c
        }
    }

    activo.value = mejor
}

function horaDe(iso) {
    return new Date(iso).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })
}

const tabla = computed(() => ({
    columnas: ['Hora', 'Momento', 'Temperatura', 'Estado', 'Fuente'],
    filas: puntos.value.map((l) => [
        horaDe(l.registrada_en),
        l.etiqueta ?? l.momento ?? '—',
        `${l.valor_c} °C`,
        l.fuera_de_rango ? 'Fuera de rango' : 'En rango',
        l.fuente ?? '—',
    ]),
}))

const subtitulo = computed(() => {
    const n = puntos.value.length
    const f = fuera.value.length

    return f === 0
        ? `${n} lectura(s) · todas dentro de ${props.rango.min}–${props.rango.max} °C`
        : `${n} lectura(s) · ${f} fuera de ${props.rango.min}–${props.rango.max} °C`
})
</script>

<template>
    <MarcoGrafico
        titulo="Perfil térmico del trayecto"
        :subtitulo="subtitulo"
        :tabla="tabla"
        :alto="alto"
        @update:ancho="ancho = $event"
    >
        <svg
            v-if="g"
            class="lienzo"
            :viewBox="`0 0 ${ancho} ${alto}`"
            :height="alto"
            role="img"
            :aria-label="`Perfil térmico. ${subtitulo}`"
            @pointermove="alMover"
            @pointerleave="activo = null"
        >
            <!-- La banda del rango exigido, detrás de todo. -->
            <rect
                :x="M.izquierda" :y="g.bandaY"
                :width="g.w" :height="g.bandaAlto"
                class="banda"
            />
            <line
                :x1="M.izquierda" :x2="M.izquierda + g.w"
                :y1="g.bandaY" :y2="g.bandaY"
                class="banda-borde"
            />
            <line
                :x1="M.izquierda" :x2="M.izquierda + g.w"
                :y1="g.bandaY + g.bandaAlto" :y2="g.bandaY + g.bandaAlto"
                class="banda-borde"
            />

            <!-- Rejilla y eje vertical, deliberadamente tenues. -->
            <g class="eje">
                <template v-for="t in g.ticks" :key="t">
                    <line :x1="M.izquierda" :x2="M.izquierda + g.w" :y1="g.y(t)" :y2="g.y(t)" class="rejilla" />
                    <text :x="M.izquierda - 6" :y="g.y(t) + 3" text-anchor="end" class="eje-texto">{{ t }}</text>
                </template>
            </g>

            <!-- MKT: la temperatura equivalente del viaje entero. -->
            <template v-if="g.mktY !== null">
                <line
                    :x1="M.izquierda" :x2="M.izquierda + g.w"
                    :y1="g.mktY" :y2="g.mktY"
                    class="mkt-linea"
                />
                <text :x="M.izquierda + g.w" :y="g.mktY - 4" text-anchor="end" class="mkt-texto">
                    MKT {{ mkt }} °C
                </text>
            </template>

            <path :d="g.linea" class="linea" />

            <!-- Marcas solo donde hay algo que mirar. -->
            <circle
                v-for="(c, i) in fuera" :key="`f${i}`"
                :cx="c.cx" :cy="c.cy" r="3.5"
                class="punto-fuera"
            />

            <template v-if="activo">
                <line
                    :x1="activo.cx" :x2="activo.cx"
                    :y1="M.arriba" :y2="M.arriba + g.h"
                    class="cruceta"
                />
                <circle :cx="activo.cx" :cy="activo.cy" r="4" class="punto-activo" />
            </template>
        </svg>

        <p v-else class="sin-datos">Sin lecturas para graficar.</p>

        <p v-if="activo" class="globo num" :class="{ 'globo--fuera': activo.fuera_de_rango }">
            {{ horaDe(activo.registrada_en) }} · <strong>{{ activo.valor_c }} °C</strong>
            <span class="globo-momento">{{ activo.etiqueta ?? activo.momento }}</span>
        </p>
    </MarcoGrafico>
</template>

<style scoped>
.lienzo { width: 100%; display: block; touch-action: pan-y; }

.banda { fill: var(--bien-suave); }
.banda-borde { stroke: var(--bien); stroke-width: 1; stroke-dasharray: 3 3; opacity: .55; }

.rejilla { stroke: var(--linea); stroke-width: 1; }
.eje-texto {
    font-size: 9.5px; fill: var(--ink-3);
    font-variant-numeric: tabular-nums;
}

.linea { fill: none; stroke: var(--serie-1); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }

/* Anillo del color de la superficie: separa la marca de la línea que pasa por
   debajo, que si no se leen como una sola mancha. */
.punto-fuera { fill: var(--critico); stroke: var(--superficie); stroke-width: 2; }
.punto-activo { fill: var(--serie-1); stroke: var(--superficie); stroke-width: 2; }
.cruceta { stroke: var(--ink-3); stroke-width: 1; stroke-dasharray: 2 3; opacity: .6; }

.mkt-linea { stroke: var(--acento); stroke-width: 1.5; stroke-dasharray: 5 4; opacity: .8; }
.mkt-texto { font-size: 9px; fill: var(--acento); font-weight: 600; }

.globo {
    display: flex; flex-wrap: wrap; align-items: baseline; gap: .4rem;
    margin: .35rem 0 0; font-size: .72rem; color: var(--ink-2);
    font-variant-numeric: tabular-nums;
}
.globo--fuera strong { color: var(--critico); }
.globo-momento { color: var(--ink-3); font-size: .66rem; }

.sin-datos { font-size: .75rem; color: var(--ink-3); margin: 0; }
</style>
