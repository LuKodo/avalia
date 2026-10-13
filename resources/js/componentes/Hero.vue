<script setup>
/**
 * Banda de encabezado de página, en la superficie de marca.
 *
 * No es decoración: separa el «dónde estoy y qué puedo hacer aquí» del «estos
 * son los datos». Sin ella, las pantallas internas arrancaban directo en una
 * tabla y se sentían como una consulta de base de datos, no como un producto.
 */
defineProps({
    rotulo: { type: String, default: '' },
    titulo: { type: String, required: true },
    descripcion: { type: String, default: '' },
    /** [{ etiqueta, valor, tono? }] — cifras de contexto, no la métrica principal. */
    cifras: { type: Array, default: () => [] },
})
</script>

<template>
    <section class="hero">
        <svg class="hero-trazo" viewBox="0 0 400 200" aria-hidden="true" focusable="false">
            <circle cx="330" cy="30" r="120" fill="none" stroke="currentColor" />
            <circle cx="330" cy="30" r="180" fill="none" stroke="currentColor" opacity=".5" />
        </svg>

        <div class="hero-txt">
            <p v-if="rotulo" class="hero-rotulo">{{ rotulo }}</p>
            <h1 class="hero-titulo">{{ titulo }}</h1>
            <p v-if="descripcion" class="hero-desc">{{ descripcion }}</p>
        </div>

        <div v-if="cifras.length" class="hero-cifras">
            <div v-for="c in cifras" :key="c.etiqueta" class="hero-cifra">
                <p class="hero-cifra-valor" :class="`t-${c.tono ?? 'neutro'}`">{{ c.valor }}</p>
                <p class="hero-cifra-etq">{{ c.etiqueta }}</p>
            </div>
        </div>

        <div v-if="$slots.acciones" class="hero-acciones">
            <slot name="acciones" />
        </div>
    </section>
</template>

<style scoped>
.hero {
    position: relative;
    overflow: hidden;
    border-radius: 14px;
    color: var(--marca-ink);
    background:
        radial-gradient(130% 130% at 92% 0%, var(--marca-3) 0%, transparent 60%),
        linear-gradient(140deg, var(--marca-2) 0%, var(--marca-1) 78%);
    padding: 1.4rem 1.4rem 1.5rem;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 1.2rem 2rem;
}

@media (min-width: 860px) { .hero { padding: 1.8rem 2rem; } }

.hero-trazo {
    position: absolute;
    inset: -30% -10% auto auto;
    width: 460px;
    color: rgba(255, 255, 255, .13);
    pointer-events: none;
}

.hero-txt { position: relative; flex: 1 1 320px; min-width: 0; }

.hero-rotulo {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: var(--marca-ink-3);
    margin: 0 0 .5rem;
}

.hero-titulo {
    font-size: clamp(1.35rem, 3vw, 1.9rem);
    font-weight: 700;
    letter-spacing: -.035em;
    line-height: 1.1;
    margin: 0;
    text-wrap: balance;
}

.hero-desc {
    font-size: .88rem;
    line-height: 1.55;
    color: var(--marca-ink-2);
    margin: .5rem 0 0;
    max-width: 62ch;
}

.hero-cifras {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    gap: 1.4rem;
}

.hero-cifra-valor {
    font-family: var(--mono);
    font-variant-numeric: tabular-nums;
    font-size: 1.6rem;
    font-weight: 600;
    letter-spacing: -.03em;
    margin: 0;
    line-height: 1;
}

.t-neutro { color: var(--marca-ink); }
.t-bien   { color: #6FD46F; }
.t-atencion { color: #FAC64D; }
.t-critico { color: #FF9C93; }

.hero-cifra-etq {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--marca-ink-3);
    margin: .35rem 0 0;
}

.hero-acciones {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}
</style>
