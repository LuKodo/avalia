/**
 * Cliente HTTP de la API.
 *
 * Dos comportamientos que no son opcionales en este dominio:
 *
 *   · Toda escritura lleva clave de idempotencia. Un mensajero con señal
 *     intermitente reintenta constantemente; sin idempotencia, cada reintento
 *     sería una entrega duplicada en la bitácora.
 *   · Un fallo de red no se confunde con un rechazo del servidor. El primero se
 *     reintenta; el segundo, no. Confundirlos hace que una entrega rechazada
 *     por regla de negocio se reintente para siempre.
 */

import { conBase } from '../base'

const BASE = conBase('/api/v1')

export class ErrorApi extends Error {
    constructor(mensaje, { estado = 0, cuerpo = null, esDeRed = false, ruta = null } = {}) {
        super(mensaje)
        this.ruta = ruta
        this.name = 'ErrorApi'
        this.estado = estado
        this.cuerpo = cuerpo
        this.esDeRed = esDeRed
    }

    /** ¿Vale la pena reintentar, o el servidor ya dijo que no? */
    get esReintentable() {
        return this.esDeRed || this.estado === 0 || this.estado === 429 || this.estado >= 500
    }
}

function token() {
    return localStorage.getItem('ltp.token')
}

async function peticion(ruta, { metodo = 'GET', cuerpo = null, formulario = null, senal = null } = {}) {
    const cabeceras = { Accept: 'application/json' }

    const jwt = token()
    if (jwt) cabeceras.Authorization = `Bearer ${jwt}`

    if (cuerpo) cabeceras['Content-Type'] = 'application/json'

    let respuesta

    try {
        respuesta = await fetch(`${BASE}${ruta}`, {
            method: metodo,
            headers: cabeceras,
            body: formulario ?? (cuerpo ? JSON.stringify(cuerpo) : null),
            signal: senal,
            credentials: 'same-origin',
        })
    } catch (e) {
        // Sin red. No es un error del servidor: es una respuesta que aún no
        // llegó, y el llamador debe poder distinguirlo para reintentar.
        throw new ErrorApi('Sin conexión con el servidor', { esDeRed: true })
    }

    if (respuesta.status === 204) return null

    const texto = await respuesta.text()
    let datos = null

    try {
        datos = texto ? JSON.parse(texto) : null
    } catch {
        datos = { mensaje: texto }
    }

    if (!respuesta.ok) {
        /*
         * Un 500 en produccion llega como «Server Error» y nada mas: Laravel
         * no manda la traza cuando APP_DEBUG esta en false, y hace bien. Pero
         * ese texto en pantalla no le dice nada a quien lo ve ni a quien tiene
         * que arreglarlo, y la primera pregunta que hay que hacerle a quien
         * reporta el fallo es siempre la misma: a que hora fue y que ruta era.
         *
         * Asi que se reemplaza por algo que si se puede usar: que fallo del
         * lado del servidor, a que hora, y donde mirar.
         */
        if (respuesta.status >= 500) {
            const hora = new Date().toLocaleTimeString('es-CO', { hour12: false })

            throw new ErrorApi(
                `El servidor no pudo responder (${respuesta.status}) a las ${hora}. `
                + `El detalle quedo en el registro del servidor; indique esta hora y «${ruta}» al area de TIC.`,
                { estado: respuesta.status, cuerpo: datos, ruta }
            )
        }

        throw new ErrorApi(
            datos?.mensaje ?? datos?.message ?? `Error ${respuesta.status}`,
            { estado: respuesta.status, cuerpo: datos }
        )
    }

    return datos
}

/**
 * Cadena de consulta que sabe de listas.
 *
 * `URLSearchParams` convierte un arreglo en «1,2,3», y eso le llega al servidor
 * como una sola cadena. Los filtros de los informes son multiples por
 * naturaleza —comparar tres clientes a la vez es el caso de uso, no la
 * excepcion—, asi que cada elemento va como `clave[]`.
 */
