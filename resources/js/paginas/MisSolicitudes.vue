<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/cliente'
import { sesion } from '../sesion'
import Hero from '../componentes/Hero.vue'
import { fechaCorta, fechaLarga } from '../graficos/util'

/**
 * El histórico de solicitudes del cliente.
 *
 * Antes solo existía el buscador: había que conocer la guía o la referencia
 * para llegar a un envío. Quien no recordaba el número no llegaba. Esta es la
 * puerta de entrada que faltaba.
 *
 * Los filtros preguntan en GRUESO y la fila responde en fino. El sistema tiene
 * once estados; un desplegable con once opciones no lo usa nadie. Estas cuatro
 * son las preguntas que de verdad se hacen —«¿sigue en camino?», «¿ya llegó?»,
 * «¿hay algún problema?»— y el estado exacto queda visible en cada fila, que es
 * donde importa el detalle.
 *
 * Las claves de los grupos las define `EstadoPaquete::grupos()` en el servidor;
 * aquí se repiten porque son cuatro constantes estables y pedirlas por red
 * costaría un viaje para traer cuatro cadenas.
 */
const GRUPOS = [
    { clave: '',                     texto: 'Todas' },
    { clave: 'en_tramite',           texto: 'En trámite' },
    { clave: 'entregadas',           texto: 'Entregadas' },
    { clave: 'con_novedad',          texto: 'Con novedad' },
    { clave: 'cerradas_sin_entrega', texto: 'Cerradas sin entrega' },
    // Aparte de «cerradas sin entrega»: una cancelación del propio cliente
    // antes de que la caja saliera no es un fallo del servicio, y mezclarlas
    // ensuciaría el único grupo que sirve para mirar lo que se torció.
    { clave: 'canceladas',           texto: 'Canceladas' },
]

const filtros = ref({ estado: '', desde: '', hasta: '', buscar: '', sede: '' })

/*
 * Las sedes del desplegable salen del catálogo de radicación.
 *
 * Es el mismo que usa «Radicar», así que un cliente solo ve las suyas: no hace
 * falta un endpoint nuevo ni exponer la lista completa de sedes del sistema.
 */
const sedes = ref([])

const pagina = ref(1)
const datos = ref(null)
const cargando = ref(true)

/**
 * Reimprimir la guía de un envío ya radicado.
 *
 * El papel se moja, se despega o se queda en la impresora del piso de arriba.
 * Que la única oportunidad de imprimirlo fuese el instante de radicar obligaba
 * a volver a radicar el envío, que es peor que cualquier otra cosa.
 *
 * La pestaña se abre ANTES de pedir el enlace: si se abriera después, el
 * navegador la tomaría por emergente no solicitada y la bloquearía, porque
 * para él el clic ya pasó.
 */
const imprimiendo = ref(null)

async function imprimirGuia(guia) {
    imprimiendo.value = guia
    const pestania = window.open('', '_blank')

    try {
        const r = await api.enlaceImpresion(guia)

        if (pestania) {
            pestania.location = r.url
        } else {
            window.location.assign(r.url)
        }
    } catch (e) {
        pestania?.close()
        error.value = `No se pudo preparar la guía ${guia}: ${e.message}`
    } finally {
        imprimiendo.value = null
    }
}
const error = ref(null)

let aborto = null

async function cargar() {
    cargando.value = true
    aborto?.abort()
    aborto = new AbortController()

    // Sin las claves vacías: `?estado=&desde=` ensucia la dirección y obliga al
    // servidor a distinguir «vacío» de «no vino».
    const q = Object.fromEntries(
        Object.entries({ ...filtros.value, pagina: pagina.value })
            .filter(([, v]) => v !== '' && v !== null)
    )

    try {
        datos.value = await api.ordenes(q, aborto.signal)
        error.value = null
    } catch (e) {
        if (e.name === 'AbortError') return

        error.value = e.cuerpo?.message ?? e.message
    } finally {
        cargando.value = false
    }
}

onMounted(async () => {
    cargar()

    // El catálogo no bloquea el listado: si falla, se pierde el desplegable de
    // sedes y nada más. Los envíos se siguen viendo.
    try {
        const cat = await api.catalogoRadicar()
        sedes.value = cat.clientes?.flatMap((c) => c.sedes ?? []) ?? []
    } catch {
        sedes.value = []
    }
})

// Cambiar un filtro vuelve a la primera página: quedarse en la 3 de un
// resultado que ahora tiene una sola página muestra una lista vacía y parece
// que no hay nada.
watch(filtros, () => { pagina.value = 1; cargar() }, { deep: true })
watch(pagina, cargar)

