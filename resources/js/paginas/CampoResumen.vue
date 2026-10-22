<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api/cliente'
import { estadoSync } from '../offline/sincronizador'

/**
 * Lo primero que ve el mensajero al abrir la aplicación.
 *
 * No es un panel de indicadores. Es lo que alguien de pie en la calle necesita
 * saber en dos segundos, antes de decidir hacia dónde va: cuánto le queda, qué
 * lleva encima, qué es lo siguiente y si algo se le está pasando de hora.
 *
 * Deliberadamente NO hay medias, porcentajes ni comparativas con otros
 * mensajeros. Eso es material de supervisión, se mira desde un escritorio, y
 * aquí solo sería ruido —o peor, presión sobre alguien que va conduciendo—.
 *
 * Y se dibuja desde la red, no desde el almacén local: a diferencia de las
 * tareas, un resumen desactualizado engaña más de lo que ayuda. Si no hay
 * señal, se dice y se manda a la lista, que sí funciona sin cobertura.
 */
const datos = ref(null)
const cargando = ref(true)
const error = ref(null)

async function cargar() {
    cargando.value = true
    error.value = null

    try {
        datos.value = await api.resumenCampo()
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

/* ==================================== pausas =========================== */

/**
 * Almuerzo, lluvia, avería.
 *
 * Existe para que la productividad se mida contra el tiempo en que de verdad
 * se podía repartir. Sin esto, una hora de almuerzo pesa igual que una hora
 * perdida y el indicador acaba castigando a quien cumplió.
 *
 * El botón de almuerzo va suelto porque es el caso de todos los días y tiene
 * que ser un solo toque. Los imprevistos van detrás de un menú: se usan poco y
 * piden escribir.
 */
const MOTIVOS = [
    { valor: 'lluvia', etiqueta: 'Lluvia' },
    { valor: 'averia', etiqueta: 'Avería del vehículo' },
    { valor: 'otro', etiqueta: 'Otro motivo' },
]

const eligiendoMotivo = ref(false)
const motivoOtro = ref('')
const pausando = ref(false)

/** La coordenada, si se puede. Sin ella la pausa se registra igual. */
function coordenadaActual() {
    return new Promise((listo) => {
        if (! navigator.geolocation) return listo({})

        navigator.geolocation.getCurrentPosition(
            (p) => listo({ latitud: p.coords.latitude, longitud: p.coords.longitude }),
            // Saber que llovió importa más que saber dónde llovía.
            () => listo({}),
            { timeout: 5000, maximumAge: 60_000 }
        )
    })
}

/* ------------------------------------------------ entrada y salida -------
 *
 * De aquí sale la hora de entrada con la que los informes arman las franjas
 * del día. No es fichar: no mide presencia ni va a nómina, marca el tramo
 * dentro del cual las cifras del día significan algo.
 */
const marcando = ref(false)
const avisoJornada = ref(null)

async function marcarJornada(accion) {
    marcando.value = true
    error.value = null

    try {
        const r = await api.marcarJornada(accion)
        avisoJornada.value = r.mensaje
        await cargar()
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        marcando.value = false
    }
}

async function abrirPausa(tipo) {
    if (tipo === 'otro' && ! motivoOtro.value.trim()) return

    pausando.value = true
    error.value = null

    try {
        const donde = await coordenadaActual()

        await api.abrirPausa({
            tipo,
            descripcion: tipo === 'otro' ? motivoOtro.value.trim() : null,
            ...donde,
        })

        eligiendoMotivo.value = false
        motivoOtro.value = ''
        await cargar()
    } catch (e) {
        error.value = e.message
    } finally {
        pausando.value = false
    }
}

async function cerrarPausa() {
    pausando.value = true
    error.value = null

    try {
        await api.cerrarPausa(await coordenadaActual())
        await cargar()
    } catch (e) {
        error.value = e.message
    } finally {
        pausando.value = false
    }
}

/** Cuánto falta para la hora pactada, dicho como lo diría una persona. */
const cuentaAtras = computed(() => {
    const limite = datos.value?.siguiente?.limite

    if (!limite) return null

    const min = Math.round((new Date(limite) - new Date()) / 60000)
    const abs = Math.abs(min)
    const texto = abs >= 1440
        ? `${Math.floor(abs / 1440)} d ${Math.floor((abs % 1440) / 60)} h`
        : abs >= 60
            ? `${Math.floor(abs / 60)} h ${abs % 60} min`
            : `${abs} min`

    return min < 0 ? `vencida hace ${texto}` : `faltan ${texto}`
})

const hora = (iso) => iso
    ? new Date(iso).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: false })
    : '—'
</script>

