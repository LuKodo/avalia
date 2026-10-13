<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/cliente'
import Hero from '../../componentes/Hero.vue'
import { numero } from '../../graficos/util'

const datos = ref(null)
const cargando = ref(true)
const error = ref(null)
const vista = ref('sellos')
const verificando = ref(false)
const verificacion = ref(null)

async function cargar() {
    cargando.value = true

    try {
        datos.value = await api.adminAuditoria()
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

async function verificar() {
    verificando.value = true
    verificacion.value = null

    try {
        verificacion.value = await api.adminVerificarCadena()
        await cargar()
    } catch (e) {
        error.value = e.message
    } finally {
        verificando.value = false
    }
}

const cifras = computed(() => {
    if (!datos.value) return []

    const sinExportar = datos.value.sellos.filter((s) => !s.exportado).length

    return [
        { etiqueta: 'Sellos', valor: datos.value.sellos.length },
        { etiqueta: 'Sin exportar', valor: sinExportar, tono: sinExportar ? 'atencion' : 'bien' },
        { etiqueta: 'Accesos', valor: datos.value.accesos.length },
        { etiqueta: 'Exportaciones', valor: datos.value.exportaciones.length },
    ]
})

function cuando(iso) {
    return iso ? new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'
}
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Administración"
            titulo="Auditoría y trazabilidad"
            descripcion="El sello diario es lo que responde «¿y si alguien con acceso de administrador reescribió la cadena entera?». Mientras viva en el mismo servidor que la cadena, no protege de eso."
            :cifras="cifras"
        >
            <template #acciones>
                <button type="button" class="btn-hero" :disabled="verificando" @click="verificar">
                    {{ verificando ? 'Verificando…' : 'Verificar cadena completa' }}
                </button>
            </template>
        </Hero>

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>

        <p v-if="verificacion" class="aviso" :class="verificacion.integra ? 'aviso--ok' : 'aviso--error'">
            <strong>{{ verificacion.integra ? '✓ Cadena íntegra' : '✕ Cadena comprometida' }}</strong> ·
            {{ numero(verificacion.revisados) }} eventos en {{ numero(verificacion.duracion_ms) }} ms.
            <template v-if="verificacion.primer_fallo">
                Fallo en la secuencia {{ verificacion.primer_fallo.secuencia }}: {{ verificacion.primer_fallo.motivo }}
            </template>
        </p>

        <p v-if="cargando" class="aviso">Cargando auditoría…</p>

        <template v-else-if="datos">
            <div class="filtros">
                <button
                    v-for="v in [
                        { k: 'sellos', t: `Sellos diarios (${datos.sellos.length})` },
                        { k: 'accesos', t: `Accesos a datos sensibles (${datos.accesos.length})` },
                        { k: 'exportaciones', t: `Exportaciones (${datos.exportaciones.length})` },
                    ]"
                    :key="v.k" type="button" class="btn"
                    :class="vista === v.k ? 'btn--pri' : 'btn--sec'"
                    @click="vista = v.k"
                >{{ v.t }}</button>
            </div>

            <div v-if="vista === 'sellos'" class="tarjeta desliza">
                <table class="tabla">
                    <thead>
                        <tr><th>Fecha</th><th>Eventos</th><th>Secuencias</th><th>Hash raíz</th><th>Firmado</th><th>Exportado</th><th>Protege</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in datos.sellos" :key="s.fecha">
                            <td class="num">{{ s.fecha }}</td>
                            <td class="num">{{ numero(s.eventos) }}</td>
                            <td class="num tenue">{{ s.desde }}–{{ s.hasta }}</td>
                            <td class="num tenue" :title="s.hash">{{ s.hash.slice(0, 16) }}…</td>
                            <td>
                                <span class="pastilla" :class="s.firmado ? 'pastilla--bien' : 'pastilla--critico'">
                                    {{ s.firmado ? 'Sí' : 'No' }}
                                </span>
                            </td>
                            <td>
                                <span class="pastilla" :class="s.exportado ? 'pastilla--bien' : 'pastilla--atencion'">
                                    {{ s.exportado ? 'Sí' : 'Pendiente' }}
                                </span>
                            </td>
                            <td>
                                <span v-if="s.protege" class="pastilla pastilla--bien">Completo</span>
                                <span v-else class="pastilla pastilla--atencion">A medias</span>
                            </td>
                        </tr>
                        <tr v-if="!datos.sellos.length"><td colspan="7" class="vacio">Todavía no hay sellos diarios.</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-else-if="vista === 'accesos'" class="tarjeta desliza">
                <table class="tabla">
                    <thead>
                        <tr><th>Cuándo</th><th>Quién</th><th>Correo</th><th>Acción</th><th>Recurso</th><th>Justificación</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(a, i) in datos.accesos" :key="i">
                            <td class="num">{{ cuando(a.cuando) }}</td>
                            <td>{{ a.usuario }}</td>
                            <td class="num tenue">{{ a.email ?? '—' }}</td>
                            <td><span class="pastilla pastilla--neutral">{{ a.accion }}</span></td>
                            <td class="tenue">{{ a.recurso }}{{ a.recurso_id ? ` #${a.recurso_id}` : '' }}</td>
                            <td class="envuelve tenue">{{ a.justificacion ?? '—' }}</td>
                            <td class="num tenue">{{ a.ip ?? '—' }}</td>
                        </tr>
                        <tr v-if="!datos.accesos.length"><td colspan="7" class="vacio">Sin accesos registrados.</td></tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="tarjeta desliza">
                <table class="tabla">
                    <thead>
                        <tr><th>Cuándo</th><th>Quién</th><th>Cliente</th><th>Formato</th><th>Registros</th><th>Datos del receptor</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(e, i) in datos.exportaciones" :key="i">
                            <td class="num">{{ cuando(e.cuando) }}</td>
                            <td>{{ e.usuario }}</td>
                            <td class="tenue">{{ e.cliente ?? '—' }}</td>
                            <td><span class="pastilla pastilla--neutral">{{ e.formato }}</span></td>
                            <td class="num">{{ numero(e.registros) }}</td>
                            <td>
                                <span v-if="e.con_datos_receptor" class="pastilla pastilla--atencion">Incluidos</span>
                                <span v-else class="tenue">Enmascarados</span>
                            </td>
                            <td class="num tenue">{{ e.ip ?? '—' }}</td>
                        </tr>
                        <tr v-if="!datos.exportaciones.length"><td colspan="7" class="vacio">Sin exportaciones registradas.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<style scoped>
@import './admin.css';

.btn-hero {
    border: 1px solid rgba(255, 255, 255, .3);
    background: rgba(255, 255, 255, .1);
    color: #fff;
    border-radius: 9px;
    padding: .55rem 1rem;
    font-size: .82rem;
    font-weight: 600;
}

.btn-hero:hover:not(:disabled) { background: rgba(255, 255, 255, .18); }
.btn-hero:disabled { opacity: .55; cursor: progress; }
</style>
