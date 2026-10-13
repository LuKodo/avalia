import { createRouter, createWebHistory } from 'vue-router'
import { BASE_APP } from './base'
import { sesion } from './sesion'

/**
 * Una sola aplicación responsive para tres audiencias.
 *
 * No hay aplicación nativa: la misma interfaz sirve al operador en un monitor
 * de 27 pulgadas y al mensajero en un teléfono de gama media, cambiando la
 * densidad y el tamaño de los objetivos táctiles, no el código. Esa decisión
 * eliminó dos publicaciones en tiendas y el ciclo de aprobación que traen.
 *
 * Las vistas se cargan por demanda: el mensajero no descarga el panel de
 * despacho ni el operador la lógica de la cámara.
 */
const rutas = [
    {
        path: '/',
        redirect: () => sesion.usuario.value?.inicio ?? '/ingreso',
    },
    {
        path: '/ingreso',
        name: 'ingreso',
        component: () => import('./paginas/Ingreso.vue'),
        meta: { titulo: 'Ingresar', publico: true, sinCascaron: true },
    },
    {
        path: '/tablero',
        name: 'tablero',
        component: () => import('./paginas/Tablero.vue'),
        meta: { titulo: 'Tablero', densidad: 'escritorio' },
    },
    {
        path: '/rastreo',
        name: 'rastreo',
        component: () => import('./paginas/Rastreo.vue'),
        // Exige sesión: la consulta interna devuelve datos del receptor y el
        // alcance por cliente se decide con la identidad de quien pregunta.
        // El rastreo verdaderamente público es el de abajo, por enlace firmado.
        meta: { titulo: 'Rastrear envío' },
    },
    {
        // Puntos pactados. La misma pantalla sirve al cliente -que propone- y
        // a la operacion -que revisa-; el componente decide que mostrar segun
        // el rol, y el servidor acota lo que cada uno puede ver y hacer.
        path: '/puntos',
        name: 'puntos',
        component: () => import('./paginas/PuntosPactados.vue'),
        meta: { titulo: 'Puntos pactados', densidad: 'escritorio' },
    },
    {
        // Historico del cliente. Sin restriccion de rol: la operacion tambien
        // lo usa para mirar lo de un cliente, y el servidor acota por
        // `cliente_id` a quien tenga uno.
        path: '/solicitudes',
        name: 'solicitudes',
        component: () => import('./paginas/MisSolicitudes.vue'),
        meta: { titulo: 'Mis solicitudes', densidad: 'escritorio' },
    },
    {
        path: '/mapa',
        name: 'mapa.flota',
        component: () => import('./paginas/MapaFlota.vue'),
        meta: { titulo: 'Mapa de flota', rol: 'operador', densidad: 'escritorio' },
    },
    {
        // El mapa de UN envio. Lo abre tanto la operacion como el cliente
        // dueno del envio; quien puede verlo lo decide el servidor.
        path: '/envios/:guia/mapa',
        name: 'mapa.envio',
        component: () => import('./paginas/MapaEnvio.vue'),
        meta: { titulo: 'Recorrido del envio' },
    },
    {
        path: '/rastreo/:token',
        name: 'rastreo.publico',
        component: () => import('./paginas/Rastreo.vue'),
        meta: { titulo: 'Seguimiento', publico: true },
        props: true,
    },
    {
        path: '/verificar',
        name: 'verificar',
        component: () => import('./paginas/Verificar.vue'),
        // Publica y sin cascaron: quien llega aqui no trabaja en Avalia, viene
        // con un certificado en la mano y probablemente no vuelva. Pedirle
        // sesion para comprobar un documento que ya tiene seria absurdo.
        meta: { titulo: 'Verificar soporte', publico: true, sinCascaron: true },
    },
    {
        path: '/despacho',
        name: 'despacho',
        component: () => import('./paginas/Despacho.vue'),
        meta: { titulo: 'Despacho', rol: 'operador', densidad: 'escritorio' },
    },
    {
        path: '/radicar',
        name: 'radicar',
        component: () => import('./paginas/Radicar.vue'),
        meta: { titulo: 'Radicar envío', densidad: 'escritorio' },
    },
    {
        path: '/telemetria',
        name: 'telemetria',
        component: () => import('./paginas/Telemetria.vue'),
        meta: { titulo: 'Cadena de frío en vivo', rol: 'operador', densidad: 'escritorio' },
    },

    // ------------------------------------------------------- administración
    {
        path: '/admin',
        name: 'admin',
        component: () => import('./paginas/admin/Panel.vue'),
        meta: { titulo: 'Panel de control', rol: 'administrador', densidad: 'escritorio' },
    },
    {
        path: '/admin/usuarios',
        name: 'admin.usuarios',
        component: () => import('./paginas/admin/Usuarios.vue'),
        meta: { titulo: 'Usuarios', rol: 'administrador', densidad: 'escritorio' },
    },
    {
        path: '/admin/clientes',
        name: 'admin.clientes',
        component: () => import('./paginas/admin/Clientes.vue'),
        meta: { titulo: 'Clientes y sedes', rol: 'administrador', densidad: 'escritorio' },
    },
    {
        path: '/admin/flota',
        name: 'admin.flota',
        component: () => import('./paginas/admin/Flota.vue'),
        meta: { titulo: 'Flota y dispositivos', rol: 'administrador', densidad: 'escritorio' },
    },
    {
        path: '/admin/parametros',
        name: 'admin.parametros',
        component: () => import('./paginas/admin/Parametros.vue'),
        meta: { titulo: 'Parámetros', rol: 'administrador', densidad: 'escritorio' },
    },
    {
        path: '/admin/auditoria',
        name: 'admin.auditoria',
        component: () => import('./paginas/admin/Auditoria.vue'),
        meta: { titulo: 'Auditoría', rol: 'administrador', densidad: 'escritorio' },
    },
    {
        path: '/campo',
        name: 'campo',
        component: () => import('./paginas/Campo.vue'),
        meta: { titulo: 'Mis tareas', rol: 'mensajero', densidad: 'campo' },
    },
    {
        /*
         * A donde lleva el QR de la etiqueta.
         *
         * El codigo del paquete va en la direccion para que la camara nativa
         * del telefono pueda abrirla: un escaner dentro de la aplicacion
         * necesitaria permiso de camara, que los navegadores solo dan sobre
         * HTTPS.
         */
        path: '/campo/recoger/:codigo',
        name: 'campo.recoger',
        component: () => import('./paginas/CampoRecoger.vue'),
        meta: { titulo: 'Confirmar recoleccion', rol: 'mensajero', densidad: 'campo' },
    },
    {
        path: '/campo/:paqueteId/entrega',
        name: 'campo.entrega',
        component: () => import('./paginas/CampoEntrega.vue'),
        meta: { titulo: 'Prueba de entrega', rol: 'mensajero', densidad: 'campo' },
        props: true,
    },
    {
        path: '/:resto(.*)*',
        name: 'noEncontrado',
        component: () => import('./paginas/NoEncontrado.vue'),
        meta: { titulo: 'Página no encontrada', publico: true },
    },
]

