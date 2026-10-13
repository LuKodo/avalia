<script setup>
import { conBase } from '../base'
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { sesion } from '../sesion'
import Logo from '../componentes/Logo.vue'

/**
 * Ingreso.
 *
 * Pantalla partida: el panel de marca a la izquierda y el formulario a la
 * derecha. El panel no es decoración —es lo que le dice a un mensajero que
 * abrió la aplicación correcta en un celular prestado, y a un cliente que el
 * enlace que le mandaron es de verdad nuestro—.
 *
 * Lo que NO hay aquí, a propósito: opciones de huella o reconocimiento facial.
 * Avalia no tiene lector biométrico conectado, y dibujar botones que no capturan
 * nada convierte la pantalla en una promesa falsa. Cuando exista el lector, el
 * bloque de abajo es donde entra.
 */
const router = useRouter()
const ruta = useRoute()

const email = ref('')
const password = ref('')
const verClave = ref(false)
const enviando = ref(false)
const error = ref(null)

const puedeEnviar = computed(() => email.value.trim() !== '' && password.value !== '')

async function entrar() {
    if (!puedeEnviar.value || enviando.value) return

    enviando.value = true
    error.value = null

    try {
        const usuario = await sesion.ingresar(email.value.trim(), password.value)

        // Cada rol entra por donde trabaja: el mensajero a sus tareas, el
        // cliente a rastrear, el operador al tablero. Aterrizar a todos en la
        // misma pantalla obliga a la mitad a navegar antes de empezar.
        router.push(ruta.query.destino || usuario.inicio || '/tablero')
    } catch (e) {
        error.value = e.cuerpo?.errors?.email?.[0]
            ?? e.cuerpo?.message
            ?? (e.esDeRed
                ? 'Sin conexión con el servidor. Verifique la red e intente de nuevo.'
                : e.message)
    } finally {
        enviando.value = false
    }
}

const garantias = [
    { txt: 'Trazabilidad verificable', det: 'Cada evento con hash encadenado' },
    { txt: 'Datos cifrados', det: 'Documento del receptor en columna cifrada' },
    { txt: 'Registro inalterable', det: 'La bitácora solo admite anexar' },
]
</script>