const ordenes = computed(() => datos.value?.data ?? [])

/**
 * El estado del envío sale del paquete, no de la orden.
 *
 * Una orden puede llevar varios paquetes; se muestra el menos avanzado, porque
 * un envío no está entregado hasta que lo está el último bulto.
 */
const ORDEN_AVANCE = [
    'creado', 'asignado', 'en_ruta_recoleccion', 'recolectado',
    'en_transito', 'en_sitio', 'entregado',
]

function estadoDe(orden) {
    const estados = (orden.paquetes ?? []).map((p) => p.estado)

    if (estados.length === 0) return null
    // Si TODAS están canceladas, el envío está cancelado. Si solo algunas, el
    // envío sigue vivo y manda lo que de verdad va en la calle.
    if (estados.every((e) => e === 'cancelado')) return 'cancelado'
    if (estados.includes('siniestro')) return 'siniestro'
    if (estados.includes('devuelto')) return 'devuelto'
    if (estados.includes('novedad')) return 'novedad'

    return estados.reduce((menos, e) =>
        ORDEN_AVANCE.indexOf(e) < ORDEN_AVANCE.indexOf(menos) ? e : menos, estados[0])
}

const ETIQUETAS = {
    creado:              ['Radicado',     'pastilla--neutral'],
    asignado:            ['Asignado',     'pastilla--neutral'],
    en_ruta_recoleccion: ['En recogida',  'pastilla--neutral'],
    recolectado:         ['Recolectado',  'pastilla--neutral'],
    en_transito:         ['En tránsito',  'pastilla--neutral'],
    en_sitio:            ['En sitio',     'pastilla--neutral'],
    entregado:           ['Entregado',    'pastilla--bien'],
    novedad:             ['Con novedad',  'pastilla--atencion'],
    reprogramado:        ['Reprogramado', 'pastilla--atencion'],
    devuelto:            ['Devuelto',     'pastilla--critico'],
    siniestro:           ['Siniestro',    'pastilla--critico'],
    cancelado:           ['Cancelada',    'pastilla--neutral'],
}

const etiqueta = (e) => ETIQUETAS[e] ?? ['En curso', 'pastilla--neutral']

function limpiar() {
    filtros.value = { estado: '', desde: '', hasta: '' }
}

/* ------------------------------------------------- cancelar una guía ----
 *
 * Solo mientras ninguna caja haya salido. Cancelada no es borrada: la guía
 * sigue en la lista, marcada, y sus eventos siguen en la bitácora. Es lo que
 * permite responder en marzo por qué un número de guía no existe en ningún
 * despacho.
 */
const puedeCancelar = computed(() => sesion.tiene('cliente', 'administrador', 'operador'))

function esCancelable(orden) {
    const estados = (orden.paquetes ?? []).map((p) => p.estado)

    return estados.length > 0 && estados.some((e) => e === 'creado')
}

/** Confirmación de lo último que se hizo, para no dejarlo en silencio. */
const aviso = ref(null)

const cancelando = ref(null)
const motivoCancelar = ref('')
const errorCancelar = ref(null)
const enviandoCancelar = ref(false)

function abrirCancelar(orden) {
    cancelando.value = orden
    motivoCancelar.value = ''
    errorCancelar.value = null
}

async function confirmarCancelar() {
    if (motivoCancelar.value.trim().length < 5) {
        errorCancelar.value = 'Escriba por qué se cancela. Queda registrado con la guía.'

        return
    }

    enviandoCancelar.value = true
    errorCancelar.value = null

    try {
        const r = await api.cancelarOrden(cancelando.value.numero_guia, motivoCancelar.value.trim())
        cancelando.value = null
        aviso.value = r.mensaje
        await cargar()
    } catch (e) {
        errorCancelar.value = e.cuerpo?.message ?? e.message
    } finally {
        enviandoCancelar.value = false
    }
}

const hayFiltro = computed(() =>
    Object.values(filtros.value).some((v) => v !== '' && v !== null))
</script>

