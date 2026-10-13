<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api/cliente'
import Mapa from '../componentes/Mapa.vue'

/**
 * Dónde está la flota ahora.
 *
 * Pantalla de sala: se deja abierta y se refresca sola. Por eso separa lo
 * VIGENTE de lo REZAGADO en vez de pintarlo todo igual — un punto de hace media
 * hora presentado como actual manda a alguien a buscar una moto donde ya no
 * está.
 */
const datos = ref(null)
const error = ref(null)
const cargando = ref(true)
const actualizado = ref(null)

let temporizador = null
let aborto = null

async function cargar() {
    aborto?.abort()
    aborto = new AbortController()

    try {
        datos.value = await api.mapaFlota(aborto.signal)
        error.value = null
        actualizado.value = new Date()
        clearTimeout(temporizador)
        temporizador = setTimeout(cargar, (datos.value.refresco_seg ?? 20) * 1000)
    } catch (e) {
        if (e.name === 'AbortError') return

        error.value = e.cuerpo?.message ?? e.message
        // Ante un fallo se reintenta más espaciado. Insistir cada veinte
        // segundos contra un servidor caído solo lo empeora.
        clearTimeout(temporizador)
        temporizador = setTimeout(cargar, 60000)
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

onBeforeUnmount(() => {
    clearTimeout(temporizador)
    aborto?.abort()
})

const mensajeros = computed(() => datos.value?.mensajeros ?? [])
const vigentes = computed(() => mensajeros.value.filter((m) => m.vigente).length)
const rezagados = computed(() => mensajeros.value.length - vigentes.value)

const hora = computed(() => actualizado.value?.toLocaleTimeString('es-CO', {
    hour: '2-digit', minute: '2-digit', second: '2-digit',
}))
</script>

<template>
    <section class="pagina">
        <header class="cab">
            <div>
                <p class="rotulo">Tiempo real</p>
                <h1>Mapa de flota</h1>
            </div>
            <p v-if="actualizado" class="sello">Actualizado {{ hora }}</p>
        </header>

        <p v-if="cargando" class="aviso">Ubicando la flota…</p>
        <p v-else-if="error" class="aviso aviso--malo">{{ error }}</p>

        <template v-else-if="datos">
            <div class="resumen">
                <span class="pastilla pastilla--bien">{{ vigentes }} en vivo</span>
                <span v-if="rezagados > 0" class="pastilla pastilla--neutra">{{ rezagados }} con señal vieja</span>
                <span v-if="datos.sin_senal > 0" class="pastilla pastilla--neutra">{{ datos.sin_senal }} sin reportar hoy</span>
            </div>

            <Mapa :mensajeros="mensajeros" alto="clamp(360px, 62vh, 680px)" />

            <p v-if="mensajeros.length === 0" class="nota">
                Ningún mensajero ha reportado posición en las últimas 12 horas.
                El teléfono envía la ubicación con la aplicación de campo abierta.
            </p>

            <ul v-else class="lista">
                <li v-for="m in mensajeros" :key="m.mensajero_id">
                    <span class="punto" :class="m.vigente ? 'punto--vivo' : 'punto--viejo'" aria-hidden="true" />
                    <span class="nom">{{ m.nombre }}</span>
                    <span class="veh">{{ m.vehiculo ?? '—' }}</span>
                    <span class="edad num">hace {{ m.minutos }} min</span>
                </li>
            </ul>
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

.resumen { display: flex; gap: .5rem; flex-wrap: wrap; }
.pastilla {
    padding: .25rem .6rem; border-radius: 999px;
    font-size: .74rem; font-weight: 600;
}
.pastilla--bien   { background: var(--bien-suave); color: var(--bien); }
.pastilla--neutra { background: var(--superficie-3); color: var(--ink-2); }

.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: .3rem; }
.lista li {
    display: grid; grid-template-columns: 14px 1fr auto auto;
    align-items: center; gap: .7rem;
    padding: .55rem .8rem; border: 1px solid var(--linea);
    border-radius: var(--r-md); background: var(--superficie);
    font-size: .86rem;
}
.punto { width: 9px; height: 9px; border-radius: 50%; }
.punto--vivo  { background: var(--bien); }
.punto--viejo { background: var(--ink-3); }
.veh  { font-family: var(--mono); font-size: .76rem; color: var(--ink-2); }
.edad { color: var(--ink-3); font-size: .78rem; }

.nota { margin: 0; font-size: .84rem; color: var(--ink-3); }
.num  { font-variant-numeric: tabular-nums; }
</style>