export const router = createRouter({
    // La base del historial: '/' en la raiz, '/Avalia' bajo subcarpeta.
    history: createWebHistory(BASE_APP || '/'),
    routes: rutas,
    scrollBehavior: () => ({ top: 0 }),
})

/**
 * Guardia de navegación.
 *
 * Es comodidad de interfaz, no seguridad: quien manipule el JavaScript puede
 * saltárselo. La autorización real la aplican las políticas del backend sobre
 * cada consulta, que es lo único que no se puede eludir desde el navegador.
 */
router.beforeEach((destino) => {
    if (destino.meta.publico) {
        return true
    }

    if (!sesion.autenticado.value) {
        return { name: 'ingreso', query: { destino: destino.fullPath } }
    }

    // El rol equivocado no ve un error: se le lleva a donde sí trabaja.
    if (destino.meta.rol && !sesion.tiene(destino.meta.rol, 'administrador')) {
        return sesion.usuario.value?.inicio ?? '/tablero'
    }

    return true
})

router.afterEach((a) => {
    document.title = a.meta.titulo ? `${a.meta.titulo} · Avalia Logística` : 'Avalia Logística'
})

/*
 * Recuperarse de una version vieja en cache.
 *
 * Cada pantalla se carga bajo demanda, y su archivo lleva un codigo en el
 * nombre que cambia con cada compilacion -Tablero-C7px9TAu.js pasa a ser
 * Tablero-D2i6ZmSt.js-. Si el navegador tiene guardado el arranque anterior,
 * al pulsar un menu pide un archivo que ya no existe: la carga falla, el
 * enrutador se queda donde estaba y el usuario ve que el clic «no hace nada».
 * Ni un error en pantalla, ni una pista.
 *
 * La salida es recargar la pagina entera en el destino: eso vuelve a pedir el
 * documento al servidor, que entrega el arranque nuevo con los nombres buenos.
 *
 * El centinela en sessionStorage evita el bucle: si tras recargar vuelve a
 * fallar, el archivo falta de verdad en el servidor y no es cosa de la cache.
 * Entonces se deja pasar el error, que es lo honesto.
 */
router.onError((error, destino) => {
    const esFragmentoQueYaNoExiste = /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i
        .test(error?.message ?? '')

    if (! esFragmentoQueYaNoExiste) {
        return
    }

    const centinela = 'avalia.recargando'

    if (sessionStorage.getItem(centinela) === destino.fullPath) {
        sessionStorage.removeItem(centinela)
        console.error('El fragmento sigue sin estar despues de recargar:', error)

        return
    }

    try {
        sessionStorage.setItem(centinela, destino.fullPath)
    } catch {
        // Modo privado o almacenamiento bloqueado: se recarga igual, sin red.
    }

    window.location.assign(destino.fullPath)
})
