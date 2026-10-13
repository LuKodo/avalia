/**
 * Utilidades de gráficos.
 *
 * Todo lo que aquí se calcula es geometría y formato: ninguna función decide
 * colores. Los colores salen de las variables CSS del sistema de diseño, de
 * modo que el cambio de tema claro/oscuro no obliga a recalcular nada.
 */

/** Escala lineal de un dominio de datos a un rango de píxeles. */
export function escalaLineal([d0, d1], [r0, r1]) {
    const span = d1 - d0 || 1
    const fn = (v) => r0 + ((v - d0) / span) * (r1 - r0)
    fn.invertir = (px) => d0 + ((px - r0) / (r1 - r0 || 1)) * span
    fn.dominio = [d0, d1]
    fn.rango = [r0, r1]
    return fn
}

/** Escala de banda para categorías (barras, celdas). */
export function escalaBanda(categorias, [r0, r1], separacion = 0.2) {
    const n = categorias.length || 1
    const paso = (r1 - r0) / n
    const ancho = paso * (1 - separacion)
    const fn = (cat) => {
        const i = categorias.indexOf(cat)
        return i < 0 ? r0 : r0 + i * paso + (paso - ancho) / 2
    }
    fn.ancho = ancho
    fn.paso = paso
    return fn
}

/**
 * Marcas de eje en valores que una persona reconoce: 1, 2, 5, 10, 20, 50…
 * Un eje que va de 0 a 37 en pasos de 7,4 es técnicamente correcto y no lo
 * lee nadie.
 */
export function ticksAgradables(min, max, deseados = 5) {
    if (min === max) return [min]

    const bruto = (max - min) / deseados
    const magnitud = Math.pow(10, Math.floor(Math.log10(bruto)))
    const norm = bruto / magnitud

    const paso = (norm >= 7.5 ? 10 : norm >= 3.5 ? 5 : norm >= 1.5 ? 2 : 1) * magnitud

    const inicio = Math.ceil(min / paso) * paso
    const salida = []

    for (let v = inicio; v <= max + paso * 0.001; v += paso) {
        salida.push(Number(v.toFixed(10)))
    }

    return salida
}

/**
 * Dominio con un poco de aire arriba y anclado en cero cuando tiene sentido.
 * Un gráfico de conteos que empieza en 40 exagera diferencias que no existen.
 */
export function dominioAgradable(valores, { desdeCero = true } = {}) {
    const limpios = valores.filter((v) => v !== null && v !== undefined && !Number.isNaN(v))
    if (limpios.length === 0) return [0, 1]

    const max = Math.max(...limpios)
    const min = desdeCero ? Math.min(0, ...limpios) : Math.min(...limpios)

    if (max === min) return [min, min + 1]

    const aire = (max - min) * 0.08
    return [desdeCero && min === 0 ? 0 : min - aire, max + aire]
}

/** Ruta de una polilínea, cortada en los huecos (null) en vez de inventarlos. */
export function rutaLinea(puntos) {
    let d = ''
    let abierto = false

    for (const p of puntos) {
        if (p.y === null || p.y === undefined || Number.isNaN(p.y)) {
            abierto = false
            continue
        }
        d += `${abierto ? 'L' : 'M'}${p.x.toFixed(2)},${p.y.toFixed(2)}`
        abierto = true
    }

    return d
}

