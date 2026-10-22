<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/cliente'
import Hero from '../componentes/Hero.vue'
import SelectorMapa from '../componentes/SelectorMapa.vue'
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

    /*
     * Domicilios: una dirección escrita en vez de una sede.
     *
     * Las sedes sirven para lo pactado —una clínica, una bodega—. Dar de alta
     * la casa de cada paciente como sede no tiene sentido: se usa una vez.
     */
    destinatario_nombre: '',
    destinatario_telefono: '',
    destinatario_direccion: '',
    destinatario_ciudad: '',
    destinatario_rural: false,
    destinatario_detalle: '',
    destinatario_lat: null,
    destinatario_lon: null,

    criticidad: 'programado',
    ventana_desde: '',
    ventana_hasta: '',
    contacto_destino_nombre: '',
    contacto_destino_telefono: '',
    observaciones: '',
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
        empaque: 'caja',
        alto_costo: false,
        temp_min: 2,
        temp_max: 8,

        /*
         * Las dispensaciones que van DENTRO de esta caja, pegadas en bruto.
         *
         * Se captura como texto y no como una lista de campos porque el cliente
         * las tiene en una pantalla de su propio sistema y lo natural es
         * copiarlas y pegarlas. Pedirle que añada cien filas a mano, una por
         * una, es garantizar que no lo haga.
         */
        referencias_texto: '',
    }
}

/*
 * Las referencias del envío: un tipo y una lista pegada.
 *
 * Vivían en una tabla de hasta cinco filas, cada una con su selector. Para un
 * radicado suelto daba igual; para una caja con veinte dispensaciones era un
 * muro. Y el tipo es uno solo en la práctica —en una caja de dispensaciones
 * las veinte son dispensaciones—, así que pedirlo veinte veces era pedir lo
 * mismo veinte veces.
 */
const tipoReferencia = ref('')
const referenciasTexto = ref('')

const referenciasPegadas = computed(() => troceaReferencias(referenciasTexto.value))

/**
 * Convierte lo pegado en una lista de referencias.
 *
 * Acepta saltos de línea, comas, punto y coma o espacios: no se sabe de dónde
 * sale el texto y no es asunto del cliente adivinar qué separador queremos.
 * Se eliminan repetidas conservando el orden —el mismo SSC dos veces en una
 * caja no es dos cosas—.
 */
function troceaReferencias(texto) {
    const vistos = new Set()

    return String(texto ?? '')
        .split(/[\s,;]+/)
        .map((t) => t.trim())
        .filter((t) => t !== '')
        .filter((t) => {
            const clave = t.toUpperCase()

            if (vistos.has(clave)) return false

            vistos.add(clave)

            return true
        })
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

/** Si el destino escogido es rural, para avisarlo antes de radicar. */
const destinoRural = computed(() => {
    if (esDomicilio.value) return forma.value.destinatario_rural

    return !!sedesDestino.value.find((s) => s.id === forma.value.sede_destino_id)?.rural
})

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
    tipoReferencia.value = c.tipo_referencia ?? ''
})

const hayFrio = computed(() => forma.value.paquetes.some((p) => p.tipo === 'cadena_frio'))
const hayControlado = computed(() => forma.value.paquetes.some((p) => p.tipo === 'controlado'))

const problemas = computed(() => {
    const f = forma.value
    const lista = []

    if (!f.cliente_id) lista.push('Falta el cliente.')
    if (!f.sede_origen_id) lista.push('Falta la sede de recolección.')
    if (esDomicilio.value) {
        if (!f.destinatario_nombre.trim()) lista.push('Falta el nombre de quien recibe.')
        if (!f.destinatario_direccion.trim()) lista.push('Falta la dirección de entrega.')
        if (!f.destinatario_ciudad.trim()) lista.push('Falta la ciudad de la dirección de entrega.')
    } else if (!f.sede_destino_id) {
        lista.push('Falta la sede de entrega.')
    }
    if (f.sede_origen_id && f.sede_origen_id === f.sede_destino_id) {
        lista.push('El origen y el destino no pueden ser la misma sede.')
    }

    // Una referencia sin tipo no se puede buscar despues: el cliente consulta
    // por «orden de compra 4460», no por «4460» a secas.
    if (referenciasPegadas.value.length && !tipoReferencia.value) {
        lista.push('Indique de que tipo son las referencias que pegó.')
    }

    if (clienteActual.value?.referencia_obligatoria && !referenciasPegadas.value.length) {
        lista.push(`Este cliente exige ${clienteActual.value.etiqueta_referencia.toLowerCase()} en cada radicación.`)
    }

    f.paquetes.forEach((p, i) => {
        // El peso es opcional, pero si lo escriben tiene que ser un peso.
        if (p.peso_kg !== '' && Number(p.peso_kg) <= 0) lista.push(`Paquete ${i + 1}: el peso no puede ser cero.`)
        if (p.tipo === 'cadena_frio' && Number(p.temp_min) >= Number(p.temp_max)) {
            lista.push(`Paquete ${i + 1}: el rango térmico está invertido.`)
        }
    })

    return lista
})

