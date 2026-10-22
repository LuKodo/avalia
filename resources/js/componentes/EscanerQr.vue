<script setup>
import { onBeforeUnmount, ref } from 'vue'

/**
 * Escáner de QR con la cámara del dispositivo.
 *
 * Dos motores, y se elige el que haya:
 *
 *   · `BarcodeDetector` si el navegador lo trae —Chrome en Android—. Lo
 *     resuelve el sistema operativo: más rápido y sin gastar batería en
 *     JavaScript.
 *   · jsQR sobre un lienzo si no. Funciona en todas partes, cuesta más.
 *
 * Lo importante de este componente no es escanear: es DECIR POR QUÉ no puede
 * cuando no puede. Antes la pantalla decía «No hay lector conectado», que era
 * falso casi siempre: el lector está en el bolsillo del mensajero. Lo que
 * faltaba era HTTPS, y eso no se arregla tocando el teléfono.
 */
const emit = defineEmits(['leido', 'error'])

const props = defineProps({
    /**
     * Escaneo continuo: la camara no se cierra tras cada lectura.
     *
     * Es el modo de la recogida rapida. Cerrar y reabrir la camara entre bolsa
     * y bolsa cuesta casi un segundo cada vez y obliga a un toque de pantalla
     * con las manos ocupadas; con cincuenta bolsas eso es la diferencia entre
     * una herramienta y un estorbo.
     */
    continuo: { type: Boolean, default: false },

    /**
     * Milisegundos que se ignora un codigo ya leido.
     *
     * La camara ve el mismo QR treinta veces por segundo mientras apunta a la
     * misma bolsa. Sin esta ventana, una bolsa se registraria decenas de veces
     * y el mensajero tendria que depurar la lista a mano.
     */
    reposoMs: { type: Number, default: 2500 },
})

const video = ref(null)
const lienzo = ref(null)

const estado = ref('inactivo')   // inactivo | abriendo | leyendo | imposible
const motivo = ref(null)

let flujo = null

/** Codigo leido → instante de la ultima emision, para el reposo del modo continuo. */
const vistos = new Map()
let detector = null
let animacion = null

/**
 * Por qué no se puede usar la cámara, en el orden en que importa.
 *
 * El contexto inseguro va primero porque es el caso real aquí y porque es el
 * único que no depende del dispositivo: por mucho que el teléfono tenga
 * cámara, el navegador no la ofrece sobre HTTP. Comprobarlo antes evita pedir
 * un permiso que el navegador ni siquiera va a preguntar.
 */
function porQueNo() {
    if (!window.isSecureContext) {
        return {
            clave: 'inseguro',
            texto: 'El navegador solo permite usar la cámara en conexiones seguras (HTTPS). '
                + 'Esta página va por HTTP, así que el escáner no está disponible.',
            salida: 'Escriba el código de la etiqueta mientras tanto.',
        }
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        return {
            clave: 'no-soportado',
            texto: 'Este navegador no sabe usar la cámara desde una página web.',
            salida: 'Escriba el código de la etiqueta.',
        }
    }

    return null
}

async function abrir() {
    const impedimento = porQueNo()

    if (impedimento) {
        estado.value = 'imposible'
        motivo.value = impedimento

        return
    }

    estado.value = 'abriendo'
    motivo.value = null

    try {
        flujo = await navigator.mediaDevices.getUserMedia({
            // La trasera: el mensajero apunta a la caja, no a su cara.
            video: { facingMode: { ideal: 'environment' } },
            audio: false,
        })

        video.value.srcObject = flujo
        await video.value.play()

        if ('BarcodeDetector' in window) {
            detector = new window.BarcodeDetector({ formats: ['qr_code'] })
        }

        estado.value = 'leyendo'
        leer()
    } catch (e) {
        estado.value = 'imposible'

        motivo.value = e.name === 'NotAllowedError'
            ? {
                clave: 'denegado',
                texto: 'No se concedió permiso para usar la cámara.',
                salida: 'Permítalo en los ajustes del navegador, o escriba el código.',
            }
            : {
                clave: 'sin-camara',
                texto: 'No se encontró ninguna cámara disponible en este dispositivo.',
                salida: 'Escriba el código de la etiqueta.',
            }

        emit('error', motivo.value)
    }
}

async function leer() {
    if (estado.value !== 'leyendo' || !video.value) return

    try {
        const texto = detector
            ? (await detector.detect(video.value))[0]?.rawValue
            : await conJsQr()

        if (texto) {
            const codigo = normalizar(texto)

            if (!props.continuo) {
                emit('leido', codigo)
                cerrar()

                return
            }

            // Modo continuo: se emite una vez por codigo y se sigue leyendo.
            const ahora = Date.now()

            if ((ahora - (vistos.get(codigo) ?? 0)) > props.reposoMs) {
                vistos.set(codigo, ahora)
                emit('leido', codigo)
            }
        }
    } catch {
        // Un cuadro ilegible no es un fallo: se intenta con el siguiente.
    }

    animacion = requestAnimationFrame(leer)
}

