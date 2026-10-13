<script setup>
import { ref } from 'vue'
import { descargarCsv } from '../util/csv'

/**
 * Botón de exportación a CSV para una pantalla de lista.
 *
 * Recibe las columnas y una función que produce las filas en el momento de
 * pulsar, no antes: si se pasaran ya calculadas, exportaría lo que había cuando
 * se pintó la pantalla y no lo que la persona tiene delante después de filtrar.
 *
 * Confirma en el propio botón cuántas filas salieron. Una descarga silenciosa
 * deja a quien la pidió sin saber si el archivo trae los 400 registros o los 12
 * que quedaron tras el filtro, y esa duda obliga a abrir el archivo para
 * comprobar — justo el trabajo que la exportación venía a evitar.
 */
const props = defineProps({
    /** Nombre base del archivo, sin fecha ni extensión. */
    nombre: { type: String, required: true },
    columnas: { type: Array, required: true },
    /** Función sin argumentos que devuelve un arreglo de arreglos. */
    filas: { type: Function, required: true },
    etiqueta: { type: String, default: 'Exportar CSV' },
    /** Advertencia que se muestra bajo el botón cuando el archivo lleva datos personales. */
    sensible: { type: Boolean, default: false },
})

const hecho = ref(0)
let temporizador = null

function exportar() {
    const filas = props.filas() ?? []

    if (filas.length === 0) {
        hecho.value = -1
    } else {
        descargarCsv(props.nombre, props.columnas, filas)
        hecho.value = filas.length
    }

    clearTimeout(temporizador)
    temporizador = setTimeout(() => { hecho.value = 0 }, 4000)
}
</script>

<template>
    <div class="envoltura">
        <button type="button" class="btn btn--sec btn--csv" @click="exportar">
            <span aria-hidden="true">⤓</span> {{ etiqueta }}
        </button>

        <!-- aria-live para que un lector de pantalla anuncie el resultado: la
             descarga en sí no produce ningún cambio perceptible en la página. -->
        <p class="aviso" role="status" aria-live="polite">
            <template v-if="hecho > 0">{{ hecho }} fila(s) exportadas</template>
            <template v-else-if="hecho === -1">No hay nada que exportar</template>
        </p>

        <!--
            Deliberadamente NO dice «su descarga queda registrada». El archivo se
            arma en el navegador con datos que ya estaban en pantalla, así que el
            servidor no se entera y no hay tal registro. Prometerlo daría una
            falsa sensación de control sobre a dónde va el archivo después.
        -->
        <p v-if="sensible" class="sensible">
            Contiene datos personales. Trátelo según la política de manejo de datos.
        </p>
    </div>
</template>

<style scoped>
.envoltura { display: flex; flex-direction: column; gap: .15rem; align-items: flex-start; }

.btn--csv { white-space: nowrap; }

.aviso {
    margin: 0; font-size: .68rem; color: var(--ink-3);
    /* Altura reservada: sin ella la fila entera salta cuando aparece el texto. */
    min-height: 1em;
}

.sensible { margin: 0; font-size: .66rem; color: var(--grave); }
</style>