<template>
    <div class="resumen">
        <header class="cab">
            <h1 class="titulo">Mi jornada</h1>
            <p class="sub">{{ datos?.mensajero ?? 'Cargando…' }}</p>
        </header>

        <p v-if="cargando" class="vacio">Cargando…</p>

        <!--
            Sin señal el resumen no se inventa: se dice, y se manda a la lista
            de tareas, que sí está guardada en el teléfono y sí funciona.
        -->
        <p v-else-if="error" class="franja franja--sin-red">
            ▲ No se pudo cargar el resumen: {{ error }}
            <RouterLink to="/campo" class="franja-enlace">Ver mis tareas</RouterLink>
        </p>

        <template v-else-if="datos">
            <!--
                La pausa abierta va arriba del todo y en color.

                Un mensajero que se fue a almorzar y no cerró la pausa aparece
                parado toda la tarde en los informes. Tenerla a la vista, con
                los minutos corriendo, es lo que hace que se acuerde.
            -->
            <div v-if="datos.pausa" class="pausa-activa">
                <div>
                    <span class="pausa-et">En pausa · {{ datos.pausa.etiqueta }}</span>
                    <span class="pausa-min num">{{ datos.pausa.minutos }} min</span>
                    <span v-if="datos.pausa.descripcion" class="pausa-desc">{{ datos.pausa.descripcion }}</span>
                </div>

                <button type="button" class="btn-reanudar" :disabled="pausando" @click="cerrarPausa">
                    {{ pausando ? 'Un momento…' : 'Reanudar' }}
                </button>
            </div>

            <div class="jornada">
                <div class="jornada-txt">
                    <p class="jornada-estado">
                        <template v-if="datos.en_turno">● En jornada</template>
                        <template v-else>○ Fuera de jornada</template>
                        <span v-if="datos.turno_inicio" class="jornada-hora">
                            · entrada {{ hora(datos.turno_inicio) }}
                        </span>
                    </p>
                    <p class="jornada-pista">
                        {{ datos.en_turno
                            ? 'Marque la salida al terminar. Lo registrado hoy queda guardado igual.'
                            : 'Marque la entrada al empezar: es de donde salen las franjas de su informe del día.' }}
                    </p>
                </div>

                <button
                    type="button" class="btn-jornada" :disabled="marcando"
                    @click="marcarJornada(datos.en_turno ? 'salida' : 'entrada')"
                >
                    {{ marcando
                        ? 'Un momento…'
                        : (datos.en_turno ? 'Marcar salida' : 'Marcar entrada') }}
                </button>
            </div>

            <p v-if="avisoJornada" class="franja franja--ok">{{ avisoJornada }}</p>

            <p v-if="!datos.en_turno" class="franja franja--turno">
                ◍ Figura fuera de turno. No se le asignarán envíos nuevos hasta que vuelva a marcar entrada.
            </p>

            <p v-if="estadoSync.pendientes > 0" class="franja franja--pendiente">
                ▲ {{ estadoSync.pendientes }} registro(s) esperando salir. Su trabajo está guardado.
            </p>

            <!-- ============================================== lo siguiente -->
            <!--
                Va primero y ocupa el espacio de todo lo demás junto, porque es
                la única pregunta que importa cuando se abre la aplicación:
                ¿a dónde voy ahora?
            -->
            <RouterLink
                v-if="datos.siguiente"
                :to="`/campo/${datos.siguiente.paquete_id}/entrega`"
                class="siguiente"
                :class="{ 'siguiente--vencida': datos.siguiente.vencida }"
            >
                <span class="sig-et">Lo siguiente</span>
                <span class="sig-accion">{{ datos.siguiente.accion === 'recoger' ? 'Recoger en' : 'Entregar en' }}</span>
                <span class="sig-sede">{{ datos.siguiente.sede ?? '—' }}</span>
                <span class="sig-cliente">{{ datos.siguiente.cliente }}</span>

                <span class="sig-hora num">
                    {{ hora(datos.siguiente.limite) }}
                    <small v-if="cuentaAtras">· {{ cuentaAtras }}</small>
                </span>
            </RouterLink>

            <p v-else class="vacio">No le quedan tareas pendientes. Buen trabajo.</p>

            <!-- =================================================== cifras -->
            <ul class="cifras">
                <li>
                    <span class="n">{{ datos.pendientes }}</span>
                    <span class="e">pendientes</span>
                </li>
                <li>
                    <span class="n">{{ datos.por_recoger }}</span>
                    <span class="e">por recoger</span>
                </li>
                <li>
                    <span class="n">{{ datos.por_entregar }}</span>
                    <span class="e">por entregar</span>
                </li>
                <li>
                    <span class="n">{{ datos.en_custodia }}</span>
                    <span class="e">lleva encima</span>
                </li>
            </ul>

            <!--
                Las dos excepciones van aparte y en color, no mezcladas con las
                cifras de arriba: una lista donde todo pesa igual es una lista
                donde nada destaca.
            -->
            <RouterLink v-if="datos.vencidos" to="/campo" class="alarma alarma--vencida">
                ⚠ {{ datos.vencidos }} envío(s) pasados de la hora pactada
            </RouterLink>

            <RouterLink v-if="datos.con_novedad" to="/campo" class="alarma alarma--novedad">
                ◍ {{ datos.con_novedad }} con novedad abierta
            </RouterLink>

            <!-- ================================================== pausar -->
            <section v-if="!datos.pausa" class="pausar">
                <button type="button" class="btn-pausa" :disabled="pausando" @click="abrirPausa('almuerzo')">
                    Iniciar almuerzo
                </button>

                <button type="button" class="btn-pausa btn-pausa--sec" @click="eligiendoMotivo = !eligiendoMotivo">
                    Otra pausa
                </button>

                <div v-if="eligiendoMotivo" class="motivos">
                    <button
                        v-for="m in MOTIVOS" :key="m.valor" type="button" class="motivo"
                        :disabled="pausando || (m.valor === 'otro' && !motivoOtro.trim())"
                        @click="abrirPausa(m.valor)"
                    >{{ m.etiqueta }}</button>

                    <input
                        v-model="motivoOtro" class="motivo-txt" type="text"
                        placeholder="Si es otro motivo, escriba cuál"
                    >
                </div>
            </section>

            <!-- ====================================================== hoy -->
            <section class="hoy">
                <h2 class="hoy-tit">Hoy</h2>
                <p class="hoy-txt">
                    <strong>{{ datos.entregados_hoy }}</strong> entregado(s)
                    <template v-if="datos.cerrados_hoy > datos.entregados_hoy">
                        · {{ datos.cerrados_hoy - datos.entregados_hoy }} cerrado(s) de otra forma
                    </template>
                    <template v-if="datos.carga_kg">
                        · <strong>{{ datos.carga_kg.toFixed(1) }} kg</strong> en custodia
                    </template>
                    <template v-if="datos.pausado_hoy_min">
                        · <strong>{{ datos.pausado_hoy_min }} min</strong> en pausas
                    </template>
                </p>
            </section>

            <RouterLink to="/campo" class="btn-lista">Ver mis tareas</RouterLink>
        </template>
    </div>