<template>
    <section class="pagina">
        <Hero
            rotulo="Mis envíos"
            titulo="Solicitudes"
            descripcion="Todo lo que ha radicado, en trámite o cerrado. Busque por la guía Avalia o por su propia referencia, y reimprima cualquier guía cuando el papel se pierda."
            :cifras="datos ? [{ etiqueta: datos.total === 1 ? 'envío' : 'envíos', valor: datos.total }] : []"
        />

        <!-- Los filtros van en una sola fila arriba del listado: es donde se
             busca el control cuando la lista no muestra lo que se esperaba. -->
        <div class="filtros" role="group" aria-label="Filtros">
            <div class="grupos">
                <button
                    v-for="g in GRUPOS"
                    :key="g.clave"
                    type="button"
                    class="grupo"
                    :aria-pressed="filtros.estado === g.clave"
                    @click="filtros.estado = g.clave"
                >{{ g.texto }}</button>
            </div>

            <div class="fechas">
                <!--
                    Guía y referencia en una sola caja: el cliente consulta con
                    el número que tiene a mano, y unas veces es el nuestro y
                    otras el suyo. Elegir el campo antes de buscar es un paso
                    que puede hacer la consulta.
                -->
                <label class="f-campo f-campo--busca">
                    <span>Buscar</span>
                    <input
                        v-model="filtros.buscar"
                        type="search"
                        class="control"
                        placeholder="Guía, radicado, orden de compra…"
                    >
                </label>

                <label class="f-campo">
                    <span>Sede</span>
                    <select v-model="filtros.sede" class="control">
                        <option value="">Todas las sedes</option>
                        <option v-for="s in sedes" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                </label>

                <label class="f-campo">
                    <span>Desde</span>
                    <input v-model="filtros.desde" type="date" class="control">
                </label>
                <label class="f-campo">
                    <span>Hasta</span>
                    <input v-model="filtros.hasta" type="date" class="control">
                </label>
                <button v-if="hayFiltro" type="button" class="limpiar" @click="limpiar">
                    Quitar filtros
                </button>
            </div>
        </div>

        <p v-if="error" class="aviso aviso--malo">{{ error }}</p>
        <p v-else-if="cargando" class="aviso">Buscando sus envíos…</p>

        <!-- Vacío con filtro y vacío sin filtro son dos cosas distintas, y
             confundirlas hace creer que no hay envíos cuando solo es el
             filtro el que no encuentra nada. -->
        <p v-else-if="ordenes.length === 0 && hayFiltro" class="aviso">
            Ningún envío coincide con ese filtro.
            <button type="button" class="enlace" @click="limpiar">Quitar los filtros</button>
        </p>
        <p v-else-if="ordenes.length === 0" class="aviso">
            Todavía no tiene envíos radicados.
            <RouterLink class="enlace" to="/radicar">Radicar el primero</RouterLink>
        </p>

        <template v-else>
            <!--
                Encabezados de columna.

                La lista tenía el mismo reparto de rejilla que las filas pero
                sin decir qué era cada cosa: había que deducir que la fecha era
                la de radicación y no la de entrega. Se ocultan en móvil, donde
                la fila se apila y los encabezados dejarían de alinearse con
                nada.
            -->
            <div class="encabezados" aria-hidden="true">
                <span>Guía y referencia</span>
                <span>Ruta</span>
                <span>Radicado</span>
                <span>Estado</span>
                <span>Acciones</span>
            </div>

            <ul class="lista">
                <li v-for="o in ordenes" :key="o.id" class="fila">
                    <div class="f-guia">
                        <p class="guia num">{{ o.numero_guia }}</p>
                        <p v-if="o.referencias?.length" class="refs">
                            <span v-for="r in o.referencias" :key="r.valor">{{ r.valor }}</span>
                        </p>
                    </div>

                    <div class="f-ruta">
                        <span>{{ o.origen?.nombre ?? '—' }}</span>
                        <span class="flecha" aria-hidden="true">→</span>
                        <span>{{ o.destino?.nombre ?? o.contacto_destino_nombre ?? '—' }}</span>
                    </div>

                    <p class="f-fecha num">{{ fechaCorta(o.created_at) }}</p>

                    <span class="pastilla" :class="etiqueta(estadoDe(o))[1]">
                        {{ etiqueta(estadoDe(o))[0] }}
                    </span>

                    <div class="f-acciones">
                        <RouterLink class="accion" :to="`/rastreo?q=${encodeURIComponent(o.numero_guia)}`">
                            Soporte
                        </RouterLink>
                        <RouterLink class="accion" :to="`/envios/${encodeURIComponent(o.numero_guia)}/mapa`">
                            Recorrido
                        </RouterLink>
                        <button
                            type="button"
                            class="accion"
                            :disabled="imprimiendo === o.numero_guia"
                            @click="imprimirGuia(o.numero_guia)"
                        >
                            {{ imprimiendo === o.numero_guia ? '…' : 'Guía' }}
                        </button>

                        <!--
                            Solo aparece mientras se puede. Un botón que está
                            siempre y falla casi siempre enseña a no fiarse de
                            los botones.
                        -->
                        <button
                            v-if="puedeCancelar && esCancelable(o)"
                            type="button" class="accion accion--riesgo"
                            @click="abrirCancelar(o)"
                        >
                            Cancelar
                        </button>
                    </div>
                </li>
            </ul>

            <p v-if="aviso" class="aviso-hecho" role="status">
                {{ aviso }}
                <button type="button" class="aviso-cerrar" @click="aviso = null">Ocultar</button>
            </p>

            <!-- --------------------------------------- cancelar guía -->
            <div
                v-if="cancelando" class="fondo-dialogo" role="dialog" aria-modal="true"
                aria-label="Cancelar guía" @click.self="cancelando = null"
            >
                <div class="dialogo">
                    <h2 class="dialogo-tit">Cancelar {{ cancelando.numero_guia }}</h2>
                    <p class="dialogo-sub">
                        La guía no se borra: queda marcada como cancelada, con su motivo y su
                        fecha. Solo se cancelan las cajas que todavía no han salido.
                    </p>

                    <label class="dialogo-rotulo" for="motivo-cancelar">¿Por qué se cancela?</label>
                    <textarea
                        id="motivo-cancelar" v-model="motivoCancelar" class="dialogo-area" rows="3"
                        placeholder="Ej.: se radicó dos veces la misma dispensación."
                    />

                    <p v-if="errorCancelar" class="dialogo-error">{{ errorCancelar }}</p>

                    <div class="dialogo-pie">
                        <button type="button" class="accion" @click="cancelando = null">Volver</button>
                        <button
                            type="button" class="accion accion--riesgo"
                            :disabled="enviandoCancelar" @click="confirmarCancelar"
                        >{{ enviandoCancelar ? 'Cancelando…' : 'Cancelar la guía' }}</button>
                    </div>
                </div>
            </div>

            <nav v-if="datos.last_page > 1" class="paginas" aria-label="Páginas">
                <button type="button" :disabled="pagina <= 1" @click="pagina--">Anterior</button>
                <span class="num">{{ datos.current_page }} de {{ datos.last_page }}</span>
                <button type="button" :disabled="pagina >= datos.last_page" @click="pagina++">Siguiente</button>
            </nav>
        </template>
    </section>
