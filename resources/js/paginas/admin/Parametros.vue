<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/cliente'
import Hero from '../../componentes/Hero.vue'

/**
 * Parámetros de operación.
 *
 * Los pesos del motor de asignación deben sumar 100. El sistema lo advierte
 * pero no lo impone: normalizar en silencio escondería un error de
 * configuración, y el operador terminaría preguntándose por qué el motor
 * sugiere lo que sugiere.
 */
const grupos = ref({})
const aviso = ref('')
const original = ref({})
const cargando = ref(true)
const guardando = ref(false)
const error = ref(null)
const resultado = ref(null)

async function cargar() {
    cargando.value = true

    try {
        const r = await api.adminParametros()
        grupos.value = r.grupos
        aviso.value = r.aviso
        original.value = Object.fromEntries(
            Object.values(r.grupos).flat().map((p) => [p.clave, p.valor])
        )
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

const cambiados = computed(() =>
    Object.values(grupos.value).flat().filter((p) => String(p.valor) !== String(original.value[p.clave])))

const sumaPesos = computed(() => Object.values(grupos.value).flat()
    .filter((p) => p.clave.startsWith('asignacion.peso.'))
    .reduce((s, p) => s + Number(p.valor || 0), 0))

async function guardar() {
    if (!cambiados.value.length) return

    guardando.value = true
    resultado.value = null

    try {
        const r = await api.adminGuardarParametros(
            cambiados.value.map((p) => ({ clave: p.clave, valor: p.valor }))
        )
        resultado.value = r
        await cargar()
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        guardando.value = false
    }
}

const tituloGrupo = (g) => ({
    asignacion: 'Motor de asignación',
    alerta: 'Umbrales de alerta',
    entrega: 'Validación de entrega',
    frio: 'Cadena de frío',
    integracion: 'Integraciones',
}[g] ?? g)
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Administración"
            titulo="Parámetros de operación"
            descripcion="Lo que la operación va a ajustar en las primeras semanas vive aquí, no en el código. Pedir un despliegue para mover un umbral garantiza que nadie lo mueva nunca."
            :cifras="[
                { etiqueta: 'Sin guardar', valor: cambiados.length, tono: cambiados.length ? 'atencion' : 'bien' },
                { etiqueta: 'Suma de pesos', valor: sumaPesos, tono: sumaPesos === 100 ? 'bien' : 'critico' },
            ]"
        />

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-else-if="cargando" class="aviso">Cargando parámetros…</p>

        <template v-else>
            <p v-if="resultado" class="aviso aviso--ok">
                <strong>{{ resultado.total }} parámetro(s) actualizado(s).</strong>
                <span v-for="c in resultado.cambios" :key="c.clave">
                    {{ c.clave }}: {{ c.antes }} → {{ c.ahora }}.
                </span>
            </p>

            <p v-if="sumaPesos !== 100" class="aviso aviso--error">
                <strong>Los pesos del motor suman {{ sumaPesos }}, no 100.</strong>
                El motor seguirá funcionando, pero los puntajes dejan de ser comparables
                contra el histórico. {{ aviso }}
            </p>

            <section v-for="(lista, grupo) in grupos" :key="grupo" class="tarjeta">
                <div class="tarjeta-cab">
                    <h2 class="seccion-tit">{{ tituloGrupo(grupo) }}</h2>
                    <span class="tarjeta-sub">{{ lista.length }} parámetro(s)</span>
                </div>

                <div class="tarjeta-cuerpo">
                    <div v-for="p in lista" :key="p.clave" class="param" :class="{ 'param--cambiado': String(p.valor) !== String(original[p.clave]) }">
                        <div class="param-txt">
                            <p class="param-clave num">{{ p.clave }}</p>
                            <p class="param-desc">{{ p.descripcion }}</p>
                        </div>

                        <div class="param-val">
                            <input
                                v-if="p.tipo === 'int' || p.tipo === 'float'"
                                v-model="p.valor" class="campo campo--num" type="number"
                                :step="p.tipo === 'float' ? '0.1' : '1'"
                            >
                            <select v-else-if="p.tipo === 'bool'" v-model="p.valor" class="campo campo--num">
                                <option :value="true">Sí</option>
                                <option :value="false">No</option>
                            </select>
                            <input v-else v-model="p.valor" class="campo campo--num" type="text">

                            <span v-if="String(p.valor) !== String(original[p.clave])" class="param-antes num">
                                antes {{ original[p.clave] }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <div class="pie-acciones">
                <span v-if="cambiados.length" class="pendiente-txt">
                    {{ cambiados.length }} cambio(s) sin guardar
                </span>
                <button type="button" class="btn btn--sec" :disabled="!cambiados.length" @click="cargar">
                    Descartar
                </button>
                <button type="button" class="btn btn--pri" :disabled="!cambiados.length || guardando" @click="guardar">
                    {{ guardando ? 'Guardando…' : 'Guardar cambios' }}
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
@import './admin.css';

.param {
    display: flex;
    flex-wrap: wrap;
    gap: .8rem;
    align-items: center;
    justify-content: space-between;
    padding: .6rem 0;
    border-bottom: 1px solid var(--linea);
}

.param:last-child { border-bottom: none; }
.param--cambiado { background: var(--atencion-suave); margin: 0 -.9rem; padding: .6rem .9rem; border-radius: var(--r-sm); }

.param-txt { flex: 1 1 260px; min-width: 0; }
.param-clave { font-size: .78rem; font-weight: 600; margin: 0; color: var(--ink); }
.param-desc { font-size: .76rem; color: var(--ink-3); margin: .15rem 0 0; line-height: 1.45; }

.param-val { display: flex; align-items: center; gap: .6rem; flex: none; }
.campo--num { flex: 0 0 120px; text-align: right; font-family: var(--mono); }
.param-antes { font-size: .7rem; color: var(--atencion-ink); }

.pie-acciones { display: flex; align-items: center; justify-content: flex-end; gap: .6rem; flex-wrap: wrap; }
.pendiente-txt { font-size: .8rem; color: var(--atencion-ink); font-weight: 600; margin-right: auto; }
</style>
