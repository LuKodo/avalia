<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../../api/cliente'
import Banner from '../../componentes/Banner.vue'
import Campo from '../../componentes/Campo.vue'
import Hero from '../../componentes/Hero.vue'
import PanelTabla from '../../componentes/PanelTabla.vue'
import SeccionForma from '../../componentes/SeccionForma.vue'
import { moneda, numero } from '../../graficos/util'

/**
 * Catálogo maestro de clientes, sedes y zonas.
 *
 * Es lo que hay que cargar antes de poder radicar nada. El orden en las
 * pestañas no es alfabético: primero el cliente, después sus sedes, después la
 * zona que fija la tarifa — que es el orden en que hay que crearlos.
 */
const datos = ref(null)
const cargando = ref(true)
const error = ref(null)
const guardando = ref(false)
const exito = ref(null)
const errores = ref({})
const vista = ref('clientes')
const editando = ref(null)   // null | 'nuevo' | id

const TIPOS_REF = [
    { valor: 'radicado', etiqueta: 'Número de radicado' },
    { valor: 'orden_compra', etiqueta: 'Orden de compra' },
    { valor: 'remision', etiqueta: 'Remisión' },
    { valor: 'factura', etiqueta: 'Factura' },
    { valor: 'historia', etiqueta: 'Historia clínica' },
    { valor: 'dispensacion', etiqueta: 'Dispensación' },
    { valor: 'interno', etiqueta: 'Referencia interna' },
]

const TIPOS_SEDE = ['hospital', 'clinica', 'farmacia', 'bodega', 'consultorio', 'otro']

const formCliente = reactive(vacioCliente())
const formSede = reactive(vacioSede())
const formZona = reactive(vacioZona())

function vacioCliente() {
    return {
        razon_social: '', nit: '', email_notificaciones: '', telefono: '',
        etiqueta_referencia: 'Radicado', tipo_referencia_defecto: '',
        referencia_obligatoria: false,
    }
}

function vacioSede() {
    return {
        cliente_id: null, zona_id: null, nombre: '', codigo_centro_costo: '', tipo: '',
        direccion: '', detalle_acceso: '', ciudad: 'Medellín', rural: false,
        latitud: '', longitud: '', coordenada_verificada: false,
        contacto_nombre: '', contacto_telefono: '',
    }
}

function vacioZona() {
    return { nombre: '', ciudad: 'Medellín', tarifa_base: '', tarifa_refrigerado: '' }
}

