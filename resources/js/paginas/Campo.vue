<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/cliente'
import { guardarOrdenManual, guardarTareas, rechazados, reencolar, tareasLocales } from '../offline/almacen'
import { estadoSync, refrescarContador, sincronizar } from '../offline/sincronizador'

/**
 * Tareas del mensajero.
 *
 * Se dibuja SIEMPRE desde el almacén local y luego se refresca contra el
 * servidor. Abrir la aplicación en el sótano de un hospital no puede dejar la
 * pantalla en blanco: ahí es justamente donde más se necesita.
 *
 * Objetivos táctiles grandes, contraste alto y una sola acción principal por
 * tarjeta. Se usa con guantes, en movimiento y a contraluz.
 */
const router = useRouter()

const tareas = ref([])
const cargando = ref(true)
const desdeCache = ref(false)
const nombre = ref('')

async function cargar() {
    // 1. Local primero: la pantalla responde de inmediato.
    const local = await tareasLocales()

    if (local.length) {
        tareas.value = local
        desdeCache.value = true
        cargando.value = false
    }

    // 2. Servidor después, si hay señal.
    try {
        const r = await api.tareas()
        nombre.value = r.mensajero
        desdeCache.value = false
        await guardarTareas(r.tareas)
        rutaSugerida.value = r.ruta_sugerida ?? []
        pausa.value = r.pausa ?? null

        /*
         * Se relee del almacén y no se usa la respuesta tal cual.
         *
         * `guardarTareas` conserva dos cosas que el servidor no sabe: el estado
         * adelantado de lo que todavía está en la cola de salida, y el orden
         * manual del mensajero. Pintar directamente `r.tareas` las perdía las
         * dos —la ruta que acababa de ordenar se deshacía sola a los treinta
         * segundos, sin que tocara nada—.
         */
        tareas.value = await tareasLocales()
    } catch {
        // Sin señal no es un error que haya que mostrar como falla: es el modo
        // de operación previsto. La franja superior ya lo comunica.
        desdeCache.value = local.length > 0
    } finally {
        cargando.value = false
    }
}

onMounted(() => { cargar(); revisarAtascados() })

/**
 * Cuando la bandeja de salida se vacía, el servidor ya sabe lo que este
 * teléfono hizo: toca volver a preguntarle. Ese refresco es el que trae la
 * dirección de entrega después de una recolección, que el dispositivo no se
 * podía inventar.
 */
watch(() => estadoSync.value.pendientes, (ahora, antes) => {
    if (antes > 0 && ahora === 0) cargar()
})

/**
 * Registros que el servidor rechazó y siguen en el teléfono.
 *
 * No se reintentan solos —si el rechazo era de verdad, insistir no arregla
 * nada— pero tampoco desaparecen. El mensajero ve que algo quedó sin salir y
 * puede volver a intentarlo cuando cambie la circunstancia: típicamente después
 * de volver a entrar, que es lo que resuelve una sesión caducada.
 */
const atascados = ref([])

async function revisarAtascados() {
    atascados.value = await rechazados()
}

async function reintentarAtascados() {
    for (const r of atascados.value) await reencolar(r.id)

    await refrescarContador()
    await revisarAtascados()

    sincronizar()
}

/**
 * Buscador de la lista.
 *
 * Nace de un problema concreto en la calle: el mensajero con varias tareas
 * tenía que abrirlas una por una para ver de qué cliente era cada cual. El
 * dato ya viajaba desde el servidor; sencillamente no se pintaba.
 *
 * Busca por cliente, sede, dirección, guía y referencia a la vez, porque en
 * la calle uno recuerda cualquiera de esas cosas y no la que la interfaz
 * decidió privilegiar. Sin acentos y sin mayúsculas: nadie los escribe bien
 * caminando.
 */
const busqueda = ref('')

