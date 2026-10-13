<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { comprimirImagen, crearFirmador, pesoLegible } from '../campo/captura'
import { api } from '../api/cliente'
import EscanerQr from '../componentes/EscanerQr.vue'
import { avanzarTareaLocal, encolar, encolarEvidencia, guardarTareas, tareasLocales, uuid7 } from '../offline/almacen'
import { refrescarContador, sincronizar } from '../offline/sincronizador'

/**
 * Prueba de entrega.
 *
 * Regla que gobierna la pantalla: SIN EVIDENCIA NO HAY ENTREGA. Foto del
 * receptor y firma son obligatorias en los tres tipos de paquete, y el botón de
 * confirmar no se habilita hasta tenerlas. Un envío sin soporte es justamente
 * el que no se puede defender cuando el cliente reclama que nunca llegó.
 *
 * Todo se captura y se guarda en el teléfono; la subida ocurre después. El
 * mensajero nunca espera a la red para cerrar una entrega.
 */
const props = defineProps({ paqueteId: { type: [String, Number], required: true } })

const router = useRouter()

const tarea = ref(null)
const paso = ref(0)
const guardando = ref(false)
const listo = ref(false)
const aviso = ref(null)
const revelarCodigo = ref(false)

/* Por que no hay coordenada, cuando no la hay. Vacio mientras se busca. */
const gps = ref({ motivo: null })

/**
 * Lo que devuelve el escáner entra como si se hubiera escrito.
 *
 * No se salta la validación: el código leído se compara con el del paquete
 * igual que uno tecleado. Escanear es más cómodo y menos propenso a erratas,
 * pero no es una credencial — la caja equivocada sigue siendo la caja
 * equivocada aunque su QR se lea perfecto.
 *
 * Si coincide, se avanza solo: el mensajero tiene las manos ocupadas y un
 * toque de más en la calle es un toque de más.
 *
 * Y si NO coincide hay que decirlo fuerte. Antes solo aparecía una línea roja
 * bajo el campo, que en la calle —teléfono en una mano, caja en la otra, sol
 * en la pantalla— se pasa por alto. Leer la caja equivocada es justo el error
 * que este paso existe para atrapar, así que se avisa con un rótulo flotante
 * y una vibración: se nota sin mirar.
 */
function alEscanear(codigo) {
    form.value.codigo = codigo

    if (codigoCoincide.value) {
        avisar('Código correcto', 'bien')
        siguiente()

        return
    }

    avisar(`Ese QR no corresponde a este paquete. Se esperaba ${tarea.value?.codigo ?? 'otro código'}.`, 'mal')
}

/**
 * Rótulo flotante breve.
 *
 * Deliberadamente no bloquea: el mensajero no tiene que cerrarlo para seguir
 * escaneando. La vibración distingue el acierto del error sin leer —un pulso
 * corto frente a dos largos— y se omite sola donde no exista.
 */
const toast = ref(null)
let relojToast = null

function avisar(texto, tono = 'mal') {
    toast.value = { texto, tono }

    if (navigator.vibrate) {
        navigator.vibrate(tono === 'bien' ? 60 : [120, 70, 120])
    }

    clearTimeout(relojToast)
    relojToast = setTimeout(() => { toast.value = null }, tono === 'bien' ? 1800 : 5000)
}

const form = ref({
    codigo: '',
    temperatura: null,
    receptor_nombre: '',
    receptor_documento: '',
    receptor_cargo: '',
    consentimiento: false,
})

const posicion = ref(null)

/** Evidencias capturadas en esta sesión, antes de encolarse. */
const evidencias = ref({ foto_receptor: null, foto_indicador: null, foto_empaque: null, firma: null })

const lienzoFirma = ref(null)
let firmador = null

const cargandoTarea = ref(true)

