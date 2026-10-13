<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api/cliente'
import { sesion } from '../sesion'
import Mapa from '../componentes/Mapa.vue'

/**
 * Puntos de recogida y entrega pactados.
 *
 * Una sola pantalla para los dos lados, porque es la misma lista vista desde
 * dos sitios: el cliente propone y consulta en qué va; la operación revisa.
 * Separarlas en dos pantallas obligaría a mantener dos veces la misma tabla.
 *
 * El mapa no es adorno. La coordenada es el motivo entero de este
 * procedimiento —de ella salen el trazado de ruta y el aviso de desvío— y
 * aprobarla leyendo dos números decimales es aprobar a ciegas. Aquí se ve
 * dónde cae antes de decidir.
 */
const esOperacion = computed(() => sesion.tiene('administrador', 'operador'))

const puntos = ref([])
const cargando = ref(true)
const error = ref(null)
const guardando = ref(false)

const filtro = ref('')
const seleccionado = ref(null)
const motivo = ref('')
const proponiendo = ref(false)

const forma = ref({
    nombre: '', direccion: '', ciudad: '',
    latitud: '', longitud: '', detalle_acceso: '',
    contacto_nombre: '', contacto_telefono: '',
})

async function cargar() {
    cargando.value = true

    try {
        const r = await api.puntos(filtro.value || null)
        puntos.value = r.puntos ?? []
        error.value = null
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

const pendientes = computed(() => puntos.value.filter((p) => p.estado === 'pendiente').length)

async function proponer() {
    guardando.value = true
    error.value = null

    try {
        await api.proponerPunto({
            ...forma.value,
            latitud: Number(forma.value.latitud),
            longitud: Number(forma.value.longitud),
        })

        proponiendo.value = false
        forma.value = { nombre: '', direccion: '', ciudad: '', latitud: '', longitud: '', detalle_acceso: '', contacto_nombre: '', contacto_telefono: '' }
        await cargar()
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        guardando.value = false
    }
}

async function revisar(punto, decision) {
    // Rechazar sin decir por qué deja al cliente sin saber qué corregir, y
    // volverá a mandar exactamente lo mismo. El servidor también lo exige.
    if (decision === 'rechazada' && ! motivo.value.trim()) {
        error.value = 'Escriba el motivo del rechazo: el cliente necesita saber qué corregir.'

        return
    }

    guardando.value = true
    error.value = null

    try {
        await api.revisarPunto(punto.id, decision, motivo.value.trim() || null)
        motivo.value = ''
        seleccionado.value = null
        await cargar()
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        guardando.value = false
    }
}

const CHIP = {
    pendiente: ['Pendiente de revisión', 'p--espera'],
    aprobada:  ['Aprobado',              'p--bien'],
    rechazada: ['Rechazado',             'p--malo'],
}

const chip = (e) => CHIP[e] ?? [e, 'p--espera']

/** Los del mapa: solo los que tienen coordenada, que son todos. */
const enMapa = computed(() => puntos.value
    .filter((p) => p.latitud !== null && p.longitud !== null)
    .map((p) => ({
        lat: p.latitud, lon: p.longitud,
        nombre: p.nombre,
        vigente: p.estado === 'aprobada',
        minutos: 0,
        vehiculo: p.cliente,
    })))
</script>

<template>
    <section class="pagina">
        <header class="cab">
            <div>
                <p class="rotulo">Red de puntos</p>
                <h1>Puntos pactados</h1>
                <p class="bajada">
                    Los puntos de recogida y entrega acordados, con su coordenada exacta.
                    <template v-if="esOperacion">
                        Solo los aprobados pueden usarse para radicar.
                    </template>
                    <template v-else>
                        Proponga uno y lo revisamos; hasta entonces no podrá radicar contra él.
                    </template>
                </p>
            </div>

            <button
                v-if="!proponiendo"
                type="button" class="boton boton--primario"
                @click="proponiendo = true"
            >Proponer un punto</button>
        </header>

        <p v-if="error" class="aviso aviso--malo">{{ error }}</p>

        <p v-if="esOperacion && pendientes > 0" class="aviso aviso--ojo">
            <strong>{{ pendientes }}</strong>
            {{ pendientes === 1 ? 'punto espera' : 'puntos esperan' }} revisión.
            Mientras tanto, ese cliente no puede radicar desde ahí.
        </p>

        <!-- ------------------------------------------------ proponer uno -->
        <form v-if="proponiendo" class="forma" @submit.prevent="proponer">
            <h2>Nuevo punto</h2>

            <div class="rejilla">
                <label><span>Nombre del punto</span>
                    <input v-model="forma.nombre" class="control" required maxlength="120"
                           placeholder="Bodega Norte · Muelle 2"></label>
                <label><span>Ciudad</span>
                    <input v-model="forma.ciudad" class="control" required maxlength="80"></label>
                <label class="ancho"><span>Dirección</span>
                    <input v-model="forma.direccion" class="control" required maxlength="200"></label>

                <label><span>Latitud</span>
                    <input v-model="forma.latitud" class="control num" required type="number" step="any"
                           placeholder="6.2705"></label>
                <label><span>Longitud</span>
                    <input v-model="forma.longitud" class="control num" required type="number" step="any"
                           placeholder="-75.5658"></label>

                <label class="ancho"><span>Cómo se entra</span>
                    <input v-model="forma.detalle_acceso" class="control" maxlength="255"
                           placeholder="Portería exige cédula. Muelle por la calle lateral."></label>

                <label><span>Contacto en el punto</span>
                    <input v-model="forma.contacto_nombre" class="control" maxlength="120"></label>
                <label><span>Teléfono</span>
                    <input v-model="forma.contacto_telefono" class="control" maxlength="40"></label>
            </div>

            <!-- Decir de dónde se saca la coordenada evita el error más común:
                 mandarla al revés, o con coma decimal. -->
            <p class="pista">
                La coordenada sale de Google Maps: pulse sobre el punto exacto y copie
                los dos números que aparecen, en ese orden. Use punto decimal, no coma.
            </p>

            <div class="forma-pie">
                <button type="submit" class="boton boton--primario" :disabled="guardando">
                    Enviar a revisión
                </button>
                <button type="button" class="boton" @click="proponiendo = false">Cancelar</button>
            </div>
        </form>

        <div v-if="esOperacion" class="filtros">
            <button
                v-for="f in [['', 'Todos'], ['pendiente','Pendientes'], ['aprobada','Aprobados'], ['rechazada','Rechazados']]"
                :key="f[0]" type="button" class="f"
                :aria-pressed="filtro === f[0]"
                @click="filtro = f[0]; cargar()"
            >{{ f[1] }}</button>
        </div>

        <p v-if="cargando" class="aviso">Cargando puntos…</p>
        <p v-else-if="puntos.length === 0" class="aviso">
            No hay puntos {{ filtro ? 'en ese estado' : 'registrados todavía' }}.
        </p>

        <template v-else>
            <Mapa :mensajeros="enMapa" alto="clamp(260px, 38vh, 420px)" />

            <ul class="lista">
                <li v-for="p in puntos" :key="p.id" class="punto">
                    <div class="p-datos">
                        <div class="p-cab">
                            <strong>{{ p.nombre }}</strong>
                            <span class="p" :class="chip(p.estado)[1]">{{ chip(p.estado)[0] }}</span>
                        </div>
                        <p class="p-dir">{{ p.direccion }}<span v-if="p.ciudad"> · {{ p.ciudad }}</span></p>
                        <p class="p-coord num">{{ p.latitud?.toFixed(5) }}, {{ p.longitud?.toFixed(5) }}</p>
                        <p v-if="p.detalle_acceso" class="p-acceso">{{ p.detalle_acceso }}</p>
                        <p v-if="p.contacto_nombre" class="p-acceso">
                            {{ p.contacto_nombre }}<span v-if="p.contacto_telefono"> · {{ p.contacto_telefono }}</span>
                        </p>
                        <p v-if="esOperacion && p.cliente" class="p-acceso">{{ p.cliente }}</p>
                        <p v-if="p.motivo" class="p-motivo">{{ p.motivo }}</p>
                    </div>

                    <div v-if="esOperacion && p.estado !== 'aprobada'" class="p-acciones">
                        <template v-if="seleccionado === p.id">
                            <input
                                v-model="motivo" class="control"
                                placeholder="Motivo del rechazo" maxlength="255"
                            >
                            <button type="button" class="boton boton--sm" :disabled="guardando" @click="revisar(p, 'rechazada')">
                                Rechazar
                            </button>
                            <button type="button" class="boton boton--sm" @click="seleccionado = null">Cancelar</button>
                        </template>
                        <template v-else>
                            <button type="button" class="boton boton--primario boton--sm" :disabled="guardando" @click="revisar(p, 'aprobada')">
                                Aprobar
                            </button>
                            <button type="button" class="boton boton--sm" @click="seleccionado = p.id; motivo = ''">
                                Rechazar
                            </button>
                        </template>
                    </div>
                </li>
            </ul>
        </template>
    </section>
</template>

<style scoped>
.pagina { display: flex; flex-direction: column; gap: 1rem; }

.cab { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.cab h1 { margin: .15rem 0 .3rem; font-size: 1.5rem; }
.rotulo { margin: 0; font-size: .74rem; color: var(--ink-3); }
.bajada { margin: 0; font-size: .86rem; color: var(--ink-2); max-width: 62ch; }

.aviso { margin: 0; padding: .85rem 1rem; border-radius: var(--r-md); background: var(--superficie-2); color: var(--ink-2); font-size: .86rem; }
.aviso--malo { background: var(--critico-suave); color: var(--critico-ink); }
.aviso--ojo  { background: var(--atencion-suave); color: var(--atencion-ink); }

.forma {
    background: var(--superficie); border: 1px solid var(--linea);
    border-radius: var(--r-lg); padding: 1.1rem 1.2rem;
    display: flex; flex-direction: column; gap: .9rem;
}
.forma h2 { margin: 0; font-size: 1.05rem; }

.rejilla { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: .8rem; }
.rejilla label { display: flex; flex-direction: column; gap: .2rem; font-size: .74rem; color: var(--ink-3); }
.rejilla .ancho { grid-column: 1 / -1; }

.pista { margin: 0; font-size: .78rem; color: var(--ink-3); }
.forma-pie { display: flex; gap: .5rem; }

.filtros { display: inline-flex; padding: 3px; gap: 2px; background: var(--superficie-2); border-radius: 9px; align-self: flex-start; }
.f {
    border: 1px solid transparent; background: transparent; border-radius: 6px;
    padding: .32rem .75rem; font-size: .79rem; color: var(--ink-3);
}
.f[aria-pressed="true"] {
    background: var(--superficie); border-color: var(--linea);
    color: var(--acento-ink); font-weight: 600;
}

.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }

.punto {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
    padding: .8rem 1rem; background: var(--superficie);
    border: 1px solid var(--linea); border-radius: var(--r-md);
    flex-wrap: wrap;
}

.p-datos { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
.p-cab { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.p-dir { margin: 0; font-size: .84rem; color: var(--ink-2); }
.p-coord { margin: 0; font-family: var(--mono); font-size: .76rem; color: var(--ink-3); }
.p-acceso { margin: 0; font-size: .78rem; color: var(--ink-3); }
.p-motivo { margin: .2rem 0 0; font-size: .8rem; color: var(--critico-ink); }

.p-acciones { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; }

.p { padding: .14rem .5rem; border-radius: 999px; font-size: .7rem; font-weight: 600; }
.p--espera { background: var(--atencion-suave); color: var(--atencion-ink); }
.p--bien   { background: var(--bien-suave);     color: var(--bien-ink); }
.p--malo   { background: var(--critico-suave);  color: var(--critico-ink); }

.boton--sm { padding: .3rem .7rem; font-size: .78rem; min-height: 0; }
</style>
