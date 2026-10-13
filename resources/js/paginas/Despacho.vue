<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api/cliente'
import { numero } from '../graficos/util'
import BotonCsv from '../componentes/BotonCsv.vue'
import CadenaTramos from '../componentes/CadenaTramos.vue'

/**
 * Bandeja de despacho.
 *
 * Ordenada por criticidad y holgura, no por hora de creación: lo que está por
 * vencer sube solo. La sugerencia del motor viaja en la fila, así que asignar
 * el caso normal cuesta un clic; abrir el detalle es para cuando el operador
 * quiere entender o discrepar.
 *
 * Cuando discrepa, el motivo es obligatorio. Esa fricción es deliberada: los
 * motivos de anulación son la única señal que dice dónde se equivoca el motor.
 */
/*
 * Columnas del CSV. No coinciden con las de la pantalla y es a propósito: en
 * pantalla la holgura se pinta con color y la sugerencia lleva su puntaje al
 * lado, cosas que en una hoja de cálculo no se pueden usar. El archivo separa
 * esos datos en columnas propias para que se puedan ordenar y filtrar.
 */
const COLUMNAS_CSV = [
    'Guía', 'Referencia', 'Cliente', 'Tipo', 'Criticidad', 'Recoger en',
    'Ventana desde', 'Ventana hasta', 'Holgura (min)', 'Estado de holgura',
    'Mensajero sugerido', 'Puntaje',
]

const bandeja = ref([])

/*
 * Filtros y ordenacion, en el navegador.
 *
 * La bandeja son las solicitudes SIN asignar: decenas, no miles. Filtrar aqui
 * responde al instante y no castiga al servidor con una consulta por cada
 * tecla. El dia que esto crezca a miles de filas habra que moverlo al servidor
 * con paginacion; mientras tanto seria complicarlo sin ganar nada.
 */
const busqueda = ref('')
const tipoFiltro = ref('')

/*
 * Por defecto, la mas antigua primero.
 *
 * Es el orden de una bandeja de trabajo: lo que lleva mas tiempo esperando se
 * atiende antes. El operador puede cambiarlo pulsando cualquier cabecera, y
 * pulsar la misma otra vez le da la vuelta.
 */
const campoOrden = ref('registrado_en')
const ascendente = ref(true)

/* El sentido actual, para que la cabecera lo diga sin tener que adivinarlo. */
function flecha(campo) {
    if (campoOrden.value !== campo) return ''

    return ascendente.value ? ' ↑' : ' ↓'
}

function ordenarPor(campo) {
    if (campoOrden.value === campo) {
        ascendente.value = ! ascendente.value

        return
    }

    campoOrden.value = campo

    // La holgura al reves que las demas: ahi lo urgente es lo MENOR -lo que
    // esta mas vencido-, asi que al elegirla se empieza por lo critico.
    ascendente.value = campo !== 'holgura_min'
}

const visibles = computed(() => {
    const texto = busqueda.value.trim().toLowerCase()

    const filtradas = bandeja.value.filter((f) => {
        if (tipoFiltro.value && f.tipo !== tipoFiltro.value) {
            return false
        }

        if (! texto) {
            return true
        }

        // Guia, referencias y cliente en una sola caja: quien busca ya sabe
        // que esta escribiendo, y obligarle a elegir el campo antes solo
        // aniade un paso.
        return [f.guia, f.cliente, ...(f.referencias ?? [])]
            .filter(Boolean)
            .some((v) => String(v).toLowerCase().includes(texto))
    })

    const signo = ascendente.value ? 1 : -1

    return [...filtradas].sort((a, b) => {
        const x = a[campoOrden.value]
        const y = b[campoOrden.value]

        // Lo que no tiene valor va al final, se ordene como se ordene: una
        // solicitud sin ventana comprometida no es "la mas urgente".
        if (x == null && y == null) return 0
        if (x == null) return 1
        if (y == null) return -1

        if (typeof x === 'number' && typeof y === 'number') {
            return (x - y) * signo
        }

        return String(x).localeCompare(String(y), 'es') * signo
    })
})

