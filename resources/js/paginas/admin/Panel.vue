<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api/cliente'
import Hero from '../../componentes/Hero.vue'
import TarjetaCifra from '../../componentes/TarjetaCifra.vue'
import { numero } from '../../graficos/util'

/**
 * Panel de control del administrador.
 *
 * No repite el tablero de operación. Aquí no importa cuántos paquetes se
 * entregaron, sino si los controles que sostienen la trazabilidad siguen en
 * pie: la cadena de auditoría, quién tiene acceso, qué documentos vencieron y
 * qué control quedó a medias.
 */
const datos = ref(null)
const cargando = ref(true)
const error = ref(null)
const verificando = ref(false)
const resultadoVerificacion = ref(null)

async function cargar() {
    cargando.value = true
    error.value = null

    try {
        datos.value = await api.adminResumen()
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

async function verificarTodo() {
    verificando.value = true
    resultadoVerificacion.value = null

    try {
        resultadoVerificacion.value = await api.adminVerificarCadena()
    } catch (e) {
        error.value = e.message
    } finally {
        verificando.value = false
    }
}

const cifrasHero = computed(() => {
    if (!datos.value) return []

    const i = datos.value.integridad
    const p = datos.value.pendientes

    return [
        { etiqueta: 'Eventos en cadena', valor: numero(i.eventos_totales) },
        { etiqueta: 'Integridad', valor: i.integra ? 'OK' : 'ROTA', tono: i.integra ? 'bien' : 'critico' },
        { etiqueta: 'Controles a medias', valor: p.length, tono: p.length ? 'atencion' : 'bien' },
    ]
})

const tono = (estado) => ({ critical: 'critico', warning: 'atencion', serious: 'grave' }[estado] ?? 'neutro')

function cuando(iso) {
    if (!iso) return '—'
    return new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
    <div class="pagina">
        <Hero
            rotulo="Administración del sistema"
            titulo="Estado de los controles"
            descripcion="Lo que sostiene la trazabilidad: la cadena de auditoría, los accesos a datos sensibles, los documentos de la flota y lo que quedó a medias."
            :cifras="cifrasHero"
        >
            <template #acciones>
                <button type="button" class="btn-hero" :disabled="verificando" @click="verificarTodo">
                    {{ verificando ? 'Verificando…' : 'Verificar cadena completa' }}
                </button>
            </template>
        </Hero>

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-else-if="cargando" class="aviso">Cargando estado del sistema…</p>

        <template v-else-if="datos">
            <p
                v-if="resultadoVerificacion"
                class="aviso"
                :class="resultadoVerificacion.integra ? 'aviso--ok' : 'aviso--error'"
            >
                <strong>{{ resultadoVerificacion.integra ? '✓ Cadena íntegra' : '✕ Cadena comprometida' }}</strong> ·
                {{ numero(resultadoVerificacion.revisados) }} eventos verificados en
                {{ numero(resultadoVerificacion.duracion_ms) }} ms.
                <span v-if="resultadoVerificacion.primer_fallo">
                    Primer fallo en la secuencia {{ resultadoVerificacion.primer_fallo.secuencia }}:
                    {{ resultadoVerificacion.primer_fallo.motivo }}
                </span>
            </p>

            <!-- 1. Controles a medias: lo primero, porque es lo accionable -->
            <section v-if="datos.pendientes.length" class="tarjeta">
                <div class="tarjeta-cab">
                    <h2 class="tarjeta-titulo">Controles pendientes</h2>
                    <span class="tarjeta-sub">{{ datos.pendientes.length }} sin cerrar</span>
                </div>
                <ul class="pendientes">
                    <li v-for="p in datos.pendientes" :key="p.control" :class="`p--${tono(p.estado)}`">
                        <span class="p-marca" aria-hidden="true" />
                        <div>
                            <strong>{{ p.control }}</strong>
                            <span class="pastilla" :class="p.estado === 'critical' ? 'pastilla--critico' : 'pastilla--atencion'">
                                {{ p.estado === 'critical' ? 'Crítico' : 'Atención' }}
                            </span>
                            <p>{{ p.detalle }}</p>
                        </div>
                    </li>
                </ul>
            </section>

            <p v-else class="aviso aviso--ok">
                <strong>✓ Sin controles pendientes.</strong> Todos los controles de trazabilidad y acceso están activos.
            </p>

            <!-- 2. Integridad -->
            <section class="rejilla">
                <TarjetaCifra
                    etiqueta="Eventos en la bitácora" :valor="numero(datos.integridad.eventos_totales)"
                    :pie="`Tramo verificado al abrir: ${numero(datos.integridad.tramo_verificado)}`"
                    :tono="datos.integridad.integra ? 'bien' : 'critico'"
                />
                <TarjetaCifra
                    etiqueta="Sellos diarios" :valor="datos.integridad.sellos.total"
                    :pie="`${datos.integridad.sellos.firmados} firmados · ${datos.integridad.sellos.exportados} exportados`"
                    :tono="datos.integridad.sellos.exportados === datos.integridad.sellos.total ? 'bien' : 'atencion'"
                />
                <TarjetaCifra
                    etiqueta="Accesos a datos sensibles" :valor="numero(datos.accesos.ultimos_30_dias)"
                    pie="Últimos 30 días" a="/admin/auditoria"
                />
                <TarjetaCifra
                    etiqueta="Exportaciones con receptor" :valor="datos.accesos.exportaciones_con_datos_receptor"
                    :pie="`De ${datos.accesos.exportaciones_30d} exportaciones en 30 días`"
                    :tono="datos.accesos.exportaciones_con_datos_receptor ? 'atencion' : 'neutro'"
                />
            </section>

            <!-- 3. Personas y flota -->
            <section class="doble">
                <div class="tarjeta">
                    <div class="tarjeta-cab">
                        <h2 class="tarjeta-titulo">Personas y accesos</h2>
                        <RouterLink to="/admin/usuarios" class="enlace">Gestionar →</RouterLink>
                    </div>
                    <div class="tarjeta-cuerpo">
                        <ul class="lista-dato">
                            <li v-for="r in datos.personas.por_rol" :key="r.rol">
                                <span class="ld-etq">{{ r.rol }}</span>
                                <span class="ld-val num">{{ r.total }}</span>
                            </li>
                        </ul>
                        <div class="separador" />
                        <ul class="lista-dato">
                            <li><span class="ld-etq">Cuentas activas</span><span class="ld-val num">{{ datos.personas.activos }}</span></li>
                            <li><span class="ld-etq">Desactivadas</span><span class="ld-val num">{{ datos.personas.inactivos }}</span></li>
                            <li><span class="ld-etq">Tokens de API vigentes</span><span class="ld-val num">{{ datos.personas.tokens_activos }}</span></li>
                            <li :class="{ alerta: datos.personas.sin_doble_factor > 0 }">
                                <span class="ld-etq">Administrativas sin doble factor</span>
                                <span class="ld-val num">{{ datos.personas.sin_doble_factor }}</span>
                            </li>
                            <li :class="{ alerta: datos.personas.sin_acceso_90d > 0 }">
                                <span class="ld-etq">Sin entrar hace 90 días</span>
                                <span class="ld-val num">{{ datos.personas.sin_acceso_90d }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="tarjeta">
                    <div class="tarjeta-cab">
                        <h2 class="tarjeta-titulo">Flota</h2>
                        <RouterLink to="/admin/flota" class="enlace">Gestionar →</RouterLink>
                    </div>
                    <div class="tarjeta-cuerpo">
                        <ul class="lista-dato">
                            <li><span class="ld-etq">Vehículos activos</span><span class="ld-val num">{{ datos.flota.vehiculos }}</span></li>
                            <li><span class="ld-etq">Con refrigeración</span><span class="ld-val num">{{ datos.flota.refrigerados }}</span></li>
                            <li :class="{ alerta: datos.flota.documentos_vencidos > 0 }">
                                <span class="ld-etq">Documentos vencidos</span>
                                <span class="ld-val num">{{ datos.flota.documentos_vencidos }}</span>
                            </li>
                            <li :class="{ alerta: datos.flota.por_vencer_30d > 0 }">
                                <span class="ld-etq">Vencen en 30 días</span>
                                <span class="ld-val num">{{ datos.flota.por_vencer_30d }}</span>
                            </li>
                            <li><span class="ld-etq">Mensajeros</span><span class="ld-val num">{{ datos.flota.mensajeros }}</span></li>
                            <li><span class="ld-etq">Certificados en controlados</span><span class="ld-val num">{{ datos.flota.certificados }}</span></li>
                        </ul>

                        <div v-if="datos.flota.detalle_vencidos.length" class="vencidos">
                            <p class="vencidos-tit">Bloqueados por documentos</p>
                            <p v-for="v in datos.flota.detalle_vencidos" :key="v.placa" class="vencido">
                                <span class="num">{{ v.placa }}</span> {{ v.tipo }} —
                                <span class="vencido-lista">{{ v.vencidos.join(', ') }}</span>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 4. Accesos recientes y crecimiento -->
            <section class="doble">
                <div class="tarjeta">
                    <div class="tarjeta-cab">
                        <h2 class="tarjeta-titulo">Accesos recientes a datos sensibles</h2>
                        <RouterLink to="/admin/auditoria" class="enlace">Ver todo →</RouterLink>
                    </div>
                    <div class="desliza">
                        <table class="tabla">
                            <thead>
                                <tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>IP</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="(a, i) in datos.accesos.recientes" :key="i">
                                    <td class="num">{{ cuando(a.cuando) }}</td>
                                    <td>{{ a.usuario }}</td>
                                    <td><span class="pastilla pastilla--neutral">{{ a.accion }}</span></td>
                                    <td class="num">{{ a.ip ?? '—' }}</td>
                                </tr>
                                <tr v-if="!datos.accesos.recientes.length">
                                    <td colspan="4" class="vacio">Sin accesos registrados todavía.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tarjeta">
                    <div class="tarjeta-cab">
                        <h2 class="tarjeta-titulo">Crecimiento del almacenamiento</h2>
                        <span class="tarjeta-sub">Lo que va a doler primero</span>
                    </div>
                    <div class="desliza">
                        <table class="tabla">
                            <thead><tr><th>Tabla</th><th>Filas</th><th>Nota</th></tr></thead>
                            <tbody>
                                <tr v-for="t in datos.almacen" :key="t.tabla">
                                    <td class="num">{{ t.tabla }}</td>
                                    <td class="num">{{ numero(t.filas) }}</td>
                                    <td class="nota-celda">{{ t.nota }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>

<style scoped>
.pagina {
    max-width: 1280px;
    margin: 0 auto;
    padding: 1.2rem 1rem 3rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 1000px) { .pagina { padding: 1.4rem 1.6rem 3rem; } }

.btn-hero {
    border: 1px solid rgba(255, 255, 255, .3);
    background: rgba(255, 255, 255, .1);
    color: #fff;
    border-radius: 9px;
    padding: .55rem 1rem;
    font-size: .82rem;
    font-weight: 600;
    backdrop-filter: blur(4px);
}

.btn-hero:hover:not(:disabled) { background: rgba(255, 255, 255, .18); }
.btn-hero:disabled { opacity: .55; cursor: progress; }

.rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: .8rem;
}

.doble { display: grid; grid-template-columns: 1fr; gap: 1rem; align-items: start; }
@media (min-width: 980px) { .doble { grid-template-columns: 1fr 1fr; } }

.pendientes { list-style: none; margin: 0; padding: 0; }

.pendientes li {
    display: grid;
    grid-template-columns: 4px 1fr;
    gap: .8rem;
    padding: .85rem .95rem;
    border-bottom: 1px solid var(--linea);
}

.pendientes li:last-child { border-bottom: none; }

.p-marca { border-radius: 3px; background: var(--ink-3); }
.p--critico .p-marca { background: var(--critico); }
.p--atencion .p-marca { background: var(--atencion); }
.p--grave .p-marca { background: var(--grave); }

.pendientes strong { font-size: .9rem; margin-right: .45rem; }
.pendientes p { font-size: .8rem; color: var(--ink-2); margin: .3rem 0 0; line-height: 1.5; }

.lista-dato { list-style: none; margin: 0; padding: 0; }

.lista-dato li {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 1rem;
    padding: .38rem 0;
    font-size: .84rem;
}

.ld-etq { color: var(--ink-2); text-transform: capitalize; }
.ld-val { font-weight: 600; color: var(--ink); }
.lista-dato li.alerta .ld-etq { color: var(--atencion-ink); }
.lista-dato li.alerta .ld-val { color: var(--atencion-ink); }

.separador { height: 1px; background: var(--linea); margin: .6rem 0; }

.vencidos { margin-top: .9rem; padding-top: .8rem; border-top: 1px solid var(--linea); }

.vencidos-tit {
    font-family: var(--mono);
    font-size: .6rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--critico);
    margin: 0 0 .4rem;
}

.vencido { font-size: .8rem; color: var(--ink-2); margin: .2rem 0; }
.vencido-lista { color: var(--critico); }

.tabla { width: 100%; border-collapse: collapse; font-size: .8rem; }

.tabla th {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--ink-3);
    font-weight: 500;
    text-align: left;
    padding: .5rem .8rem;
    background: var(--superficie-2);
    border-bottom: 1px solid var(--linea);
    white-space: nowrap;
}

.tabla td { padding: .5rem .8rem; border-bottom: 1px solid var(--linea); vertical-align: middle; }
.tabla tbody tr:last-child td { border-bottom: none; }
.nota-celda { color: var(--ink-3); font-size: .76rem; }
.vacio { text-align: center; color: var(--ink-3); padding: 1.4rem; }

.enlace { font-size: .76rem; font-weight: 600; color: var(--acento); text-decoration: none; }
.enlace:hover { text-decoration: underline; }

.aviso {
    padding: .9rem 1rem;
    border-radius: var(--r-md);
    font-size: .85rem;
    line-height: 1.5;
    background: var(--superficie);
    border: 1px solid var(--linea);
    margin: 0;
}

.aviso--error { background: var(--critico-suave); border-color: var(--critico); color: var(--critico); }
.aviso--ok { background: var(--bien-suave); border-color: var(--bien); color: var(--bien-ink); }
</style>