onMounted(async () => {
    await resolverTarea()

    /*
     * La coordenada se pide al abrir, no al confirmar: obtener una posición
     * precisa toma segundos, y pedirla al final haría esperar al mensajero
     * justo cuando ya terminó.
     *
     * Y si no se puede, se dice POR QUÉ. La pantalla se quedaba en «Buscando
     * ubicación…» indefinidamente cuando el navegador no iba a preguntar
     * siquiera —sobre HTTP no lo hace—, y eso manda a revisar el GPS del
     * teléfono, la cobertura, los permisos: todo menos lo que era.
     */
    if (!window.isSecureContext) {
        gps.value.motivo = 'Ubicación no disponible: el navegador la exige sobre HTTPS'
    } else if (!navigator.geolocation) {
        gps.value.motivo = 'Este navegador no ofrece ubicación'
    } else {
        navigator.geolocation.getCurrentPosition(
            (p) => {
                posicion.value = {
                    latitud: p.coords.latitude,
                    longitud: p.coords.longitude,
                    precision_m: Math.round(p.coords.accuracy),
                }
            },
            (e) => {
                posicion.value = null
                gps.value.motivo = e.code === e.PERMISSION_DENIED
                    ? 'Ubicación denegada: permítala en los ajustes del navegador'
                    : 'No se pudo obtener la ubicación (sin señal o GPS apagado)'
            },
            { enableHighAccuracy: true, timeout: 12_000, maximumAge: 30_000 }
        )
    }
})

/**
 * Busca la tarea: primero en el teléfono, después en el servidor.
 *
 * Mirar solo el almacenamiento local dejaba la pantalla en «no se encontró»
 * ante algo tan común como recargar la página o abrir el enlace directo — el
 * almacén solo se llena al pasar por la lista. Local primero sigue siendo lo
 * correcto para trabajar sin señal; rendirse cuando sí hay red, no.
 */
async function resolverTarea() {
    cargandoTarea.value = true

    const locales = await tareasLocales()
    tarea.value = locales.find((t) => String(t.paquete_id) === String(props.paqueteId)) ?? null

    if (tarea.value === null && navigator.onLine) {
        try {
            const r = await api.tareas()
            await guardarTareas(r.tareas)
            tarea.value = r.tareas.find((t) => String(t.paquete_id) === String(props.paqueteId)) ?? null
        } catch {
            // Sin red no es un error que mostrar como falla: es el modo de
            // operación previsto. El estado vacío de abajo lo explica.
        }
    }

    cargandoTarea.value = false
}


onBeforeUnmount(() => {
    firmador?.destruir()
    clearTimeout(relojToast)
})

const esRecoleccion = computed(() => tarea.value?.accion === 'recoger')
const controles = computed(() => tarea.value?.controles ?? [])

const pasos = computed(() => {
    const lista = [{ clave: 'escaneo', titulo: 'Escanear el paquete' }]

    if (controles.value.includes('temperatura')) {
        lista.push({ clave: 'temperatura', titulo: 'Registrar temperatura' })
    }

    if (esRecoleccion.value) {
        if (controles.value.includes('foto_indicador')) {
            lista.push({ clave: 'foto_indicador', titulo: 'Foto del indicador' })
        }
    } else {
        lista.push({ clave: 'receptor', titulo: 'Datos de quien recibe' })
        lista.push({ clave: 'foto_receptor', titulo: 'Foto del receptor' })
        lista.push({ clave: 'firma', titulo: 'Firma del receptor' })
    }

    return lista
})

const pasoActual = computed(() => pasos.value[paso.value] ?? null)

// El lienzo solo existe cuando su paso está en pantalla, así que el firmador se
// engancha al aparecer y se suelta al salir.
watch(pasoActual, async (p) => {
    if (p?.clave !== 'firma') {
        firmador?.destruir()
        firmador = null

        return
    }

    await new Promise((r) => setTimeout(r, 60))

    if (lienzoFirma.value) {
        firmador = crearFirmador(lienzoFirma.value)
    }
})

const codigoCoincide = computed(() =>
    form.value.codigo.trim().toUpperCase() === (tarea.value?.codigo ?? '').toUpperCase())

const temperaturaEnRango = computed(() => {
    const r = tarea.value?.rango_termico
    const v = Number(form.value.temperatura)

    if (!r || form.value.temperatura === null || form.value.temperatura === '' || Number.isNaN(v)) return null

    return v >= r.min && v <= r.max
})