</template>

<style scoped>
.pagina { display: flex; flex-direction: column; gap: 1rem; }

.accion--riesgo { color: var(--critico); border-color: var(--critico); }

.aviso-hecho {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .8rem;
    margin: 0;
    padding: .6rem .9rem;
    border-left: 3px solid var(--bien);
    border-radius: 0 8px 8px 0;
    background: var(--superficie-2);
    font-size: .85rem;
    line-height: 1.5;
    color: var(--ink-2);
}

.aviso-cerrar {
    border: 0;
    background: none;
    font: inherit;
    font-size: .8rem;
    color: var(--ink-3);
    text-decoration: underline;
    cursor: pointer;
}

.fondo-dialogo {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgb(0 0 0 / .45);
}

.dialogo {
    width: 100%;
    max-width: 480px;
    padding: 1.2rem;
    border-radius: 14px;
    background: var(--superficie);
    box-shadow: 0 16px 48px rgb(0 0 0 / .3);
}

.dialogo-tit { margin: 0; font-size: 1.05rem; }

.dialogo-sub {
    margin: .4rem 0 0;
    font-size: .85rem;
    line-height: 1.55;
    color: var(--ink-3);
}

.dialogo-rotulo {
    display: block;
    margin: .9rem 0 .35rem;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.dialogo-area {
    width: 100%;
    border: 1px solid var(--linea-fuerte);
    border-radius: 8px;
    padding: .55rem .7rem;
    font: inherit;
    font-size: .85rem;
    color: var(--ink);
    background: var(--superficie);
    resize: vertical;
}

.dialogo-error { margin: .5rem 0 0; font-size: .8rem; color: var(--critico); }

.dialogo-pie {
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
    margin-top: 1rem;
}

.cab { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.cab h1 { margin: .15rem 0 0; font-size: 1.5rem; }
.rotulo { margin: 0; font-size: .74rem; color: var(--ink-3); }
.conteo { margin: 0; font-size: .84rem; color: var(--ink-3); font-variant-numeric: tabular-nums; }

.filtros {
    display: flex; flex-wrap: wrap; gap: .9rem 1.4rem;
    align-items: flex-end; justify-content: space-between;
    padding: .85rem 1rem;
    background: var(--superficie); border: 1px solid var(--linea);
    border-radius: var(--r-lg);
}

.grupos { display: inline-flex; padding: 3px; gap: 2px; background: var(--superficie-2); border-radius: 9px; }

.grupo {
    border: 1px solid transparent; background: transparent; border-radius: 6px;
    padding: .34rem .8rem; font-size: .8rem; font-weight: 500; color: var(--ink-3);
    transition: color .14s ease, background .14s ease;
}
.grupo:hover:not([aria-pressed="true"]) { color: var(--ink); }
.grupo[aria-pressed="true"] {
    background: var(--superficie); border-color: var(--linea);
    color: var(--acento-ink); font-weight: 600;
    box-shadow: 0 1px 2px rgba(13, 19, 51, .07);
}

.fechas { display: flex; align-items: flex-end; gap: .7rem; flex-wrap: wrap; }
.f-campo { display: flex; flex-direction: column; gap: .2rem; font-size: .72rem; color: var(--ink-3); }

.limpiar, .enlace {
    background: none; border: none; padding: 0;
    color: var(--acento-ink); font-size: .8rem; font-weight: 500;
    text-decoration: underline; cursor: pointer;
}

.aviso { margin: 0; padding: 1.1rem; border-radius: var(--r-md); background: var(--superficie-2); color: var(--ink-2); }
.aviso--malo { background: var(--critico-suave); color: var(--critico-ink); }

.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }

/* Mismo reparto que .fila, o los encabezados no caerían sobre su columna. */
.encabezados {
    display: grid;
    grid-template-columns: minmax(150px, 1fr) minmax(180px, 1.6fr) auto auto auto;
    align-items: center; gap: 1rem;
    padding: 0 .95rem .45rem;
    font-family: var(--mono);
    font-size: .64rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.fila {
    display: grid;
    grid-template-columns: minmax(150px, 1fr) minmax(180px, 1.6fr) auto auto auto;
    align-items: center; gap: 1rem;
    padding: .7rem .95rem;
    background: var(--superficie); border: 1px solid var(--linea);
    border-radius: var(--r-md);
    transition: border-color .14s ease;
}
.fila:hover { border-color: var(--linea-fuerte); }

.guia { margin: 0; font-weight: 600; font-size: .92rem; font-variant-numeric: tabular-nums; }
.refs { margin: .15rem 0 0; display: flex; gap: .45rem; flex-wrap: wrap; font-size: .72rem; color: var(--ink-3); }

.f-ruta { display: flex; align-items: center; gap: .45rem; font-size: .84rem; color: var(--ink-2); min-width: 0; }
.f-ruta span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.flecha { color: var(--ink-3); flex: none; }

.f-fecha { font-size: .8rem; color: var(--ink-3); white-space: nowrap; }

.f-acciones { display: flex; gap: .4rem; }

/* La caja de busqueda se queda con el espacio sobrante: ahi se escriben
   numeros de guia completos, no dos digitos. */
.f-campo--busca { flex: 1 1 15rem; min-width: 12rem; }
.accion {
    border: 1px solid var(--linea-fuerte); background: var(--superficie);
    border-radius: var(--r-sm); padding: .28rem .6rem;
    font-size: .74rem; color: var(--acento-ink); text-decoration: none; white-space: nowrap;
}
.accion:hover { background: var(--acento-suave); }

/* El de «Guía» es un <button> y no un enlace, porque antes de abrir hay que
   pedir el enlace firmado al servidor. Hereda el tipo de letra, que el
   navegador no le da por su cuenta. */
button.accion { font-family: inherit; cursor: pointer; }
button.accion:disabled { opacity: .55; cursor: default; }

.paginas { display: flex; align-items: center; justify-content: center; gap: 1rem; padding: .5rem 0; }
.paginas button {
    border: 1px solid var(--linea-fuerte); background: var(--superficie);
    border-radius: var(--r-sm); padding: .35rem .85rem; font-size: .8rem;
}
.paginas button:disabled { opacity: .4; }
.paginas .num { font-size: .82rem; color: var(--ink-3); font-variant-numeric: tabular-nums; }

@media (max-width: 860px) {
    /* La fila se apila: unos encabezados fijos dejarian de corresponder con
       nada y estorbarian mas de lo que explican. */
    .encabezados { display: none; }
    .fila { grid-template-columns: 1fr auto; gap: .5rem .8rem; }
    .f-ruta, .f-fecha { grid-column: 1 / -1; }
}
</style>
