<script setup>
/**
 * El logotipo de Avalia Logística.
 *
 * Va incrustado en el documento —no como <img>— porque las piezas se pintan con
 * `currentColor`. Dentro de una imagen ese color no hereda nada y el logotipo
 * saldría negro; incrustado toma el color de quien lo contiene, y así el MISMO
 * archivo sirve sobre la barra clara y sobre el panel azul noche.
 *
 * Los archivos se leen de `public/marca/` con `?raw`, que Vite resuelve en
 * tiempo de compilación. Se importan de ahí, y no de una copia dentro de
 * `resources/`, para que exista un solo original: el sitio público los sirve
 * desde esa misma carpeta, y dos copias del logotipo terminan divergiendo.
 */
import principal from '../../../public/marca/avalia-principal.svg?raw'
import eslogan   from '../../../public/marca/avalia-eslogan.svg?raw'
import palabra   from '../../../public/marca/avalia-palabra.svg?raw'
import icono     from '../../../public/marca/avalia-icono.svg?raw'

const piezas = { principal, eslogan, palabra, icono }

// La proporción de cada archivo, medida sobre el dibujo real. Solo se fija el
// ancho: la altura la calcula el navegador con el viewBox. Fijar las dos es lo
// que deforma un logotipo.
const props = defineProps({
    pieza: {
        type: String,
        default: 'principal',
        // La lista va literal y no derivada de `piezas`: `defineProps` se iza
        // fuera de `setup()` y no alcanza a ver las variables del módulo.
        validator: (v) => ['principal', 'eslogan', 'palabra', 'icono'].includes(v),
    },
    // Ancho en píxeles, o cualquier medida CSS válida.
    ancho: { type: [Number, String], default: 150 },
    // Un logotipo junto al nombre de la empresa escrito al lado es redundante
    // para quien usa lector de pantalla: en ese caso se marca decorativo.
    decorativo: { type: Boolean, default: false },
})
</script>

<template>
    <span
        class="logo-avalia"
        :style="{ width: typeof ancho === 'number' ? ancho + 'px' : ancho }"
        :aria-hidden="decorativo ? 'true' : null"
        v-html="piezas[pieza]"
    />
</template>

<style scoped>
.logo-avalia {
    display: block;
    color: inherit;
}

/* `:deep` porque el SVG entra por v-html y no lleva el atributo de alcance que
   Vue le pone al resto del componente. */
.logo-avalia :deep(svg) {
    display: block;
    width: 100%;
    height: auto;
}
</style>