const receptorCompleto = computed(() =>
    form.value.receptor_nombre.trim() !== ''
    && form.value.receptor_documento.trim() !== ''
    && form.value.consentimiento)

/** Sin foto y sin firma no se puede confirmar. No es negociable. */
const evidenciaCompleta = computed(() =>
    evidencias.value.foto_receptor !== null && evidencias.value.firma !== null)

async function tomarFoto(evento, tipo) {
    const archivo = evento.target.files?.[0]
    if (!archivo) return

    aviso.value = null

    try {
        const { blob, bytesOriginal } = await comprimirImagen(archivo)

        fijarEvidencia(tipo, {
            blob,
            url: URL.createObjectURL(blob),
            bytes: blob.size,
            bytesOriginal,
            capturada_en: new Date().toISOString(),
        })
    } catch (e) {
        aviso.value = 'No se pudo procesar la imagen. Intente tomarla de nuevo.'
    } finally {
        evento.target.value = ''
    }
}

function fijarEvidencia(tipo, valor) {
    if (evidencias.value[tipo]?.url) URL.revokeObjectURL(evidencias.value[tipo].url)
    evidencias.value[tipo] = valor
}

function repetirFoto(tipo) {
    fijarEvidencia(tipo, null)
}

async function guardarFirma() {
    const blob = await firmador?.aBlob()

    if (!blob) {
        aviso.value = 'La firma está vacía. Pida al receptor que firme en el recuadro.'

        return
    }

    fijarEvidencia('firma', {
        blob,
        url: URL.createObjectURL(blob),
        bytes: blob.size,
        capturada_en: new Date().toISOString(),
    })

    aviso.value = null
}

function limpiarFirma() {
    firmador?.limpiar()
    fijarEvidencia('firma', null)
}

/**
 * Avanza al paso siguiente, o cierra si ya no hay siguiente.
 *
 * El remate importa. Una recolección sin cadena de frío ni foto de indicador
 * tiene un único paso, el escaneo, y antes este botón no hacía nada: com-
 * probaba si existía un paso posterior, no lo había, y se quedaba quieto. El
 * mensajero leía el QR, veía «Código correcto» y no podía cerrar la recogida.
 *
 * Lo mismo ocurría cuando el último paso era la temperatura.
 */
function siguiente() {
    if (paso.value < pasos.value.length - 1) {
        paso.value++

        return
    }

    confirmar()
}

async function confirmar() {
    if (!esRecoleccion.value && !evidenciaCompleta.value) {
        aviso.value = 'Falta capturar la evidencia. Sin foto y firma no se puede cerrar la entrega.'

        return
    }

    guardando.value = true

    // 1. Las evidencias se encolan primero y sus identificadores viajan en el
    //    evento: el servidor verifica que existan antes de aceptar la entrega,
    //    y el sincronizador se encarga de subirlas en ese orden.
    const ids = {}

    for (const [tipo, ev] of Object.entries(evidencias.value)) {
        if (!ev) continue
        ids[tipo] = await encolarEvidencia(props.paqueteId, tipo, ev.blob, ev.capturada_en)
    }

    const carga = {
        evento_id: uuid7(),
        codigo: form.value.codigo.trim().toUpperCase(),
        ocurrido_en: new Date().toISOString(),
        ...posicion.value,
    }

    if (form.value.temperatura !== null && form.value.temperatura !== '') {
        carga.temperatura = Number(form.value.temperatura)
    }

    if (!esRecoleccion.value) {
        Object.assign(carga, {
            receptor_nombre: form.value.receptor_nombre.trim(),
            receptor_documento: form.value.receptor_documento.trim(),
            receptor_cargo: form.value.receptor_cargo.trim() || null,
            consentimiento: true,
            firma_id: ids.firma ?? null,
            foto_receptor_id: ids.foto_receptor ?? null,
        })
    }

    await encolar(esRecoleccion.value ? 'recolectar' : 'entregar', props.paqueteId, carga)

    // El estado cambia AQUÍ, no cuando conteste el servidor. Lo que acaba de
    // pasar —la caja cambió de manos— ya ocurrió; lo que falta es contarlo.
    // Dejar la tarjeta como estaba invitaba a repetir la misma entrega.
    await avanzarTareaLocal(props.paqueteId, esRecoleccion.value ? 'recolectado' : 'entregado')
    await refrescarContador()

    sincronizar()

    guardando.value = false
    listo.value = true
}

