import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Ancho real del contenedor.
 *
 * Las gráficas se dibujan en coordenadas de píxel y no con `viewBox` escalado,
 * porque escalar un `viewBox` estira también el texto y los grosores: en un
 * teléfono terminaría con ejes de 4 px de alto y líneas gruesas. Medir el
 * contenedor y redibujar cuesta un `ResizeObserver` y evita todo eso.
 */
export function useTamano(alturaPorDefecto = 220) {
    const contenedor = ref(null)
    const ancho = ref(640)
    const alto = ref(alturaPorDefecto)

    let observador = null

    onMounted(() => {
        if (!contenedor.value) return

        const medir = () => {
            const w = contenedor.value?.clientWidth ?? 640
            ancho.value = Math.max(240, w)
        }

        medir()

        observador = new ResizeObserver(medir)
        observador.observe(contenedor.value)
    })

    onBeforeUnmount(() => observador?.disconnect())

    return { contenedor, ancho, alto }
}