</template>

<style scoped>
.jornada {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .9rem;
    flex-wrap: wrap;
    padding: .75rem .95rem;
    border: 1px solid var(--linea);
    border-radius: 12px;
    background: var(--superficie);
}

.jornada-estado {
    margin: 0;
    font-size: .92rem;
    font-weight: 700;
    color: var(--ink);
}

.jornada-hora {
    font-weight: 400;
    color: var(--ink-3);
    font-variant-numeric: tabular-nums;
}

.jornada-pista {
    margin: .2rem 0 0;
    font-size: .78rem;
    line-height: 1.45;
    color: var(--ink-3);
}

.btn-jornada {
    /* Dedo, no ratón: se pulsa al bajarse de la moto. */
    min-height: 48px;
    padding: 0 1.1rem;
    border: 1px solid var(--acento);
    border-radius: 10px;
    font: inherit;
    font-size: .9rem;
    font-weight: 600;
    color: var(--sobre-acento);
    background: var(--acento);
    cursor: pointer;
}

.btn-jornada:disabled { opacity: .55; cursor: progress; }

.franja--ok {
    background: var(--superficie-2);
    border-left: 3px solid var(--bien);
}

.resumen { max-width: 560px; margin: 0 auto; padding: 1rem .8rem 2.5rem; }

.cab { margin-bottom: .9rem; }
.titulo { font-size: 1.5rem; font-weight: 700; letter-spacing: -.03em; margin: 0; }
.sub { font-size: .85rem; color: var(--ink-3); margin: .2rem 0 0; }

.vacio { text-align: center; color: var(--ink-3); font-size: .9rem; padding: 1.4rem 0; }

