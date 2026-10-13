<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/cliente'
import { guardarTareas, rechazados, reencolar, tareasLocales } from '../offline/almacen'
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
        tareas.value = r.tareas
        nombre.value = r.mensajero
        desdeCache.value = false
        await guardarTareas(r.tareas)
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

const pendientes = computed(() => filtradas.value.filter((t) => t.estado !== 'entregado'))
const hechas = computed(() => filtradas.value.filter((t) => t.estado === 'entregado'))

/** Cuántas quedan fuera del filtro, para que nadie crea que se le perdieron. */
const ocultas = computed(() => tareas.value.length - filtradas.value.length)

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
        </header>

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

            <p v-if="busqueda && ocultas > 0" class="filtrando">
                Mostrando {{ pendientes.length + hechas.length }} de {{ tareas.length }} tareas.
            </p>

            <p v-if="!pendientes.length && !hechas.length" class="vacio">
                Ninguna tarea coincide con «{{ busqueda }}».
            </p>
        </template>

        <ul v-if="!cargando && pendientes.length" class="lista">
            <li v-for="t in pendientes" :key="t.paquete_id">
                <article class="tarea" :class="claseTipo(t.tipo)">
                    <header class="tarea-cab">
                        <span class="accion">{{ t.accion === 'recoger' ? 'Recoger' : 'Entregar' }}</span>
                        <span class="pastilla" :class="t.criticidad === 'urgente' ? 'pastilla--critico' : 'pastilla--neutral'">
                            {{ t.criticidad === 'urgente' ? '⚡ Urgente' : 'Programado' }}
                        </span>
                        <span v-if="t.tipo === 'cadena_frio'" class="pastilla pastilla--neutral">❄ Frío</span>
                        <span v-if="t.tipo === 'controlado'" class="pastilla pastilla--atencion">⊘ Controlado</span>
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
                        <div><dt>Peso</dt><dd class="num">{{ t.peso_kg }} kg</dd></div>
                        <div v-if="t.rango_termico">
                            <dt>Rango</dt>
                            <dd class="num">{{ t.rango_termico.min }}–{{ t.rango_termico.max }} °C</dd>
                        </div>
                        <div><dt>Guía</dt><dd class="num">{{ t.guia }}</dd></div>
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

                        <RouterLink v-else :to="`/campo/${t.paquete_id}/entrega`" class="btn btn--pri">
                            {{ t.accion === 'recoger' ? 'Recolectar' : 'Entregar' }}
                        </RouterLink>
                    </div>
                </article>
            </li>
        </ul>

        <details v-if="hechas.length" class="hechas">
            <summary>{{ hechas.length }} completadas hoy</summary>
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

.cab { margin-bottom: .8rem; }
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

.hechas { margin-top: 1.2rem; }
.hechas summary { font-size: .82rem; color: var(--ink-3); cursor: pointer; }
.hechas ul { list-style: none; margin: .6rem 0 0; padding: 0; display: flex; flex-direction: column; gap: .35rem; }
.hechas li { display: flex; gap: .5rem; align-items: center; font-size: .8rem; color: var(--ink-3); }
</style>
