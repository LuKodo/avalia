<script setup>
import { computed, onMounted, ref } from 'vue'
import Chispograma from './Chispograma.vue'
import { decimal, numero } from './util'

/**
 * Titular. No es una gráfica: es un número que se lee de un vistazo, con el
 * contexto mínimo para saber si está bien o mal.
 *
 * Tres decisiones de diseño que vale la pena dejar escritas:
 *
 *  · El número va en League Spartan —la fuente del logotipo— y no en
 *    monoespaciado. El monoespaciado alinea columnas de datos; aquí no hay
 *    columna que alinear, hay una cifra que tiene que imponerse. Se conserva
 *    `tabular-nums` para que al actualizarse no baile de ancho.
 *
 *  · La chispa va DEBAJO y a todo el ancho, no al lado. Al lado competía con el
 *    número por el mismo renglón y quedaba aplastada; abajo hace de zócalo y
 *    todas las tarjetas comparten la misma estructura.
 *
 *  · El icono de la pastilla es un SVG, no el carácter «✓». Ese carácter
 *    depende de la fuente que lo resuelva, y en la pila monoespaciada del
 *    sistema se dibujaba alto y fino: en pantalla se leía como un signo de
 *    raíz cuadrada. Un dictamen de cumplimiento no puede depender de qué
 *    fuentes tenga instaladas quien mira.
 */
const props = defineProps({
    etiqueta: { type: String, required: true },
    valor: { type: [Number, String, null], default: null },
    unidad: { type: String, default: '' },
    decimales: { type: Number, default: 0 },
    meta: { type: Number, default: null },
    // Mayor es mejor (cumplimiento) o menor es mejor (tasa de novedades).
    mayorEsMejor: { type: Boolean, default: true },
    serie: { type: Array, default: () => [] },
    color: { type: String, default: 'var(--acento)' },
    nota: { type: String, default: '' },
    // El indicador del que vive la operación. Uno por tablero: si se destacan
    // tres, no se destaca ninguno.
    destacado: { type: Boolean, default: false },
})

const numerico = computed(() => typeof props.valor === 'number' && Number.isFinite(props.valor))

const formatear = (v) => (props.decimales > 0 ? decimal(v, props.decimales) : numero(v))

/**
 * El valor que se pinta.
 *
 * Arranca en cero y sube hasta el real. No es un adorno: el recuento dirige la
 * mirada al número en cuanto carga la pantalla, que es exactamente donde debe
 * empezar a leerse un tablero. Quien pidió menos movimiento lo ve quieto.
 */
const mostrado = ref(null)

onMounted(() => {
    if (! numerico.value) return

    const destino = props.valor
    const quieto = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

    if (quieto) {
        mostrado.value = destino

        return
    }

    const desde = performance.now()
    const DURACION = 620

    const paso = (ahora) => {
        const t = Math.min(1, (ahora - desde) / DURACION)
        // Desaceleración cúbica: arranca rápido y se posa. Una interpolación
        // lineal parece un contador de gasolinera.
        const suave = 1 - Math.pow(1 - t, 3)

        mostrado.value = destino * suave

        if (t < 1) requestAnimationFrame(paso)
        else mostrado.value = destino
    }

    requestAnimationFrame(paso)
})

const texto = computed(() => {
    if (props.valor === null || props.valor === undefined) return '—'
    if (typeof props.valor === 'string') return props.valor
    if (! numerico.value) return '—'

    return formatear(mostrado.value ?? props.valor)
})

/**
 * Comparación contra la meta.
 *
 * La dirección importa: en cumplimiento, más es mejor; en tasa de novedades,
 * más es peor. Un texto genérico como «bajo la meta» dice lo contrario de la
 * realidad en la mitad de los indicadores, así que la frase se arma con el
 * signo real de la diferencia y no con el hecho de cumplir o no.
 */
const contraMeta = computed(() => {
    if (props.meta === null || typeof props.valor !== 'number') return null

    const cumple = props.mayorEsMejor ? props.valor >= props.meta : props.valor <= props.meta
    const dif = props.valor - props.meta

    return {
        cumple,
        // Corto a proposito: la tarjeta es angosta y la frase larga se partia
        // en dos renglones, descuadrando la altura de toda la fila.
        texto: Math.abs(dif) < 0.05
            ? `En la meta de ${decimal(props.meta, 0)}`
            : `${dif > 0 ? '+' : '−'}${decimal(Math.abs(dif), 1)} sobre ${decimal(props.meta, 0)}`,
    }
})
</script>

