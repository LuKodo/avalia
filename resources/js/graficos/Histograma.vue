<script setup>
import { computed, ref } from 'vue'
import MarcoGrafico from './MarcoGrafico.vue'
import { escalaBanda, escalaLineal, numero, ticksAgradables } from './util'

/**
 * Distribución de las lecturas de temperatura.
 *
 * El promedio no basta para cadena de frío: dos operaciones con la misma media
 * pueden tener perfiles de riesgo opuestos. Una con todas las lecturas apiñadas
 * en 5 °C está bajo control; otra que oscila entre 1 y 9 °C está incumpliendo
 * la mitad del tiempo aunque su promedio dé igual. La forma es el dato.
 *
 * La banda del rango exigido va pintada detrás, no como línea: separa a golpe
 * de vista lo que cumple de lo que no.
 */
const props = defineProps({
    titulo: { type: String, default: 'Distribución de temperatura' },
    subtitulo: { type: String, default: '' },
    /** [{ grado, total }] */
    datos: { type: Array, required: true },
    rango: { type: Object, default: () => ({ min: 2, max: 8 }) },
    alto: { type: Number, default: 220 },
})

const ancho = ref(640)
const activo = ref(null)

// Arriba hay que dejar sitio al rotulo del rango exigido; a la derecha, a la
// unidad, que antes se cortaba contra el borde.
const M = { arriba: 26, derecha: 24, abajo: 30, izquierda: 40 }

const grados = computed(() => props.datos.map((d) => d.grado))

const g = computed(() => {
    const w = Math.max(80, ancho.value - M.izquierda - M.derecha)
    const h = Math.max(60, props.alto - M.arriba - M.abajo)

    const max = Math.max(1, ...props.datos.map((d) => d.total))
    const x = escalaBanda(grados.value, [M.izquierda, M.izquierda + w], 0.18)
    const y = escalaLineal([0, max], [M.arriba + h, M.arriba])

    const barras = props.datos.map((d) => ({
        ...d,
        x: x(d.grado),
        y: y(d.total),
        w: x.ancho,
        h: M.arriba + h - y(d.total),
        // Dentro o fuera del rango: el color lo dice, y la etiqueta del eje
        // también, para que no dependa solo del color.
        dentro: d.grado >= props.rango.min && d.grado < props.rango.max,
    }))

    // Banda del rango exigido, en coordenadas de la escala de banda.
    const desde = x(grados.value.find((v) => v >= props.rango.min) ?? props.rango.min)
    const hastaGrado = [...grados.value].reverse().find((v) => v < props.rango.max)
    const hasta = hastaGrado !== undefined ? x(hastaGrado) + x.ancho : desde

    return { x, y, h, barras, ticks: ticksAgradables(0, max, 3), banda: { desde, ancho: Math.max(0, hasta - desde) } }
})

const fuera = computed(() => props.datos.filter((d) => d.grado < props.rango.min || d.grado >= props.rango.max)
    .reduce((s, d) => s + d.total, 0))

const total = computed(() => props.datos.reduce((s, d) => s + d.total, 0))

