<script setup>
/**
 * Cifra con barra de acento a la izquierda.
 *
 * La barra codifica el estado con posición y color a la vez. El color solo
 * jamás lleva el significado: la etiqueta y el pie dicen lo mismo en palabras.
 */
defineProps({
    etiqueta: { type: String, required: true },
    valor: { type: [String, Number], default: '—' },
    unidad: { type: String, default: '' },
    pie: { type: String, default: '' },
    /** neutro | bien | atencion | grave | critico */
    tono: { type: String, default: 'neutro' },
    a: { type: String, default: null },
})
</script>

<template>
    <component :is="a ? 'RouterLink' : 'div'" :to="a" class="cifra" :class="[`cifra--${tono}`, { 'cifra--enlace': a }]">
        <span class="barra" aria-hidden="true" />

        <span class="cuerpo">
            <span class="etq">{{ etiqueta }}</span>
            <span class="val">{{ valor }}<small v-if="unidad">{{ unidad }}</small></span>
            <span v-if="pie" class="pie">{{ pie }}</span>
        </span>
    </component>
</template>

<style scoped>
.cifra {
    display: flex;
    gap: .85rem;
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-radius: var(--r-md);
    padding: .85rem .95rem .85rem .6rem;
    min-width: 0;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
}

.cifra--enlace:hover { border-color: var(--linea-fuerte); background: var(--superficie-2); }

.barra {
    flex: none;
    width: 4px;
    border-radius: 3px;
    background: var(--ink-3);
}

.cifra--bien     .barra { background: var(--bien); }
.cifra--atencion .barra { background: var(--atencion); }
.cifra--grave    .barra { background: var(--grave); }
.cifra--critico  .barra { background: var(--critico); }
.cifra--neutro   .barra { background: var(--acento); }

.cuerpo { display: flex; flex-direction: column; gap: .22rem; min-width: 0; }

.etq {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--ink-3);
    overflow-wrap: anywhere;
}

.val {
    font-family: var(--mono);
    font-variant-numeric: tabular-nums;
    font-size: 1.75rem;
    font-weight: 600;
    letter-spacing: -.035em;
    line-height: 1.05;
    color: var(--ink);
    white-space: nowrap;
}

.val small { font-size: .68rem; font-weight: 400; color: var(--ink-3); margin-left: .2rem; }

.pie { font-size: .72rem; color: var(--ink-3); line-height: 1.4; }
</style>
