<script setup>
import { computed } from 'vue'

/**
 * Seguimiento visual de un paquete, al estilo de las guías de paquetería que la
 * gente ya sabe leer.
 *
 * Lo que había antes era correcto y nadie lo usaba: una lista plegada que decía
 * «Trazabilidad completa (4 eventos)» con el hash de cada uno. Sirve para
 * auditar, no para responder la única pregunta que trae quien consulta —
 * ¿dónde está y cuándo llega—. Esa pregunta se responde de un vistazo o no se
 * responde.
 *
 * De ahí las decisiones: los hitos del recorrido siempre visibles aunque no
 * hayan ocurrido, para que se entienda cuánto falta; el estado actual grande y
 * arriba; y el detalle criptográfico relegado al final, donde no estorba a
 * quien no lo necesita y sigue estando para quien sí.
 */
const props = defineProps({
    /** [{ secuencia, estado, etiqueta, tipo, actor, fecha, hora, latitud, longitud, hash }] */
    eventos: { type: Array, required: true },
    /** Estado actual del paquete, por si no hay eventos. */
    estado: { type: String, default: null },
    estadoEtiqueta: { type: String, default: null },
    /** Momento comprometido, en ISO. */
    comprometidaEn: { type: String, default: null },
    entregadoEn: { type: String, default: null },
    /** Novedades para intercalar en la línea. */
    novedades: { type: Array, default: () => [] },
})

/*
 * Los hitos del recorrido normal. Novedad, devuelto y siniestro no aparecen
 * aquí: no son etapas de un camino, son desvíos. Cuando ocurren se muestran
 * como interrupción sobre la línea, que es lo que son.
 */
const HITOS = [
    { estado: 'creado', titulo: 'Envío registrado', icono: '□', descripcion: 'La guía fue creada y está a la espera de mensajero.' },
    { estado: 'asignado', titulo: 'Mensajero asignado', icono: '◇', descripcion: 'Un mensajero tiene el envío en su jornada.' },
    { estado: 'recolectado', titulo: 'Recogido', icono: '◆', descripcion: 'El paquete está bajo custodia de Avalia.' },
    { estado: 'en_transito', titulo: 'En camino', icono: '▶', descripcion: 'El paquete va hacia su destino.' },
    { estado: 'entregado', titulo: 'Entregado', icono: '✓', descripcion: 'Recibido en destino, con evidencia.' },
]

/** Estados que no continúan el camino: se muestran, pero no avanzan la barra. */
const DESVIOS = ['novedad', 'reprogramado', 'devuelto', 'siniestro']

const ordenados = computed(() =>
    [...props.eventos].sort((a, b) => a.secuencia - b.secuencia))

/** Primer evento registrado para cada hito, si existe. */
const eventoDe = computed(() => {
    const mapa = {}

    for (const ev of ordenados.value) {
        // Se conserva el primero: un estado puede repetirse tras una
        // reprogramación, y lo que interesa mostrar es cuándo ocurrió.
        if (! mapa[ev.estado]) mapa[ev.estado] = ev
    }

    // «En ruta a recolección» y «en sitio» son matices del mismo tramo que el
    // público no distingue; se pliegan al hito que les corresponde para no
    // convertir el seguimiento en una lista de tecnicismos.
    if (! mapa.recolectado && mapa.en_ruta_recoleccion) mapa.recolectado = null
    if (! mapa.en_transito && mapa.en_sitio) mapa.en_transito = mapa.en_sitio

    return mapa
})

const hitos = computed(() => {
    let alcanzado = -1

    const lista = HITOS.map((h, i) => {
        const ev = eventoDe.value[h.estado] ?? null

        if (ev) alcanzado = i

        return { ...h, evento: ev, indice: i }
    })

    return lista.map((h) => ({
        ...h,
        // Tres estados visuales: hecho, en curso, pendiente. El «en curso» es el
        // siguiente al último alcanzado, y es el que la persona quiere ver.
        fase: h.indice <= alcanzado ? 'hecho' : (h.indice === alcanzado + 1 ? 'curso' : 'pendiente'),
    }))
})

const pasoActual = computed(() => hitos.value.find((h) => h.fase === 'curso') ?? null)
/*
 * Entregado de verdad, no «alguna vez entregado».
 *
 * El evento de entrega no se borra nunca —ni cuando operación reabre la
 * entrega para completar un acta que faltó—, así que preguntarle solo a la
 * bitácora daba un titular en verde sobre un envío que había vuelto a estar en
 * curso. Manda el estado actual del paquete cuando se conoce; la bitácora
 * responde cuando no se pasó.
 */
const entregado = computed(() => (
    props.estado ? props.estado === 'entregado' : eventoDe.value.entregado != null
))

/** Desvíos ocurridos, en orden, para mostrarlos como interrupciones. */
const desvios = computed(() =>
    ordenados.value.filter((e) => DESVIOS.includes(e.estado)))

