<script setup>
import { computed, ref } from 'vue'
import MarcoGrafico from './MarcoGrafico.vue'
import {
    colorSerie, dominioAgradable, escalaLineal, fechaCorta, fechaLarga,
    numero, rutaArea, rutaLinea, ticksAgradables,
} from './util'

/**
 * Serie temporal, una o varias.
 *
 * Un solo eje vertical, siempre. Dos escalas verticales en la misma gráfica
 * permiten que cualquier par de series se «cruce» donde uno quiera, y es el
 * error más común en tableros de operación: se ve una correlación que no
 * existe. Si hay dos medidas de escalas distintas, son dos gráficas.
 */
const props = defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    datos: { type: Array, required: true },
    campoX: { type: String, default: 'fecha' },
    /** [{ clave, nombre, area?: bool, color?: string }] */
    series: { type: Array, required: true },
    unidad: { type: String, default: '' },
    desdeCero: { type: Boolean, default: true },
    /** Línea de referencia: la meta de cumplimiento, por ejemplo. */
    meta: { type: Number, default: null },
    etiquetaMeta: { type: String, default: 'Meta' },
    /**
     * Par de claves cuya diferencia se rellena: ['radicados', 'entregados'].
     *
     * Es el arreglo de un desajuste entre lo que la gráfica decía y lo que
     * dibujaba. El pie afirmaba que «la brecha entre las dos líneas es el
     * trabajo acumulado», pero lo sombreado era el área bajo UNA de ellas: se
     * le pedía al lector que restase a ojo justo la magnitud que se le estaba
     * señalando como importante. Rellenar entre las dos convierte esa
     * magnitud en algo que se ve, en vez de algo que se calcula mirando.
     */
    banda: { type: Array, default: null },
    etiquetaBanda: { type: String, default: '' },
    decimales: { type: Number, default: 0 },
    alto: { type: Number, default: 240 },
})

const ancho = ref(640)
const activo = ref(null)

// El margen derecho reserva sitio para la cifra de remate. Sin esa reserva la
// etiqueta se salia del lienzo y quedaba cortada por el borde.
const M = { arriba: 12, derecha: 52, abajo: 26, izquierda: 44 }

const seriesConColor = computed(() =>
    props.series.map((s, i) => ({ ...s, color: s.color ?? colorSerie(i) }))
)

const g = computed(() => {
    const w = Math.max(120, ancho.value - M.izquierda - M.derecha)
    const h = Math.max(60, props.alto - M.arriba - M.abajo)

    const valores = props.datos.flatMap((d) =>
        props.series
            .map((s) => d[s.clave])
            .filter((v) => v !== null && v !== undefined)
    )

    if (props.meta !== null) valores.push(props.meta)

    const [d0, d1] = dominioAgradable(valores, { desdeCero: props.desdeCero })

    const x = escalaLineal([0, Math.max(1, props.datos.length - 1)], [M.izquierda, M.izquierda + w])
    const y = escalaLineal([d0, d1], [M.arriba + h, M.arriba])

    // En una serie de 30 días no caben 30 etiquetas: se muestran las que caben
    // sin encimarse, calculado sobre el ancho real y no a ojo.
    const cadaX = Math.max(1, Math.ceil(props.datos.length / Math.max(2, Math.floor(w / 62))))

    const trazos = seriesConColor.value.map((s) => {
        const puntos = props.datos.map((d, i) => ({
            x: x(i),
            y: d[s.clave] === null || d[s.clave] === undefined ? null : y(d[s.clave]),
        }))

        // El ultimo punto CON dato, que no siempre es el ultimo del arreglo:
        // una serie puede terminar en huecos.
        const ultimoIndice = [...props.datos].reduce(
            (acc, d, i) => (d[s.clave] === null || d[s.clave] === undefined ? acc : i), -1
        )

        return {
            ...s,
            puntos,
            linea: rutaLinea(puntos),
            area: s.area ? rutaArea(puntos, M.arriba + h) : null,
            ultimo: ultimoIndice >= 0 ? puntos[ultimoIndice] : null,
            ultimoValor: ultimoIndice >= 0 ? props.datos[ultimoIndice][s.clave] : null,
        }
    })

    /*
     * La banda: la primera serie de ida y la segunda de vuelta, cerrando un
     * poligono. Se corta en cuanto a cualquiera de las dos le falta un dato;
     * unir por encima de un hueco afirmaria una diferencia que nadie midio.
     */
    let banda = null

    if (props.banda?.length === 2) {
        const [ka, kb] = props.banda
        const tramos = []
        let ida = []
        let vuelta = []

        props.datos.forEach((d, i) => {
            const va = d[ka]
            const vb = d[kb]

            if (va === null || va === undefined || vb === null || vb === undefined) {
                if (ida.length > 1) tramos.push([...ida, ...vuelta.reverse()])
                ida = []
                vuelta = []

                return
            }

            ida.push(`${x(i)},${y(va)}`)
            vuelta.push(`${x(i)},${y(vb)}`)
        })

        if (ida.length > 1) tramos.push([...ida, ...vuelta.reverse()])

        banda = tramos.map((t) => `M${t.join('L')}Z`).join(' ')
    }

    /*
     * Los remates, separados si coinciden.
     *
     * Cuando las dos series acaban en el mismo valor -que aqui pasa siempre que
     * el dia cierra con todo entregado- las dos cifras caian exactamente en el
     * mismo punto y se leia una encima de otra. Se apartan lo justo para que
     * ambas se lean, y como se apartan igual hacia arriba y hacia abajo, nadie
     * puede confundir el desplazamiento con una diferencia de valor.
     */
    const SEPARACION = 13
    const remates = trazos
        .filter((t) => t.ultimo)
        .map((t) => ({ clave: t.clave, color: t.color, x: t.ultimo.x, y: t.ultimo.y, ey: t.ultimo.y, valor: t.ultimoValor }))
        .sort((a, b) => a.y - b.y)

    for (let i = 1; i < remates.length; i++) {
        const separacionReal = remates[i].ey - remates[i - 1].ey

        if (separacionReal < SEPARACION) {
            const empuje = (SEPARACION - separacionReal) / 2
            remates[i - 1].ey -= empuje
            remates[i].ey += empuje
        }
    }

    return { x, y, ticks: ticksAgradables(d0, d1, 4), cadaX, trazos, banda, remates, base: M.arriba + h }
})