function sinTildes(t) {
    return String(t ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
}

function coincide(t, aguja) {
    if (!aguja) return true

    return [t.cliente, t.sede?.nombre, t.sede?.direccion, t.guia, t.referencia]
        .some((campo) => sinTildes(campo).includes(aguja))
}

const filtradas = computed(() => {
    const aguja = sinTildes(busqueda.value).trim()

    return tareas.value.filter((t) => coincide(t, aguja))
})

/*
 * Pestanas por estado.
 *
 * El monton unico obligaba a leerlo entero para encontrar lo urgente, y lo
 * urgente es justo lo que no se puede tardar en encontrar. Cada pestana
 * responde una pregunta concreta que el mensajero se hace en la calle:
 * «que no puede esperar», «que llevo encima», «donde no me recibieron» y
 * «que ya cerre».
 *
 * «Todas» se queda primero: una pestana que esconde tareas sin que se note es
 * peor que una lista larga.
 */
const SOLAPAS = [
    { k: 'todas', t: 'Todas', filtro: () => true },
    { k: 'urgentes', t: 'Urgentes', filtro: (t) => t.criticidad === 'urgente' && t.estado !== 'entregado' },
    { k: 'transito', t: 'En tránsito', filtro: (t) => ['recolectado', 'en_transito', 'en_sitio'].includes(t.estado) },
    { k: 'novedad', t: 'No recibidas', filtro: (t) => t.estado === 'novedad' },
    { k: 'hechas', t: 'Entregadas', filtro: (t) => t.estado === 'entregado' },
]

const solapa = ref('todas')

const cuentaSolapa = computed(() => Object.fromEntries(
    SOLAPAS.map((s) => [s.k, filtradas.value.filter(s.filtro).length])
))

const deLaSolapa = computed(() => {
    const activa = SOLAPAS.find((s) => s.k === solapa.value) ?? SOLAPAS[0]

    return filtradas.value.filter(activa.filtro)
})

/*
 * En «Entregadas» la lista de pendientes va vacia y todo cae en el desplegable
 * de hechas, que es donde el mensajero lo espera. En el resto, al reves.
 */
/*
 * Orden manual del mensajero.
 *
 * El montón llega ordenado por estado, que es un criterio del sistema y no el
 * de la calle: lo que decide por dónde empezar es la geografía, los horarios de
 * las porterías y dónde almuerza. Esto deja que lo ponga él.
 *
 * Solo se ofrece en «Todas», porque reordenar un subconjunto y que el orden se
 * aplique al resto es justo la clase de cosa que nadie espera.
 */
/*
 * La pausa abierta bloquea las acciones de campo.
 *
 * Pasaba esto: el mensajero marcaba almuerzo, volvia a la calle sin pulsar
 * «Reanudar» y seguia entregando con el contador corriendo. La plataforma le
 * dejaba, y el informe de productividad descontaba como descanso las horas en
 * que mas trabajo — dos mentiras a la vez: el almuerzo inflado y la jornada
 * recortada.
 *
 * Ahora el boton de la tarea no lleva a ninguna parte hasta reanudar, y el
 * propio boton lo hace: «Reanudar y continuar» cierra la pausa y abre la
 * entrega en el mismo toque. No se le pide ir a otra pantalla a buscarlo,
 * porque eso es como se aprende a no marcar el almuerzo.
 */
const pausa = ref(null)
const reanudando = ref(false)

/*
 * Esta pantalla no tenia un sitio donde contar un fallo —`cargar()` se calla a
 * proposito, porque sin senal se pinta la copia local y eso no es un error—.
 * Reanudar si necesita uno: si no se pudo cerrar la pausa, el mensajero tiene
 * que enterarse antes de seguir, no quedarse mirando un boton que no hizo nada.
 */
const errorPausa = ref(null)

async function reanudarYSeguir(paqueteId = null) {
    reanudando.value = true
    errorPausa.value = null

    try {
        await api.cerrarPausa(await donde())
        pausa.value = null

        if (paqueteId !== null) {
            router.push(`/campo/${paqueteId}/entrega`)
        }
    } catch (e) {
        errorPausa.value = e.cuerpo?.message ?? e.message
    } finally {
        reanudando.value = false
    }
}

/** La coordenada, si el navegador la da. Nunca bloquea: ver CampoRecoger. */
function donde() {
    return new Promise((resolver) => {
        if (!navigator.geolocation || !window.isSecureContext) return resolver({})

        const corte = setTimeout(() => resolver({}), 4000)

        navigator.geolocation.getCurrentPosition(
            (p) => {
                clearTimeout(corte)
                resolver({ latitud: p.coords.latitude, longitud: p.coords.longitude })
            },
            () => { clearTimeout(corte); resolver({}) },
            { timeout: 4000, maximumAge: 30000 },
        )
    })
}

const reordenando = ref(false)

/*
 * La ruta que propone el servidor: primero la hora comprometida, y dentro de
 * cada franja la cercania. Se guarda aparte del orden manual porque no lo pisa:
 * el mensajero la aplica si quiere, con un boton, y a partir de ahi manda lo
 * que el ponga.
 */
const rutaSugerida = ref([])

const puedeSugerir = computed(() => rutaSugerida.value.length > 1)

async function aplicarRutaSugerida() {
    /*
     * Lo sugerido primero y el resto detras, en el orden que ya tenian. Si una
     * tarea entro despues de calcularse la sugerencia, no puede desaparecer de
     * la lista por no estar en ella.
     */
    const sugeridos = rutaSugerida.value.map(String)
    const resto = pendientes.value
        .map((t) => String(t.paquete_id))
        .filter((id) => !sugeridos.includes(id))

    await guardarOrdenManual([...sugeridos, ...resto])
    tareas.value = await tareasLocales()
    reordenando.value = true
}

function conOrdenManual(lista) {
    return [...lista].sort((a, b) => {
        // Las que nunca se movieron van al final, en el orden que traían. Así
        // una tarea nueva no se cuela en medio de la ruta ya pensada.
        const oa = a.orden ?? Number.MAX_SAFE_INTEGER
        const ob = b.orden ?? Number.MAX_SAFE_INTEGER

        return oa - ob
    })
}

async function mover(paqueteId, salto) {
    const actual = pendientes.value.map((t) => String(t.paquete_id))
    const i = actual.indexOf(String(paqueteId))
    const j = i + salto

    if (i < 0 || j < 0 || j >= actual.length) return

    const [movida] = actual.splice(i, 1)
    actual.splice(j, 0, movida)

    await guardarOrdenManual(actual)
    tareas.value = await tareasLocales()
}

const pendientes = computed(() => conOrdenManual(deLaSolapa.value.filter((t) => t.estado !== 'entregado')))
const hechas = computed(() => deLaSolapa.value.filter((t) => t.estado === 'entregado'))

/** Cuántas quedan fuera del filtro, para que nadie crea que se le perdieron. */
const ocultas = computed(() => tareas.value.length - filtradas.value.length)

/*
 * Cuántas esperan recogida, sobre TODAS las tareas y no sobre las filtradas:
 * es la cifra que decide si ofrecer el escaneo continuo, y no debe cambiar
 * porque alguien esté buscando otra cosa.
 */
const porRecoger = computed(() => tareas.value.filter((t) => t.accion === 'recoger').length)

/**
 * Cuándo se le asignó, dicho como lo diría una persona.
 *
 * «Hoy 09:14» y «hace 3 días» responden la pregunta que de verdad se hace el
 * mensajero —¿esto es de hoy o lo vengo arrastrando?— mucho mejor que una
 * fecha completa, que hay que leer y comparar.
 */
function desdeCuando(iso) {
    const d = new Date(iso)
    const dias = Math.floor((new Date().setHours(0, 0, 0, 0) - new Date(iso).setHours(0, 0, 0, 0)) / 86400000)
    const hora = d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: false })

    if (dias === 0) return `hoy ${hora}`
    if (dias === 1) return `ayer ${hora}`

    return `hace ${dias} días`
}

