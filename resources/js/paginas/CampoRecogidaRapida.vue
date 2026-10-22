<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import EscanerQr from '../componentes/EscanerQr.vue'
import { avanzarTareaLocal, encolar, tareasLocales } from '../offline/almacen'
import { refrescarContador, sincronizar } from '../offline/sincronizador'
import { api } from '../api/cliente'

/**
 * Recogida rápida: cargar el vehículo escaneando sin parar.
 *
 * El flujo normal —abrir la tarea, escanear, confirmar, volver— cuesta cuatro
 * toques de pantalla por bolsa. Con cincuenta bolsas en una bodega eso son
 * doscientos toques con las manos ocupadas, y lo que pasa en la práctica es
 * que el mensajero carga primero y marca después, de memoria, que es
 * exactamente la clase de registro que esta plataforma existe para evitar.
 *
 * Aquí la cámara se queda abierta. Cada Qué leído se compara contra las tareas
 * del mensajero y entra en una lista; al final se confirma todo de una vez.
 *
 * Nada se envía durante el escaneo: se acumula y se encola al confirmar. Una
 * bodega es justo donde no hay señal, y una pantalla que se queda esperando al
 * servidor por bolsa es una pantalla que se abandona.
 */
const router = useRouter()

const tareas = ref([])
const cargando = ref(true)
const error = ref(null)

/** Lo escaneado, en orden de lectura. Cada entrada es una tarea del mensajero. */
const recogidos = ref([])

/** Códigos leídos que no sirvieron, con su motivo, para poder explicarlos. */
const rechazados = ref([])

const guardando = ref(false)
const listo = ref(false)

const toast = ref(null)
let relojToast = null

onMounted(async () => {
    /*
     * Primero lo que ya está en el dispositivo: en una bodega sin señal, eso
     * es todo lo que va a haber, y es suficiente para trabajar.
     */
    tareas.value = await tareasLocales()

    if (navigator.onLine) {
        try {
            const r = await api.tareas()
            tareas.value = r.tareas ?? tareas.value
        } catch {
            // Sin red no es un fallo: se trabaja con lo guardado.
        }
    }

    cargando.value = false
})

const porRecoger = computed(
    () => tareas.value.filter((t) => t.accion === 'recoger')
)

const pendientes = computed(
    () => porRecoger.value.filter((t) => !recogidos.value.some((r) => r.paquete_id === t.paquete_id))
)

/**
 * Las que exigen temperatura y no la tienen.
 *
 * La recogida rápida no pide temperatura bolsa a bolsa —sería devolver el
 * problema que vino a resolver—, así que las de cadena de frío se apartan: van
 * por el flujo normal, donde el dato se captura con su control.
 */
const conFrio = computed(() => porRecoger.value.filter((t) => t.tipo === 'cadena_frio'))

function avisar(texto, tono = 'mal') {
    toast.value = { texto, tono }

    if (navigator.vibrate) {
        navigator.vibrate(tono === 'bien' ? 60 : [120, 70, 120])
    }

    clearTimeout(relojToast)
    relojToast = setTimeout(() => { toast.value = null }, tono === 'bien' ? 1200 : 4000)
}

/**
 * Un código leído.
 *
 * El QR de la etiqueta lleva una dirección, no el código pelado, así que se
 * busca el código del paquete dentro del texto leído en vez de compararlo
 * entero.
 */
function alLeer(texto) {
    const leido = String(texto ?? '').toUpperCase()

    const tarea = porRecoger.value.find((t) => leido.includes((t.codigo ?? '').toUpperCase()))

    if (!tarea) {
        if (!rechazados.value.some((r) => r.texto === leido)) {
            rechazados.value.push({
                texto: leido,
                motivo: tareas.value.some((t) => leido.includes((t.codigo ?? '').toUpperCase()))
                    ? 'Ya no está pendiente de recogida.'
                    : 'No está entre sus tareas.',
            })
        }

        avisar('Ese código no corresponde a ninguna recogida suya.')

        return
    }

    if (recogidos.value.some((r) => r.paquete_id === tarea.paquete_id)) {
        avisar(`${tarea.codigo} ya estaba en la lista.`, 'aviso')

        return
    }

    if (tarea.tipo === 'cadena_frio') {
        avisar(`${tarea.codigo} es de cadena de frío: recójala por la tarea, con su temperatura.`)

        return
    }

    recogidos.value.push(tarea)
    avisar(`${recogidos.value.length} · ${tarea.codigo}`, 'bien')
}

/*
 * Entrada manual del codigo.
 *
 * Una etiqueta rota, mojada o impresa de mas no se deja escanear, y sin esta
 * salida la unica alternativa es cargar la bolsa sin registrarla. El codigo
 * viaja igual al servidor y alli se compara con el del paquete, que es donde
 * de verdad se valida: esto ahorra la camara, no la comprobacion.
 */