const totalEvidencia = computed(() =>
    Object.values(evidencias.value).filter(Boolean).reduce((s, e) => s + e.bytes, 0))
</script>

<template>
    <div class="entrega">
        <!-- ------------------------------------------------------- cierre -->
        <template v-if="listo">
            <div class="exito">
                <p class="marca" aria-hidden="true">✓</p>
                <h1 class="titulo">
                    {{ esRecoleccion ? 'Paquete bajo su custodia' : 'Entrega registrada' }}
                </h1>
                <p class="sub">
                    Quedó guardado en este teléfono con fecha, hora, ubicación
                    <template v-if="!esRecoleccion">y {{ Object.values(evidencias).filter(Boolean).length }} evidencia(s)</template>.
                    Se enviará solo cuando haya señal.
                </p>
                <button type="button" class="btn btn--pri btn--ancho" @click="router.push('/campo')">
                    Volver a mis tareas
                </button>
            </div>
        </template>

        <template v-else-if="cargandoTarea">
            <p class="vacio">Buscando la tarea…</p>
        </template>

        <template v-else-if="!tarea">
            <div class="vacio">
                <p>
                    Esta tarea no está entre las suyas. Puede que ya la haya cerrado,
                    que se la hayan reasignado, o que esté abriendo un enlace viejo.
                </p>
                <button type="button" class="btn btn--pri btn--ancho" @click="router.push('/campo')">
                    Ver mis tareas
                </button>
            </div>
        </template>

        <template v-else>
            <header class="cab">
                <h1 class="titulo">{{ esRecoleccion ? 'Recolección' : 'Prueba de entrega' }}</h1>
                <p class="sub num">{{ tarea.guia }} · {{ tarea.sede?.nombre }}</p>
            </header>

            <ol class="progreso" :aria-label="`Paso ${paso + 1} de ${pasos.length}`">
                <li v-for="(p, i) in pasos" :key="p.clave" :class="{ hecho: i < paso, activo: i === paso }" />
            </ol>

            <p class="paso-titulo">{{ paso + 1 }}. {{ pasoActual?.titulo }}</p>

            <p v-if="aviso" class="alerta" role="alert">{{ aviso }}</p>

            <!-- ------------------------------------------------- escaneo -->
            <section v-if="pasoActual?.clave === 'escaneo'" class="bloque">
                <p class="ayuda">
                    Escanee o escriba el código de la etiqueta.
                    Sin un código válido no se puede continuar.
                </p>
                <!--
                    El escáner primero: es el camino previsto, y escribir a mano
                    es el recurso de cuando no se puede. Si la cámara no está
                    disponible, el componente dice por qué en vez de callarse.
                -->
                <EscanerQr @leido="alEscanear" />

                <input
                    v-model="form.codigo" class="campo campo--grande" type="text"
                    inputmode="text" autocapitalize="characters" spellcheck="false"
                    placeholder="Código de la etiqueta"
                >
                <p v-if="form.codigo && !codigoCoincide" class="error">✕ Ese código no corresponde a este paquete.</p>
                <p v-else-if="codigoCoincide" class="ok">✓ Código correcto</p>

                <!--
                    Ayuda de prueba, no parte del flujo real.

                    Mostrar el código esperado vuelve inútil el control: quien lo lee
                    en pantalla ya no necesita tener el paquete en la mano, que es
                    justo lo que el escaneo verifica. Por eso va detrás de un toque
                    explícito y rotulado como lo que es.

                    El rótulo decía «No hay lector conectado», y casi siempre era
                    falso: el lector está en el bolsillo del mensajero. Lo que
                    faltaba era HTTPS. Decirlo mal mandaba a buscar un aparato que
                    no hacía falta.
                -->
                <button type="button" class="ayuda-prueba" @click="revelarCodigo = !revelarCodigo">
                    {{ revelarCodigo ? 'Ocultar' : 'Ver el código (solo para pruebas)' }}
                </button>
                <p v-if="revelarCodigo" class="codigo-revelado num">
                    {{ tarea.codigo }}
                    <button type="button" class="copiar" @click="form.codigo = tarea.codigo">Usar</button>
                </p>

                <button type="button" class="btn btn--pri btn--ancho" :disabled="!codigoCoincide" @click="siguiente">
                    Continuar
                </button>
            </section>

            <!-- --------------------------------------------- temperatura -->
            <section v-else-if="pasoActual?.clave === 'temperatura'" class="bloque">
                <p class="ayuda">
                    Lea el indicador o el datalogger y registre el valor.
                    Rango exigido {{ tarea.rango_termico?.min }} – {{ tarea.rango_termico?.max }} °C.
                </p>
                <input
                    v-model="form.temperatura" class="campo campo--grande campo--num"
                    type="number" inputmode="decimal" step="0.1" placeholder="0,0"
                >
                <p v-if="temperaturaEnRango === true" class="ok">✓ Dentro del rango</p>
                <p v-else-if="temperaturaEnRango === false" class="error">
                    ✕ Fuera de rango. Al confirmar se abrirá una novedad por excursión térmica
                    y se avisará al operador y al cliente.
                </p>

                <button
                    type="button" class="btn btn--pri btn--ancho"
                    :disabled="form.temperatura === null || form.temperatura === ''"
                    @click="siguiente"
                >Continuar</button>
            </section>

            <!-- ------------------------------------- foto del indicador -->
            <section v-else-if="pasoActual?.clave === 'foto_indicador'" class="bloque">
                <p class="ayuda">Fotografíe el indicador de temperatura antes de cerrar el paquete.</p>

                <div v-if="evidencias.foto_indicador" class="previa">
                    <img :src="evidencias.foto_indicador.url" alt="Fotografía del indicador de temperatura">
                    <p class="previa-peso num">
                        {{ pesoLegible(evidencias.foto_indicador.bytes) }}
                        <span class="tenue">(original {{ pesoLegible(evidencias.foto_indicador.bytesOriginal) }})</span>
                    </p>
                    <button type="button" class="btn btn--sec btn--ancho" @click="repetirFoto('foto_indicador')">
                        Repetir la foto
                    </button>
                </div>

                <label v-else class="captura">
                    <input type="file" accept="image/*" capture="environment" @change="tomarFoto($event, 'foto_indicador')">
                    <span class="captura-icono" aria-hidden="true">📷</span>
                    <span class="captura-txt">Tomar foto del indicador</span>
                </label>

                <button
                    type="button" class="btn btn--pri btn--ancho"
                    :disabled="!evidencias.foto_indicador || guardando" @click="confirmar"
                >
                    {{ guardando ? 'Registrando…' : 'Confirmar recolección' }}
                </button>
            </section>

            <!-- ------------------------------------------------- receptor -->
            <section v-else-if="pasoActual?.clave === 'receptor'" class="bloque">
                <label class="etiqueta" for="rn">Nombre de quien recibe</label>
                <input id="rn" v-model="form.receptor_nombre" class="campo" type="text" autocomplete="off">

                <label class="etiqueta" for="rd">Documento de identidad</label>
                <input id="rd" v-model="form.receptor_documento" class="campo campo--num" type="text" inputmode="numeric">

                <label class="etiqueta" for="rc">Cargo o dependencia</label>
                <input id="rc" v-model="form.receptor_cargo" class="campo" type="text" autocomplete="off">

                <label class="consentimiento">
                    <input v-model="form.consentimiento" type="checkbox">
                    <span>
                        El receptor autoriza el uso de su nombre, documento e imagen
                        únicamente como constancia de esta entrega.
                    </span>
                </label>

                <button type="button" class="btn btn--pri btn--ancho" :disabled="!receptorCompleto" @click="siguiente">
                    Continuar
                </button>
            </section>

            <!-- -------------------------------------- foto del receptor -->
            <section v-else-if="pasoActual?.clave === 'foto_receptor'" class="bloque">
                <p class="ayuda">
                    Fotografíe a quien recibe con el paquete. Es obligatorio:
                    sin esta foto no se puede cerrar la entrega.
                </p>

                <div v-if="evidencias.foto_receptor" class="previa">
                    <img :src="evidencias.foto_receptor.url" alt="Fotografía del receptor con el paquete">
                    <p class="previa-peso num">
                        {{ pesoLegible(evidencias.foto_receptor.bytes) }}
                        <span class="tenue">(original {{ pesoLegible(evidencias.foto_receptor.bytesOriginal) }})</span>
                    </p>
                    <button type="button" class="btn btn--sec btn--ancho" @click="repetirFoto('foto_receptor')">
                        Repetir la foto
                    </button>
                </div>

                <label v-else class="captura">
                    <input type="file" accept="image/*" capture="environment" @change="tomarFoto($event, 'foto_receptor')">
                    <span class="captura-icono" aria-hidden="true">📷</span>
                    <span class="captura-txt">Tomar foto del receptor</span>
                    <span class="captura-nota">Se abre la cámara del teléfono</span>
                </label>

                <button
                    type="button" class="btn btn--pri btn--ancho"
                    :disabled="!evidencias.foto_receptor" @click="siguiente"
                >Continuar</button>
            </section>

            <!-- ---------------------------------------------------- firma -->
            <section v-else-if="pasoActual?.clave === 'firma'" class="bloque">
                <p class="ayuda">Pida al receptor que firme dentro del recuadro con el dedo.</p>

                <div v-if="evidencias.firma" class="previa">
                    <img :src="evidencias.firma.url" alt="Firma capturada del receptor" class="previa-firma">
                    <p class="previa-peso num">Firma capturada · {{ pesoLegible(evidencias.firma.bytes) }}</p>
                    <button type="button" class="btn btn--sec btn--ancho" @click="limpiarFirma">
                        Firmar de nuevo
                    </button>
                </div>

                <template v-else>
                    <canvas ref="lienzoFirma" class="lienzo" aria-label="Área de firma del receptor" />
                    <div class="firma-acciones">
                        <button type="button" class="btn btn--sec" @click="limpiarFirma">Borrar</button>
                        <button type="button" class="btn btn--sec" @click="guardarFirma">Usar esta firma</button>
                    </div>
                </template>

                <!-- Resumen de evidencia: lo que se va a guardar, antes de guardarlo -->
                <div class="resumen">
                    <p class="resumen-tit">Evidencia de esta entrega</p>
                    <p class="resumen-fila">
                        <span :class="evidencias.foto_receptor ? 'si' : 'no'">
                            {{ evidencias.foto_receptor ? '✓' : '✕' }}
                        </span>
                        Foto del receptor
                    </p>
                    <p class="resumen-fila">
                        <span :class="evidencias.firma ? 'si' : 'no'">{{ evidencias.firma ? '✓' : '✕' }}</span>
                        Firma
                    </p>
                    <p v-if="totalEvidencia" class="resumen-peso num">
                        {{ pesoLegible(totalEvidencia) }} por enviar
                    </p>
                </div>

                <button
                    type="button" class="btn btn--pri btn--ancho"
                    :disabled="!evidenciaCompleta || guardando" @click="confirmar"
                >
                    {{ guardando ? 'Registrando…' : 'Confirmar entrega' }}
                </button>

                <p v-if="!evidenciaCompleta" class="bloqueo">
                    Sin evidencia no se puede cerrar la entrega.
                </p>

                <button type="button" class="btn btn--novedad btn--ancho" @click="router.push('/campo')">
                    No se pudo entregar · registrar novedad
                </button>
            </section>

            <p v-if="posicion" class="gps num">
                📍 {{ posicion.latitud.toFixed(5) }}, {{ posicion.longitud.toFixed(5) }}
                · precisión {{ posicion.precision_m }} m
            </p>
            <!--
                «Buscando ubicación…» para siempre era una espera que no iba a
                terminar: sobre HTTP el navegador ni siquiera pregunta. Decirlo
                deja claro que no es el teléfono ni la cobertura.
            -->
            <p v-else-if="gps.motivo" class="gps atenuado">📍 {{ gps.motivo }}</p>
            <p v-else class="gps atenuado">📍 Buscando ubicación…</p>
        </template>

        <!--
            Va al final del marcado y fijo a la pantalla: aparece sobre lo que
            haya, sin empujar el contenido ni mover el botón que el mensajero
            está a punto de pulsar.
        -->
        <Transition name="toast">
            <p v-if="toast" class="toast" :class="`toast--${toast.tono}`" role="alert" aria-live="assertive">
                {{ toast.tono === 'bien' ? '✓' : '✕' }} {{ toast.texto }}
            </p>
        </Transition>
    </div>
