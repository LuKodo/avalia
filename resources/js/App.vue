<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { estadoSync } from './offline/sincronizador'
import { detenerRastreo, iniciarRastreo } from './offline/rastreoGps'
import { sesion } from './sesion'
import Logo from './componentes/Logo.vue'

/**
 * Armazón de la aplicación.
 *
 * Barra lateral en azul marino, la misma superficie de marca del ingreso: sin
 * ella, la aplicación se sentía como una hoja de cálculo con encabezados. El
 * color no es adorno — es lo que hace que la pantalla de trabajo y la de
 * ingreso se lean como el mismo producto.
 *
 * La navegación se arma según el rol y no se oculta con CSS: si un rol no tiene
 * una sección, la sección no existe en su menú. Un menú lleno de opciones que
 * llevan a un «no autorizado» es un menú que miente.
 */
const ruta = useRoute()
const router = useRouter()

const menuAbierto = ref(false)

watch(() => ruta.fullPath, () => { menuAbierto.value = false })

/**
 * El GPS solo se enciende para el mensajero, y solo mientras tenga sesión.
 *
 * Va atado al rol y no a una pantalla concreta a propósito: el rastro tiene que
 * seguir saliendo aunque el mensajero esté mirando el buscador de envíos en vez
 * de su lista de tareas. Y se apaga al salir, porque tener el receptor
 * encendido para alguien que ya cerró sesión es gastarle la batería y recoger
 * una ubicación que nadie pidió.
 */
watch(() => sesion.rol.value, (rol) => {
    if (rol === 'mensajero') iniciarRastreo()
    else detenerRastreo()
}, { immediate: true })

const esCampo = computed(() => ruta.meta.densidad === 'campo')
/*
 * Sin sesión no hay cascarón.
 *
 * El menú lateral, el nombre del usuario y el botón de Salir son la señal de
 * que hay una sesión abierta. Pintarlos sin ella le dice a quien mira que
 * sigue dentro cuando ya no lo está, y eso es exactamente lo contrario de lo
 * que debe comunicar una pantalla de un sistema con datos de pacientes.
 *
 * Pasaba en las rutas públicas que comparten plantilla con la aplicación —la
 * de «página no encontrada», la más fácil de alcanzar: basta escribir
 * cualquier dirección—. Los enlaces no llevaban a ninguna parte, porque el
 * guardia del enrutador y el servidor los paran igual, pero el aspecto decía
 * otra cosa.
 */
const sinCascaron = computed(
    () => ruta.meta.sinCascaron === true || ! sesion.autenticado.value)

/**
 * Secciones por rol.
 *
 * Cada entrada lleva una sigla de dos letras en vez de un icono: se lee igual
 * de rápido, no depende de que alguien adivine qué significa un pictograma, y
 * no arrastra una librería de iconos por seis elementos de menú.
 */
const secciones = computed(() => {
    if (sesion.tiene('mensajero')) {
        return [{
            titulo: 'Mi jornada',
            items: [
                { a: '/jornada', sigla: 'MJ', txt: 'Mi jornada' },
                { a: '/campo', sigla: 'MT', txt: 'Mis tareas' },
                { a: '/rastreo', sigla: 'RA', txt: 'Buscar envío' },
            ],
        }]
    }

    /*
     * `cliente_radicador` ve lo mismo que `cliente`: radica y consulta. La
     * diferencia es que no puede cancelar, y eso se decide en el servidor —el
     * middleware de la ruta—, no escondiendo un botón. Un menú distinto solo
     * serviría para que el botón que igual no funciona no estorbe; el control
     * de verdad está en la ruta.
     */
    if (sesion.tiene('cliente', 'cliente_radicador')) {
        return [{
            titulo: 'Mis envíos',
            items: [
                { a: '/radicar', sigla: 'RD', txt: 'Radicar envío' },
                { a: '/radicar/lote', sigla: 'RM', txt: 'Radicación masiva' },
                { a: '/solicitudes', sigla: 'MS', txt: 'Mis solicitudes' },
                { a: '/puntos', sigla: 'PP', txt: 'Puntos pactados' },
                { a: '/rastreo', sigla: 'RA', txt: 'Rastreo y soportes' },
                { a: '/tablero', sigla: 'TB', txt: 'Mis indicadores' },
            ],
        }]
    }

    const base = [
        {
            titulo: 'Operación',
            items: [
                { a: '/tablero', sigla: 'TB', txt: 'Tablero' },
                { a: '/despacho', sigla: 'DE', txt: 'Despacho' },
                { a: '/mapa', sigla: 'MP', txt: 'Mapa de flota' },
                { a: '/puntos', sigla: 'PP', txt: 'Puntos pactados' },
                { a: '/radicar', sigla: 'RD', txt: 'Radicar envío' },
                { a: '/radicar/lote', sigla: 'RM', txt: 'Radicación masiva' },
                { a: '/telemetria', sigla: 'CF', txt: 'Cadena de frío' },
            ],
        },
        {
            titulo: 'Consulta',
            items: [
                { a: '/rastreo', sigla: 'RA', txt: 'Rastreo y soportes' },
            ],
        },
    ]

    if (sesion.tiene('administrador')) {
        base.push({
            titulo: 'Administración',
            items: [
                { a: '/admin', sigla: 'PA', txt: 'Panel de control' },
                { a: '/admin/informes', sigla: 'IN', txt: 'Informes' },
                { a: '/admin/usuarios', sigla: 'US', txt: 'Usuarios' },
                { a: '/admin/clientes', sigla: 'CL', txt: 'Clientes y sedes' },
                { a: '/admin/flota', sigla: 'FL', txt: 'Flota' },
                { a: '/admin/parametros', sigla: 'PR', txt: 'Parámetros' },
                { a: '/admin/auditoria', sigla: 'AU', txt: 'Auditoría' },
            ],
        })
    }

    return base
})