/* Las fechas ISO se leen bien en una tabla, pero no se leen bien a secas. */
function fechaCorta(iso) {
    if (! iso) return '—'

    const d = new Date(iso)

    // Reloj de 24 h y mes abreviado sin la preposicion: en una columna que se
    // compara de un vistazo, «28 sep 07:44» dice lo mismo que
    // «28 de sept 07:44 a. m.» en la mitad de espacio.
    const dia = d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' }).replace(' de ', ' ')
    const hora = d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: false })

    return `${dia} ${hora}`
}

const cargando = ref(true)
const error = ref(null)
const detalle = ref(null)
const candidatos = ref(null)
const motivo = ref('')
const asignando = ref(false)

async function cargar() {
    cargando.value = true
    error.value = null

    try {
        const r = await api.bandeja()
        bandeja.value = r.paquetes
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

/* =============================== soportes sin acuse ==================== */

/**
 * Lo que no llegó al repositorio del cliente.
 *
 * Vive aquí, pegado a la bandeja, porque es trabajo de operación y porque si
 * vive en un sitio al que hay que ir a propósito, nadie va. Agotados los
 * reintentos el trabajo cae en `failed_jobs`, y nadie mira `failed_jobs`.
 *
 * El panel solo aparece cuando hay algo que mirar: una fila permanente vacía
 * enseña a la gente a ignorar ese rincón de la pantalla.
 */
const soportes = ref([])
const reenviando = ref(null)

async function cargarSoportes() {
    try {
        const r = await api.soportesPendientes()
        soportes.value = r.pendientes
    } catch {
        // Un fallo aquí no puede tapar la bandeja, que es el trabajo principal
        // de esta pantalla.
        soportes.value = []
    }
}

async function reenviar(fila) {
    reenviando.value = fila.evidencia_id

    try {
        await api.reenviarSoporte(fila.evidencia_id)
        await cargarSoportes()
    } catch (e) {
        error.value = e.message
    } finally {
        reenviando.value = null
    }
}

onMounted(() => { cargar(); cargarCatalogos(); cargarSoportes() })

async function abrir(fila) {
    detalle.value = fila
    candidatos.value = null
    motivo.value = ''

    try {
        candidatos.value = await api.candidatos(fila.paquete_id)
    } catch (e) {
        error.value = e.message
    }
}

/*
 * Catalogos para los mandos de la cadena.
 *
 * Se piden UNA vez al abrir la pantalla, no cada vez que se abre un paquete:
 * las sedes propias y los mensajeros no cambian entre una fila y la siguiente,
 * y pedirlos por paquete seria un viaje por clic.
 */
const centros = ref([])
const mensajerosCat = ref([])

async function cargarCatalogos() {
    try {
        const c = await api.catalogoDespacho()
        centros.value = c.centros ?? []
        mensajerosCat.value = c.mensajeros ?? []
    } catch {
        // Sin catalogo, la cadena se ve pero no se puede asignar desde aqui.
        // No es motivo para tumbar la bandeja entera.
    }
}

async function asignar(mensajeroId) {
    const esSugerido = candidatos.value?.candidatos?.[0]?.mensajero_id === mensajeroId

    if (!esSugerido && !motivo.value.trim()) {
        error.value = 'Indique el motivo por el que descarta la sugerencia del sistema.'
        return
    }

    asignando.value = true
    error.value = null

    try {
        await api.asignar(detalle.value.paquete_id, mensajeroId, motivo.value.trim() || null)
        detalle.value = null
        await cargar()
    } catch (e) {
        error.value = e.message
    } finally {
        asignando.value = false
    }
}

function claseHolgura(min) {
    if (min === null) return ''
    if (min < 45) return 'holgura--critica'
    if (min < 120) return 'holgura--atencion'
    return ''
}

/**
 * Filas del CSV.
 *
 * Se calcula al pulsar, no al cargar: así el archivo refleja lo que la bandeja
 * tiene en ese momento y no lo que tenía al abrir la pantalla.
 */
function filasCsv() {
    return bandeja.value.map((f) => [
        f.guia,
        f.referencias?.[0] ?? '',
        f.cliente,
        f.tipo_etiqueta,
        f.criticidad,
        f.origen,
        f.ventana?.desde ?? '',
        f.ventana?.hasta ?? '',
        // El número crudo, para poder ordenar en la hoja. El texto legible va
        // en la columna siguiente.
        f.holgura_min ?? '',
        f.holgura_min === null ? '' : (f.holgura_min < 0 ? 'vencida' : 'vigente'),
        f.sugerencia?.nombre ?? 'Sin candidato viable',
        f.sugerencia?.puntaje ?? '',
    ])
}

function formatoHolgura(min) {
    if (min === null) return '—'

    /*
     * Por encima de un dia se cuenta en dias.
     *
     * «vencida 6758 min» es un dato, no una informacion: nadie divide entre
     * 1440 de cabeza para entender que lleva casi cinco dias parada. Y de paso
     * la columna deja de necesitar el doble de ancho que las demas.
     */
    if (Math.abs(min) >= 1440) {
        const dias = Math.floor(Math.abs(min) / 1440)

        // Sin las horas: cuando algo lleva dias parado, que sean cuatro o
        // cuatro y veinte no cambia ninguna decision.
        return min < 0 ? `vencida ${dias} d` : `${dias} d`
    }

    if (min < 0) return `vencida ${Math.abs(min)} min`
    if (min < 60) return `${min} min`

    // Los minutos van con cero a la izquierda: en una columna que se compara de
    // un vistazo, «17 h 3» se lee peor que «17 h 03».
    return `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')}`
}
</script>

<template>
    <div class="pagina">
        <header class="cab">
            <div>
                <h1 class="titulo">Bandeja de despacho</h1>
                <p class="sub">
                    {{ numero(bandeja.length) }} sin asignar ·
                    {{ visibles.length }} visible(s) · pulse una cabecera para ordenar
                </p>
            </div>
            <div class="cab-acciones">
                <BotonCsv
                    v-if="bandeja.length"
                    nombre="bandeja-despacho"
                    :columnas="COLUMNAS_CSV"
                    :filas="filasCsv"
                />
                <button type="button" class="btn btn--sec" @click="cargar">↻ Actualizar</button>
            </div>
        </header>

        <!--
            Los filtros, en una barra propia sobre la tabla.

            Una sola caja busca por guia, referencia y cliente a la vez: quien
            escribe ya sabe que esta buscando, y obligarle a elegir el campo
            antes solo aniade un paso.
        -->
        <div v-if="bandeja.length" class="filtros">
            <label class="filtro-busca">
                <span class="solo-lector">Buscar por guia, referencia o cliente</span>
                <input
                    v-model="busqueda"
                    type="search"
                    class="campo"
                    placeholder="Buscar guía, referencia o cliente…"
                >
            </label>

            <label class="filtro-tipo">
                <span class="solo-lector">Tipo de carga</span>
                <select v-model="tipoFiltro" class="campo">
                    <option value="">Todos los tipos</option>
                    <option value="general">General</option>
                    <option value="cadena_frio">Cadena de frío</option>
                    <option value="controlado">Control especial</option>
                </select>
            </label>

            <p class="filtro-cuenta num">
                {{ visibles.length }} de {{ bandeja.length }}
            </p>
        </div>

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-if="cargando" class="aviso">Cargando bandeja…</p>
        <p v-else-if="!bandeja.length" class="aviso">No hay paquetes sin asignar.</p>

        <div v-else class="desliza tarjeta">
            <table class="tabla">
                <!--
                    El reparto de ancho, en porcentaje para que siga funcionando
                    en cualquier pantalla. Guia, ventana y holgura llevan ancho
                    propio porque su contenido es de largo conocido; cliente y
                    origen son los que se recortan si hace falta.
                -->
                <colgroup>
                    <col style="width: 12%">   <!-- Guia: identificador, nunca se recorta -->
                    <col style="width: 11%">   <!-- Referencia: la llave del cliente -->
                    <col style="width: 10%">   <!-- Cliente: cede, lleva title -->
                    <col style="width: 10%">   <!-- Tipo -->
                    <col style="width: 10%">   <!-- Recoger en: cede, lleva title -->
                    <col style="width: 11%">   <!-- Registrado -->
                    <col style="width: 9%">    <!-- Ventana -->
                    <col style="width: 9%">    <!-- Holgura -->
                    <col style="width: 10%">   <!-- Sugerencia -->
                    <col style="width: 8%">    <!-- Accion -->
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col"><button type="button" class="orden" @click="ordenarPor('guia')">Guía<span class="flecha">{{ flecha('guia') }}</span></button></th>
                        <th scope="col">Referencia</th>
                        <th scope="col"><button type="button" class="orden" @click="ordenarPor('cliente')">Cliente<span class="flecha">{{ flecha('cliente') }}</span></button></th>
                        <th scope="col"><button type="button" class="orden" @click="ordenarPor('tipo')">Tipo<span class="flecha">{{ flecha('tipo') }}</span></button></th>
                        <th scope="col">Recoger en</th>
                        <th scope="col"><button type="button" class="orden" @click="ordenarPor('registrado_en')">Registrado<span class="flecha">{{ flecha('registrado_en') }}</span></button></th>
                        <th scope="col">Ventana</th>
                        <th scope="col"><button type="button" class="orden" @click="ordenarPor('holgura_min')">Holgura<span class="flecha">{{ flecha('holgura_min') }}</span></button></th>
                        <th scope="col">Sugerencia</th>
                        <th scope="col"><span class="solo-lector">Acción</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in visibles" :key="f.paquete_id">
                        <td class="num">{{ f.guia }}</td>
                        <td class="num ref">{{ f.referencias?.[0] ?? '—' }}</td>
                        <td :title="f.cliente">{{ f.cliente }}</td>
                        <td>
                            <span class="pastilla pastilla--neutral">{{ f.tipo_etiqueta }}</span>
                            <span v-if="f.criticidad === 'urgente'" class="pastilla pastilla--critico">⚡</span>
                        </td>
                        <td :title="f.origen">{{ f.origen }}</td>
                        <td class="num" :title="f.registrado_en">{{ fechaCorta(f.registrado_en) }}</td>
                        <td class="num">{{ f.ventana.desde }}–{{ f.ventana.hasta }}</td>
                        <td class="num" :class="claseHolgura(f.holgura_min)">{{ formatoHolgura(f.holgura_min) }}</td>
                        <td>
                            <template v-if="f.sugerencia">
                                {{ f.sugerencia.nombre }}
                                <strong class="puntaje num">{{ f.sugerencia.puntaje }}</strong>
                            </template>
                            <span v-else class="sin-candidato" title="Ningún mensajero cumple el filtro duro">Sin candidato</span>
                        </td>
                        <td>
                            <button type="button" class="btn btn--mini" @click="abrir(f)">
                                {{ f.sugerencia ? 'Asignar' : 'Revisar' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Panel de asignación -->
        <div v-if="detalle" class="panel">
            <header class="panel-cab">
                <div>
                    <h2 class="panel-titulo num">{{ detalle.guia }}</h2>
                    <p class="panel-sub">{{ detalle.origen }} → {{ detalle.destino }}</p>
                </div>
                <button type="button" class="cerrar" @click="detalle = null" aria-label="Cerrar">✕</button>
            </header>

            <!-- La cadena de trayectos. Va ARRIBA de los candidatos porque
                 decide sobre que tramo se esta asignando: sin verla, el
                 operador no sabe si pone mensajero a la recogida o a la
                 entrega. -->
            <CadenaTramos
                :paquete-id="detalle.paquete_id"
                :centros="centros"
                :mensajeros="mensajerosCat"
                class="bloque-cadena"
                @cambio="cargar"
            />

            <p v-if="!candidatos" class="aviso">Calculando candidatos…</p>

            <template v-else>
                <section class="filtro">
                    <p class="filtro-titulo">
                        Filtro duro ·
                        <strong>{{ candidatos.filtro_duro.viables }}</strong>
                        de {{ candidatos.filtro_duro.total_mensajeros }} mensajeros pueden hacerlo
                    </p>
                    <ul>
                        <li v-for="e in candidatos.filtro_duro.exigencias" :key="e">✓ {{ e }}</li>
                    </ul>

                    <!--
                        Por que quedo fuera cada uno. Antes solo se listaban los
                        requisitos, asi que con cero candidatos habia que adivinar
                        cual fallaba; ahora lo dice el motor, con la cuenta.
                    -->
                    <ul v-if="candidatos.filtro_duro.descartes?.length" class="descartes">
                        <li v-for="d in candidatos.filtro_duro.descartes" :key="d.motivo">
                            ✗ {{ d.motivo }}
                            <strong class="num">{{ d.cuantos }}</strong>
                        </li>
                    </ul>
                </section>

                <p v-if="!candidatos.candidatos.length" class="aviso aviso--error">
                    Ningún mensajero cumple los requisitos obligatorios.
                    <template v-if="candidatos.filtro_duro.descartes?.length">
                        El principal motivo:
                        <strong>{{ candidatos.filtro_duro.descartes[0].motivo.toLowerCase() }}</strong>
                        ({{ candidatos.filtro_duro.descartes[0].cuantos }} de
                        {{ candidatos.filtro_duro.total_mensajeros }}).
                    </template>
                </p>

                <ul v-else class="candidatos">
                    <li
                        v-for="(c, i) in candidatos.candidatos"
                        :key="c.mensajero_id"
                        class="candidato"
                        :class="{ 'candidato--top': i === 0 }"
                    >
                        <header class="candidato-cab">
                            <div>
                                <strong>{{ c.nombre }}</strong>
                                <span v-if="i === 0" class="pastilla pastilla--bien">sugerido</span>
                            </div>
                            <span class="candidato-puntaje num">{{ c.puntaje }}</span>
                        </header>

                        <p class="candidato-meta num">
                            {{ c.tipo_vehiculo }} {{ c.vehiculo }} ·
                            {{ c.paquetes_activos }} activos ·
                            {{ c.distancia_km !== null ? `a ${c.distancia_km} km` : 'sin posición' }}
                        </p>

                        <ul class="desglose">
                            <li v-for="(v, k) in c.desglose" :key="k">
                                <span class="factor">{{ k }}</span>
                                <span class="barra"><span class="relleno" :style="{ width: `${v}%` }" /></span>
                                <span class="valor num">{{ v }}</span>
                            </li>
                        </ul>

                        <button
                            type="button"
                            class="btn"
                            :class="i === 0 ? 'btn--pri' : 'btn--sec'"
                            :disabled="asignando"
                            @click="asignar(c.mensajero_id)"
                        >Asignar a {{ c.nombre.split(' ')[0] }}</button>
                    </li>
                </ul>

                <div v-if="candidatos.candidatos.length > 1" class="motivo">
                    <label class="etiqueta" for="motivo">
                        Motivo, si descarta la sugerencia (obligatorio)
                    </label>
                    <input
                        id="motivo" v-model="motivo" class="campo" type="text"
                        placeholder="Ej.: el sugerido ya va con retraso en la ruta del norte"
                    >
                    <p class="motivo-nota">
                        Estos motivos son la materia prima para calibrar los pesos del motor.
                        Sin ellos, el sistema nunca aprende dónde se equivoca.
                    </p>
                </div>
            </template>
        </div>

        <!-- ================================= soportes sin acuse del cliente -->
        <section v-if="soportes.length" class="soportes">
            <header class="soportes-cab">
                <h2 class="soportes-tit">Soportes sin confirmar</h2>
                <p class="soportes-sub">
                    {{ soportes.length }} envío(s) de soporte que el repositorio del cliente
                    todavía no ha acusado. La entrega es válida igual —el soporte está en
                    Avalia— pero el cliente no lo ha recibido.
                </p>
            </header>

            <table class="tabla-soportes">
                <thead>
                    <tr>
                        <th>Guía</th><th>Cliente</th><th>Referencia</th>
                        <th>Tipo</th><th>Intentos</th><th>Último error</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in soportes" :key="f.evidencia_id">
                        <td class="num">{{ f.guia }}</td>
                        <td>{{ f.cliente }}</td>
                        <td class="num">{{ f.referencia ?? '—' }}</td>
                        <td>{{ f.tipo }}</td>
                        <td class="num">{{ f.intentos }}</td>
                        <td class="error-celda">{{ f.ultimo_error ?? '—' }}</td>
                        <td>
                            <button
                                type="button" class="btn-reenviar"
                                :disabled="reenviando === f.evidencia_id"
                                @click="reenviar(f)"
                            >{{ reenviando === f.evidencia_id ? 'Enviando…' : 'Reintentar' }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>

<style scoped>
/* ------------------------------------------- soportes sin acuse ------- */
.soportes {
    margin-top: 1.4rem;
    border: 1px solid var(--linea);
    border-left: 3px solid var(--atencion, #d9a300);
    border-radius: var(--r-md);
    background: var(--superficie);
    padding: 1rem 1.1rem 1.2rem;
}

.soportes-cab { margin-bottom: .8rem; }
.soportes-tit { font-size: 1rem; font-weight: 700; margin: 0; letter-spacing: -.01em; }
.soportes-sub { margin: .25rem 0 0; font-size: .82rem; color: var(--ink-3); line-height: 1.5; max-width: 72ch; }

.tabla-soportes { width: 100%; border-collapse: collapse; font-size: .82rem; }
.tabla-soportes th {
    text-align: left; font-size: .7rem; letter-spacing: .08em; text-transform: uppercase;
    color: var(--ink-3); font-weight: 600; padding: .4rem .5rem; border-bottom: 1px solid var(--linea);
}
.tabla-soportes td { padding: .5rem; border-bottom: 1px solid var(--linea); vertical-align: top; }
.tabla-soportes .num { font-variant-numeric: tabular-nums; }

/* El error se recorta: algunos traen el cuerpo entero de la respuesta ajena
   y una fila de doce líneas hace ilegible la tabla. */
.error-celda {
    max-width: 34ch; color: var(--ink-2);
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden;
}

.btn-reenviar {
    padding: .35rem .8rem; border: 1px solid var(--linea-fuerte);
    border-radius: var(--r-sm); background: var(--superficie);
    font-family: inherit; font-size: .78rem; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.btn-reenviar:disabled { opacity: .6; cursor: default; }

/* Las acciones de cabecera se alinean al pie para que el aviso de filas
   exportadas, que aparece bajo el boton, no empuje al de Actualizar. */
.cab-acciones { display: flex; align-items: flex-start; gap: .5rem; flex-wrap: wrap; }

/* La bandeja es una tabla operativa de nueve columnas: aqui el limite de
   lectura comoda no manda, manda que quepa la fila entera sin desplazar. */
.pagina { max-width: 1600px; margin: 0 auto; padding: 1.2rem 1rem 3rem; display: flex; flex-direction: column; gap: 1rem; }

.cab { display: flex; flex-wrap: wrap; gap: .8rem; align-items: flex-end; justify-content: space-between; }

/* La barra de filtros: la caja de busqueda se come el espacio sobrante y el
   resto se queda con lo suyo. */
.filtros { display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; }
.filtro-busca { flex: 1 1 18rem; }
.filtro-tipo  { flex: 0 0 12rem; }
.filtro-cuenta { margin: 0; font-size: .72rem; color: var(--ink-3); white-space: nowrap; }

/* Cabecera pulsable. Hereda el aspecto del <th> para que siga pareciendo una
   cabecera y no un boton suelto; lo unico que la delata es el cursor y la
   flecha cuando esta activa. */
.orden {
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    color: inherit;
    letter-spacing: inherit;
    text-transform: inherit;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: .15rem;
}

/* Los motivos de descarte, en rojo apagado: son informacion, no una alarma. */
.descartes { margin: .4rem 0 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: .2rem .9rem; }
.descartes li { font-size: .72rem; color: var(--critico-ink, #B32626); }

.orden:hover { color: var(--ink-1); }
.flecha { font-size: .7rem; }
.titulo { font-size: 1.35rem; font-weight: 600; letter-spacing: -.025em; margin: 0; }
.sub { font-family: var(--mono); font-size: .72rem; color: var(--ink-3); margin: .15rem 0 0; }

/*
 * `table-layout: fixed` y un reparto explicito.
 *
 * Por omision el navegador ensancha cada columna hasta que quepa su texto mas
 * largo, la tabla se pasa del ancho de la tarjeta y `.desliza` lo tapa con una
 * barra horizontal: el boton de la ultima columna queda fuera de la vista y
 * sobra espacio alrededor. Con el reparto fijo, las columnas se ajustan a lo
 * que hay y el texto que no quepa se recorta con puntos suspensivos.
 */
.tabla { width: 100%; border-collapse: collapse; font-size: .82rem; table-layout: fixed; }

.tabla td { overflow: hidden; text-overflow: ellipsis; }

.tabla th {
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-3);
    font-weight: 500;
    text-align: left;
    padding: .55rem .7rem;
    background: var(--superficie-2);
    border-bottom: 1px solid var(--linea);
    white-space: nowrap;
}

.tabla td { padding: .55rem .7rem; border-bottom: 1px solid var(--linea); vertical-align: middle; white-space: nowrap; }
.tabla tbody tr:hover { background: var(--superficie-2); }

.ref { color: var(--ink-2); }
.puntaje { color: var(--acento-ink); font-weight: 600; margin-left: .3rem; }
.sin-candidato { color: var(--atencion); font-size: .78rem; }

.holgura--critica { color: var(--critico); font-weight: 600; }
.holgura--atencion { color: var(--atencion); font-weight: 600; }

.panel {
    background: var(--superficie);
    border: 1px solid var(--acento);
    border-radius: var(--r-md);
    padding: 1rem;
    box-shadow: var(--sombra);
}

.panel-cab { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: .8rem; }
.panel-titulo { font-size: 1.05rem; margin: 0; font-weight: 600; }
.bloque-cadena {
    padding: .9rem 0;
    border-bottom: 1px solid var(--linea);
    margin-bottom: .4rem;
}

.panel-sub { font-size: .8rem; color: var(--ink-3); margin: .15rem 0 0; }

.cerrar { border: none; background: none; font-size: 1.1rem; color: var(--ink-3); min-width: var(--toque); min-height: 32px; }

.filtro { background: var(--superficie-2); border-radius: var(--r-sm); padding: .7rem .8rem; margin-bottom: .9rem; }
.filtro-titulo { font-size: .82rem; margin: 0 0 .4rem; color: var(--ink-2); }
.filtro-titulo strong { color: var(--acento-ink); }
.filtro ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .2rem 1.2rem; font-size: .76rem; color: var(--ink-3); }

.candidatos {
    list-style: none; margin: 0; padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: .7rem;
}

.candidato { border: 1.5px solid var(--linea); border-radius: var(--r-md); padding: .8rem; }
.candidato--top { border-color: var(--acento); background: var(--acento-suave); }

.candidato-cab { display: flex; justify-content: space-between; align-items: baseline; gap: .5rem; margin-bottom: .3rem; }
.candidato-cab div { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.candidato-puntaje { font-size: 1.2rem; font-weight: 700; color: var(--acento-ink); }
.candidato-meta { font-size: .72rem; color: var(--ink-3); margin: 0 0 .6rem; }

.desglose { list-style: none; margin: 0 0 .7rem; padding: 0; display: flex; flex-direction: column; gap: .25rem; }
.desglose li { display: grid; grid-template-columns: 68px 1fr 28px; align-items: center; gap: .4rem; font-size: .7rem; }
.factor { color: var(--ink-3); text-transform: capitalize; }
.barra { height: 6px; background: var(--superficie-3); border-radius: 3px; overflow: hidden; }
.relleno { display: block; height: 100%; background: var(--acento); border-radius: 3px; }
.valor { text-align: right; color: var(--ink-2); }

.motivo { margin-top: .9rem; }
.etiqueta { display: block; font-family: var(--mono); font-size: .62rem; letter-spacing: .1em; text-transform: uppercase; color: var(--ink-3); margin-bottom: .3rem; }
.motivo-nota { font-size: .74rem; color: var(--ink-3); margin: .4rem 0 0; line-height: 1.45; }

.campo { width: 100%; min-height: 40px; border: 1px solid var(--linea-fuerte); border-radius: var(--r-sm); background: var(--superficie); padding: .5rem .7rem; font-size: .85rem; }

.btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-height: 38px; padding: .45rem .9rem;
    border-radius: var(--r-sm); font-size: .84rem; font-weight: 600;
    border: 1px solid transparent; width: 100%;
}

.btn--pri { background: var(--acento); color: var(--sobre-acento); }
.btn--sec { background: var(--superficie); border-color: var(--linea-fuerte); color: var(--ink); }
.btn--mini { width: auto; min-height: 30px; padding: .25rem .7rem; font-size: .76rem; background: var(--acento-suave); color: var(--acento-ink); }

.aviso { padding: 1.2rem; text-align: center; color: var(--ink-3); font-size: .84rem; background: var(--superficie); border: 1px solid var(--linea); border-radius: var(--r-md); margin: 0; }
.aviso--error { color: var(--critico); background: var(--critico-suave); border-color: var(--critico); }
</style>