function claseTipo(tipo) {
    return {
        cadena_frio: 'tarea--frio',
        controlado: 'tarea--controlado',
    }[tipo] ?? ''
}

function urlNavegacion(sede) {
    if (!sede?.latitud) return null
    // Waze si está instalado; el sistema cae a Google Maps si no.
    return `https://waze.com/ul?ll=${sede.latitud},${sede.longitud}&navigate=yes`
}
</script>

<template>
    <div class="campo">
        <header class="cab">
            <div>
                <h1 class="titulo">Mis tareas</h1>
                <p class="sub">
                    {{ nombre || 'Mensajero' }} ·
                    <strong>{{ pendientes.length }}</strong> pendientes ·
                    {{ hechas.length }} hechas
                </p>
            </div>

            <!--
                Solo aparece si hay algo que recoger. Un botón permanente que
                casi siempre lleva a una pantalla vacía enseña a ignorarlo.
            -->
            <RouterLink
                v-if="porRecoger > 1" to="/campo/recogida-rapida" class="atajo-rapida"
            >
                ⚡ Recogida rápida
                <span class="atajo-n">{{ porRecoger }}</span>
            </RouterLink>
        </header>

        <!--
            Lo primero de la pantalla cuando hay una pausa abierta: el contador
            sigue corriendo y eso tiene que verse antes que nada.
        -->
        <div v-if="pausa" class="franja franja--pausa" role="alert">
            <div>
                <strong>En pausa · {{ pausa.etiqueta }}</strong>
                <span class="franja-sub">
                    Lleva {{ pausa.minutos }} min y el contador sigue. Reanude antes de entregar.
                </span>
            </div>

            <button type="button" class="btn-reanudar" :disabled="reanudando" @click="reanudarYSeguir()">
                {{ reanudando ? 'Un momento…' : 'Reanudar' }}
            </button>
        </div>

        <p v-if="errorPausa" class="franja franja--atasco" role="alert">
            ▲ No se pudo reanudar: {{ errorPausa }}
        </p>

        <p v-if="desdeCache" class="franja">
            ◍ Mostrando la copia guardada en este teléfono. Se actualizará al recuperar señal.
        </p>

        <!--
            Decir «esperando salir» y callar el motivo deja al mensajero sin
            saber si es cosa de dos segundos o de algo roto. Si hay un error
            registrado, se enseña.
        -->
        <p v-if="estadoSync.pendientes > 0" class="franja franja--pendiente">
            ▲ {{ estadoSync.pendientes }} registro(s) esperando salir. Su trabajo está guardado.
            <span v-if="!estadoSync.enLinea" class="franja-sub">Sin conexión: saldrán solos al recuperar señal.</span>
            <span v-else-if="estadoSync.ultimoError" class="franja-sub">{{ estadoSync.ultimoError }}</span>
            <span v-else-if="estadoSync.sincronizando" class="franja-sub">Enviando…</span>
        </p>

        <p v-if="atascados.length" class="franja franja--atasco" role="alert">
            ▲ {{ atascados.length }} registro(s) no se pudieron enviar y siguen guardados aquí.
            <span class="franja-sub">{{ atascados[0].ultimo_error }}</span>
            <button type="button" class="btn-reintentar" @click="reintentarAtascados">Volver a intentarlo</button>
        </p>

        <p v-if="cargando" class="vacio">Cargando tareas…</p>

        <p v-else-if="!tareas.length" class="vacio">
            No hay tareas asignadas por ahora.
        </p>

        <template v-else>
            <!--
                `type="search"` para que el teclado del teléfono traiga la equis
                de borrar: con guantes o de pie, vaciar el campo a base de
                retrocesos es una pelea innecesaria.
            -->
            <div class="buscador">
                <input
                    v-model="busqueda"
                    class="buscador-campo"
                    type="search"
                    inputmode="search"
                    autocapitalize="none"
                    spellcheck="false"
                    placeholder="Buscar cliente, sede, guía…"
                    aria-label="Buscar entre mis tareas"
                >
                <button v-if="busqueda" type="button" class="buscador-limpiar" @click="busqueda = ''">
                    Ver todas
                </button>
            </div>

            <nav class="solapas" aria-label="Filtrar tareas por estado">
                <button
                    v-for="sp in SOLAPAS" :key="sp.k" type="button"
                    class="solapa" :class="{ 'solapa--on': solapa === sp.k }"
                    :aria-pressed="solapa === sp.k"
                    @click="solapa = sp.k"
                >
                    {{ sp.t }}
                    <span class="solapa-n">{{ cuentaSolapa[sp.k] }}</span>
                </button>
            </nav>

            <p v-if="busqueda && ocultas > 0" class="filtrando">
                Mostrando {{ pendientes.length + hechas.length }} de {{ tareas.length }} tareas.
            </p>

            <p v-if="!pendientes.length && !hechas.length" class="vacio">
                <template v-if="busqueda">Ninguna tarea coincide con «{{ busqueda }}».</template>
                <template v-else>Nada en esta pestaña ahora mismo.</template>
            </p>
        </template>

        <div v-if="!cargando && pendientes.length > 1 && solapa === 'todas'" class="barra-orden">
            <button
                type="button" class="btn-orden" :class="{ 'btn-orden--on': reordenando }"
                :aria-pressed="reordenando" @click="reordenando = !reordenando"
            >
                {{ reordenando ? '✓ Listo' : '↕ Ordenar mi ruta' }}
            </button>
            <button
                v-if="puedeSugerir" type="button" class="btn-orden"
                @click="aplicarRutaSugerida"
            >
                ✦ Usar ruta sugerida
            </button>

            <span v-if="reordenando" class="orden-pista">
                Use las flechas para poner las tareas en el orden en que las va a hacer.
                Se guarda en este teléfono.
            </span>

            <span v-else-if="puedeSugerir" class="orden-pista">
                La ruta sugerida ordena primero por la hora comprometida y, dentro de cada
                franja, por cercanía. Es un punto de partida: después la cambia a mano.
            </span>
        </div>

        <ul v-if="!cargando && pendientes.length" class="lista">
            <li v-for="(t, i) in pendientes" :key="t.paquete_id">
                <!--
                    Flechas y no arrastrar: se usa de pie, en movimiento y a
                    veces con guantes. Arrastrar en un teléfono compite con el
                    desplazamiento de la página y falla justo cuando hay prisa.
                -->
                <div v-if="reordenando" class="flechas">
                    <button
                        type="button" class="flecha" :disabled="i === 0"
                        :aria-label="`Subir ${t.guia}`" @click="mover(t.paquete_id, -1)"
                    >▲</button>
                    <span class="flecha-n">{{ i + 1 }}</span>
                    <button
                        type="button" class="flecha" :disabled="i === pendientes.length - 1"
                        :aria-label="`Bajar ${t.guia}`" @click="mover(t.paquete_id, 1)"
                    >▼</button>
                </div>

                <article class="tarea" :class="claseTipo(t.tipo)">
                    <header class="tarea-cab">
                        <span class="accion">{{ t.accion === 'recoger' ? 'Recoger' : 'Entregar' }}</span>
                        <span class="pastilla" :class="t.criticidad === 'urgente' ? 'pastilla--critico' : 'pastilla--neutral'">
                            {{ t.criticidad === 'urgente' ? '⚡ Urgente' : 'Programado' }}
                        </span>
                        <span v-if="t.tipo === 'cadena_frio'" class="pastilla pastilla--neutral">❄ Frío</span>
                        <span v-if="t.tipo === 'controlado'" class="pastilla pastilla--atencion">⊘ Controlado</span>
                        <!-- El mensajero también tiene que saberlo: cambia cómo
                             lo lleva y dónde lo deja mientras conduce. -->
                        <span v-if="t.alto_costo" class="pastilla pastilla--alto">$ Alto costo</span>
                        <span v-if="t.sincronizando" class="pastilla pastilla--espera">◍ Registrada · enviando</span>
                    </header>

                    <!--
                        El cliente primero. Es lo que el mensajero busca cuando
                        repasa la lista: la sede le dice dónde, pero el cliente
                        le dice de quién, y sin eso no distingue dos tareas en
                        la misma dirección.
                    -->
                    <p class="cliente">{{ t.cliente }}</p>
                    <p class="sede">{{ t.sede?.nombre }}</p>
                    <p class="direccion">{{ t.sede?.direccion }}</p>

                    <p v-if="t.nota" class="nota-estado">{{ t.nota }}</p>

                    <dl class="meta">
                        <div><dt>Ventana</dt><dd class="num">{{ t.ventana.desde }}–{{ t.ventana.hasta }}</dd></div>
                        <div v-if="t.peso_kg"><dt>Peso</dt><dd class="num">{{ t.peso_kg }} kg</dd></div>
                        <div v-if="t.rango_termico">
                            <dt>Rango</dt>
                            <dd class="num">{{ t.rango_termico.min }}–{{ t.rango_termico.max }} °C</dd>
                        </div>
                        <div><dt>Guía</dt><dd class="num">{{ t.guia }}</dd></div>
                        <!--
                            Desde cuándo la lleva él, no cuándo se radicó. Una guía
                            reasignada esta mañana y otra que arrastra desde el
                            lunes se veían igual, y no se parecen en nada.
                        -->
                        <div v-if="t.asignada_en"><dt>Asignada</dt><dd class="num">{{ desdeCuando(t.asignada_en) }}</dd></div>
                        <div v-if="t.referencia"><dt>Referencia</dt><dd class="num">{{ t.referencia }}</dd></div>
                    </dl>

                    <div class="acciones">
                        <a
                            v-if="urlNavegacion(t.sede)"
                            :href="urlNavegacion(t.sede)"
                            class="btn btn--sec"
                            target="_blank"
                            rel="noopener"
                        >🧭 Navegar</a>

                        <!--
                            Mientras el registro viaja, la acción se bloquea. Es
                            lo único que impide que la misma entrega se registre
                            dos veces cuando la red va lenta y la tarjeta sigue
                            en pantalla.
                        -->
                        <span v-if="t.sincronizando" class="btn btn--espera" aria-disabled="true">
                            ◍ Enviando…
                        </span>

                        <!--
                            Sin acción disponible: se dice por qué y no se ofrece
                            botón. Un paquete con novedad está esperando que
                            operaciones decida; ofrecerle «Recolectar» le hacía
                            registrar eventos que no movían nada.
                        -->
                        <span v-else-if="!t.accion" class="btn btn--espera" aria-disabled="true">
                            {{ t.estado_etiqueta }}
                        </span>

                        <!--
                            Con una pausa corriendo no se entra a la entrega.
                            El mismo boton la cierra y sigue: un toque, no dos
                            pantallas.
                        -->
                        <button
                            v-else-if="pausa"
                            type="button" class="btn btn--pausa"
                            :disabled="reanudando"
                            @click="reanudarYSeguir(t.paquete_id)"
                        >
                            {{ reanudando ? 'Reanudando…' : 'Reanudar y continuar' }}
                        </button>

                        <RouterLink v-else :to="`/campo/${t.paquete_id}/entrega`" class="btn btn--pri">
                            {{ t.accion === 'recoger' ? 'Recolectar' : 'Entregar' }}
                        </RouterLink>
                    </div>
                </article>
            </li>
        </ul>

        <details v-if="hechas.length" class="hechas">
            <summary>{{ hechas.length }} completadas</summary>
            <ul>
                <li v-for="t in hechas" :key="t.paquete_id">
                    <span class="pastilla pastilla--bien">✓</span>
                    <span>{{ t.cliente }} · {{ t.sede?.nombre }}</span>
                    <span class="num">{{ t.guia }}</span>
                </li>
            </ul>
        </details>
    </div>
