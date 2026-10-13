<script setup>
import { conBase } from '../base'
import { computed, ref } from 'vue'

/**
 * Verificación de un soporte por un tercero.
 *
 * Página abierta, sin sesión. Está pensada para alguien que NO trabaja en Avalia y
 * probablemente nunca vuelva: el auditor de una clínica, un revisor de INVIMA,
 * el área de calidad de un laboratorio. Tiene el certificado en la mano y una
 * sola pregunta — ¿esto es auténtico?
 *
 * De ahí las decisiones de la pantalla: dos campos y nada más, el veredicto en
 * grande antes que cualquier detalle, y una explicación de cómo funciona la
 * comprobación al alcance de quien no sabe qué es un hash. Pedirle registrarse
 * para verificar un documento que ya tiene sería absurdo.
 */
const guia = ref('')
const codigo = ref('')
const cargando = ref(false)
const resultado = ref(null)
const error = ref(null)

const puedeConsultar = computed(() => guia.value.trim() !== '' && codigo.value.trim() !== '')

async function verificar() {
    if (! puedeConsultar.value) return

    cargando.value = true
    error.value = null
    resultado.value = null

    try {
        const r = await fetch(conBase('/api/v1/verificar'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ guia: guia.value.trim(), codigo: codigo.value.trim() }),
        })

        const cuerpo = await r.json()

        if (r.status === 429) {
            error.value = 'Demasiadas consultas seguidas. Espere un minuto e intente de nuevo.'
        } else {
            // Un 404 no es un fallo de la aplicación: es el veredicto «no cuadra»,
            // y se muestra como tal.
            resultado.value = cuerpo
        }
    } catch (e) {
        error.value = 'No se pudo contactar al servidor de verificación.'
    } finally {
        cargando.value = false
    }
}

/**
 * El veredicto en una palabra.
 *
 * Válido y cadena íntegra no son lo mismo, y colapsarlos sería el peor error
 * posible en esta pantalla: un certificado auténtico cuya cadena esté rota es
 * exactamente el caso que hay que gritar, no esconder detrás de un visto bueno.
 */
const veredicto = computed(() => {
    const r = resultado.value

    if (! r) return null

    if (! r.valido) {
        return {
            clave: 'no',
            titulo: 'No se pudo verificar',
            detalle: r.motivo,
        }
    }

    if (! r.cadena.integra) {
        return {
            clave: 'roto',
            titulo: 'Documento alterado',
            detalle: 'El soporte existe, pero la bitácora de este envío no supera la comprobación '
                + 'de integridad. No debe aceptarse como prueba sin auditoría.',
        }
    }

    return {
        clave: 'si',
        titulo: 'Soporte auténtico',
        detalle: `Los ${r.cadena.eventos} eventos registrados para este envío conservan `
            + 'intacta su cadena de integridad.',
    }
})

function limpiar() {
    guia.value = ''
    codigo.value = ''
    resultado.value = null
    error.value = null
}
</script>

<template>
    <div class="verificar">
        <header class="cab">
            <p class="marca">Avalia Logística</p>
            <h1 class="titulo">Verificar un soporte de entrega</h1>
            <p class="entrada">
                Si tiene en sus manos un certificado de entrega emitido por Avalia Logística, aquí puede
                comprobar por su cuenta que es auténtico y que no ha sido alterado.
                No necesita cuenta ni permiso nuestro.
            </p>
        </header>

        <form class="forma" @submit.prevent="verificar">
            <label class="campo-bloque">
                <span class="rotulo">Número de guía</span>
                <input
                    v-model="guia" class="campo num" type="text"
                    placeholder="AVL-260709-0002" autocapitalize="characters" spellcheck="false"
                >
            </label>

            <label class="campo-bloque">
                <span class="rotulo">Código de verificación</span>
                <input
                    v-model="codigo" class="campo num" type="text"
                    placeholder="F426288CD176" autocapitalize="characters" spellcheck="false"
                >
                <span class="pista">Aparece al pie del certificado, junto al código QR.</span>
            </label>

            <div class="acciones">
                <button type="submit" class="btn btn--pri" :disabled="!puedeConsultar || cargando">
                    {{ cargando ? 'Comprobando…' : 'Verificar' }}
                </button>
                <button v-if="resultado" type="button" class="btn btn--sec" @click="limpiar">
                    Verificar otro
                </button>
            </div>
        </form>

        <p v-if="error" class="alerta" role="alert">{{ error }}</p>

        <section v-if="veredicto" class="resultado" :class="`resultado--${veredicto.clave}`" aria-live="polite">
            <p class="veredicto">
                <span class="veredicto-icono" aria-hidden="true">{{
                    { si: '✓', roto: '✕', no: '?' }[veredicto.clave]
                }}</span>
                {{ veredicto.titulo }}
            </p>
            <p class="veredicto-detalle">{{ veredicto.detalle }}</p>

            <dl v-if="resultado.valido" class="datos">
                <div>
                    <dt>Guía</dt>
                    <dd class="num">{{ resultado.guia }}</dd>
                </div>
                <div v-if="resultado.entregado_el">
                    <dt>Fecha de entrega</dt>
                    <dd class="num">{{ resultado.entregado_el }}</dd>
                </div>
                <div>
                    <dt>Paquetes</dt>
                    <dd class="num">{{ resultado.paquetes }}</dd>
                </div>
                <div>
                    <dt>Eventos comprobados</dt>
                    <dd class="num">
                        {{ resultado.cadena.eventos }}
                        <span class="tenue">(secuencias {{ resultado.cadena.desde }}–{{ resultado.cadena.hasta }})</span>
                    </dd>
                </div>
                <div v-if="resultado.cadena.primer_fallo !== null">
                    <dt>Primer eslabón roto</dt>
                    <dd class="num mal">secuencia {{ resultado.cadena.primer_fallo }}</dd>
                </div>
            </dl>

            <!--
                El estado del sello se dice completo, incluida la parte incómoda.
                Un sello que existe pero no se ha exportado fuera del servidor
                protege menos de lo que aparenta, y presentarlo como un visto
                bueno sin matices sería vender una garantía que no está dada.
            -->
            <div v-if="resultado.valido && resultado.sello" class="sello">
                <p class="sello-titulo">Sello de la jornada</p>
                <template v-if="resultado.sello.existe">
                    <p class="sello-linea">
                        {{ resultado.sello.fecha }} · {{ resultado.sello.total_eventos }} eventos sellados
                    </p>
                    <p class="sello-linea num hash">{{ resultado.sello.hash_raiz }}</p>
                    <p class="sello-linea" :class="resultado.sello.proteccion_completa ? 'bueno' : 'parcial'">
                        {{ resultado.sello.proteccion_completa
                            ? '✓ Firmado y exportado fuera del servidor'
                            : '△ Firmado, pendiente de exportación externa' }}
                    </p>
                </template>
                <p v-else class="sello-linea">{{ resultado.sello.nota }}</p>
            </div>

            <p v-if="resultado.valido" class="explicacion">{{ resultado.como_funciona }}</p>
        </section>

        <footer class="pie">
            <p>
                Esta comprobación no revela el contenido del envío, su destino ni quién lo recibió.
                Solo confirma si el documento corresponde a un registro auténtico e inalterado.
            </p>
        </footer>
    </div>
