<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/cliente'
import Mapa from '../componentes/Mapa.vue'

/**
 * Dónde viene un envío, y si va por donde debería.
 *
 * La pantalla responde tres preguntas en este orden, que es el que hace quien
 * la abre: ¿por dónde va?, ¿cuánto falta?, ¿se salió de la ruta?
 *
 * Se refresca sola. El intervalo lo manda el servidor y no el navegador para
 * poder bajarlo un día de mucha carga sin publicar una versión nueva.
 */
const ruta = useRoute()
const guia = ruta.params.guia

const datos = ref(null)
const error = ref(null)
const cargando = ref(true)
const actualizado = ref(null)

let temporizador = null
let aborto = null

async function cargar() {
    // Se cancela la petición anterior antes de lanzar otra: con la pestaña en
    // segundo plano se acumulan, y al volver llegan todas juntas y desordenadas
    // —la última en llegar puede ser la más vieja—.
    aborto?.abort()
    aborto = new AbortController()

    try {
        datos.value = await api.mapaEnvio(guia, aborto.signal)
        error.value = null
        actualizado.value = new Date()
        programar(datos.value.refresco_seg)
    } catch (e) {
        if (e.name === 'AbortError') return

        error.value = e.cuerpo?.message ?? e.message
    } finally {
        cargando.value = false
    }
}

function programar(segundos) {
    clearTimeout(temporizador)

    // Sin refresco si el envío ya terminó: no hay nada nuevo que pedir y sería
    // tráfico y batería a cambio de nada.
    if (['entregado', 'devuelto', 'cancelado'].includes(datos.value?.estado)) {
        return
    }

    temporizador = setTimeout(cargar, (segundos ?? 20) * 1000)
}

onMounted(cargar)

onBeforeUnmount(() => {
    clearTimeout(temporizador)
    aborto?.abort()
})

const aproximada = computed(() => datos.value?.ruta !== null && ! datos.value?.ruta?.por_carretera)

const desvio = computed(() => datos.value?.desvio ?? null)

const hora = computed(() => actualizado.value?.toLocaleTimeString('es-CO', {
    hour: '2-digit', minute: '2-digit',
}))
</script>

<template>
    <section class="pagina">
        <header class="cab">
            <div>
                <p class="rotulo">Seguimiento</p>
                <h1 class="num">{{ guia }}</h1>
            </div>
            <p v-if="actualizado" class="sello">Actualizado {{ hora }}</p>
        </header>

        <p v-if="cargando" class="aviso">Cargando el recorrido…</p>
        <p v-else-if="error" class="aviso aviso--malo">{{ error }}</p>

        <template v-else-if="datos">
            <!-- El dictamen va ARRIBA del mapa: es la respuesta, y el mapa la
                 evidencia. Al revés, hay que interpretar el dibujo para saber
                 si hay algo que mirar. -->
            <p v-if="desvio?.desviado" class="dictamen dictamen--malo">
                <strong>Fuera de la ruta trazada.</strong>
                Se apartó hasta {{ desvio.metros_max }} m durante
                {{ desvio.minutos_fuera }} min seguidos
                (el umbral son {{ desvio.umbral_metros }} m).
            </p>
            <p v-else-if="desvio" class="dictamen dictamen--bien">
                <strong>Va por la ruta trazada.</strong>
                Máxima separación: {{ desvio.metros_max }} m.
            </p>

            <p v-if="datos.sin_ruta_porque" class="dictamen dictamen--neutro">
                {{ datos.sin_ruta_porque }}
            </p>

            <!-- Decirlo en palabras, no solo dibujar la línea punteada: sobre
                 una recta el desvío no se puede juzgar, y quien mira el mapa
                 tiene derecho a saber que no lo estamos juzgando. -->
            <p v-else-if="aproximada" class="dictamen dictamen--neutro">
                No fue posible calcular la vía por carretera, así que la línea
                es apenas la dirección entre origen y destino. Sobre una
                aproximación así no se evalúa el desvío.
            </p>

            <Mapa
                :ruta="datos.ruta?.geometria ?? []"
                :rastro="datos.rastro"
                :origen="datos.origen"
                :destino="datos.destino"
                :aproximada="aproximada"
                alto="clamp(320px, 52vh, 560px)"
            />

            <dl class="cifras">
                <div v-if="datos.ruta">
                    <dt>Distancia planeada</dt>
                    <dd class="num">{{ datos.ruta.distancia_km }} km</dd>
                </div>
                <div v-if="datos.ruta">
                    <dt>Tiempo estimado</dt>
                    <dd class="num">{{ datos.ruta.minutos }} min</dd>
                </div>
                <div>
                    <dt>Puntos recibidos</dt>
                    <dd class="num">{{ datos.rastro.length }}</dd>
                </div>
                <div v-if="datos.mensajero">
                    <dt>Mensajero</dt>
                    <dd>{{ datos.mensajero.nombre }}<span v-if="datos.mensajero.vehiculo"> · {{ datos.mensajero.vehiculo }}</span></dd>
                </div>
            </dl>

            <p v-if="datos.rastro.length === 0" class="nota">
                Todavía no hay posiciones de este envío. El rastro aparece cuando
                el mensajero recoge el paquete y su teléfono empieza a reportar.
            </p>
        </template>
    </section>
</template>

<style scoped>
.pagina { display: flex; flex-direction: column; gap: 1rem; }

.cab { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.cab h1 { margin: .15rem 0 0; font-size: 1.5rem; }

.rotulo {
    margin: 0; font-family: var(--mono); font-size: .64rem;
    letter-spacing: .14em; text-transform: uppercase; color: var(--ink-3);
}

.sello { margin: 0; font-size: .78rem; color: var(--ink-3); font-variant-numeric: tabular-nums; }

.aviso { margin: 0; padding: 1rem; border-radius: var(--r-md); background: var(--superficie-2); color: var(--ink-2); }
.aviso--malo { background: var(--critico-suave); color: var(--critico); }

.dictamen {
    margin: 0; padding: .8rem 1rem; border-radius: var(--r-md);
    font-size: .88rem; line-height: 1.55;
    border-left: 3px solid transparent;
}
.dictamen--malo   { background: var(--critico-suave); border-left-color: var(--critico); }
.dictamen--bien   { background: var(--bien-suave);    border-left-color: var(--bien); }
.dictamen--neutro { background: var(--superficie-2);  border-left-color: var(--linea-fuerte); color: var(--ink-2); }

.cifras {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: .9rem; margin: 0;
}
.cifras div {
    padding: .75rem .9rem; border: 1px solid var(--linea);
    border-radius: var(--r-md); background: var(--superficie);
}
.cifras dt {
    font-family: var(--mono); font-size: .62rem; letter-spacing: .12em;
    text-transform: uppercase; color: var(--ink-3);
}
.cifras dd { margin: .3rem 0 0; font-size: 1.05rem; font-weight: 600; }

.nota { margin: 0; font-size: .84rem; color: var(--ink-3); }

.num { font-variant-numeric: tabular-nums; }
</style>