const manual = ref('')

function usarManual() {
    const texto = manual.value.trim()

    if (texto === '') return

    alLeer(texto)
    manual.value = ''
}

function quitar(paqueteId) {
    recogidos.value = recogidos.value.filter((r) => r.paquete_id !== paqueteId)
}

/**
 * Confirma todo lo escaneado.
 *
 * Se encola, no se envía: así funciona igual con señal y sin ella, y el
 * sincronizador se encarga del resto. El estado local avanza de inmediato
 * porque la recolección ya ocurrió —la caja está en el vehículo—; lo que falta
 * es contarlo.
 */
async function confirmar() {
    if (recogidos.value.length === 0) return

    guardando.value = true
    error.value = null

    try {
        const donde = await ubicacion()
        const ahora = new Date().toISOString()

        for (const t of recogidos.value) {
            await encolar('recolectar', t.paquete_id, {
                evento_id: crypto.randomUUID(),
                codigo: t.codigo,
                ocurrido_en: ahora,
                ...donde,
            })

            await avanzarTareaLocal(t.paquete_id, 'recolectado')
        }

        await refrescarContador()
        sincronizar()

        listo.value = true
    } catch (e) {
        error.value = e.message
    } finally {
        guardando.value = false
    }
}

/** La posición, si el navegador la da. Nunca bloquea: ver CampoRecoger. */
function ubicacion() {
    return new Promise((resolver) => {
        if (!navigator.geolocation || !window.isSecureContext) return resolver({})

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
            { enableHighAccuracy: true, timeout: 6000, maximumAge: 30000 },
        )
    })
}
</script>

<template>
    <div class="pagina">
        <header class="cab">
            <h1>Recogida rápida</h1>
            <p class="sub">
                Apunte la cámara a cada etiqueta. La cámara no se cierra entre bolsa y bolsa;
                al final confirma todo de una vez.
            </p>
        </header>

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>

        <!-- ------------------------------------------------------ cerrado -->
        <section v-if="listo" class="cerrado">
            <p class="cerrado-tit">
                {{ recogidos.length }} paquete(s) recogido(s)
            </p>
            <p class="cerrado-sub">
                Quedan guardados en el dispositivo y salen solos en cuanto haya señal.
            </p>

            <button type="button" class="btn btn--pri" @click="router.push('/campo')">
                Ver mis tareas
            </button>
        </section>

        <template v-else>
            <p v-if="cargando" class="aviso">Cargando sus tareas…</p>

            <p v-else-if="!porRecoger.length" class="aviso">
                No tiene recogidas pendientes.
            </p>

            <template v-else>
                <EscanerQr continuo @leido="alLeer" @error="(m) => (error = m)" />

                <details class="manual">
                    <summary>No se deja escanear: escribir el código</summary>
                    <div class="manual-fila">
                        <input
                            v-model="manual" class="manual-campo" type="text"
                            placeholder="AVL-260924-0004-P01"
                            autocapitalize="characters" autocomplete="off" spellcheck="false"
                            @keyup.enter="usarManual"
                        >
                        <button type="button" class="btn btn--sec" @click="usarManual">Añadir</button>
                    </div>
                </details>

                <div class="marcador">
                    <p class="marcador-n">{{ recogidos.length }}</p>
                    <p class="marcador-t">
                        escaneado(s) de {{ porRecoger.length }} pendiente(s)
                    </p>
                </div>

                <!--
                    Las de frío se apartan y se dice por qué. Sin esta nota, el
                    mensajero escanearía la bolsa, oiría el rechazo y no sabría
                    que la culpa no es del código.
                -->
                <p v-if="conFrio.length" class="nota-frio">
                    {{ conFrio.length }} de cadena de frío no entran aquí: necesitan su
                    temperatura y se recogen desde la tarea.
                </p>

                <ul v-if="recogidos.length" class="lista">
                    <li v-for="r in recogidos" :key="r.paquete_id">
                        <div>
                            <p class="codigo">{{ r.codigo }}</p>
                            <p class="donde">{{ r.cliente }} · {{ r.sede?.nombre }}</p>
                        </div>
                        <button type="button" class="quitar" @click="quitar(r.paquete_id)">
                            Quitar
                        </button>
                    </li>
                </ul>

                <details v-if="rechazados.length" class="rechazados">
                    <summary>{{ rechazados.length }} código(s) que no se pudieron usar</summary>
                    <ul>
                        <li v-for="(r, i) in rechazados" :key="i">
                            <span class="codigo">{{ r.texto.slice(0, 60) }}</span> — {{ r.motivo }}
                        </li>
                    </ul>
                </details>

                <details v-if="pendientes.length" class="faltan">
                    <summary>{{ pendientes.length }} sin escanear</summary>
                    <ul>
                        <li v-for="t in pendientes" :key="t.paquete_id">
                            <span class="codigo">{{ t.codigo }}</span> · {{ t.sede?.nombre }}
                        </li>
                    </ul>
                </details>

                <div class="pie">
                    <button
                        type="button" class="btn btn--pri btn--ancho"
                        :disabled="!recogidos.length || guardando"
                        @click="confirmar"
                    >
                        {{ guardando
                            ? 'Registrando…'
                            : `Confirmar ${recogidos.length} recolección(es)` }}
                    </button>

                    <button type="button" class="btn btn--sec" @click="router.push('/campo')">
                        Salir sin registrar
                    </button>
                </div>
            </template>
        </template>

        <p v-if="toast" class="toast" :class="`toast--${toast.tono}`" role="status">
            {{ toast.texto }}
        </p>
    </div>