/** Decodifica el cuadro actual con jsQR, para navegadores sin BarcodeDetector. */
async function conJsQr() {
    const v = video.value

    if (!v.videoWidth) return null

    const { default: jsQR } = await import('jsqr')
    const c = lienzo.value
    const ctx = c.getContext('2d', { willReadFrequently: true })

    c.width = v.videoWidth
    c.height = v.videoHeight
    ctx.drawImage(v, 0, 0, c.width, c.height)

    const datos = ctx.getImageData(0, 0, c.width, c.height)

    return jsQR(datos.data, datos.width, datos.height, { inversionAttempts: 'dontInvert' })?.data ?? null
}

/**
 * El QR de la guía lleva una DIRECCIÓN, no el código suelto.
 *
 * Se extrae el código del final de la ruta. Así el mismo código sirve tanto si
 * se escanea aquí dentro como si se apunta con la cámara nativa del teléfono,
 * que abre esa dirección en el navegador.
 */
function normalizar(texto) {
    const t = String(texto).trim()
    const m = t.match(/\/campo\/recoger\/([^/?#]+)/i)

    return decodeURIComponent(m ? m[1] : t)
}

function cerrar() {
    if (animacion) cancelAnimationFrame(animacion)
    animacion = null

    flujo?.getTracks().forEach((p) => p.stop())
    flujo = null

    if (video.value) video.value.srcObject = null
    estado.value = 'inactivo'
    vistos.clear()
}

onBeforeUnmount(cerrar)

defineExpose({ abrir, cerrar })
</script>

<template>
    <div class="escaner">
        <!-- ------------------------------------------------- aún sin abrir -->
        <button
            v-if="estado === 'inactivo'"
            type="button"
            class="btn-camara"
            @click="abrir"
        >
            📷 Escanear con la cámara
        </button>

        <p v-else-if="estado === 'abriendo'" class="estado">Abriendo la cámara…</p>

        <!-- ----------------------------------------------------- en marcha -->
        <div v-show="estado === 'leyendo'" class="visor">
            <video ref="video" playsinline muted></video>
            <div class="mira" aria-hidden="true"></div>
            <p class="pista">Apunte al código QR de la etiqueta</p>
            <button type="button" class="cancelar" @click="cerrar">Cancelar</button>
        </div>

        <!-- ---------------------------------------------------- no se puede -->
        <div v-if="estado === 'imposible'" class="impedimento">
            <p class="impedimento-txt">{{ motivo.texto }}</p>
            <p class="impedimento-salida">{{ motivo.salida }}</p>
            <button
                v-if="motivo.clave !== 'inseguro'"
                type="button"
                class="reintentar"
                @click="abrir"
            >Reintentar</button>
        </div>

        <canvas ref="lienzo" hidden></canvas>
    </div>
</template>

<style scoped>
.escaner { margin-bottom: .9rem; }

.btn-camara {
    width: 100%;
    min-height: 52px;
    border: 1px dashed var(--acento);
    background: var(--acento-suave, #eaf1ff);
    color: var(--acento-ink, #004CB9);
    border-radius: var(--r-md);
    font-family: inherit;
    font-size: .95rem;
    font-weight: 600;
    cursor: pointer;
}

.estado { text-align: center; color: var(--ink-3); font-size: .85rem; margin: .6rem 0; }

.visor { position: relative; border-radius: var(--r-md); overflow: hidden; background: #000; }
.visor video { width: 100%; max-height: 60vh; object-fit: cover; display: block; }

/* La mira no recorta nada: el decodificador mira el cuadro entero. Está para
   que el mensajero sepa dónde poner el código, que es media batalla. */
.mira {
    position: absolute; inset: 50% auto auto 50%;
    width: 62%; aspect-ratio: 1;
    transform: translate(-50%, -50%);
    border: 3px solid rgb(255 255 255 / .9);
    border-radius: 14px;
    box-shadow: 0 0 0 100vmax rgb(0 0 0 / .35);
}

.pista {
    position: absolute; left: 0; right: 0; bottom: 3.6rem;
    text-align: center; color: #fff; font-size: .85rem; margin: 0;
    text-shadow: 0 1px 3px rgb(0 0 0 / .7);
}

.cancelar {
    position: absolute; left: 50%; bottom: .9rem; transform: translateX(-50%);
    padding: .5rem 1.2rem; border: none; border-radius: var(--r-sm);
    background: rgb(255 255 255 / .92); font-family: inherit; font-size: .85rem; cursor: pointer;
}

.impedimento {
    border: 1px solid var(--linea);
    border-left: 3px solid var(--atencion, #d9a300);
    border-radius: var(--r-md);
    padding: .8rem 1rem;
    background: var(--superficie-2);
}

.impedimento-txt { margin: 0 0 .3rem; font-size: .85rem; color: var(--ink-2); line-height: 1.5; }
.impedimento-salida { margin: 0; font-size: .8rem; color: var(--ink-3); }

.reintentar {
    margin-top: .6rem; padding: .4rem .9rem;
    border: 1px solid var(--linea-fuerte); background: var(--superficie);
    border-radius: var(--r-sm); font-family: inherit; font-size: .8rem; cursor: pointer;
}
</style>
