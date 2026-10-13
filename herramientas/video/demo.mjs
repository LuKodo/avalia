import fs from 'node:fs'
import path from 'node:path'
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { Grabador } from './grabador.mjs'

const requireProyecto = createRequire('file:///C:/xampp2/htdocs/LTP/')
const puppeteer = requireProyecto('puppeteer-core')

const require = createRequire(import.meta.url)
const ffmpeg = require('ffmpeg-static')

/**
 * Graba sola la demostración de Avalia Logística.
 *
 * Maneja el navegador como lo haría una persona —entra con cada perfil, navega,
 * espera a que las pantallas terminen de dibujarse— y va capturando. Al final
 * junta las piezas en un MP4 a doble velocidad.
 *
 * El segmento del mensajero se graba aparte, en tamaño de teléfono, y después
 * se encaja centrado sobre un fondo azul noche. Es la única forma honesta de
 * enseñarlo: su aplicación de campo ES una PWA vertical, y mostrarla estirada a
 * 1920 píxeles diría que es algo que no es.
 */

const BASE   = 'http://127.0.0.1:8000'
const CLAVE  = 'Avalia*2026'
const SALIDA = 'C:/xampp2/htdocs/LTP/storage/app/video'
const TRABAJO = path.dirname(new URL(import.meta.url).pathname.slice(1))

// Todo se acelera a 2x. Las pausas se piden en segundos DEL VIDEO FINAL y aquí
// se multiplican: si no, una pausa de 4 s pensada para leer un aviso se queda
// en 2 s y no da tiempo.
const FACTOR = 2

// Las dos guias que salen en camara. Se eligen a proposito: una en regla para
// la escena del cliente y otra con desvio real para la del operador.
const GUIA_EN_REGLA = 'AVL-260913-0001'
const GUIA_DESVIADA = 'AVL-260912-0001'

const ANCHO = 1440
const ALTO  = 900

// ---------------------------------------------------------------- utilidades

const esperar = (ms) => new Promise((r) => setTimeout(r, ms))

/** Pausa expresada en segundos del video terminado. */
const pausa = (segundosFinales) => esperar(segundosFinales * FACTOR * 1000)

/**
 * El rótulo inferior con la frase de cada escena.
 *
 * Hace las veces de locución: no hay voz en esta grabación, y una demostración
 * muda obliga a adivinar qué se está viendo.
 */
async function rotulo(p, texto, perfil = null) {
    await p.evaluate((txt, per) => {
        let caja = document.getElementById('avalia-rotulo')

        if (caja === null) {
            caja = document.createElement('div')
            caja.id = 'avalia-rotulo'
            caja.style.cssText = `
                position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147483647;
                padding: 18px 30px 20px;
                background: linear-gradient(to top, rgba(13,19,51,.97), rgba(13,19,51,.88) 62%, rgba(13,19,51,0));
                color: #fff; font: 500 20px/1.45 "Work Sans", "Segoe UI", system-ui, sans-serif;
                display: flex; align-items: baseline; gap: 16px;
                pointer-events: none; transition: opacity .25s ease;`

            const etiqueta = document.createElement('span')
            etiqueta.id = 'avalia-rotulo-perfil'
            etiqueta.style.cssText = `
                flex: none; font: 700 11px/1 "League Spartan", sans-serif;
                letter-spacing: .18em; text-transform: uppercase;
                color: #0D1333; background: #D2E3F3;
                padding: 7px 11px; border-radius: 4px;`

            const frase = document.createElement('span')
            frase.id = 'avalia-rotulo-txt'

            caja.append(etiqueta, frase)
            document.body.appendChild(caja)
        }

        const et = document.getElementById('avalia-rotulo-perfil')
        et.textContent = per ?? ''
        et.style.display = per ? '' : 'none'

        document.getElementById('avalia-rotulo-txt').textContent = txt
    }, texto, perfil)
}

/** Desplazamiento suave, que además es lo que genera fotogramas. */
async function bajar(p, hasta, paso = 9) {
    await p.evaluate(async (limite, incremento) => {
        const tope = Math.min(limite, document.body.scrollHeight - window.innerHeight)

        for (let y = window.scrollY; y < tope; y += incremento) {
            window.scrollTo(0, y)
            await new Promise((r) => requestAnimationFrame(r))
        }
    }, hasta, paso)
}

async function irA(p, ruta) {
    await p.goto(BASE + ruta, { waitUntil: 'networkidle0' })
    await p.evaluate(() => document.fonts.ready)
    await esperar(700)
}

