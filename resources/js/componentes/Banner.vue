<script setup>
/**
 * Franja informativa con acción a la derecha.
 *
 * Sirve para lo que no es un error ni un dato, sino una instrucción que el
 * usuario necesita antes de empezar: «esto se imprime al guardar», «este
 * cliente exige referencia». Va arriba del formulario, no en un tooltip que
 * hay que descubrir.
 */
defineProps({
    titulo: { type: String, required: true },
    texto: { type: String, default: '' },
    /** info | atencion | critico | bien */
    tono: { type: String, default: 'info' },
})
</script>

<template>
    <div class="banner" :class="`banner--${tono}`">
        <span class="marca" aria-hidden="true" />

        <div class="txt">
            <p class="tit">{{ titulo }}</p>
            <p v-if="texto" class="cuerpo">{{ texto }}</p>
            <slot />
        </div>

        <div v-if="$slots.accion" class="accion"><slot name="accion" /></div>
    </div>
</template>

<style scoped>
.banner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .8rem 1.2rem;
    padding: .9rem 1.1rem;
    border-radius: var(--r-lg);
    border: 1px solid;
}

.marca { width: 4px; align-self: stretch; border-radius: 3px; flex: none; min-height: 34px; }

.txt { flex: 1 1 320px; min-width: 0; }
.tit { font-size: .89rem; font-weight: 700; margin: 0; letter-spacing: -.01em; }
.cuerpo { font-size: .8rem; line-height: 1.55; margin: .25rem 0 0; }
.accion { flex: none; display: flex; gap: .45rem; flex-wrap: wrap; }

.banner--info {
    background: var(--acento-suave);
    border-color: color-mix(in srgb, var(--acento) 35%, transparent);
    color: var(--acento-ink);
}
.banner--info .marca { background: var(--acento); }

.banner--atencion { background: var(--atencion-suave); border-color: var(--atencion); color: var(--ink); }
.banner--atencion .marca { background: var(--atencion); }

.banner--critico { background: var(--critico-suave); border-color: var(--critico); color: var(--critico); }
.banner--critico .marca { background: var(--critico); }

.banner--bien { background: var(--bien-suave); border-color: var(--bien); color: var(--bien-ink); }
.banner--bien .marca { background: var(--bien); }
</style>
