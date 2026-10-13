<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/cliente'
import Hero from '../componentes/Hero.vue'
import { moneda } from '../graficos/util'

/**
 * Radicación de un envío.
 *
 * Es la puerta por donde nace la guía. Existía solo como API —lo que servía al
 * ERP de un cliente integrado y a nadie más—: esta pantalla la abre para el
 * operador que recibe la solicitud por teléfono y para el cliente que radica
 * él mismo.
 *
 * Las sedes se eligen de un catálogo con coordenada verificada. No hay campo
 * de dirección libre a propósito: escribir direcciones a mano es la principal
 * causa de entregas en la cuadra equivocada.
 */
const catalogo = ref(null)
const cargando = ref(true)
const enviando = ref(false)
const error = ref(null)
const creada = ref(null)

const forma = ref({
    cliente_id: null,
    sede_origen_id: null,
    sede_destino_id: null,
    criticidad: 'programado',
    ventana_desde: '',
    ventana_hasta: '',
    contacto_destino_nombre: '',
    contacto_destino_telefono: '',
    observaciones: '',
    referencias: [{ tipo: '', valor: '' }],
    paquetes: [nuevoPaquete()],
})

function nuevoPaquete() {
    return {
        tipo: 'general',
        descripcion: '',
        lote: '',
        peso_kg: '',
        largo_cm: '',
        ancho_cm: '',
        alto_cm: '',
        temp_min: 2,
        temp_max: 8,
    }
}