</template>

<style scoped>
.entrega { max-width: 560px; margin: 0 auto; padding: 1rem .8rem 2.5rem; }

/* ------------------------------------------------------------------ toast */
.toast {
    position: fixed;
    left: 50%;
    bottom: 1.6rem;
    transform: translateX(-50%);
    z-index: 60;
    width: min(92vw, 520px);
    margin: 0;
    padding: .85rem 1.1rem;
    border-radius: var(--r-md);
    font-size: .92rem;
    font-weight: 600;
    line-height: 1.45;
    text-align: center;
    color: #fff;
    box-shadow: 0 10px 30px rgb(0 0 0 / .28);
}

.toast--mal  { background: #b3261e; }
.toast--bien { background: #1b7a46; }

.toast-enter-active, .toast-leave-active { transition: opacity .18s ease, transform .18s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 12px); }

@media (prefers-reduced-motion: reduce) {
    .toast-enter-active, .toast-leave-active { transition: none; }
}

.cab { margin-bottom: .9rem; }
.titulo { font-size: 1.3rem; font-weight: 700; letter-spacing: -.03em; margin: 0; }
.sub { font-size: .8rem; color: var(--ink-3); margin: .2rem 0 0; line-height: 1.5; }

.progreso { display: flex; gap: .25rem; list-style: none; margin: 0 0 1rem; padding: 0; }
.progreso li { flex: 1; height: 5px; border-radius: 3px; background: var(--superficie-3); }
.progreso li.hecho, .progreso li.activo { background: var(--acento); }
.progreso li.activo { opacity: .7; }

.paso-titulo { font-size: 1rem; font-weight: 600; margin: 0 0 .7rem; }

.bloque { display: flex; flex-direction: column; gap: .6rem; }

.ayuda { font-size: .86rem; color: var(--ink-2); margin: 0; line-height: 1.5; }

.etiqueta {
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin-top: .3rem;
}

.campo {
    min-height: var(--toque);
    border: 1px solid var(--linea-fuerte);
    border-radius: 10px;
    background: var(--superficie);
    padding: .7rem .8rem;
    font-size: 1rem;
    width: 100%;
    color: var(--ink);
}

.campo--grande { font-size: 1.35rem; text-align: center; letter-spacing: .04em; }
.campo--num { font-family: var(--mono); font-variant-numeric: tabular-nums; }

.ayuda-prueba{background:none;border:1px dashed var(--linea-fuerte);border-radius:9px;padding:.5rem .7rem;font-size:.76rem;color:var(--ink-3);width:100%}
.ayuda-prueba:hover{border-color:var(--acento);color:var(--acento)}
.codigo-revelado{display:flex;align-items:center;justify-content:space-between;gap:.6rem;background:var(--atencion-suave);color:var(--atencion);border-radius:9px;padding:.6rem .75rem;font-size:1rem;font-weight:600;margin:0}
.copiar{border:1px solid currentColor;background:transparent;color:inherit;border-radius:7px;padding:.25rem .7rem;font-size:.76rem;font-weight:600}

.ok { color: var(--bien); font-size: .85rem; font-weight: 600; margin: 0; }
.error { color: var(--critico); font-size: .85rem; font-weight: 600; margin: 0; line-height: 1.4; }

.alerta {
    background: var(--critico-suave);
    color: var(--critico);
    border: 1px solid var(--critico);
    border-radius: 9px;
    padding: .6rem .75rem;
    font-size: .84rem;
    margin: 0;
    line-height: 1.45;
}

/* -------- captura de foto -------------------------------------------------
   El objetivo táctil ocupa toda la tarjeta, no solo un botón: se usa con
   guantes y en movimiento. El input va oculto pero dentro del label, que es lo
   que abre la cámara nativa del teléfono. */
.captura {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    min-height: 170px;
    border: 2px dashed var(--linea-fuerte);
    border-radius: 12px;
    background: var(--superficie-2);
    padding: 1.2rem;
    text-align: center;
    cursor: pointer;
}

.captura:active { background: var(--superficie-3); }
.captura input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.captura-icono { font-size: 2.2rem; }
.captura-txt { font-size: .95rem; font-weight: 600; color: var(--ink); }
.captura-nota { font-size: .76rem; color: var(--ink-3); }

.previa { display: flex; flex-direction: column; gap: .5rem; }

.previa img {
    width: 100%;
    max-height: 260px;
    object-fit: contain;
    border-radius: 10px;
    border: 1px solid var(--linea);
    background: var(--superficie-2);
}

.previa-firma { max-height: 150px; background: var(--superficie); }
.previa-peso { font-size: .74rem; color: var(--ink-2); margin: 0; text-align: center; }
.tenue { color: var(--ink-3); }

/* -------- firma ---------------------------------------------------------- */
.lienzo {
    width: 100%;
    height: 170px;
    border: 2px dashed var(--linea-fuerte);
    border-radius: 12px;
    background: var(--superficie);
    touch-action: none;
}

.firma-acciones { display: flex; gap: .5rem; }
.firma-acciones .btn { flex: 1; }

.consentimiento {
    display: flex;
    gap: .6rem;
    align-items: flex-start;
    font-size: .8rem;
    color: var(--ink-2);
    line-height: 1.45;
    background: var(--superficie-2);
    padding: .7rem;
    border-radius: 10px;
    margin-top: .4rem;
}

.consentimiento input { width: 22px; height: 22px; flex: none; margin-top: .1rem; }

.resumen {
    background: var(--superficie-2);
    border-radius: 10px;
    padding: .75rem .85rem;
    margin-top: .4rem;
}

.resumen-tit {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--ink-3);
    margin: 0 0 .45rem;
}

