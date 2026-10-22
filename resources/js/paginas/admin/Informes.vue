<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../../api/cliente'
import BotonCsv from '../../componentes/BotonCsv.vue'
import Hero from '../../componentes/Hero.vue'
import GraficoLinea from '../../graficos/GraficoLinea.vue'
import { colorSerie, numero } from '../../graficos/util'

/**
 * Informes de operación.
 *
 * Las cinco preguntas del administrador —cuánto entregó cada mensajero, en qué
 * estados quedó su carga, cómo se reparte el trabajo por cliente, cuánto
 * costaron las urgentes y cuántas guías generó cada cliente— son cortes de un
 * mismo rango de fechas. Por eso se piden juntas y se cambian con pestañas, no
 * con cinco pantallas: el administrador compara entre ellas, y obligarle a
 * reescribir el rango en cada una es pedirle que lo haga de memoria.
 *
 * Dos ejes conviven y la pantalla los nombra, porque sumarlos sería un error:
 * «entregadas» se cuenta por fecha de entrega —el trabajo hecho en el rango—,
 * y «asignadas / en curso» es una foto de lo radicado en el rango.
 */
const datos = ref(null)
const filtros = ref({ mensajeros: [], clientes: [] })
const cargando = ref(true)
const error = ref(null)
const vista = ref('mensajeros')

const hoy = new Date()
const haceUnMes = new Date(hoy.getTime() - 29 * 86400000)

const rango = ref({
    desde: iso(haceUnMes),
    hasta: iso(hoy),
    agrupar: 'dia',
})

const elegidos = ref({ mensajeros: [], clientes: [] })

/*
 * La fecha en hora LOCAL, no en UTC.
 *
 * `toISOString()` convierte a UTC antes de cortar, y en Colombia —cinco horas
 * detras— eso adelanta un dia a partir de las siete de la tarde: el informe se
 * abria con «hasta manana» y el rango no coincidia con el que la persona creia
 * estar pidiendo.
 */
function iso(f) {
    return [
        f.getFullYear(),
        String(f.getMonth() + 1).padStart(2, '0'),
        String(f.getDate()).padStart(2, '0'),
    ].join('-')
}