</template>

<style scoped>
.campo {
    max-width: 640px;
    margin: 0 auto;
    padding: 1rem .8rem 2rem;
}

/* El atajo va a la derecha del título, y debajo cuando no cabe. */
.cab {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .7rem;
    margin-bottom: .8rem;
}
.titulo { font-size: 1.3rem; font-weight: 700; letter-spacing: -.03em; margin: 0; }
.sub { font-size: .82rem; color: var(--ink-3); margin: .2rem 0 0; }
.sub strong { color: var(--ink); }

.franja {
    background: var(--atencion-suave);
    color: var(--atencion);
    font-family: var(--mono);
    font-size: .72rem;
    padding: .5rem .7rem;
    border-radius: var(--r-sm);
    margin: 0 0 .7rem;
}

.franja--pendiente { background: var(--acento-suave); color: var(--acento-ink); }

.lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .7rem; }

.franja-sub { display: block; margin-top: .25rem; font-size: .78rem; opacity: .85; }

.nota-estado {
    margin: .5rem 0 0;
    padding: .45rem .6rem;
    border-left: 3px solid var(--atencion, #d9a300);
    background: var(--superficie-2, #f4f7fb);
    font-size: .82rem;
    line-height: 1.45;
    color: var(--ink-2);
}

.franja--atasco { background: #ffe9e5; color: #7a1f14; border-left: 3px solid #b3261e; }

.btn-reintentar {
    display: block; margin-top: .6rem;
    min-height: 40px; padding: 0 1rem;
    border: 1px solid #b3261e; border-radius: var(--r-sm);
    background: #fff; color: #7a1f14;
    font-family: inherit; font-size: .85rem; font-weight: 600; cursor: pointer;
}

.pastilla--espera { background: #e8eef9; color: #2b4a80; }
.pastilla--alto { background: #f3e4ff; color: #5b2a86; font-weight: 700; }

.btn--espera {
    background: var(--superficie-2, #eef2f8);
    color: var(--ink-3);
    border: 1px dashed var(--linea-fuerte);
    cursor: default;
}

/* ------------------------------------------------------------- buscador */
.buscador { display: flex; gap: .5rem; margin-bottom: .7rem; }

.buscador-campo {
    flex: 1;
    min-width: 0;
    /* 44 px es el mínimo que se acierta con el pulgar en movimiento. */
    min-height: 46px;
    padding: .6rem .8rem;
    border: 1px solid var(--linea-fuerte);
    border-radius: var(--r-md);
    background: var(--superficie);
    color: var(--ink-1);
    font-family: inherit;
    font-size: 1rem;
}

.buscador-campo:focus-visible { outline: 2px solid var(--acento); outline-offset: 1px; }

.buscador-limpiar {
    flex: none;
    padding: 0 .9rem;
    border: 1px solid var(--linea-fuerte);
    border-radius: var(--r-md);
    background: var(--superficie);
    color: var(--ink-2);
    font-family: inherit;
    font-size: .85rem;
    cursor: pointer;
}

.filtrando { margin: -.3rem 0 .7rem; font-size: .8rem; color: var(--ink-3); }

.cliente {
    margin: 0 0 .15rem;
    font-size: 1.02rem;
    font-weight: 700;
    letter-spacing: -.01em;
    color: var(--ink-1);
}

.tarea {
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-left: 5px solid var(--linea-fuerte);
    border-radius: var(--r-md);
    padding: .85rem;
}

.tarea--frio { border-left-color: var(--serie-1); }
.tarea--controlado { border-left-color: var(--critico); }

.tarea-cab { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-bottom: .5rem; }

.accion {
    font-size: 1.05rem;
    font-weight: 700;
    letter-spacing: -.02em;
    margin-right: .2rem;
}

.sede { font-size: 1rem; font-weight: 600; margin: 0; }
.direccion { font-size: .88rem; color: var(--ink-2); margin: .1rem 0 .6rem; line-height: 1.35; }

.meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: .5rem .8rem;
    margin: 0 0 .8rem;
}

.meta dt {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.meta dd { margin: 0; font-size: .84rem; font-weight: 500; }

.acciones { display: flex; gap: .5rem; }

.btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    min-height: var(--toque);
    border-radius: var(--r-md);
    font-size: .95rem;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid transparent;
}

.btn--pri { background: var(--acento); color: var(--sobre-acento); }
.btn--sec { background: var(--superficie); border-color: var(--linea-fuerte); color: var(--ink); }

.vacio {
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--ink-3);
    font-size: .88rem;
}

.franja--pausa {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .8rem;
    flex-wrap: wrap;
    background: var(--atencion-suave);
    border-left: 4px solid var(--atencion);
    color: var(--ink);
}

.btn-reanudar {
    /* Dedo, no ratón: se pulsa al bajarse de la moto. */
    min-height: 44px;
    padding: 0 1rem;
    border: 0;
    border-radius: 10px;
    font: inherit;
    font-size: .88rem;
    font-weight: 700;
    color: #FFFFFF;
    background: var(--atencion-fuerte, #8A5A00);
    cursor: pointer;
}

.btn-reanudar:disabled { opacity: .6; cursor: progress; }

.btn--pausa {
    background: var(--atencion-fuerte, #8A5A00);
    color: #FFFFFF;
    border-color: var(--atencion-fuerte, #8A5A00);
}

.barra-orden {
    display: flex;
    align-items: center;
    gap: .7rem;
    flex-wrap: wrap;
    margin: .2rem 0 .6rem;
}

.btn-orden {
    border: 1px solid var(--linea-fuerte);
    border-radius: 999px;
    min-height: 40px;
    padding: 0 .9rem;
    font: inherit;
    font-size: .82rem;
    font-weight: 600;
    color: var(--ink-2);
    background: var(--superficie);
    cursor: pointer;
}

.btn-orden--on {
    background: var(--acento);
    border-color: var(--acento);
    color: var(--sobre-acento);
}

.orden-pista {
    flex: 1 1 14rem;
    font-size: .78rem;
    line-height: 1.45;
    color: var(--ink-3);
}

.flechas {
    display: flex;
    align-items: center;
    gap: .45rem;
    margin-bottom: .4rem;
}

.flecha {
    /* Objetivo grande: se pulsa de pie y en movimiento. */
    min-width: 48px;
    min-height: 44px;
    border: 1px solid var(--linea-fuerte);
    border-radius: 10px;
    font: inherit;
    font-size: 1rem;
    color: var(--ink-2);
    background: var(--superficie);
    cursor: pointer;
}

.flecha:disabled { opacity: .35; cursor: default; }

.flecha-n {
    min-width: 2ch;
    font-size: .85rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--ink-3);
}

.atajo-rapida {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    /* Dedo, no ratón: se pulsa de pie, con las manos ocupadas. */
    min-height: 44px;
    padding: 0 .9rem;
    border-radius: 999px;
    background: var(--acento);
    color: var(--sobre-acento);
    font-size: .85rem;
    font-weight: 600;
    text-decoration: none;
}

.atajo-n {
    font-size: .78rem;
    opacity: .8;
    font-variant-numeric: tabular-nums;
}

.solapas {
    display: flex;
    gap: .35rem;
    overflow-x: auto;
    margin: .6rem 0 .2rem;
    padding-bottom: .2rem;
    /* Sin esto, en un teléfono la última pestaña queda pegada al borde y no se
       ve que hay más a la derecha. */
    scrollbar-width: none;
}

.solapas::-webkit-scrollbar { display: none; }

.solapa {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border: 1px solid var(--linea-fuerte);
    border-radius: 999px;
    padding: .4rem .75rem;
    font: inherit;
    font-size: .82rem;
    color: var(--ink-2);
    background: var(--superficie);
    /* Dedo, no ratón: el objetivo tiene que poder pulsarse en movimiento. */
    min-height: 40px;
    cursor: pointer;
}

.solapa--on {
    background: var(--acento);
    border-color: var(--acento);
    color: var(--sobre-acento);
    font-weight: 600;
}

.solapa-n {
    font-size: .74rem;
    opacity: .75;
    font-variant-numeric: tabular-nums;
}

.hechas { margin-top: 1.2rem; }
.hechas summary { font-size: .82rem; color: var(--ink-3); cursor: pointer; }
.hechas ul { list-style: none; margin: .6rem 0 0; padding: 0; display: flex; flex-direction: column; gap: .35rem; }
.hechas li { display: flex; gap: .5rem; align-items: center; font-size: .8rem; color: var(--ink-3); }
</style>