const tabla = computed(() => ({
    columnas: ['Temperatura', 'Lecturas', 'Dentro del rango'],
    filas: props.datos.map((d) => [
        `${d.grado} a ${d.grado + 1} °C`,
        numero(d.total),
        d.grado >= props.rango.min && d.grado < props.rango.max ? 'Sí' : 'No',
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
            role="img"
            :aria-label="`${titulo}. ${numero(fuera)} de ${numero(total)} lecturas fuera del rango ${rango.min} a ${rango.max} grados`"
        >
            <!--
                La banda del rango exigido.

                Antes iba al 7 % de opacidad: existia en el codigo y no en la
                pantalla. Ahora lleva relleno legible y un filo a cada lado,
                porque lo que separa es el limite entre cumplir y no cumplir
                -no un matiz decorativo-. Las lecturas fuera son pocas y por
                tanto bajas; la forma de hacerlas visibles es senalar la zona,
                no inflar la barra, que seria falsear el dato.
            -->
            <template v-if="g.banda.ancho > 0">
                <rect
                    :x="g.banda.desde" :y="M.arriba"
                    :width="g.banda.ancho" :height="g.h"
                    class="banda-ok"
                />
                <line
                    v-for="bx in [g.banda.desde, g.banda.desde + g.banda.ancho]" :key="bx"
                    class="banda-filo"
                    :x1="bx" :x2="bx" :y1="M.arriba" :y2="M.arriba + g.h"
                />
                <text
                    class="banda-txt"
                    :x="g.banda.desde + g.banda.ancho / 2" :y="M.arriba - 9"
                    text-anchor="middle"
                >Rango exigido {{ rango.min }} – {{ rango.max }} °C</text>
            </template>

            <g class="rejilla">
                <line
                    v-for="t in g.ticks" :key="`r${t}`"
                    :x1="M.izquierda" :x2="ancho - M.derecha"
                    :y1="g.y(t)" :y2="g.y(t)"
                />
            </g>

            <g class="eje-txt">
                <text
                    v-for="t in g.ticks" :key="`e${t}`"
                    :x="M.izquierda - 7" :y="g.y(t) + 4" text-anchor="end"
                >{{ numero(t) }}</text>
            </g>

            <g
                v-for="b in g.barras" :key="b.grado"
                @pointerenter="activo = b" @pointerleave="activo = null"
            >
                <rect
                    :x="b.x" :y="M.arriba" :width="b.w" :height="g.h"
                    fill="transparent"
                />
                <rect
                    :x="b.x" :y="b.y" :width="b.w" :height="Math.max(1, b.h)"
                    :fill="b.dentro ? 'var(--serie-1)' : 'var(--critico)'"
                    rx="2"
                    :opacity="activo === null || activo.grado === b.grado ? 1 : .45"
                />
                <text
                    class="eje-txt-x"
                    :x="b.x + b.w / 2" :y="alto - 16" text-anchor="middle"
                >{{ b.grado }}</text>
            </g>

            <text class="unidad" :x="ancho - M.derecha" :y="alto - 4" text-anchor="end">°C</text>
        </svg>

        <div class="pie">
            <p v-if="activo" class="lectura">
                <strong class="num">{{ numero(activo.total) }}</strong> lecturas entre
                {{ activo.grado }} y {{ activo.grado + 1 }} °C
                <span class="pastilla" :class="activo.dentro ? 'pastilla--bien' : 'pastilla--critico'">
                    {{ activo.dentro ? 'En rango' : 'Excursión' }}
                </span>
            </p>
            <p v-else class="lectura atenuado">
                Rango exigido {{ rango.min }} – {{ rango.max }} °C ·
                <strong class="num">{{ numero(fuera) }}</strong> de {{ numero(total) }} lecturas fuera
            </p>
        </div>

        <template #nota><slot name="nota" /></template>
    </MarcoGrafico>
</template>

<style scoped>
.lienzo { display: block; }

.rejilla line { stroke: var(--rejilla); stroke-width: 1; }

.eje-txt text, .eje-txt-x, .unidad {
    font-family: var(--sans);
    font-variant-numeric: tabular-nums;
    font-size: 10.5px;
    fill: var(--ink-3);
}

.banda-ok { fill: var(--bien); opacity: .09; }

.banda-filo {
    stroke: var(--bien);
    stroke-width: 1;
    stroke-dasharray: 3 3;
    opacity: .55;
}

.banda-txt {
    font-family: var(--sans);
    font-size: 10.5px;
    font-weight: 600;
    fill: var(--bien-ink);
}

.pie { margin-top: .5rem; }

.lectura {
    font-size: .78rem;
    color: var(--ink-2);
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .4rem;
}

.lectura.atenuado { color: var(--ink-3); }
.lectura strong { color: var(--ink); font-weight: 600; }
</style>