/** Ruta de área bajo la línea, con el mismo tratamiento de huecos. */
export function rutaArea(puntos, base) {
    const tramos = []
    let actual = []

    for (const p of puntos) {
        if (p.y === null || p.y === undefined || Number.isNaN(p.y)) {
            if (actual.length) tramos.push(actual)
            actual = []
            continue
        }
        actual.push(p)
    }
    if (actual.length) tramos.push(actual)

    return tramos
        .filter((t) => t.length > 1)
        .map((t) => {
            const arriba = t.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(2)},${p.y.toFixed(2)}`).join('')
            return `${arriba}L${t[t.length - 1].x.toFixed(2)},${base}L${t[0].x.toFixed(2)},${base}Z`
        })
        .join('')
}

/** Arco de dona. Ángulos en radianes desde las 12 en punto. */
export function arco(cx, cy, radioExt, radioInt, desde, hasta) {
    const p = (r, a) => [cx + r * Math.sin(a), cy - r * Math.cos(a)]
    const grande = hasta - desde > Math.PI ? 1 : 0

    const [x1, y1] = p(radioExt, desde)
    const [x2, y2] = p(radioExt, hasta)
    const [x3, y3] = p(radioInt, hasta)
    const [x4, y4] = p(radioInt, desde)

    return [
        `M${x1.toFixed(2)},${y1.toFixed(2)}`,
        `A${radioExt},${radioExt} 0 ${grande} 1 ${x2.toFixed(2)},${y2.toFixed(2)}`,
        `L${x3.toFixed(2)},${y3.toFixed(2)}`,
        `A${radioInt},${radioInt} 0 ${grande} 0 ${x4.toFixed(2)},${y4.toFixed(2)}`,
        'Z',
    ].join('')
}

// ---------------------------------------------------------------- formato

const NUM = new Intl.NumberFormat('es-CO')
const NUM1 = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 })

export function numero(v) {
    return v === null || v === undefined ? '—' : NUM.format(v)
}

export function decimal(v, d = 1) {
    if (v === null || v === undefined) return '—'
    return d === 1 ? NUM1.format(v) : v.toFixed(d).replace('.', ',')
}

export function porcentaje(v, d = 1) {
    return v === null || v === undefined ? '—' : `${decimal(v, d)} %`
}

export function moneda(v) {
    if (v === null || v === undefined) return '—'
    return new Intl.NumberFormat('es-CO', {
        style: 'currency', currency: 'COP', maximumFractionDigits: 0,
    }).format(v)
}

const DIAS = ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom']

export function diaSemana(n) {
    return DIAS[n] ?? ''
}

/** Fecha corta para eje: «21 ago». Sin año, que en una serie de 30 días sobra. */
/**
 * Interpreta una fecha que puede venir de dos formas.
 *
 * Las series de las graficas traen solo el dia -«2026-09-24»- y hay que
 * anclarlas a medianoche LOCAL: sin la hora, el navegador las lee como UTC y
 * en Colombia se corren un dia hacia atras. Pero los registros traen la marca
 * completa -«2026-09-24T18:30:00Z»-, y pegarle otra hora a eso produce una
 * cadena imposible y un «Invalid Date» en pantalla.
 *
 * Distinguirlas por el largo no basta: se mira si ya trae hora.
 */
function comoFecha(iso) {
    if (iso === null || iso === undefined || iso === '') return null

    const texto = String(iso)
    const d = texto.includes('T') || texto.includes(' ')
        ? new Date(texto)
        : new Date(`${texto}T00:00:00`)

    return Number.isNaN(d.getTime()) ? null : d
}

export function fechaCorta(iso) {
    const d = comoFecha(iso)

    if (d === null) return '—'

    return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }).replace('.', '')
}

export function fechaLarga(iso) {
    const d = comoFecha(iso)

    if (d === null) return '—'

    return d.toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' })
}

export function hora(iso) {
    return new Date(iso).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })
}

/** Los ocho espacios categóricos, en orden fijo. Nunca se ciclan. */
export const SERIES = [
    'var(--serie-1)', 'var(--serie-2)', 'var(--serie-3)', 'var(--serie-4)',
    'var(--serie-5)', 'var(--serie-6)', 'var(--serie-7)', 'var(--serie-8)',
]

/**
 * Color de una serie por su posición. A partir del noveno no se genera un
 * matiz nuevo: se agrupa en «Otros». Un noveno color inventado no lo distingue
 * nadie y rompe la garantía de la paleta validada.
 */
export function colorSerie(i) {
    return SERIES[i] ?? 'var(--ink-3)'
}

/** Rampa secuencial de 7 pasos para magnitud continua (mapas de calor). */
export const SECUENCIAL = [
    'var(--sec-100)', 'var(--sec-200)', 'var(--sec-300)', 'var(--sec-400)',
    'var(--sec-500)', 'var(--sec-600)', 'var(--sec-700)',
]

export function pasoSecuencial(valor, maximo) {
    if (!maximo || valor <= 0) return 'var(--superficie-2)'
    const i = Math.min(SECUENCIAL.length - 1, Math.floor((valor / maximo) * SECUENCIAL.length))
    return SECUENCIAL[i]
}

/** Colores de estado. Reservados: nunca actúan como serie. */
export const ESTADO = {
    bien: 'var(--bien)',
    atencion: 'var(--atencion)',
    grave: 'var(--grave)',
    critico: 'var(--critico)',
    neutral: 'var(--ink-3)',
}

export function colorSemaforo(clave) {
    return ESTADO[clave] ?? ESTADO.neutral
}
