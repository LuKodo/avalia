import { bd, encolar } from './almacen'

/**
 * Vigilancia de posición del mensajero.
 *
 * Es la pieza que faltaba para que el mapa de flota tenga datos reales. El
 * servidor ya aceptaba lotes de posiciones y la bandeja de salida ya contemplaba
 * el tipo `posiciones`; lo que no existía era nadie que mirara el GPS. La única
 * llamada a geolocalización de toda la aplicación era una toma suelta al abrir
 * la pantalla de entrega.
 *
 * Cómo funciona: `watchPosition` deja el receptor encendido y el navegador avisa
 * cuando la posición cambia. Las lecturas se acumulan en memoria y se vuelcan a
 * la cola cada `INTERVALO_VOLCADO`; de la cola las saca el sincronizador, que ya
 * sabe reintentar con espera creciente.
 *
 * ---------------------------------------------------------------------------
 * Por qué las posiciones NO se tratan como el resto de la bandeja
 *
 * Una entrega no se puede perder jamás: es prueba, y se reintenta hasta que
 * entre. Una posición caduca. Un punto de hace seis horas no le sirve a nadie, y
 * son muchos: a un punto cada 20 segundos, una jornada de ocho horas son 1 440
 * por mensajero.
 *
 * Por eso aquí hay tope y caducidad, cosa que la bandeja no tiene para nada más.
 * Si el mensajero pasa el día sin cobertura, al reconectar deben llegar sus
 * entregas íntegras y un rastro razonable — no mil cuatrocientos puntos rancios
 * disputándole el ancho de banda a las entregas.
 * ---------------------------------------------------------------------------
 */

/** Cada cuánto se vuelca lo acumulado a la cola. */
const INTERVALO_VOLCADO = 60_000

/** O antes, si se juntan estos. El endpoint admite hasta 500 por envío. */
const MAXIMO_POR_LOTE = 40

/**
 * Cuánto tiene que moverse para registrar un punto nuevo.
 *
 * Sin este filtro, un mensajero parado en un semáforo genera decenas de puntos
 * idénticos que solo sirven para engordar la cola. El GPS urbano tiene un error
 * de 20-50 m en calle cerrada, así que por debajo de 25 m lo que se registraría
 * es ruido del receptor, no movimiento del vehículo.
 */
const MINIMO_METROS = 25

/** Ni aunque no se mueva: un punto cada tanto confirma que sigue vivo. */
const MAXIMO_SILENCIO = 180_000

/** Tope de la cola de posiciones. Pasado esto se tiran las más viejas. */
const TOPE_EN_COLA = 600

/** Más viejas que esto no se envían: ya no informan de nada. */
const CADUCIDAD_HORAS = 6

let vigilanteId = null
let temporizador = null
let acumuladas = []
let ultima = null

export const estadoGps = {
    activo: false,
    ultimoError: null,
    ultimaLectura: null,
}

/** Distancia aproximada en metros. Suficiente para decidir si hubo movimiento. */
function metrosEntre(a, b) {
    const R = 6_371_000
    const dLat = ((b.latitud - a.latitud) * Math.PI) / 180
    const dLon = ((b.longitud - a.longitud) * Math.PI) / 180
    const lat = ((a.latitud + b.latitud) / 2) * (Math.PI / 180)

    const x = dLon * Math.cos(lat)

    return Math.sqrt(dLat * dLat + x * x) * R
}

function alRecibir(p) {
    const punto = {
        latitud: p.coords.latitude,
        longitud: p.coords.longitude,
        precision_m: Number.isFinite(p.coords.accuracy) ? Math.round(p.coords.accuracy) : null,
        // El navegador da m/s; el servidor espera km/h en entero.
        velocidad_kmh: Number.isFinite(p.coords.speed) && p.coords.speed >= 0
            ? Math.round(p.coords.speed * 3.6)
            : null,
        registrado_en: new Date(p.timestamp).toISOString(),
    }

    estadoGps.ultimaLectura = punto.registrado_en
    estadoGps.ultimoError = null

    // Una lectura con error mayor que el propio umbral de movimiento no puede
    // distinguir estar quieto de haberse movido: se descarta.
    if (punto.precision_m !== null && punto.precision_m > 150) {
        return
    }

    if (ultima !== null) {
        const movio = metrosEntre(ultima, punto) >= MINIMO_METROS
        const callado = Date.parse(punto.registrado_en) - Date.parse(ultima.registrado_en) >= MAXIMO_SILENCIO

        if (! movio && ! callado) {
            return
        }
    }

    ultima = punto
    acumuladas.push(punto)

    if (acumuladas.length >= MAXIMO_POR_LOTE) {
        volcar()
    }
}