</template>

<style scoped>
.pagina {
    display: flex;
    flex-direction: column;
    gap: .9rem;
    padding: 1rem;
    max-width: 640px;
    margin: 0 auto;
}

.cab h1 { margin: 0; font-size: 1.3rem; }

.sub {
    margin: .3rem 0 0;
    font-size: .85rem;
    line-height: 1.5;
    color: var(--ink-3);
}

.marcador {
    display: flex;
    align-items: baseline;
    gap: .6rem;
    padding: .7rem .9rem;
    border-radius: 12px;
    background: var(--superficie-2);
}

.marcador-n {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    color: var(--ink);
}

.marcador-t { margin: 0; font-size: .85rem; color: var(--ink-3); }

.nota-frio {
    margin: 0;
    padding: .55rem .8rem;
    border-left: 3px solid var(--atencion);
    border-radius: 0 8px 8px 0;
    background: var(--superficie-2);
    font-size: .82rem;
    line-height: 1.5;
    color: var(--ink-2);
}

.lista {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: .4rem;
}

.lista li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .8rem;
    padding: .55rem .8rem;
    border: 1px solid var(--linea);
    border-radius: 10px;
    background: var(--superficie);
}

.codigo {
    margin: 0;
    font-family: var(--mono);
    font-size: .82rem;
    font-weight: 600;
    color: var(--ink);
}

.donde { margin: .15rem 0 0; font-size: .78rem; color: var(--ink-3); }

.quitar {
    border: 1px solid var(--linea-fuerte);
    border-radius: 8px;
    /* Dedo, no ratón: en un muelle de carga no se apunta fino. */
    min-height: 40px;
    padding: 0 .8rem;
    font: inherit;
    font-size: .8rem;
    color: var(--ink-2);
    background: var(--superficie);
    cursor: pointer;
}

.manual { font-size: .82rem; color: var(--ink-3); }
.manual summary { cursor: pointer; min-height: 36px; }

.manual-fila {
    display: flex;
    gap: .4rem;
    margin-top: .45rem;
}

.manual-campo {
    flex: 1 1 auto;
    min-height: 44px;
    border: 1px solid var(--linea-fuerte);
    border-radius: 8px;
    padding: 0 .7rem;
    font: inherit;
    font-family: var(--mono);
    font-size: .85rem;
    color: var(--ink);
    background: var(--superficie);
}

.rechazados, .faltan { font-size: .82rem; color: var(--ink-3); }
.rechazados summary, .faltan summary { cursor: pointer; min-height: 36px; }
.rechazados ul, .faltan ul { margin: .4rem 0 0; padding-left: 1.1rem; line-height: 1.7; }

.pie {
    display: flex;
    flex-direction: column;
    gap: .5rem;
    margin-top: .4rem;
}

.btn--ancho { width: 100%; min-height: 52px; font-size: 1rem; }

.cerrado {
    display: flex;
    flex-direction: column;
    gap: .5rem;
    align-items: flex-start;
    padding: 1.2rem;
    border-radius: 12px;
    background: var(--superficie-2);
}

.cerrado-tit { margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--ink); }
.cerrado-sub { margin: 0; font-size: .85rem; line-height: 1.5; color: var(--ink-3); }

.toast {
    position: fixed;
    left: 50%;
    bottom: 1.2rem;
    transform: translateX(-50%);
    z-index: 60;
    max-width: 92vw;
    padding: .6rem 1rem;
    border-radius: 999px;
    font-size: .88rem;
    font-weight: 600;
    color: #fff;
    background: var(--critico);
    box-shadow: 0 6px 20px rgb(0 0 0 / .25);
}

.toast--bien { background: var(--bien); }
.toast--aviso { background: var(--atencion); color: var(--ink); }
</style>
