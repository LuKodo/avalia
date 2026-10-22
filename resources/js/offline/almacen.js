import Dexie from 'dexie'

/**
 * Almacén local del dispositivo.
 *
 * La aplicación de campo escribe SIEMPRE aquí primero y responde de inmediato;
 * la sincronización ocurre después, por detrás. El mensajero nunca espera a la
 * red para completar una entrega, y eso no es una optimización: los sótanos de
 * hospital y los depósitos de farmacia sencillamente no tienen cobertura.
 *
 * Tres tablas con trabajos distintos:
 *
 *   · `tareas`   — copia local de la jornada, para abrir sin señal.
 *   · `bandeja`  — cola de salida: lo que hizo el mensajero y aún no confirma
 *                  el servidor. Es el activo más valioso del dispositivo.
 *   · `archivos` — evidencias pendientes de subir, en cola aparte y con menor
 *                  prioridad que los eventos de estado.
 */
export const bd = new Dexie('ltp')

bd.version(1).stores({
    tareas: 'paquete_id, estado, actualizado_en',
    bandeja: '++id, estado, creado_en, evento_id',
    archivos: 'id, paquete_id, subido',
    meta: 'clave',
})

/**
 * UUID v7: único y ordenable por tiempo. Se genera en el dispositivo para no
 * tener que pedirle un identificador al servidor —que es justo lo que no se
 * puede hacer sin señal— y para que el reintento de un envío perdido lleve el
 * mismo identificador y el servidor lo reconozca como duplicado.
 */