function alFallar(e) {
    // Códigos: 1 permiso denegado, 2 sin señal, 3 tiempo agotado. Solo el
    // primero es definitivo; los otros dos son la vida normal en ruta.
    estadoGps.ultimoError = e.code === 1
        ? 'Permiso de ubicación denegado'
        : 'Sin señal de GPS'

    if (e.code === 1) {
        detenerRastreo()
    }
}

/** Pasa lo acumulado a la bandeja de salida. */
export async function volcar() {
    if (acumuladas.length === 0) {
        return
    }

    const lote = acumuladas
    acumuladas = []

    // El nivel de batería viaja con el lote: un teléfono al 4 % es un paquete a
    // punto de quedarse sin trazabilidad, y el operador debe poder verlo venir.
    let bateria = null

    try {
        const b = await navigator.getBattery?.()
        if (b) bateria = Math.round(b.level * 100)
    } catch {
        // No todos los navegadores la exponen. No es motivo para perder el lote.
    }

    try {
        await podar()
        // `paquete_id` nulo: un lote de posiciones es de la jornada, no de un
        // paquete. El sincronizador ya se salta la subida de evidencias para
        // este tipo justamente por eso.
        await encolar('posiciones', null, { posiciones: lote, bateria })
    } catch (e) {
        // Si la cola falla, se devuelven al acumulador antes que perderlas.
        acumuladas = lote.concat(acumuladas)
        estadoGps.ultimoError = e.message
    }
}

/**
 * Tira lo que ya no informa: lo caducado primero, y si aún sobra, lo más viejo.
 *
 * Solo toca registros de tipo `posiciones`. Una entrega jamás se descarta por
 * antigüedad ni por volumen.
 */
async function podar() {
    const corte = new Date(Date.now() - CADUCIDAD_HORAS * 3_600_000).toISOString()

    await bd.bandeja
        .where('estado').anyOf('pendiente', 'reintentando')
        .filter((r) => r.tipo === 'posiciones' && r.creado_en < corte)
        .delete()

    const vivas = await bd.bandeja
        .where('estado').anyOf('pendiente', 'reintentando')
        .filter((r) => r.tipo === 'posiciones')
        .toArray()

    if (vivas.length <= TOPE_EN_COLA) {
        return
    }

    const sobran = vivas
        .sort((a, b) => a.creado_en.localeCompare(b.creado_en))
        .slice(0, vivas.length - TOPE_EN_COLA)
        .map((r) => r.id)

    await bd.bandeja.bulkDelete(sobran)
}

/**
 * Enciende la vigilancia. Idempotente: llamarla dos veces no abre dos
 * receptores, que es la forma clásica de duplicar el consumo de batería.
 */
export function iniciarRastreo() {
    if (vigilanteId !== null || ! navigator.geolocation) {
        if (! navigator.geolocation) {
            estadoGps.ultimoError = 'Este dispositivo no expone geolocalización'
        }

        return
    }

    vigilanteId = navigator.geolocation.watchPosition(alRecibir, alFallar, {
        enableHighAccuracy: true,
        // Una posición de hasta medio minuto vale: pedir siempre una lectura
        // fresca mantiene el receptor a plena potencia y funde la batería.
        maximumAge: 30_000,
        timeout: 25_000,
    })

    temporizador = setInterval(volcar, INTERVALO_VOLCADO)
    estadoGps.activo = true

    // Al cerrar o esconder la aplicación se vuelca lo que quede: en móvil el
    // navegador puede descartar la pestaña sin avisar, y lo que esté solo en
    // memoria se pierde. `visibilitychange` es el único evento que los
    // navegadores móviles garantizan; `beforeunload` no se dispara en iOS.
    document.addEventListener('visibilitychange', alEsconder)
}

function alEsconder() {
    if (document.visibilityState === 'hidden') {
        volcar()
    }
}

export function detenerRastreo() {
    if (vigilanteId !== null) {
        navigator.geolocation.clearWatch(vigilanteId)
        vigilanteId = null
    }

    clearInterval(temporizador)
    temporizador = null

    document.removeEventListener('visibilitychange', alEsconder)

    estadoGps.activo = false

    // Lo pendiente se vuelca al apagar: son los últimos puntos del turno.
    volcar()
}