const porcentaje = computed(() => {
    const hechos = hitos.value.filter((h) => h.fase === 'hecho').length

    return Math.round((hechos / HITOS.length) * 100)
})

/** Titular: lo primero y lo más grande que se lee. */
const titular = computed(() => {
    if (entregado.value) {
        const ev = eventoDe.value.entregado

        return {
            texto: 'Entregado',
            detalle: `${formatoFecha(momento(ev).fecha)} a las ${momento(ev).hora?.slice(0, 5)}`,
            tono: 'bien',
        }
    }

    const ultimoDesvio = desvios.value[desvios.value.length - 1]

    if (ultimoDesvio && ['devuelto', 'siniestro'].includes(ultimoDesvio.estado)) {
        return { texto: ultimoDesvio.etiqueta, detalle: 'El envío no llegó a destino.', tono: 'critico' }
    }

    if (ultimoDesvio && ultimoDesvio.estado === 'novedad') {
        return { texto: 'Con novedad', detalle: 'Hubo un inconveniente. Se está gestionando.', tono: 'atencion' }
    }

    return {
        texto: pasoActual.value?.titulo ?? props.estadoEtiqueta ?? 'En proceso',
        detalle: props.comprometidaEn
            ? `Comprometido para el ${formatoFecha(props.comprometidaEn.slice(0, 10))}`
            : '',
        tono: 'curso',
    }
})

/**
 * Momento que se le muestra a quien consulta.
 *
 * Se prefiere la hora del dispositivo sobre la del servidor. Cuando el mensajero
 * entrega en un sótano sin señal, el servidor anota el hecho al recuperar
 * conexión —a veces horas después—, y esa es la marca que sostiene la auditoría.
 * Pero el cliente no pregunta cuándo se sincronizó el teléfono: pregunta a qué
 * hora llegó su medicamento. Se le responde eso.
 *
 * La hora del servidor no se pierde: sigue en el soporte, y la exportación y la
 * verificación siguen usándola.
 */
function momento(ev) {
    return {
        fecha: ev.ocurrio_fecha ?? ev.fecha,
        hora: ev.ocurrio_hora ?? ev.hora,
    }
}

const MESES = [
    'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
]

/** «2026-07-09» → «9 de julio». El año solo si no es el actual. */
function formatoFecha(iso) {
    if (! iso) return ''

    const [a, m, d] = iso.split('-').map(Number)

    if (! a || ! m || ! d) return iso

    const mes = MESES[m - 1] ?? ''
    const anioActual = new Date().getFullYear()

    return a === anioActual ? `${d} de ${mes}` : `${d} de ${mes} de ${a}`
}
</script>

<template>
    <section class="seguimiento" aria-label="Seguimiento del envío">
        <!-- Titular -->
        <header class="titular" :class="`titular--${titular.tono}`">
            <span class="titular-icono" aria-hidden="true">{{ entregado ? '✓' : '●' }}</span>
            <div>
                <p class="titular-texto">{{ titular.texto }}</p>
                <p v-if="titular.detalle" class="titular-detalle">{{ titular.detalle }}</p>
            </div>
        </header>

        <!-- Barra de avance. role=img con etiqueta: para quien no la ve, el
             porcentaje suelto no significa nada sin decir de qué. -->
        <div
            class="barra"
            role="img"
            :aria-label="`Avance del envío: ${porcentaje} por ciento`"
        >
            <span class="barra-relleno" :class="{ 'barra-relleno--fin': entregado }" :style="{ width: `${porcentaje}%` }" />
        </div>

        <!-- Hitos -->
        <ol class="hitos">
            <li v-for="h in hitos" :key="h.estado" class="hito" :class="`hito--${h.fase}`">
                <span class="hito-marca" aria-hidden="true">{{ h.fase === 'hecho' ? '✓' : h.icono }}</span>

                <div class="hito-cuerpo">
                    <p class="hito-titulo">
                        {{ h.titulo }}
                        <span v-if="h.fase === 'curso'" class="hito-ahora">en curso</span>
                    </p>

                    <p v-if="h.evento" class="hito-cuando num">
                        {{ formatoFecha(momento(h.evento).fecha) }} · {{ momento(h.evento).hora?.slice(0, 5) }}
                        <span v-if="h.evento.actor" class="hito-actor">· {{ h.evento.actor }}</span>
                    </p>
                    <!--
                        La descripción solo aplica a lo que aún no ha pasado. Un
                        hito ya superado sin evento propio —«En camino» cuando el
                        seguimiento automático no lo emitió— mostraba «El paquete
                        va hacia su destino» en un envío ya entregado, con el
                        visto verde al lado. Se dice lo que de verdad se sabe.
                    -->
                    <p v-else-if="h.fase === 'hecho'" class="hito-descripcion">
                        Sin registro propio; el envío superó esta etapa.
                    </p>
                    <p v-else class="hito-descripcion">{{ h.descripcion }}</p>
                </div>
            </li>
        </ol>

        <!-- Desvíos: no son etapas, son interrupciones, y por eso van aparte. -->
        <div v-if="desvios.length" class="desvios">
            <p class="desvios-titulo">Novedades del envío</p>
            <ul>
                <li v-for="d in desvios" :key="d.secuencia">
                    <b>{{ d.etiqueta }}</b>
                    <span class="num">{{ formatoFecha(momento(d).fecha) }} · {{ momento(d).hora?.slice(0, 5) }}</span>
                    <span v-if="d.actor" class="desvio-actor">{{ d.actor }}</span>
                </li>
            </ul>
        </div>
    </section>