<template>
    <div class="ingreso">

        <!-- ------------------------------------------------ panel de marca -->
        <aside class="marca">
            <!-- Trazo de fondo. Aria-hidden: no aporta información, solo
                 profundidad, y un lector de pantalla no debe anunciarlo. -->
            <svg class="trazo" viewBox="0 0 600 600" aria-hidden="true" focusable="false">
                <circle cx="430" cy="180" r="230" fill="none" stroke="currentColor" stroke-width="1" />
                <circle cx="430" cy="180" r="330" fill="none" stroke="currentColor" stroke-width="1" opacity=".55" />
                <circle cx="120" cy="520" r="190" fill="none" stroke="currentColor" stroke-width="1" opacity=".4" />
            </svg>

            <!--
                El logotipo lleva al sitio público. La gente ya intenta pulsarlo
                esperando eso; hacerlo un enlace de verdad cuesta nada.

                Es <a href> y no una ruta de Vue a propósito: el sitio lo sirve
                el servidor, fuera de la aplicación de una sola página.
            -->
            <a class="marca-cab" :href="conBase('/sitio')" title="Ir al sitio de Avalia Logística">
                <Logo pieza="eslogan" ancho="212px" decorativo />
            </a>

            <div class="marca-cuerpo">
                <p class="eyebrow">Operación protegida</p>

                <h1 class="titular">
                    Cada entrega,<br>
                    <span>con prueba.</span>
                </h1>

                <p class="bajada">
                    Recolección, custodia y prueba de entrega de medicamentos e insumos
                    médicos, con trazabilidad que resiste una auditoría.
                </p>
            </div>

            <ul class="garantias">
                <li v-for="g in garantias" :key="g.txt">
                    <span class="tick" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m5 13 4.5 4.5L19 7" />
                        </svg>
                    </span>
                    <span class="garantia-txt">
                        <strong>{{ g.txt }}</strong>
                        <small>{{ g.det }}</small>
                    </span>
                </li>
            </ul>
        </aside>

        <!-- ---------------------------------------------------- formulario -->
        <main class="panel">
            <div class="caja">
                <p class="eyebrow eyebrow--claro">Acceso al sistema</p>
                <h2 class="titulo">Ingrese a su cuenta</h2>
                <p class="sub">
                    Use el correo con el que se creó su usuario. Cada rol entra
                    directamente a donde trabaja.
                </p>

                <form class="forma" novalidate @submit.prevent="entrar">
                    <div class="campo-grupo">
                        <label class="etiqueta" for="email">Correo</label>
                        <div class="campo-caja">
                            <svg class="campo-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="2.5" />
                                <path d="m3.5 7 8.5 6 8.5-6" />
                            </svg>
                            <input
                                id="email"
                                v-model="email"
                                class="campo"
                                type="email"
                                inputmode="email"
                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                                placeholder="usuario@dominio.com"
                                :aria-invalid="error !== null"
                                aria-describedby="aviso-error"
                            >
                        </div>
                    </div>

                    <div class="campo-grupo">
                        <label class="etiqueta" for="clave">Contraseña</label>
                        <div class="campo-caja">
                            <svg class="campo-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.7" aria-hidden="true">
                                <rect x="4.5" y="10.5" width="15" height="9.5" rx="2.5" />
                                <path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7" />
                            </svg>
                            <input
                                id="clave"
                                v-model="password"
                                class="campo"
                                :type="verClave ? 'text' : 'password'"
                                autocomplete="current-password"
                                placeholder="Su contraseña"
                                :aria-invalid="error !== null"
                                aria-describedby="aviso-error"
                            >
                            <button
                                type="button"
                                class="ojo"
                                :aria-pressed="verClave"
                                :aria-label="verClave ? 'Ocultar la contraseña' : 'Mostrar la contraseña'"
                                @click="verClave = !verClave"
                            >
                                <svg v-if="!verClave" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" aria-hidden="true">
                                    <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12S18 18.5 12 18.5 2.5 12 2.5 12z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                                    <path d="M4 4l16 16" />
                                    <path d="M9.6 6.1A9.4 9.4 0 0 1 12 5.8c6 0 9.5 6.2 9.5 6.2a17 17 0 0 1-2.5 3.3" />
                                    <path d="M6.3 8A17 17 0 0 0 2.5 12s3.5 6.2 9.5 6.2c1 0 1.9-.2 2.7-.5" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- El aviso vive en el flujo, no flotando: así no tapa el
                         campo que hay que corregir. -->
                    <p v-if="error" id="aviso-error" class="error" role="alert">
                        <span class="error-icono" aria-hidden="true">!</span>
                        {{ error }}
                    </p>

                    <button type="submit" class="btn" :disabled="!puedeEnviar || enviando">
                        <span v-if="enviando" class="hilando" aria-hidden="true" />
                        {{ enviando ? 'Verificando…' : 'Entrar' }}
                        <svg v-if="!enviando" class="btn-flecha" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 12h13M13 6l6 6-6 6" />
                        </svg>
                    </button>
                </form>

                <p class="nota">
                    Los intentos fallidos se limitan por cuenta y por red. Si perdió su
                    contraseña, el área de TIC la restablece: no se puede recuperar,
                    solo cambiar.
                </p>
            </div>

            <p class="pie">
                <span>¿Solo necesita ver un envío?</span>
                Use el enlace de rastreo que le compartieron; no requiere cuenta.
            </p>

            <!--
                Salida al sitio público, siempre visible. El panel de marca —donde
                el logotipo también lleva allí— se oculta en pantallas estrechas,
                así que en el teléfono este es el único camino de vuelta.
            -->
            <a class="volver" :href="conBase('/sitio')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m15 18-6-6 6-6" />
                </svg>
                Volver al sitio de Avalia
            </a>
        </main>
    </div>
</template>

<style scoped>
.ingreso {
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    grid-template-columns: 1fr;
}

@media (min-width: 900px) {
    .ingreso { grid-template-columns: 1.15fr 1fr; }
}

/* ============================================================ marca ===== */

