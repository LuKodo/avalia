<script setup>
import PerfilTermicoGrafico from '../graficos/PerfilTermico.vue'
import LineaTiempo from '../componentes/LineaTiempo.vue'
import Hero from '../componentes/Hero.vue'
import EscanerQr from '../componentes/EscanerQr.vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api/cliente'
import { hora, numero } from '../graficos/util'

/**
 * Consulta de envíos.
 *
 * Una sola caja de búsqueda que acepta las dos llaves del cliente: la guía Avalia
 * y la referencia que él definió al radicar —su radicado, su orden de compra,
 * su remisión—. Obligarlo a elegir cuál está tecleando sería trasladarle un
 * problema nuestro: en el momento en que alguien de su equipo pregunta por un
 * envío, lo que tiene a mano es su propio número, no el nuestro.
 *
 * El resultado no es un estado suelto: es el soporte de entrega completo, que
 * es lo que el cliente necesita para cerrar su proceso interno.
 */
const props = defineProps({
    token: { type: String, default: null },
})

const ruta = useRoute()
const consulta = ref('')
const modoLote = ref(false)
const lote = ref('')
const resultados = ref([])
const sinResultado = ref([])
const buscando = ref(false)
const error = ref(null)
const buscadoComo = ref(null)
const expandido = ref(null)

/**
 * Dos formas de llegar aquí con la respuesta ya puesta.
 *
 * 1. Rastreo público: el servidor incrusta los datos en la página, porque
 *    quien entra por un enlace compartido no tiene sesión ni tiene por qué
 *    escribir nada.
 *
 * 2. Con `?q=` en la dirección: así llegan los botones de «Soporte» desde el
 *    listado de solicitudes. Antes el número viajaba en la URL y la pantalla
 *    lo ignoraba: el cliente lo veía escrito arriba y aun así tenía que
 *    teclearlo. Ahora se busca solo.
 */
onMounted(() => {
    const nodo = document.getElementById('datos-rastreo')

    if (nodo) {
        resultados.value = [JSON.parse(nodo.textContent)]
        buscadoComo.value = 'enlace'
        expandido.value = 0

        return
    }

    const pedido = String(ruta.query.q ?? '').trim()

    if (pedido) {
        consulta.value = pedido
        buscar()
    }
})

/**
 * Lo que trae el QR de la etiqueta y lo que entiende esta pantalla no son lo
 * mismo.
 *
 * El QR lleva el código del PAQUETE —`AVL-261003-0002-P01`— porque identifica
 * una caja concreta, que es lo que el mensajero escanea. La consulta, en
 * cambio, trabaja por envío: la guía es `AVL-261003-0002`. El sufijo se quita
 * aquí, al borde, y no en el servidor: es una particularidad de cómo rotulamos
 * las cajas, no de cómo se consultan los envíos.
 */
function alEscanear(codigo) {
    consulta.value = String(codigo).trim().replace(/-P\d+$/i, '')
    buscar()
}

async function buscar() {
    if (!consulta.value.trim()) return

    buscando.value = true
    error.value = null
    sinResultado.value = []

    try {
        const r = await api.rastrear(consulta.value.trim())
        resultados.value = r.envios ?? []
        buscadoComo.value = r.buscado_como
        expandido.value = resultados.value.length === 1 ? 0 : null
    } catch (e) {
        resultados.value = []
        error.value = e.estado === 404
            ? 'No hay envíos con esa guía ni con esa referencia. Verifique el dato o pruebe con el código de la etiqueta del paquete.'
            : e.message
    } finally {
        buscando.value = false
    }
}

async function buscarLote() {
    const claves = lote.value
        .split(/[\n,;\t]+/)
        .map((s) => s.trim())
        .filter(Boolean)

    if (!claves.length) return

    buscando.value = true
    error.value = null

    try {
        const r = await api.rastrearLote(claves, true)
        resultados.value = r.envios ?? []
        sinResultado.value = r.sin_resultado ?? []
        buscadoComo.value = 'lote'
        expandido.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        buscando.value = false
    }
}

const urlCsv = computed(() => {
    const guias = resultados.value.map((r) => r.envio?.guia_avalia).filter(Boolean)
    if (!guias.length) return null

    const p = new URLSearchParams({ formato: 'csv' })
    guias.forEach((g) => p.append('guias[]', g))

    return `/api/v1/envios/exportar?${p}`
})

function etiquetaEstado(estado) {
    return {
        entregado: ['✓ Entregado', 'pastilla--bien'],
        entregado_parcial: ['◐ Entregado parcial', 'pastilla--atencion'],
        novedad: ['△ Con novedad', 'pastilla--atencion'],
        devuelto: ['↩ Devuelto', 'pastilla--grave'],
        siniestro: ['✕ Siniestro', 'pastilla--critico'],
    }[estado] ?? ['En curso', 'pastilla--neutral']
}

