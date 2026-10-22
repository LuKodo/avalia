<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api/cliente'
import Hero from '../componentes/Hero.vue'
import { sesion } from '../sesion'

/**
 * Radicación masiva desde una hoja de cálculo.
 *
 * El cliente dispensa por lotes: la farmacia saca cuarenta SSC de un turno y
 * tener que teclearlos de uno en uno es la razón por la que acaban radicándose
 * tarde, o por la que alguien junta diez dispensaciones bajo una sola guía para
 * ahorrarse el trámite —y entonces la trazabilidad por SSC deja de existir—.
 *
 * **Una fila es un SSC.** Es la decisión que gobierna todo lo demás: lo que el
 * cliente tiene en su sistema es una lista de dispensaciones, no de cajas ni de
 * guías. Las columnas `guia` y `caja` son las que agrupan esas filas, y están
 * vacías en el caso normal —una dispensación, una caja, una guía—.
 *
 * El archivo se lee AQUÍ, en el navegador, y lo que viaja son guías ya armadas
 * contra la misma entrada que usa el formulario de una en una. Así no hay dos
 * caminos de validación que puedan separarse con el tiempo: lo que acepta la
 * radicación suelta es exactamente lo que acepta el lote.
 */
const catalogo = ref(null)
const cargando = ref(true)
const error = ref(null)

/* ------------------------------------------------------------- plantilla -- */

/**
 * Las columnas, en el orden en que se leen.
 *
 * `clave` es el nombre normalizado —sin tildes, en minúscula— con el que se
 * busca la columna en el archivo. El archivo del cliente puede traerlas en otro
 * orden o con mayúsculas, y eso no debería ser un error.
 */
const COLUMNAS = [
    { clave: 'referencia', titulo: 'referencia', ejemplo: 'SSC-100234', nota: 'Obligatoria. Una fila por SSC.' },
    { clave: 'guia', titulo: 'guia', ejemplo: '', nota: 'Vacío = una guía por fila. Mismo valor = misma guía.' },
    { clave: 'caja', titulo: 'caja', ejemplo: '', nota: 'Vacío = una caja por fila. Mismo valor = misma caja.' },
    { clave: 'sede_origen', titulo: 'sede_origen', ejemplo: 'Bodega Central Avalia', nota: 'Nombre exacto de la sede.' },
    { clave: 'sede_destino', titulo: 'sede_destino', ejemplo: 'Clínica del Norte · Farmacia', nota: 'O deje vacío y llene las columnas de domicilio.' },
    { clave: 'destinatario_nombre', titulo: 'destinatario_nombre', ejemplo: '', nota: 'Solo para domicilios.' },
    { clave: 'destinatario_direccion', titulo: 'destinatario_direccion', ejemplo: '', nota: 'Solo para domicilios.' },
    { clave: 'destinatario_ciudad', titulo: 'destinatario_ciudad', ejemplo: '', nota: 'Solo para domicilios.' },
    { clave: 'destinatario_telefono', titulo: 'destinatario_telefono', ejemplo: '', nota: 'Opcional.' },
    { clave: 'tipo_paquete', titulo: 'tipo_paquete', ejemplo: 'general', nota: 'general · cadena_frio · controlado' },
    { clave: 'empaque', titulo: 'empaque', ejemplo: 'caja', nota: 'bolsa · caja · panales' },
    { clave: 'alto_costo', titulo: 'alto_costo', ejemplo: 'no', nota: 'si · no' },
    { clave: 'criticidad', titulo: 'criticidad', ejemplo: 'programado', nota: 'programado · urgente' },
    { clave: 'peso_kg', titulo: 'peso_kg', ejemplo: '', nota: 'Opcional.' },
    { clave: 'observaciones', titulo: 'observaciones', ejemplo: '', nota: 'Opcional.' },
]

function normalizar(t) {
    return String(t ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '_')
}

