<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../../api/cliente'
import Banner from '../../componentes/Banner.vue'
import Campo from '../../componentes/Campo.vue'
import Hero from '../../componentes/Hero.vue'
import PanelTabla from '../../componentes/PanelTabla.vue'
import SeccionForma from '../../componentes/SeccionForma.vue'
import { decimal } from '../../graficos/util'

/**
 * Flota: vehículos, mensajeros y sondas.
 *
 * El orden de las pestañas es el orden en que hay que crearlos: primero el
 * vehículo, después el mensajero que lo conduce, después la sonda que vigila
 * su cava. Un mensajero sin vehículo no pasa el filtro duro y nunca recibe
 * asignación — por eso el formulario lo advierte en vez de dejarlo pasar.
 */
const flota = ref(null)
const vivo = ref(null)
const zonas = ref([])
const cargando = ref(true)
const error = ref(null)
const guardando = ref(false)
const exito = ref(null)
const secreto = ref(null)
const claveTemporal = ref(null)
const errores = ref({})
const vista = ref('vehiculos')
const editando = ref(null)

const TIPOS_VEHICULO = [
    { valor: 'moto', etiqueta: 'Moto', frio: false, cap: 15 },
    { valor: 'automovil', etiqueta: 'Automóvil', frio: false, cap: 80 },
    { valor: 'camioneta_refrigerada', etiqueta: 'Camioneta refrigerada', frio: true, cap: 600 },
    { valor: 'furgon', etiqueta: 'Furgón', frio: false, cap: 1500 },
]

const formVehiculo = reactive(vacioVehiculo())
const formMensajero = reactive(vacioMensajero())
const formSonda = reactive(vacioSonda())

function vacioVehiculo() {
    return {
        placa: '', tipo: '', capacidad_kg: 600,
        soat_vence: '', tecnomecanica_vence: '', certificado_frio_vence: '',
    }
}

function vacioMensajero() {
    return {
        nombre: '', email: '', documento: '', telefono: '',
        vehiculo_id: null, certificado_controlados: false, certificado_vence: '',
        zonas_habilitadas: [],
    }
}

function vacioSonda() {
    return {
        serial: '', proveedor: '', modelo: 'Tempubi TU-300', alias: '',
        vehiculo_id: null, rango_min: 2, rango_max: 8, intervalo_seg: 300,
    }
}

