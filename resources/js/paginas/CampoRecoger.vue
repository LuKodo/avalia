<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/cliente'

/**
 * Confirmación de recolección tras escanear el QR de la guía.
 *
 * Aquí llega el mensajero apuntando la cámara del teléfono a la etiqueta. Esa
 * es la razón de que el QR lleve una dirección y no los datos del envío:
 *
 *   · La cámara nativa del móvil abre direcciones sin pedir permisos a la web.
 *     Un escáner dentro de la aplicación necesitaría `getUserMedia`, que los
 *     navegadores solo conceden sobre HTTPS — y hoy no lo hay.
 *
 *   · Un QR con los datos dentro lo lee cualquiera. Quien fotografíe una caja
 *     en un andén vería remitente, destinatario y contenido. Con una
 *     dirección no ve nada: hace falta sesión de mensajero y que el paquete
 *     esté asignado a él.
 *
 * El código escaneado viaja al servidor y se compara con el del paquete. No es
 * ceremonia: es lo que distingue «recogí esta caja» de «marqué recogida desde
 * el sofá».
 */
const ruta = useRoute()
const router = useRouter()

const codigo = computed(() => String(ruta.params.codigo ?? '').trim())

const cargando = ref(true)
const error = ref(null)
const tarea = ref(null)
const confirmando = ref(false)
const listo = ref(false)
const temperatura = ref('')

const exigeTemperatura = computed(() => tarea.value?.tipo === 'cadena_frio')