function parametros(filtros) {
    const q = new URLSearchParams()

    for (const [clave, valor] of Object.entries(filtros)) {
        if (valor === null || valor === undefined || valor === '') continue

        if (Array.isArray(valor)) {
            valor.forEach((v) => q.append(`${clave}[]`, v))
        } else {
            q.append(clave, valor)
        }
    }

    return q.toString()
}

export const api = {
    // ------------------------------------------------------------- ingreso
    ingresar: (email, password) =>
        peticion('/auth/ingresar', {
            metodo: 'POST',
            cuerpo: {
                email,
                password,
                // Identifica el dispositivo para poder revocarlo solo a él.
                dispositivo: navigator.userAgent.slice(0, 100),
            },
        }),

    yo: () => peticion('/auth/yo'),

    salir: () => peticion('/auth/salir', { metodo: 'POST' }),

    // ------------------------------------------------------------ consulta
    /** Una sola caja: guía Avalia, referencia del cliente o código de etiqueta. */
    rastrear: (q, tipo = null) =>
        peticion(`/rastreo?q=${encodeURIComponent(q)}${tipo ? `&tipo=${tipo}` : ''}`),

    rastrearLote: (claves, detalle = false) =>
        peticion('/rastreo/lote', { metodo: 'POST', cuerpo: { claves, detalle } }),

    soporte: (guia) => peticion(`/envios/${encodeURIComponent(guia)}/soporte`),

    // --------------------------------------------------------------- mapas
    /** Ruta trazada, rastro real y dictamen de desvío de un envío. */
    mapaEnvio: (guia, senal = null) =>
        peticion(`/envios/${encodeURIComponent(guia)}/mapa`, { senal }),

    // ------------------------------------------------------------- tramos
    // ------------------------------------------------------ puntos pactados
    puntos: (estado = null) => peticion(`/puntos${estado ? `?estado=${estado}` : ''}`),

    proponerPunto: (datos) => peticion('/puntos', { metodo: 'POST', cuerpo: datos }),

    revisarPunto: (id, decision, motivo = null) =>
        peticion(`/despacho/puntos/${id}/revisar`, {
            metodo: 'POST', cuerpo: { decision, ...(motivo ? { motivo } : {}) },
        }),

    catalogoDespacho: () => peticion('/despacho/catalogo'),

    tramos: (paqueteId) => peticion(`/despacho/paquetes/${paqueteId}/tramos`),

    armarTramos: (paqueteId, centroId = null) =>
        peticion(`/despacho/paquetes/${paqueteId}/tramos`, {
            metodo: 'POST', cuerpo: centroId ? { centro_id: Number(centroId) } : {},
        }),

    trasbordar: (paqueteId, centroId) =>
        peticion(`/despacho/paquetes/${paqueteId}/trasbordo`, {
            metodo: 'POST', cuerpo: { centro_id: centroId },
        }),

    asignarTramo: (tramoId, datos) =>
        peticion(`/despacho/tramos/${tramoId}/asignar`, { metodo: 'POST', cuerpo: datos }),

    cerrarTramo: (tramoId, prueba) =>
        peticion(`/campo/tramos/${tramoId}/cerrar`, { metodo: 'POST', cuerpo: prueba }),

    /** Dónde está cada mensajero ahora. Solo operación. */
    mapaFlota: (senal = null) => peticion('/despacho/mapa', { senal }),

    emitirEnlace: (guia, dias = 30) =>
        peticion(`/envios/${encodeURIComponent(guia)}/enlace`, {
            metodo: 'POST', cuerpo: { dias_vigencia: dias },
        }),

    /** La exportación no pasa por fetch: es una descarga del navegador. */
    urlExportacion: (parametros) => {
        const q = new URLSearchParams(parametros).toString()
        return `${BASE}/envios/exportar?${q}`
    },

    // ------------------------------------------------------------- órdenes
    ordenes: (filtros = {}, senal = null) =>
        peticion(`/ordenes?${new URLSearchParams(filtros)}`, { senal }),
    catalogoRadicar: () => peticion('/ordenes/catalogo'),
    crearOrden: (datos) => peticion('/ordenes', { metodo: 'POST', cuerpo: datos }),

    /** Cancela una guía radicada por error. No borra: deja constancia. */
    cancelarOrden: (guia, motivo) =>
        peticion(`/ordenes/${encodeURIComponent(guia)}/cancelar`, {
            metodo: 'POST', cuerpo: { motivo },
        }),

    // Enlace firmado y temporal para la guía imprimible. Ver imprimirGuia()
    // en Radicar.vue: la pestaña de impresión no lleva el token de sesión.
    enlaceImpresion: (guia) => peticion(`/ordenes/${encodeURIComponent(guia)}/enlace-impresion`),

    // ---------------------------------------------------------- telemetría
    telemetriaEnVivo: () => peticion('/telemetria/en-vivo'),
    serieDispositivo: (id, horas = 24) => peticion(`/telemetria/dispositivos/${id}/serie?horas=${horas}`),
    excursiones: (estado = null) =>
        peticion(`/telemetria/excursiones${estado ? `?estado=${estado}` : ''}`),
    atenderExcursion: (id, resolucion, descartar = false) =>
        peticion(`/telemetria/excursiones/${id}/atender`, {
            metodo: 'POST', cuerpo: { resolucion, descartar },
        }),

    // ------------------------------------------------------ administración
    adminResumen: () => peticion('/admin/resumen'),
    adminUsuarios: (filtros = {}) => peticion(`/admin/usuarios?${new URLSearchParams(filtros)}`),
    adminAlternarUsuario: (id) => peticion(`/admin/usuarios/${id}/alternar`, { metodo: 'POST' }),
    adminRestablecerClave: (id, justificacion = null) =>
        peticion(`/admin/usuarios/${id}/clave`, { metodo: 'POST', cuerpo: { justificacion } }),
    adminParametros: () => peticion('/admin/parametros'),
    adminGuardarParametros: (parametros) =>
        peticion('/admin/parametros', { metodo: 'PUT', cuerpo: { parametros } }),
    adminAuditoria: (filtros = {}) => peticion(`/admin/auditoria?${new URLSearchParams(filtros)}`),
    adminVerificarCadena: () => peticion('/admin/auditoria/verificar', { metodo: 'POST' }),
    /*
     * Informes. Los filtros van por separado porque la pantalla los necesita
     * para pintar los selectores antes de tener un rango que consultar.
     */
    adminInformes: (filtros = {}) => peticion(`/admin/informes?${parametros(filtros)}`),
    adminInformeFiltros: () => peticion('/admin/informes/filtros'),

    /** Reabre una entrega cerrada por error. No borra la evidencia anterior. */
    reabrirEntrega: (paqueteId, motivo) =>
        peticion(`/despacho/paquetes/${paqueteId}/reabrir-entrega`, {
            metodo: 'POST', cuerpo: { motivo },
        }),

    adminFlota: () => peticion('/admin/flota'),
    adminClientes: () => peticion('/admin/clientes'),

    // ------------------------------------------------- catálogo maestro
    // Lo que hay que cargar antes de poder radicar: clientes, sedes, zonas,
    // vehículos, mensajeros y sondas.
    crearCliente: (d) => peticion('/admin/clientes', { metodo: 'POST', cuerpo: d }),
    actualizarCliente: (id, d) => peticion(`/admin/clientes/${id}`, { metodo: 'PUT', cuerpo: d }),
    guardarIntegracion: (id, d) => peticion(`/admin/clientes/${id}/integracion`, { metodo: 'PUT', cuerpo: d }),

    resumenCampo: () => peticion('/campo/resumen'),
    /** Entrada y salida de jornada: de aquí sale la «hora de entrada». */
    marcarJornada: (accion) => peticion('/campo/jornada', { metodo: 'POST', cuerpo: { accion } }),

    abrirPausa: (d) => peticion('/campo/pausas', { metodo: 'POST', cuerpo: d }),
    cerrarPausa: (d) => peticion('/campo/pausas/cerrar', { metodo: 'POST', cuerpo: d ?? {} }),
    enCurso: () => peticion('/despacho/en-curso'),

    /** Lo que lleva días parado, y en manos de quién. */
    custodiaProlongada: (dias = 3) => peticion(`/despacho/custodia-prolongada?dias=${dias}`),
    soportesPendientes: () => peticion('/despacho/soportes-pendientes'),
    reenviarSoporte: (id) => peticion(`/despacho/evidencias/${id}/reenviar`, { metodo: 'POST' }),
    alternarCliente: (id) => peticion(`/admin/clientes/${id}/alternar`, { metodo: 'POST' }),

    crearSede: (d) => peticion('/admin/sedes', { metodo: 'POST', cuerpo: d }),
    actualizarSede: (id, d) => peticion(`/admin/sedes/${id}`, { metodo: 'PUT', cuerpo: d }),
    alternarSede: (id) => peticion(`/admin/sedes/${id}/alternar`, { metodo: 'POST' }),

    crearZona: (d) => peticion('/admin/zonas', { metodo: 'POST', cuerpo: d }),
    actualizarZona: (id, d) => peticion(`/admin/zonas/${id}`, { metodo: 'PUT', cuerpo: d }),

    crearVehiculo: (d) => peticion('/admin/vehiculos', { metodo: 'POST', cuerpo: d }),
    actualizarVehiculo: (id, d) => peticion(`/admin/vehiculos/${id}`, { metodo: 'PUT', cuerpo: d }),
    alternarVehiculo: (id) => peticion(`/admin/vehiculos/${id}/alternar`, { metodo: 'POST' }),

    crearMensajero: (d) => peticion('/admin/mensajeros', { metodo: 'POST', cuerpo: d }),
    actualizarMensajero: (id, d) => peticion(`/admin/mensajeros/${id}`, { metodo: 'PUT', cuerpo: d }),

    crearDispositivo: (d) => peticion('/admin/dispositivos', { metodo: 'POST', cuerpo: d }),
    instalarDispositivo: (id, d) => peticion(`/admin/dispositivos/${id}/instalar`, { metodo: 'POST', cuerpo: d }),
    rotarSecreto: (id) => peticion(`/admin/dispositivos/${id}/secreto`, { metodo: 'POST' }),

    // ------------------------------------------------------------- tablero
    tablero: (dias = 30) => peticion(`/tablero?dias=${dias}`),

    // ------------------------------------------------------------ despacho
    bandeja: () => peticion('/despacho/bandeja'),
    candidatos: (paqueteId) => peticion(`/despacho/paquetes/${paqueteId}/candidatos`),
    asignar: (paqueteId, mensajeroId, motivo = null) =>
        peticion(`/despacho/paquetes/${paqueteId}/asignar`, {
            metodo: 'POST', cuerpo: { mensajero_id: mensajeroId, motivo },
        }),

    // --------------------------------------------------------------- campo
    tareas: () => peticion('/campo/tareas'),

    posiciones: (posiciones, bateria = null) =>
        peticion('/campo/posiciones', { metodo: 'POST', cuerpo: { posiciones, bateria } }),

    recolectar: (paqueteId, datos) =>
        peticion(`/campo/paquetes/${paqueteId}/recolectar`, { metodo: 'POST', cuerpo: datos }),

    entregar: (paqueteId, datos) =>
        peticion(`/campo/paquetes/${paqueteId}/entregar`, { metodo: 'POST', cuerpo: datos }),

    novedad: (paqueteId, datos) =>
        peticion(`/campo/paquetes/${paqueteId}/novedad`, { metodo: 'POST', cuerpo: datos }),

    subirEvidencia: (paqueteId, formulario) =>
        peticion(`/campo/paquetes/${paqueteId}/evidencias`, { metodo: 'POST', formulario }),

    catalogos: () => peticion('/catalogos'),
}