async function descargarPlantilla() {
    const XLSX = await import('xlsx')

    const hoja = XLSX.utils.aoa_to_sheet([
        COLUMNAS.map((c) => c.titulo),
        COLUMNAS.map((c) => c.ejemplo),
    ])

    // Una segunda hoja con el significado de cada columna. Va aparte para que
    // la primera se pueda pegar tal cual desde el sistema del cliente.
    const ayuda = XLSX.utils.aoa_to_sheet([
        ['columna', 'qué va aquí'],
        ...COLUMNAS.map((c) => [c.titulo, c.nota]),
    ])

    const libro = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(libro, hoja, 'Guias')
    XLSX.utils.book_append_sheet(libro, ayuda, 'Instrucciones')

    XLSX.writeFile(libro, 'avalia-plantilla-radicacion.xlsx')
}

/* ----------------------------------------------------------------- lectura - */

const filas = ref([])
const nombreArchivo = ref(null)
const leyendo = ref(false)

async function alElegirArchivo(evento) {
    const archivo = evento.target.files?.[0]

    if (!archivo) return

    leyendo.value = true
    error.value = null
    resultados.value = []

    try {
        const XLSX = await import('xlsx')
        const libro = XLSX.read(await archivo.arrayBuffer(), { type: 'array' })
        const hoja = libro.Sheets[libro.SheetNames[0]]

        // `defval: ''` para que las celdas vacías lleguen como cadena y no
        // desaparezcan: una fila a la que le falta la referencia tiene que
        // poder señalarse, no colarse como fila corta.
        const crudas = XLSX.utils.sheet_to_json(hoja, { defval: '' })

        filas.value = crudas.map((fila, i) => {
            const limpia = {}

            for (const [clave, valor] of Object.entries(fila)) {
                limpia[normalizar(clave)] = String(valor ?? '').trim()
            }

            // +2: la primera fila del archivo son los títulos, y las hojas de
            // cálculo cuentan desde uno. El número que se muestra tiene que ser
            // el que la persona ve en Excel.
            return { ...limpia, _fila: i + 2 }
        })

        nombreArchivo.value = archivo.name
    } catch (e) {
        error.value = `No se pudo leer el archivo: ${e.message}`
        filas.value = []
    } finally {
        leyendo.value = false
        // Permite volver a elegir el MISMO archivo tras corregirlo: sin esto,
        // el navegador no dispara el evento la segunda vez.
        evento.target.value = ''
    }
}

/* -------------------------------------------------------------- validación - */

const cliente = computed(() => {
    const cs = catalogo.value?.clientes ?? []

    return clienteId.value
        ? cs.find((c) => c.id === clienteId.value)
        : cs[0]
})

const clienteId = ref(null)

function sedePorNombre(nombre) {
    const aguja = normalizar(nombre)

    if (!aguja) return null

    return (cliente.value?.sedes ?? []).find((s) => normalizar(s.nombre) === aguja) ?? null
}

const TIPOS = ['general', 'cadena_frio', 'controlado']
const EMPAQUES = ['bolsa', 'caja', 'panales']
const CRITICIDADES = ['programado', 'urgente']

function siNo(v) {
    return ['si', 'sí', 's', 'true', '1', 'x'].includes(normalizar(v))
}

/** Los problemas de una fila, en el idioma de quien llenó la hoja. */
function problemasDe(f) {
    const malas = []

    if (!f.referencia) malas.push('falta la referencia')

    if (!sedePorNombre(f.sede_origen)) {
        malas.push(f.sede_origen
            ? `no existe la sede de origen «${f.sede_origen}»`
            : 'falta la sede de origen')
    }

    const tieneDestinoSede = !!sedePorNombre(f.sede_destino)
    const tieneDomicilio = !!(f.destinatario_nombre && f.destinatario_direccion && f.destinatario_ciudad)

    if (!tieneDestinoSede && !tieneDomicilio) {
        malas.push(f.sede_destino
            ? `no existe la sede de destino «${f.sede_destino}»`
            : 'falta el destino: una sede, o nombre + dirección + ciudad del domicilio')
    }

    if (tieneDestinoSede && tieneDomicilio) {
        malas.push('trae sede de destino y domicilio: deje solo uno')
    }

    if (f.tipo_paquete && !TIPOS.includes(normalizar(f.tipo_paquete))) {
        malas.push(`tipo_paquete «${f.tipo_paquete}» no es ${TIPOS.join(', ')}`)
    }

    if (f.empaque && !EMPAQUES.includes(normalizar(f.empaque))) {
        malas.push(`empaque «${f.empaque}» no es ${EMPAQUES.join(', ')}`)
    }

    if (f.criticidad && !CRITICIDADES.includes(normalizar(f.criticidad))) {
        malas.push(`criticidad «${f.criticidad}» no es ${CRITICIDADES.join(', ')}`)
    }

    if (f.peso_kg && Number.isNaN(Number(f.peso_kg.replace(',', '.')))) {
        malas.push(`peso_kg «${f.peso_kg}» no es un número`)
    }

    return malas
}