.franja { border-radius: var(--r-md); padding: .7rem .9rem; font-size: .84rem; line-height: 1.5; margin: 0 0 .8rem; }
.franja--sin-red { background: #ffe9e5; color: #7a1f14; border-left: 3px solid #b3261e; }
.franja--turno { background: var(--superficie-2, #eef2f8); color: var(--ink-2); border-left: 3px solid var(--atencion, #d9a300); }
.franja--pendiente { background: #e8eef9; color: #2b4a80; border-left: 3px solid #2b4a80; }
.franja-enlace { display: block; margin-top: .4rem; color: inherit; font-weight: 600; }

/* ------------------------------------------------------ pausa activa -- */
.pausa-activa {
    display: flex; align-items: center; justify-content: space-between; gap: .8rem;
    background: #fff4e0; border-left: 3px solid #d9a300;
    border-radius: var(--r-md); padding: .8rem .9rem; margin-bottom: .8rem;
}

.pausa-et { display: block; font-size: .8rem; font-weight: 700; color: #7a4a00; }
.pausa-min { display: block; font-size: 1.3rem; font-weight: 700; color: #7a4a00; font-variant-numeric: tabular-nums; }
.pausa-desc { display: block; font-size: .78rem; color: #7a4a00; opacity: .85; }

.btn-reanudar {
    flex: none; min-height: 44px; padding: 0 1.1rem;
    border: none; border-radius: var(--r-sm);
    background: #7a4a00; color: #fff;
    font-family: inherit; font-size: .9rem; font-weight: 600; cursor: pointer;
}

/* ------------------------------------------------------- lo siguiente -- */
.siguiente {
    display: grid;
    gap: .15rem;
    padding: 1rem 1.1rem 1.1rem;
    border-radius: var(--r-md);
    background: var(--ink-1, #0D1333);
    color: #fff;
    text-decoration: none;
    margin-bottom: 1rem;
}

.siguiente--vencida { background: #7a1f14; }

.sig-et { font-size: .66rem; letter-spacing: .14em; text-transform: uppercase; opacity: .7; }
.sig-accion { font-size: .78rem; opacity: .85; margin-top: .3rem; }
.sig-sede { font-size: 1.12rem; font-weight: 700; line-height: 1.2; }
.sig-cliente { font-size: .82rem; opacity: .8; }
.sig-hora { margin-top: .5rem; font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.sig-hora small { font-weight: 400; opacity: .8; }

/* ------------------------------------------------------------ cifras -- */
.cifras {
    list-style: none; margin: 0 0 .8rem; padding: 0;
    display: grid; grid-template-columns: repeat(4, 1fr); gap: .5rem;
}

.cifras li {
    display: flex; flex-direction: column; align-items: center; gap: .1rem;
    padding: .7rem .2rem;
    border: 1px solid var(--linea);
    border-radius: var(--r-md);
    background: var(--superficie);
}

.cifras .n { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1; }
.cifras .e { font-size: .64rem; color: var(--ink-3); text-align: center; line-height: 1.25; }

/* ----------------------------------------------------------- alarmas -- */
.alarma {
    display: block; margin-bottom: .6rem;
    padding: .7rem .9rem; border-radius: var(--r-md);
    font-size: .86rem; font-weight: 600; text-decoration: none;
}

.alarma--vencida { background: #ffe9e5; color: #7a1f14; border-left: 3px solid #b3261e; }
.alarma--novedad { background: #fff4e0; color: #7a4a00; border-left: 3px solid #d9a300; }

/* ------------------------------------------------------------ pausar -- */
.pausar { display: flex; flex-wrap: wrap; gap: .5rem; margin: .4rem 0 .2rem; }

.btn-pausa {
    flex: 1; min-width: 150px; min-height: 46px;
    border: 1px solid var(--linea-fuerte); border-radius: var(--r-md);
    background: var(--superficie); color: var(--ink-2);
    font-family: inherit; font-size: .88rem; font-weight: 600; cursor: pointer;
}

.btn-pausa--sec { font-weight: 400; }

.motivos { flex: 1 0 100%; display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .2rem; }

.motivo {
    min-height: 40px; padding: 0 .8rem;
    border: 1px dashed var(--linea-fuerte); border-radius: var(--r-sm);
    background: var(--superficie); color: var(--ink-2);
    font-family: inherit; font-size: .82rem; cursor: pointer;
}

.motivo:disabled { opacity: .5; cursor: default; }

.motivo-txt {
    flex: 1 0 100%; min-height: 42px; padding: 0 .7rem;
    border: 1px solid var(--linea-fuerte); border-radius: var(--r-sm);
    background: var(--superficie); color: var(--ink-1);
    font-family: inherit; font-size: .85rem;
}

/* --------------------------------------------------------------- hoy -- */
.hoy { border-top: 1px solid var(--linea); padding-top: .8rem; margin-top: .8rem; }
.hoy-tit { font-size: .7rem; letter-spacing: .14em; text-transform: uppercase; color: var(--ink-3); margin: 0 0 .25rem; font-weight: 600; }
.hoy-txt { margin: 0; font-size: .88rem; color: var(--ink-2); line-height: 1.5; }

.btn-lista {
    display: block; margin-top: 1.2rem;
    min-height: 52px; line-height: 52px;
    text-align: center; text-decoration: none;
    border-radius: var(--r-md);
    background: var(--acento); color: #fff;
    font-size: .95rem; font-weight: 600;
}
</style>
