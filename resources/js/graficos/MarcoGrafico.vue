<script setup>
import { ref, watch } from 'vue'
import { useTamano } from './useTamano'

/**
 * Marco común de toda gráfica: título, leyenda, medición del ancho y vista de
 * tabla.
 *
 * La vista de tabla no es un extra: es lo que hace que la información siga
 * siendo accesible para quien usa lector de pantalla, para quien no distingue
 * los matices y para quien necesita copiar los números a otro lado. Cada
 * gráfica la trae, siempre.
 */
defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    // [{ nombre, color }] — obligatoria desde dos series; con una sola sobra,
    // porque el título ya la nombra.
    leyenda: { type: Array, default: () => [] },
    tabla: { type: Object, default: null },  // { columnas: [], filas: [[]] }
    alto: { type: Number, default: 240 },
})

/**
 * El ancho medido se emite en vez de pasarse solo por slot: cada gráfica lo
 * guarda en un `ref` propio y deriva su geometría con `computed`. Calcular la
 * geometría dentro de la plantilla, a partir de un slot prop, la recalcularía
 * en cada repintado —incluido cada movimiento del cursor sobre la cruceta—.
 */
const emit = defineEmits(['update:ancho'])

const { contenedor, ancho } = useTamano()
const verTabla = ref(false)

watch(ancho, (v) => emit('update:ancho', v), { immediate: true })
</script>

<template>
    <figure class="grafico tarjeta">
        <figcaption class="tarjeta-cab">
            <div class="grafico-titulos">
                <h3 class="tarjeta-titulo">{{ titulo }}</h3>
                <p v-if="subtitulo" class="grafico-sub">{{ subtitulo }}</p>
            </div>

            <button
                v-if="tabla"
                type="button"
                class="grafico-alterna"
                :aria-pressed="verTabla"
                @click="verTabla = !verTabla"
            >
                {{ verTabla ? 'Ver gráfica' : 'Ver tabla' }}
            </button>
        </figcaption>

        <ul v-if="leyenda.length > 1 && !verTabla" class="leyenda">
            <li v-for="s in leyenda" :key="s.nombre">
                <span class="leyenda-marca" :style="{ background: s.color }" aria-hidden="true" />
                {{ s.nombre }}
            </li>
        </ul>

        <div v-show="!verTabla" ref="contenedor" class="grafico-lienzo" :style="{ minHeight: alto + 'px' }">
            <slot :ancho="ancho" />
        </div>

        <div v-if="tabla && verTabla" class="desliza">
            <table class="grafico-tabla">
                <caption class="solo-lector">{{ titulo }}. {{ subtitulo }}</caption>
                <thead>
                    <tr>
                        <th v-for="c in tabla.columnas" :key="c" scope="col">{{ c }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(fila, i) in tabla.filas" :key="i">
                        <td
                            v-for="(celda, j) in fila"
                            :key="j"
                            :class="{ num: j > 0 }"
                            :scope="j === 0 ? 'row' : null"
                        >{{ celda }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="$slots.nota && !verTabla" class="grafico-nota">
            <slot name="nota" />
        </p>
    </figure>
</template>

<style scoped>
.grafico { margin: 0; display: flex; flex-direction: column; }

.grafico-titulos { min-width: 0; }

.grafico-sub {
    font-size: .74rem;
    color: var(--ink-3);
    margin: .1rem 0 0;
}

.grafico-alterna {
    flex: none;
    border: 1px solid var(--linea-fuerte);
    background: var(--superficie);
    border-radius: var(--r-sm);
    padding: .25rem .6rem;
    font-size: .72rem;
    font-weight: 500;
    color: var(--ink-2);
}

.grafico-alterna:hover { background: var(--superficie-3); }
.grafico-alterna[aria-pressed="true"] { border-color: var(--acento); color: var(--acento-ink); }

.leyenda {
    display: flex;
    flex-wrap: wrap;
    gap: .3rem 1rem;
    list-style: none;
    margin: 0;
    padding: .6rem .9rem 0;
    font-size: .76rem;
    color: var(--ink-2);
}

.leyenda li { display: flex; align-items: center; gap: .35rem; }

.leyenda-marca {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    flex: none;
}

.grafico-lienzo { padding: .6rem .9rem .9rem; position: relative; }

.grafico-tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: .8rem;
}

.grafico-tabla th,
.grafico-tabla td {
    text-align: left;
    padding: .45rem .7rem;
    border-bottom: 1px solid var(--linea);
    white-space: nowrap;
}

.grafico-tabla thead th {
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-3);
    font-weight: 500;
    background: var(--superficie-2);
}

.grafico-tabla td.num { text-align: right; }

.grafico-nota {
    font-size: .73rem;
    color: var(--ink-3);
    margin: 0;
    padding: 0 .9rem .9rem;
    line-height: 1.45;
}
</style>