function fechaHora(iso) {
    if (!iso) return '—'
    const d = new Date(iso)
    return `${d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })} · ${hora(iso)}`
}

function pesoLegible(bytes) {
    if (!bytes) return '—'
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1048576) return `${Math.round(bytes / 1024)} KB`

    return `${(bytes / 1048576).toFixed(1)} MB`
}

/** Visor a tamaño completo. */
const visor = ref(null)

function verEvidencia(ev, codigo) {
    visor.value = { ...ev, codigo }
}

function cerrarVisor() {
    visor.value = null
}

function alTeclear(e) {
    if (e.key === 'Escape') cerrarVisor()
}

onMounted(() => window.addEventListener('keydown', alTeclear))
onBeforeUnmount(() => window.removeEventListener('keydown', alTeclear))
</script>

<template>
    <div class="pagina">
        <Hero
            v-if="!token"
            rotulo="Consulta"
            titulo="Rastrear un envío"
            descripcion="Busque por la guía Avalia, por su propia referencia —radicado, orden de compra, remisión— o por el código de la etiqueta del paquete."
        />

        <section v-if="!token" class="buscador tarjeta">
            <div class="modo">
                <button type="button" :aria-pressed="!modoLote" @click="modoLote = false">Una consulta</button>
                <button type="button" :aria-pressed="modoLote" @click="modoLote = true">Varias a la vez</button>
            </div>

            <form v-if="!modoLote" class="forma" @submit.prevent="buscar">
                <label class="solo-lector" for="q">Guía Avalia o referencia</label>
                <input
                    id="q"
                    v-model="consulta"
                    type="search"
                    class="campo"
                    placeholder="AVL-260821-0417  ·  OC-4471  ·  RAD-90233"
                    autocomplete="off"
                    spellcheck="false"
                >
                <button type="submit" class="btn btn--pri" :disabled="buscando">
                    {{ buscando ? 'Buscando…' : 'Buscar' }}
                </button>
            </form>

            <!--
                Quien pregunta por un envío muchas veces tiene la caja delante.
                Teclear quince caracteres de una guía mirando una etiqueta es
                trabajo que la cámara hace mejor y sin erratas.
            -->
            <div v-if="!modoLote" class="escanear">
                <EscanerQr @leido="alEscanear" />
            </div>

            <form v-else class="forma forma--lote" @submit.prevent="buscarLote">
                <label for="lote" class="etiqueta">
                    Pegue su lista de referencias o guías — una por línea, o separadas por coma
                </label>
                <textarea
                    id="lote"
                    v-model="lote"
                    class="campo campo--area"
                    rows="5"
                    placeholder="OC-4471&#10;OC-4472&#10;AVL-260821-0417"
                    spellcheck="false"
                />
                <button type="submit" class="btn btn--pri" :disabled="buscando">
                    {{ buscando ? 'Consultando…' : 'Consultar lote' }}
                </button>
            </form>
        </section>

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>

        <div v-if="resultados.length" class="cabecera-resultados">
            <p class="conteo">
                {{ numero(resultados.length) }}
                {{ resultados.length === 1 ? 'envío encontrado' : 'envíos encontrados' }}
                <span v-if="buscadoComo === 'referencia'" class="pastilla pastilla--neutral">por su referencia</span>
                <span v-else-if="buscadoComo === 'guia'" class="pastilla pastilla--neutral">por guía Avalia</span>
                <span v-else-if="buscadoComo === 'paquete'" class="pastilla pastilla--neutral">por código de etiqueta</span>
            </p>

            <a v-if="urlCsv" :href="urlCsv" class="btn btn--sec">⤓ Exportar CSV con soporte</a>
        </div>

        <p v-if="sinResultado.length" class="aviso aviso--parcial">
            <strong>{{ sinResultado.length }} sin resultado:</strong>
            <span class="num">{{ sinResultado.join(' · ') }}</span>
            <br>
            Estas referencias no corresponden a ningún envío radicado en Avalia.
        </p>

        <article
            v-for="(r, i) in resultados"
            :key="r.envio.guia_avalia"
            class="envio tarjeta"
        >
            <header class="envio-cab" @click="expandido = expandido === i ? null : i">
                <div class="envio-id">
                    <p class="guia num">{{ r.envio.guia_avalia }}</p>
                    <p v-if="r.referencias_cliente?.length" class="refs">
                        <span v-for="ref in r.referencias_cliente" :key="ref.valor" class="ref">
                            {{ ref.etiqueta }}: <strong>{{ ref.valor }}</strong>
                        </span>
                    </p>
                </div>

                <div class="envio-estado">
                    <span class="pastilla" :class="etiquetaEstado(r.envio.estado)[1]">
                        {{ etiquetaEstado(r.envio.estado)[0] }}
                    </span>
                    <span v-if="r.envio.dentro_de_ventana === false" class="pastilla pastilla--atencion">
                        △ Fuera de ventana
                    </span>
                    <!-- `.stop`: la cabecera entera despliega el soporte al
                         pulsarla, y sin esto ir al mapa además desplegaría el
                         panel que se deja atrás. -->
                    <RouterLink
                        class="ver-mapa"
                        :to="`/envios/${encodeURIComponent(r.envio.guia_avalia)}/mapa`"
                        @click.stop
                    >
                        Ver recorrido
                    </RouterLink>
                    <button type="button" class="expandir" :aria-expanded="expandido === i">
                        {{ expandido === i ? 'Ocultar' : 'Ver soporte' }}
                    </button>
                </div>
            </header>

            <div class="ruta">
                <span>{{ r.origen?.nombre }}</span>
                <span class="flecha" aria-hidden="true">→</span>
                <span>{{ r.destino?.nombre }}</span>
                <span class="ciudad">{{ r.destino?.ciudad }}</span>
            </div>

            <div v-if="expandido === i" class="detalle">
                <div v-for="p in r.paquetes" :key="p.codigo" class="paquete">
                    <div class="paquete-cab">
                        <p class="codigo num">{{ p.codigo }}</p>
                        <span class="pastilla pastilla--neutral">{{ p.tipo_etiqueta }}</span>
                        <span class="paquete-cons">{{ p.consecutivo }}</span>
                    </div>

                    <!-- El bloque que el cliente vino a buscar -->
                    <dl v-if="p.entrega" class="soporte">
                        <div><dt>Fecha de entrega</dt><dd class="num">{{ p.entrega.fecha }}</dd></div>
                        <div><dt>Hora</dt><dd class="num">{{ p.entrega.hora }}</dd></div>
                        <div><dt>Recibido por</dt><dd>{{ p.entrega.recibido_por.nombre }}</dd></div>
                        <div><dt>Documento</dt><dd class="num">{{ p.entrega.recibido_por.documento }}</dd></div>
                        <div v-if="p.entrega.recibido_por.cargo">
                            <dt>Cargo</dt><dd>{{ p.entrega.recibido_por.cargo }}</dd>
                        </div>
                        <div v-if="p.entrega.mensajero">
                            <dt>Entregado por</dt>
                            <dd>{{ p.entrega.mensajero.nombre }} · {{ p.entrega.mensajero.vehiculo }}</dd>
                        </div>
                        <!--
                            Tres casos, no dos. Antes se pintaba «✓ a 0 m del
                            destino» cuando no había coordenada: el soporte
                            afirmaba una verificación que nunca ocurrió, y es
                            justo el dato que se esgrime cuando alguien discute
                            dónde se hizo la entrega. No medido y medido-y-
                            correcto no pueden verse igual.
                        -->
                        <div>
                            <dt>Ubicación</dt>
                            <dd v-if="p.entrega.ubicacion.latitud === null">
                                <span class="tenue">Sin coordenada</span>
                                <span class="pastilla pastilla--neutral">
                                    el dispositivo no entregó ubicación
                                </span>
                            </dd>
                            <dd v-else>
                                <span class="num">{{ p.entrega.ubicacion.latitud }}, {{ p.entrega.ubicacion.longitud }}</span>
                                <span
                                    class="pastilla"
                                    :class="p.entrega.ubicacion.coherente ? 'pastilla--bien' : 'pastilla--atencion'"
                                >
                                    {{ p.entrega.ubicacion.coherente
                                        ? `✓ a ${p.entrega.ubicacion.distancia_a_sede_m ?? 0} m del destino`
                                        : '△ fuera del radio esperado' }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <p v-else class="pendiente">Este paquete todavía no se ha entregado.</p>

                    <div v-if="p.evidencias?.length" class="evidencias">
                        <p class="etiqueta">
                            Evidencia capturada · {{ p.evidencias.length }} archivo(s)
                        </p>

                        <!--
                            Se muestran las imágenes, no una lista de enlaces. Quien
                            revisa un soporte quiere VER quién recibió y cómo firmó;
                            obligarlo a abrir cada archivo en una pestaña nueva
                            convierte la revisión en un trámite.
                        -->
                        <ul class="galeria">
                            <li v-for="ev in p.evidencias" :key="ev.tipo">
                                <button
                                    v-if="ev.es_imagen"
                                    type="button" class="miniatura"
                                    @click="verEvidencia(ev, p.codigo)"
                                >
                                    <img :src="ev.enlace" :alt="`${ev.etiqueta} del envío ${p.codigo}`" loading="lazy">
                                    <span class="mini-pie">
                                        <span class="mini-tit">{{ ev.etiqueta }}</span>
                                        <span class="mini-meta num">{{ pesoLegible(ev.bytes) }}</span>
                                        <!--
                                            Los DOS estados, no solo el bueno. Si solo se
                                            marcara lo enviado, el silencio se leería como
                                            «todo bien» cuando significa lo contrario.
                                            Para un cliente sin integración no sale nada:
                                            ahí sí no hay nada que esperar.
                                        -->
                                        <span
                                            v-if="ev.integracion"
                                            class="mini-acuse"
                                            :class="ev.integracion.enviada ? 'mini-acuse--si' : 'mini-acuse--no'"
                                            :title="ev.integracion.enviada
                                                ? `Enviado como ${ev.integracion.nombre}`
                                                : `${ev.integracion.intentos} intento(s) sin acuse`"
                                        >
                                            {{ ev.integracion.enviada ? '✓ en su repositorio' : '○ sin enviar a su repositorio' }}
                                        </span>
                                    </span>
                                </button>

                                <a v-else :href="ev.enlace" target="_blank" rel="noopener" class="miniatura miniatura--doc">
                                    <span class="doc-icono" aria-hidden="true">📄</span>
                                    <span class="mini-pie">
                                        <span class="mini-tit">{{ ev.etiqueta }}</span>
                                        <span class="mini-meta num">{{ pesoLegible(ev.bytes) }} · abrir</span>
                                    </span>
                                </a>
                            </li>
                        </ul>

                        <p class="evidencias-nota">
                            Cada archivo guarda su huella SHA-256: si cambiara después de
                            capturado, dejaría de coincidir. Los enlaces caducan a los 30 minutos.
                        </p>
                    </div>

                    <div v-if="p.cadena_frio" class="frio">
                        <p class="etiqueta">
                            Cadena de frío · rango exigido
                            {{ p.cadena_frio.rango_exigido.min }} – {{ p.cadena_frio.rango_exigido.max }} °C
                        </p>

                        <!--
                            El dictamen va primero y en grande porque es la única
                            línea que el químico farmacéutico necesita leer para
                            decidir. Los números que lo sustentan quedan debajo,
                            a la vista de quien quiera auditar el criterio.
                        -->
                        <div v-if="p.cadena_frio.analisis" class="dictamen" :class="`dictamen--${p.cadena_frio.analisis.dictamen.semaforo}`">
                            <p class="dictamen-titulo">
                                <span class="dictamen-icono" aria-hidden="true">{{
                                    { good: '✓', warning: '△', critical: '✕' }[p.cadena_frio.analisis.dictamen.semaforo]
                                }}</span>
                                {{ p.cadena_frio.analisis.dictamen.etiqueta }}
                            </p>
                            <ul class="dictamen-motivos">
                                <li v-for="(m, k) in p.cadena_frio.analisis.dictamen.motivos" :key="k">{{ m }}</li>
                            </ul>
                        </div>

                        <div v-if="p.cadena_frio.analisis" class="metricas">
                            <div class="metrica">
                                <span class="metrica-rotulo">MKT del trayecto</span>
                                <strong
                                    class="metrica-valor num"
                                    :class="{ fuera: p.cadena_frio.analisis.mkt && !p.cadena_frio.analisis.mkt.en_rango }"
                                >{{ p.cadena_frio.analisis.mkt ? `${p.cadena_frio.analisis.mkt.valor_c} °C` : '—' }}</strong>
                                <span class="metrica-pie">Temperatura cinética media</span>
                            </div>

                            <div class="metrica">
                                <span class="metrica-rotulo">Fuera de rango</span>
                                <strong class="metrica-valor num" :class="{ fuera: p.cadena_frio.analisis.tiempo_fuera_de_rango.total_min > 0 }">
                                    {{ p.cadena_frio.analisis.tiempo_fuera_de_rango.total_min }} min
                                </strong>
                                <span class="metrica-pie">
                                    <template v-if="p.cadena_frio.analisis.tiempo_fuera_de_rango.hubo_congelacion">
                                        {{ p.cadena_frio.analisis.tiempo_fuera_de_rango.frio_min }} min bajo el mínimo
                                    </template>
                                    <template v-else>
                                        {{ p.cadena_frio.analisis.tiempo_fuera_de_rango.calor_min }} min sobre el máximo
                                    </template>
                                </span>
                            </div>

                            <div v-if="p.cadena_frio.analisis.presupuesto_estabilidad" class="metrica metrica--ancha">
                                <span class="metrica-rotulo">Presupuesto de estabilidad</span>
                                <strong class="metrica-valor num">
                                    {{ p.cadena_frio.analisis.presupuesto_estabilidad.consumido_pct }} %
                                </strong>
                                <!--
                                    La barra encoge o crece, nunca desborda: el
                                    ancho sale de un porcentaje ya acotado a 100
                                    en el servidor.
                                -->
                                <div class="barra" role="img"
                                     :aria-label="`${p.cadena_frio.analisis.presupuesto_estabilidad.consumidos_min} de ${p.cadena_frio.analisis.presupuesto_estabilidad.minutos} minutos consumidos`">
                                    <span
                                        class="barra-relleno"
                                        :class="{ 'barra-relleno--alto': p.cadena_frio.analisis.presupuesto_estabilidad.consumido_pct > 60 }"
                                        :style="{ width: `${p.cadena_frio.analisis.presupuesto_estabilidad.consumido_pct}%` }"
                                    />
                                </div>
                                <span class="metrica-pie">
                                    {{ p.cadena_frio.analisis.presupuesto_estabilidad.consumidos_min }} de
                                    {{ p.cadena_frio.analisis.presupuesto_estabilidad.minutos }} min ·
                                    quedan {{ p.cadena_frio.analisis.presupuesto_estabilidad.restante_min }}
                                </span>
                            </div>
                        </div>

                        <!--
                            Con dos lecturas la lista vertical se leía bien; con
                            telemetría continua se volvía una columna de treinta
                            números donde un pico de 11 °C pasaba tan inadvertido
                            como si no estuviera. El umbral es bajo a propósito:
                            desde cuatro lecturas la forma de la curva dice más
                            que los valores sueltos. La tabla completa sigue a un
                            clic, dentro del propio gráfico.
                        -->
                        <PerfilTermicoGrafico
                            v-if="p.cadena_frio.lecturas.length >= 4"
                            :lecturas="p.cadena_frio.lecturas"
                            :rango="p.cadena_frio.rango_exigido"
                            :mkt="p.cadena_frio.analisis?.mkt?.valor_c ?? null"
                        />
                        <ul v-else class="lecturas">
                            <li v-for="(l, k) in p.cadena_frio.lecturas" :key="k">
                                <span>{{ l.etiqueta }}</span>
                                <strong class="num" :class="{ fuera: l.fuera_de_rango }">{{ l.valor_c }} °C</strong>
                            </li>
                        </ul>

                        <!--
                            La cobertura del muestreo se dice siempre. Un MKT
                            sobre dos lecturas y uno sobre un registro continuo
                            no valen lo mismo, y presentarlos con la misma cara
                            sería engañar al que firma con base en esto.
                        -->
                        <p v-if="p.cadena_frio.analisis?.cobertura" class="cobertura">
                            {{ {
                                continua: 'Registro continuo',
                                periodica: 'Medición periódica',
                                puntual: 'Medición puntual',
                                sin_datos: 'Sin registro',
                            }[p.cadena_frio.analisis.cobertura.calidad] }}
                            · {{ p.cadena_frio.analisis.cobertura.lecturas }} lectura(s)
                            <template v-if="p.cadena_frio.analisis.cobertura.intervalo_medio_min">
                                cada {{ p.cadena_frio.analisis.cobertura.intervalo_medio_min }} min
                            </template>
                        </p>

                        <p v-if="p.cadena_frio.analisis" class="descargo">
                            {{ p.cadena_frio.analisis.dictamen.descargo }}
                        </p>
                    </div>

                    <!--
                        El seguimiento visual va primero porque responde la
                        pregunta con la que la gente llega: donde esta y cuando
                        llega. La lista de eventos con sus hashes sigue debajo,
                        plegada, para quien viene a auditar y no a consultar.
                    -->
                    <LineaTiempo
                        v-if="p.trazabilidad?.length"
                        class="seguimiento-bloque"
                        :eventos="p.trazabilidad"
                        :estado="p.estado"
                        :estado-etiqueta="p.estado_etiqueta"
                        :comprometida-en="r.envio?.comprometida_en"
                        :novedades="p.novedades ?? []"
                    />

                    <details v-if="p.trazabilidad?.length" class="traza">
                        <summary>Trazabilidad completa ({{ p.trazabilidad.length }} eventos)</summary>
                        <ol>
                            <li v-for="ev in p.trazabilidad" :key="ev.secuencia">
                                <span class="traza-est">{{ ev.etiqueta }}</span>
                                <span class="traza-fecha num">{{ ev.fecha }} {{ ev.hora }}</span>
                                <span v-if="ev.actor" class="traza-actor">{{ ev.actor }}</span>
                                <span class="traza-hash num" :title="ev.hash">{{ ev.hash?.slice(0, 10) }}…</span>
                            </li>
                        </ol>
                    </details>
                </div>

                <p v-if="r.verificacion?.verificable" class="verificacion">
                    <span
                        class="pastilla"
                        :class="r.verificacion.cadena_integra ? 'pastilla--bien' : 'pastilla--critico'"
                    >
                        {{ r.verificacion.cadena_integra ? '✓ Cadena íntegra' : '✕ Cadena comprometida' }}
                    </span>
                    {{ r.verificacion.eventos_revisados }} eventos verificados con
                    {{ r.verificacion.algoritmo?.toUpperCase() }}. Los hashes encadenados permiten
                    comprobar que ningún evento se modificó después de registrado.
                </p>
            </div>
        </article>
        <!-- Visor a tamano completo -->
        <div v-if="visor" class="visor" role="dialog" aria-modal="true" :aria-label="visor.etiqueta" @click.self="cerrarVisor">
            <div class="visor-caja">
                <header class="visor-cab">
                    <div>
                        <p class="visor-tit">{{ visor.etiqueta }}</p>
                        <p class="visor-sub num">{{ visor.codigo }} · {{ pesoLegible(visor.bytes) }}</p>
                    </div>
                    <button type="button" class="visor-cerrar" aria-label="Cerrar" @click="cerrarVisor">✕</button>
                </header>

                <img :src="visor.enlace" :alt="visor.etiqueta" class="visor-img">

                <footer class="visor-pie">
                    <p class="visor-hash num" :title="visor.checksum">SHA-256 {{ visor.checksum }}</p>
                    <a :href="visor.enlace" target="_blank" rel="noopener" class="visor-abrir">Abrir aparte</a>
                </footer>
            </div>
        </div>
    </div>
</template>

<style scoped>
.mini-acuse { display: block; margin-top: .15rem; font-size: .68rem; font-weight: 600; }
.mini-acuse--si { color: var(--bien, #1b7a46); }
.mini-acuse--no { color: var(--ink-3); }

/* El escaneo es una alternativa al campo, no un paso previo: va debajo y con
   menos peso visual, para que quien prefiera teclear no sienta que se lo
   imponen. */
.escanear { margin-top: .9rem; }

.pagina {
    max-width: 940px;
    margin: 0 auto;
    padding: 1.2rem 1rem 3rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.titulo { font-size: 1.35rem; font-weight: 600; letter-spacing: -.025em; margin: 0; }
.sub { font-size: .88rem; color: var(--ink-2); margin: .3rem 0 0; max-width: 62ch; }

.buscador { padding: .9rem; }

.modo { display: flex; gap: .25rem; margin-bottom: .8rem; }

.modo button {
    border: 1px solid var(--linea-fuerte);
    background: var(--superficie);
    border-radius: var(--r-sm);
    padding: .3rem .7rem;
    font-size: .78rem;
    font-weight: 500;
    color: var(--ink-2);
}

.modo button[aria-pressed="true"] {
    background: var(--acento-suave);
    border-color: var(--acento);
    color: var(--acento-ink);
}

.forma { display: flex; gap: .5rem; flex-wrap: wrap; }
.forma--lote { flex-direction: column; align-items: flex-start; }

.etiqueta {
    font-family: var(--mono);
    font-size: .64rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.campo {
    flex: 1 1 260px;
    min-height: var(--toque);
    border: 1px solid var(--linea-fuerte);
    border-radius: var(--r-sm);
    background: var(--superficie);
    padding: .6rem .8rem;
    font-size: .95rem;
    font-family: var(--mono);
}

.campo--area { width: 100%; min-height: 100px; resize: vertical; }

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    min-height: var(--toque);
    padding: .55rem 1.1rem;
    border-radius: var(--r-sm);
    font-size: .88rem;
    font-weight: 600;
    border: 1px solid transparent;
    text-decoration: none;
}

.btn--pri { background: var(--acento); color: var(--sobre-acento); }
.btn--pri:disabled { opacity: .55; cursor: progress; }
.btn--sec { background: var(--superficie); border-color: var(--linea-fuerte); color: var(--ink); }

.cabecera-resultados {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem 1rem;
    align-items: center;
    justify-content: space-between;
}

.conteo { margin: 0; font-size: .88rem; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }

.envio { display: flex; flex-direction: column; }

.envio-cab {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem 1rem;
    align-items: center;
    justify-content: space-between;
    padding: .8rem .9rem;
    cursor: pointer;
}

.guia { font-size: 1rem; font-weight: 600; margin: 0; letter-spacing: .01em; }

.refs { margin: .2rem 0 0; display: flex; flex-wrap: wrap; gap: .2rem .8rem; font-size: .76rem; color: var(--ink-3); }
.ref strong { color: var(--ink-2); font-weight: 600; }

.envio-estado { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; }

.expandir,
.ver-mapa {
    border: 1px solid var(--linea-fuerte);
    background: var(--superficie);
    border-radius: var(--r-sm);
    padding: .3rem .7rem;
    font-size: .76rem;
    font-weight: 500;
}

.ver-mapa {
    color: var(--acento-ink);
    text-decoration: none;
    white-space: nowrap;
}

.ver-mapa:hover { background: var(--acento-suave); }

.ruta {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    align-items: center;
    padding: 0 .9rem .8rem;
    font-size: .82rem;
    color: var(--ink-2);
}

.flecha { color: var(--ink-3); }
.ciudad { color: var(--ink-3); font-size: .76rem; }

.detalle { border-top: 1px solid var(--linea); background: var(--superficie-2); padding: .9rem; }

.paquete {
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-radius: var(--r-sm);
    padding: .8rem;
    margin-bottom: .7rem;
}

.paquete-cab { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-bottom: .7rem; }
.codigo { margin: 0; font-weight: 600; font-size: .86rem; }
.paquete-cons { font-size: .72rem; color: var(--ink-3); }

.soporte {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: .6rem .9rem;
    margin: 0;
}

.soporte dt {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-bottom: .15rem;
}

.soporte dd { margin: 0; font-size: .85rem; display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; }

.pendiente { font-size: .82rem; color: var(--ink-3); margin: 0; }
.seguimiento-bloque { margin: .9rem 0; padding-top: .8rem; border-top: 1px solid var(--linea); }
.tenue { color: var(--ink-3); }

.evidencias, .frio { margin-top: .8rem; padding-top: .7rem; border-top: 1px solid var(--linea); }
.evidencias ul { list-style: none; margin: .35rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: .3rem .9rem; }
.evidencias li { display: flex; align-items: center; gap: .4rem; font-size: .8rem; }
.checksum { font-size: .66rem; color: var(--ink-3); }

.lecturas { list-style: none; margin: .4rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: .3rem 1.2rem; font-size: .82rem; }
.lecturas li { display: flex; gap: .4rem; align-items: baseline; }
.lecturas strong { color: var(--ink); }
.lecturas strong.fuera { color: var(--critico); }

/* --- dictamen de aptitud ------------------------------------------------
   Los colores salen de los tokens de estado reservados, nunca de la paleta
   categórica: esto es un semáforo, no una serie de datos. El icono acompaña
   siempre al color para que el estado no dependa solo del matiz. */
.dictamen {
    display: flex; flex-direction: column; gap: .3rem;
    margin: .55rem 0 .7rem;
    padding: .6rem .75rem;
    border-radius: 10px;
    border-left: 3px solid currentColor;
}
.dictamen--good     { background: var(--bien-suave);     color: var(--bien); }
.dictamen--warning  { background: var(--atencion-suave); color: var(--grave); }
.dictamen--critical { background: var(--critico-suave);  color: var(--critico); }

.dictamen-titulo {
    display: flex; align-items: center; gap: .4rem;
    margin: 0; font-size: .88rem; font-weight: 650; letter-spacing: -.01em;
}
.dictamen-icono { font-size: .95rem; line-height: 1; }

/* El texto explicativo vuelve a tinta normal: el color del semáforo es del
   veredicto, y teñir además los motivos los vuelve difíciles de leer. */
.dictamen-motivos {
    list-style: none; margin: 0; padding: 0;
    display: flex; flex-direction: column; gap: .18rem;
    font-size: .74rem; line-height: 1.45; color: var(--ink-2);
}

/* --- métricas ----------------------------------------------------------- */
/* Dos columnas fijas, no auto-fit: con tres metricas el auto-fit resolvia
   cinco pistas y dejaba las dos primeras encogidas contra el borde izquierdo
   con un hueco muerto al lado. Abajo de 420 px se apilan. */
.metricas {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .5rem;
    margin-bottom: .7rem;
}
@media (max-width: 420px) {
    .metricas { grid-template-columns: 1fr; }
}
.metrica {
    display: flex; flex-direction: column; gap: .12rem;
    padding: .5rem .6rem;
    border: 1px solid var(--linea);
    border-radius: 9px;
    background: var(--superficie);
    min-width: 0;
}
.metrica--ancha { grid-column: 1 / -1; }
.metrica-rotulo {
    font-size: .63rem; text-transform: uppercase; letter-spacing: .06em;
    color: var(--ink-3);
}
.metrica-valor {
    font-size: 1.15rem; font-weight: 620; letter-spacing: -.02em;
    font-variant-numeric: tabular-nums;
    color: var(--ink-1);
}
.metrica-valor.fuera { color: var(--critico); }
.metrica-pie { font-size: .66rem; color: var(--ink-3); line-height: 1.4; }

.barra {
    height: 6px; margin: .3rem 0 .18rem;
    border-radius: 999px; background: var(--linea);
    overflow: hidden;
}
.barra-relleno {
    display: block; height: 100%;
    border-radius: 999px; background: var(--bien);
    transition: width .3s ease;
}
.barra-relleno--alto { background: var(--grave); }

.cobertura {
    font-size: .68rem; color: var(--ink-3);
    margin: .5rem 0 0;
}
.descargo {
    font-size: .66rem; color: var(--ink-3); line-height: 1.5;
    margin: .35rem 0 0; font-style: italic;
}

@media (prefers-reduced-motion: reduce) {
    .barra-relleno { transition: none; }
}

.traza { margin-top: .8rem; }
.traza summary { font-size: .78rem; color: var(--ink-2); cursor: pointer; }
.traza ol { margin: .5rem 0 0; padding-left: 1.1rem; font-size: .76rem; color: var(--ink-2); }
.traza li { display: flex; flex-wrap: wrap; gap: .3rem .8rem; margin-bottom: .25rem; }
.traza-est { font-weight: 500; min-width: 140px; }
.traza-fecha, .traza-hash { color: var(--ink-3); font-size: .7rem; }

.verificacion {
    font-size: .78rem;
    color: var(--ink-2);
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .45rem;
    line-height: 1.5;
}


.evidencias-nota{font-size:.72rem;color:var(--ink-3);line-height:1.5;margin:.6rem 0 0}

.galeria{list-style:none;margin:.6rem 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.6rem}

.miniatura{display:flex;flex-direction:column;width:100%;padding:0;border:1px solid var(--linea);border-radius:10px;overflow:hidden;background:var(--superficie);text-align:left;text-decoration:none;color:inherit}
.miniatura:hover{border-color:var(--acento);box-shadow:var(--sombra)}
.miniatura img{width:100%;height:118px;object-fit:cover;display:block;background:var(--superficie-2)}
.miniatura--doc{align-items:center}
.doc-icono{height:118px;display:grid;place-items:center;font-size:2.2rem;width:100%;background:var(--superficie-2)}
.mini-pie{display:flex;flex-direction:column;gap:.12rem;padding:.45rem .55rem;width:100%;border-top:1px solid var(--linea)}
.mini-tit{font-size:.74rem;font-weight:600;line-height:1.25}
.mini-meta{font-size:.66rem;color:var(--ink-3)}

.visor{position:fixed;inset:0;z-index:80;background:rgba(7,27,61,.8);backdrop-filter:blur(4px);display:grid;place-items:center;padding:1rem}
.visor-caja{background:var(--superficie);border-radius:14px;overflow:hidden;max-width:min(880px,100%);max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 60px -20px rgba(0,0,0,.6)}
.visor-cab{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:.85rem 1rem;border-bottom:1px solid var(--linea)}
.visor-tit{font-size:.95rem;font-weight:700;margin:0}
.visor-sub{font-size:.72rem;color:var(--ink-3);margin:.15rem 0 0}
.visor-cerrar{border:1px solid var(--linea-fuerte);background:var(--superficie);border-radius:8px;width:34px;height:34px;font-size:.85rem;color:var(--ink-2);flex:none}
.visor-img{max-width:100%;max-height:66vh;object-fit:contain;background:var(--superficie-2);display:block;margin:auto}
.visor-pie{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.6rem;padding:.7rem 1rem;border-top:1px solid var(--linea)}
.visor-hash{font-size:.66rem;color:var(--ink-3);margin:0;overflow:hidden;text-overflow:ellipsis;max-width:60ch}
.visor-abrir{font-size:.76rem;font-weight:600;color:var(--acento);text-decoration:none}
.aviso {
    padding: .9rem;
    border-radius: var(--r-md);
    font-size: .85rem;
    background: var(--superficie);
    border: 1px solid var(--linea);
    margin: 0;
}

.aviso--error { background: var(--critico-suave); border-color: var(--critico); color: var(--critico); }
.aviso--parcial { background: var(--atencion-suave); border-color: var(--atencion); color: var(--ink-2); }
</style>
