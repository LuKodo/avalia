<script setup>
/**
 * Sección de formulario: tarjeta con encabezado y rejilla de campos.
 *
 * Los formularios largos sin secciones se leen como un muro. Partirlos en
 * bloques con título propio deja claro qué se está diligenciando y permite
 * volver a uno sin releer todo.
 */
defineProps({
    titulo: { type: String, required: true },
    descripcion: { type: String, default: '' },
    numero: { type: [String, Number], default: null },
    /** Ancho mínimo de cada campo antes de que la rejilla reacomode. */
    minCampo: { type: Number, default: 210 },
})
</script>

<template>
    <section class="seccion">
        <header class="cab">
            <div class="cab-txt">
                <h2 class="tit">
                    <span v-if="numero !== null" class="num-sec" aria-hidden="true">{{ numero }}</span>
                    {{ titulo }}
                </h2>
                <p v-if="descripcion" class="desc">{{ descripcion }}</p>
            </div>
            <div v-if="$slots.acciones" class="cab-acc"><slot name="acciones" /></div>
        </header>

        <div class="cuerpo">
            <div class="rejilla" :style="{ '--min-campo': `${minCampo}px` }">
                <slot />
            </div>
            <slot name="pie" />
        </div>
    </section>
</template>

<style scoped>
.seccion {
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-radius: var(--r-lg);
    overflow: hidden;
}

.cab {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem 1rem;
    align-items: flex-start;
    justify-content: space-between;
    padding: 1rem 1.15rem .85rem;
    border-bottom: 1px solid var(--linea);
    background: linear-gradient(180deg, var(--superficie-2) 0%, var(--superficie) 100%);
}

.cab-txt { min-width: 0; }

.tit {
    display: flex;
    align-items: center;
    gap: .55rem;
    font-size: 1.02rem;
    font-weight: 700;
    letter-spacing: -.02em;
    margin: 0;
    color: var(--ink);
}

/* El número no es decoración: estos formularios sí son una secuencia, y saber
   «voy en el 2 de 4» reduce el abandono a la mitad. */
.num-sec {
    width: 24px;
    height: 24px;
    flex: none;
    border-radius: 7px;
    display: grid;
    place-items: center;
    background: var(--acento);
    color: var(--sobre-acento);
    font-family: var(--mono);
    font-size: .72rem;
    font-weight: 700;
}

.desc {
    font-size: .81rem;
    color: var(--ink-2);
    line-height: 1.5;
    margin: .35rem 0 0;
    max-width: 76ch;
}

.cab-acc { display: flex; gap: .4rem; flex-wrap: wrap; }

.cuerpo { padding: 1.05rem 1.15rem 1.15rem; }

.rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(var(--min-campo), 1fr));
    gap: .9rem 1rem;
}
</style>