<template>
    <div class="kpi" :class="{ 'kpi--destacado': destacado }">
        <p class="kpi-etiqueta">{{ etiqueta }}</p>

        <p class="kpi-valor">
            <span class="kpi-cifra">{{ texto }}</span><span
                v-if="unidad"
                class="kpi-unidad"
            >{{ unidad.trim() }}</span>
        </p>

        <p v-if="contraMeta" class="kpi-meta" :class="contraMeta.cumple ? 'kpi-meta--bien' : 'kpi-meta--ojo'">
            <!-- Dibujado, no escrito: un glifo de fuente no siempre llega. -->
            <svg class="kpi-icono" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                <path
                    v-if="contraMeta.cumple"
                    d="M3.5 8.5 6.5 11.5 12.5 4.5"
                    fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round"
                />
                <path
                    v-else
                    d="M8 2.6 15 14.2H1z M8 6.6v3.2 M8 11.8v.1"
                    fill="none" stroke="currentColor" stroke-width="1.7"
                    stroke-linecap="round" stroke-linejoin="round"
                />
            </svg>
            {{ contraMeta.texto }}
        </p>
        <p v-else-if="nota" class="kpi-nota">{{ nota }}</p>

        <!-- Zócalo: la tendencia, sin ejes ni cifras. Es contexto de la forma
             del periodo, no un dato que se lea punto por punto. -->
        <span v-if="serie.length > 1" class="kpi-chispa">
            <Chispograma :valores="serie" :color="destacado ? 'var(--marca)' : color" />
        </span>
    </div>
</template>

<style scoped>
.kpi {
    position: relative;
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-radius: var(--r-lg);
    padding: 1rem 1.05rem .9rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    min-width: 0;
    overflow: hidden;
    transition: border-color .16s ease, box-shadow .16s ease;
}

.kpi:hover { border-color: var(--linea-fuerte); }

/* El destacado no cambia de tamaño —romperia la rejilla— sino de material:
   un filo de marca arriba y una sombra que lo despega del resto. Peso sin
   desorden. */
.kpi--destacado {
    box-shadow: var(--sombra);
    border-color: transparent;
}

.kpi--destacado::before {
    content: "";
    position: absolute;
    inset: 0 0 auto;
    height: 3px;
    background: linear-gradient(90deg, var(--acento), var(--marca));
}

.kpi-etiqueta {
    margin: 0;
    font-size: .74rem;
    font-weight: 500;
    letter-spacing: .01em;
    color: var(--ink-3);
    overflow-wrap: anywhere;
}

.kpi-valor {
    display: flex;
    align-items: baseline;
    gap: .22rem;
    margin: 0;
    min-width: 0;
}

.kpi-cifra {
    font-family: var(--display);
    /* Tabular aunque no haya columna: sin esto el numero cambia de ancho
       mientras cuenta y la tarjeta entera tiembla. */
    font-variant-numeric: tabular-nums;
    font-size: clamp(1.9rem, 2.6vw, 2.35rem);
    font-weight: 700;
    letter-spacing: -.035em;
    line-height: .95;
    color: var(--ink);
    white-space: nowrap;
}

.kpi--destacado .kpi-cifra { color: var(--acento); }

.kpi-unidad {
    font-family: var(--sans);
    font-size: .82rem;
    font-weight: 500;
    color: var(--ink-3);
    letter-spacing: 0;
}

.kpi-meta {
    display: inline-flex;
    align-items: center;
    gap: .34rem;
    margin: 0;
    font-size: .76rem;
    font-weight: 500;
    line-height: 1.35;
}

.kpi-meta--bien { color: var(--bien-ink); }
.kpi-meta--ojo  { color: var(--atencion-ink); }

.kpi-icono { width: 13px; height: 13px; flex: none; }

.kpi-nota {
    margin: 0;
    font-size: .76rem;
    color: var(--ink-3);
}

/* Empuja la chispa al fondo para que todas las tarjetas la tengan a la misma
   altura, tengan tres renglones de texto o uno. */
.kpi-chispa {
    display: block;
    margin-top: auto;
    padding-top: .75rem;
    /* Sin altura fija: la marca el propio dibujo segun su proporcion. Fijarla
       aqui es lo que recortaba la linea contra el borde de la tarjeta. */
    /* Se atenua: a plena intensidad competia con el numero por la atencion,
       que es justo lo contrario de su trabajo. */
    opacity: .78;
}
</style>
