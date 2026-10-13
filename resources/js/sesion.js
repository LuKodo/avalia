import { computed, reactive } from 'vue'
import { api } from './api/cliente'

/**
 * Sesión del usuario.
 *
 * El token vive en `localStorage` y no en una cookie de sesión, porque la
 * aplicación de campo tiene que seguir autenticada después de cerrarse y
 * reabrirse sin señal — una cookie de sesión no sobrevive a eso.
 *
 * A cambio hay que ser estricto con dos cosas: el token caduca a los 30 días y
 * se borra ante cualquier 401, para que un dispositivo perdido deje de servir
 * en cuanto se revoque el token del lado del servidor.
 */
const estado = reactive({
    usuario: null,
    cargando: true,
})

export const sesion = {
    estado,

    usuario: computed(() => estado.usuario),
    autenticado: computed(() => estado.usuario !== null),
    rol: computed(() => estado.usuario?.rol ?? null),

    /** Etiqueta que el cliente le da a SU referencia; la interfaz la usa tal cual. */
    etiquetaReferencia: computed(() => estado.usuario?.etiqueta_referencia ?? 'referencia'),

    tiene(...roles) {
        return roles.includes(estado.usuario?.rol)
    },

    async ingresar(email, password) {
        const r = await api.ingresar(email, password)

        localStorage.setItem('ltp.token', r.token)
        estado.usuario = r.usuario

        return r.usuario
    },

    async salir() {
        try {
            await api.salir()
        } catch {
            // Si el servidor no responde, la sesión local se cierra igual: dejar
            // al usuario dentro porque no hubo red sería peor.
        }

        localStorage.removeItem('ltp.token')
        estado.usuario = null
    },

    /** Restaura la sesión al arrancar. Se llama una sola vez, desde main.js. */
    async restaurar() {
        estado.cargando = true

        if (!localStorage.getItem('ltp.token')) {
            estado.usuario = null
            estado.cargando = false

            return null
        }

        try {
            const r = await api.yo()
            estado.usuario = r.usuario
        } catch (e) {
            // Un fallo de red no invalida la sesión: sin señal, la aplicación de
            // campo sigue siendo utilizable con lo que tiene guardado. Solo un
            // rechazo explícito del servidor cierra la sesión.
            if (!e.esDeRed && e.estado === 401) {
                localStorage.removeItem('ltp.token')
                estado.usuario = null
            }
        } finally {
            estado.cargando = false
        }

        return estado.usuario
    },
}