/**
 * En una urgente, la hora de cierre se propone sola: inicio más tres horas.
 *
 * Es el compromiso que el sistema asume de todos modos para una urgente, y
 * verlo escrito evita radicar a ciegas y descubrirlo después. Sigue siendo
 * editable: es una propuesta, no una imposición.
 *
 * Solo para urgentes. Las programadas conservan el valor que trae el
 * formulario al abrirse; tocarlas aquí alargaría el compromiso de todos los
 * envíos normales, que es un cambio que nadie pidió.
 *
 * Y nunca pisa una hora escrita a mano. Quien la corrige sabe algo que el
 * formulario no.
 */
/** Si el envio va a un domicilio y no a una sede registrada. */
const esDomicilio = ref(false)

const cierreTocado = ref(false)

function proponerCierre() {
    const f = forma.value

    if (f.criticidad !== 'urgente' || ! f.ventana_desde || cierreTocado.value) return

    const horas = 3
    const cierre = new Date(f.ventana_desde)

    if (Number.isNaN(cierre.getTime())) return

    cierre.setHours(cierre.getHours() + horas)

    // `toISOString` pasa a UTC y adelantaría el reloj cinco horas. Se arma a
    // mano en hora local, que es la que el campo muestra y el cliente lee.
    const d = (n) => String(n).padStart(2, '0')

    f.ventana_hasta = `${cierre.getFullYear()}-${d(cierre.getMonth() + 1)}-${d(cierre.getDate())}`
        + `T${d(cierre.getHours())}:${d(cierre.getMinutes())}`
}

