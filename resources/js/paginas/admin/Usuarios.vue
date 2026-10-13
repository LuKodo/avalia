<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/cliente'
import Hero from '../../componentes/Hero.vue'

const datos = ref(null)
const cargando = ref(true)
const error = ref(null)
const buscar = ref('')
const rolFiltro = ref('')
const claveGenerada = ref(null)
const ocupado = ref(null)

async function cargar() {
    cargando.value = true

    try {
        datos.value = await api.adminUsuarios({
            ...(buscar.value ? { buscar: buscar.value } : {}),
            ...(rolFiltro.value ? { rol: rolFiltro.value } : {}),
        })
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

async function alternar(u) {
    ocupado.value = u.id

    try {
        await api.adminAlternarUsuario(u.id)
        await cargar()
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        ocupado.value = null
    }
}

async function restablecer(u) {
    ocupado.value = u.id
    claveGenerada.value = null

    try {
        const r = await api.adminRestablecerClave(u.id, 'restablecimiento desde el panel')
        claveGenerada.value = { usuario: u.nombre, email: u.email, ...r }
        await cargar()
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        ocupado.value = null
    }
}

const cifras = computed(() => {
    if (!datos.value) return []
    const u = datos.value.usuarios

    return [
        { etiqueta: 'Cuentas', valor: u.length },
        { etiqueta: 'Activas', valor: u.filter((x) => x.activo).length, tono: 'bien' },
        { etiqueta: 'Desactivadas', valor: u.filter((x) => !x.activo).length,
          tono: u.filter((x) => !x.activo).length ? 'atencion' : 'neutro' },
    ]
})

function cuando(iso) {
    return iso ? new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'nunca'
}
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Administración"
            titulo="Usuarios y accesos"
            descripcion="Desactivar una cuenta revoca además sus tokens: una cuenta «inactiva» con sesiones abiertas no está desactivada."
            :cifras="cifras"
        />

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>

        <div v-if="claveGenerada" class="aviso aviso--clave">
            <strong>Contraseña temporal para {{ claveGenerada.usuario }}</strong>
            <code class="clave">{{ claveGenerada.clave_temporal }}</code>
            <p>
                {{ claveGenerada.aviso }}
                Se revocaron {{ claveGenerada.tokens_revocados }} sesión(es) activa(s).
            </p>
            <button type="button" class="btn btn--sec btn--peq" @click="claveGenerada = null">Entendido, ocultar</button>
        </div>

        <div class="filtros">
            <input v-model="buscar" class="campo" type="search" placeholder="Buscar por nombre o correo" @keyup.enter="cargar">
            <select v-model="rolFiltro" class="campo campo--corto" @change="cargar">
                <option value="">Todos los roles</option>
                <option v-for="r in datos?.roles ?? []" :key="r" :value="r">{{ r }}</option>
            </select>
            <button type="button" class="btn btn--sec" @click="cargar">Buscar</button>
        </div>

        <p v-if="cargando" class="aviso">Cargando usuarios…</p>

        <div v-else-if="datos" class="tarjeta desliza">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Nombre</th><th>Correo</th><th>Rol</th><th>Vinculación</th>
                        <th>2FA</th><th>Último acceso</th><th>Tokens</th><th>Estado</th><th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in datos.usuarios" :key="u.id" :class="{ inactivo: !u.activo }">
                        <td>{{ u.nombre }}</td>
                        <td class="num">{{ u.email }}</td>
                        <td><span class="pastilla pastilla--neutral">{{ u.rol }}</span></td>
                        <td class="tenue">{{ u.cliente ?? u.mensajero ?? '—' }}</td>
                        <td>
                            <span v-if="u.doble_factor" class="pastilla pastilla--bien">Sí</span>
                            <span v-else class="tenue">—</span>
                        </td>
                        <td class="num tenue">{{ cuando(u.ultimo_acceso) }}</td>
                        <td class="num">{{ u.tokens }}</td>
                        <td>
                            <span class="pastilla" :class="u.activo ? 'pastilla--bien' : 'pastilla--critico'">
                                {{ u.activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="acciones">
                            <button type="button" class="mini" :disabled="ocupado === u.id" @click="alternar(u)">
                                {{ u.activo ? 'Desactivar' : 'Activar' }}
                            </button>
                            <button type="button" class="mini" :disabled="ocupado === u.id" @click="restablecer(u)">
                                Clave
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="nota">
            Para crear cuentas nuevas se usa la consola, donde la contraseña no queda en el historial:
            <code>php artisan ltp:usuario correo@dominio --rol=operador --generar</code>
        </p>
    </div>
</template>

<style scoped>
@import './admin.css';
</style>
