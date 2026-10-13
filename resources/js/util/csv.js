/**
 * Exportación a CSV desde el navegador.
 *
 * Las pantallas de lista ya tienen sus datos cargados, así que exportarlos no
 * necesita pasar por el servidor: es la misma información que la persona está
 * viendo, guardada en un archivo. No concede ningún acceso nuevo.
 *
 * Dos decisiones que parecen menores y no lo son:
 *
 *   · Separador punto y coma y marca BOM. Excel en español interpreta la coma
 *     como separador decimal, así que un CSV separado por comas le aparece al
 *     usuario todo amontonado en la primera columna. Sin el BOM, además, las
 *     tildes y las eñes salen rotas. Es el mismo criterio que ya usa la
 *     exportación del servidor, y conviene que ambas coincidan.
 *
 *   · Neutralización de fórmulas. Un valor que empieza por «=», «+», «-» o «@»
 *     Excel lo ejecuta al abrir el archivo. Como los nombres de cliente y las
 *     descripciones los escriben personas, alguien podría guardar un texto que
 *     se convierte en fórmula en la máquina de quien abra el reporte. Se le
 *     antepone un apóstrofo, que Excel no muestra y que corta el problema.
 */

/** Caracteres con los que Excel decide que una celda es una fórmula. */
const INICIOS_PELIGROSOS = ['=', '+', '-', '@', '\t', '\r']

/**
 * Prepara un valor para una celda.
 *
 * @param {*} valor
 * @returns {string}
 */
export function celda(valor) {
    if (valor === null || valor === undefined) return ''

    if (typeof valor === 'boolean') return valor ? 'Sí' : 'No'

    /*
     * Los números salen tal cual, sin pasar por la neutralización de fórmulas.
     *
     * Hace falta decirlo explícitamente porque un negativo empieza por «-», que
     * es uno de los caracteres que disparan una fórmula en Excel. Sin esta
     * salida temprana, una holgura de −5133 minutos se exportaba como
     * «'-5133»: Excel la trataba como texto y la columna dejaba de poder
     * ordenarse, que era justo la razón de exportar el número crudo.
     *
     * No hay riesgo en dejarlos pasar: un número no puede contener el separador
     * ni comillas, y una fórmula nunca llega aquí como tipo number.
     */
    if (typeof valor === 'number') {
        return Number.isFinite(valor) ? String(valor) : ''
    }

    let texto = String(valor)

    // Los saltos de línea dentro de una celda son legales entre comillas, pero
    // vuelven ilegible el archivo abierto en un editor de texto y rompen a más
    // de un importador. Se aplanan.
    texto = texto.replace(/\r?\n/g, ' ').trim()

    if (INICIOS_PELIGROSOS.includes(texto.charAt(0))) {
        texto = `'${texto}`
    }

    // Se entrecomilla siempre que el contenido pueda confundir al lector: el
    // separador, la comilla misma o espacios en los extremos.
    if (/[";]/.test(texto)) {
        return `"${texto.replace(/"/g, '""')}"`
    }

    return texto
}

/**
 * Arma el texto del archivo.
 *
 * @param {string[]} columnas  Encabezados, en orden.
 * @param {Array<Array<*>>} filas
 * @returns {string}
 */
export function armarCsv(columnas, filas) {
    const lineas = [columnas.map(celda).join(';')]

    for (const fila of filas) {
        lineas.push(fila.map(celda).join(';'))
    }

    // CRLF: es lo que espera Excel en Windows, que es donde se va a abrir.
    return lineas.join('\r\n')
}

/**
 * Genera el archivo y lanza la descarga.
 *
 * @param {string} nombre    Sin extensión; se le añade la fecha y «.csv».
 * @param {string[]} columnas
 * @param {Array<Array<*>>} filas
 * @returns {number}  Filas exportadas, para poder avisarlo en pantalla.
 */
export function descargarCsv(nombre, columnas, filas) {
    const texto = armarCsv(columnas, filas)

    // El BOM va antes que nada; sin él Excel asume la codificación del sistema
    // y las tildes se rompen.
    const blob = new Blob(['﻿' + texto], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)

    const a = document.createElement('a')
    a.href = url
    a.download = `${nombre}-${sello()}.csv`
    document.body.appendChild(a)
    a.click()
    a.remove()

    // Liberar en el mismo turno cancelaría la descarga en algunos navegadores.
    setTimeout(() => URL.revokeObjectURL(url), 2000)

    return filas.length
}

/** Marca de tiempo para el nombre del archivo: 20260822-1145. */
function sello() {
    const d = new Date()
    const p = (n) => String(n).padStart(2, '0')

    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`
}

/**
 * Extrae columnas y filas de una tabla ya pintada.
 *
 * Sirve para las pantallas donde los datos viven en el propio marcado y no en
 * una estructura aparte. Se salta las columnas marcadas con `data-sin-exportar`
 * —la de acciones, por ejemplo—, porque un botón no es un dato.
 *
 * @param {HTMLTableElement} tabla
 * @returns {{columnas: string[], filas: string[][]}}
 */
export function desdeTabla(tabla) {
    const encabezados = [...tabla.querySelectorAll('thead th')]
    const omitir = new Set()

    const columnas = encabezados
        .map((th, i) => {
            if (th.hasAttribute('data-sin-exportar')) {
                omitir.add(i)

                return null
            }

            return th.textContent.replace(/\s+/g, ' ').trim()
        })
        .filter((c) => c !== null)

    const filas = [...tabla.querySelectorAll('tbody tr')].map((tr) =>
        [...tr.children]
            .filter((_, i) => !omitir.has(i))
            .map((td) => td.textContent.replace(/\s+/g, ' ').trim())
    )

    return { columnas, filas }
}