const revisadas = computed(() => filas.value.map((f) => ({ ...f, problemas: problemasDe(f) })))
const conProblema = computed(() => revisadas.value.filter((f) => f.problemas.length > 0))
const validas = computed(() => revisadas.value.filter((f) => f.problemas.length === 0))

/* --------------------------------------------------------------- agrupación */

/**
 * Las filas válidas, convertidas en guías.
 *
 * Dos niveles de agrupación y los dos opcionales: `guia` junta filas en un
 * mismo envío, `caja` las junta dentro de ese envío en un mismo bulto. Sin
 * ninguno de los dos, cada fila es su propia guía con su propia caja, que es
 * el caso normal.
 *
 * La clave de agrupación incluye el destino: dos filas con la misma etiqueta de
 * guía pero distinto destino no pueden ser el mismo envío, y juntarlas en
 * silencio produciría una guía que va a un sitio en el que la mitad de las
 * dispensaciones no se esperan.
 */
const guias = computed(() => {
    const mapa = new Map()

    validas.value.forEach((f, i) => {
        const destino = sedePorNombre(f.sede_destino)
        const claveDestino = destino ? `s${destino.id}` : `d${normalizar(f.destinatario_direccion)}`
        const claveGuia = f.guia ? `g:${normalizar(f.guia)}|${claveDestino}` : `fila:${i}`

        if (!mapa.has(claveGuia)) {
            mapa.set(claveGuia, {
                clave: claveGuia,
                etiqueta: f.guia || f.referencia,
                origen: sedePorNombre(f.sede_origen),
                destino,
                domicilio: destino ? null : {
                    nombre: f.destinatario_nombre,
                    direccion: f.destinatario_direccion,
                    ciudad: f.destinatario_ciudad,
                    telefono: f.destinatario_telefono || null,
                },
                criticidad: normalizar(f.criticidad) || 'programado',
                observaciones: f.observaciones || null,
                cajas: new Map(),
            })
        }

        const g = mapa.get(claveGuia)

        /*
         * La urgencia la manda la fila mas exigente, no la primera.
         *
         * Si de las tres dispensaciones de una guia una es urgente, la guia es
         * urgente: el viaje es uno solo y tiene que salir cuando lo necesita la
         * que no puede esperar. Tomar la de la primera fila habria degradado a
         * programada una urgente por el orden en que estaban en la hoja.
         */
        if (normalizar(f.criticidad) === 'urgente') g.criticidad = 'urgente'

        const claveCaja = f.caja ? `c:${normalizar(f.caja)}` : `fila:${i}`

        if (!g.cajas.has(claveCaja)) {
            g.cajas.set(claveCaja, {
                tipo: normalizar(f.tipo_paquete) || 'general',
                empaque: normalizar(f.empaque) || 'caja',
                alto_costo: siNo(f.alto_costo),
                peso_kg: f.peso_kg ? Number(f.peso_kg.replace(',', '.')) : null,
                referencias: [],
            })
        }

        /*
         * Alto costo, igual que la urgencia: basta una.
         *
         * El tipo y el empaque los define la primera fila porque una caja solo
         * puede ser de una forma, pero «lleva algo de alto costo» es una
         * propiedad de lo que hay dentro: si una de las cuatro dispensaciones
         * de la caja es un oncologico, la caja entera lo es. Tomar el valor de
         * la primera fila dejaba sin marca cajas que si la necesitaban.
         */
        if (siNo(f.alto_costo)) g.cajas.get(claveCaja).alto_costo = true

        g.cajas.get(claveCaja).referencias.push({
            tipo: cliente.value?.tipo_referencia ?? 'otro',
            valor: f.referencia,
        })
    })

    return [...mapa.values()].map((g) => ({ ...g, cajas: [...g.cajas.values()] }))
})

