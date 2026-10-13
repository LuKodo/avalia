/**
 * La ruta donde vive la aplicacion dentro del servidor.
 *
 * Vale '' cuando se sirve en la raiz de un dominio -el caso normal- y '/Avalia'
 * cuando cuelga de una subcarpeta. Lo dice el servidor en una etiqueta <meta>,
 * porque es lo unico que lo sabe: el mismo paquete compilado tiene que servir
 * para las dos situaciones, y hornearlo en tiempo de compilacion obligaria a
 * recompilar para cambiar de sitio.
 *
 * Sin esto, un despliegue en subcarpeta pide sus datos a /api/v1 en la raiz del
 * dominio -donde no hay nada- y el enrutador del navegador calcula mal cada
 * direccion.
 */
const etiqueta = document.querySelector('meta[name="base-app"]')

export const BASE_APP = (etiqueta?.getAttribute('content') || '').replace(/\/+$/, '')

/** Antepone la base a una ruta absoluta de la aplicacion. */
export function conBase(ruta) {
    return BASE_APP + ruta
}
