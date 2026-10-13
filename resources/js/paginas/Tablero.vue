<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api/cliente'
import GraficoBarras from '../graficos/GraficoBarras.vue'
import GraficoDona from '../graficos/GraficoDona.vue'
import GraficoLinea from '../graficos/GraficoLinea.vue'
import Histograma from '../graficos/Histograma.vue'
import Indicador from '../graficos/Indicador.vue'
import MapaCalor from '../graficos/MapaCalor.vue'
import { colorSemaforo, numero } from '../graficos/util'

/**
 * Tablero de operación.
 *
 * Orden deliberado: primero los titulares —lo que hay que saber en tres
 * segundos—, después la tendencia, después las causas y al final el detalle
 * operativo. Un tablero que abre con una gráfica compleja obliga a interpretar
 * antes de informar.
 */
const datos = ref(null)
const cargando = ref(true)
const error = ref(null)
const dias = ref(30)

async function cargar() {
    cargando.value = true
    error.value = null

    try {
        datos.value = await api.tablero(dias.value)
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

function cambiarPeriodo(n) {
    dias.value = n
    cargar()
}

const ind = computed(() => datos.value?.indicadores ?? {})

/**
 * Series de contexto para el zócalo de cada titular.
 *
 * Las lleva la mayoría de las tarjetas y no solo dos: con unas cuantas con
 * chispa y otras sin ella, la franja se leía como si algunas estuvieran a
 * medio cargar. Un número sin su forma en el tiempo tampoco dice si 314 es
 * mucho o poco.
 */
const serieRadicados = computed(() =>
    (datos.value?.serie_entregas ?? []).map((d) => d.radicados ?? d.entregados)
)

const serieEntregas = computed(() =>
    (datos.value?.serie_entregas ?? []).map((d) => d.entregados)
)

const serieCumplimiento = computed(() =>
    (datos.value?.serie_cumplimiento ?? []).map((d) => d.porcentaje)
)

const estadosDona = computed(() =>
    (datos.value?.distribucion_estados ?? []).map((e) => ({
        etiqueta: e.etiqueta,
        total: e.total,
        // Los estados llevan color de semáforo, no de serie: «entregado» no es
        // una categoría más, es un desenlace bueno, y eso el color lo dice.
        color: e.semaforo === 'neutral' ? 'var(--serie-1)' : colorSemaforo(e.semaforo),
    }))
)

const novedades = computed(() =>
    (datos.value?.novedades_por_causa ?? []).map((n) => ({
        etiqueta: n.etiqueta,
        total: n.total,
        semaforo: n.severidad,
        nota: n.imputable
            ? 'Causa imputable a Avalia: cuenta contra el indicador de cumplimiento.'
            : 'Causa externa: no cuenta contra el indicador, pero sí afecta al cliente.',
    }))
)

/**
 * El rango del periodo, escrito para una persona.
 *
 * Omite el año cuando los dos extremos caen en el mismo —que es casi siempre—
 * porque repetirlo dos veces no aporta nada y alarga el renglón.
 */
const rangoLegible = computed(() => {
    const p = datos.value?.periodo

    if (! p) return ''

    const desde = new Date(`${p.desde}T00:00:00`)
    const hasta = new Date(`${p.hasta}T00:00:00`)
    const mismoAno = desde.getFullYear() === hasta.getFullYear()

    const dia = (f, conAno) => f.toLocaleDateString('es-CO', {
        day: 'numeric', month: 'short', ...(conAno ? { year: 'numeric' } : {}),
    }).replace('.', '')

    return `${dia(desde, ! mismoAno)} – ${dia(hasta, true)} · ${p.dias} días`
})

const excursiones = computed(() => {
    const cf = datos.value?.cadena_frio
    if (!cf || !cf.total_lecturas) return null

    return {
        porcentaje: (cf.excursiones / cf.total_lecturas) * 100,
        ...cf,
    }
})
</script>

<template>
    <div class="pagina">
        <header class="cab">
            <div>
                <h1 class="titulo">Tablero de operación</h1>
                <!-- En castellano y no en ISO. «2026-08-18 a 2026-09-16» es
                     una cadena de máquina: obliga a descifrar el año y el mes
                     para leer un rango que se entiende de un golpe escrito
                     bien. La forma ISO se reserva para las guías y los
                     registros, donde la ordenación importa. -->
                <p class="sub" v-if="datos">
                    {{ rangoLegible }}
                </p>
            </div>

            <div class="periodos" role="group" aria-label="Periodo del tablero">
                <button
                    v-for="n in [7, 30, 90]" :key="n"
                    type="button"
                    class="periodo"
                    :aria-pressed="dias === n"
                    @click="cambiarPeriodo(n)"
                >{{ n }} días</button>
            </div>
        </header>

        <p v-if="error" class="aviso aviso--error">
            No fue posible cargar el tablero: {{ error }}
            <button type="button" class="reintentar" @click="cargar">Reintentar</button>
        </p>

        <p v-else-if="cargando" class="aviso">Cargando indicadores…</p>

        <template v-else-if="datos">
            <!-- 1. Titulares -->
            <section class="kpis" aria-label="Indicadores del periodo">
                <!-- El cumplimiento va PRIMERO y destacado. Es el número del
                     que vive la operación, y en la fila anterior pesaba lo
                     mismo que «en curso ahora». Uno solo lleva el destaque: si
                     se destacan tres, no se destaca ninguno. -->
                <Indicador
                    etiqueta="Cumplimiento de ventana"
                    :valor="ind.cumplimiento?.valor"
                    unidad="%"
                    :decimales="1"
                    :meta="ind.cumplimiento?.meta"
                    :serie="serieCumplimiento"
                    destacado
                />
                <Indicador
                    etiqueta="Envíos radicados"
                    :valor="ind.envios_totales?.valor"
                    :serie="serieRadicados"
                    color="var(--serie-1)"
                    nota="paquetes en el periodo"
                />
                <Indicador
                    etiqueta="Entregados"
                    :valor="ind.entregados?.valor"
                    :serie="serieEntregas"
                    color="var(--serie-2)"
                    nota="acumulado del periodo"
                />
                <Indicador
                    etiqueta="Tasa de novedades"
                    :valor="ind.tasa_novedades?.valor"
                    unidad="%"
                    :decimales="1"
                    :meta="5"
                    :mayor-es-mejor="false"
                />
                <Indicador
                    etiqueta="Tiempo medio de entrega"
                    :valor="ind.tiempo_medio_entrega?.valor"
                    unidad="min"
                    nota="de recolección a entrega"
                />
                <Indicador
                    etiqueta="En curso ahora"
                    :valor="ind.en_curso?.valor"
                    nota="paquetes bajo custodia"
                />
            </section>

            <!-- 2. Tendencia -->
            <section class="rejilla">
                <GraficoLinea
                    class="ancho-completo"
                    titulo="Radicación y entrega"
                    subtitulo="Paquetes por día"
                    :datos="datos.serie_entregas"
                    :series="[
                        { clave: 'radicados', nombre: 'Radicados' },
                        { clave: 'entregados', nombre: 'Entregados' },
                    ]"
                    :banda="['radicados', 'entregados']"
                    unidad="paquetes"
                >
                    <template #nota>
                        La brecha entre las dos líneas es el trabajo acumulado: cuando radicados
                        supera a entregados varios días seguidos, la operación está tomando más
                        de lo que puede sacar.
                    </template>
                </GraficoLinea>

                <GraficoLinea
                    class="ancho-completo"
                    titulo="Cumplimiento de ventana"
                    subtitulo="Porcentaje de entregas dentro de la hora comprometida"
                    :datos="datos.serie_cumplimiento"
                    :series="[{ clave: 'porcentaje', nombre: 'Cumplimiento', area: true, color: 'var(--serie-3)' }]"
                    unidad="%"
                    :decimales="1"
                    :desde-cero="false"
                    :meta="95"
                    etiqueta-meta="Meta"
                >
                    <template #nota>
                        Los días sin entregas aparecen como corte en la línea, no como cero:
                        un cero significaría que todo llegó tarde, que es una afirmación distinta.
                    </template>
                </GraficoLinea>
            </section>

            <!-- 3. Causas y reparto -->
            <section class="rejilla">
                <GraficoBarras
                    v-if="novedades.length"
                    titulo="Novedades por causa"
                    subtitulo="Ordenadas de mayor a menor en el periodo"
                    :datos="novedades"
                    por-semaforo
                >
                    <template #nota>
                        El color indica severidad, y cada barra dice además si la causa es
                        imputable a Avalia. Las causas externas —receptor ausente, sede cerrada—
                        no cuentan contra el indicador, pero sí afectan al cliente y son la
                        base de la conversación con él.
                    </template>
                </GraficoBarras>

                <GraficoDona
                    v-if="estadosDona.length"
                    titulo="Dónde están los paquetes"
                    subtitulo="Últimos 7 días"
                    :datos="estadosDona"
                    etiqueta-total="paquetes"
                />
            </section>

            <!-- 4. Detalle operativo -->
            <section class="rejilla">
                <MapaCalor
                    class="ancho-completo"
                    subtitulo="Radicaciones por franja horaria"
                    :datos="datos.demanda_por_hora"
                >
                    <template #nota>
                        Esta es la vista que decide cuántos mensajeros poner en turno y a qué
                        hora. El promedio diario esconde justamente eso, y esconderlo cuesta
                        dinero todos los días.
                    </template>
                </MapaCalor>

                <Histograma
                    v-if="excursiones"
                    class="ancho-completo"
                    :subtitulo="`${numero(excursiones.excursiones)} excursiones sobre ${numero(excursiones.total_lecturas)} lecturas`"
                    :datos="datos.cadena_frio.histograma"
                    :rango="{ min: 2, max: 8 }"
                >
                    <template #nota>
                        Cada excursión térmica exige una novedad tipificada y una decisión
                        documentada sobre continuar o abortar el servicio. El promedio no
                        basta: dos operaciones con la misma media pueden tener perfiles de
                        riesgo opuestos.
                    </template>
                </Histograma>
            </section>

            <section v-if="datos.flota" class="rejilla">
                <div class="tarjeta">
                    <div class="tarjeta-cab">
                        <h3 class="tarjeta-titulo">Flota</h3>
                        <span class="tarjeta-sub">Ahora mismo</span>
                    </div>
                    <div class="tarjeta-cuerpo flota">
                        <p><strong class="num">{{ datos.flota.en_turno }}</strong> de {{ datos.flota.total }} en turno</p>
                        <p v-if="datos.flota.sin_senal">
                            <span class="pastilla pastilla--atencion">△ {{ datos.flota.sin_senal }} sin señal</span>
                            Un teléfono apagado es un paquete sin trazabilidad.
                        </p>
                        <p v-if="datos.flota.bateria_baja">
                            <span class="pastilla pastilla--atencion">△ {{ datos.flota.bateria_baja }} con batería baja</span>
                        </p>
                        <p><strong class="num">{{ datos.flota.refrigerados }}</strong> con vehículo refrigerado</p>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>

<style scoped>
.pagina {
    max-width: 1240px;
    margin: 0 auto;
    padding: 1.2rem 1rem 3rem;
    display: flex;
    flex-direction: column;
    gap: 1.2rem;
}

.cab {
    display: flex;
    flex-wrap: wrap;
    gap: .8rem 1rem;
    align-items: flex-end;
    justify-content: space-between;
}

.titulo {
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -.025em;
    margin: 0;
}

.sub {
    font-size: .82rem;
    color: var(--ink-3);
    margin: .3rem 0 0;
}

/* Control segmentado: un solo recinto con el botón activo recortado dentro,
   en vez de tres cajas sueltas. Dice «estas tres opciones son la misma
   pregunta» con la forma, no con la separación. */
.periodos {
    display: inline-flex;
    padding: 3px;
    gap: 2px;
    border: 1px solid var(--linea);
    background: var(--superficie-2);
    border-radius: 9px;
}

.periodo {
    border: 1px solid transparent;
    background: transparent;
    border-radius: 6px;
    padding: .34rem .78rem;
    font-size: .78rem;
    font-weight: 500;
    color: var(--ink-3);
    transition: color .14s ease, background .14s ease;
}

.periodo:hover:not([aria-pressed="true"]) { color: var(--ink); }

.periodo[aria-pressed="true"] {
    background: var(--superficie);
    border-color: var(--linea);
    color: var(--acento-ink);
    font-weight: 600;
    box-shadow: 0 1px 2px rgba(13, 19, 51, .07);
}

.kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(178px, 1fr));
    gap: .7rem;
}

.rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
    gap: 1rem;
    align-items: start;
}

.ancho-completo { grid-column: 1 / -1; }

@media (min-width: 1000px) {
    .rejilla > .ancho-completo:only-child { grid-column: 1 / -1; }
}

.aviso {
    padding: 2rem 1rem;
    text-align: center;
    color: var(--ink-3);
    font-family: var(--mono);
    font-size: .8rem;
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-radius: var(--r-md);
}

.aviso--error {
    color: var(--critico);
    background: var(--critico-suave);
    border-color: var(--critico);
}

.reintentar {
    display: block;
    margin: .7rem auto 0;
    border: 1px solid currentColor;
    background: transparent;
    border-radius: var(--r-sm);
    padding: .3rem .8rem;
    font-size: .78rem;
}

.flota { display: flex; flex-direction: column; gap: .5rem; font-size: .85rem; }
.flota p { margin: 0; display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; color: var(--ink-2); }
.flota strong { color: var(--ink); font-size: 1.05rem; }
</style>
