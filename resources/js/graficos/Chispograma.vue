<script setup>
import { computed } from 'vue'
import { dominioAgradable, escalaLineal, rutaArea, rutaLinea } from './util'

/**
 * Línea de contexto para un indicador. No lleva ejes ni tooltip: su trabajo es
 * decir «viene subiendo» o «viene cayendo» en el espacio de una palabra. El
 * último punto va marcado, porque es el valor que el titular está mostrando.
 *
 * Se dibuja en un `viewBox` fijo y se escala por CSS al ancho que le deje su
 * contenedor. Fijar el ancho en píxeles fue un error: en cuanto la tarjeta se
 * estrechaba, el SVG no encogía y se salía del recuadro.
 */
const props = defineProps({
    valores: { type: Array, required: true },
    color: { type: String, default: 'var(--acento)' },
    ancho: { type: Number, default: 120 },
    /*
     * Proporcion achatada a proposito.
     *
     * El SVG escala uniformemente al ancho que le deje la tarjeta: con un
     * lienzo de 120x32, a 180 px de ancho pedia 48 px de alto y se recortaba
     * contra el borde inferior. Bajar el alto del lienzo da la altura correcta
     * SIN deformar -que es lo que pasaria con preserveAspectRatio="none", donde
     * la pendiente miente y el punto final se vuelve una elipse-.
     */
    alto: { type: Number, default: 21 },
})

/**
 * Margen interior. El punto final es un círculo de radio 2,75 con anillo de
 * 1,5: sin este margen su borde cae fuera del lienzo y hay que recurrir a
 * `overflow: visible`, que es justamente lo que deja escapar el dibujo.
 */
const M = 4

const geo = computed(() => {
    const limpios = props.valores.filter((v) => v !== null && v !== undefined && !Number.isNaN(v))

    if (limpios.length < 2) return null

    const x = escalaLineal([0, props.valores.length - 1], [M, props.ancho - M])
    const y = escalaLineal(dominioAgradable(limpios, { desdeCero: false }), [props.alto - M, M])

    const puntos = props.valores.map((v, i) => ({
        x: x(i),
        y: v === null || v === undefined ? null : y(v),
    }))

    return {
        linea: rutaLinea(puntos),
        area: rutaArea(puntos, props.alto),
        ultimo: [...puntos].reverse().find((p) => p.y !== null),
    }
})
</script>

<template>
    <svg
        v-if="geo"
        class="chispa"
        :viewBox="`0 0 ${ancho} ${alto}`"
        preserveAspectRatio="xMidYMid meet"
        role="presentation"
        aria-hidden="true"
        focusable="false"
    >
        <path :d="geo.area" :fill="color" opacity=".09" />
        <path
            :d="geo.linea" fill="none" :stroke="color" stroke-width="1.5"
            stroke-linecap="round" stroke-linejoin="round"
        />
        <circle
            v-if="geo.ultimo"
            :cx="geo.ultimo.x" :cy="geo.ultimo.y" r="2.4"
            :fill="color" stroke="var(--superficie)" stroke-width="1.5"
        />
    </svg>
</template>

<style scoped>
.chispa {
    display: block;
    width: 100%;
    height: auto;
    /* Recorta en vez de desbordar. Con el margen interior de arriba no hay nada
       que recortar, pero si algún día lo hay, se queda dentro del recuadro. */
    overflow: hidden;
}
</style>