onMounted(async () => {
    try {
        const r = await api.tareas()

        // El código de la etiqueta es el del PAQUETE —AVL-260928-0010-P01—,
        // así que se busca por ahí y no por el número de guía: una orden puede
        // llevar varias cajas y cada una tiene la suya.
        tarea.value = (r.tareas ?? []).find(
            (t) => (t.codigo ?? '').toUpperCase() === codigo.value.toUpperCase())

        if (!tarea.value) {
            error.value = 'Ese paquete no está entre sus tareas. '
                + 'Puede que esté asignado a otro mensajero, o que ya se haya recogido.'
        } else if (tarea.value.accion !== 'recoger') {
            error.value = `Este paquete ya está «${tarea.value.estado}». No hay recolección pendiente.`
        }
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
})

async function confirmar() {
    confirmando.value = true
    error.value = null

    try {
        const posicion = await ubicacion()

        await api.recolectar(tarea.value.paquete_id, {
            evento_id: crypto.randomUUID(),
            codigo: codigo.value,
            ocurrido_en: new Date().toISOString(),
            ...posicion,
            ...(temperatura.value !== '' ? { temperatura: Number(temperatura.value) } : {}),
        })

        listo.value = true
    } catch (e) {
        error.value = e.message
    } finally {
        confirmando.value = false
    }
}

/**
 * La posición, si el navegador la da.
 *
 * Nunca bloquea: sobre HTTP el navegador ni siquiera pregunta, y en un sótano
 * el GPS tarda o no llega. Una recolección sin coordenada es peor que una con
 * ella, pero muchísimo mejor que una que no se registra.
 */
function ubicacion() {
    return new Promise((resolver) => {
        if (!navigator.geolocation) return resolver({})

        const corte = setTimeout(() => resolver({}), 6000)

        navigator.geolocation.getCurrentPosition(
            (p) => {
                clearTimeout(corte)
                resolver({
                    latitud: p.coords.latitude,
                    longitud: p.coords.longitude,
                    precision_m: Math.round(p.coords.accuracy),
                })
            },
            () => { clearTimeout(corte); resolver({}) },
            { enableHighAccuracy: true, timeout: 5000 },
        )
    })
}
</script>

<template>
    <div class="recoger">
        <p v-if="cargando" class="aviso">Buscando el paquete…</p>

        <!-- ------------------------------------------------------- hecho -->
        <section v-else-if="listo" class="tarjeta hecho">
            <div class="marca" aria-hidden="true">✓</div>
            <h1 class="tit">Recolección confirmada</h1>
            <p class="cod num">{{ codigo }}</p>
            <p class="texto">
                El cliente ya recibió el aviso. Lleve la carga a su destino y
                registre la entrega con firma y foto.
            </p>
            <button type="button" class="btn btn--pri" @click="router.push('/campo')">
                Volver a mis tareas
            </button>
        </section>

        <!-- ------------------------------------------------------ problema -->
        <section v-else-if="error && !tarea" class="tarjeta problema">
            <div class="marca marca--mal" aria-hidden="true">!</div>
            <h1 class="tit">No se puede recoger</h1>
            <p class="cod num">{{ codigo }}</p>
            <p class="texto">{{ error }}</p>
            <button type="button" class="btn btn--sec" @click="router.push('/campo')">
                Ir a mis tareas
            </button>
        </section>

        <!-- ----------------------------------------------------- confirmar -->
        <section v-else class="tarjeta">
            <p class="et">Confirmar recolección</p>
            <h1 class="tit">{{ tarea.guia }}</h1>
            <p class="cod num">{{ tarea.codigo }}</p>

            <dl class="datos">
                <div><dt>Cliente</dt><dd>{{ tarea.cliente }}</dd></div>
                <div><dt>Recoger en</dt><dd>{{ tarea.origen }}</dd></div>
                <div><dt>Entregar en</dt><dd>{{ tarea.destino }}</dd></div>
                <div><dt>Tipo</dt><dd>{{ tarea.tipo_etiqueta }}</dd></div>
            </dl>

            <label v-if="exigeTemperatura" class="campo-grupo">
                <span class="etiqueta">Temperatura al recoger (°C)</span>
                <input v-model="temperatura" class="campo" type="number" step="0.1" inputmode="decimal">
                <small>Obligatoria en cadena de frío: sin ella no se puede cerrar.</small>
            </label>

            <p v-if="error" class="aviso aviso--error">{{ error }}</p>

            <button
                type="button"
                class="btn btn--pri btn--ancho"
                :disabled="confirmando || (exigeTemperatura && temperatura === '')"
                @click="confirmar"
            >
                {{ confirmando ? 'Registrando…' : 'Confirmar que la recogí' }}
            </button>
        </section>
    </div>
</template>

<style scoped>
.recoger { max-width: 520px; margin: 0 auto; padding: 1.2rem 1rem 3rem; }

.tarjeta {
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-radius: var(--r-lg, 14px);
    padding: 1.6rem 1.3rem;
}

.hecho, .problema { text-align: center; }

.marca {
    width: 56px; height: 56px; margin: 0 auto .8rem;
    display: grid; place-items: center;
    border-radius: 50%;
    background: var(--bien-suave, #e3f5e3);
    color: var(--bien-ink, #087A08);
    font-size: 1.8rem; font-weight: 700;
}

.marca--mal { background: var(--critico-suave, #fde8e8); color: var(--critico-ink, #B32626); }

.et { font-family: var(--mono); font-size: .68rem; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-3); margin: 0 0 .2rem; }
.tit { font-size: 1.3rem; font-weight: 700; margin: 0; letter-spacing: -.02em; }
.cod { font-size: .85rem; color: var(--ink-3); margin: .2rem 0 1rem; }
.texto { font-size: .9rem; color: var(--ink-2); line-height: 1.55; margin: 0 0 1.3rem; }

.datos { margin: 0 0 1.2rem; display: grid; gap: .55rem; }
.datos div { display: flex; gap: .7rem; }
.datos dt { flex: 0 0 6.5rem; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3); margin: 0; }
.datos dd { flex: 1; margin: 0; font-size: .92rem; font-weight: 500; }

.campo-grupo { display: block; margin-bottom: 1.1rem; }
.campo-grupo small { display: block; font-size: .74rem; color: var(--ink-3); margin-top: .3rem; }

.btn--ancho { width: 100%; justify-content: center; min-height: 52px; font-size: 1rem; }
</style>
