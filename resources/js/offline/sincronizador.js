import { ref } from 'vue'
import { api, ErrorApi } from '../api/cliente'
import {
    contarEvidenciasPendientes, contarPendientes, evidenciasPendientes,
    marcarEnviado, marcarEvidenciaFallida, marcarEvidenciaSubida,
    marcarRechazado, pendientes, purgar, reprogramar,
} from './almacen'

/**
 * Vaciado de la bandeja de salida.
 *
 * Reglas que hacen que esto sea confiable y no una fuente de duplicados:
 *
 *   · Un solo ciclo a la vez. Dos ciclos concurrentes reenvían lo mismo.
 *   · Orden de creación. Un paquete no puede llegar «entregado» antes que
 *     «recolectado».
 *   · LAS EVIDENCIAS DEL PAQUETE SUBEN PRIMERO. El evento de entrega referencia
 *     los identificadores de la firma y la foto, y el servidor verifica que
 *     existan: mandar el evento antes que sus archivos lo haría rebotar por
 *     validación en cada intento, para siempre.
 *   · Fallo de red se reintenta; rechazo del servidor, no. Reintentar un 422
 *     eternamente solo llena la cola.
 */

export const estadoSync = ref({
    enLinea: navigator.onLine,
    sincronizando: false,
    pendientes: 0,
    evidencias: 0,
    ultimoIntento: null,
    ultimoError: null,
})

let cicloEnCurso = false
let temporizador = null

export async function refrescarContador() {
    estadoSync.value.pendientes = await contarPendientes()
    estadoSync.value.evidencias = await contarEvidenciasPendientes()
}

async function enviarUno(registro) {
    const { tipo, paquete_id: paqueteId, carga } = registro

    switch (tipo) {
        case 'recolectar': return api.recolectar(paqueteId, carga)
        case 'entregar':   return api.entregar(paqueteId, carga)
        case 'novedad':    return api.novedad(paqueteId, carga)
        case 'posiciones': return api.posiciones(carga.posiciones, carga.bateria)
        default: throw new ErrorApi(`Tipo de acción desconocido: ${tipo}`, { estado: 400 })
    }
}

/**
 * Sube las evidencias de un paquete. Devuelve `false` si alguna falló por red,
 * para que el llamador no mande todavía el evento que las referencia.
 */
async function subirEvidenciasDe(paqueteId) {
    const archivos = (await evidenciasPendientes()).filter((a) => String(a.paquete_id) === String(paqueteId))

    for (const archivo of archivos) {
        if (!archivo.blob) {
            // Sin blob no hay nada que subir: se marca para que no bloquee la
            // cola indefinidamente, y queda su error registrado.
            await marcarEvidenciaFallida(archivo.id, archivo.intentos + 1, 'archivo perdido en el dispositivo')
            continue
        }

        const forma = new FormData()
        forma.append('id', archivo.id)
        forma.append('tipo', archivo.tipo)
        forma.append('capturada_en', archivo.capturada_en)
        forma.append('archivo', archivo.blob, `${archivo.id}.${archivo.mime === 'image/png' ? 'png' : 'jpg'}`)

        try {
            await api.subirEvidencia(paqueteId, forma)
            await marcarEvidenciaSubida(archivo.id)
        } catch (e) {
            await marcarEvidenciaFallida(archivo.id, (archivo.intentos ?? 0) + 1, e.message)

            if (e instanceof ErrorApi && e.esReintentable) {
                return false
            }
            // Un rechazo definitivo no debe bloquear el evento para siempre: se
            // deja registrado y se sigue, para que la entrega al menos exista.
        }
    }

    return true
}

export async function sincronizar() {
    if (cicloEnCurso || !navigator.onLine) return

    cicloEnCurso = true
    estadoSync.value.sincronizando = true
    estadoSync.value.ultimoIntento = new Date().toISOString()

    try {
        const cola = (await pendientes()).sort((a, b) => a.creado_en.localeCompare(b.creado_en))

        for (const registro of cola) {
            if (registro.proximo_en && registro.proximo_en > new Date().toISOString()) continue

            // Primero los archivos que el evento va a referenciar.
            if (registro.tipo !== 'posiciones') {
                const listas = await subirEvidenciasDe(registro.paquete_id)

                if (!listas) {
                    estadoSync.value.ultimoError = 'Subiendo evidencias…'
                    break
                }
            }

            try {
                await enviarUno(registro)
                await marcarEnviado(registro.id)
                estadoSync.value.ultimoError = null
            } catch (e) {
                /*
                 * Sesión caducada: se PARA, no se descarta.
                 *
                 * Un 401 encaja en la definición de «el servidor ya dijo que
                 * no», y por eso caía del lado de los rechazos definitivos. Pero
                 * no es un no al dato: es un no a la credencial. El mensajero
                 * vuelve a entrar y la misma entrega es perfectamente válida.
                 *
                 * Tratarlo como rechazo costó una entrega real: foto, firma y
                 * coordenada capturadas en la calle, descartadas porque el token
                 * expiró mientras la cola reintentaba otra cosa. Se corta el
                 * ciclo —sin credencial lo que sigue también fallará— y se deja
                 * el registro intacto.
                 */
                if (e instanceof ErrorApi && e.estado === 401) {
                    estadoSync.value.ultimoError =
                        'Su sesión caducó. Vuelva a entrar: su trabajo está guardado y saldrá solo.'
                    break
                }

                if (e instanceof ErrorApi && !e.esReintentable) {
                    await marcarRechazado(registro.id, e.mensaje ?? e.message)
                    estadoSync.value.ultimoError = e.message
                    continue
                }

                await reprogramar(registro.id, (registro.intentos ?? 0) + 1, e.message)
                estadoSync.value.ultimoError = e.message

                if (e instanceof ErrorApi && e.esDeRed) break
            }
        }

        // Evidencias huérfanas: de paquetes cuyo evento ya salió.
        for (const archivo of await evidenciasPendientes()) {
            await subirEvidenciasDe(archivo.paquete_id)
        }

        await purgar()
    } finally {
        await refrescarContador()
        estadoSync.value.sincronizando = false
        cicloEnCurso = false
    }
}

export function iniciarSincronizacion() {
    refrescarContador()

    window.addEventListener('online', () => {
        estadoSync.value.enLinea = true
        sincronizar()
    })

    window.addEventListener('offline', () => {
        estadoSync.value.enLinea = false
    })

    // Al volver a primer plano: es el momento en que el mensajero sacó el
    // teléfono del bolsillo, y muchas veces también el momento en que recuperó
    // señal sin que se disparara el evento `online`.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') sincronizar()
    })

    temporizador = setInterval(sincronizar, 30_000)

    sincronizar()
}

export function detenerSincronizacion() {
    if (temporizador) clearInterval(temporizador)
}