async function cargar() {
    try {
        const [f, v, c] = await Promise.all([api.adminFlota(), api.telemetriaEnVivo(), api.adminClientes()])
        flota.value = f
        vivo.value = v
        zonas.value = c.zonas
        error.value = null
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

const tipoElegido = computed(() => TIPOS_VEHICULO.find((t) => t.valor === formVehiculo.tipo))

function abrirNuevo() {
    errores.value = {}
    exito.value = null
    secreto.value = null
    claveTemporal.value = null
    editando.value = 'nuevo'

    if (vista.value === 'vehiculos') Object.assign(formVehiculo, vacioVehiculo())
    if (vista.value === 'mensajeros') Object.assign(formMensajero, vacioMensajero())
    if (vista.value === 'sondas') Object.assign(formSonda, vacioSonda())
}

/**
 * Abre el formulario sobre un mensajero que ya existe.
 *
 * El vehiculo se asignaba al crear la cuenta y no habia forma de cambiarlo
 * despues: un mensajero que estrenaba moto se quedaba con la placa anterior, y
 * el motor de asignacion decide con ese dato. Lo que no se puede cambiar aqui
 * es el correo ni el documento —son la identidad de la cuenta, y cambiarlos de
 * paso dentro de «editar flota» es como se pierden los accesos—.
 */
function editarMensajero(m) {
    errores.value = {}
    exito.value = null
    secreto.value = null
    claveTemporal.value = null
    editando.value = m.id

    Object.assign(formMensajero, {
        nombre: m.nombre,
        email: '',
        documento: '',
        telefono: m.telefono ?? '',
        vehiculo_id: m.vehiculo_id ?? null,
        certificado_controlados: !!m.certificado,
        certificado_vence: m.certificado_vence ?? '',
        zonas_habilitadas: [...(m.zonas_habilitadas ?? [])],
        en_turno: !!m.en_turno,
    })
}

function cancelar() {
    editando.value = null
    errores.value = {}
}

/** Al cambiar el tipo se sugiere su capacidad nominal, sin imponerla. */
function alCambiarTipo() {
    formVehiculo.capacidad_kg = tipoElegido.value?.cap ?? formVehiculo.capacidad_kg

    if (!tipoElegido.value?.frio) {
        formVehiculo.certificado_frio_vence = ''
    }
}

async function guardar() {
    guardando.value = true
    errores.value = {}
    exito.value = null
    secreto.value = null
    claveTemporal.value = null

    try {
        if (vista.value === 'vehiculos') {
            const r = await api.crearVehiculo({
                ...formVehiculo,
                capacidad_kg: Number(formVehiculo.capacidad_kg),
                soat_vence: formVehiculo.soat_vence || null,
                tecnomecanica_vence: formVehiculo.tecnomecanica_vence || null,
                certificado_frio_vence: formVehiculo.certificado_frio_vence || null,
            })
            exito.value = `Vehículo ${r.placa} creado · ${r.tipo_etiqueta}`
                + (r.al_dia ? '' : ` — bloqueado: ${r.vencidos.join(', ')}`)
        } else if (vista.value === 'mensajeros') {
            const cuerpo = {
                ...formMensajero,
                certificado_vence: formMensajero.certificado_vence || null,
                zonas_habilitadas: formMensajero.zonas_habilitadas.length ? formMensajero.zonas_habilitadas : null,
            }

            if (editando.value === 'nuevo') {
                const r = await api.crearMensajero(cuerpo)
                exito.value = `Mensajero ${r.mensajero.nombre} creado con código ${r.mensajero.codigo}`
                claveTemporal.value = r
            } else {
                const r = await api.actualizarMensajero(editando.value, cuerpo)
                exito.value = `${r.nombre} actualizado`
                    + (r.vehiculo ? ` · vehículo ${r.vehiculo}` : ' · sin vehículo')
            }
        } else {
            const r = await api.crearDispositivo({
                ...formSonda,
                rango_min: Number(formSonda.rango_min),
                rango_max: Number(formSonda.rango_max),
                intervalo_seg: Number(formSonda.intervalo_seg),
            })
            exito.value = `Sonda ${r.dispositivo.serial} registrada`
            secreto.value = r
        }

        editando.value = null
        await cargar()
    } catch (e) {
        errores.value = e.cuerpo?.errors ?? {}

        if (!Object.keys(errores.value).length) {
            error.value = e.cuerpo?.message ?? e.message
        }
    } finally {
        guardando.value = false
    }
}

async function alternarVehiculo(v) {
    try {
        await api.alternarVehiculo(v.id)
        await cargar()
    } catch (e) {
        error.value = e.cuerpo?.message ?? e.message
    }
}

const err = (c) => errores.value[c]?.[0] ?? ''

const cifras = computed(() => {
    if (!flota.value) return []

    const vencidos = flota.value.vehiculos.filter((v) => !v.al_dia).length
    const sinVehiculo = flota.value.mensajeros.filter((m) => !m.vehiculo).length

    return [
        { etiqueta: 'Vehículos', valor: flota.value.vehiculos.length },
        { etiqueta: 'Bloqueados', valor: vencidos, tono: vencidos ? 'critico' : 'bien' },
        { etiqueta: 'Sin vehículo', valor: sinVehiculo, tono: sinVehiculo ? 'atencion' : 'bien' },
        { etiqueta: 'Sondas', valor: vivo.value?.resumen.total ?? 0 },
    ]
})

const pestanas = computed(() => [
    { k: 'vehiculos', t: 'Vehículos', n: flota.value?.vehiculos.length ?? 0 },
    { k: 'mensajeros', t: 'Mensajeros', n: flota.value?.mensajeros.length ?? 0 },
    { k: 'sondas', t: 'Sondas Tempubi', n: vivo.value?.resumen.total ?? 0 },
])

function cambiarVista(k) {
    vista.value = k
    editando.value = null
    errores.value = {}
}

const fecha = (f) => f ?? '—'
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Administración · flota"
            titulo="Vehículos, mensajeros y sondas"
            descripcion="El orden importa: primero el vehículo, después quien lo conduce, después la sonda que vigila su cava. Un vehículo con documentos vencidos no recibe asignación."
            :cifras="cifras"
        />

        <Banner v-if="exito && !secreto && !claveTemporal" tono="bien" titulo="Guardado" :texto="exito">
            <template #accion>
                <button type="button" class="boton boton--secundario boton--compacto" @click="exito = null">Ocultar</button>
            </template>
        </Banner>

        <!-- Credencial de un solo uso: se muestra una vez y no se vuelve a mostrar -->
        <Banner v-if="claveTemporal" tono="atencion" :titulo="exito">
            <p class="credencial">{{ claveTemporal.clave_temporal }}</p>
            <p class="credencial-nota">{{ claveTemporal.aviso }}</p>
            <template #accion>
                <button type="button" class="boton boton--secundario boton--compacto" @click="claveTemporal = null; exito = null">
                    Ya la copié
                </button>
            </template>
        </Banner>

        <Banner v-if="secreto" tono="atencion" :titulo="exito">
            <p class="credencial">{{ secreto.secreto_ingesta }}</p>
            <p class="credencial-nota">
                {{ secreto.aviso }}<br>
                URL de ingesta: <code>{{ secreto.url_ingesta }}</code>
            </p>
            <template #accion>
                <button type="button" class="boton boton--secundario boton--compacto" @click="secreto = null; exito = null">
                    Ya lo copié
                </button>
            </template>
        </Banner>

        <Banner v-if="error" tono="critico" titulo="No fue posible completar la operación" :texto="error" />

        <p v-if="cargando" class="cargando">Cargando flota…</p>

        <template v-else-if="flota">
            <nav class="pestanas" aria-label="Secciones de flota">
                <button
                    v-for="p in pestanas" :key="p.k" type="button" class="pestana"
                    :class="{ 'pestana--activa': vista === p.k }" :aria-current="vista === p.k"
                    @click="cambiarVista(p.k)"
                >{{ p.t }}<span class="pestana-n">{{ p.n }}</span></button>
            </nav>

            <!-- ================================================ VEHÍCULOS -->
            <template v-if="vista === 'vehiculos'">
                <SeccionForma
                    v-if="editando" numero="+" titulo="Nuevo vehículo"
                    descripcion="La refrigeración la determina el tipo, no una casilla aparte: dos fuentes de verdad para el mismo hecho terminan contradiciéndose, y aquí la contradicción sería un paquete de frío en una moto."
                >
                    <Campo etiqueta="Placa" obligatorio :error="err('placa')" v-slot="{ id }">
                        <input :id="id" v-model="formVehiculo.placa" class="control control--num" type="text"
                               style="text-transform: uppercase" placeholder="WKN482" maxlength="10">
                    </Campo>

                    <Campo etiqueta="Tipo de vehículo" obligatorio :error="err('tipo')" v-slot="{ id }">
                        <select :id="id" v-model="formVehiculo.tipo" class="control" @change="alCambiarTipo">
                            <option value="">Seleccione…</option>
                            <option v-for="t in TIPOS_VEHICULO" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
                        </select>
                    </Campo>

                    <Campo
                        etiqueta="Capacidad (kg)" obligatorio :error="err('capacidad_kg')"
                        ayuda="Se usa en el filtro duro: si el paquete pesa más, este vehículo no aparece."
                        v-slot="{ id }"
                    >
                        <input :id="id" v-model="formVehiculo.capacidad_kg" class="control control--num" type="number" min="1">
                    </Campo>

                    <Campo etiqueta="Vencimiento del SOAT" :error="err('soat_vence')" v-slot="{ id }">
                        <input :id="id" v-model="formVehiculo.soat_vence" class="control" type="date">
                    </Campo>

                    <Campo etiqueta="Vencimiento de tecnomecánica" :error="err('tecnomecanica_vence')" v-slot="{ id }">
                        <input :id="id" v-model="formVehiculo.tecnomecanica_vence" class="control" type="date">
                    </Campo>

                    <Campo
                        v-if="tipoElegido?.frio"
                        etiqueta="Vencimiento del certificado de frío" :error="err('certificado_frio_vence')"
                        ayuda="Sin este certificado vigente, el vehículo no puede tomar carga refrigerada."
                        v-slot="{ id }"
                    >
                        <input :id="id" v-model="formVehiculo.certificado_frio_vence" class="control" type="date">
                    </Campo>

                    <template #pie>
                        <Banner
                            v-if="tipoElegido"
                            :tono="tipoElegido.frio ? 'info' : 'atencion'"
                            :titulo="tipoElegido.frio ? 'Este vehículo podrá transportar cadena de frío' : 'Este vehículo NO podrá transportar cadena de frío'"
                            :texto="tipoElegido.frio
                                ? 'El filtro duro lo incluirá entre los candidatos de paquetes refrigerados, siempre que el certificado esté vigente.'
                                : 'El filtro duro lo descartará automáticamente de cualquier paquete que exija refrigeración.'"
                        />

                        <div class="acciones-forma">
                            <button type="button" class="boton boton--secundario" @click="cancelar">Cancelar</button>
                            <button type="button" class="boton boton--primario" :disabled="guardando" @click="guardar">
                                {{ guardando ? 'Guardando…' : 'Crear vehículo' }}
                            </button>
                        </div>
                    </template>
                </SeccionForma>

                <PanelTabla titulo="Vehículos" :total="flota.vehiculos.length" :vacio="!flota.vehiculos.length">
                    <template #acciones>
                        <button v-if="!editando" type="button" class="boton boton--primario boton--compacto" @click="abrirNuevo">
                            ＋ Nuevo vehículo
                        </button>
                    </template>

                    <table class="tabla-datos">
                        <thead>
                            <tr>
                                <th>Placa</th><th>Tipo</th><th>Frío</th><th>Capacidad</th>
                                <th>SOAT</th><th>Tecnomecánica</th><th>Cert. frío</th>
                                <th>Mensajeros</th><th>Estado</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="v in flota.vehiculos" :key="v.id" :class="{ 'fila--inactiva': !v.activo }">
                                <td class="celda-principal num">{{ v.placa }}</td>
                                <td>{{ v.tipo_etiqueta }}</td>
                                <td>
                                    <span v-if="v.refrigeracion" class="pastilla pastilla--bien">Sí</span>
                                    <span v-else class="celda-tenue">No</span>
                                </td>
                                <td class="num">{{ decimal(v.capacidad_kg, 0) }} kg</td>
                                <td class="num celda-tenue">{{ fecha(v.soat) }}</td>
                                <td class="num celda-tenue">{{ fecha(v.tecnomecanica) }}</td>
                                <td class="num celda-tenue">{{ fecha(v.certificado_frio) }}</td>
                                <td class="num">{{ v.mensajeros }}</td>
                                <td>
                                    <span v-if="v.al_dia" class="pastilla pastilla--bien">Al día</span>
                                    <span v-else class="pastilla pastilla--critico">{{ v.vencidos.join(', ') }}</span>
                                </td>
                                <td class="celda-acciones">
                                    <button type="button" class="boton boton--secundario boton--compacto" @click="alternarVehiculo(v)">
                                        {{ v.activo ? 'Desactivar' : 'Activar' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </PanelTabla>
            </template>

            <!-- =============================================== MENSAJEROS -->
            <template v-else-if="vista === 'mensajeros'">
                <SeccionForma
                    v-if="editando"
                    :numero="editando === 'nuevo' ? '+' : '✎'"
                    :titulo="editando === 'nuevo' ? 'Nuevo mensajero' : `Editar a ${formMensajero.nombre}`"
                    :descripcion="editando === 'nuevo'
                        ? 'Se crea la cuenta de acceso junto con el perfil: un mensajero sin usuario no puede entrar a la aplicación de campo, y separarlo en dos pasos solo produce mensajeros a medio crear.'
                        : 'El correo y el documento no se tocan desde aquí: son la identidad de la cuenta. Lo que sí cambia con el tiempo es el vehículo, la certificación y las zonas.'"
                >
                    <Campo etiqueta="Nombre completo" obligatorio :error="err('nombre')" v-slot="{ id }">
                        <input :id="id" v-model="formMensajero.nombre" class="control" type="text" placeholder="Andrés Quintero">
                    </Campo>

                    <Campo
                        v-if="editando === 'nuevo'"
                        etiqueta="Correo de acceso" obligatorio :error="err('email')"
                        ayuda="Con este correo entrará a la aplicación de campo."
                        v-slot="{ id }"
                    >
                        <input :id="id" v-model="formMensajero.email" class="control" type="email" placeholder="nombre.apellido@ltp.local">
                    </Campo>

                    <Campo v-if="editando === 'nuevo'" etiqueta="Documento" :error="err('documento')" v-slot="{ id }">
                        <input :id="id" v-model="formMensajero.documento" class="control control--num" type="text">
                    </Campo>

                    <Campo etiqueta="Teléfono" :error="err('telefono')" v-slot="{ id }">
                        <input :id="id" v-model="formMensajero.telefono" class="control" type="tel">
                    </Campo>

                    <Campo
                        etiqueta="Vehículo asignado" :error="err('vehiculo_id')"
                        ayuda="Sin vehículo no pasa el filtro duro y nunca recibirá asignación."
                        v-slot="{ id }"
                    >
                        <select :id="id" v-model="formMensajero.vehiculo_id" class="control">
                            <option :value="null">Sin vehículo</option>
                            <option v-for="v in flota.vehiculos.filter((x) => x.activo)" :key="v.id" :value="v.id">
                                {{ v.placa }} — {{ v.tipo_etiqueta }}{{ v.refrigeracion ? ' (frío)' : '' }}
                            </option>
                        </select>
                    </Campo>

                    <Campo
                        v-if="formMensajero.certificado_controlados"
                        etiqueta="Vence la certificación" obligatorio :error="err('certificado_vence')"
                        v-slot="{ id }"
                    >
                        <input :id="id" v-model="formMensajero.certificado_vence" class="control" type="date">
                    </Campo>

                    <Campo etiqueta="¿Certificado en medicamentos de control especial?" ancho v-slot="{ id }">
                        <label class="interruptor">
                            <input :id="id" v-model="formMensajero.certificado_controlados" type="checkbox">
                            <span>
                                Solo los mensajeros con certificación vigente pueden transportar
                                medicamentos de control especial. El filtro duro lo verifica en cada asignación.
                            </span>
                        </label>
                    </Campo>

                    <template #pie>
                        <Banner
                            v-if="!formMensajero.vehiculo_id"
                            tono="atencion"
                            titulo="Quedará sin vehículo"
                            texto="Podrá entrar a la aplicación, pero el motor de asignación lo descartará hasta que se le asigne uno."
                        />

                        <div class="acciones-forma">
                            <button type="button" class="boton boton--secundario" @click="cancelar">Cancelar</button>
                            <button type="button" class="boton boton--primario" :disabled="guardando" @click="guardar">
                                <template v-if="guardando">{{ editando === 'nuevo' ? 'Creando…' : 'Guardando…' }}</template>
                                <template v-else>{{ editando === 'nuevo' ? 'Crear mensajero y su cuenta' : 'Guardar cambios' }}</template>
                            </button>
                        </div>
                    </template>
                </SeccionForma>

                <PanelTabla titulo="Mensajeros" :total="flota.mensajeros.length" :vacio="!flota.mensajeros.length">
                    <template #acciones>
                        <button v-if="!editando" type="button" class="boton boton--primario boton--compacto" @click="abrirNuevo">
                            ＋ Nuevo mensajero
                        </button>
                    </template>

                    <table class="tabla-datos">
                        <thead>
                            <tr>
                                <th>Código</th><th>Nombre</th><th>Vehículo</th><th>Frío</th><th>Controlados</th>
                                <th>Turno</th><th>Señal</th><th>Batería</th><th>Carga</th><th>Activos</th><th>Cumplimiento</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in flota.mensajeros" :key="m.id">
                                <td class="num">{{ m.codigo }}</td>
                                <td class="celda-principal">{{ m.nombre }}</td>
                                <td class="num">
                                    <span v-if="m.vehiculo">{{ m.vehiculo }}</span>
                                    <span v-else class="pastilla pastilla--atencion">Sin vehículo</span>
                                </td>
                                <td>
                                    <span v-if="m.refrigeracion" class="pastilla pastilla--bien">Sí</span>
                                    <span v-else class="celda-tenue">No</span>
                                </td>
                                <td>
                                    <span v-if="m.certificacion_vigente" class="pastilla pastilla--bien">Vigente</span>
                                    <span v-else-if="m.certificado" class="pastilla pastilla--critico">Vencida</span>
                                    <span v-else class="celda-tenue">No</span>
                                </td>
                                <td>
                                    <span class="pastilla" :class="m.en_turno ? 'pastilla--bien' : 'pastilla--neutral'">
                                        {{ m.en_turno ? 'En turno' : 'Fuera' }}
                                    </span>
                                </td>
                                <td>
                                    <span v-if="m.sin_senal" class="pastilla pastilla--atencion">Sin señal</span>
                                    <span v-else class="celda-tenue">OK</span>
                                </td>
                                <td class="num" :class="{ alerta: m.bateria !== null && m.bateria < 20 }">{{ m.bateria ?? '—' }}%</td>
                                <td class="num">{{ decimal(m.carga_kg, 1) }} kg</td>
                                <td class="num">{{ m.activos }}</td>
                                <td class="num">{{ decimal(m.cumplimiento, 1) }}%</td>
                                <td class="celda-acciones">
                                    <button type="button" class="boton boton--secundario boton--compacto" @click="editarMensajero(m)">
                                        Editar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </PanelTabla>
            </template>

            <!-- ==================================================== SONDAS -->
            <template v-else>
                <SeccionForma
                    v-if="editando" numero="+" titulo="Registrar sonda Tempubi"
                    descripcion="La sonda mide el compartimento del vehículo, no el paquete. Los envíos a bordo heredan sus lecturas mientras estén bajo custodia de ese vehículo."
                >
                    <Campo etiqueta="Serial del dispositivo" obligatorio :error="err('serial')" v-slot="{ id }">
                        <input :id="id" v-model="formSonda.serial" class="control control--num" type="text" placeholder="TPB-XKD-2001">
                    </Campo>

                    <Campo etiqueta="Proveedor" obligatorio :error="err('proveedor')" v-slot="{ id }">
                        <select :id="id" v-model="formSonda.proveedor" class="control">
                            <option value="">Seleccione…</option>
                            <option value="tempubi">Tempubi</option>
                        </select>
                    </Campo>

                    <Campo etiqueta="Modelo" :error="err('modelo')" v-slot="{ id }">
                        <input :id="id" v-model="formSonda.modelo" class="control" type="text">
                    </Campo>

                    <Campo etiqueta="Alias" ayuda="Cómo la llama la operación: «Cava camioneta 1»." v-slot="{ id }">
                        <input :id="id" v-model="formSonda.alias" class="control" type="text">
                    </Campo>

                    <Campo
                        etiqueta="Vehículo donde se instala" :error="err('vehiculo_id')"
                        ayuda="Solo tiene sentido en vehículos refrigerados."
                        v-slot="{ id }"
                    >
                        <select :id="id" v-model="formSonda.vehiculo_id" class="control">
                            <option :value="null">Sin instalar</option>
                            <option v-for="v in flota.vehiculos.filter((x) => x.refrigeracion && x.activo)" :key="v.id" :value="v.id">
                                {{ v.placa }} — {{ v.tipo_etiqueta }}
                            </option>
                        </select>
                    </Campo>

                    <Campo etiqueta="Intervalo de reporte (segundos)" obligatorio :error="err('intervalo_seg')" v-slot="{ id }">
                        <input :id="id" v-model="formSonda.intervalo_seg" class="control control--num" type="number" min="10" max="3600">
                    </Campo>

                    <Campo etiqueta="Temperatura mínima vigilada (°C)" obligatorio :error="err('rango_min')" v-slot="{ id }">
                        <input :id="id" v-model="formSonda.rango_min" class="control control--num" type="number" step="0.5">
                    </Campo>

                    <Campo etiqueta="Temperatura máxima vigilada (°C)" obligatorio :error="err('rango_max')" v-slot="{ id }">
                        <input :id="id" v-model="formSonda.rango_max" class="control control--num" type="number" step="0.5">
                    </Campo>

                    <template #pie>
                        <Banner
                            tono="info"
                            titulo="Al guardar se genera el secreto de ingesta"
                            texto="Se muestra una sola vez. Configúrelo en la plataforma de Tempubi como firma HMAC-SHA256 del cuerpo, en la cabecera X-Firma. Un secreto consultable a voluntad es un secreto de todos."
                        />

                        <div class="acciones-forma">
                            <button type="button" class="boton boton--secundario" @click="cancelar">Cancelar</button>
                            <button type="button" class="boton boton--primario" :disabled="guardando" @click="guardar">
                                {{ guardando ? 'Registrando…' : 'Registrar sonda' }}
                            </button>
                        </div>
                    </template>
                </SeccionForma>

                <PanelTabla
                    titulo="Sondas Tempubi" :total="vivo?.resumen.total ?? 0"
                    descripcion="Una sonda callada es tan grave como una fuera de rango: en ambos casos se perdió la vigilancia."
                    :vacio="!vivo?.dispositivos.length"
                    texto-vacio="No hay sondas registradas. Regístrelas para vigilar la cadena de frío en vivo."
                >
                    <template #acciones>
                        <button v-if="!editando" type="button" class="boton boton--primario boton--compacto" @click="abrirNuevo">
                            ＋ Registrar sonda
                        </button>
                    </template>

                    <table class="tabla-datos">
                        <thead>
                            <tr>
                                <th>Serial</th><th>Alias</th><th>Proveedor</th><th>Vehículo</th>
                                <th>Temperatura</th><th>Rango</th><th>Batería</th><th>Última lectura</th><th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in vivo?.dispositivos ?? []" :key="d.id">
                                <td class="celda-principal num">{{ d.serial }}</td>
                                <td>{{ d.alias }}</td>
                                <td><span class="pastilla pastilla--neutral">{{ d.proveedor }}</span></td>
                                <td class="num">{{ d.vehiculo ?? '— sin instalar —' }}</td>
                                <td class="num" :class="{ alerta: !d.en_rango }">{{ decimal(d.temperatura, 1) }} °C</td>
                                <td class="num celda-tenue">{{ d.rango.min }}–{{ d.rango.max }} °C</td>
                                <td class="num">{{ d.bateria }}%</td>
                                <td class="num celda-tenue">
                                    {{ d.ultima_lectura ? new Date(d.ultima_lectura).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—' }}
                                </td>
                                <td>
                                    <span v-if="d.silencioso" class="pastilla pastilla--atencion">Sin reportar</span>
                                    <span v-else-if="!d.en_rango" class="pastilla pastilla--critico">Fuera de rango</span>
                                    <span v-else class="pastilla pastilla--bien">Reportando</span>
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

.pestanas { display: flex; gap: .25rem; border-bottom: 1px solid var(--linea); overflow-x: auto; }

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

.credencial {
    font-family: var(--mono);
    font-size: 1.15rem;
    letter-spacing: .04em;
    background: var(--superficie);
    border: 1px dashed var(--atencion);
    border-radius: 9px;
    padding: .65rem .85rem;
    margin: .6rem 0 .4rem;
    user-select: all;
    word-break: break-all;
}

.credencial-nota { font-size: .78rem; line-height: 1.55; margin: 0; color: var(--ink-2); }
.credencial-nota code { font-family: var(--mono); font-size: .74rem; }

.alerta { color: var(--critico); font-weight: 600; }
.cargando { text-align: center; color: var(--ink-3); padding: 3rem 1rem; font-size: .86rem; }
</style>