function alMover(evento) {
    const caja = evento.currentTarget.getBoundingClientRect()
    const i = Math.round(g.value.x.invertir(evento.clientX - caja.left))

    activo.value = i >= 0 && i < props.datos.length ? i : null
}

const posicionTooltip = computed(() => {
    if (activo.value === null) return 0
    return Math.min(Math.max(g.value.x(activo.value), 92), Math.max(92, ancho.value - 92))
})

const tabla = computed(() => ({
    columnas: ['Fecha', ...props.series.map((s) => s.nombre)],
    filas: props.datos.map((d) => [
        fechaLarga(d[props.campoX]),
        ...props.series.map((s) => (d[s.clave] === null || d[s.clave] === undefined ? 'sin datos' : numero(d[s.clave]))),
    ]),
}))

function formatoValor(v) {
    if (v === null || v === undefined) return 'sin datos'

    return props.decimales > 0
        ? `${v.toFixed(props.decimales).replace('.', ',')} ${props.unidad}`.trim()
        : `${numero(v)} ${props.unidad}`.trim()
}
</script>

<template>
    <MarcoGrafico
        :titulo="titulo"
        :subtitulo="subtitulo"
        :leyenda="seriesConColor.map((s) => ({ nombre: s.nombre, color: s.color }))"
        :tabla="tabla"
        :alto="alto"
        @update:ancho="ancho = $event"
    >
        <svg
            :width="ancho"
            :height="alto"
            class="lienzo"
            role="img"
            :aria-label="`${titulo}. ${subtitulo}`"
            @pointermove="alMover"
            @pointerleave="activo = null"
        >
            <!-- rejilla: recesiva, nunca compite con los datos -->
            <g class="rejilla">
                <line
                    v-for="t in g.ticks"
                    :key="`r${t}`"
                    :x1="M.izquierda" :x2="ancho - M.derecha"
                    :y1="g.y(t)" :y2="g.y(t)"
                />
            </g>

            <!-- meta: discontinua y etiquetada, para no confundirla con un dato -->
            <template v-if="meta !== null">
                <line
                    class="meta"
                    :x1="M.izquierda" :x2="ancho - M.derecha"
                    :y1="g.y(meta)" :y2="g.y(meta)"
                />
                <text class="meta-txt" :x="ancho - M.derecha" :y="g.y(meta) - 5" text-anchor="end">
                    {{ etiquetaMeta }} {{ meta }}{{ unidad }}
                </text>
            </template>

            <g class="eje-txt">
                <text
                    v-for="t in g.ticks"
                    :key="`e${t}`"
                    :x="M.izquierda - 8" :y="g.y(t) + 4"
                    text-anchor="end"
                >{{ numero(t) }}</text>
            </g>

            <g class="eje-txt">
                <template v-for="(d, i) in datos" :key="`x${i}`">
                    <text
                        v-if="i % g.cadaX === 0"
                        :x="g.x(i)" :y="alto - 8"
                        text-anchor="middle"
                    >{{ fechaCorta(d[campoX]) }}</text>
                </template>
            </g>

            <!-- La banda de brecha, primero de todo: es fondo, no dato.
                 Va en tinta neutra y NO en un color de serie, para que nadie
                 la lea como una tercera serie. -->
            <path v-if="g.banda" :d="g.banda" class="banda" />

            <!-- las áreas van primero: nunca tapan las líneas -->
            <template v-for="s in g.trazos" :key="`a${s.clave}`">
                <path v-if="s.area" :d="s.area" :fill="s.color" opacity=".10" />
            </template>

            <path
                v-for="s in g.trazos"
                :key="`l${s.clave}`"
                :d="s.linea" fill="none" :stroke="s.color"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            />

            <!--
                El ultimo valor, escrito al final de su linea.

                Es la cifra que se busca primero al abrir un tablero -«como
                vamos hoy»- y sin esto habia que perseguirla con el cursor
                hasta el borde derecho. Se oculta mientras la cruceta esta
                activa para no competir con el tooltip.
            -->
            <g v-if="activo === null" class="remate">
                <template v-for="r in g.remates" :key="`f${r.clave}`">
                    <circle :cx="r.x" :cy="r.y" r="3.2" :fill="r.color" />
                    <!-- Un hilo del punto a su cifra cuando hubo que apartarla,
                         para que se sepa cual pertenece a cual. -->
                    <line
                        v-if="Math.abs(r.ey - r.y) > 0.5"
                        class="remate-hilo"
                        :x1="r.x + 3.6" :y1="r.y" :x2="r.x + 7" :y2="r.ey - 3.5"
                        :stroke="r.color"
                    />
                    <text
                        :x="r.x + 8" :y="r.ey + 3.5"
                        text-anchor="start" :fill="r.color"
                    >{{ decimales > 0 ? r.valor.toFixed(decimales).replace('.', ',') : numero(r.valor) }}</text>
                </template>
            </g>

            <!-- cruceta -->
            <g v-if="activo !== null">
                <line
                    class="cruceta"
                    :x1="g.x(activo)" :x2="g.x(activo)"
                    :y1="M.arriba" :y2="g.base"
                />
                <template v-for="s in g.trazos" :key="`p${s.clave}`">
                    <circle
                        v-if="s.puntos[activo] && s.puntos[activo].y !== null"
                        :cx="s.puntos[activo].x" :cy="s.puntos[activo].y" r="4.5"
                        :fill="s.color" stroke="var(--superficie)" stroke-width="2"
                    />
                </template>
            </g>
        </svg>

        <div v-if="activo !== null" class="tooltip" :style="{ left: `${posicionTooltip}px` }">
            <p class="tooltip-cab">{{ fechaLarga(datos[activo][campoX]) }}</p>
            <p v-for="s in seriesConColor" :key="s.clave" class="tooltip-fila">
                <span class="tooltip-marca" :style="{ background: s.color }" />
                <span class="tooltip-nombre">{{ s.nombre }}</span>
                <span class="tooltip-valor">{{ formatoValor(datos[activo][s.clave]) }}</span>
            </p>
        </div>

        <template #nota><slot name="nota" /></template>
    </MarcoGrafico>