.marca {
    position: relative;
    overflow: hidden;
    color: var(--marca-ink);
    background:
        radial-gradient(120% 110% at 88% 6%, var(--marca-3) 0%, transparent 58%),
        linear-gradient(155deg, var(--marca-2) 0%, var(--marca-1) 72%);
    padding: 2rem 1.5rem 2.4rem;
    display: flex;
    flex-direction: column;
    gap: 2rem;
}

@media (min-width: 900px) {
    .marca { padding: 3rem 3.4rem; gap: 3rem; }
}

.trazo {
    position: absolute;
    inset: -12% -18% auto auto;
    width: min(115%, 720px);
    color: rgba(255, 255, 255, .13);
    pointer-events: none;
}

.marca-cab,
.marca-cuerpo,
.garantias { position: relative; }

.marca-cab {
    display: inline-flex;
    align-self: flex-start;
    text-decoration: none;
    /* El logotipo hereda este color: el panel es azul noche, la pieza va en
       blanco. */
    color: var(--marca-ink);
    border-radius: 12px;
    transition: opacity .14s ease;
}

.marca-cab:hover { opacity: .82; }
.marca-cab:focus-visible { outline: 2px solid var(--marca-ink); outline-offset: 6px; }

@media (max-width: 420px) {
    .marca-cab :deep(.logo-avalia) { width: 176px; }
}

.marca-cuerpo { margin-top: auto; }

.eyebrow {
    font-family: var(--mono);
    font-size: .64rem;
    letter-spacing: .19em;
    text-transform: uppercase;
    color: var(--marca-ink-3);
    margin: 0 0 1rem;
}

.titular {
    font-size: clamp(2.1rem, 5.6vw, 3.5rem);
    font-weight: 700;
    letter-spacing: -.042em;
    line-height: 1.02;
    margin: 0;
    text-wrap: balance;
}

.titular span { color: var(--marca-ink-2); }

.bajada {
    font-size: clamp(.9rem, 1.6vw, 1.02rem);
    line-height: 1.6;
    color: var(--marca-ink-2);
    max-width: 44ch;
    margin: 1.1rem 0 0;
}

.garantias {
    list-style: none;
    margin: 0;
    padding: 1.6rem 0 0;
    border-top: 1px solid var(--marca-linea);
    display: grid;
    gap: .85rem;
}

@media (min-width: 620px) and (max-width: 899px) {
    .garantias { grid-template-columns: repeat(3, 1fr); }
}

.garantias li { display: flex; align-items: flex-start; gap: .65rem; }

.tick {
    width: 21px;
    height: 21px;
    flex: none;
    margin-top: .12rem;
    border-radius: 50%;
    display: grid;
    place-items: center;
    /* El verde queda reservado a «esto está bien»: aquí es exactamente eso, y
       viaja con texto, nunca solo como color. */
    background: rgba(12, 163, 12, .16);
    color: var(--bien);
    border: 1px solid rgba(12, 163, 12, .38);
}

.tick svg { width: 12px; height: 12px; }

.garantia-txt { display: flex; flex-direction: column; min-width: 0; }
.garantia-txt strong { font-size: .84rem; font-weight: 600; }

.garantia-txt small {
    font-size: .74rem;
    color: var(--marca-ink-3);
    line-height: 1.4;
    margin-top: .1rem;
}

/* ======================================================== formulario ===== */

.panel {
    background: var(--fondo);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 1.4rem;
    padding: 2.4rem 1.25rem 2rem;
}

@media (min-width: 900px) {
    .panel { padding: 3rem 2.6rem; }
}

.caja {
    width: 100%;
    max-width: 410px;
    background: var(--superficie);
    border: 1px solid var(--linea);
    border-radius: 14px;
    padding: 1.7rem 1.5rem 1.5rem;
    box-shadow: var(--sombra);
}

.eyebrow--claro { color: var(--acento); margin-bottom: .5rem; }

.titulo {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: -.032em;
    margin: 0;
    color: var(--ink);
}

.sub {
    font-size: .85rem;
    color: var(--ink-2);
    line-height: 1.5;
    margin: .4rem 0 1.4rem;
}

.forma { display: flex; flex-direction: column; gap: .9rem; }

.campo-grupo { display: flex; flex-direction: column; gap: .3rem; }

