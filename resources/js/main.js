import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { sesion } from './sesion'
import { iniciarSincronizacion } from './offline/sincronizador'

/**
 * Retira la pantalla de carga que `app.blade.php` deja pintada en el HTML.
 *
 * Se llama cuando el enrutador ya resolvió la primera vista, no cuando monta la
 * aplicación: montar solo garantiza el cascarón, y quitar el velo ahí deja ver
 * un hueco vacío mientras llega el componente de la ruta —que se carga por
 * demanda—. Esperar a `isReady` es lo que hace que al desaparecer el velo haya
 * algo debajo.
 */
function retirarPantallaDeCarga() {
    const velo = document.getElementById('cargando')

    if (velo === null) {
        return
    }

    velo.classList.add('se-va')
    velo.addEventListener('transitionend', () => velo.remove(), { once: true })

    // Red de seguridad: en una pestaña en segundo plano el navegador no dispara
    // `transitionend`, y el velo se quedaría puesto para siempre.
    setTimeout(() => velo.remove(), 600)
}

/**
 * La sesión se restaura ANTES de montar. Si no, la guardia de navegación corre
 * con `autenticado` todavía en falso y manda al ingreso a alguien que ya tenía
 * sesión válida — el parpadeo clásico de las aplicaciones de una sola página.
 */
sesion.restaurar().finally(() => {
    const app = createApp(App)

    app.use(createPinia())
    app.use(router)

    // El sincronizador arranca con la aplicación, no cuando alguien entra a la
    // pantalla de campo: si el mensajero cerró la aplicación con entregas en la
    // bandeja de salida, esas entregas tienen que salir en cuanto haya señal.
    iniciarSincronizacion()

    app.mount('#app')

    // `finally` y no `then`: si la primera ruta falla al resolverse, el velo
    // igual tiene que irse. Dejarlo puesto convertiría un error de una vista en
    // una aplicación que no abre.
    router.isReady().finally(retirarPantallaDeCarga)
})