</template>

<style scoped>
.seguimiento { display: flex; flex-direction: column; gap: .8rem; }

/* ------------------------------------------------------------- titular */
.titular {
    display: flex; align-items: center; gap: .7rem;
    padding: .75rem .9rem; border-radius: 11px;
    border-left: 3px solid currentColor;
}
.titular--bien     { background: var(--bien-suave);     color: var(--bien-ink); }
.titular--curso    { background: var(--acento-suave);   color: var(--acento); }
.titular--atencion { background: var(--atencion-suave); color: var(--grave); }
.titular--critico  { background: var(--critico-suave);  color: var(--critico); }

.titular-icono { font-size: 1.2rem; line-height: 1; }
.titular-texto {
    margin: 0; font-size: 1.05rem; font-weight: 650; letter-spacing: -.018em;
}
.titular-detalle { margin: .1rem 0 0; font-size: .78rem; color: var(--ink-2); }

/* --------------------------------------------------------------- barra */
.barra {
    height: 5px; border-radius: 999px; background: var(--linea); overflow: hidden;
}
.barra-relleno {
    display: block; height: 100%; border-radius: 999px;
    background: var(--acento); transition: width .45s ease;
}
.barra-relleno--fin { background: var(--bien); }

/* --------------------------------------------------------------- hitos */
.hitos { list-style: none; margin: .2rem 0 0; padding: 0; }

.hito {
    display: grid; grid-template-columns: 1.6rem 1fr; gap: .7rem;
    padding-bottom: .85rem; position: relative;
}
/* La línea que une los hitos se dibuja desde cada marca hacia la siguiente; el
   último no la lleva, o sobresaldría por debajo del final. */
.hito:not(:last-child)::before {
    content: ""; position: absolute;
    left: .75rem; top: 1.6rem; bottom: .1rem;
    width: 2px; background: var(--linea);
}
.hito--hecho:not(:last-child)::before { background: var(--bien); }

.hito-marca {
    width: 1.6rem; height: 1.6rem; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 700;
    border: 2px solid var(--linea-fuerte); background: var(--superficie);
    color: var(--ink-3); z-index: 1;
}
/* El fondo es el verde OSCURO, no el de marca: blanco sobre el verde claro da
   3,35:1, por debajo del mínimo para texto. Aquí el glifo dentro del círculo
   es texto, aunque sea un solo carácter. */
.hito--hecho .hito-marca {
    background: var(--bien-ink); border-color: var(--bien-ink); color: #fff;
}
.hito--curso .hito-marca {
    border-color: var(--acento); color: var(--acento);
}

.hito-cuerpo { min-width: 0; padding-top: .1rem; }
.hito-titulo {
    margin: 0; font-size: .88rem; font-weight: 600; color: var(--ink-1);
    display: flex; align-items: center; gap: .45rem; flex-wrap: wrap;
}
.hito--pendiente .hito-titulo { color: var(--ink-3); font-weight: 500; }

.hito-ahora {
    font-size: .6rem; text-transform: uppercase; letter-spacing: .07em;
    color: var(--acento); border: 1px solid currentColor;
    border-radius: 999px; padding: .05rem .4rem; font-weight: 600;
}

.hito-cuando {
    margin: .12rem 0 0; font-size: .74rem; color: var(--ink-2);
    font-variant-numeric: tabular-nums;
}
.hito-actor { color: var(--ink-3); }
.hito-descripcion { margin: .12rem 0 0; font-size: .72rem; color: var(--ink-3); }

/* ------------------------------------------------------------- desvíos */
.desvios {
    padding: .6rem .8rem; border-radius: 10px;
    background: var(--atencion-suave);
}
.desvios-titulo {
    margin: 0 0 .3rem; font-size: .64rem; font-weight: 600;
    letter-spacing: .08em; text-transform: uppercase; color: var(--grave);
}
.desvios ul { list-style: none; margin: 0; padding: 0; }
.desvios li {
    display: flex; flex-wrap: wrap; gap: .1rem .6rem;
    font-size: .76rem; color: var(--ink-2); padding: .12rem 0;
}
.desvio-actor { color: var(--ink-3); }

@media (prefers-reduced-motion: reduce) {
    .barra-relleno { transition: none; }
}
</style>