</template>

<style scoped>
.verificar {
    max-width: 46rem; margin: 0 auto; padding: 2.5rem 1.25rem 3rem;
    display: flex; flex-direction: column; gap: 1.5rem;
}

.cab { display: flex; flex-direction: column; gap: .45rem; }
.marca {
    margin: 0; font-size: .7rem; font-weight: 700; letter-spacing: .22em;
    color: var(--acento);
}
.titulo {
    margin: 0; font-size: 1.5rem; font-weight: 660; letter-spacing: -.02em;
    color: var(--ink-1); text-wrap: balance;
}
.entrada {
    margin: 0; font-size: .86rem; line-height: 1.6; color: var(--ink-2);
    max-width: 60ch;
}

.forma { display: flex; flex-direction: column; gap: .85rem; }
.campo-bloque { display: flex; flex-direction: column; gap: .25rem; }
.rotulo {
    font-size: .66rem; text-transform: uppercase; letter-spacing: .07em;
    color: var(--ink-3); font-weight: 600;
}
.campo {
    width: 100%; padding: .6rem .7rem;
    border: 1px solid var(--linea-fuerte); border-radius: 9px;
    background: var(--superficie); color: var(--ink-1);
    font-size: .95rem; letter-spacing: .02em;
}
.campo:focus-visible { outline: 2px solid var(--acento); outline-offset: 1px; }
.pista { font-size: .68rem; color: var(--ink-3); }

.acciones { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .2rem; }

.alerta {
    margin: 0; padding: .6rem .75rem; border-radius: 9px;
    background: var(--atencion-suave); color: var(--grave); font-size: .8rem;
}

/* --- resultado ---------------------------------------------------------- */
.resultado {
    display: flex; flex-direction: column; gap: .7rem;
    padding: 1rem 1.1rem;
    border-radius: 12px;
    border: 1px solid var(--linea);
    border-left: 4px solid currentColor;
    background: var(--superficie);
}
.resultado--si   { color: var(--bien); }
.resultado--roto { color: var(--critico); }
.resultado--no   { color: var(--grave); }

.veredicto {
    display: flex; align-items: center; gap: .5rem;
    margin: 0; font-size: 1.1rem; font-weight: 660; letter-spacing: -.015em;
}
.veredicto-icono { font-size: 1.15rem; line-height: 1; }
.veredicto-detalle {
    margin: 0; font-size: .82rem; line-height: 1.55; color: var(--ink-2);
}

.datos {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: .6rem; margin: .2rem 0 0;
    padding-top: .7rem; border-top: 1px solid var(--linea);
}
.datos dt {
    font-size: .63rem; text-transform: uppercase; letter-spacing: .06em;
    color: var(--ink-3);
}
.datos dd {
    margin: .1rem 0 0; font-size: .88rem; color: var(--ink-1);
    font-variant-numeric: tabular-nums;
}
.datos dd.mal { color: var(--critico); font-weight: 620; }
.tenue { color: var(--ink-3); font-size: .72rem; }

.sello {
    padding-top: .7rem; border-top: 1px solid var(--linea);
    display: flex; flex-direction: column; gap: .18rem;
}
.sello-titulo {
    margin: 0; font-size: .63rem; text-transform: uppercase; letter-spacing: .06em;
    color: var(--ink-3);
}
.sello-linea { margin: 0; font-size: .76rem; color: var(--ink-2); }
.sello-linea.bueno { color: var(--bien); }
.sello-linea.parcial { color: var(--grave); }
/* El hash se parte donde sea: es una tira de 64 caracteres y forzar el ancho
   de la tarjeta por su culpa desbordaría la página en el teléfono. */
.hash {
    font-size: .64rem; color: var(--ink-3);
    overflow-wrap: anywhere; line-height: 1.4;
}

.explicacion {
    margin: 0; padding-top: .6rem; border-top: 1px solid var(--linea);
    font-size: .72rem; line-height: 1.55; color: var(--ink-3);
}

.pie { border-top: 1px solid var(--linea); padding-top: .9rem; }
.pie p { margin: 0; font-size: .72rem; line-height: 1.55; color: var(--ink-3); max-width: 62ch; }
</style>
