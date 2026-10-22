/**
 * Captura de evidencia en campo: fotografía y firma.
 *
 * Dos decisiones que gobiernan este archivo:
 *
 *   · Se comprime EN EL DISPOSITIVO antes de guardar. Una foto de un teléfono
 *     moderno pesa entre 3 y 6 MB; veinte entregas al día son 100 MB de datos
 *     móviles del mensajero. Reducida a 1600 px de lado y 70 % de calidad pesa
 *     unos 300 KB y sigue permitiendo identificar receptor y empaque, que es
 *     para lo que existe.
 *
 *   · No se usa `getUserMedia`. El `<input capture>` abre la cámara nativa del
 *     teléfono, que enfoca mejor, tiene flash y funciona igual en iOS y en
 *     Android. Montar un visor propio con WebRTC daría peor foto y más código.
 */

const LADO_MAX = 1600
const CALIDAD = 0.7

/**
 * Reduce una imagen manteniendo su proporción.
 *
 * @param {File|Blob} archivo
 * @returns {Promise<{blob: Blob, ancho: number, alto: number, bytesOriginal: number}>}
 */
export async function comprimirImagen(archivo) {
    const bitmap = await creaBitmap(archivo)

    const escala = Math.min(1, LADO_MAX / Math.max(bitmap.width, bitmap.height))
    const ancho = Math.round(bitmap.width * escala)
    const alto = Math.round(bitmap.height * escala)

    const lienzo = document.createElement('canvas')
    lienzo.width = ancho
    lienzo.height = alto

    const ctx = lienzo.getContext('2d')
    ctx.drawImage(bitmap, 0, 0, ancho, alto)
    bitmap.close?.()

    const blob = await new Promise((r) => lienzo.toBlob(r, 'image/jpeg', CALIDAD))

    return { blob, ancho, alto, bytesOriginal: archivo.size }
}

async function creaBitmap(archivo) {
    // `createImageBitmap` respeta la orientación EXIF; sin eso, las fotos
    // tomadas en vertical se guardan acostadas y el acta sale ilegible.
    if ('createImageBitmap' in window) {
        try {
            return await createImageBitmap(archivo, { imageOrientation: 'from-image' })
        } catch {
            // Safari viejo no acepta las opciones: se cae al camino de abajo.
        }
    }

    return await new Promise((resolver, rechazar) => {
        const img = new Image()
        img.onload = () => resolver(img)
        img.onerror = () => rechazar(new Error('No se pudo leer la imagen.'))
        img.src = URL.createObjectURL(archivo)
    })
}

/**
 * Lienzo de firma manuscrita.
 *
 * Se dibuja con eventos de puntero, que cubren dedo, lápiz y ratón con el mismo
 * código. La resolución interna va al doble para que la firma no salga pixelada
 * en el acta impresa.
 */
export function crearFirmador(lienzo) {
    const ctx = lienzo.getContext('2d')
    const escala = Math.min(2, window.devicePixelRatio || 1)

    let dibujando = false
    let hayTrazo = false
    let ultimo = null

    function ajustar() {
        const caja = lienzo.getBoundingClientRect()
        lienzo.width = Math.round(caja.width * escala)
        lienzo.height = Math.round(caja.height * escala)
        ctx.scale(escala, escala)
        /*
         * Trazo grueso y muy oscuro, sobre fondo claro forzado por CSS.
         *
         * Se firma de pie, con el dedo, en un teléfono a contraluz y a veces
         * mojado. Un trazo fino y discreto obliga a repetir la firma, y repetir
         * una firma delante de quien recibe es la clase de fricción que acaba
         * en «déjelo así».
         */
        ctx.lineWidth = 3
        ctx.lineCap = 'round'
        ctx.lineJoin = 'round'
        ctx.strokeStyle = '#0B1020'
    }

    const punto = (e) => {
        const c = lienzo.getBoundingClientRect()
        return { x: e.clientX - c.left, y: e.clientY - c.top }
    }

    function iniciar(e) {
        e.preventDefault()
        dibujando = true
        hayTrazo = true
        ultimo = punto(e)
        lienzo.setPointerCapture?.(e.pointerId)
    }

    function mover(e) {
        if (!dibujando) return
        e.preventDefault()

        const p = punto(e)
        ctx.beginPath()
        ctx.moveTo(ultimo.x, ultimo.y)
        ctx.lineTo(p.x, p.y)
        ctx.stroke()
        ultimo = p
    }

    function soltar() {
        dibujando = false
    }

    ajustar()

    lienzo.addEventListener('pointerdown', iniciar)
    lienzo.addEventListener('pointermove', mover)
    lienzo.addEventListener('pointerup', soltar)
    lienzo.addEventListener('pointerleave', soltar)
    lienzo.addEventListener('pointercancel', soltar)

    return {
        get tieneTrazo() {
            return hayTrazo
        },

        limpiar() {
            ctx.clearRect(0, 0, lienzo.width, lienzo.height)
            hayTrazo = false
        },

        /** La firma se exporta en PNG: el JPEG con fondo blanco emborrona el trazo. */
        async aBlob() {
            if (!hayTrazo) return null

            /*
             * Se exporta sobre BLANCO, no sobre transparencia.
             *
             * El lienzo solo contiene el trazo; su fondo es CSS y no viaja en
             * el PNG. Una firma guardada con transparencia es un trazo oscuro
             * sobre nada: se ve bien en un visor de fondo claro y desaparece en
             * uno oscuro o en un acta impresa sobre color. Y esa firma es la
             * prueba de que alguien recibió un medicamento: tiene que verse
             * siempre, no según dónde se mire.
             */
            const plano = document.createElement('canvas')
            plano.width = lienzo.width
            plano.height = lienzo.height

            const ctxPlano = plano.getContext('2d')
            ctxPlano.fillStyle = '#FFFFFF'
            ctxPlano.fillRect(0, 0, plano.width, plano.height)
            ctxPlano.drawImage(lienzo, 0, 0)

            return await new Promise((r) => plano.toBlob(r, 'image/png'))
        },

        destruir() {
            lienzo.removeEventListener('pointerdown', iniciar)
            lienzo.removeEventListener('pointermove', mover)
            lienzo.removeEventListener('pointerup', soltar)
            lienzo.removeEventListener('pointerleave', soltar)
            lienzo.removeEventListener('pointercancel', soltar)
        },
    }
}

export function pesoLegible(bytes) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

    return `${(bytes / 1048576).toFixed(1)} MB`
}
