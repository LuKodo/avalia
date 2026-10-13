<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../api/cliente'

/**
 * La cadena de trayectos de un paquete, con sus mandos.
 *
 * Responde la pregunta que antes no se podía contestar: quién tiene el paquete
 * ahora y qué falta para que llegue. Un envío directo trae un tramo; uno
 * consolidado, dos —recogida hasta el centro y entrega desde él—, y el segundo
 * puede salir otro día con otro mensajero y otro vehículo.
 */
const props = defineProps({
    paqueteId: { type: Number, required: true },
    // Sedes propias de Avalia, para elegir centro de trasbordo.
    centros: { type: Array, default: () => [] },
    mensajeros: { type: Array, default: () => [] },
})

const emit = defineEmits(['cambio'])

const cadena = ref(null)
const cargando = ref(false)
const error = ref(null)
const trabajando = ref(false)

// Formulario de asignación, abierto sobre un tramo concreto.
const asignando = ref(null)
const forma = ref({ mensajero_id: '', programado_desde: '', programado_hasta: '' })

const centroElegido = ref('')

async function cargar() {
    cargando.value = true
    error.value = null

    try {
        cadena.value = await api.tramos(props.paqueteId)
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        cargando.value = false
    }
}

watch(() => props.paqueteId, cargar, { immediate: true })

const tramos = computed(() => cadena.value?.tramos ?? [])

/** Solo se puede reencaminar mientras quede algo abierto. */
const puedeTrasbordar = computed(() =>
    tramos.value.length === 1 && ['pendiente', 'asignado', 'en_curso'].includes(tramos.value[0]?.estado))

async function accion(fn) {
    trabajando.value = true
    error.value = null

    try {
        await fn()
        await cargar()
        emit('cambio')
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        trabajando.value = false
    }
}

const armar = () => accion(() => api.armarTramos(props.paqueteId, centroElegido.value || null))

const trasbordar = () => accion(async () => {
    if (! centroElegido.value) throw new Error('Elija el centro de trasbordo.')

    await api.trasbordar(props.paqueteId, Number(centroElegido.value))
})

function abrirAsignacion(t) {
    asignando.value = t.id
    forma.value = {
        mensajero_id: '',
        // Se precargan las fechas que ya tuviera: reescribirlas a mano cada vez
        // que se corrige el mensajero es trabajo regalado.
        programado_desde: t.programado_desde ? t.programado_desde.slice(0, 16) : '',
        programado_hasta: t.programado_hasta ? t.programado_hasta.slice(0, 16) : '',
    }
}

const asignar = (t) => accion(async () => {
    if (! forma.value.mensajero_id) throw new Error('Elija un mensajero.')

    await api.asignarTramo(t.id, {
        mensajero_id: Number(forma.value.mensajero_id),
        programado_desde: forma.value.programado_desde || null,
        programado_hasta: forma.value.programado_hasta || null,
    })

    asignando.value = null
})

const ESTADOS = {
    pendiente: ['Sin asignar', 'p--neutro'],
    asignado:  ['Asignado',    'p--info'],
    en_curso:  ['En curso',    'p--info'],
    cerrado:   ['Cerrado',     'p--bien'],
    cancelado: ['Cancelado',   'p--malo'],
}

const chip = (e) => ESTADOS[e] ?? [e, 'p--neutro']

const cuando = (iso) => iso === null || iso === undefined
    ? null
    : new Date(iso).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
</script>