</template>

<style scoped>
.lienzo { display: block; touch-action: pan-y; }

.rejilla line { stroke: var(--rejilla); stroke-width: 1; }

.banda { fill: var(--ink-3); opacity: .13; }

.remate-hilo { stroke-width: 1; opacity: .55; }

.remate text {
    font-family: var(--sans);
    font-variant-numeric: tabular-nums;
    font-size: 11.5px;
    font-weight: 600;
    paint-order: stroke;
    stroke: var(--superficie);
    stroke-width: 3.5px;
    stroke-linejoin: round;
}

.eje-txt text {
    font-family: var(--sans);
    font-variant-numeric: tabular-nums;
    font-size: 10.5px;
    fill: var(--ink-3);
}

.meta {
    stroke: var(--ink-3);
    stroke-width: 1.25;
    stroke-dasharray: 5 4;
    opacity: .7;
}

.meta-txt {
    font-family: var(--mono);
    font-size: 9.5px;
    fill: var(--ink-3);
    letter-spacing: .04em;
}

.cruceta { stroke: var(--linea-fuerte); stroke-width: 1; }

.tooltip {
    position: absolute;
    top: 1rem;
    transform: translateX(-50%);
    background: var(--superficie);
    border: 1px solid var(--linea-fuerte);
    border-radius: var(--r-sm);
    box-shadow: var(--sombra);
    padding: .5rem .6rem;
    pointer-events: none;
    min-width: 170px;
    z-index: 5;
}

.tooltip-cab {
    font-size: .72rem;
    font-weight: 600;
    margin: 0 0 .35rem;
    color: var(--ink);
    text-transform: capitalize;
}

.tooltip-fila {
    display: grid;
    grid-template-columns: 10px 1fr auto;
    align-items: center;
    gap: .4rem;
    margin: 0;
    font-size: .74rem;
    line-height: 1.7;
}

.tooltip-marca { width: 9px; height: 9px; border-radius: 2px; }
.tooltip-nombre { color: var(--ink-2); }

.tooltip-valor {
    font-family: var(--mono);
    font-variant-numeric: tabular-nums;
    color: var(--ink);
    font-weight: 500;
}
</style>
