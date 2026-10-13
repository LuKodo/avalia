<script setup>
/**
 * Tarjeta contenedora de una tabla, con encabezado y barra de herramientas.
 *
 * Una tabla suelta sobre el fondo no dice de qué es ni cuántas filas tiene, y
 * obliga a leer los datos para deducirlo. El encabezado con conteo y la barra
 * de acciones son lo que la convierte en una herramienta.
 */
defineProps({
    titulo: { type: String, required: true },
    descripcion: { type: String, default: '' },
    total: { type: [Number, String], default: null },
    vacio: { type: Boolean, default: false },
    textoVacio: { type: String, default: 'No hay registros todavía.' },
})
</script>

<template>
    <section class="panel">
        <header class="cab">
            <div class="cab-txt">
                <h2 class="tit">
                    {{ titulo }}
                    <span v-if="total !== null" class="conteo">{{ total }}</span>
                </h2>
                <p v-if="descripcion" class="desc">{{ descripcion }}</p>
            </div>
            <div v-if="$slots.acciones" class="cab-acc"><slot name="acciones" /></div>
        </header>

        <div v-if="$slots.herramientas" class="herramientas">
            <slot name="herramientas" />
        </div>

        <p v-if="vacio" class="vacio">{{ textoVacio }}</p>

        <div v-else class="marco">
            <slot />
        </div>

        <footer v-if="$slots.pie" class="pie"><slot name="pie" /></footer>
    </section>
</template>

<style scoped>
.panel {
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-radius: var(--r-lg);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.cab {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem 1rem;
    align-items: flex-start;
    justify-content: space-between;
    padding: .95rem 1.1rem .8rem;
    border-bottom: 1px solid var(--linea);
    background: linear-gradient(180deg, var(--superficie-2) 0%, var(--superficie) 100%);
}

.cab-txt { min-width: 0; }

.tit {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: -.02em;
    margin: 0;
}

.conteo {
    font-family: var(--mono);
    font-size: .68rem;
    font-weight: 600;
    padding: .16rem .45rem;
    border-radius: 999px;
    background: var(--acento-suave);
    color: var(--acento-ink);
}

.desc { font-size: .79rem; color: var(--ink-2); line-height: 1.5; margin: .3rem 0 0; max-width: 74ch; }
.cab-acc { display: flex; gap: .45rem; flex-wrap: wrap; }

.herramientas {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    padding: .7rem 1.1rem;
    border-bottom: 1px solid var(--linea);
    background: var(--superficie);
}

/* El marco propio es lo que evita que una tabla ancha empuje la página entera
   hacia los lados en un portátil. */
.marco { overflow-x: auto; -webkit-overflow-scrolling: touch; }

.vacio {
    padding: 2.6rem 1.2rem;
    text-align: center;
    color: var(--ink-3);
    font-size: .86rem;
    margin: 0;
}

.pie {
    padding: .7rem 1.1rem;
    border-top: 1px solid var(--linea);
    font-size: .76rem;
    color: var(--ink-3);
    line-height: 1.5;
}
</style>