watch(() => [forma.value.ventana_desde, forma.value.criticidad], proponerCierre)

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
        sede_destino_id: esDomicilio.value ? null : f.sede_destino_id,
        destinatario: !esDomicilio.value ? null : {
            nombre: f.destinatario_nombre.trim(),
            telefono: f.destinatario_telefono.trim() || null,
            direccion: f.destinatario_direccion.trim(),
            ciudad: f.destinatario_ciudad.trim(),
            detalle_acceso: f.destinatario_detalle.trim() || null,
            rural: f.destinatario_rural,
            latitud: f.destinatario_lat,
            longitud: f.destinatario_lon,
        },
        criticidad: f.criticidad,
        ventana_desde: f.ventana_desde || null,
        ventana_hasta: f.ventana_hasta || null,
        /*
         * En un domicilio el contacto del destino ES el destinatario.
         *
         * El campo sigue viajando —lo usan la etiqueta impresa, el aviso al
         * mensajero y el soporte— pero se toma de lo que ya se escribió arriba
         * en vez de pedirlo otra vez. Antes se mandaba vacío y la guía de un
         * domicilio salía sin contacto en el papel.
         */
        contacto_destino_nombre: esDomicilio.value
            ? (f.destinatario_nombre.trim() || null)
            : (f.contacto_destino_nombre || null),
        contacto_destino_telefono: esDomicilio.value
            ? (f.destinatario_telefono.trim() || null)
            : (f.contacto_destino_telefono || null),
        observaciones: f.observaciones || null,
        referencias: referenciasPegadas.value.map((valor) => ({ tipo: tipoReferencia.value, valor })),
        paquetes: f.paquetes.map((p) => ({
            tipo: p.tipo,
            descripcion: p.descripcion || null,
            lote: p.lote || null,
            peso_kg: p.peso_kg === '' ? null : Number(p.peso_kg),
            largo_cm: p.largo_cm ? Number(p.largo_cm) : null,
            ancho_cm: p.ancho_cm ? Number(p.ancho_cm) : null,
            alto_cm: p.alto_cm ? Number(p.alto_cm) : null,
            empaque: p.empaque,
            alto_costo: p.alto_costo,
            temp_min: p.tipo === 'cadena_frio' ? Number(p.temp_min) : null,
            temp_max: p.tipo === 'cadena_frio' ? Number(p.temp_max) : null,

            // Todas con el tipo que el cliente tiene por omision: en una caja de
            // dispensaciones las cien son dispensaciones.
            referencias: troceaReferencias(p.referencias_texto).map((valor) => ({
                tipo: clienteActual.value?.tipo_referencia ?? 'radicado',
                valor,
            })),
        })),
    }

    try {
        creada.value = await api.crearOrden(cuerpo)
        errorImpresion.value = null

        /*
         * Subir la pantalla al confirmar.
         *
         * La tarjeta de éxito sustituye a un formulario largo, y el navegador
         * conserva el desplazamiento: quien pulsaba «Radicar» abajo del todo se
         * quedaba mirando el pie de página y creía que la pantalla había
         * vuelto atrás sin hacer nada.
         */
        window.scrollTo({ top: 0, behavior: 'smooth' })
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

/**
 * Deja el formulario como recién abierto.
 *
 * Antes solo vaciaba las referencias, los paquetes y las observaciones: sede,
 * ventana, contacto y criticidad se quedaban con lo del envío anterior. Quien
 * radicaba dos seguidos se encontraba medio formulario lleno y tenía que
 * adivinar qué partía de la guía anterior —y lo que no se nota, se radica—.
 *
 * Lo único que sobrevive es el cliente, que para un usuario de cliente no es
 * una elección suya de todos modos.
 */
function otra() {
    const cliente = forma.value.cliente_id

    creada.value = null
    errorImpresion.value = null
    error.value = null

    forma.value = {
        cliente_id: cliente,
        sede_origen_id: null,
        sede_destino_id: null,
        destinatario_nombre: '',
        destinatario_telefono: '',
        destinatario_direccion: '',
        destinatario_ciudad: '',
    destinatario_rural: false,
        destinatario_detalle: '',
        destinatario_lat: null,
        destinatario_lon: null,
        criticidad: 'programado',
        ventana_desde: '',
        ventana_hasta: '',
        contacto_destino_nombre: '',
        contacto_destino_telefono: '',
        observaciones: '',
        paquetes: [nuevoPaquete()],
    }

    tipoReferencia.value = clienteActual.value?.tipo_referencia ?? 'radicado'
    referenciasTexto.value = ''
    cierreTocado.value = false
    esDomicilio.value = false

    window.scrollTo({ top: 0, behavior: 'smooth' })
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

                    <!--
                        Sede pactada o domicilio.
                        
                        Dos cosas distintas que merecen dos caminos: una clínica
                        es un punto que se visita cien veces y conviene tenerlo
                        registrado, verificado y con zona; la casa de un paciente
                        se visita una vez y darla de alta como sede solo ensucia
                        el catálogo.
                    -->
                    <div class="campo-grupo campo-grupo--ancho">
                        <span class="etiqueta">Entregar en</span>

                        <div class="conmutador">
                            <button
                                type="button" :class="{ activo: !esDomicilio }"
                                @click="esDomicilio = false"
                            >Una sede registrada</button>
                            <button
                                type="button" :class="{ activo: esDomicilio }"
                                @click="esDomicilio = true"
                            >Un domicilio</button>
                        </div>
                    </div>

                    <label v-if="!esDomicilio" class="campo-grupo">
                        <span class="etiqueta">Sede de entrega</span>
                        <select v-model.number="forma.sede_destino_id" class="campo" :class="{ 'campo--vacio': forma.sede_destino_id === null }">
                            <option :value="null">Seleccione…</option>
                            <option v-for="s in sedesDestino" :key="s.id" :value="s.id">
                                {{ s.nombre }} — {{ s.ciudad }}{{ s.rural ? ' · rural' : '' }}
                            </option>
                        </select>

                        <!--
                            Se avisa al escoger, no en la factura. Un recargo
                            del que uno se entera a fin de mes es una discusión;
                            el mismo recargo dicho aquí es una decisión.
                        -->
                        <span v-if="destinoRural" class="pista pista--rural">
                            Destino rural: este envío lleva el recargo por desplazamiento fuera del casco urbano.
                        </span>
                    </label>

                    <template v-if="esDomicilio">
                        <label class="campo-grupo">
                            <span class="etiqueta">Quién recibe</span>
                            <input v-model="forma.destinatario_nombre" class="campo" type="text" placeholder="Nombre del paciente">
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Teléfono · opcional</span>
                            <input v-model="forma.destinatario_telefono" class="campo" type="tel" placeholder="300 000 0000">
                        </label>

                        <label class="campo-grupo campo-grupo--ancho">
                            <span class="etiqueta">Dirección</span>
                            <input v-model="forma.destinatario_direccion" class="campo" type="text" placeholder="Cra 51 #100-25, apto 302">
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Ciudad</span>
                            <input v-model="forma.destinatario_ciudad" class="campo" type="text" placeholder="Cartagena">
                        </label>

                        <label class="campo-grupo campo-grupo--ancho interruptor">
                            <input v-model="forma.destinatario_rural" type="checkbox">
                            <span>
                                <strong>Es un destino rural</strong> —corregimiento, vereda, finca—.
                                Lleva recargo por desplazamiento fuera del casco urbano.
                            </span>
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Detalle de acceso · opcional</span>
                            <input v-model="forma.destinatario_detalle" class="campo" type="text" placeholder="Portería, torre 2">
                        </label>

                        <!--
                            La coordenada es opcional pero vale mucho: sin ella el
                            mensajero navega a ojo y la entrega no se puede validar
                            contra el sitio. Marcarla cuesta un clic.
                        -->
                        <div class="campo-grupo campo-grupo--ancho">
                            <span class="etiqueta">Ubicación en el mapa · opcional</span>
                            <SelectorMapa
                                v-model:latitud="forma.destinatario_lat"
                                v-model:longitud="forma.destinatario_lon"
                                alto="240px"
                            />
                        </div>
                    </template>

                    <!--
                        Solo para sedes.

                        En un domicilio este bloque pedía por segunda vez lo que
                        ya se acababa de escribir arriba —quién recibe y su
                        teléfono—, con otro rótulo. Dos campos para el mismo
                        dato no se llenan igual: se llena uno, el otro queda
                        vacío o distinto, y después nadie sabe a cuál llamar.
                        En una sede sí tiene sentido: el contacto del envío
                        puede no ser el que figura en la ficha de la sede.
                    -->
                    <template v-if="!esDomicilio">
                        <label class="campo-grupo">
                            <span class="etiqueta">Contacto en destino · opcional</span>
                            <input v-model="forma.contacto_destino_nombre" class="campo" type="text" placeholder="Q.F. responsable">
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Teléfono · opcional</span>
                            <input v-model="forma.contacto_destino_telefono" class="campo" type="tel" placeholder="604 000 0000">
                        </label>
                    </template>
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

                    <!--
                        Pegar, no teclear.
                        
                        Quien radica diez dispensaciones las tiene en una pantalla
                        de su propio sistema; lo natural es copiarlas. Pedirle diez
                        filas a mano, cada una con su selector de tipo, es
                        garantizar que no lo haga —o que registre una sola y las
                        otras nueve se queden sin soporte—.
                    -->
                    <div class="ref-pegar">
                        <select v-model="tipoReferencia" class="campo campo--tipo" :class="{ 'campo--vacio': !tipoReferencia }">
                            <option value="">Seleccione…</option>
                            <option v-for="t in catalogo.tipos_referencia" :key="t.valor" :value="t.valor">
                                {{ t.etiqueta }}
                            </option>
                        </select>

                        <textarea
                            v-model="referenciasTexto" class="campo campo--area" rows="3"
                            placeholder="SSC6111111&#10;SSC6111112&#10;SSC6111113"
                        ></textarea>
                    </div>

                    <small class="ayuda-campo">
                        Una por línea, o separadas por coma.
                        <template v-if="referenciasPegadas.length">
                            — <strong>{{ referenciasPegadas.length }}</strong> registrada(s).
                        </template>
                    </small>

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

                        <!--
                            En qué viaja, que es distinto de qué contiene.
                            
                            De esto depende el volumen que ocupa y por tanto lo
                            que cuesta moverlo: una caja de pañales ocupa el
                            doble que todo lo demás junto.
                        -->
                        <!--
                            Lo declara el cliente porque es quien sabe lo que
                            mete en la caja. El sistema no conoce el valor del
                            contenido —ni debe— pero sí puede llevar la
                            advertencia de quien sí lo sabe.
                        -->
                        <label class="campo-grupo campo-grupo--ancho">
                            <span class="etiqueta">Medicamento de alto costo</span>
                            <label class="casilla">
                                <input v-model="p.alto_costo" type="checkbox">
                                <span>
                                    Sí — requiere recomendaciones especiales para su traslado.
                                    Se marca en el despacho y en la tarea del mensajero.
                                </span>
                            </label>
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Empaque</span>
                            <select v-model="p.empaque" class="campo">
                                <option value="bolsa">Bolsa</option>
                                <option value="caja">Caja</option>
                                <option value="panales">Pañales</option>
                            </select>
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Lote</span>
                            <input v-model="p.lote" class="campo" type="text">
                        </label>

                        <!--
                            Las dispensaciones que van dentro de ESTA caja.
                            Ocupa el ancho entero porque es lo que se pega, no
                            lo que se teclea: el cliente las copia de su sistema.
                        -->
                        <!--
                            Solo cuando hay varias cajas.
                            
                            Con una sola, las referencias del envío YA son las de
                            esa caja —el servidor lo resuelve así— y enseñar aquí
                            un segundo sitio para lo mismo solo confunde.
                        -->
                        <label v-if="forma.paquetes.length > 1" class="campo-grupo campo-grupo--ancho">
                            <span class="etiqueta">
                                {{ clienteActual?.etiqueta_referencia || 'Referencias' }} de esta caja
                                · opcional
                            </span>
                            <textarea
                                v-model="p.referencias_texto" class="campo campo--area" rows="3"
                                placeholder="SSC6111111&#10;SSC6111112&#10;SSC6111113"
                            ></textarea>
                            <small class="ayuda-campo">
                                Si lo deja vacío, esta caja lleva las referencias del envío.
                                <template v-if="troceaReferencias(p.referencias_texto).length">
                                    — <strong>{{ troceaReferencias(p.referencias_texto).length }}</strong> en esta caja.
                                </template>
                            </small>
                        </label>

                        <label class="campo-grupo">
                            <span class="etiqueta">Peso (kg) · opcional</span>
                            <input v-model="p.peso_kg" class="campo num" type="number" step="0.01" min="0.01" placeholder="Si lo sabe">
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
                            <span class="etiqueta">
                                Ventana hasta
                                <template v-if="forma.criticidad === 'urgente' && !cierreTocado">
                                    · <span class="et-auto">3 h por ser urgente</span>
                                </template>
                            </span>
                            <input
                                v-model="forma.ventana_hasta" class="campo" type="datetime-local"
                                @input="cierreTocado = true"
                            >
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
.et-auto { color: var(--acento); font-weight: 600; }

.campo-grupo--ancho { grid-column: 1 / -1; }
.casilla { display: flex; gap: .55rem; align-items: flex-start; font-size: .84rem; line-height: 1.45; color: var(--ink-2); cursor: pointer; }
.casilla input { margin-top: .2rem; flex: none; }

.conmutador { display: flex; gap: .4rem; }
.conmutador button {
    flex: 1; min-height: 42px; padding: 0 .8rem;
    border: 1px solid var(--linea-fuerte); border-radius: var(--r-sm);
    background: var(--superficie); color: var(--ink-2);
    font-family: inherit; font-size: .88rem; cursor: pointer;
}
.conmutador button.activo { border-color: var(--acento); background: var(--acento-suave, #eaf1ff); color: var(--acento-ink, #004CB9); font-weight: 600; }
.ref-pegar { display: flex; gap: .5rem; align-items: flex-start; }
.ref-pegar .campo--tipo { flex: 0 0 11rem; }
.ref-pegar .campo--area { flex: 1; min-width: 0; }
.campo--area { font-family: inherit; resize: vertical; min-height: 68px; line-height: 1.5; }
.ayuda-campo { display: block; margin-top: .3rem; font-size: .76rem; color: var(--ink-3); line-height: 1.45; }

.pista--rural {
    display: block;
    margin-top: .4rem;
    padding: .4rem .6rem;
    border-left: 3px solid var(--atencion);
    border-radius: 0 6px 6px 0;
    background: var(--superficie-2);
    font-size: .78rem;
    line-height: 1.5;
    color: var(--ink-2);
}

/* La casilla de rural, en la columna ancha del domicilio. */
.interruptor {
    flex-direction: row;
    align-items: flex-start;
    gap: .55rem;
    font-size: .82rem;
    line-height: 1.5;
    color: var(--ink-2);
}

.interruptor input { margin-top: .2rem; }

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