/** El cuerpo que espera POST /ordenes, desde una guía ya agrupada. */
function cuerpoDe(g) {
    return {
        ...(catalogo.value?.puede_elegir_cliente ? { cliente_id: cliente.value?.id } : {}),
        sede_origen_id: g.origen.id,
        ...(g.destino
            ? { sede_destino_id: g.destino.id }
            : { destinatario: g.domicilio }),
        criticidad: g.criticidad,
        observaciones: g.observaciones,
        paquetes: g.cajas.map((c) => ({
            tipo: c.tipo,
            empaque: c.empaque,
            alto_costo: c.alto_costo,
            ...(c.peso_kg ? { peso_kg: c.peso_kg } : {}),
            referencias: c.referencias,
        })),
    }
}

/* ------------------------------------------------------------------- envío - */

const enviando = ref(false)
const progreso = ref(0)
const resultados = ref([])

async function radicar() {
    if (guias.value.length === 0) return

    enviando.value = true
    progreso.value = 0
    resultados.value = []
    error.value = null

    /*
     * Una guía por petición, en serie y sin cortar al primer fallo.
     *
     * En serie porque el número de guía es consecutivo y treinta peticiones a
     * la vez son treinta oportunidades de pelearse por el mismo. Sin cortar
     * porque lo que el cliente necesita saber al final es qué entró y qué no,
     * no en qué fila se paró todo: las que fallan se corrigen y se vuelven a
     * subir, y las buenas ya están dentro.
     */
    for (const g of guias.value) {
        try {
            const r = await api.crearOrden(cuerpoDe(g))

            resultados.value.push({
                etiqueta: g.etiqueta,
                ok: true,
                guia: r.guia_avalia ?? '—',
                cajas: g.cajas.length,
                referencias: g.cajas.reduce((a, c) => a + c.referencias.length, 0),
            })
        } catch (e) {
            resultados.value.push({
                etiqueta: g.etiqueta,
                ok: false,
                motivo: e.cuerpo?.message ?? e.message,
            })
        }

        progreso.value += 1
    }

    enviando.value = false
}

const radicadas = computed(() => resultados.value.filter((r) => r.ok))
const fallidas = computed(() => resultados.value.filter((r) => !r.ok))