<template>
    <section class="cadena">
        <header class="c-cab">
            <h3>Trayectos</h3>
            <p v-if="tramos.length" class="c-sub">
                {{ tramos.length === 1 ? 'Envío directo' : `${tramos.length} tramos` }}
            </p>
        </header>

        <p v-if="error" class="c-aviso c-aviso--malo">{{ error }}</p>
        <p v-if="cargando" class="c-aviso">Cargando trayectos…</p>

        <!-- Sin cadena: el paquete se radicó antes de que existieran los
             tramos. Se arma aquí, directa o con centro. -->
        <div v-else-if="tramos.length === 0" class="c-vacio">
            <p>Este paquete todavía no tiene trayectos.</p>
            <div class="c-fila">
                <select v-model="centroElegido" class="control">
                    <option value="">Directo, sin trasbordo</option>
                    <option v-for="s in centros" :key="s.id" :value="s.id">
                        Pasando por {{ s.nombre }}
                    </option>
                </select>
                <button type="button" class="boton boton--primario" :disabled="trabajando" @click="armar">
                    Armar trayectos
                </button>
            </div>
        </div>

        <template v-else>
            <ol class="pasos">
                <li v-for="t in tramos" :key="t.id" class="paso" :class="{ 'paso--cerrado': t.estado === 'cerrado' }">
                    <span class="paso-n num" aria-hidden="true">{{ t.orden }}</span>

                    <div class="paso-cuerpo">
                        <div class="paso-cab">
                            <strong>{{ t.tipo_etiqueta }}</strong>
                            <span class="p" :class="chip(t.estado)[1]">{{ chip(t.estado)[0] }}</span>
                            <span v-if="!t.disponible" class="p p--neutro">programado</span>
                        </div>

                        <p class="paso-ruta">
                            {{ t.origen?.nombre ?? '—' }}
                            <span aria-hidden="true">→</span>
                            {{ t.destino?.nombre ?? '—' }}
                        </p>

                        <p class="paso-meta">
                            <span v-if="t.mensajero">{{ t.mensajero }}</span>
                            <span v-else class="sin">Sin mensajero</span>
                            <span v-if="t.vehiculo" class="veh">{{ t.vehiculo }}</span>
                            <span v-if="t.programado_desde">· desde {{ cuando(t.programado_desde) }}</span>
                            <span v-if="t.cerrado_en">· cerrado {{ cuando(t.cerrado_en) }}</span>
                        </p>

                        <!-- Lo que le falta al mensajero para poder cerrarlo.
                             Decirlo aquí evita que el operador llame a
                             preguntar por qué no se cierra. -->
                        <p v-if="t.estado !== 'cerrado' && t.faltan?.length" class="paso-falta">
                            Para cerrarlo hace falta: {{ t.faltan.join(', ') }}
                        </p>

                        <div v-if="asignando === t.id" class="asignar">
                            <label>
                                <span>Mensajero</span>
                                <select v-model="forma.mensajero_id" class="control">
                                    <option value="">Seleccionar…</option>
                                    <option v-for="m in mensajeros" :key="m.id" :value="m.id">
                                        {{ m.nombre }}<template v-if="m.vehiculo"> · {{ m.vehiculo }}</template>
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span>Desde</span>
                                <input v-model="forma.programado_desde" type="datetime-local" class="control">
                            </label>
                            <label>
                                <span>Hasta</span>
                                <input v-model="forma.programado_hasta" type="datetime-local" class="control">
                            </label>
                            <div class="asignar-pie">
                                <button type="button" class="boton boton--primario" :disabled="trabajando" @click="asignar(t)">
                                    Guardar
                                </button>
                                <button type="button" class="boton" @click="asignando = null">Cancelar</button>
                            </div>
                        </div>

                        <button
                            v-else-if="t.estado !== 'cerrado'"
                            type="button" class="boton boton--sm"
                            @click="abrirAsignacion(t)"
                        >{{ t.mensajero ? 'Cambiar mensajero o fecha' : 'Asignar mensajero' }}</button>
                    </div>
                </li>
            </ol>

            <!-- Consolidar sobre la marcha: se radicó directo y a media mañana
                 se decide pasarlo por el centro. -->
            <div v-if="puedeTrasbordar" class="c-trasbordo">
                <select v-model="centroElegido" class="control">
                    <option value="">Añadir trasbordo por…</option>
                    <option v-for="s in centros" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                </select>
                <button
                    type="button" class="boton boton--sm"
                    :disabled="trabajando || !centroElegido"
                    @click="trasbordar"
                >Consolidar en centro</button>
            </div>
        </template>
    </section>
</template>

<style scoped>
.cadena { display: flex; flex-direction: column; gap: .7rem; }

.c-cab { display: flex; align-items: baseline; gap: .6rem; }
.c-cab h3 { margin: 0; font-size: .95rem; }
.c-sub { margin: 0; font-size: .76rem; color: var(--ink-3); }

.c-aviso { margin: 0; padding: .6rem .8rem; border-radius: var(--r-sm); background: var(--superficie-2); font-size: .82rem; color: var(--ink-2); }
.c-aviso--malo { background: var(--critico-suave); color: var(--critico-ink); }

.c-vacio { display: flex; flex-direction: column; gap: .6rem; font-size: .84rem; color: var(--ink-2); }
.c-vacio p { margin: 0; }
.c-fila { display: flex; gap: .5rem; flex-wrap: wrap; }

.pasos { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }

.paso {
    display: grid; grid-template-columns: 26px 1fr; gap: .7rem;
    padding: .7rem .85rem;
    border: 1px solid var(--linea); border-radius: var(--r-md);
    background: var(--superficie);
}
.paso--cerrado { background: var(--superficie-2); }

.paso-n {
    width: 26px; height: 26px; border-radius: 50%;
    display: grid; place-items: center;
    background: var(--acento); color: var(--sobre-acento);
    font-size: .76rem; font-weight: 700;
}
.paso--cerrado .paso-n { background: var(--bien-ink); }

.paso-cuerpo { display: flex; flex-direction: column; gap: .35rem; min-width: 0; }
.paso-cab { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; font-size: .88rem; }

.paso-ruta { margin: 0; font-size: .82rem; color: var(--ink-2); }
.paso-meta { margin: 0; display: flex; gap: .4rem; flex-wrap: wrap; font-size: .76rem; color: var(--ink-3); }
.paso-meta .sin { color: var(--atencion-ink); font-weight: 500; }
.paso-meta .veh { font-family: var(--mono); }

.paso-falta { margin: 0; font-size: .75rem; color: var(--atencion-ink); }

.p { padding: .12rem .45rem; border-radius: 999px; font-size: .68rem; font-weight: 600; }
.p--neutro { background: var(--superficie-3); color: var(--ink-2); }
.p--info   { background: var(--acento-suave); color: var(--acento-ink); }
.p--bien   { background: var(--bien-suave);   color: var(--bien-ink); }
.p--malo   { background: var(--critico-suave); color: var(--critico-ink); }

.asignar { display: grid; gap: .5rem; padding-top: .3rem; }
.asignar label { display: flex; flex-direction: column; gap: .15rem; font-size: .72rem; color: var(--ink-3); }
.asignar-pie { display: flex; gap: .4rem; }

.boton--sm { align-self: flex-start; padding: .28rem .65rem; font-size: .76rem; min-height: 0; }

.c-trasbordo { display: flex; gap: .5rem; flex-wrap: wrap; padding-top: .2rem; }
</style>