async function cargar() {
    try {
        datos.value = await api.adminClientes()
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

function abrirNuevo() {
    errores.value = {}
    exito.value = null
    editando.value = 'nuevo'

    if (vista.value === 'clientes') Object.assign(formCliente, vacioCliente())
    if (vista.value === 'sedes') Object.assign(formSede, vacioSede())
    if (vista.value === 'zonas') Object.assign(formZona, vacioZona())
}

function editar(fila) {
    errores.value = {}
    exito.value = null
    editando.value = fila.id

    if (vista.value === 'clientes') {
        Object.assign(formCliente, {
            razon_social: fila.razon_social, nit: fila.nit,
            email_notificaciones: fila.email ?? '', telefono: fila.telefono ?? '',
            etiqueta_referencia: fila.etiqueta_referencia,
            tipo_referencia_defecto: fila.tipo_referencia_defecto ?? 'radicado',
            referencia_obligatoria: fila.referencia_obligatoria,
        })
    }

    /*
     * Las sedes se rellenan desde `fila.datos`, los valores crudos, y no desde
     * las columnas de la tabla: esas están compuestas para leerse —la
     * dirección lleva la ciudad pegada, el cliente es la razón social— y
     * devolverlas al formulario guardaría basura.
     */
    if (vista.value === 'sedes') {
        Object.assign(formSede, {
            ...vacioSede(),
            ...fila.datos,
            latitud: fila.datos?.latitud ?? '',
            longitud: fila.datos?.longitud ?? '',
            codigo_centro_costo: fila.datos?.codigo_centro_costo ?? '',
            detalle_acceso: fila.datos?.detalle_acceso ?? '',
            contacto_nombre: fila.datos?.contacto_nombre ?? '',
            contacto_telefono: fila.datos?.contacto_telefono ?? '',
        })
    }

    if (vista.value === 'zonas') {
        Object.assign(formZona, {
            nombre: fila.nombre, ciudad: fila.ciudad,
            tarifa_base: fila.tarifa_base, tarifa_refrigerado: fila.tarifa_refrigerado,
        })
    }
}

function cancelar() {
    editando.value = null
    errores.value = {}
}

/* ====================================================== integración ===== */

/**
 * La integración se edita en su propio formulario, no dentro del de cliente.
 *
 * No es manía de separar: aquí se maneja una credencial de un sistema ajeno, y
 * mezclarla con «corregir el teléfono» invita a que alguien la toque sin
 * querer. Además tiene una regla propia —el token solo se escribe si se
 * escribe— que no encaja con el resto de campos.
 */
const integrando = ref(null)

const formIntegracion = reactive({
    activa: false, api_url: '', bucket: '', prefijo: '', token: '',
})

function abrirIntegracion(c) {
    integrando.value = c.id
    editando.value = null
    errores.value = {}
    exito.value = null

    Object.assign(formIntegracion, {
        activa: c.integracion?.activa ?? false,
        api_url: c.integracion?.api_url ?? '',
        bucket: c.integracion?.bucket ?? '',
        prefijo: c.integracion?.prefijo ?? '',
        // Siempre vacío: el token nunca viaja de vuelta, así que no hay nada
        // que precargar. Dejarlo vacío también es lo que hace que guardar sin
        // tocarlo conserve el que ya había.
        token: '',
    })
}

function cancelarIntegracion() {
    integrando.value = null
    errores.value = {}
}

const clienteIntegrando = computed(
    () => datos.value?.clientes.find((c) => c.id === integrando.value) ?? null)

async function guardarIntegracion() {
    guardando.value = true
    errores.value = {}
    exito.value = null

    try {
        await api.guardarIntegracion(integrando.value, { ...formIntegracion })

        exito.value = formIntegracion.activa
            ? 'Integración configurada y activa.'
            : 'Integración desactivada.'

        integrando.value = null
        await cargar()
    } catch (e) {
        errores.value = e.cuerpo?.errors ?? {}
        error.value = e.cuerpo?.message ?? e.message
    } finally {
        guardando.value = false
    }
}

async function guardar() {
    guardando.value = true
    errores.value = {}
    exito.value = null

    const nuevo = editando.value === 'nuevo'

    try {
        let r

        if (vista.value === 'clientes') {
            r = nuevo
                ? await api.crearCliente({ ...formCliente })
                : await api.actualizarCliente(editando.value, { ...formCliente })
            exito.value = `Cliente ${nuevo ? 'creado' : 'actualizado'}: ${r.razon_social} (${r.codigo})`
        } else if (vista.value === 'sedes') {
            const cuerpo = {
                ...formSede,
                latitud: formSede.latitud === '' ? null : Number(formSede.latitud),
                longitud: formSede.longitud === '' ? null : Number(formSede.longitud),
            }
            r = nuevo
                ? await api.crearSede(cuerpo)
                : await api.actualizarSede(editando.value, cuerpo)

            exito.value = `Sede ${nuevo ? 'creada' : 'actualizada'}: ${r.nombre}`
                + (r.coordenada_verificada ? '' : ' — sin coordenada verificada, el motor no podrá calcular cercanía.')
        } else {
            const cuerpo = {
                ...formZona,
                tarifa_base: Number(formZona.tarifa_base),
                tarifa_refrigerado: Number(formZona.tarifa_refrigerado),
            }
            r = nuevo
                ? await api.crearZona(cuerpo)
                : await api.actualizarZona(editando.value, cuerpo)
            exito.value = `Zona ${nuevo ? 'creada' : 'actualizada'}: ${r.nombre}`
        }

        editando.value = null
        await cargar()
    } catch (e) {
        // Los errores de campo se muestran junto al campo; el resto, arriba.
        errores.value = e.cuerpo?.errors ?? {}

        if (!Object.keys(errores.value).length) {
            error.value = e.cuerpo?.message ?? e.message
        }
    } finally {
        guardando.value = false
    }
}

async function alternar(tipo, fila) {
    try {
        if (tipo === 'cliente') await api.alternarCliente(fila.id)
        if (tipo === 'sede') await api.alternarSede(fila.id)
        await cargar()
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    }
}

const err = (campo) => errores.value[campo]?.[0] ?? ''

const cifras = computed(() => {
    if (!datos.value) return []

    const sinCoord = datos.value.sedes.filter((s) => !s.tiene_coordenada).length

    return [
        { etiqueta: 'Clientes', valor: datos.value.clientes.length },
        { etiqueta: 'Sedes', valor: datos.value.sedes.length },
        { etiqueta: 'Sin coordenada', valor: sinCoord, tono: sinCoord ? 'atencion' : 'bien' },
        { etiqueta: 'Zonas', valor: datos.value.zonas.length },
    ]
})

const clientesActivos = computed(() => (datos.value?.clientes ?? []).filter((c) => c.activo))

const pestanas = computed(() => [
    { k: 'clientes', t: 'Clientes', n: datos.value?.clientes.length ?? 0 },
    { k: 'sedes', t: 'Sedes', n: datos.value?.sedes.length ?? 0 },
    { k: 'zonas', t: 'Zonas y tarifas', n: datos.value?.zonas.length ?? 0 },
])

function cambiarVista(k) {
    vista.value = k
    editando.value = null
    errores.value = {}
}
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Administración · catálogo maestro"
            titulo="Clientes, sedes y zonas"
            descripcion="Sin este catálogo no se puede radicar nada. El orden importa: primero el cliente, después sus sedes, después la zona que fija la tarifa."
            :cifras="cifras"
        />

        <Banner
            v-if="exito"
            tono="bien"
            titulo="Guardado"
            :texto="exito"
        >
            <template #accion>
                <button type="button" class="boton boton--secundario boton--compacto" @click="exito = null">
                    Ocultar
                </button>
            </template>
        </Banner>

        <Banner v-if="error" tono="critico" titulo="No fue posible completar la operación" :texto="error" />

        <p v-if="cargando" class="cargando">Cargando catálogo…</p>

        <template v-else-if="datos">
            <!-- pestañas -->
            <nav class="pestanas" aria-label="Secciones del catálogo">
                <button
                    v-for="p in pestanas" :key="p.k"
                    type="button" class="pestana"
                    :class="{ 'pestana--activa': vista === p.k }"
                    :aria-current="vista === p.k"
                    @click="cambiarVista(p.k)"
                >
                    {{ p.t }}<span class="pestana-n">{{ p.n }}</span>
                </button>
            </nav>

            <!-- ================================================== CLIENTES -->
            <template v-if="vista === 'clientes'">
                <SeccionForma
                    v-if="editando"
                    :numero="editando === 'nuevo' ? '+' : '✎'"
                    :titulo="editando === 'nuevo' ? 'Nuevo cliente' : 'Editar cliente'"
                    descripcion="La etiqueta de referencia es cómo el cliente llama a SU número. Si dice «radicado», el portal y el acta dirán «radicado»: no se le impone un nombre nuestro."
                >
                    <Campo etiqueta="Razón social" obligatorio :error="err('razon_social')" ancho v-slot="{ id }">
                        <input :id="id" v-model="formCliente.razon_social" class="control" type="text" placeholder="Clínica del Norte S.A.S.">
                    </Campo>

                    <Campo etiqueta="NIT" obligatorio :error="err('nit')" v-slot="{ id }">
                        <input :id="id" v-model="formCliente.nit" class="control control--num" type="text" placeholder="890.900.123-4">
                    </Campo>

                    <Campo etiqueta="Correo de notificaciones" :error="err('email_notificaciones')" v-slot="{ id }">
                        <input :id="id" v-model="formCliente.email_notificaciones" class="control" type="email" placeholder="farmacia@cliente.com">
                    </Campo>

                    <Campo etiqueta="Teléfono" :error="err('telefono')" v-slot="{ id }">
                        <input :id="id" v-model="formCliente.telefono" class="control" type="tel" placeholder="604 000 0000">
                    </Campo>

                    <Campo
                        etiqueta="Cómo llama a su referencia" obligatorio
                        ayuda="Se muestra tal cual en el portal y en el acta de entrega."
                        :error="err('etiqueta_referencia')" v-slot="{ id }"
                    >
                        <input :id="id" v-model="formCliente.etiqueta_referencia" class="control" type="text" placeholder="Radicado">
                    </Campo>

                    <Campo etiqueta="Tipo por defecto" obligatorio :error="err('tipo_referencia_defecto')" v-slot="{ id }">
                        <select :id="id" v-model="formCliente.tipo_referencia_defecto" class="control">
                            <option value="">Seleccione…</option>
                            <option v-for="t in TIPOS_REF" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
                        </select>
                    </Campo>

                    <Campo etiqueta="¿Es obligatoria al radicar?" ancho v-slot="{ id }">
                        <label class="interruptor">
                            <input :id="id" v-model="formCliente.referencia_obligatoria" type="checkbox">
                            <span>
                                Sí — el sistema rechazará radicaciones de este cliente sin referencia.
                                Actívelo solo si el cliente siempre la tiene: si no, bloquea la operación.
                            </span>
                        </label>
                    </Campo>

                    <template #pie>
                        <div class="acciones-forma">
                            <button type="button" class="boton boton--secundario" @click="cancelar">Cancelar</button>
                            <button type="button" class="boton boton--primario" :disabled="guardando" @click="guardar">
                                {{ guardando ? 'Guardando…' : (editando === 'nuevo' ? 'Crear cliente' : 'Guardar cambios') }}
                            </button>
                        </div>
                    </template>
                </SeccionForma>

                <!-- ============================================ integración -->
                <SeccionForma
                    v-if="integrando"
                    numero="⇄"
                    :titulo="`Integración · ${clienteIntegrando?.razon_social ?? ''}`"
                    descripcion="Si está activa, Avalia envía la foto del soporte al repositorio del cliente en cuanto se cierra la entrega. El envío va en cola y con reintentos: un fallo suyo no detiene la operación. El centro de costo viaja con cada envío y se configura en cada sede, no aquí: cambia de un punto a otro, que es para lo que sirve."
                >
                    <Campo etiqueta="¿Tiene integración?" ancho v-slot="{ id }">
                        <label class="interruptor">
                            <input :id="id" v-model="formIntegracion.activa" type="checkbox">
                            <span>
                                Sí — cada entrega de este cliente intentará enviar el soporte a su repositorio.
                                Un cliente sin integración no hace ninguna llamada a terceros.
                            </span>
                        </label>
                    </Campo>

                    <Campo etiqueta="Dirección de la API" :error="err('api_url')" ancho v-slot="{ id }">
                        <input :id="id" v-model="formIntegracion.api_url" class="control" type="url" placeholder="https://cassandra.pharmaser.com.co/api/v1">
                    </Campo>

                    <Campo etiqueta="Bucket" :error="err('bucket')" v-slot="{ id }">
                        <input :id="id" v-model="formIntegracion.bucket" class="control" type="text" placeholder="bucket_cpscan">
                    </Campo>

                    <Campo
                        etiqueta="Prefijo a recortar"
                        ayuda="El archivo se llama como la referencia del cliente sin este prefijo: SSC6564684 se sube como 6564684."
                        :error="err('prefijo')" v-slot="{ id }"
                    >
                        <input :id="id" v-model="formIntegracion.prefijo" class="control" type="text" placeholder="SSC">
                    </Campo>

                    <Campo
                        etiqueta="Credencial"
                        :ayuda="clienteIntegrando?.integracion?.tiene_token
                            ? 'Ya hay una configurada. Déjelo vacío para conservarla; escriba una nueva para reemplazarla.'
                            : 'No se muestra nunca una vez guardada.'"
                        :error="err('token')" ancho v-slot="{ id }"
                    >
                        <input
                            :id="id" v-model="formIntegracion.token" class="control" type="password"
                            autocomplete="new-password"
                            :placeholder="clienteIntegrando?.integracion?.tiene_token ? '••••••  configurada' : 'Pegue aquí la credencial'"
                        >
                    </Campo>

                    <template #pie>
                        <div class="acciones-forma">
                            <button type="button" class="boton boton--secundario" @click="cancelarIntegracion">Cancelar</button>
                            <button type="button" class="boton boton--primario" :disabled="guardando" @click="guardarIntegracion">
                                {{ guardando ? 'Guardando…' : 'Guardar integración' }}
                            </button>
                        </div>
                    </template>
                </SeccionForma>

                <PanelTabla
                    titulo="Clientes" :total="datos.clientes.length"
                    descripcion="Desactivar un cliente no borra su historia: sus envíos siguen consultables, solo deja de poder radicar."
                    :vacio="!datos.clientes.length"
                >
                    <template #acciones>
                        <button v-if="!editando" type="button" class="boton boton--primario boton--compacto" @click="abrirNuevo">
                            ＋ Nuevo cliente
                        </button>
                    </template>

                    <table class="tabla-datos">
                        <thead>
                            <tr>
                                <th>Código</th><th>Razón social</th><th>NIT</th>
                                <th>Su referencia</th><th>Obligatoria</th>
                                <th>Integración</th>
                                <th>Sedes</th><th>Envíos</th><th>Usuarios</th><th>Estado</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in datos.clientes" :key="c.id" :class="{ 'fila--inactiva': !c.activo }">
                                <td class="num">{{ c.codigo }}</td>
                                <td class="celda-principal">{{ c.razon_social }}</td>
                                <td class="num">{{ c.nit }}</td>
                                <td><span class="pastilla pastilla--neutral">{{ c.etiqueta_referencia }}</span></td>
                                <td>
                                    <span v-if="c.referencia_obligatoria" class="pastilla pastilla--atencion">Sí</span>
                                    <span v-else class="celda-tenue">No</span>
                                </td>
                                <td>
                                    <span v-if="c.integracion?.activa" class="pastilla pastilla--bien">
                                        {{ c.integracion.tipo }}
                                    </span>
                                    <span v-else class="celda-tenue">—</span>
                                    <!--
                                        Una integración activa sin credencial no falla al guardarse:
                                        falla en la primera entrega, horas después. Conviene que se
                                        vea aquí.
                                    -->
                                    <span v-if="c.integracion?.activa && !c.integracion?.tiene_token"
                                          class="pastilla pastilla--critico">sin credencial</span>
                                </td>
                                <td class="num">{{ c.sedes }}</td>
                                <td class="num">{{ numero(c.ordenes) }}</td>
                                <td class="num">{{ c.usuarios }}</td>
                                <td>
                                    <span class="pastilla" :class="c.activo ? 'pastilla--bien' : 'pastilla--critico'">
                                        {{ c.activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="celda-acciones">
                                    <button type="button" class="boton boton--secundario boton--compacto" @click="editar(c)">Editar</button>
                                    <button type="button" class="boton boton--secundario boton--compacto" @click="abrirIntegracion(c)">
                                        Integración
                                    </button>
                                    <button type="button" class="boton boton--secundario boton--compacto" @click="alternar('cliente', c)">
                                        {{ c.activo ? 'Desactivar' : 'Activar' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </PanelTabla>
            </template>

            <!-- ===================================================== SEDES -->
            <template v-else-if="vista === 'sedes'">
                <SeccionForma
                    v-if="editando"
                    :numero="editando === 'nuevo' ? '+' : '✎'"
                    :titulo="editando === 'nuevo' ? 'Nueva sede' : 'Editar sede'"
                    descripcion="La coordenada es lo que permite al motor calcular cercanía y validar que la entrega ocurrió donde debía. Una sede sin ella obliga a asignar a mano."
                >
                    <Campo etiqueta="Cliente" ayuda="Déjelo vacío si es una sede propia de Avalia, como una bodega." :error="err('cliente_id')" v-slot="{ id }">
                        <select :id="id" v-model="formSede.cliente_id" class="control">
                            <option :value="null">Propia de Avalia</option>
                            <option v-for="c in clientesActivos" :key="c.id" :value="c.id">{{ c.razon_social }}</option>
                        </select>
                    </Campo>

                    <Campo etiqueta="Nombre de la sede" obligatorio :error="err('nombre')" v-slot="{ id }">
                        <input :id="id" v-model="formSede.nombre" class="control" type="text" placeholder="Clínica del Norte · Farmacia">
                    </Campo>

                    <Campo
                        etiqueta="Centro de costo"
                        ayuda="Código con el que el CLIENTE identifica esta sede en su contabilidad. Viaja con cada soporte que se le envía, si tiene integración."
                        :error="err('codigo_centro_costo')" v-slot="{ id }"
                    >
                        <input :id="id" v-model="formSede.codigo_centro_costo" class="control control--num" type="text" placeholder="002">
                    </Campo>

                    <Campo etiqueta="Tipo" obligatorio :error="err('tipo')" v-slot="{ id }">
                        <select :id="id" v-model="formSede.tipo" class="control">
                            <option value="">Seleccione…</option>
                            <option v-for="t in TIPOS_SEDE" :key="t" :value="t">{{ t }}</option>
                        </select>
                    </Campo>

                    <Campo etiqueta="Zona" ayuda="Define la tarifa del envío hacia esta sede." :error="err('zona_id')" v-slot="{ id }">
                        <select :id="id" v-model="formSede.zona_id" class="control">
                            <option :value="null">Sin zona</option>
                            <option v-for="z in datos.zonas" :key="z.id" :value="z.id">{{ z.nombre }}</option>
                        </select>
                    </Campo>

                    <Campo etiqueta="Dirección" obligatorio :error="err('direccion')" v-slot="{ id }">
                        <input :id="id" v-model="formSede.direccion" class="control" type="text" placeholder="Cra 51 #100-25">
                    </Campo>

                    <Campo etiqueta="Detalle de acceso" ayuda="«Piso 3, portería 2, muelle»: lo que el mensajero necesita al llegar." v-slot="{ id }">
                        <input :id="id" v-model="formSede.detalle_acceso" class="control" type="text">
                    </Campo>

                    <Campo etiqueta="Ciudad" obligatorio :error="err('ciudad')" v-slot="{ id }">
                        <input :id="id" v-model="formSede.ciudad" class="control" type="text">
                    </Campo>

                    <Campo etiqueta="¿Destino rural?" ancho v-slot="{ id }">
                        <label class="interruptor">
                            <input :id="id" v-model="formSede.rural" type="checkbox">
                            <span>
                                Corregimiento, vereda o finca. Lleva el recargo por desplazamiento
                                fuera del casco urbano, y al cliente se le avisa al escoger el destino.
                                El porcentaje se fija en <strong>Parámetros · tarifa.recargo_rural</strong>;
                                mientras esté en cero no se cobra nada.
                            </span>
                        </label>
                    </Campo>

                    <Campo etiqueta="Latitud" :error="err('latitud')" ayuda="Ej.: 6.2988" v-slot="{ id }">
                        <input :id="id" v-model="formSede.latitud" class="control control--num" type="number" step="0.0000001">
                    </Campo>

                    <Campo etiqueta="Longitud" :error="err('longitud')" ayuda="Ej.: -75.5619" v-slot="{ id }">
                        <input :id="id" v-model="formSede.longitud" class="control control--num" type="number" step="0.0000001">
                    </Campo>

                    <Campo etiqueta="Contacto en la sede" v-slot="{ id }">
                        <input :id="id" v-model="formSede.contacto_nombre" class="control" type="text" placeholder="Q.F. responsable">
                    </Campo>

                    <Campo etiqueta="Teléfono del contacto" v-slot="{ id }">
                        <input :id="id" v-model="formSede.contacto_telefono" class="control" type="tel">
                    </Campo>

                    <Campo etiqueta="¿Coordenada verificada en campo?" ancho v-slot="{ id }">
                        <label class="interruptor">
                            <input :id="id" v-model="formSede.coordenada_verificada" type="checkbox" :disabled="!formSede.latitud || !formSede.longitud">
                            <span>
                                Marque solo si alguien confirmó el punto físicamente.
                                Sin latitud y longitud no se puede marcar.
                            </span>
                        </label>
                    </Campo>

                    <template #pie>
                        <div class="acciones-forma">
                            <button type="button" class="boton boton--secundario" @click="cancelar">Cancelar</button>
                            <button type="button" class="boton boton--primario" :disabled="guardando" @click="guardar">
                                {{ guardando ? 'Guardando…' : 'Crear sede' }}
                            </button>
                        </div>
                    </template>
                </SeccionForma>

                <PanelTabla titulo="Sedes" :total="datos.sedes.length" :vacio="!datos.sedes.length">
                    <template #acciones>
                        <button v-if="!editando" type="button" class="boton boton--primario boton--compacto" @click="abrirNuevo">
                            ＋ Nueva sede
                        </button>
                    </template>

                    <table class="tabla-datos">
                        <thead>
                            <tr>
                                <th>Sede</th><th>Cliente</th><th>Centro de costo</th><th>Tipo</th><th>Dirección</th>
                                <th>Zona</th><th>Coordenada</th><th>Ventanas</th><th>Estado</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in datos.sedes" :key="s.id" :class="{ 'fila--inactiva': !s.activo }">
                                <td class="celda-principal">{{ s.nombre }}</td>
                                <td class="celda-tenue">{{ s.cliente }}</td>
                                <!--
                                    Es el código que viaja en la integración. Se enseña en la
                                    tabla para que falte a la vista, no al fallar un envío.
                                -->
                                <td class="num">
                                    <span v-if="s.codigo_centro_costo">{{ s.codigo_centro_costo }}</span>
                                    <span v-else class="celda-tenue">—</span>
                                </td>
                                <td><span class="pastilla pastilla--neutral">{{ s.tipo }}</span></td>
                                <td class="celda-envuelve">{{ s.direccion }}</td>
                                <td class="celda-tenue">{{ s.zona ?? '— sin zona —' }}</td>
                                <td>
                                    <span v-if="s.coordenada_verificada" class="pastilla pastilla--bien">Verificada</span>
                                    <span v-else-if="s.tiene_coordenada" class="pastilla pastilla--atencion">Sin verificar</span>
                                    <span v-else class="pastilla pastilla--critico">Sin coordenada</span>
                                </td>
                                <td class="num">{{ s.ventanas }}</td>
                                <td>
                                    <span class="pastilla" :class="s.activo ? 'pastilla--bien' : 'pastilla--critico'">
                                        {{ s.activo ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="celda-acciones">
                                    <button type="button" class="boton boton--secundario boton--compacto" @click="editar(s)">Editar</button>
                                    <button type="button" class="boton boton--secundario boton--compacto" @click="alternar('sede', s)">
                                        {{ s.activo ? 'Desactivar' : 'Activar' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </PanelTabla>
            </template>

            <!-- ===================================================== ZONAS -->
            <template v-else>
                <SeccionForma
                    v-if="editando"
                    :numero="editando === 'nuevo' ? '+' : '✎'"
                    :titulo="editando === 'nuevo' ? 'Nueva zona' : 'Editar zona'"
                    descripcion="La tarifa se cobra según la zona de la sede de destino. Un paquete refrigerado hace refrigerada toda la orden: no se cobra el promedio de la carga, se cobra lo que la carga obliga a usar."
                >
                    <Campo etiqueta="Nombre de la zona" obligatorio :error="err('nombre')" v-slot="{ id }">
                        <input :id="id" v-model="formZona.nombre" class="control" type="text" placeholder="Zona 5 · Oriente">
                    </Campo>

                    <Campo etiqueta="Ciudad" obligatorio :error="err('ciudad')" v-slot="{ id }">
                        <input :id="id" v-model="formZona.ciudad" class="control" type="text">
                    </Campo>

                    <Campo etiqueta="Tarifa base (COP)" obligatorio :error="err('tarifa_base')" v-slot="{ id }">
                        <input :id="id" v-model="formZona.tarifa_base" class="control control--num" type="number" min="0" placeholder="28400">
                    </Campo>

                    <Campo etiqueta="Tarifa refrigerado (COP)" obligatorio :error="err('tarifa_refrigerado')" v-slot="{ id }">
                        <input :id="id" v-model="formZona.tarifa_refrigerado" class="control control--num" type="number" min="0" placeholder="41200">
                    </Campo>

                    <template #pie>
                        <div class="acciones-forma">
                            <button type="button" class="boton boton--secundario" @click="cancelar">Cancelar</button>
                            <button type="button" class="boton boton--primario" :disabled="guardando" @click="guardar">
                                {{ guardando ? 'Guardando…' : (editando === 'nuevo' ? 'Crear zona' : 'Guardar cambios') }}
                            </button>
                        </div>
                    </template>
                </SeccionForma>

                <PanelTabla
                    titulo="Zonas y tarifas" :total="datos.zonas.length"
                    descripcion="Cambiar una tarifa queda registrado en auditoría con el valor anterior y el nuevo: tiene efecto económico directo sobre lo que se factura."
                    :vacio="!datos.zonas.length"
                >
                    <template #acciones>
                        <button v-if="!editando" type="button" class="boton boton--primario boton--compacto" @click="abrirNuevo">
                            ＋ Nueva zona
                        </button>
                    </template>

                    <table class="tabla-datos">
                        <thead>
                            <tr><th>Zona</th><th>Ciudad</th><th>Tarifa base</th><th>Tarifa refrigerado</th><th>Sedes</th><th>Estado</th><th>Acciones</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="z in datos.zonas" :key="z.id" :class="{ 'fila--inactiva': !z.activo }">
                                <td class="celda-principal">{{ z.nombre }}</td>
                                <td class="celda-tenue">{{ z.ciudad }}</td>
                                <td class="num">{{ moneda(z.tarifa_base) }}</td>
                                <td class="num">{{ moneda(z.tarifa_refrigerado) }}</td>
                                <td class="num">{{ z.sedes }}</td>
                                <td>
                                    <span class="pastilla" :class="z.activo ? 'pastilla--bien' : 'pastilla--critico'">
                                        {{ z.activo ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="celda-acciones">
                                    <button type="button" class="boton boton--secundario boton--compacto" @click="editar(z)">Editar</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </PanelTabla>
            </template>
        </template>
    </div>
</template>

<style scoped>
.pagina {
    max-width: 1320px;
    margin: 0 auto;
    padding: 1.2rem 1rem 3rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 1000px) { .pagina { padding: 1.4rem 1.6rem 3rem; } }

.pestanas {
    display: flex;
    gap: .25rem;
    border-bottom: 1px solid var(--linea);
    overflow-x: auto;
}

.pestana {
    display: flex;
    align-items: center;
    gap: .45rem;
    padding: .6rem .95rem;
    background: none;
    border: none;
    border-bottom: 2.5px solid transparent;
    font-size: .87rem;
    font-weight: 600;
    color: var(--ink-3);
    white-space: nowrap;
    margin-bottom: -1px;
}

.pestana:hover { color: var(--ink); }

.pestana--activa { color: var(--acento); border-bottom-color: var(--acento); }

.pestana-n {
    font-family: var(--mono);
    font-size: .66rem;
    padding: .1rem .4rem;
    border-radius: 999px;
    background: var(--superficie-3);
    color: var(--ink-3);
}

.pestana--activa .pestana-n { background: var(--acento-suave); color: var(--acento-ink); }

.interruptor {
    display: flex;
    gap: .65rem;
    align-items: flex-start;
    font-size: .81rem;
    color: var(--ink-2);
    line-height: 1.5;
    background: var(--superficie-2);
    padding: .7rem .8rem;
    border-radius: 9px;
}

.interruptor input { width: 20px; height: 20px; flex: none; margin-top: .08rem; }

.acciones-forma {
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
    margin-top: 1.1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--linea);
}

.cargando { text-align: center; color: var(--ink-3); padding: 3rem 1rem; font-size: .86rem; }
</style>