async function cargar() {
    cargando.value = true

    try {
        datos.value = await api.adminInformes({
            ...rango.value,
            mensajeros: elegidos.value.mensajeros,
            clientes: elegidos.value.clientes,
        })
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(async () => {
    try {
        filtros.value = await api.adminInformeFiltros()
    } catch (e) {
        error.value = e.message
    }

    await cargar()
})

/*
 * El rango se recarga solo; los selectores de sujeto no.
 *
 * Marcar cinco clientes uno a uno dispararía cinco consultas, y las cuatro
 * primeras son trabajo que nadie pidió. El botón «Aplicar» existe por eso.
 */
watch(() => [rango.value.desde, rango.value.hasta, rango.value.agrupar], cargar)

function alternar(lista, id) {
    const i = elegidos.value[lista].indexOf(id)

    if (i >= 0) {
        elegidos.value[lista].splice(i, 1)
    } else {
        elegidos.value[lista].push(id)
    }
}

function esta(lista, id) {
    return elegidos.value[lista].includes(id)
}

function limpiar() {
    elegidos.value = { mensajeros: [], clientes: [] }
    cargar()
}

const hayFiltro = computed(
    () => elegidos.value.mensajeros.length > 0 || elegidos.value.clientes.length > 0
)

const cifras = computed(() => {
    if (!datos.value) return []

    const entregadas = datos.value.mensajeros.reduce((a, m) => a + m.entregadas, 0)
    const guias = datos.value.clientes.reduce((a, c) => a + c.guias, 0)
    const conCompromiso = datos.value.mensajeros.reduce((a, m) => a + m.con_compromiso, 0)
    const aTiempo = datos.value.mensajeros.reduce((a, m) => a + m.a_tiempo, 0)

    /*
     * Con un mensajero marcado, «guías generadas» no se filtra —una guía la
     * genera el cliente, no quien la lleva— y dejar la cifra global al lado de
     * las demás, ya filtradas, invita a leerlas como partes de un mismo total.
     * En ese caso se muestra lo que sí le corresponde: lo que tiene asignado.
     */
    const porMensajero = elegidos.value.mensajeros.length > 0
    const asignadas = datos.value.mensajeros.reduce((a, m) => a + m.asignadas, 0)

    return [
        porMensajero
            ? { etiqueta: 'Paquetes asignados', valor: numero(asignadas) }
            : { etiqueta: 'Guías generadas', valor: numero(guias) },
        { etiqueta: 'Paquetes entregados', valor: numero(entregadas) },
        {
            etiqueta: 'Cumplimiento',
            valor: conCompromiso ? `${(aTiempo * 100 / conCompromiso).toFixed(1)} %` : '—',
            tono: conCompromiso && aTiempo / conCompromiso >= 0.95 ? 'bien' : 'atencion',
        },
        { etiqueta: 'Urgentes', valor: numero(datos.value.urgentes.length) },
    ]
})

/**
 * Las series que entiende el gráfico: una fila por periodo, una columna por
 * sujeto. Se limitan a ocho porque la paleta tiene ocho colores distinguibles
 * y un noveno matiz inventado no lo separa nadie.
 */
function tabular(series) {
    if (!datos.value) return { datos: [], series: [] }

    const visibles = series.slice(0, 8)

    return {
        /*
         * Al grafico se le pasa la clave ISO, no la etiqueta ya formateada: el
         * eje la convierte a fecha por su cuenta, y una cadena como «Sem. 42»
         * no la puede parsear —el eje entero salia en rayas—. La etiqueta de
         * la agrupacion ya la dice el subtitulo.
         */
        datos: datos.value.periodos.map((p, i) => {
            const fila = { periodo: p.clave }
            visibles.forEach((s) => { fila[`s${s.id}`] = s.valores[i] ?? 0 })
            return fila
        }),
        series: visibles.map((s, i) => ({
            clave: `s${s.id}`,
            nombre: s.nombre,
            color: colorSerie(i),
        })),
    }
}

const serieMensajeros = computed(() => tabular(datos.value?.series ?? []))
const serieGuias = computed(() => tabular(datos.value?.series_guias ?? []))

/*
 * Minutos legibles. Por debajo del minuto se baja a segundos en vez de
 * redondear a cero: «0 min» en una columna entera parece una columna rota, y
 * en una operacion que registra varios eventos seguidos —recolectado, en
 * transito, en sitio— los saltos de segundos son reales.
 */
function minutos(v) {
    if (v === null || v === undefined) return '—'
    if (v < 1) return `${Math.round(v * 60)} s`
    if (v < 60) return `${Math.round(v)} min`
    return `${Math.floor(v / 60)} h ${Math.round(v % 60)} min`
}

/** Como se nombra la agrupacion en una frase. */
const PERIODO = { dia: 'día', semana: 'semana', mes: 'mes' }

function pct(v) {
    return v === null || v === undefined ? '—' : `${v} %`
}

function cuando(iso) {
    return iso
        ? new Date(iso.replace(' ', 'T')).toLocaleString('es-CO', {
            day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
        })
        : '—'
}

// --------------------------------------------------------------- descargas
// Cada pestaña exporta lo que está mostrando, con sus filtros aplicados: el
// CSV se arma de las mismas filas que pinta la tabla, no de una segunda
// consulta que podría no coincidir.

const CSV = {
    mensajeros: {
        columnas: ['Mensajero', 'Entregadas', 'Urgentes', 'Días activos', 'Promedio por día',
            'Cumplimiento %', 'Minutos en tránsito', 'Minutos entre gestiones',
            'Asignadas', 'En curso', 'Con novedad', 'Devueltas'],
        filas: () => (datos.value?.mensajeros ?? []).map((m) => [
            m.mensajero, m.entregadas, m.urgentes, m.dias_activos, m.promedio_dia,
            m.cumplimiento ?? '', m.minutos_transito ?? '', m.minutos_entre_gestiones ?? '',
            m.asignadas, m.en_curso, m.con_novedad, m.devueltas,
        ]),
    },
    clientes: {
        columnas: ['Cliente', 'Guías generadas', 'Paquetes', 'Entregados', 'Urgentes',
            'En curso', 'Con novedad', 'Devueltos', 'Cumplimiento %', 'Minutos en tránsito'],
        filas: () => (datos.value?.clientes ?? []).map((c) => [
            c.cliente, c.guias, c.paquetes, c.entregadas, c.urgentes,
            c.en_curso, c.con_novedad, c.devueltas, c.cumplimiento ?? '', c.minutos_transito ?? '',
        ]),
    },
    consolidado: {
        columnas: ['Mensajero', 'Cliente', 'Asignadas', 'Entregadas', 'Urgentes', 'Con novedad'],
        filas: () => (datos.value?.consolidado ?? []).map((f) => [
            f.mensajero, f.cliente, f.asignadas, f.entregadas, f.urgentes, f.con_novedad,
        ]),
    },
    urgentes: {
        columnas: ['Guía', 'Paquete', 'Cliente', 'Mensajero', 'Radicada', 'Compromiso',
            'Entregada', 'Minutos totales', 'Minutos en tránsito', 'A tiempo'],
        filas: () => (datos.value?.urgentes ?? []).map((u) => [
            u.guia, u.codigo, u.cliente, u.mensajero, u.radicada_en, u.comprometida_en ?? '',
            u.entregada_en, u.minutos_totales, u.minutos_transito ?? '',
            u.a_tiempo === null ? '' : (u.a_tiempo ? 'sí' : 'no'),
        ]),
    },
}

const csvFranjas = computed(() => ({
    columnas: ['Mensajero', ...(datos.value?.franjas.columnas ?? []).map((c) => c.etiqueta), 'Total'],
    filas: () => (datos.value?.franjas.filas ?? []).map((f) => [
        f.mensajero,
        ...(datos.value?.franjas.columnas ?? []).map((c) => f.por_franja[c.clave] ?? 0),
        f.total,
    ]),
}))

const csvEstados = computed(() => ({
    columnas: ['Mensajero', ...(datos.value?.estados.columnas ?? []).map((c) => c.etiqueta), 'Total'],
    filas: () => (datos.value?.estados.filas ?? []).map((f) => [
        f.mensajero,
        ...(datos.value?.estados.columnas ?? []).map((c) => f.por_estado[c.clave] ?? 0),
        f.total,
    ]),
}))

const csvActual = computed(() => {
    if (vista.value === 'estados') return csvEstados.value
    if (vista.value === 'franjas') return csvFranjas.value

    return CSV[vista.value]
})

const PESTANAS = [
    { k: 'mensajeros', t: 'Por mensajero' },
    { k: 'franjas', t: 'Por franja horaria' },
    { k: 'estados', t: 'Estados por mensajero' },
    { k: 'consolidado', t: 'Mensajero y cliente' },
    { k: 'clientes', t: 'Por cliente' },
    { k: 'urgentes', t: 'Urgentes' },
]
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Administración"
            titulo="Informes de operación"
            descripcion="Lo entregado se cuenta por fecha de entrega: es el trabajo hecho en el periodo. Lo asignado y en curso es una foto de lo radicado en ese mismo periodo. Son dos preguntas distintas y no se suman."
            :cifras="cifras"
        />

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>

        <!-- ------------------------------------------------------- filtros -->
        <section class="caja-filtros">
            <div class="fila-fechas">
                <label class="rotulado">
                    <span>Desde</span>
                    <input v-model="rango.desde" type="date" class="campo campo--corto">
                </label>

                <label class="rotulado">
                    <span>Hasta</span>
                    <input v-model="rango.hasta" type="date" class="campo campo--corto">
                </label>

                <label class="rotulado">
                    <span>Agrupar por</span>
                    <select v-model="rango.agrupar" class="campo campo--corto">
                        <option value="dia">Día</option>
                        <option value="semana">Semana</option>
                        <option value="mes">Mes</option>
                    </select>
                </label>

                <div class="crece" />

                <button type="button" class="btn btn--pri" :disabled="cargando" @click="cargar">
                    {{ cargando ? 'Calculando…' : 'Aplicar' }}
                </button>

                <button v-if="hayFiltro" type="button" class="btn btn--sec" @click="limpiar">
                    Quitar filtros
                </button>
            </div>

            <!--
                Varios clientes a la vez: comparar es el caso de uso, no la
                excepción. Sin ninguno marcado el informe los incluye a todos.
            -->
            <div class="grupo">
                <span class="rotulo">Clientes</span>
                <div class="fichas">
                    <button
                        v-for="c in filtros.clientes" :key="c.id" type="button"
                        class="ficha" :class="{ 'ficha--on': esta('clientes', c.id) }"
                        @click="alternar('clientes', c.id)"
                    >{{ c.nombre }}</button>
                </div>
            </div>

            <div class="grupo">
                <span class="rotulo">Mensajeros</span>
                <div class="fichas">
                    <button
                        v-for="m in filtros.mensajeros" :key="m.id" type="button"
                        class="ficha" :class="{ 'ficha--on': esta('mensajeros', m.id) }"
                        @click="alternar('mensajeros', m.id)"
                    >{{ m.nombre }}</button>
                </div>
            </div>
        </section>

        <p v-if="cargando && !datos" class="aviso">Calculando informes…</p>

        <template v-else-if="datos">
            <div class="filtros">
                <button
                    v-for="p in PESTANAS" :key="p.k" type="button" class="btn"
                    :class="vista === p.k ? 'btn--pri' : 'btn--sec'"
                    @click="vista = p.k"
                >{{ p.t }}</button>

                <div class="crece" />

                <BotonCsv
                    v-if="csvActual"
                    :nombre="`avalia-informe-${vista}`"
                    :columnas="csvActual.columnas"
                    :filas="csvActual.filas"
                />
            </div>

            <!-- ------------------------------------------- D1 · D2 · D3 -->
            <template v-if="vista === 'mensajeros'">
                <GraficoLinea
                    v-if="serieMensajeros.series.length"
                    titulo="Entregas por mensajero"
                    :subtitulo="`Contadas por fecha de entrega · agrupadas por ${PERIODO[rango.agrupar]}`"
                    :datos="serieMensajeros.datos"
                    campo-x="periodo"
                    :series="serieMensajeros.series"
                    unidad="paquetes"
                />

                <div class="tarjeta desliza">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Mensajero</th>
                                <th class="num">Entregadas</th>
                                <th class="num">Urgentes</th>
                                <th class="num">Días activos</th>
                                <th class="num">Promedio/día</th>
                                <th class="num">Cumplimiento</th>
                                <th class="num">En tránsito</th>
                                <th class="num">Entre gestiones</th>
                                <th class="num">Asignadas</th>
                                <th class="num">En curso</th>
                                <th class="num">Novedad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in datos.mensajeros" :key="m.mensajero_id">
                                <td>{{ m.mensajero }}</td>
                                <td class="num"><strong>{{ numero(m.entregadas) }}</strong></td>
                                <td class="num tenue">{{ numero(m.urgentes) }}</td>
                                <td class="num tenue">{{ m.dias_activos }}</td>
                                <td class="num">{{ m.promedio_dia }}</td>
                                <td class="num">
                                    <span
                                        v-if="m.cumplimiento !== null" class="pastilla"
                                        :class="m.cumplimiento >= 95 ? 'pastilla--bien' : (m.cumplimiento >= 85 ? 'pastilla--atencion' : 'pastilla--critico')"
                                    >{{ pct(m.cumplimiento) }}</span>
                                    <span v-else class="tenue">—</span>
                                </td>
                                <td class="num tenue">{{ minutos(m.minutos_transito) }}</td>
                                <td class="num tenue">{{ minutos(m.minutos_entre_gestiones) }}</td>
                                <td class="num tenue">{{ numero(m.asignadas) }}</td>
                                <td class="num tenue">{{ numero(m.en_curso) }}</td>
                                <td class="num">
                                    <span v-if="m.con_novedad" class="pastilla pastilla--atencion">{{ m.con_novedad }}</span>
                                    <span v-else class="tenue">0</span>
                                </td>
                            </tr>
                            <tr v-if="!datos.mensajeros.length">
                                <td colspan="11" class="vacio">Sin movimiento en este periodo.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="nota">
                    <strong>Entre gestiones</strong> es el tiempo medio entre un evento de la
                    bitácora y el siguiente del mismo paquete. Se descartan los saltos de más de
                    un día: son paquetes que durmieron en bodega, no el ritmo de nadie.
                </p>
            </template>

            <!-- ------------------------------------------------------ D4 -->
            <template v-else-if="vista === 'franjas'">
                <div class="tarjeta desliza">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Mensajero</th>
                                <th v-for="c in datos.franjas.columnas" :key="c.clave" class="num">
                                    {{ c.etiqueta }}
                                </th>
                                <th class="num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(f, i) in datos.franjas.filas" :key="i">
                                <td>{{ f.mensajero }}</td>
                                <td
                                    v-for="c in datos.franjas.columnas" :key="c.clave"
                                    class="num" :class="{ tenue: !f.por_franja[c.clave] }"
                                >{{ f.por_franja[c.clave] || '·' }}</td>
                                <td class="num"><strong>{{ numero(f.total) }}</strong></td>
                            </tr>
                            <tr v-if="!datos.franjas.filas.length">
                                <td :colspan="datos.franjas.columnas.length + 2" class="vacio">
                                    Sin entregas en este periodo.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="nota">
                    Las franjas se cortan por la hora del reloj y se configuran en
                    <strong>Parámetros · jornada.cortes_franja</strong>. La hora de entrada de cada
                    mensajero la marca él mismo en <em>Mi jornada</em>; no es la hora en que el
                    cliente radicó la guía, que es otra cosa.
                </p>
            </template>

            <!-- ------------------------------------------------------ D7 -->
            <div v-else-if="vista === 'estados'" class="tarjeta desliza">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Mensajero</th>
                            <th v-for="c in datos.estados.columnas" :key="c.clave" class="num">{{ c.etiqueta }}</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(f, i) in datos.estados.filas" :key="i">
                            <td>{{ f.mensajero }}</td>
                            <td
                                v-for="c in datos.estados.columnas" :key="c.clave"
                                class="num" :class="{ tenue: !f.por_estado[c.clave] }"
                            >{{ f.por_estado[c.clave] || '·' }}</td>
                            <td class="num"><strong>{{ numero(f.total) }}</strong></td>
                        </tr>
                        <tr v-if="!datos.estados.filas.length">
                            <td :colspan="datos.estados.columnas.length + 2" class="vacio">
                                No se radicó nada en este periodo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ------------------------------------------------------ D8 -->
            <div v-else-if="vista === 'consolidado'" class="tarjeta desliza">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Mensajero</th><th>Cliente</th>
                            <th class="num">Asignadas</th><th class="num">Entregadas</th>
                            <th class="num">Urgentes</th><th class="num">Con novedad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(f, i) in datos.consolidado" :key="i">
                            <td>{{ f.mensajero }}</td>
                            <td class="tenue">{{ f.cliente }}</td>
                            <td class="num tenue">{{ numero(f.asignadas) }}</td>
                            <td class="num"><strong>{{ numero(f.entregadas) }}</strong></td>
                            <td class="num tenue">{{ numero(f.urgentes) }}</td>
                            <td class="num">
                                <span v-if="f.con_novedad" class="pastilla pastilla--atencion">{{ f.con_novedad }}</span>
                                <span v-else class="tenue">0</span>
                            </td>
                        </tr>
                        <tr v-if="!datos.consolidado.length">
                            <td colspan="6" class="vacio">Sin movimiento en este periodo.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ---------------------------------------------- D11 · D12 · D13 -->
            <template v-else-if="vista === 'clientes'">
                <GraficoLinea
                    v-if="serieGuias.series.length"
                    titulo="Guías generadas por cliente"
                    :subtitulo="`Por fecha de radicación · agrupadas por ${PERIODO[rango.agrupar]}`"
                    :datos="serieGuias.datos"
                    campo-x="periodo"
                    :series="serieGuias.series"
                    unidad="guías"
                />

                <div class="tarjeta desliza">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th class="num">Guías</th><th class="num">Paquetes</th>
                                <th class="num">Entregados</th><th class="num">Urgentes</th>
                                <th class="num">En curso</th><th class="num">Novedad</th>
                                <th class="num">Cumplimiento</th><th class="num">En tránsito</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in datos.clientes" :key="c.cliente_id">
                                <td>{{ c.cliente }}</td>
                                <td class="num"><strong>{{ numero(c.guias) }}</strong></td>
                                <td class="num tenue">{{ numero(c.paquetes) }}</td>
                                <td class="num">{{ numero(c.entregadas) }}</td>
                                <td class="num tenue">{{ numero(c.urgentes) }}</td>
                                <td class="num tenue">{{ numero(c.en_curso) }}</td>
                                <td class="num">
                                    <span v-if="c.con_novedad" class="pastilla pastilla--atencion">{{ c.con_novedad }}</span>
                                    <span v-else class="tenue">0</span>
                                </td>
                                <td class="num">
                                    <span
                                        v-if="c.cumplimiento !== null" class="pastilla"
                                        :class="c.cumplimiento >= 95 ? 'pastilla--bien' : (c.cumplimiento >= 85 ? 'pastilla--atencion' : 'pastilla--critico')"
                                    >{{ pct(c.cumplimiento) }}</span>
                                    <span v-else class="tenue">—</span>
                                </td>
                                <td class="num tenue">{{ minutos(c.minutos_transito) }}</td>
                            </tr>
                            <tr v-if="!datos.clientes.length">
                                <td colspan="9" class="vacio">Ningún cliente radicó en este periodo.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- ------------------------------------------------------ D9 -->
            <template v-else>
                <div class="tarjeta desliza">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Guía</th><th>Cliente</th><th>Mensajero</th>
                                <th class="num">Radicada</th><th class="num">Compromiso</th>
                                <th class="num">Entregada</th>
                                <th class="num">Total</th><th class="num">En tránsito</th>
                                <th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(u, i) in datos.urgentes" :key="i">
                                <td class="num">{{ u.guia }}</td>
                                <td class="tenue">{{ u.cliente }}</td>
                                <td>{{ u.mensajero }}</td>
                                <td class="num tenue">{{ cuando(u.radicada_en) }}</td>
                                <td class="num tenue">{{ cuando(u.comprometida_en) }}</td>
                                <td class="num">{{ cuando(u.entregada_en) }}</td>
                                <td class="num"><strong>{{ minutos(u.minutos_totales) }}</strong></td>
                                <td class="num tenue">{{ minutos(u.minutos_transito) }}</td>
                                <td>
                                    <span
                                        v-if="u.a_tiempo !== null" class="pastilla"
                                        :class="u.a_tiempo ? 'pastilla--bien' : 'pastilla--critico'"
                                    >{{ u.a_tiempo ? 'A tiempo' : 'Fuera de compromiso' }}</span>
                                    <span v-else class="tenue">Sin compromiso</span>
                                </td>
                            </tr>
                            <tr v-if="!datos.urgentes.length">
                                <td colspan="9" class="vacio">No hubo urgentes entregadas en este periodo.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="nota">
                    <strong>Total</strong> es el tiempo desde que el cliente radicó hasta que se
                    entregó, que es el que él percibe. <strong>En tránsito</strong> empieza en la
                    recolección: es el tramo del que responde la operación.
                </p>
            </template>
        </template>
    </div>
</template>

<style scoped>
@import './admin.css';

.caja-filtros {
    display: flex;
    flex-direction: column;
    gap: .9rem;
    padding: 1rem 1.1rem;
    border: 1px solid var(--linea);
    border-radius: 12px;
    background: var(--superficie-2);
}

.fila-fechas {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: .75rem;
}

/*
 * `rotulado` y no `campo`: admin.css ya usa `.campo` para el control en si, y
 * reutilizar el nombre para la etiqueta que lo envuelve hacia que el control
 * heredase el `text-transform: uppercase` del rotulo.
 */
.rotulado {
    display: flex;
    flex-direction: column;
    gap: .3rem;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--ink-3);
}

/*
 * `flex: 0 0 auto` anula el `flex: 1 1 220px` de admin.css: dentro de una
 * columna ese crecimiento es VERTICAL, y los selectores de fecha salian de
 * cien pixeles de alto.
 */
.rotulado .campo {
    flex: 0 0 auto;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
    color: var(--ink);
}

.crece { flex: 1 1 auto; }

.grupo {
    display: flex;
    align-items: baseline;
    gap: .75rem;
    flex-wrap: wrap;
}

.rotulo {
    flex: 0 0 5.5rem;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.fichas {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    flex: 1 1 20rem;
}

.ficha {
    border: 1px solid var(--linea-fuerte);
    border-radius: 999px;
    padding: .25rem .7rem;
    font-size: .78rem;
    background: var(--superficie);
    color: var(--ink-2);
    cursor: pointer;
}

.ficha:hover { border-color: var(--ink-3); }

.ficha--on {
    background: var(--acento);
    border-color: var(--acento);
    color: var(--sobre-acento);
    font-weight: 600;
}

.filtros { align-items: center; }

.nota { margin-top: .4rem; }
</style>