export function uuid7() {
    const ms = BigInt(Date.now())
    const bytes = new Uint8Array(16)

    crypto.getRandomValues(bytes)

    bytes[0] = Number((ms >> 40n) & 0xffn)
    bytes[1] = Number((ms >> 32n) & 0xffn)
    bytes[2] = Number((ms >> 24n) & 0xffn)
    bytes[3] = Number((ms >> 16n) & 0xffn)
    bytes[4] = Number((ms >> 8n) & 0xffn)
    bytes[5] = Number(ms & 0xffn)

    bytes[6] = (bytes[6] & 0x0f) | 0x70   // versión 7
    bytes[8] = (bytes[8] & 0x3f) | 0x80   // variante RFC 4122

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('')

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/** Encola una acción de campo. Devuelve el identificador del evento. */
export async function encolar(tipo, paqueteId, carga) {
    const eventoId = carga.evento_id ?? uuid7()

    await bd.bandeja.add({
        tipo,                       // recolectar | entregar | novedad | posiciones
        paquete_id: paqueteId,
        evento_id: eventoId,
        carga: { ...carga, evento_id: eventoId },
        estado: 'pendiente',
        intentos: 0,
        creado_en: new Date().toISOString(),
        ultimo_error: null,
    })

    return eventoId
}

/**
 * Encola una evidencia con su archivo.
 *
 * El archivo se guarda como Blob en IndexedDB, no como base64: base64 infla el
 * peso un 33 % y hay que decodificarlo entero en memoria para subirlo. Un
 * teléfono de gama media con quince fotos pendientes lo nota.
 *
 * Van en cola aparte y con menor prioridad que los eventos de estado: el
 * paquete debe quedar entregado aunque la foto tarde una hora en subir.
 */
export async function encolarEvidencia(paqueteId, tipo, blob, capturadaEn = null) {
    const id = uuid7()

    await bd.archivos.add({
        id,
        paquete_id: paqueteId,
        tipo,
        blob,
        bytes: blob.size,
        mime: blob.type,
        capturada_en: capturadaEn ?? new Date().toISOString(),
        subido: 0,
        intentos: 0,
        ultimo_error: null,
    })

    return id
}

export function evidenciasPendientes() {
    return bd.archivos.where('subido').equals(0).toArray()
}

export function contarEvidenciasPendientes() {
    return bd.archivos.where('subido').equals(0).count()
}

export async function marcarEvidenciaSubida(id) {
    // El Blob se descarta al confirmar: ya está en el servidor y guardarlo dos
    // veces llena el almacenamiento del teléfono sin ganar nada.
    await bd.archivos.update(id, { subido: 1, blob: null, subido_en: new Date().toISOString() })
}

export async function marcarEvidenciaFallida(id, intentos, motivo) {
    await bd.archivos.update(id, { intentos, ultimo_error: motivo })
}

export function pendientes() {
    return bd.bandeja.where('estado').anyOf('pendiente', 'reintentando').toArray()
}

export function contarPendientes() {
    return bd.bandeja.where('estado').anyOf('pendiente', 'reintentando').count()
}

export async function marcarEnviado(id) {
    await bd.bandeja.update(id, { estado: 'enviado', enviado_en: new Date().toISOString() })
}

/**
 * Un rechazo definitivo del servidor —una regla de negocio, no un fallo de
 * red— no se reintenta, pero tampoco se borra en silencio: queda como
 * `rechazado` para que el mensajero y el operador lo vean. Nada desaparece sin
 * que alguien se entere.
 */
export async function marcarRechazado(id, motivo) {
    await bd.bandeja.update(id, { estado: 'rechazado', ultimo_error: motivo })
}

/**
 * Lo que el servidor rechazó y sigue guardado aquí.
 *
 * Existe para que el rechazo no sea el final del camino. Algunos son
 * definitivos de verdad —un código que no corresponde al paquete— y otros no
 * lo eran en absoluto, como la sesión caducada que descartó una entrega ya
 * hecha. Mientras el dato siga en el teléfono se puede volver a intentar.
 */
export function rechazados() {
    return bd.bandeja.where('estado').equals('rechazado').toArray()
}

/** Devuelve un rechazado a la cola, con la cuenta de intentos a cero. */
export async function reencolar(id) {
    await bd.bandeja.update(id, {
        estado: 'pendiente',
        intentos: 0,
        proximo_en: null,
        ultimo_error: null,
    })
}

export async function reprogramar(id, intentos, motivo) {
    await bd.bandeja.update(id, {
        estado: 'reintentando',
        intentos,
        ultimo_error: motivo,
        proximo_en: new Date(Date.now() + Math.min(300, 2 ** intentos) * 1000).toISOString(),
    })
}

/**
 * Refresca la copia local con lo que dice el servidor, SIN pisar lo que este
 * teléfono ya hizo y aún no ha confirmado.
 *
 * Sin esta salvedad aparecía el peor fallo posible en campo: el mensajero
 * entregaba, el registro quedaba en la cola, y la siguiente lectura del
 * servidor —que todavía no se había enterado— devolvía la tarea como
 * pendiente. La tarjeta volvía a decir «Entregar» con el botón activo y la
 * misma entrega se registraba dos veces.
 *
 * La regla es: mientras ese paquete tenga algo en la bandeja de salida, manda
 * el dispositivo. Es quien tiene el dato más nuevo.
 */
export async function guardarTareas(tareas) {
    const enCola = new Set(await paquetesEnCola())
    const previas = new Map((await bd.tareas.toArray()).map((t) => [String(t.paquete_id), t]))
    const ahora = new Date().toISOString()

    await bd.transaction('rw', bd.tareas, async () => {
        await bd.tareas.clear()
        await bd.tareas.bulkPut(tareas.map((t) => {
            const previa = previas.get(String(t.paquete_id))

            /*
             * El orden manual del mensajero SOBREVIVE a la recarga.
             *
             * Es suyo y es del día: lo arma al cargar el vehículo y lo va
             * cambiando en la calle. Que una sincronización —que ocurre cada
             * treinta segundos— se lo deshiciera volvería la función inútil.
             * Las tareas nuevas entran sin orden y la lista las pone al final.
             */
            const orden = previa?.orden ?? null

            if (enCola.has(String(t.paquete_id)) && previa) {
                return {
                    ...t,
                    estado: previa.estado,
                    orden,
                    sincronizando: true,
                    actualizado_en: ahora,
                }
            }

            return { ...t, orden, sincronizando: false, actualizado_en: ahora }
        }))
    })
}

export function tareasLocales() {
    return bd.tareas.toArray()
}

/**
 * Guarda el orden en que el mensajero quiere hacer sus tareas.
 *
 * Vive solo en el dispositivo a propósito. No es una decisión de la operación
 * —la asignación ya la tomó alguien— sino la forma en que esta persona va a
 * recorrer su día: por dónde le queda de paso, dónde almuerza, qué portería
 * cierra a mediodía. Subirlo al servidor obligaría a inventar qué pasa cuando
 * dos pantallas lo cambian a la vez, para resolver un problema que nadie tiene.
 *
 * @param  {Array<string|number>}  ids  los paquetes en el orden deseado
 */
export async function guardarOrdenManual(ids) {
    const posicion = new Map(ids.map((id, i) => [String(id), i]))

    await bd.transaction('rw', bd.tareas, async () => {
        const todas = await bd.tareas.toArray()

        await bd.tareas.bulkPut(todas.map((t) => ({
            ...t,
            orden: posicion.get(String(t.paquete_id)) ?? t.orden ?? null,
        })))
    })
}

/** Paquetes con alguna acción esperando salir. Las posiciones GPS no cuentan. */
export async function paquetesEnCola() {
    const filas = await bd.bandeja.where('estado').equals('pendiente').toArray()

    return [...new Set(
        filas.filter((r) => r.tipo !== 'posiciones').map((r) => String(r.paquete_id))
    )]
}

/**
 * Adelanta el estado de una tarea en cuanto el mensajero la completa, sin
 * esperar al servidor.
 *
 * El estado nuevo es cierto: la recolección ocurrió, lo que falta es contarlo.
 * `sincronizando` marca que todavía no hay acuse, y es lo que la lista usa para
 * desactivar el botón —la única forma de que nadie registre dos veces lo mismo
 * mientras el registro viaja—.
 */
export async function avanzarTareaLocal(paqueteId, estado) {
    /*
     * La búsqueda va por comparación de texto, no por clave.
     *
     * La ruta del navegador entrega el identificador como cadena —«453»— y
     * aquí está guardado como número. `bd.tareas.get('453')` no encuentra la
     * fila 453 y devuelve `undefined` sin protestar: la tarea no se actualizaba
     * y el mensajero seguía viendo el botón activo después de recolectar, que
     * es justo lo que esto vino a evitar.
     */
    const clave = String(paqueteId)
    const tarea = (await bd.tareas.toArray()).find((t) => String(t.paquete_id) === clave)

    if (!tarea) return

    await bd.tareas.put({
        ...tarea,
        estado,
        sincronizando: true,
        actualizado_en: new Date().toISOString(),
    })
}

/** Limpieza: lo enviado hace más de tres días ya no le sirve a nadie. */
export async function purgar() {
    const corte = new Date(Date.now() - 3 * 86400_000).toISOString()

    await bd.bandeja
        .where('estado').equals('enviado')
        .filter((r) => r.creado_en < corte)
        .delete()
}