const iniciales = computed(() => (sesion.usuario.value?.nombre ?? '?')
    .split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase())

/** Migaja: la sección donde está parado, para no perder el contexto. */
const migaja = computed(() => {
    for (const s of secciones.value) {
        const encontrado = s.items.find((i) => ruta.path === i.a
            || (i.a !== '/' && ruta.path.startsWith(`${i.a}/`)))

        if (encontrado) return `${s.titulo} · ${encontrado.txt}`
    }

    return ruta.meta.titulo ?? ''
})

const etiquetaRol = computed(() => ({
    administrador: 'Administrador',
    operador: 'Operador',
    cliente: sesion.usuario.value?.cliente ?? 'Cliente',
    mensajero: 'Mensajero',
}[sesion.rol.value] ?? ''))

async function salir() {
    await sesion.salir()
    router.push('/ingreso')
}
</script>

<template>
    <RouterView v-if="sinCascaron" />

    <div v-else class="capa" :class="{ 'capa--campo': esCampo }">

        <!-- ------------------------------------------------------- lateral -->
        <aside class="lateral" :class="{ 'lateral--abierto': menuAbierto }">
            <RouterLink to="/" class="marca" aria-label="Avalia Logística — inicio">
                <Logo pieza="principal" ancho="164px" decorativo />
            </RouterLink>

            <div class="sesion-activa">
                <p class="sesion-rotulo">Sesión activa</p>
                <div class="sesion-fila">
                    <span class="sesion-nombre">{{ sesion.usuario.value?.nombre }}</span>
                    <span class="chip" :class="estadoSync.enLinea ? 'chip--ok' : 'chip--fuera'">
                        {{ estadoSync.enLinea ? 'EN LÍNEA' : 'SIN RED' }}
                    </span>
                </div>
                <p class="sesion-rol">{{ etiquetaRol }}</p>
            </div>

            <nav class="nav" aria-label="Secciones">
                <div v-for="s in secciones" :key="s.titulo" class="nav-grupo">
                    <p class="nav-titulo">{{ s.titulo }}</p>
                    <RouterLink
                        v-for="i in s.items"
                        :key="i.a"
                        :to="i.a"
                        class="nav-item"
                        :class="{ 'nav-item--activo': ruta.path === i.a }"
                    >
                        <span class="nav-sigla" aria-hidden="true">{{ i.sigla }}</span>
                        <span class="nav-txt">{{ i.txt }}</span>
                    </RouterLink>
                </div>
            </nav>

            <p class="lateral-pie">
                Operación protegida<br>
                <span v-if="estadoSync.pendientes > 0" class="pendientes-pie">
                    {{ estadoSync.pendientes }} registro(s) por sincronizar
                </span>
                <span v-else>Bitácora de solo anexado</span>
            </p>
        </aside>

        <button
            v-if="menuAbierto"
            type="button"
            class="velo"
            aria-label="Cerrar el menú"
            @click="menuAbierto = false"
        />

        <!-- -------------------------------------------------------- cuerpo -->
        <div class="cuerpo">
            <header class="superior">
                <button
                    type="button"
                    class="hamburguesa"
                    aria-label="Abrir el menú"
                    :aria-expanded="menuAbierto"
                    @click="menuAbierto = !menuAbierto"
                >
                    <span aria-hidden="true" />
                    <span aria-hidden="true" />
                    <span aria-hidden="true" />
                </button>

                <div class="migaja">
                    <p class="migaja-ruta">Avalia · {{ migaja }}</p>
                    <p class="migaja-titulo">{{ ruta.meta.titulo }}</p>
                </div>

                <div class="cuenta">
                    <span class="avatar">{{ iniciales }}</span>
                    <span class="cuenta-txt">
                        <span class="cuenta-nombre">{{ sesion.usuario.value?.nombre }}</span>
                        <span class="cuenta-rol">{{ etiquetaRol }}</span>
                    </span>
                    <button type="button" class="salir" @click="salir">Salir</button>
                </div>
            </header>

            <main class="contenido">
                <RouterView v-slot="{ Component }">
                    <Suspense>
                        <component :is="Component" />
                        <template #fallback>
                            <p class="cargando">Cargando…</p>
                        </template>
                    </Suspense>
                </RouterView>
            </main>
        </div>
    </div>