.etiqueta {
    font-family: var(--mono);
    font-size: .61rem;
    letter-spacing: .13em;
    text-transform: uppercase;
    color: var(--ink-3);
}

.campo-caja {
    position: relative;
    display: flex;
    align-items: center;
    border: 1.5px solid var(--linea-fuerte);
    border-radius: 10px;
    background: var(--superficie);
    transition: border-color .14s ease, box-shadow .14s ease;
}

/* El foco se marca en el contenedor, no en el input: así el anillo abarca el
   icono y el botón del ojo, y se lee como un solo control. */
.campo-caja:focus-within {
    border-color: var(--acento);
    box-shadow: 0 0 0 3px var(--acento-suave);
}

.campo-icono {
    width: 18px;
    height: 18px;
    flex: none;
    margin-left: .8rem;
    color: var(--ink-3);
}

.campo-caja:focus-within .campo-icono { color: var(--acento); }

.campo {
    flex: 1;
    min-width: 0;
    min-height: var(--toque);
    border: none;
    outline: none;
    background: transparent;
    padding: .6rem .8rem;
    font-size: .95rem;
    color: var(--ink);
}

.campo::placeholder { color: var(--ink-3); opacity: .75; }

.ojo {
    flex: none;
    width: 40px;
    min-height: var(--toque);
    border: none;
    background: none;
    color: var(--ink-3);
    display: grid;
    place-items: center;
    border-radius: 0 8px 8px 0;
}

.ojo svg { width: 18px; height: 18px; }
.ojo:hover { color: var(--ink-2); }

.error {
    display: flex;
    align-items: flex-start;
    gap: .5rem;
    background: var(--critico-suave);
    color: var(--critico);
    border: 1px solid rgba(208, 59, 59, .35);
    font-size: .82rem;
    line-height: 1.45;
    padding: .6rem .7rem;
    border-radius: 8px;
    margin: 0;
}

.error-icono {
    flex: none;
    width: 17px;
    height: 17px;
    margin-top: .06rem;
    border-radius: 50%;
    background: var(--critico);
    color: var(--superficie);
    font-family: var(--mono);
    font-size: .68rem;
    font-weight: 700;
    display: grid;
    place-items: center;
}

.btn {
    min-height: 50px;
    margin-top: .4rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    background: var(--acento);
    color: var(--sobre-acento);
    border: none;
    border-radius: 10px;
    font-size: .96rem;
    font-weight: 600;
    letter-spacing: -.005em;
    transition: filter .14s ease, transform .08s ease;
}

.btn:hover:not(:disabled) { filter: brightness(1.08); }
.btn:active:not(:disabled) { transform: translateY(1px); }

.btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.btn-flecha { width: 17px; height: 17px; }

.hilando {
    width: 15px;
    height: 15px;
    border: 2px solid rgba(255, 255, 255, .35);
    border-top-color: currentColor;
    border-radius: 50%;
    animation: gira .7s linear infinite;
}

@keyframes gira { to { transform: rotate(360deg); } }

.nota {
    font-size: .74rem;
    color: var(--ink-3);
    line-height: 1.5;
    margin: 1.1rem 0 0;
    padding-top: 1rem;
    border-top: 1px solid var(--linea);
}

.pie {
    max-width: 410px;
    font-size: .76rem;
    color: var(--ink-3);
    line-height: 1.5;
    text-align: center;
    margin: 0;
}

.pie span { display: block; font-weight: 600; color: var(--ink-2); }

/* Objetivo tactil completo: en el telefono este es el unico camino de
   vuelta al sitio, y un enlace de texto suelto es dificil de acertar. */
.volver {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 44px;
    padding: 0 .9rem;
    margin-top: .2rem;
    border-radius: var(--r-md);
    font-size: .82rem;
    font-weight: 500;
    color: var(--ink-2);
    text-decoration: none;
}

.volver svg { width: 15px; height: 15px; flex: none; }
.volver:hover { color: var(--acento); background: var(--acento-suave); }
.volver:focus-visible { outline: 2px solid var(--acento); outline-offset: 1px; }

@media (prefers-reduced-motion: reduce) {
    .hilando { animation-duration: 2.4s; }
    .btn:active:not(:disabled) { transform: none; }
}
</style>