/** Entra con una cuenta y espera a que la aplicación monte de verdad. */
async function entrar(p, correo) {
    await irA(p, '/ingreso')
    await p.waitForSelector('#email', { visible: true, timeout: 25000 })
    await p.type('#email', correo, { delay: 25 })
    await p.type('#clave', CLAVE, { delay: 15 })
    await Promise.all([
        p.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
        p.click('button[type=submit]'),
    ])
    await p.evaluate(() => document.fonts.ready)
    await esperar(1500)
}

async function salir(p) {
    const boton = await p.$('.salir')

    if (boton) {
        await boton.click()
        await esperar(1200)
    }
}

// ------------------------------------------------------------------ escenas

/** Sitio público + perfil cliente + perfil operador. */
async function parteEscritorio(navegador, carpeta, archivo) {
    const p = await navegador.newPage()
    await p.setViewport({ width: ANCHO, height: ALTO, deviceScaleFactor: 1 })

    await irA(p, '/sitio')
    const g = new Grabador(p, carpeta)
    await g.iniciar()

    // ---------------------------------------------------------- [0] el sitio
    await rotulo(p, 'Conectamos destinos, respaldamos confianzas.')
    await pausa(2)
    await bajar(p, 2400)
    await rotulo(p, 'Tres líneas de servicio, y una plataforma detrás de todas.')
    await pausa(2)

    // --------------------------------------------------------- [1] cliente
    await entrar(p, 'farmacia@clinicadelnorte.example')
    await rotulo(p, 'El cliente entra a su propio portal.', 'Cliente')
    await pausa(1.5)

    await irA(p, '/rastreo')
    await rotulo(p, 'Busca por SU referencia, no por la nuestra.', 'Cliente')
    await p.waitForSelector('#q', { visible: true, timeout: 15000 })
    // Un envío SIN desvío: el cliente ve el caso normal. El desvío se reserva
    // para el operador, más adelante. Con la misma guía en las dos escenas, el
    // mapa salía dos veces casi idéntico y parecía un montaje repetido.
    await p.type('#q', GUIA_EN_REGLA, { delay: 45 })
    await p.keyboard.press('Enter')
    await esperar(2500)
    await rotulo(p, 'Trazabilidad completa del envío, evento por evento.', 'Cliente')
    await pausa(3)

    const verMapa = await p.$('.ver-mapa')

    if (verMapa) {
        await verMapa.click()
        await esperar(4000)
        await rotulo(p, 'Y el recorrido: la ruta trazada contra por dónde fue de verdad.', 'Cliente')
        await pausa(3.5)
    }

    // -------------------------------------------------------- [2] operador
    await salir(p)
    await entrar(p, 'operador@ltp.local')
    await rotulo(p, 'El operador ve la operación completa.', 'Operador')
    await pausa(2)
    await bajar(p, 900)
    await pausa(1.5)

    await irA(p, '/despacho')
    await rotulo(p, 'Qué está sin asignar, y a quién conviene asignárselo.', 'Operador')
    await pausa(3)

    await irA(p, '/mapa')
    await esperar(4500)
    await rotulo(p, 'Dónde está cada mensajero, ahora mismo.', 'Operador')
    await pausa(3.5)

    await irA(p, `/envios/${GUIA_DESVIADA}/mapa`)
    await esperar(4500)
    await rotulo(p, 'Y quién se salió de la ruta: 620 m durante 15 minutos.', 'Operador')
    await pausa(4.5)

    await irA(p, '/telemetria')
    await rotulo(p, 'Cadena de frío: no avisa de la excursión. Dictamina si el producto sirve.', 'Operador')
    await pausa(4)
    await bajar(p, 700)
    await pausa(2)

    await g.detener()
    const info = g.componer(archivo, { factor: FACTOR })
    await p.close()

    return info
}

/** El mensajero, en tamaño de teléfono. */
async function parteMovil(navegador, carpeta, archivo) {
    const p = await navegador.newPage()
    await p.setViewport({
        width: 430, height: 932, deviceScaleFactor: 2,
        isMobile: true, hasTouch: true,
    })

    await irA(p, '/ingreso')
    const g = new Grabador(p, carpeta)
    await g.iniciar()

    await entrar(p, 'julian.ramirez@ltp.local')
    await rotulo(p, 'El mensajero trabaja desde su teléfono.', 'Mensajero')
    await pausa(2.5)

    await irA(p, '/campo')
    await rotulo(p, 'Sus tareas del día, con objetivos grandes: se usa con guantes.', 'Mensajero')
    await pausa(3)
    await bajar(p, 800, 6)
    await rotulo(p, 'Y sigue funcionando sin señal: las entregas salen al reconectar.', 'Mensajero')
    await pausa(3)

    await g.detener()
    const info = g.componer(archivo, { factor: FACTOR })
    await p.close()

    return info
}