.resumen-fila { display: flex; align-items: center; gap: .5rem; font-size: .85rem; margin: .2rem 0; }
.resumen-fila .si { color: var(--bien); font-weight: 700; }
.resumen-fila .no { color: var(--critico); font-weight: 700; }
.resumen-peso { font-size: .73rem; color: var(--ink-3); margin: .5rem 0 0; }

.btn {
    min-height: var(--toque);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    border: 1px solid transparent;
    padding: .7rem 1rem;
}

.btn--ancho { width: 100%; margin-top: .4rem; }
.btn--pri { background: var(--acento); color: var(--sobre-acento); }
.btn--pri:disabled { opacity: .45; }
.btn--sec { background: var(--superficie); border-color: var(--linea-fuerte); color: var(--ink); }
.btn--novedad { background: var(--superficie); border-color: var(--atencion); color: var(--atencion); font-size: .88rem; }

.bloqueo { font-size: .78rem; color: var(--critico); text-align: center; margin: .35rem 0 0; font-weight: 600; }

.gps { font-size: .72rem; color: var(--ink-3); margin: 1rem 0 0; text-align: center; }
.gps.atenuado { opacity: .7; }

.exito { text-align: center; padding: 3rem 1rem; }

.exito .marca {
    width: 76px; height: 76px;
    margin: 0 auto 1rem;
    display: grid; place-items: center;
    border-radius: 50%;
    background: var(--bien-suave);
    color: var(--bien);
    font-size: 2.2rem;
}

.vacio { text-align: center; padding: 3rem 1rem; color: var(--ink-3); font-size: .88rem; line-height: 1.55; }
</style>
