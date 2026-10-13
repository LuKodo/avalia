<script setup>
import { computed, useId } from 'vue'

/**
 * Campo de formulario con etiqueta, marca de obligatorio, ayuda y error.
 *
 * La etiqueta va ENCIMA y en negrita, no dentro del campo: un marcador de
 * posición que hace de etiqueta desaparece al escribir, y quien vuelve a
 * revisar el formulario ya no sabe qué era cada casilla.
 */
const props = defineProps({
    etiqueta: { type: String, required: true },
    obligatorio: { type: Boolean, default: false },
    ayuda: { type: String, default: '' },
    error: { type: String, default: '' },
    /** Ocupa toda la fila de la rejilla. */
    ancho: { type: Boolean, default: false },
})

const id = useId()
const describe = computed(() => [props.ayuda && `${id}-ayuda`, props.error && `${id}-error`]
    .filter(Boolean).join(' ') || undefined)
</script>

<template>
    <div class="campo-grupo" :class="{ 'campo-grupo--ancho': ancho, 'campo-grupo--error': error }">
        <label class="etq" :for="id">
            {{ etiqueta }}
            <span v-if="obligatorio" class="req" aria-hidden="true">*</span>
            <span v-if="obligatorio" class="solo-lector">(obligatorio)</span>
        </label>

        <slot :id="id" :describe="describe" />

        <p v-if="error" :id="`${id}-error`" class="err" role="alert">{{ error }}</p>
        <p v-else-if="ayuda" :id="`${id}-ayuda`" class="ayuda">{{ ayuda }}</p>
    </div>
</template>

<style scoped>
.campo-grupo { display: flex; flex-direction: column; gap: .32rem; min-width: 0; }
.campo-grupo--ancho { grid-column: 1 / -1; }

.etq {
    font-size: .81rem;
    font-weight: 600;
    color: var(--ink);
    letter-spacing: -.005em;
}

.req { color: var(--critico); margin-left: .1rem; }

.ayuda { font-size: .73rem; color: var(--ink-3); margin: 0; line-height: 1.45; }
.err { font-size: .75rem; color: var(--critico); margin: 0; line-height: 1.45; font-weight: 500; }

/* El estado de error se marca en el borde del control, que es donde el ojo ya
   está mirando cuando lee el mensaje. */
.campo-grupo--error :deep(input),
.campo-grupo--error :deep(select),
.campo-grupo--error :deep(textarea) {
    border-color: var(--critico);
}
</style>
