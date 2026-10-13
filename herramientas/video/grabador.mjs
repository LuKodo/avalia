import fs from 'node:fs'
import path from 'node:path'
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const ffmpeg = require('ffmpeg-static')

/**
 * Graba lo que pinta una pestaña de Chrome y lo convierte en un MP4.
 *
 * Usa el "screencast" del protocolo de DevTools en vez de tomar capturas en
 * bucle: Chrome empuja un fotograma cada vez que repinta, sin bloquear la
 * página. Tomar capturas una por una da unos diez por segundo y congela la
 * interfaz mientras lo hace, que es justo lo que no se quiere al grabar una
 * demostración.
 *
 * Los fotogramas llegan a ritmo IRREGULAR —una pantalla quieta no genera
 * ninguno—, así que no sirve juntarlos a una cadencia fija: habría que inventar
 * los que faltan. En lugar de eso se anota cuánto dura cada uno y se arma la
 * lista para el demultiplexor `concat` de ffmpeg, que sí entiende duraciones
 * distintas. Acelerar a 2x es entonces dividir esas duraciones entre dos.
 */
export class Grabador {
    constructor(pagina, carpeta) {
        this.pagina = pagina
        this.carpeta = carpeta
        this.fotogramas = []
        this.cdp = null

        fs.rmSync(carpeta, { recursive: true, force: true })
        fs.mkdirSync(carpeta, { recursive: true })
    }

    async iniciar() {
        this.cdp = await this.pagina.target().createCDPSession()

        this.cdp.on('Page.screencastFrame', async ({ data, sessionId, metadata }) => {
            const n = String(this.fotogramas.length).padStart(6, '0')
            const archivo = path.join(this.carpeta, `f${n}.jpg`)

            fs.writeFileSync(archivo, Buffer.from(data, 'base64'))
            this.fotogramas.push({ archivo, momento: metadata.timestamp })

            // Sin este acuse Chrome deja de mandar fotogramas tras los
            // primeros. Es el fallo clasico de este protocolo.
            try {
                await this.cdp.send('Page.screencastFrameAck', { sessionId })
            } catch {
                // La sesion ya se cerro: no hay nada que acusar.
            }
        })

        await this.cdp.send('Page.startScreencast', {
            format: 'jpeg',
            quality: 82,
            everyNthFrame: 1,
        })
    }

    async detener() {
        try {
            await this.cdp.send('Page.stopScreencast')
        } catch {
            // Ya estaba detenido.
        }
    }

    /**
     * @param {string} salida  Ruta del MP4 a escribir.
     * @param {number} factor  2 = el doble de rapido.
     * @param {number} fps     Cadencia del video resultante.
     */
    componer(salida, { factor = 2, fps = 30 } = {}) {
        if (this.fotogramas.length < 2) {
            throw new Error(`Solo se capturaron ${this.fotogramas.length} fotogramas.`)
        }

        const lista = path.join(this.carpeta, 'lista.txt')
        const lineas = []

        for (let i = 0; i < this.fotogramas.length; i++) {
            const actual = this.fotogramas[i]
            const siguiente = this.fotogramas[i + 1]

            /*
             * El suelo tiene que ser MUY pequeno, no "un cuadro".
             *
             * Chrome entrega cerca de 60 fotogramas por segundo; al partir sus
             * duraciones entre dos quedan por debajo de 1/60, y un suelo de 1/60
             * las devolvia a su valor original: el video salia a velocidad
             * normal aunque se pidiera 2x. El filtro `fps` de mas abajo es quien
             * remuestrea a la cadencia final, asi que aqui solo hace falta que
             * ninguna duracion sea cero.
             *
             * El ultimo fotograma no tiene sucesor del que deducir su duracion;
             * se le da la de un cuadro para que no desaparezca.
             */
            const dura = siguiente
                ? Math.max(1 / 1000, (siguiente.momento - actual.momento) / factor)
                : 1 / fps

            lineas.push(`file '${actual.archivo.replace(/\\/g, '/')}'`)
            lineas.push(`duration ${dura.toFixed(4)}`)
        }

        // El demultiplexor `concat` ignora la duracion del ultimo archivo, asi
        // que hay que repetirlo para que se respete.
        lineas.push(`file '${this.fotogramas.at(-1).archivo.replace(/\\/g, '/')}'`)

        fs.writeFileSync(lista, lineas.join('\n'))

        execFileSync(ffmpeg, [
            '-y',
            '-f', 'concat', '-safe', '0', '-i', lista,
            '-vf', `fps=${fps},format=yuv420p`,
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '20',
            // Para que reproduzca en cualquier parte, incluido WhatsApp y
            // PowerPoint, que son quisquillosos con el perfil de H.264.
            '-profile:v', 'high',
            '-movflags', '+faststart',
            salida,
        ], { stdio: 'pipe' })

        const segundos = (this.fotogramas.at(-1).momento - this.fotogramas[0].momento) / factor

        return {
            fotogramas: this.fotogramas.length,
            segundos: Number(segundos.toFixed(1)),
            bytes: fs.statSync(salida).size,
        }
    }
}
