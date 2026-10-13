<script setup>
import { computed } from 'vue'
import { conBase } from '../base'
import { sesion } from '../sesion'
import Logo from '../componentes/Logo.vue'

/**
 * Página de dirección inexistente.
 *
 * Tiene dos caras, y la diferencia importa más de lo que parece.
 *
 * CON sesión, vive dentro de la aplicación y ofrece el atajo útil: casi
 * siempre quien llega aquí tecleó mal una guía.
 *
 * SIN sesión, es una puerta cerrada. No enseña el menú, ni el nombre de nadie,
 * ni a qué pantallas se podría ir: alguien que escribe una dirección al azar
 * no tiene por qué averiguar qué existe detrás. La única salida es el ingreso
 * o el sitio público.
 *
 * El control real está en el servidor —cada ruta exige token y rol—, pero una
 * pantalla que enumera lo que hay dentro le ahorra el trabajo a quien esté
 * probando, y no hay razón para regalárselo.
 */
const dentro = computed(() => sesion.autenticado.value)
</script>

<template>
    <!-- ------------------------------------------- con sesión: dentro de la app -->
    <div v-if="dentro" class="no">
        <p class="codigo">404</p>
        <h1 class="titulo">Esta página no existe</h1>
        <p class="texto">
            Si llegó buscando un envío, use el rastreo: acepta la guía Avalia,
            la referencia que usted definió al radicar o el código de la etiqueta.
        </p>
        <RouterLink to="/rastreo" class="btn">Ir al rastreo</RouterLink>
    </div>

    <!-- --------------------------------------- sin sesión: puerta cerrada -->
    <div v-else class="cerrado">
        <div class="tarjeta">
            <Logo pieza="principal" ancho="150px" decorativo />

            <p class="codigo">404</p>
            <h1 class="titulo">Esta dirección no existe</h1>

            <p class="texto">
                No hay nada en esa dirección, o hace falta una sesión para verlo.
            </p>

            <div class="acciones">
                <RouterLink to="/ingreso" class="btn">Ingresar</RouterLink>
                <a :href="conBase('/sitio')" class="enlace">Volver al sitio de Avalia</a>
            </div>

            <p class="pie">
                ¿Solo necesita consultar un envío? Use el enlace de rastreo que
                le compartieron; no requiere cuenta.
            </p>
        </div>
    </div>
</template>

<style scoped>
/* ------------------------------------------------------- con sesión */
.no { max-width: 460px; margin: 0 auto; padding: 4rem 1rem; text-align: center; }

.codigo {
    font-family: var(--mono);
    font-size: 3rem;
    font-weight: 600;
    color: var(--linea-fuerte);
    margin: 0;
    letter-spacing: -.04em;
}

.titulo { font-size: 1.25rem; font-weight: 600; margin: .3rem 0 .6rem; letter-spacing: -.02em; }
.texto { font-size: .88rem; color: var(--ink-2); line-height: 1.55; margin: 0 0 1.4rem; }

/* ------------------------------------------------------- sin sesión */
.cerrado {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 2rem 1rem;
    background: var(--superficie-2, #f4f7fb);
}

.tarjeta {
    width: 100%;
    max-width: 420px;
    background: var(--superficie, #fff);
    border: 1px solid var(--linea, #e3e9f2);
    border-radius: var(--r-lg, 14px);
    padding: 2.4rem 2rem;
    text-align: center;
    box-shadow: 0 1px 2px rgb(13 19 51 / .04), 0 8px 24px rgb(13 19 51 / .06);
}

.tarjeta .codigo { margin-top: 1.6rem; }

.acciones {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .8rem;
    margin-bottom: 1.6rem;
}

.enlace { font-size: .82rem; color: var(--ink-3); text-decoration: none; }
.enlace:hover { color: var(--acento); text-decoration: underline; }

.pie {
    font-size: .76rem;
    color: var(--ink-3);
    line-height: 1.5;
    margin: 0;
    padding-top: 1.2rem;
    border-top: 1px solid var(--linea, #e3e9f2);
}

/* ------------------------------------------------------------ común */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: var(--toque);
    padding: .6rem 1.3rem;
    background: var(--acento);
    color: var(--sobre-acento);
    border-radius: var(--r-md);
    font-weight: 600;
    font-size: .9rem;
    text-decoration: none;
}
</style>