</template>

<style scoped>
.capa {
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    grid-template-columns: 1fr;
}

@media (min-width: 1000px) {
    .capa { grid-template-columns: 258px minmax(0, 1fr); }
}

/* ========================================================== lateral ===== */

.lateral {
    position: fixed;
    inset: 0 auto 0 0;
    width: 268px;
    z-index: 60;
    transform: translateX(-100%);
    transition: transform .18s ease;
    display: flex;
    flex-direction: column;
    color: var(--marca-ink);
    background:
        radial-gradient(140% 60% at 10% 0%, var(--marca-3) 0%, transparent 55%),
        linear-gradient(185deg, var(--marca-2) 0%, var(--marca-1) 68%);
    overflow-y: auto;
}

.lateral--abierto { transform: translateX(0); }

@media (min-width: 1000px) {
    .lateral { position: sticky; top: 0; height: 100vh; width: auto; transform: none; }
}

.marca {
    display: flex;
    align-items: center;
    padding: 1.25rem 1.1rem 1.15rem;
    text-decoration: none;
    /* El logotipo se pinta con `currentColor`: este color es el que hereda. La
       barra es azul noche, así que la pieza va en blanco. */
    color: var(--marca-ink);
    border-bottom: 1px solid var(--marca-linea);
    flex: none;
}

.marca:focus-visible {
    outline: 2px solid var(--marca-ink);
    outline-offset: 2px;
    border-radius: var(--r-sm);
}

.sesion-activa {
    padding: .9rem 1.1rem;
    border-bottom: 1px solid var(--marca-linea);
    flex: none;
}

.sesion-rotulo {
    font-family: var(--mono);
    font-size: .55rem;
    letter-spacing: .16em;
    text-transform: uppercase;
    color: var(--marca-ink-3);
    margin: 0 0 .35rem;
}

.sesion-fila { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }

.sesion-nombre {
    font-size: .88rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sesion-rol { font-size: .72rem; color: var(--marca-ink-3); margin: .18rem 0 0; }

.chip {
    font-family: var(--mono);
    font-size: .53rem;
    letter-spacing: .1em;
    padding: .18rem .38rem;
    border-radius: 999px;
    flex: none;
}

.chip--ok { background: rgba(12, 163, 12, .18); color: #6FD46F; border: 1px solid rgba(12, 163, 12, .4); }
.chip--fuera { background: rgba(250, 178, 25, .18); color: #FAC64D; border: 1px solid rgba(250, 178, 25, .42); }

.nav { flex: 1; padding: .9rem .7rem 1.2rem; }

.nav-grupo + .nav-grupo { margin-top: 1.15rem; }

.nav-titulo {
    font-family: var(--mono);
    font-size: .55rem;
    letter-spacing: .17em;
    text-transform: uppercase;
    color: var(--marca-ink-3);
    margin: 0 0 .45rem .45rem;
}

.nav-item {
    position: relative;
    display: flex;
    align-items: center;
    gap: .65rem;
    padding: .5rem .55rem;
    border-radius: 9px;
    margin-bottom: .15rem;
    text-decoration: none;
    color: var(--marca-ink-2);
    font-size: .85rem;
    font-weight: 500;
    min-height: 42px;
}

.nav-item:hover { background: var(--marca-vidrio); color: var(--marca-ink); }

.nav-item--activo {
    background: rgba(255, 255, 255, .12);
    color: var(--marca-ink);
    font-weight: 600;
}

/* Barra de acento a la izquierda del activo: el estado se dice con posición y
   con peso, no solo con un fondo un poco más claro. */
.nav-item--activo::before {
    content: '';
    position: absolute;
    left: -.55rem;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 22px;
    border-radius: 0 3px 3px 0;
    background: #7FB0FF;
}

.nav-sigla {
    width: 28px;
    height: 28px;
    flex: none;
    border-radius: 8px;
    display: grid;
    place-items: center;
    font-family: var(--mono);
    font-size: .62rem;
    font-weight: 600;
    letter-spacing: .04em;
    background: var(--marca-vidrio);
    border: 1px solid var(--marca-linea);
    color: var(--marca-ink-2);
}

.nav-item--activo .nav-sigla {
    background: #7FB0FF;
    border-color: #7FB0FF;
    color: var(--marca-1);
}

.nav-txt { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.lateral-pie {
    flex: none;
    padding: .85rem 1.1rem;
    border-top: 1px solid var(--marca-linea);
    font-family: var(--mono);
    font-size: .6rem;
    line-height: 1.6;
    color: var(--marca-ink-3);
    margin: 0;
}

.pendientes-pie { color: #FAC64D; }

.velo {
    position: fixed;
    inset: 0;
    z-index: 55;
    border: none;
    background: rgba(7, 27, 61, .55);
    backdrop-filter: blur(2px);
}

@media (min-width: 1000px) { .velo { display: none; } }

/* =========================================================== cuerpo ===== */

.cuerpo { display: flex; flex-direction: column; min-width: 0; }

.superior {
    position: sticky;
    top: 0;
    z-index: 40;
    display: flex;
    align-items: center;
    gap: .9rem;
    padding: .7rem 1rem;
    background: var(--superficie);
    border-bottom: 1px solid var(--linea);
}

@media (min-width: 1000px) { .superior { padding: .8rem 1.6rem; } }

.hamburguesa {
    flex: none;
    width: 40px;
    height: 40px;
    border: 1px solid var(--linea-fuerte);
    border-radius: 9px;
    background: var(--superficie);
    display: grid;
    place-content: center;
    gap: 4px;
    padding: 0;
}

.hamburguesa span { display: block; width: 16px; height: 1.8px; background: var(--ink-2); border-radius: 2px; }

@media (min-width: 1000px) { .hamburguesa { display: none; } }

.migaja { flex: 1; min-width: 0; }

.migaja-ruta {
    font-family: var(--mono);
    font-size: .58rem;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--acento);
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.migaja-titulo {
    font-size: 1.02rem;
    font-weight: 700;
    letter-spacing: -.025em;
    color: var(--ink);
    margin: .1rem 0 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cuenta { display: flex; align-items: center; gap: .6rem; flex: none; }

.avatar {
    width: 36px;
    height: 36px;
    flex: none;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: var(--acento);
    color: var(--sobre-acento);
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .02em;
}

.cuenta-txt { display: flex; flex-direction: column; line-height: 1.15; }
.cuenta-nombre { font-size: .8rem; font-weight: 600; white-space: nowrap; }

.cuenta-rol {
    font-family: var(--mono);
    font-size: .56rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--ink-3);
    white-space: nowrap;
    max-width: 18ch;
    overflow: hidden;
    text-overflow: ellipsis;
}

.salir {
    border: 1px solid var(--linea-fuerte);
    background: var(--superficie);
    border-radius: 8px;
    padding: .34rem .7rem;
    font-size: .74rem;
    font-weight: 500;
    color: var(--ink-2);
}

.salir:hover { background: var(--superficie-2); color: var(--ink); }

@media (max-width: 700px) { .cuenta-txt { display: none; } }

.contenido { flex: 1; min-width: 0; background: var(--fondo); }

.cargando {
    padding: 4rem 1rem;
    text-align: center;
    color: var(--ink-3);
    font-family: var(--mono);
    font-size: .8rem;
}

/* -------- densidad de campo: el pulgar llega abajo, no arriba ---------- */
@media (max-width: 999px) {
    .capa--campo .contenido { padding-bottom: 1rem; }
}

@media (prefers-reduced-motion: reduce) {
    .lateral { transition: none; }
}
</style>