/** Administrador y verificación pública. */
async function parteCierre(navegador, carpeta, archivo) {
    const p = await navegador.newPage()
    await p.setViewport({ width: ANCHO, height: ALTO, deviceScaleFactor: 1 })

    await irA(p, '/ingreso')
    const g = new Grabador(p, carpeta)
    await g.iniciar()

    await entrar(p, 'admin@ltp.local')

    /*
     * Entrar deja al administrador en el tablero, que tarda en traer sus
     * series. Sin esta espera, la grabacion capturaba la pantalla a medio
     * cargar —un "Cargando indicadores…" sobre el vacio— y ese fotograma se
     * quedaba segundos en camara, porque la duracion de cada cuadro sale del
     * tiempo real que estuvo en pantalla.
     */
    await p.waitForFunction(
        () => ! document.body.innerText.includes('Cargando indicadores'),
        { timeout: 20000 },
    ).catch(() => {})
    await esperar(800)

    await rotulo(p, 'El administrador ve todo lo anterior, y además lo gobierna.', 'Administrador')
    await pausa(2.5)

    await irA(p, '/admin')
    await rotulo(p, 'La administración: usuarios, flota, tarifas, parámetros.', 'Administrador')
    await pausa(2.5)

    await irA(p, '/admin/flota')
    await rotulo(p, 'Vehículos, certificados de frío y vencimientos.', 'Administrador')
    await pausa(2.5)

    await irA(p, '/admin/auditoria')
    await rotulo(p, 'La bitácora solo admite anexar. Cada evento, encadenado por hash.', 'Administrador')
    await pausa(3)

    const verificar = await p.$x?.('//button[contains(., "Verificar")]')
    const boton = verificar?.[0] ?? await p.$('button.boton--primario')

    if (boton) {
        await boton.click()
        await esperar(3500)
        await rotulo(p, 'No es que no la cambiemos: es que no se puede.', 'Administrador')
        await pausa(4)
    }

    await salir(p)
    await irA(p, '/verificar')
    await rotulo(p, 'Y un auditor externo lo comprueba por su cuenta. Sin cuenta nuestra.')
    await pausa(4)

    await rotulo(p, 'Avalia Logística · avalialogistica.com')
    await pausa(3)

    await g.detener()
    const info = g.componer(archivo, { factor: FACTOR })
    await p.close()

    return info
}

// --------------------------------------------------------------------- main

fs.mkdirSync(SALIDA, { recursive: true })

const navegador = await puppeteer.launch({
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: true,
    args: ['--no-sandbox', '--hide-scrollbars', '--force-device-scale-factor=1'],
})

console.log('1/3  Sitio, cliente y operador…')
const a = await parteEscritorio(navegador, `${TRABAJO}/cuadros-1`, `${TRABAJO}/p1.mp4`)
console.log('     ', JSON.stringify(a))

console.log('2/3  Mensajero (teléfono)…')
const b = await parteMovil(navegador, `${TRABAJO}/cuadros-2`, `${TRABAJO}/p2-movil.mp4`)
console.log('     ', JSON.stringify(b))

console.log('3/3  Administrador y cierre…')
const c = await parteCierre(navegador, `${TRABAJO}/cuadros-3`, `${TRABAJO}/p3.mp4`)
console.log('     ', JSON.stringify(c))

await navegador.close()

// El clip del teléfono se lleva al mismo lienzo que los demás: centrado sobre
// el azul noche de la marca. Sin esto no se pueden concatenar -ffmpeg exige
// que todas las piezas tengan la misma resolución- y además queda con el
// aspecto de un teléfono sobre una superficie, que es lo que es.
console.log('Encajando el clip del teléfono en 1440x900…')
execFileSync(ffmpeg, [
    '-y', '-i', `${TRABAJO}/p2-movil.mp4`,
    '-vf', `scale=-2:${ALTO - 40},pad=${ANCHO}:${ALTO}:(ow-iw)/2:(oh-ih)/2:color=0x0D1333,format=yuv420p`,
    '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-profile:v', 'high', '-r', '30',
    `${TRABAJO}/p2.mp4`,
], { stdio: 'pipe' })

const lista = `${TRABAJO}/partes.txt`
fs.writeFileSync(lista, ['p1.mp4', 'p2.mp4', 'p3.mp4']
    .map((f) => `file '${TRABAJO}/${f}'`).join('\n'))

const final = `${SALIDA}/avalia-demo.mp4`

console.log('Uniendo las tres partes…')
execFileSync(ffmpeg, [
    '-y', '-f', 'concat', '-safe', '0', '-i', lista,
    '-c:v', 'libx264', '-preset', 'medium', '-crf', '20',
    '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-r', '30',
    '-movflags', '+faststart',
    final,
], { stdio: 'pipe' })

const mb = (fs.statSync(final).size / 1048576).toFixed(1)
console.log(`\nListo: ${final}  (${mb} MB)`)
