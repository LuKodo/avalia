<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api/cliente'
import Hero from '../componentes/Hero.vue'
import TarjetaCifra from '../componentes/TarjetaCifra.vue'
import { decimal, numero } from '../graficos/util'

/**
 * Cadena de frío en vivo (Tempubi).
 *
 * La sonda mide el compartimento del vehículo, no el paquete. Por eso esta
 * pantalla habla de vehículos y de episodios, y solo al abrir un episodio
 * aparece la lista de envíos que iban a bordo — que es el dato por el que
 * pregunta el cliente cuando algo salió mal.
 */
const vivo = ref(null)
const excursiones = ref([])
const serie = ref(null)
const seleccionado = ref(null)
const cargando = ref(true)
const error = ref(null)
const resolucion = ref('')
const atendiendo = ref(null)

let temporizador = null

async function cargar() {
    try {
        const [v, e] = await Promise.all([api.telemetriaEnVivo(), api.excursiones()])
        vivo.value = v
        excursiones.value = e.excursiones
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(async () => {
    await cargar()

    // Refresco periódico: es una consola de vigilancia, no un informe. Treinta
    // segundos alcanza — la sonda reporta cada cinco minutos, así que pedir más
    // seguido solo gastaría batería del servidor sin traer nada nuevo.
    temporizador = setInterval(cargar, 30_000)
})

onBeforeUnmount(() => temporizador && clearInterval(temporizador))

async function abrirSerie(d) {
    seleccionado.value = d
    serie.value = null

    try {
        serie.value = await api.serieDispositivo(d.id, 24)
    } catch (e) {
        error.value = e.message
    }
}

async function atender(exc) {
    if (resolucion.value.trim().length < 10) return

    atendiendo.value = exc.id

    try {
        await api.atenderExcursion(exc.id, resolucion.value.trim())
        resolucion.value = ''
        await cargar()
    } catch (e) {
        error.value = e.message
    } finally {
        atendiendo.value = null
    }
}

const cifrasHero = computed(() => {
    const r = vivo.value?.resumen
    if (!r) return []

    return [
        { etiqueta: 'Sondas activas', valor: r.total },
        { etiqueta: 'Reportando', valor: r.reportando, tono: r.silenciosos ? 'atencion' : 'bien' },
        { etiqueta: 'Fuera de rango', valor: r.fuera_rango, tono: r.fuera_rango ? 'critico' : 'bien' },
        { etiqueta: 'Episodios abiertos', valor: abiertas.value.length, tono: abiertas.value.length ? 'critico' : 'bien' },
    ]
})

const abiertas = computed(() => excursiones.value.filter((e) => e.estado === 'abierta'))
const cerradas = computed(() => excursiones.value.filter((e) => e.estado !== 'abierta').slice(0, 10))

/** Geometría del mapa: se encuadra sobre las sondas que sí tienen posición. */
const mapa = computed(() => {
    const puntos = (vivo.value?.dispositivos ?? []).filter((d) => d.latitud && d.longitud)
    if (!puntos.length) return null

    const lats = puntos.map((p) => p.latitud)
    const lons = puntos.map((p) => p.longitud)
    const margen = 0.012

    const latMin = Math.min(...lats) - margen
    const latMax = Math.max(...lats) + margen
    const lonMin = Math.min(...lons) - margen
    const lonMax = Math.max(...lons) + margen

    return {
        puntos: puntos.map((p) => ({
            ...p,
            x: ((p.longitud - lonMin) / (lonMax - lonMin || 1)) * 620 + 10,
            y: (1 - (p.latitud - latMin) / (latMax - latMin || 1)) * 320 + 10,
        })),
    }
})

/** Curva de temperatura del dispositivo abierto, con la banda del rango. */
const curva = computed(() => {
    const s = serie.value
    if (!s || s.serie.length < 2) return null

    const W = 680
    const H = 190
    const M = { i: 34, d: 10, a: 12, b: 24 }

    const temps = s.serie.map((p) => p.temperatura).filter((t) => t !== null)
    const min = Math.min(...temps, s.dispositivo.rango.min) - 1
    const max = Math.max(...temps, s.dispositivo.rango.max) + 1

    const x = (i) => M.i + (i / (s.serie.length - 1)) * (W - M.i - M.d)
    const y = (t) => M.a + (1 - (t - min) / (max - min || 1)) * (H - M.a - M.b)

    return {
        W, H, M, min, max,
        d: s.serie.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.temperatura).toFixed(1)}`).join(''),
        banda: { y: y(s.dispositivo.rango.max), alto: Math.max(1, y(s.dispositivo.rango.min) - y(s.dispositivo.rango.max)) },
        marcas: [min, (min + max) / 2, max].map((t) => ({ t, y: y(t) })),
        fuera: s.serie.map((p, i) => ({ ...p, x: x(i), y: y(p.temperatura) })).filter((p) => p.fuera),
    }
})

function hora(iso) {
    if (!iso) return '—'
    return new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

const tonoSeveridad = (s) => ({ critical: 'critico', serious: 'grave', warning: 'atencion' }[s] ?? 'neutro')
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Telemetría Tempubi"
            titulo="Cadena de frío en vivo"
            descripcion="Cada sonda vigila el compartimento de un vehículo y reporta temperatura y ubicación. Los paquetes a bordo heredan esas lecturas mientras estén bajo su custodia."
            :cifras="cifrasHero"
        />

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-else-if="cargando" class="aviso">Consultando dispositivos…</p>

        <template v-else-if="vivo">
            <!-- Episodios abiertos: lo primero, es lo que exige acción ahora -->
            <section v-if="abiertas.length" class="tarjeta borde-critico">
                <div class="tarjeta-cab">
                    <h2 class="tarjeta-titulo">Excursión térmica en curso</h2>
                    <span class="pastilla pastilla--critico">{{ abiertas.length }} abierta(s)</span>
                </div>

                <div v-for="e in abiertas" :key="e.id" class="episodio">
                    <div class="ep-cab">
                        <div>
                            <p class="ep-tit">
                                {{ e.alias ?? e.dispositivo }} · vehículo <span class="num">{{ e.vehiculo }}</span>
                            </p>
                            <p class="ep-meta num">
                                Desde {{ hora(e.inicio) }} · {{ e.duracion_min }} min ·
                                {{ decimal(e.valor_min, 1) }} a {{ decimal(e.valor_max, 1) }} °C
                            </p>
                        </div>
                        <span class="pastilla" :class="`pastilla--${tonoSeveridad(e.severidad) === 'critico' ? 'critico' : 'atencion'}`">
                            {{ e.supera_tolerancia ? 'Supera la tolerancia' : 'Dentro de tolerancia' }}
                        </span>
                    </div>

                    <div v-if="e.paquetes.length" class="ep-paquetes">
                        <p class="ep-rotulo">{{ e.paquetes.length }} envío(s) a bordo durante el episodio</p>
                        <ul>
                            <li v-for="p in e.paquetes" :key="p.codigo">
                                <span class="num">{{ p.guia }}</span>
                                <span class="pastilla pastilla--neutral">{{ p.tipo }}</span>
                                <span v-if="p.rango" class="ep-rango num">exige {{ p.rango.min }}–{{ p.rango.max }} °C</span>
                            </li>
                        </ul>
                        <p class="ep-nota">
                            Ya quedó anotado en la bitácora de cada envío y aparecerá en su soporte de entrega.
                        </p>
                    </div>

                    <p v-else class="ep-nota">
                        Ningún envío iba a bordo durante el episodio: la cava estuvo fuera de rango vacía.
                    </p>

                    <div class="ep-cierre">
                        <label class="etiqueta" :for="`res-${e.id}`">
                            Decisión documentada (obligatoria para cerrar)
                        </label>
                        <div class="ep-fila">
                            <input
                                :id="`res-${e.id}`"
                                v-model="resolucion"
                                class="campo"
                                type="text"
                                placeholder="Ej.: puerta abierta en muelle 12 min; producto verificado por el Q.F. y apto"
                            >
                            <button
                                type="button" class="btn btn--pri"
                                :disabled="resolucion.trim().length < 10 || atendiendo === e.id"
                                @click="atender(e)"
                            >
                                {{ atendiendo === e.id ? 'Guardando…' : 'Cerrar episodio' }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Resumen de sondas -->
            <section class="rejilla">
                <TarjetaCifra
                    etiqueta="Sondas instaladas" :valor="vivo.resumen.total"
                    pie="Solo vehículos refrigerados"
                />
                <TarjetaCifra
                    etiqueta="Sin reportar" :valor="vivo.resumen.silenciosos"
                    pie="Una sonda callada es vigilancia perdida"
                    :tono="vivo.resumen.silenciosos ? 'atencion' : 'bien'"
                />
                <TarjetaCifra
                    etiqueta="Fuera de rango ahora" :valor="vivo.resumen.fuera_rango"
                    :tono="vivo.resumen.fuera_rango ? 'critico' : 'bien'"
                />
                <TarjetaCifra
                    etiqueta="Batería baja" :valor="vivo.resumen.bateria_baja"
                    pie="Menos del 20 %"
                    :tono="vivo.resumen.bateria_baja ? 'atencion' : 'neutro'"
                />
            </section>

            <section class="doble">
                <!-- Mapa -->
                <div class="tarjeta">
                    <div class="tarjeta-cab">
                        <h2 class="tarjeta-titulo">Ubicación de la mercancía</h2>
                        <span class="tarjeta-sub">Posición de la sonda, no del teléfono</span>
                    </div>
                    <div class="tarjeta-cuerpo">
                        <div v-if="mapa" class="mapa">
                            <svg viewBox="0 0 640 340" role="img" aria-label="Ubicación de los vehículos instrumentados">
                                <rect width="640" height="340" fill="var(--superficie-2)" rx="8" />
                                <g stroke="var(--linea)" stroke-width="8" opacity=".7">
                                    <path d="M0 90 H640" /><path d="M0 200 H640" /><path d="M0 300 H640" />
                                    <path d="M140 0 V340" /><path d="M340 0 V340" /><path d="M500 0 V340" />
                                </g>
                                <g v-for="p in mapa.puntos" :key="p.id">
                                    <circle
                                        :cx="p.x" :cy="p.y" r="16" opacity=".18"
                                        :fill="p.en_rango ? 'var(--bien)' : 'var(--critico)'"
                                    />
                                    <circle
                                        :cx="p.x" :cy="p.y" r="8"
                                        :fill="p.en_rango ? 'var(--bien)' : 'var(--critico)'"
                                        stroke="var(--superficie)" stroke-width="2.5"
                                    />
                                    <text :x="p.x" :y="p.y - 20" text-anchor="middle" class="mapa-etq">
                                        {{ p.vehiculo }} · {{ decimal(p.temperatura, 1) }}°
                                    </text>
                                </g>
                            </svg>
                        </div>
                        <p v-else class="vacio">Ninguna sonda ha reportado posición todavía.</p>
                    </div>
                </div>

                <!-- Listado de sondas -->
                <div class="tarjeta">
                    <div class="tarjeta-cab">
                        <h2 class="tarjeta-titulo">Sondas</h2>
                        <span class="tarjeta-sub">Toque una para ver su curva</span>
                    </div>
                    <ul class="sondas">
                        <li v-for="d in vivo.dispositivos" :key="d.id">
                            <button
                                type="button" class="sonda"
                                :class="{ 'sonda--sel': seleccionado?.id === d.id }"
                                @click="abrirSerie(d)"
                            >
                                <span class="sonda-izq">
                                    <span class="sonda-serial num">{{ d.serial }}</span>
                                    <span class="sonda-veh">{{ d.alias }} · {{ d.vehiculo }}</span>
                                </span>
                                <span class="sonda-der">
                                    <span class="sonda-temp num" :class="d.en_rango ? 'ok' : 'mal'">
                                        {{ decimal(d.temperatura, 1) }} °C
                                    </span>
                                    <span class="sonda-est">
                                        <span v-if="d.silencioso" class="pastilla pastilla--atencion">Sin reportar</span>
                                        <span v-else-if="!d.en_rango" class="pastilla pastilla--critico">Fuera de rango</span>
                                        <span v-else class="pastilla pastilla--bien">En rango</span>
                                        <span class="sonda-bat num">{{ d.bateria }}%</span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Curva del dispositivo seleccionado -->
            <section v-if="seleccionado" class="tarjeta">
                <div class="tarjeta-cab">
                    <h2 class="tarjeta-titulo">
                        Curva de {{ seleccionado.alias ?? seleccionado.serial }} · últimas 24 h
                    </h2>
                    <span v-if="serie" class="tarjeta-sub num">
                        {{ numero(serie.estadisticas.lecturas) }} lecturas ·
                        {{ serie.estadisticas.fuera }} fuera de rango
                    </span>
                </div>
                <div class="tarjeta-cuerpo">
                    <p v-if="!serie" class="vacio">Cargando la serie…</p>
                    <template v-else-if="curva">
                        <div class="desliza">
                            <svg :viewBox="`0 0 ${curva.W} ${curva.H}`" class="curva"
                                 role="img" aria-label="Curva de temperatura de las últimas 24 horas">
                                <rect
                                    :x="curva.M.i" :y="curva.banda.y"
                                    :width="curva.W - curva.M.i - curva.M.d" :height="curva.banda.alto"
                                    fill="var(--serie-1)" opacity=".10" rx="3"
                                />
                                <g class="eje">
                                    <line
                                        v-for="m in curva.marcas" :key="m.t"
                                        :x1="curva.M.i" :x2="curva.W - curva.M.d" :y1="m.y" :y2="m.y"
                                        stroke="var(--rejilla)"
                                    />
                                    <text v-for="m in curva.marcas" :key="`t${m.t}`"
                                          :x="curva.M.i - 6" :y="m.y + 3.5" text-anchor="end">
                                        {{ decimal(m.t, 1) }}
                                    </text>
                                </g>
                                <path :d="curva.d" fill="none" stroke="var(--serie-1)" stroke-width="1.8"
                                      stroke-linejoin="round" stroke-linecap="round" />
                                <circle
                                    v-for="(p, i) in curva.fuera" :key="i"
                                    :cx="p.x" :cy="p.y" r="3" fill="var(--critico)"
                                />
                                <text :x="curva.W - curva.M.d" :y="curva.H - 6" text-anchor="end" class="eje-nota">
                                    banda azul = rango exigido {{ serie.dispositivo.rango.min }}–{{ serie.dispositivo.rango.max }} °C
                                </text>
                            </svg>
                        </div>
                        <p class="curva-pie num">
                            mínima {{ decimal(serie.estadisticas.minima, 1) }} °C ·
                            promedio {{ decimal(serie.estadisticas.promedio, 1) }} °C ·
                            máxima {{ decimal(serie.estadisticas.maxima, 1) }} °C
                        </p>
                    </template>
                    <p v-else class="vacio">Este dispositivo aún no tiene suficientes lecturas.</p>
                </div>
            </section>

            <!-- Historial -->
            <section v-if="cerradas.length" class="tarjeta">
                <div class="tarjeta-cab">
                    <h2 class="tarjeta-titulo">Episodios cerrados</h2>
                    <span class="tarjeta-sub">Últimos 10</span>
                </div>
                <div class="desliza">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Vehículo</th><th>Inicio</th><th>Duración</th>
                                <th>Mín / Máx</th><th>Envíos</th><th>Severidad</th><th>Resolución</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in cerradas" :key="e.id">
                                <td class="num">{{ e.vehiculo }}</td>
                                <td class="num">{{ hora(e.inicio) }}</td>
                                <td class="num">{{ e.duracion_min }} min</td>
                                <td class="num">{{ decimal(e.valor_min, 1) }} / {{ decimal(e.valor_max, 1) }} °C</td>
                                <td class="num">{{ e.paquetes.length }}</td>
                                <td>
                                    <span class="pastilla" :class="e.severidad === 'critical' ? 'pastilla--critico' : 'pastilla--atencion'">
                                        {{ e.severidad }}
                                    </span>
                                </td>
                                <td class="res">{{ e.resolucion ?? '— sin documentar —' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>

<style scoped>
.pagina {
    max-width: 1280px;
    margin: 0 auto;
    padding: 1.2rem 1rem 3rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 1000px) { .pagina { padding: 1.4rem 1.6rem 3rem; } }

.rejilla { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .8rem; }
.doble { display: grid; grid-template-columns: 1fr; gap: 1rem; align-items: start; }
@media (min-width: 1020px) { .doble { grid-template-columns: 1.25fr 1fr; } }

.borde-critico { border-color: var(--critico); }

.episodio { padding: .95rem; border-top: 1px solid var(--linea); }
.ep-cab { display: flex; flex-wrap: wrap; gap: .6rem; justify-content: space-between; align-items: flex-start; }
.ep-tit { font-size: .95rem; font-weight: 600; margin: 0; }
.ep-meta { font-size: .75rem; color: var(--ink-3); margin: .18rem 0 0; }

.ep-paquetes { margin-top: .8rem; padding: .7rem .8rem; background: var(--superficie-2); border-radius: var(--r-sm); }

.ep-rotulo {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin: 0 0 .45rem;
}

.ep-paquetes ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .3rem; }
.ep-paquetes li { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; font-size: .82rem; }
.ep-rango { font-size: .72rem; color: var(--ink-3); }

.ep-nota { font-size: .76rem; color: var(--ink-3); margin: .6rem 0 0; line-height: 1.45; }

.ep-cierre { margin-top: .9rem; }
.ep-fila { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .3rem; }

.etiqueta {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.campo {
    flex: 1 1 280px;
    min-width: 0;
    min-height: 42px;
    border: 1px solid var(--linea-fuerte);
    border-radius: var(--r-sm);
    background: var(--superficie);
    padding: .5rem .7rem;
    font-size: .85rem;
}

.btn {
    min-height: 42px;
    padding: .5rem 1rem;
    border-radius: var(--r-sm);
    font-size: .84rem;
    font-weight: 600;
    border: 1px solid transparent;
    flex: none;
}

.btn--pri { background: var(--acento); color: var(--sobre-acento); }
.btn--pri:disabled { opacity: .45; cursor: not-allowed; }

.mapa { border-radius: var(--r-sm); overflow: hidden; }
.mapa svg { display: block; width: 100%; height: auto; }
.mapa-etq { font-family: var(--mono); font-size: 10px; fill: var(--ink-2); }

.sondas { list-style: none; margin: 0; padding: 0; }
.sondas li { border-bottom: 1px solid var(--linea); }
.sondas li:last-child { border-bottom: none; }

.sonda {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .8rem;
    padding: .7rem .9rem;
    background: none;
    border: none;
    text-align: left;
}

.sonda:hover { background: var(--superficie-2); }
.sonda--sel { background: var(--acento-suave); }

.sonda-izq { display: flex; flex-direction: column; min-width: 0; }
.sonda-serial { font-size: .8rem; font-weight: 600; }
.sonda-veh { font-size: .72rem; color: var(--ink-3); }

.sonda-der { display: flex; flex-direction: column; align-items: flex-end; gap: .25rem; flex: none; }
.sonda-temp { font-size: 1.05rem; font-weight: 600; }
.sonda-temp.ok { color: var(--ink); }
.sonda-temp.mal { color: var(--critico); }
.sonda-est { display: flex; align-items: center; gap: .4rem; }
.sonda-bat { font-size: .68rem; color: var(--ink-3); }

.curva { display: block; width: 100%; min-width: 520px; height: auto; }
.eje text { font-family: var(--mono); font-size: 9.5px; fill: var(--ink-3); }
.eje-nota { font-family: var(--mono); font-size: 9px; fill: var(--ink-3); }
.curva-pie { font-size: .76rem; color: var(--ink-3); margin: .6rem 0 0; text-align: center; }

.tabla { width: 100%; border-collapse: collapse; font-size: .8rem; }

.tabla th {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--ink-3);
    font-weight: 500;
    text-align: left;
    padding: .5rem .8rem;
    background: var(--superficie-2);
    border-bottom: 1px solid var(--linea);
    white-space: nowrap;
}

.tabla td { padding: .5rem .8rem; border-bottom: 1px solid var(--linea); white-space: nowrap; }
.tabla tbody tr:last-child td { border-bottom: none; }
.res { white-space: normal; color: var(--ink-2); font-size: .76rem; max-width: 34ch; }

.vacio { text-align: center; color: var(--ink-3); font-size: .84rem; padding: 1.6rem; }

.aviso {
    padding: .9rem 1rem;
    border-radius: var(--r-md);
    font-size: .85rem;
    background: var(--superficie);
    border: 1px solid var(--linea);
    margin: 0;
}

.aviso--error { background: var(--critico-suave); border-color: var(--critico); color: var(--critico); }
</style>