onMounted(async () => {
    try {
        catalogo.value = await api.catalogoRadicar()

        /*
         * Solo se preselecciona cuando NO hay elección que hacer: un usuario de
         * cliente tiene el suyo y nada más, y el servidor se lo impone igual.
         *
         * Con varios clientes el campo arranca vacío a propósito. Antes traía al
         * primero de la lista, y un operador que no bajara a mirarlo radicaba a
         * nombre de otra institución sin enterarse — un error que solo se
         * descubre cuando el envío ya salió y la factura llegó a quien no era.
         */
        const clientes = catalogo.value.clientes ?? []

        if (clientes.length === 1) {
            forma.value.cliente_id = clientes[0].id
        }

        // Ventana por defecto: de aquí a cuatro horas, redondeada. Un formulario
        // que arranca vacío obliga a teclear lo mismo cien veces al día.
        const ahora = new Date()
        const mas = new Date(ahora.getTime() + 4 * 3600_000)
        forma.value.ventana_desde = paraInput(ahora)
        forma.value.ventana_hasta = paraInput(mas)
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
})

function paraInput(d) {
    const p = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

const clienteActual = computed(() =>
    catalogo.value?.clientes.find((c) => c.id === forma.value.cliente_id) ?? null)

const sedes = computed(() => clienteActual.value?.sedes ?? [])

/*
 * Cada desplegable esconde la sede que ya eligió el otro.
 *
 * Un paquete no puede recogerse y entregarse en el mismo sitio. Eso ya lo
 * rechazaban la validación del formulario y la del servidor, pero las dos
 * actúan al enviar: hasta entonces la lista invitaba a elegir algo imposible y
 * la respuesta era un regaño. Quitarlo de la lista evita el error en vez de
 * señalarlo.
 *
 * No hace falta limpiar nada al cambiar de selección: cada lista excluye la
 * sede del OTRO campo, así que la propia siempre sigue estando.
 */
const sedesOrigen = computed(
    () => sedes.value.filter((s) => s.id !== forma.value.sede_destino_id))

const sedesDestino = computed(
    () => sedes.value.filter((s) => s.id !== forma.value.sede_origen_id))

watch(clienteActual, (c) => {
    if (!c) return
    forma.value.sede_origen_id = null
    forma.value.sede_destino_id = null
    /*
     * El tipo que use ESE cliente, y nada si no tiene uno configurado.
     *
     * Preseleccionar su tipo habitual es util -el hospital que llama «orden de
     * compra» a su referencia la ve ya elegida-, pero caer en 'radicado'
     * cuando no hay ninguno configurado seria justo el valor por omision a
     * ciegas que se quiere evitar: se envia aunque nadie lo haya mirado.
     */
    forma.value.referencias[0].tipo = c.tipo_referencia ?? ''
})

const hayFrio = computed(() => forma.value.paquetes.some((p) => p.tipo === 'cadena_frio'))
const hayControlado = computed(() => forma.value.paquetes.some((p) => p.tipo === 'controlado'))

const problemas = computed(() => {
    const f = forma.value
    const lista = []

    if (!f.cliente_id) lista.push('Falta el cliente.')
    if (!f.sede_origen_id) lista.push('Falta la sede de recolección.')
    if (!f.sede_destino_id) lista.push('Falta la sede de entrega.')
    if (f.sede_origen_id && f.sede_origen_id === f.sede_destino_id) {
        lista.push('El origen y el destino no pueden ser la misma sede.')
    }

    // Una referencia escrita sin tipo no se puede buscar despues: el cliente
    // consulta por «orden de compra 4460», no por «4460» a secas.
    if (f.referencias.some((r) => r.valor.trim() && !r.tipo)) {
        lista.push('Indique de que tipo es cada referencia que escribio.')
    }

    if (clienteActual.value?.referencia_obligatoria && !f.referencias.some((r) => r.valor.trim())) {
        lista.push(`Este cliente exige ${clienteActual.value.etiqueta_referencia.toLowerCase()} en cada radicación.`)
    }

    f.paquetes.forEach((p, i) => {
        if (!p.peso_kg || Number(p.peso_kg) <= 0) lista.push(`Paquete ${i + 1}: falta el peso.`)
        if (p.tipo === 'cadena_frio' && Number(p.temp_min) >= Number(p.temp_max)) {
            lista.push(`Paquete ${i + 1}: el rango térmico está invertido.`)
        }
    })

    return lista
})

async function radicar() {
    if (problemas.value.length || enviando.value) return

    enviando.value = true
    error.value = null

    const f = forma.value

    const cuerpo = {
        // Solo lo manda quien puede elegir: a un usuario de cliente el
        // servidor le impone el suyo y este campo se ignora.
        cliente_id: catalogo.value?.puede_elegir_cliente ? f.cliente_id : null,
        sede_origen_id: f.sede_origen_id,
        sede_destino_id: f.sede_destino_id,
        criticidad: f.criticidad,
        ventana_desde: f.ventana_desde || null,
        ventana_hasta: f.ventana_hasta || null,
        contacto_destino_nombre: f.contacto_destino_nombre || null,
        contacto_destino_telefono: f.contacto_destino_telefono || null,
        observaciones: f.observaciones || null,
        referencias: f.referencias
            .filter((r) => r.valor.trim())
            .map((r) => ({ tipo: r.tipo, valor: r.valor.trim() })),
        paquetes: f.paquetes.map((p) => ({
            tipo: p.tipo,
            descripcion: p.descripcion || null,
            lote: p.lote || null,
            peso_kg: Number(p.peso_kg),
            largo_cm: p.largo_cm ? Number(p.largo_cm) : null,
            ancho_cm: p.ancho_cm ? Number(p.ancho_cm) : null,
            alto_cm: p.alto_cm ? Number(p.alto_cm) : null,
            temp_min: p.tipo === 'cadena_frio' ? Number(p.temp_min) : null,
            temp_max: p.tipo === 'cadena_frio' ? Number(p.temp_max) : null,
        })),
    }

    try {
        creada.value = await api.crearOrden(cuerpo)
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        enviando.value = false
    }
}

const imprimiendo = ref(false)
const errorImpresion = ref(null)

/**
 * Abre la guía lista para imprimir.
 *
 * Dos pasos y no uno: primero se pide al servidor un enlace firmado —ahí se
 * comprueba que esta guía es de quien la pide— y después se abre. No se puede
 * enlazar directo porque la pestaña nueva no lleva el token de sesión: viaja
 * en una cabecera, no en una cookie, y el servidor respondería 401.
 *
 * La pestaña se abre ANTES de la espera. Si se abriera después, el navegador
 * lo trataría como una ventana emergente no pedida por el usuario y la
 * bloquearía: para él, el clic ya pasó.
 */
async function imprimirGuia() {
    if (!creada.value?.guia_avalia) return

    imprimiendo.value = true
    errorImpresion.value = null

    const pestania = window.open('', '_blank')

    try {
        const r = await api.enlaceImpresion(creada.value.guia_avalia)

        if (pestania) {
            pestania.location = r.url
        } else {
            // Bloqueada de todos modos: se navega en la misma pestaña, que es
            // peor que abrir otra pero mucho mejor que no hacer nada.
            window.location.assign(r.url)
        }
    } catch (e) {
        pestania?.close()
        errorImpresion.value = `No se pudo preparar la guía para imprimir: ${e.message}`
    } finally {
        imprimiendo.value = false
    }
}

function otra() {
    creada.value = null
    forma.value.referencias = [{ tipo: clienteActual.value?.tipo_referencia ?? 'radicado', valor: '' }]
    forma.value.paquetes = [nuevoPaquete()]
    forma.value.observaciones = ''
}
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Operación"
            titulo="Radicar un envío"
            descripcion="Aquí nace la guía Avalia. Las sedes salen del catálogo con coordenada verificada; la referencia que registre es la llave con la que el cliente va a consultar después."
        />

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-else-if="cargando" class="aviso">Cargando catálogo…</p>

        <!-- ------------------------------------------------ confirmación -->
        <section v-else-if="creada" class="tarjeta exito">
            <div class="exito-marca" aria-hidden="true">✓</div>
            <h2 class="exito-tit">Envío radicado</h2>
            <p class="exito-guia num">{{ creada.guia_avalia }}</p>

            <ul class="exito-refs">
                <li v-for="r in creada.referencias" :key="r.valor">
                    {{ r.tipo }}: <strong class="num">{{ r.valor }}</strong>
                </li>
            </ul>

            <div class="desliza">
                <table class="tabla">
                    <thead><tr><th>Paquete</th><th>Tipo</th><th>Estado</th></tr></thead>
                    <tbody>
                        <tr v-for="p in creada.paquetes" :key="p.codigo">
                            <td class="num">{{ p.codigo }}</td>
                            <td>{{ p.tipo }}</td>
                            <td><span class="pastilla pastilla--neutral">{{ p.estado }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="exito-nota">
                Total {{ moneda(creada.total) }} ·
                comprometido para {{ new Date(creada.comprometida_en).toLocaleString('es-CO') }}
            </p>

            <div class="exito-acciones">
                <button type="button" class="btn btn--pri" :disabled="imprimiendo" @click="imprimirGuia">
                    {{ imprimiendo ? 'Preparando…' : '🖨 Imprimir guía' }}
                </button>
                <button type="button" class="btn btn--sec" @click="otra">Radicar otro</button>
                <RouterLink to="/solicitudes" class="btn btn--sec">Mis solicitudes</RouterLink>
            </div>

            <p v-if="errorImpresion" class="exito-nota" style="color:var(--critico-ink,#B32626)">
                {{ errorImpresion }}
            </p>

            <p class="exito-nota">
                Imprima la guía y péguela a la caja: lleva el código QR que el
                mensajero escanea para confirmar la recolección.
            </p>
        </section>

        <!-- ---------------------------------------------------- formulario -->
        <form v-else class="forma" @submit.prevent="radicar">
            <section class="tarjeta">
                <div class="tarjeta-cab"><h2 class="tarjeta-titulo">1 · Quién y a dónde</h2></div>
                <div class="tarjeta-cuerpo rejilla-campos">
                    <label v-if="catalogo.puede_elegir_cliente" class="campo-grupo">
                        <span class="etiqueta">Cliente</span>
                        <select v-model.number="forma.cliente_id" class="campo" :class="{ 'campo--vacio': forma.cliente_id === null }">
                            <option :value="null">Seleccione…</option>
                            <option v-for="c in catalogo.clientes" :key="c.id" :value="c.id">
                                {{ c.razon_social }}
                            </option>
                        </select>
                    </label>

                    <label class="campo-grupo">
                        <span class="etiqueta">Recoger en</span>
                        <select v-model.number="forma.sede_origen_id" class="campo" :class="{ 'campo--vacio': forma.sede_origen_id === null }">
                            <option :value="null">Seleccione…</option>
                            <option v-for="s in sedesOrigen" :key="s.id" :value="s.id">
                                {{ s.nombre }} — {{ s.ciudad }}{{ s.coordenada_verificada ? '' : ' (sin coordenada)' }}
                            </option>
                        </select>
                    </label>

                    <label class="campo-grupo">
                        <span class="etiqueta">Entregar en</span>
                        <select v-model.number="forma.sede_destino_id" class="campo" :class="{ 'campo--vacio': forma.sede_destino_id === null }">
                            <option :value="null">Seleccione…</option>
                            <option v-for="s in sedesDestino" :key="s.id" :value="s.id">
                                {{ s.nombre }} — {{ s.ciudad }}
                            </option>
                        </select>
                    </label>

                    <label class="campo-grupo">
                        <span class="etiqueta">Contacto en destino</span>
                        <input v-model="forma.contacto_destino_nombre" class="campo" type="text" placeholder="Q.F. responsable">
                    </label>

                    <label class="campo-grupo">
                        <span class="etiqueta">Teléfono</span>
                        <input v-model="forma.contacto_destino_telefono" class="campo" type="tel" placeholder="604 000 0000">
                    </label>
                </div>
            </section>

            <section class="tarjeta">
                <div class="tarjeta-cab">
                    <h2 class="tarjeta-titulo">
                        2 · {{ clienteActual?.etiqueta_referencia ?? 'Referencia' }} del cliente
                    </h2>
                    <span v-if="clienteActual?.referencia_obligatoria" class="pastilla pastilla--atencion">
                        Obligatoria
                    </span>
                </div>
                <div class="tarjeta-cuerpo">
                    <p class="ayuda">
                        Es la llave con la que el cliente va a consultar desde su propio sistema.
                        Puede registrar varias: cualquiera recupera el envío completo.
                    </p>

                    <div v-for="(r, i) in forma.referencias" :key="i" class="ref-fila">
                        <select v-model="r.tipo" class="campo campo--tipo" :class="{ 'campo--vacio': !r.tipo }">
                            <option value="">Seleccione…</option>
                            <option v-for="t in catalogo.tipos_referencia" :key="t.valor" :value="t.valor">
                                {{ t.etiqueta }}
                            </option>
                        </select>
                        <input v-model="r.valor" class="campo" type="text" placeholder="RAD-2608-00123">
                        <button
                            v-if="forma.referencias.length > 1" type="button" class="btn-quitar"
                            aria-label="Quitar referencia" @click="forma.referencias.splice(i, 1)"
                        >✕</button>
                    </div>

                    <button
                        v-if="forma.referencias.length < 5" type="button" class="btn btn--sec btn--peq"
                        @click="forma.referencias.push({ tipo: 'orden_compra', valor: '' })"
                    >+ Otra referencia</button>
                </div>
            </section>

            <section class="tarjeta">
                <div class="tarjeta-cab">
                    <h2 class="tarjeta-titulo">3 · Qué se transporta</h2>
                    <span class="tarjeta-sub">{{ forma.paquetes.length }} paquete(s)</span>
                </div>

                <div v-for="(p, i) in forma.paquetes" :key="i" class="paquete">
                    <div class="paquete-cab">
                        <span class="paquete-n">Paquete {{ i + 1 }}</span>
                        <button
                            v-if="forma.paquetes.length > 1" type="button" class="btn-quitar"
                            aria-label="Quitar paquete" @click="forma.paquetes.splice(i, 1)"
                        >✕</button>
                    </div>

                    <div class="tipos">
                        <button
                            v-for="t in catalogo.tipos_paquete" :key="t.valor"
                            type="button" class="tipo" :class="{ 'tipo--sel': p.tipo === t.valor }"
                            @click="p.tipo = t.valor"
                        >{{ t.etiqueta }}</button>
                    </div>

                    <div class="rejilla-campos">
                        <label class="campo-grupo campo-ancho">
                            <span class="etiqueta">Descripción</span>
                            <input v-model="p.descripcion" class="campo" type="text" placeholder="Nevera con 3 viales de insulina">
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Lote</span>
                            <input v-model="p.lote" class="campo" type="text">
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Peso (kg)</span>
                            <input v-model="p.peso_kg" class="campo num" type="number" step="0.01" min="0.01">
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Largo (cm)</span>
                            <input v-model="p.largo_cm" class="campo num" type="number" min="1">
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Ancho (cm)</span>
                            <input v-model="p.ancho_cm" class="campo num" type="number" min="1">
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Alto (cm)</span>
                            <input v-model="p.alto_cm" class="campo num" type="number" min="1">
                        </label>

                        <template v-if="p.tipo === 'cadena_frio'">
                            <label class="campo-grupo">
                                <span class="etiqueta">Temp. mínima (°C)</span>
                                <input v-model="p.temp_min" class="campo num" type="number" step="0.5">
                            </label>
                            <label class="campo-grupo">
                                <span class="etiqueta">Temp. máxima (°C)</span>
                                <input v-model="p.temp_max" class="campo num" type="number" step="0.5">
                            </label>
                        </template>
                    </div>
                </div>

                <div class="tarjeta-cuerpo">
                    <button type="button" class="btn btn--sec btn--peq" @click="forma.paquetes.push(nuevoPaquete())">
                        + Agregar paquete
                    </button>
                </div>
            </section>

            <section class="tarjeta">
                <div class="tarjeta-cab"><h2 class="tarjeta-titulo">4 · Cuándo</h2></div>
                <div class="tarjeta-cuerpo">
                    <div class="tipos">
                        <button
                            v-for="c in catalogo.criticidades" :key="c.valor"
                            type="button" class="tipo tipo--ancho"
                            :class="{ 'tipo--sel': forma.criticidad === c.valor }"
                            @click="forma.criticidad = c.valor"
                        >
                            <strong>{{ c.etiqueta }}</strong>
                            <small>{{ c.nota }}</small>
                        </button>
                    </div>

                    <div class="rejilla-campos">
                        <label class="campo-grupo">
                            <span class="etiqueta">Ventana desde</span>
                            <input v-model="forma.ventana_desde" class="campo" type="datetime-local">
                        </label>
                        <label class="campo-grupo">
                            <span class="etiqueta">Ventana hasta</span>
                            <input v-model="forma.ventana_hasta" class="campo" type="datetime-local">
                        </label>
                        <label class="campo-grupo campo-ancho">
                            <span class="etiqueta">Observaciones</span>
                            <input v-model="forma.observaciones" class="campo" type="text" placeholder="Acceso por muelle 2, preguntar por portería">
                        </label>
                    </div>

                </div>
            </section>

            <div v-if="hayFrio || hayControlado" class="aviso aviso--info">
                <strong>Requisitos que impone esta carga:</strong>
                <span v-if="hayFrio"> vehículo refrigerado y registro de temperatura en recolección y entrega.</span>
                <span v-if="hayControlado"> mensajero certificado en control especial y cadena de custodia reforzada.</span>
                El filtro duro descartará a quien no cumpla — no es negociable desde el despacho.
            </div>

            <div v-if="problemas.length" class="aviso aviso--error">
                <strong>Falta por completar:</strong>
                <ul><li v-for="p in problemas" :key="p">{{ p }}</li></ul>
            </div>

            <div class="pie-forma">
                <button type="submit" class="btn btn--pri btn--grande" :disabled="problemas.length > 0 || enviando">
                    {{ enviando ? 'Radicando…' : 'Radicar envío y generar guía' }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.pagina {
    max-width: 1060px;
    margin: 0 auto;
    padding: 1.2rem 1rem 3rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 1000px) { .pagina { padding: 1.4rem 1.6rem 3rem; } }

.forma { display: flex; flex-direction: column; gap: 1rem; }

.rejilla-campos {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: .75rem;
}

.campo-ancho { grid-column: 1 / -1; }
.campo-grupo { display: flex; flex-direction: column; gap: .28rem; min-width: 0; }

.etiqueta {
    font-family: var(--mono);
    font-size: .59rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.campo {
    min-height: 44px;
    border: 1px solid var(--linea-fuerte);
    border-radius: var(--r-sm);
    background: var(--superficie);
    padding: .5rem .7rem;
    font-size: .88rem;
    color: var(--ink);
    width: 100%;
}

.campo:focus { outline: 2px solid var(--acento); outline-offset: 1px; }
.campo--tipo { flex: 0 0 190px; }

/* Un desplegable sin elegir se ve como lo que es. En tinta plena parecería una
   respuesta ya dada, que es justo la confusión que se quiere evitar. */
.campo--vacio { color: var(--ink-3); }

.ayuda { font-size: .8rem; color: var(--ink-2); margin: 0 0 .8rem; line-height: 1.5; }

.ref-fila { display: flex; gap: .5rem; margin-bottom: .5rem; align-items: center; }

.btn-quitar {
    flex: none;
    width: 34px;
    height: 34px;
    border: 1px solid var(--linea-fuerte);
    border-radius: var(--r-sm);
    background: var(--superficie);
    color: var(--ink-3);
    font-size: .8rem;
}

.btn-quitar:hover { border-color: var(--critico); color: var(--critico); }

.paquete { padding: .9rem; border-top: 1px solid var(--linea); }
.paquete-cab { display: flex; justify-content: space-between; align-items: center; margin-bottom: .6rem; }

.paquete-n {
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.tipos { display: flex; flex-wrap: wrap; gap: .45rem; margin-bottom: .8rem; }

.tipo {
    border: 1.5px solid var(--linea);
    background: var(--superficie);
    border-radius: var(--r-sm);
    padding: .45rem .8rem;
    font-size: .82rem;
    font-weight: 500;
    color: var(--ink-2);
    min-height: 40px;
}

.tipo--sel { border-color: var(--acento); background: var(--acento-suave); color: var(--acento-ink); font-weight: 600; }

.tipo--ancho {
    flex: 1 1 220px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .15rem;
    text-align: left;
    padding: .6rem .8rem;
}

.tipo--ancho small { font-size: .72rem; font-weight: 400; color: var(--ink-3); line-height: 1.35; }
.tipo--sel small { color: var(--acento-ink); opacity: .85; }

.check {
    display: flex;
    gap: .7rem;
    align-items: flex-start;
    margin-top: .9rem;
    padding: .8rem;
    background: var(--superficie-2);
    border-radius: var(--r-sm);
    font-size: .8rem;
    line-height: 1.5;
    color: var(--ink-2);
}

.check input { width: 20px; height: 20px; flex: none; margin-top: .1rem; }
.check strong { display: block; color: var(--ink); margin-bottom: .1rem; }

.pie-forma { display: flex; justify-content: flex-end; }

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: .55rem 1.1rem;
    border-radius: var(--r-sm);
    font-size: .88rem;
    font-weight: 600;
    border: 1px solid transparent;
    text-decoration: none;
}

.btn--pri { background: var(--acento); color: var(--sobre-acento); }
.btn--pri:disabled { opacity: .45; cursor: not-allowed; }
.btn--sec { background: var(--superficie); border-color: var(--linea-fuerte); color: var(--ink); }
.btn--peq { min-height: 36px; padding: .35rem .8rem; font-size: .8rem; }
.btn--grande { min-height: 52px; padding: .7rem 1.8rem; font-size: .95rem; }

.aviso {
    padding: .85rem 1rem;
    border-radius: var(--r-md);
    font-size: .83rem;
    line-height: 1.55;
    background: var(--superficie);
    border: 1px solid var(--linea);
    margin: 0;
}

.aviso ul { margin: .4rem 0 0; padding-left: 1.1rem; }
.aviso--error { background: var(--critico-suave); border-color: var(--critico); color: var(--critico); }
.aviso--info { background: var(--acento-suave); border-color: var(--acento); color: var(--acento-ink); }

.exito { text-align: center; padding: 2rem 1.2rem; }

.exito-marca {
    width: 62px; height: 62px;
    margin: 0 auto .9rem;
    border-radius: 50%;
    display: grid; place-items: center;
    background: var(--bien-suave);
    color: var(--bien);
    font-size: 1.8rem;
}

.exito-tit { font-size: 1.2rem; font-weight: 700; margin: 0; letter-spacing: -.02em; }

.exito-guia {
    font-size: 1.7rem;
    font-weight: 600;
    letter-spacing: .01em;
    margin: .5rem 0 .8rem;
    color: var(--acento);
}

.exito-refs { list-style: none; margin: 0 0 1rem; padding: 0; font-size: .82rem; color: var(--ink-2); }
.exito-nota { font-size: .8rem; color: var(--ink-3); margin: .9rem 0 1.2rem; }
.exito-acciones { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; }

.tabla { width: 100%; border-collapse: collapse; font-size: .8rem; }

.tabla th {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--ink-3);
    font-weight: 500;
    text-align: left;
    padding: .45rem .7rem;
    background: var(--superficie-2);
    border-bottom: 1px solid var(--linea);
}

.tabla td { padding: .45rem .7rem; border-bottom: 1px solid var(--linea); text-align: left; }
</style>