onMounted(async () => {
    try {
        catalogo.value = await api.catalogoRadicar()
        clienteId.value = catalogo.value.clientes[0]?.id ?? null
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
})

const puedeRadicar = computed(() => sesion.tiene('cliente', 'cliente_radicador', 'operador', 'administrador'))
</script>

<template>
    <section class="pagina">
        <Hero
            rotulo="Mis envíos"
            titulo="Radicación masiva"
            descripcion="Una fila del archivo es un SSC. Las columnas «guia» y «caja» agrupan filas cuando varias dispensaciones viajan juntas; déjelas vacías y cada fila será su propia guía."
        />

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-if="cargando" class="aviso">Cargando el catálogo…</p>

        <template v-else-if="puedeRadicar">
            <!-- ------------------------------------------------- paso 1 -->
            <section class="paso">
                <h2 class="paso-tit"><span class="paso-n">1</span> La plantilla</h2>
                <p class="paso-txt">
                    Trae los títulos que el lector reconoce y una segunda hoja con el
                    significado de cada columna. El orden de las columnas no importa.
                </p>

                <button type="button" class="btn btn--sec" @click="descargarPlantilla">
                    ⤓ Descargar plantilla (.xlsx)
                </button>
            </section>

            <!-- ------------------------------------------------- paso 2 -->
            <section class="paso">
                <h2 class="paso-tit"><span class="paso-n">2</span> El archivo</h2>

                <label
                    v-if="catalogo.puede_elegir_cliente"
                    class="rotulado"
                >
                    <span>Radicar a nombre de</span>
                    <select v-model="clienteId" class="control">
                        <option v-for="c in catalogo.clientes" :key="c.id" :value="c.id">
                            {{ c.razon_social }}
                        </option>
                    </select>
                </label>

                <input
                    type="file" class="archivo"
                    accept=".xlsx,.xls,.csv"
                    :disabled="leyendo"
                    @change="alElegirArchivo"
                >

                <p v-if="nombreArchivo" class="paso-txt">
                    <strong>{{ nombreArchivo }}</strong> · {{ filas.length }} fila(s) leída(s)
                </p>
            </section>

            <!-- ------------------------------------------------- paso 3 -->
            <section v-if="filas.length" class="paso">
                <h2 class="paso-tit"><span class="paso-n">3</span> Revisión</h2>

                <div class="cifras">
                    <div class="cifra">
                        <p class="cifra-n">{{ validas.length }}</p>
                        <p class="cifra-t">filas correctas</p>
                    </div>
                    <div class="cifra" :class="{ 'cifra--mal': conProblema.length }">
                        <p class="cifra-n">{{ conProblema.length }}</p>
                        <p class="cifra-t">con problemas</p>
                    </div>
                    <div class="cifra">
                        <p class="cifra-n">{{ guias.length }}</p>
                        <p class="cifra-t">guías a crear</p>
                    </div>
                </div>

                <!--
                    Los problemas van antes que el resumen de lo que saldrá bien.
                    Una lista de errores escondida bajo un «todo listo» es como
                    se radican lotes a los que les falta un tercio.
                -->
                <div v-if="conProblema.length" class="malas">
                    <p class="malas-tit">
                        Estas filas no se van a radicar. Corríjalas en el archivo y vuelva a subirlo.
                    </p>
                    <ul>
                        <li v-for="f in conProblema.slice(0, 50)" :key="f._fila">
                            <span class="num">Fila {{ f._fila }}</span>
                            <span v-if="f.referencia" class="num tenue">· {{ f.referencia }}</span>
                            — {{ f.problemas.join('; ') }}
                        </li>
                    </ul>
                    <p v-if="conProblema.length > 50" class="tenue">
                        y {{ conProblema.length - 50 }} más.
                    </p>
                </div>

                <details v-if="guias.length" class="previo">
                    <summary>Ver las {{ guias.length }} guías que se van a crear</summary>
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Agrupada como</th><th>Origen</th><th>Destino</th>
                                <th class="num">Cajas</th><th class="num">SSC</th><th>Urgencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="g in guias.slice(0, 200)" :key="g.clave">
                                <td class="num">{{ g.etiqueta }}</td>
                                <td class="tenue">{{ g.origen.nombre }}</td>
                                <td class="tenue">{{ g.destino?.nombre ?? `${g.domicilio.nombre} · ${g.domicilio.direccion}` }}</td>
                                <td class="num">{{ g.cajas.length }}</td>
                                <td class="num">{{ g.cajas.reduce((a, c) => a + c.referencias.length, 0) }}</td>
                                <td>
                                    <span class="pastilla" :class="g.criticidad === 'urgente' ? 'pastilla--critico' : 'pastilla--neutral'">
                                        {{ g.criticidad === 'urgente' ? 'Urgente' : 'Programado' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </details>
            </section>

            <!-- ------------------------------------------------- paso 4 -->
            <section v-if="guias.length" class="paso">
                <h2 class="paso-tit"><span class="paso-n">4</span> Radicar</h2>

                <p v-if="enviando" class="paso-txt">
                    Radicando {{ progreso }} de {{ guias.length }}…
                </p>

                <button
                    type="button" class="btn btn--pri"
                    :disabled="enviando" @click="radicar"
                >
                    {{ enviando ? 'Radicando…' : `Radicar ${guias.length} guía(s)` }}
                </button>

                <div v-if="resultados.length" class="resultado">
                    <p class="resultado-tit">
                        {{ radicadas.length }} radicada(s)<template v-if="fallidas.length">,
                        {{ fallidas.length }} sin radicar</template>.
                    </p>

                    <ul class="lista-res">
                        <li v-for="(r, i) in resultados" :key="i" :class="{ mal: !r.ok }">
                            <template v-if="r.ok">
                                <span class="num">{{ r.guia }}</span>
                                — {{ r.cajas }} caja(s), {{ r.referencias }} SSC
                            </template>
                            <template v-else>
                                <span class="num">{{ r.etiqueta }}</span> — {{ r.motivo }}
                            </template>
                        </li>
                    </ul>
                </div>
            </section>
        </template>

        <p v-else class="aviso">Su perfil no radica envíos.</p>
    </section>
</template>

<style scoped>
.pagina { display: flex; flex-direction: column; gap: 1rem; }

.paso {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .7rem;
    padding: 1.1rem 1.2rem;
    border: 1px solid var(--linea);
    border-radius: 12px;
    background: var(--superficie);
}

.paso-tit {
    display: flex;
    align-items: center;
    gap: .55rem;
    margin: 0;
    font-size: 1rem;
}

.paso-n {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.6rem;
    height: 1.6rem;
    border-radius: 50%;
    background: var(--acento);
    color: var(--sobre-acento);
    font-size: .8rem;
    font-weight: 700;
}

.paso-txt {
    margin: 0;
    font-size: .86rem;
    line-height: 1.55;
    color: var(--ink-3);
}

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

.control {
    min-height: 42px;
    border: 1px solid var(--linea-fuerte);
    border-radius: 8px;
    padding: 0 .6rem;
    font: inherit;
    font-size: .88rem;
    text-transform: none;
    letter-spacing: 0;
    color: var(--ink);
    background: var(--superficie);
}

.archivo { font: inherit; font-size: .86rem; }

.cifras { display: flex; flex-wrap: wrap; gap: 1.4rem; }

.cifra-n {
    margin: 0;
    font-size: 1.7rem;
    font-weight: 700;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    color: var(--ink);
}

.cifra--mal .cifra-n { color: var(--critico); }
.cifra-t { margin: .2rem 0 0; font-size: .78rem; color: var(--ink-3); }

.malas {
    width: 100%;
    padding: .75rem .9rem;
    border-left: 3px solid var(--critico);
    border-radius: 0 8px 8px 0;
    background: var(--superficie-2);
}

.malas-tit { margin: 0; font-size: .85rem; font-weight: 600; color: var(--ink); }

.malas ul { margin: .5rem 0 0; padding-left: 1.1rem; }

.malas li { font-size: .82rem; line-height: 1.65; color: var(--ink-2); }

.previo { width: 100%; font-size: .85rem; color: var(--ink-3); }
.previo summary { cursor: pointer; min-height: 36px; }
.previo .tabla { margin-top: .6rem; }

/* La vista previa puede ser ancha; se desplaza ella, no la página. */
.previo { overflow-x: auto; }

.tabla { width: 100%; border-collapse: collapse; font-size: .82rem; }
.tabla th { text-align: left; font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-3); padding: .35rem .5rem; border-bottom: 1px solid var(--linea); }
.tabla td { padding: .38rem .5rem; border-bottom: 1px solid var(--linea); color: var(--ink-2); }
.tabla .num { font-variant-numeric: tabular-nums; }

.resultado { width: 100%; }
.resultado-tit { margin: 0 0 .4rem; font-size: .9rem; font-weight: 600; color: var(--ink); }

.lista-res { margin: 0; padding-left: 1.1rem; }
.lista-res li { font-size: .83rem; line-height: 1.7; color: var(--ink-2); }
.lista-res li.mal { color: var(--critico); }

.tenue { color: var(--ink-3); }
</style>
