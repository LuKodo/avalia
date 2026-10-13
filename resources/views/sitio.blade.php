<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Avalia Logística — carga y transporte terrestre, logística para el sector salud y última milla, con cadena de frío monitoreada y evidencia verificable. Cartagena, Colombia.">
<title>Avalia Logística · Conectamos destinos, respaldamos confianzas</title>
<meta name="theme-color" content="#0D1333">

<link rel="icon" href="{{ asset('marca/icono.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('marca/icono-180.png') }}">

{{-- Las fuentes de marca se sirven desde este mismo dominio, no desde un CDN:
     una dependencia externa menos, ninguna petición del visitante a un tercero
     y nada que dependa de que ese tercero siga en pie. --}}
<link rel="preload" href="{{ asset('fuentes/work-sans.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fuentes/league-spartan.woff2') }}" as="font" type="font/woff2" crossorigin>

<style>
/* ==========================================================================
   Avalia Logística — sitio público
   --------------------------------------------------------------------------
   Los colores y las tipografías salen del manual de marca:

     #0D1333  azul noche   · el dominante
     #004CB9  azul vivo    · el acento
     #D2E3F3  azul pálido  · los fondos suaves

   Aquí el azul vivo SÍ puede ser el acento —a diferencia de la aplicación—
   porque en el sitio no hay gráficas de datos con las que pueda confundirse.
   Es el mismo criterio, aplicado a un contexto distinto.

   League Spartan para los titulares (es la fuente del logotipo) y Work Sans
   para el texto corrido. Las dos se sirven desde este dominio.

   La página se compromete con un solo aspecto: claro con secciones en azul
   noche. Todo color se pinta explícito; nada hereda del tema del visitante.
   ========================================================================== */
@font-face {
    font-family: "League Spartan";
    src: url("{{ asset('fuentes/league-spartan.woff2') }}") format("woff2-variations");
    font-weight: 100 900; font-style: normal; font-display: swap;
}
@font-face {
    font-family: "Work Sans";
    src: url("{{ asset('fuentes/work-sans.woff2') }}") format("woff2-variations");
    font-weight: 100 900; font-style: normal; font-display: swap;
}

:root {
    --papel:      #FFFFFF;
    --papel-2:    #F3F6FC;
    --papel-3:    #E4EDF9;
    --tinta:      #0D1333;
    --tinta-2:    #3D465F;
    --tinta-3:    #69738C;
    --linea:      #D9E3F1;
    --linea-2:    #BCCADD;

    /* Las superficies oscuras de marca. */
    --marino:     #0D1333;
    --marino-2:   #141C45;
    --marino-3:   #0A0F28;
    --marino-ink: #D2E3F3;
    --marino-ink-2: #93AFD8;

    /* El azul vivo del manual y sus pasos. Reemplaza al antiguo acento frío:
       la identidad de Avalia es azul, no turquesa. */
    --vivo:       #004CB9;
    --vivo-2:     #003C93;
    --vivo-3:     #4C8AE0;
    --vivo-piso:  #D2E3F3;

    --bien:       #0CA30C;

    --display: "League Spartan", "Segoe UI", system-ui, sans-serif;
    --sans: "Work Sans", "Segoe UI", system-ui, -apple-system, sans-serif;
    --mono: ui-monospace, "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace;
}

h1, h2, h3, h4 {
    font-family: var(--display);
    letter-spacing: -.02em;
    text-wrap: balance;
}

/* --------------------------------------------------------------- justificado
   El texto corrido va justificado a ambos márgenes.

   Con `hyphens: auto` y no sin él: justificar sin partir palabras estira los
   espacios hasta abrir «ríos» blancos verticales entre líneas, y en español
   —de palabras largas— el efecto es peor que el borde irregular que se quería
   evitar. La partición necesita que el documento declare su idioma, y <html>
   ya trae lang="es".

   Se aplica al texto largo, NO a titulares, botones ni etiquetas: en una línea
   suelta el justificado solo separa dos palabras a lo ancho de la pantalla. */
p, li, dd, .plomo, .tarj p, .valor p, .propuesta p, .nos-caja p, .calidad-lista li {
    text-align: justify;
    hyphens: auto;
    -webkit-hyphens: auto;
    /* Nunca partir una palabra dejando menos de tres letras a un lado: «lo-
       gística» es peor que la línea corta que evita. */
    hyphenate-limit-chars: 6 3 3;
}

/* Las excepciones. Aquí el justificado estorba en vez de ayudar.

   `.plomo` sale entero: son párrafos de entrada que suelen ir en columna
   estrecha, y justificar una medida corta abre «ríos» de espacio en blanco
   entre palabras —se ve peor que el borde irregular que se quería quitar—.
   El justificado necesita renglones largos para repartir el sobrante. */
.rotulo,
.plomo,
.pie p,
figcaption,
label,
.btn,
th, td {
    text-align: left;
    hyphens: manual;
}

* { box-sizing: border-box; }

body {
    margin: 0; background: var(--papel); color: var(--tinta);
    font-family: var(--sans); font-size: 16px; line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

img { max-width: 100%; }
a { color: var(--marino-3); }

.env { width: 100%; max-width: 1160px; margin: 0 auto; padding: 0 1.5rem; }

.rotulo {
    font-family: var(--mono); font-size: .68rem; font-weight: 500;
    letter-spacing: .18em; text-transform: uppercase; color: var(--vivo-2);
    margin: 0 0 .8rem;
}
.rotulo--claro { color: var(--marino-ink-2); }

h2 {
    margin: 0 0 .8rem; font-size: clamp(1.7rem, 3.6vw, 2.5rem);
    font-weight: 700; letter-spacing: -.035em; line-height: 1.08; text-wrap: balance;
}
h3 { margin: 0 0 .4rem; font-size: 1.05rem; font-weight: 600; letter-spacing: -.018em; }
p { margin: 0 0 1rem; }
.plomo { font-size: 1.06rem; color: var(--tinta-2); max-width: 60ch; line-height: 1.65; }
.plomo--claro { color: var(--marino-ink); }

/* Marcador de dato que Avalia debe reemplazar. Se ve a propósito: un dato
   inventado en una página comercial es peor que un hueco visible. */
.dato {
    background: #FFF4CE; color: #7A5A00; border-bottom: 1px solid #E0C36A;
    padding: 0 .22em; font-weight: 600; white-space: nowrap;
}

/* ------------------------------------------------------------------ botones */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    height: 50px; padding: 0 1.5rem; border-radius: 8px;
    font-family: var(--sans); font-size: .95rem; font-weight: 600;
    text-decoration: none; border: 1px solid transparent; cursor: pointer;
    transition: transform .12s ease, background .12s ease;
}
.btn:hover { transform: translateY(-1px); }
.btn--pri { background: var(--vivo); color: #FFFFFF; border-color: var(--vivo); }
.btn--pri:hover { background: var(--vivo-2); border-color: var(--vivo-2); }
.btn--marino { background: var(--marino-3); color: #FFFFFF; border-color: var(--marino-3); }
.btn--marino:hover { background: var(--marino); border-color: var(--marino); }
.btn--linea { background: transparent; color: var(--tinta); border-color: var(--linea-2); }
.btn--linea:hover { border-color: var(--tinta); }
.btn--vidrio { background: rgba(255,255,255,.1); color: #FFFFFF; border-color: rgba(255,255,255,.3); }
.btn--vidrio:hover { background: rgba(255,255,255,.18); }
.btn--sm { height: 42px; padding: 0 1.1rem; font-size: .85rem; }

/* --------------------------------------------------------------------- nav */
.nav {
    position: sticky; top: 0; z-index: 40;
    background: rgba(255,255,255,.92); backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--linea);
}
.nav-int { display: flex; align-items: center; gap: 2rem; height: 74px; }
.logo { display: flex; align-items: center; text-decoration: none; color: var(--marino); flex: none; }

/* El logotipo va incrustado, no como <img>, porque la pieza se pinta con
   `currentColor`: dentro de una imagen ese color no hereda nada y saldría
   negro. Incrustado toma el color de quien lo contiene, y así el MISMO archivo
   sirve en la barra clara y en el pie oscuro.

   Solo se fija el ancho. La altura la calcula el navegador con el viewBox, que
   lleva la proporción exacta del original: fijar las dos medidas es lo que
   deforma un logotipo. */
.logo-pieza { display: block; width: 176px; height: auto; }
.logo-pieza svg { display: block; width: 100%; height: auto; }

@media (max-width: 560px) { .logo-pieza { width: 142px; } }
/* ---------------------------------------------------- secciones de identidad
   Quiénes somos, valores, propuesta de valor y política de calidad. Contenido
   institucional: el trabajo del diseño aquí es que se lea, no que compita. */

.nos-int { display: grid; grid-template-columns: 1.05fr 1fr; gap: clamp(2rem, 5vw, 4rem); align-items: start; }
.nos-int > div > p { color: var(--tinta-2); max-width: 54ch; }
.nos-par { display: grid; gap: 1rem; }

.nos-caja { padding: 1.5rem 1.6rem; border-radius: 14px; background: var(--marino); color: #FFFFFF; }
.nos-caja h3 { margin: 0 0 .55rem; font-size: 1.15rem; color: #FFFFFF; }
.nos-caja p  { margin: 0; font-size: .93rem; line-height: 1.65; color: var(--marino-ink); }

/* La visión en claro y la misión en oscuro: son dos afirmaciones distintas —una
   sobre hoy, otra sobre 2030— y el contraste entre las cajas lo dice sin texto. */
.nos-caja--clara { background: var(--vivo-piso); color: var(--tinta); }
.nos-caja--clara h3 { color: var(--marino); }
.nos-caja--clara p  { color: var(--tinta-2); }

.valores { gap: 1rem; }
.valor {
    padding: 1.35rem 1.45rem; border-radius: 12px;
    background: var(--papel); border: 1px solid var(--linea);
}
.valor h3 { margin: 0 0 .4rem; font-size: 1rem; color: var(--vivo); }
.valor p  { margin: 0; font-size: .89rem; line-height: 1.6; color: var(--tinta-2); }

.sec-cab--osc h2 { color: #FFFFFF; }
.sec-cab--osc .rotulo { color: var(--marino-ink); }

.propuesta { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 1.4rem 2.4rem; }
.propuesta li { display: flex; gap: 1rem; align-items: flex-start; }
.propuesta h3 { margin: 0 0 .25rem; font-size: 1.02rem; color: #FFFFFF; }
.propuesta p  { margin: 0; font-size: .9rem; line-height: 1.6; color: var(--marino-ink); }
.propuesta-n {
    flex: none; width: 40px; height: 40px; display: grid; place-items: center;
    border-radius: 50%; border: 1.5px solid rgba(255,255,255,.35);
    font-family: var(--display); font-size: .92rem; font-weight: 700;
    color: #FFFFFF; font-variant-numeric: tabular-nums;
}

.calidad { max-width: 62ch; margin: 0 auto; }
.calidad-plomo { font-size: 1.04rem; line-height: 1.7; color: var(--tinta); font-weight: 500; }
.calidad-lista { list-style: none; margin: 1.6rem 0 0; padding: 0; display: grid; gap: 1rem; }
.calidad-lista li {
    position: relative; padding-left: 1.9rem;
    font-size: .95rem; line-height: 1.65; color: var(--tinta-2);
}
.calidad-lista li::before {
    content: ""; position: absolute; left: 0; top: .48em;
    width: 11px; height: 11px; border-radius: 50%; background: var(--vivo);
}

@media (max-width: 860px) {
    .nos-int { grid-template-columns: 1fr; }
    .propuesta { grid-template-columns: 1fr; }
}

.nav-links { display: flex; align-items: center; gap: 1.5rem; margin-left: auto; }
.nav-links a { font-size: .88rem; font-weight: 500; color: var(--tinta-2); text-decoration: none; white-space: nowrap; }
.nav-links a:hover { color: var(--tinta); }
.nav-acc { display: flex; align-items: center; gap: .6rem; flex: none; }
@media (max-width: 1020px) { .nav-links { display: none; } .nav-acc { margin-left: auto; } }

/* -------------------------------------------------------------------- hero */
.hero {
    background:
        radial-gradient(120% 80% at 82% 0%, var(--marino-2) 0%, transparent 58%),
        linear-gradient(160deg, #0A2450 0%, var(--marino) 72%);
    color: #FFFFFF; overflow: hidden;
}
.hero-int { display: grid; grid-template-columns: 1.08fr .92fr; gap: 3.5rem; align-items: center; padding: 5rem 0 5.5rem; }
.hero h1 {
    margin: 0 0 1.2rem; font-size: clamp(2.3rem, 5.4vw, 3.7rem);
    font-weight: 800; letter-spacing: -.045em; line-height: 1.02; text-wrap: balance;
}
.hero h1 em { font-style: normal; color: var(--vivo-3); }
.hero-cta { display: flex; flex-wrap: wrap; gap: .7rem; margin-top: 1.9rem; }
.hero-nota { display: flex; align-items: center; gap: .5rem; margin-top: 1.6rem; font-size: .82rem; color: var(--marino-ink-2); }

.termo { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.16); border-radius: 16px; padding: 1.3rem 1.4rem; }
.termo-cab { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; margin-bottom: .2rem; }
.termo-guia { font-family: var(--mono); font-size: .84rem; font-weight: 600; }
.termo-pill {
    font-family: var(--mono); font-size: .6rem; letter-spacing: .08em; text-transform: uppercase;
    padding: .2rem .5rem; border-radius: 999px;
    background: rgba(12,163,12,.2); color: #6FD46F; border: 1px solid rgba(12,163,12,.42);
}
.termo-pie { display: flex; gap: 1.6rem; margin-top: .9rem; padding-top: .9rem; border-top: 1px solid rgba(255,255,255,.14); }
.termo-dato p { margin: 0; }
.termo-dato .k { font-family: var(--mono); font-size: .56rem; letter-spacing: .12em; text-transform: uppercase; color: var(--marino-ink-2); }
.termo-dato .v { font-size: 1.2rem; font-weight: 700; letter-spacing: -.03em; font-variant-numeric: tabular-nums; margin-top: .1rem; }

@media (max-width: 900px) { .hero-int { grid-template-columns: 1fr; gap: 2.5rem; padding: 3.5rem 0 4rem; } }

/* ------------------------------------------------------------------ cifras */
.cifras { background: var(--marino-3); color: #FFFFFF; }
.cifras-int { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1px; background: rgba(255,255,255,.16); }
.cifra { background: var(--marino-3); padding: 1.7rem 1.5rem; }
.cifra .v { font-size: 2rem; font-weight: 800; letter-spacing: -.04em; line-height: 1; font-variant-numeric: tabular-nums; }
.cifra .k { margin: .45rem 0 0; font-size: .82rem; color: var(--marino-ink); line-height: 1.4; }
@media (max-width: 760px) { .cifras-int { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

/* --------------------------------------------------------------- secciones */
section { padding: 5rem 0; }
.sec-gris { background: var(--papel-2); }
.sec-marino { background: var(--marino); color: #FFFFFF; }
.sec-cab { max-width: 62ch; margin-bottom: 2.6rem; }

.rejilla-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.2rem; }
.rejilla-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.2rem; }
@media (max-width: 900px) { .rejilla-3, .rejilla-2 { grid-template-columns: 1fr; } }

.tarj { background: var(--papel); border: 1px solid var(--linea); border-radius: 14px; padding: 1.5rem 1.6rem; display: flex; flex-direction: column; }
.tarj-ico { width: 44px; height: 44px; border-radius: 11px; flex: none; background: var(--vivo-piso); display: grid; place-items: center; margin-bottom: 1rem; }
.tarj p { font-size: .9rem; color: var(--tinta-2); margin: 0; line-height: 1.6; }
.tarj-lista { margin: .9rem 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: .35rem; }
.tarj-lista li { display: flex; gap: .5rem; font-size: .84rem; color: var(--tinta-2); }
.tarj-lista svg { flex: none; margin-top: .28rem; }

/* -------------------------------------------------------------- carrusel
   Desplazamiento con anclaje: funciona con dedo, con rueda y con los botones,
   y sigue funcionando si el guion no carga. El JS solo añade los botones y los
   puntos; no es lo que hace que el carrusel exista. */
.carrusel { position: relative; }
.carrusel-pista {
    display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr);
    gap: 1rem; overflow-x: auto; scroll-snap-type: x mandatory;
    scrollbar-width: none; padding-bottom: .3rem;
}
.carrusel-pista::-webkit-scrollbar { display: none; }
@media (max-width: 900px) { .carrusel-pista { grid-auto-columns: 88%; } }

.lamina {
    scroll-snap-align: start; border-radius: 14px; overflow: hidden;
    border: 1px solid var(--linea); background: var(--papel);
}
.lamina figcaption { padding: .9rem 1.1rem; }
.lamina h3 { margin-bottom: .2rem; font-size: .98rem; }
.lamina p { margin: 0; font-size: .84rem; color: var(--tinta-2); line-height: 1.55; }
.lamina-marca {
    display: inline-flex; align-items: center; gap: .3rem; margin-top: .6rem;
    font-family: var(--mono); font-size: .58rem; letter-spacing: .08em; text-transform: uppercase;
    color: #7A5A00; background: #FFF4CE; border: 1px solid #E0C36A;
    border-radius: 4px; padding: .1rem .4rem;
}

.carrusel-mandos { display: flex; align-items: center; gap: .6rem; margin-top: 1.1rem; }
.carrusel-btn {
    width: 42px; height: 42px; border-radius: 999px; flex: none;
    border: 1px solid var(--linea-2); background: var(--papel);
    display: grid; place-items: center; cursor: pointer; color: var(--tinta);
}
.carrusel-btn:hover { border-color: var(--tinta); }
.carrusel-btn:disabled { opacity: .35; cursor: default; }
.carrusel-puntos { display: flex; gap: .4rem; margin-left: .4rem; }
.carrusel-punto { width: 7px; height: 7px; border-radius: 999px; background: var(--linea-2); border: none; padding: 0; cursor: pointer; }
.carrusel-punto[aria-current="true"] { background: var(--vivo); width: 22px; }

/* ---------------------------------------------------------------- aliados */
.aliados { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 1px; background: var(--linea); border: 1px solid var(--linea); border-radius: 14px; overflow: hidden; }
@media (max-width: 900px) { .aliados { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 520px) { .aliados { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.aliado { background: var(--papel); aspect-ratio: 3 / 2; display: grid; place-items: center; padding: 1rem; }

.testimonio {
    background: var(--papel); border: 1px solid var(--linea); border-radius: 14px;
    padding: 1.6rem 1.7rem; display: flex; flex-direction: column;
}
.testimonio blockquote { margin: 0; font-size: 1rem; line-height: 1.65; color: var(--tinta); }
.testimonio footer { margin-top: 1.1rem; display: flex; align-items: center; gap: .7rem; }
.testimonio-av { width: 40px; height: 40px; border-radius: 999px; flex: none; background: var(--marino-3); color: #FFFFFF; display: grid; place-items: center; font-size: .8rem; font-weight: 600; }

/* -------------------------------------------------------- cadena de frío */
.frio-int { display: grid; grid-template-columns: .95fr 1.05fr; gap: 3.5rem; align-items: center; }
@media (max-width: 900px) { .frio-int { grid-template-columns: 1fr; gap: 2.5rem; } }

.dictamen { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.16); border-radius: 16px; overflow: hidden; }
.dictamen-cab { padding: 1.1rem 1.3rem; background: rgba(12,163,12,.14); border-bottom: 1px solid rgba(255,255,255,.14); }
.dictamen-cuerpo { padding: 1.2rem 1.3rem; }
.dictamen-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.2rem; }
.dictamen-grid .k { font-family: var(--mono); font-size: .56rem; letter-spacing: .1em; text-transform: uppercase; color: var(--marino-ink-2); margin: 0; }
.dictamen-grid .v { font-size: 1.3rem; font-weight: 700; letter-spacing: -.03em; margin: .12rem 0 0; font-variant-numeric: tabular-nums; }

.pasos-frio { margin: 1.6rem 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 1.1rem; }
.paso-frio { display: grid; grid-template-columns: 34px 1fr; gap: .9rem; }
.paso-frio-n {
    width: 34px; height: 34px; border-radius: 999px; display: grid; place-items: center;
    border: 1px solid rgba(255,255,255,.28); background: rgba(255,255,255,.07);
    font-family: var(--mono); font-size: .78rem; font-weight: 600;
}
.paso-frio h3 { color: #FFFFFF; margin-bottom: .2rem; }
.paso-frio p { margin: 0; font-size: .88rem; color: var(--marino-ink); line-height: 1.55; }

/* ------------------------------------------------------------ trazabilidad */
.prueba { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.2rem; margin-top: 2rem; }
@media (max-width: 900px) { .prueba { grid-template-columns: 1fr; } }
.prueba-item { border-top: 3px solid var(--vivo); padding-top: 1.1rem; }
.prueba-item h3 { margin-bottom: .35rem; }
.prueba-item p { font-size: .9rem; color: var(--tinta-2); margin: 0; }

/* ------------------------------------------------------------- integración */
.codigo {
    background: #071022; border: 1px solid #1B2942; border-radius: 12px;
    padding: 1.2rem 1.3rem; overflow-x: auto;
    font-family: var(--mono); font-size: .78rem; line-height: 1.75; color: #D6E2F5;
}
.codigo .c-cad { color: #8FD3AE; }
.codigo .c-cla { color: #8FB8E8; }
.codigo .c-not { color: #6E7C96; }

/* ---------------------------------------------------------------- cobertura */
.ciudades { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1.4rem; }
.ciudad { font-size: .86rem; font-weight: 500; padding: .45rem .9rem; border-radius: 999px; border: 1px solid var(--linea-2); color: var(--tinta-2); background: var(--papel); }

.norma { display: flex; gap: .8rem; padding: 1.1rem 0; border-bottom: 1px solid var(--linea); }
.norma:last-child { border-bottom: none; }
.norma h3 { margin-bottom: .2rem; font-size: .95rem; }
.norma p { margin: 0; font-size: .86rem; color: var(--tinta-2); }

/* ------------------------------------------------------------------ pasos */
.pasos { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1.4rem; counter-reset: p; }
@media (max-width: 900px) { .pasos { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pasos { grid-template-columns: 1fr; } }
.paso { counter-increment: p; }
.paso::before {
    content: counter(p, decimal-leading-zero);
    display: block; font-family: var(--mono); font-size: .8rem; font-weight: 600;
    color: var(--vivo-2); margin-bottom: .6rem;
}
.paso h3 { margin-bottom: .3rem; }
.paso p { font-size: .88rem; color: var(--tinta-2); margin: 0; }

/* --------------------------------------------------------- formularios */
.cot-int { display: grid; grid-template-columns: .9fr 1.1fr; gap: 3.5rem; align-items: start; }
@media (max-width: 900px) { .cot-int { grid-template-columns: 1fr; gap: 2.2rem; } }

.forma { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.campo { display: flex; flex-direction: column; gap: .35rem; }
.campo--ancho { grid-column: 1 / -1; }
.campo label { font-family: var(--mono); font-size: .6rem; letter-spacing: .12em; text-transform: uppercase; color: var(--tinta-3); }
.campo input, .campo select, .campo textarea {
    height: 48px; padding: 0 .9rem; border-radius: 8px;
    border: 1px solid var(--linea-2); background: var(--papel);
    font-family: var(--sans); font-size: .95rem; color: var(--tinta); width: 100%;
}
.campo textarea { height: 108px; padding: .7rem .9rem; resize: vertical; line-height: 1.55; }
.campo input[type="file"] { padding: .6rem .9rem; height: auto; font-size: .85rem; }
/* Mientras nadie ha elegido, el campo se ve como lo que es: vacío. */
.campo select:invalid { color: var(--tinta-3); }
.campo input:focus-visible, .campo select:focus-visible, .campo textarea:focus-visible { outline: 2px solid var(--vivo); outline-offset: 1px; border-color: var(--vivo); }
.forma-pie { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; }
.forma-nota { font-size: .76rem; color: var(--tinta-3); line-height: 1.5; max-width: 46ch; margin: 0; }
.forma-aviso { grid-column: 1 / -1; margin: 0; font-size: .82rem; min-height: 1.2em; color: var(--vivo-2); font-weight: 600; }

.contacto-directo { display: flex; flex-direction: column; gap: 1.2rem; margin-top: 1.8rem; }
.contacto-fila { display: flex; gap: .8rem; align-items: flex-start; }
.contacto-fila .k { font-family: var(--mono); font-size: .58rem; letter-spacing: .12em; text-transform: uppercase; color: var(--tinta-3); margin: 0; }
.contacto-fila .v { font-size: 1rem; font-weight: 600; margin: .1rem 0 0; }

/* --------------------------------------------------------------------- pie */
.pie { background: var(--marino); color: var(--marino-ink); padding: 3.5rem 0 2rem; }
.pie-int { display: grid; grid-template-columns: 1.6fr 1fr 1fr 1fr; gap: 2rem; }
@media (max-width: 900px) { .pie-int { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .pie-int { grid-template-columns: 1fr; } }
.pie h4 { margin: 0 0 .9rem; font-family: var(--mono); font-size: .6rem; letter-spacing: .14em; text-transform: uppercase; color: var(--marino-ink-2); font-weight: 500; }
.pie ul { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: .5rem; }
.pie a { color: var(--marino-ink); text-decoration: none; font-size: .88rem; }
.pie a:hover { color: #FFFFFF; }
.pie-legal { margin-top: 2.8rem; padding-top: 1.5rem; border-top: 1px solid rgba(255,255,255,.14); display: flex; flex-wrap: wrap; gap: .8rem 2rem; justify-content: space-between; font-size: .76rem; color: var(--marino-ink-2); }

@media (prefers-reduced-motion: reduce) {
    .btn:hover { transform: none; }
    .carrusel-pista { scroll-behavior: auto; }
}
</style>
</head>
<body>

<!-- ==================================================================== nav -->
<nav class="nav">
  <div class="env nav-int">
    <a class="logo" href="#inicio" aria-label="Avalia Logística — inicio">
      <span class="logo-pieza">{!! file_get_contents(public_path('marca/avalia-principal.svg')) !!}</span>
    </a>

    <div class="nav-links">
      <a href="#nosotros">Nosotros</a>
      <a href="#servicios">Servicios</a>
      <a href="#frio">Cadena de frío</a>
      <a href="#trazabilidad">Trazabilidad</a>
      <a href="#calidad">Calidad</a>
      <a href="#integracion">Integraciones</a>
      <a href="#pqrs">PQRS</a>
    </div>

    <div class="nav-acc">
      <!-- La llamada al software. Apunta a la ruta real de la plataforma; si el
           portal vive en otro dominio, aquí va esa dirección. -->
      <a class="btn btn--linea btn--sm" href="{{ url('/ingreso') }}" data-plataforma>Portal de clientes</a>
      <a class="btn btn--marino btn--sm" href="#cotizar">Cotizar</a>
    </div>
  </div>
</nav>

<!-- =================================================================== hero -->
<header class="hero" id="inicio">
  <div class="env hero-int">
    <div>
      <p class="rotulo rotulo--claro">Transporte de medicamentos · Colombia</p>
      <h1>Sus medicamentos llegan.<br>Y podemos <em>probarlo</em>.</h1>
      <p class="plomo plomo--claro">
        Transportamos medicamentos, biológicos y dispositivos médicos con cadena de frío
        monitoreada y evidencia obligatoria en cada entrega. Cada envío deja un soporte que
        su auditoría puede verificar por su cuenta, sin depender de nosotros.
      </p>
      <div class="hero-cta">
        <a class="btn btn--pri" href="#cotizar">Solicitar una cotización</a>
        <a class="btn btn--vidrio" href="{{ url('/rastreo') }}" data-plataforma>Rastrear un envío</a>
      </div>
      <p class="hero-nota">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#4C8AE0" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
        Bitácora de solo anexado · cada entrega verificable por terceros
      </p>
    </div>

    <div class="termo">
      <div class="termo-cab">
        <span class="termo-guia">AVL-260825-0001</span>
        <span class="termo-pill">Producto apto</span>
      </div>
      <p style="margin: 0; font-size: .78rem; color: var(--marino-ink-2)">Perfil térmico del trayecto · rango exigido 2 – 8 °C</p>

      <svg viewBox="0 0 420 150" style="width: 100%; height: auto; display: block; margin-top: .8rem" role="img" aria-label="Perfil de temperatura del trayecto: se mantuvo dentro del rango exigido de 2 a 8 grados">
        <rect x="34" y="34" width="374" height="76" fill="rgba(79,216,232,.12)"></rect>
        <line x1="34" y1="34" x2="408" y2="34" stroke="#4C8AE0" stroke-width="1" stroke-dasharray="4 4" opacity=".55"></line>
        <line x1="34" y1="110" x2="408" y2="110" stroke="#4C8AE0" stroke-width="1" stroke-dasharray="4 4" opacity=".55"></line>
        <text x="26" y="38" text-anchor="end" font-family="ui-monospace, Consolas, monospace" font-size="10" fill="#93AFD8">8°</text>
        <text x="26" y="114" text-anchor="end" font-family="ui-monospace, Consolas, monospace" font-size="10" fill="#93AFD8">2°</text>
        <path d="M34 86 L86 79 L138 74 L190 84 L242 70 L294 77 L346 66 L408 72" fill="none" stroke="#4C8AE0" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"></path>
        <circle cx="34" cy="86" r="4" fill="#4C8AE0" stroke="#0A2450" stroke-width="2"></circle>
        <circle cx="408" cy="72" r="4" fill="#4C8AE0" stroke="#0A2450" stroke-width="2"></circle>
        <text x="34" y="134" font-family="ui-monospace, Consolas, monospace" font-size="9" fill="#93AFD8">Recolección</text>
        <text x="408" y="134" text-anchor="end" font-family="ui-monospace, Consolas, monospace" font-size="9" fill="#93AFD8">Entrega</text>
      </svg>

      <div class="termo-pie">
        <div class="termo-dato"><p class="k">MKT</p><p class="v">4,73 °C</p></div>
        <div class="termo-dato"><p class="k">Fuera de rango</p><p class="v">0 min</p></div>
        <div class="termo-dato"><p class="k">Lecturas</p><p class="v">23</p></div>
      </div>
    </div>
  </div>
</header>

<!-- ================================================================= cifras -->
<section class="cifras" style="padding: 0">
  <div class="env">
    <div class="cifras-int">
      <div class="cifra"><div class="v"><span class="dato">[00.0 %]</span></div><p class="k">Entregas dentro de la ventana comprometida</p></div>
      <div class="cifra"><div class="v"><span class="dato">[00.000]</span></div><p class="k">Envíos con cadena de frío al año</p></div>
      <div class="cifra"><div class="v">100 %</div><p class="k">Entregas con foto y firma del receptor</p></div>
      <div class="cifra"><div class="v"><span class="dato">[00]</span></div><p class="k">Años transportando producto farmacéutico</p></div>
    </div>
  </div>
</section>

<!-- =============================================================== carrusel -->
<section>
  <div class="env">
    <div class="sec-cab">
      <p class="rotulo">La operación</p>
      <h2>Cómo se ve el trabajo por dentro</h2>
      <p class="plomo">
        Vehículos con cava certificada, sondas que reportan solas, un centro de distribución
        que consolida y entregas hasta la puerta del paciente.
      </p>
    </div>

    <div class="carrusel" data-carrusel>
      <div class="carrusel-pista" data-pista tabindex="0" role="group" aria-label="Imágenes de la operación">

        <figure class="lamina" style="margin: 0">
          <svg viewBox="0 0 480 300" style="width: 100%; display: block" role="img" aria-label="Ilustración de un vehículo refrigerado en ruta">
            <rect width="480" height="300" fill="#141C45"></rect>
            <circle cx="392" cy="62" r="34" fill="#17499B" opacity=".55"></circle>
            <path d="M0 214h480" stroke="#0A2450" stroke-width="34"></path>
            <path d="M0 214h480" stroke="#93AFD8" stroke-width="2" stroke-dasharray="26 20" opacity=".5"></path>
            <g opacity=".3">
              <rect x="24" y="132" width="34" height="66" fill="#0D1333"></rect>
              <rect x="70" y="112" width="26" height="86" fill="#0D1333"></rect>
              <rect x="108" y="146" width="40" height="52" fill="#0D1333"></rect>
              <rect x="352" y="120" width="30" height="78" fill="#0D1333"></rect>
              <rect x="394" y="150" width="46" height="48" fill="#0D1333"></rect>
            </g>
            <g>
              <rect x="150" y="112" width="146" height="74" rx="6" fill="#EAEEF6"></rect>
              <rect x="162" y="124" width="70" height="30" rx="3" fill="#4C8AE0" opacity=".35"></rect>
              <path d="M296 140h34l26 26v20h-60z" fill="#D9E3F1"></path>
              <rect x="304" y="146" width="24" height="18" rx="2" fill="#141C45" opacity=".6"></rect>
              <rect x="150" y="186" width="206" height="10" rx="3" fill="#C2CAD9"></rect>
              <circle cx="192" cy="200" r="16" fill="#0D1333"></circle><circle cx="192" cy="200" r="6" fill="#69738C"></circle>
              <circle cx="326" cy="200" r="16" fill="#0D1333"></circle><circle cx="326" cy="200" r="6" fill="#69738C"></circle>
              <g transform="translate(198 126)">
                <rect x="0" y="0" width="26" height="26" rx="4" fill="#004CB9"></rect>
                <path d="M13 5v16M6 9l7 4 7-4M6 17l7-4 7 4M4 13h18" stroke="#FFFFFF" stroke-width="1.6" stroke-linecap="round" fill="none"></path>
              </g>
              <text x="242" y="146" font-family="League Spartan, sans-serif" font-size="15" font-weight="700" fill="#141C45">AVALIA</text>
            </g>
          </svg>
          <figcaption>
            <h3>Flota con cava certificada</h3>
            <p>Vehículos habilitados para cadena de frío, con certificado vigente verificado por el sistema antes de cada asignación.</p>
            <span class="lamina-marca">Ilustración · reemplazar por foto real</span>
          </figcaption>
        </figure>

        <figure class="lamina" style="margin: 0">
          <svg viewBox="0 0 480 300" style="width: 100%; display: block" role="img" aria-label="Ilustración de monitoreo de temperatura en pantalla">
            <rect width="480" height="300" fill="#EAEEF6"></rect>
            <rect x="60" y="52" width="360" height="196" rx="12" fill="#FFFFFF" stroke="#C2CAD9"></rect>
            <rect x="60" y="52" width="360" height="34" rx="12" fill="#0A0F28"></rect>
            <rect x="60" y="74" width="360" height="12" fill="#0A0F28"></rect>
            <circle cx="80" cy="69" r="4" fill="#4C8AE0"></circle>
            <text x="96" y="74" font-family="ui-monospace, Consolas, monospace" font-size="11" fill="#D2E3F3">CADENA DE FRÍO · EN VIVO</text>
            <rect x="84" y="112" width="312" height="72" fill="#D2E3F3"></rect>
            <line x1="84" y1="112" x2="396" y2="112" stroke="#004CB9" stroke-dasharray="4 4" stroke-width="1"></line>
            <line x1="84" y1="184" x2="396" y2="184" stroke="#004CB9" stroke-dasharray="4 4" stroke-width="1"></line>
            <path d="M84 158 L136 150 L188 146 L240 156 L292 142 L344 150 L396 140" fill="none" stroke="#004CB9" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path>
            <circle cx="396" cy="140" r="5" fill="#004CB9" stroke="#FFFFFF" stroke-width="2"></circle>
            <text x="84" y="106" font-family="ui-monospace, Consolas, monospace" font-size="9" fill="#69738C">8 °C</text>
            <text x="84" y="200" font-family="ui-monospace, Consolas, monospace" font-size="9" fill="#69738C">2 °C</text>
            <rect x="84" y="212" width="92" height="24" rx="6" fill="#E3F3E3"></rect>
            <text x="96" y="228" font-family="League Spartan, sans-serif" font-size="11" font-weight="600" fill="#0A7A0A">Dentro de rango</text>
            <text x="196" y="228" font-family="ui-monospace, Consolas, monospace" font-size="12" font-weight="600" fill="#0D1333">4.6 °C</text>
          </svg>
          <figcaption>
            <h3>Monitoreo continuo</h3>
            <p>Las sondas reportan temperatura y posición sin que el mensajero intervenga. Una excursión abre alerta al operador en el momento.</p>
            <span class="lamina-marca">Ilustración · reemplazar por foto real</span>
          </figcaption>
        </figure>

        <figure class="lamina" style="margin: 0">
          <svg viewBox="0 0 480 300" style="width: 100%; display: block" role="img" aria-label="Ilustración del centro de distribución">
            <rect width="480" height="300" fill="#F5F7FB"></rect>
            <rect x="0" y="232" width="480" height="68" fill="#D9E3F1"></rect>
            <g fill="#C2CAD9">
              <rect x="40" y="96" width="126" height="8"></rect><rect x="40" y="152" width="126" height="8"></rect>
              <rect x="40" y="208" width="126" height="8"></rect>
              <rect x="40" y="96" width="8" height="128"></rect><rect x="158" y="96" width="8" height="128"></rect>
              <rect x="314" y="96" width="126" height="8"></rect><rect x="314" y="152" width="126" height="8"></rect>
              <rect x="314" y="208" width="126" height="8"></rect>
              <rect x="314" y="96" width="8" height="128"></rect><rect x="432" y="96" width="8" height="128"></rect>
            </g>
            <g>
              <rect x="56" y="66" width="42" height="30" rx="2" fill="#EAEEF6" stroke="#D2E3F3"></rect>
              <rect x="106" y="70" width="42" height="26" rx="2" fill="#EAEEF6" stroke="#D2E3F3"></rect>
              <rect x="56" y="122" width="42" height="30" rx="2" fill="#004CB9" opacity=".22"></rect>
              <rect x="106" y="126" width="42" height="26" rx="2" fill="#EAEEF6" stroke="#D2E3F3"></rect>
              <rect x="330" y="70" width="42" height="26" rx="2" fill="#EAEEF6" stroke="#D2E3F3"></rect>
              <rect x="380" y="66" width="42" height="30" rx="2" fill="#004CB9" opacity=".22"></rect>
              <rect x="330" y="122" width="42" height="30" rx="2" fill="#EAEEF6" stroke="#D2E3F3"></rect>
            </g>
            <g transform="translate(206 128)">
              <rect x="0" y="0" width="72" height="72" rx="6" fill="#0A0F28"></rect>
              <path d="M36 14v44M18 24l18 10 18-10M18 48l18-10 18 10M12 36h48" stroke="#4C8AE0" stroke-width="2.4" stroke-linecap="round" fill="none"></path>
              <text x="36" y="92" text-anchor="middle" font-family="ui-monospace, Consolas, monospace" font-size="11" fill="#3D465F">CEDI</text>
            </g>
          </svg>
          <figcaption>
            <h3>Centro de distribución</h3>
            <p>Recibimos de varias sedes, consolidamos y redespachamos. Cada traspaso queda documentado como cambio de custodia.</p>
            <span class="lamina-marca">Ilustración · reemplazar por foto real</span>
          </figcaption>
        </figure>

        <figure class="lamina" style="margin: 0">
          <svg viewBox="0 0 480 300" style="width: 100%; display: block" role="img" aria-label="Ilustración de entrega domiciliaria">
            <rect width="480" height="300" fill="#EAEEF6"></rect>
            <rect x="0" y="240" width="480" height="60" fill="#D9E3F1"></rect>
            <path d="M120 240V118l80-52 80 52v122z" fill="#FFFFFF" stroke="#C2CAD9" stroke-width="2"></path>
            <path d="M104 122 200 58l96 64" fill="none" stroke="#0A0F28" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"></path>
            <rect x="176" y="164" width="48" height="76" rx="3" fill="#0A0F28"></rect>
            <circle cx="214" cy="204" r="3" fill="#4C8AE0"></circle>
            <rect x="140" y="140" width="30" height="30" rx="2" fill="#D2E3F3" stroke="#C2CAD9"></rect>
            <rect x="230" y="140" width="30" height="30" rx="2" fill="#D2E3F3" stroke="#C2CAD9"></rect>
            <g transform="translate(304 128)">
              <circle cx="26" cy="18" r="16" fill="#D2E3F3"></circle>
              <path d="M4 112V62a22 22 0 0 1 44 0v50z" fill="#0A0F28"></path>
              <rect x="44" y="56" width="52" height="42" rx="4" fill="#FFFFFF" stroke="#C2CAD9" stroke-width="2"></rect>
              <path d="M52 62h36M52 72h24" stroke="#C2CAD9" stroke-width="3" stroke-linecap="round"></path>
              <rect x="60" y="80" width="22" height="14" rx="2" fill="#004CB9"></rect>
            </g>
            <g transform="translate(56 168)">
              <rect x="0" y="0" width="46" height="46" rx="5" fill="#FFFFFF" stroke="#C2CAD9" stroke-width="2"></rect>
              <path d="M23 10v26M12 17l11 6 11-6M12 29l11-6 11 6" stroke="#004CB9" stroke-width="2" stroke-linecap="round" fill="none"></path>
            </g>
          </svg>
          <figcaption>
            <h3>Entrega al paciente</h3>
            <p>Hasta la puerta del afiliado, con aviso previo, ventana acordada y foto y firma de quien recibe.</p>
            <span class="lamina-marca">Ilustración · reemplazar por foto real</span>
          </figcaption>
        </figure>
      </div>

      <div class="carrusel-mandos">
        <button class="carrusel-btn" type="button" data-ant aria-label="Imagen anterior">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <button class="carrusel-btn" type="button" data-sig aria-label="Imagen siguiente">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </button>
        <div class="carrusel-puntos" data-puntos></div>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================== quiénes somos -->
{{-- Todo el texto de esta sección y de las tres siguientes es el del brochure
     corporativo, palabra por palabra. Es material aprobado: no se reescribe
     "para que suene mejor" ni se resume. --}}
<section id="nosotros">
  <div class="env">
    <div class="nos-int">
      <div>
        <p class="rotulo">Quiénes somos</p>
        <h2>El nombre dice lo que hacemos.</h2>
        <p>
          Avalia nace del concepto de <strong>aval</strong>: la garantía que respalda una
          operación y da la tranquilidad de que todo saldrá bien. En un sector donde el
          cumplimiento y la seriedad marcan la diferencia, elegimos ese principio como base
          de nuestra identidad.
        </p>
        <p>
          Avalia Logística se construye como una marca que no solo transporta mercancía:
          respalda cada entrega con compromiso, tecnología y cercanía, en carga terrestre,
          operación logística integral y última milla.
        </p>
      </div>

      <div class="nos-par">
        <article class="nos-caja">
          <h3>Misión</h3>
          <p>
            Conectar personas y destinos a través de soluciones integrales de transporte y
            logística respaldadas por tecnología, cumplimiento y un servicio cercano que
            convierte cada entrega en una prueba de confianza.
          </p>
        </article>
        <article class="nos-caja nos-caja--clara">
          <h3>Visión</h3>
          <p>
            Ser en 2030 el operador logístico referente del sector y reconocido por
            respaldar cada operación con innovación, cobertura nacional y un servicio que
            convierte la logística en una ventaja competitiva real para nuestros clientes.
          </p>
        </article>
      </div>
    </div>
  </div>
</section>

<!-- ================================================================= valores -->
<section class="sec-gris" id="valores">
  <div class="env">
    <div class="sec-cab">
      <p class="rotulo">Valores corporativos</p>
      <h2>Seis acuerdos que no se negocian</h2>
    </div>

    <div class="rejilla-3 valores">
      <article class="valor"><h3>Compromiso</h3><p>Cumplimos lo que prometemos y asumimos cada operación con responsabilidad.</p></article>
      <article class="valor"><h3>Confianza</h3><p>Construimos relaciones transparentes y duraderas con nuestros clientes, aliados y colaboradores.</p></article>
      <article class="valor"><h3>Eficiencia</h3><p>Buscamos hacer cada proceso de manera organizada, ágil y efectiva.</p></article>
      <article class="valor"><h3>Seguridad</h3><p>Protegemos las mercancías, la información y cada etapa de la operación.</p></article>
      <article class="valor"><h3>Integridad</h3><p>Actuamos con transparencia, ética y respeto por las normas y las personas.</p></article>
      <article class="valor"><h3>Innovación</h3><p>Mejoramos procesos y tecnología para operar con eficiencia.</p></article>
    </div>
  </div>
</section>

<!-- ====================================================== propuesta de valor -->
<section class="sec-marino" id="propuesta">
  <div class="env">
    <div class="sec-cab sec-cab--osc">
      <p class="rotulo">Propuesta de valor</p>
      <h2>Por qué un cliente nos deja su carga</h2>
    </div>

    {{-- La numeración es del brochure y significa algo: es el orden en que la
         empresa presenta su argumento, no adorno. --}}
    <ol class="propuesta">
      <li><span class="propuesta-n">01</span><div><h3>Respaldo real</h3><p>Garantía y seguimiento activo de cada envío, del origen al destino.</p></div></li>
      <li><span class="propuesta-n">02</span><div><h3>Tecnología y visibilidad</h3><p>Trazabilidad de la operación para que el cliente siempre sepa dónde está su carga.</p></div></li>
      <li><span class="propuesta-n">03</span><div><h3>Cobertura y flexibilidad</h3><p>Soluciones que crecen con el cliente, desde la pyme hasta la gran empresa.</p></div></li>
      <li><span class="propuesta-n">04</span><div><h3>Servicio cercano</h3><p>Atención humana y disponible antes, durante y después de cada entrega.</p></div></li>
    </ol>
  </div>
</section>

<!-- =============================================================== servicios -->
<section id="servicios">
  <div class="env">
    <div class="sec-cab">
      <p class="rotulo">Servicios</p>
      <h2>Tres líneas de servicio</h2>
    </div>

    <div class="rejilla-3">
      <article class="tarj">
        <span class="tarj-ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17h4V5H2v12h3"/><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg></span>
        <h3>Carga y transporte terrestre</h3>
        <p>Movilización de mercancía a nivel nacional y regional, con seguimiento y cumplimiento de tiempos pactados.</p>
      </article>

      <article class="tarj">
        <span class="tarj-ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="m5 6 7 4 7-4"/><path d="m5 18 7-4 7 4"/><path d="M2 12h20"/></svg></span>
        <h3>Logística especializada para el sector salud</h3>
        <p>Soluciones logísticas diseñadas para el manejo, transporte y distribución de productos del sector salud, priorizando la seguridad, trazabilidad y conservación de los productos durante cada etapa de la operación.</p>
      </article>

      <article class="tarj">
        <span class="tarj-ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5"/><path d="M9.5 21v-6h5v6"/></svg></span>
        <h3>Mensajería y última milla</h3>
        <p>Entregas urbanas ágiles para e-commerce y clientes finales, con visibilidad de principio a fin.</p>
      </article>
    </div>
  </div>
</section>

<!-- ============================================================= modalidades -->
<section class="sec-gris" id="modalidades">
  <div class="env">
    <div class="sec-cab">
      <p class="rotulo">Modalidades</p>
      <h2>Cinco formas de mover producto farmacéutico</h2>
      <p class="plomo">
        Cada modalidad exige controles distintos, y el sistema los impone: el tipo de envío
        decide qué vehículo puede tomarlo y qué tiene que registrar el mensajero antes de
        cerrarlo.
      </p>
    </div>

    <div class="rejilla-3">
      <article class="tarj">
        <span class="tarj-ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="m5 6 7 4 7-4"/><path d="m5 18 7-4 7 4"/><path d="M2 12h20"/></svg></span>
        <h3>Cadena de frío</h3>
        <p>Vacunas, biológicos, insulinas y reactivos entre 2 y 8 °C, o el rango que exija su producto.</p>
        <ul class="tarj-lista">
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Vehículos con certificado de frío vigente</li>
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Registro continuo de temperatura</li>
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Dictamen de aptitud en cada entrega</li>
        </ul>
      </article>

      <article class="tarj">
        <span class="tarj-ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>
        <h3>Medicamentos de control especial</h3>
        <p>Transporte con acta de custodia y mensajero certificado, según la normativa vigente.</p>
        <ul class="tarj-lista">
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Cadena de custodia documentada</li>
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Verificación de identidad del receptor</li>
        </ul>
      </article>

      <article class="tarj">
        <span class="tarj-ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5"/><path d="M9.5 21v-6h5v6"/></svg></span>
        <h3>Entrega domiciliaria</h3>
        <p>Directo a su afiliado o paciente, con ventana de atención acordada y aviso previo.</p>
        <ul class="tarj-lista">
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Confirmación al paciente antes de salir</li>
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Reintento agendado si no hay quien reciba</li>
        </ul>
      </article>

      <article class="tarj">
        <span class="tarj-ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3"/><path d="M16 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3"/><path d="M9 12h6"/><path d="m13 9 3 3-3 3"/></svg></span>
        <h3>Traslado entre sedes</h3>
        <p>Movimiento de inventario entre sus bodegas, farmacias y puntos de atención.</p>
        <ul class="tarj-lista">
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Recogidas programadas y recurrentes</li>
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Consolidación en centro de distribución</li>
        </ul>
      </article>

      <article class="tarj">
        <span class="tarj-ico"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9Z"/></svg></span>
        <h3>Urgencias</h3>
        <p>Despacho inmediato para lo que no puede esperar, con compromiso de tiempo en firme.</p>
        <ul class="tarj-lista">
          <li><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0CA30C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Asignación en minutos, no en horas</li>
        </ul>
      </article>

      <article class="tarj" style="background: var(--marino-3); border-color: var(--marino-3); color: #FFFFFF; justify-content: center">
        <h3 style="color: #FFFFFF; font-size: 1.15rem">¿Su caso no está aquí?</h3>
        <p style="color: var(--marino-ink)">Cuéntenos qué necesita mover y bajo qué condiciones. Diseñamos la operación con usted.</p>
        <a class="btn btn--vidrio btn--sm" href="#cotizar" style="margin-top: 1.1rem; align-self: flex-start">Hablemos</a>
      </article>
    </div>
  </div>
</section>

<!-- ============================================================ cadena frío -->
<section class="sec-marino" id="frio">
  <div class="env">
    <div class="frio-int">
      <div>
        <p class="rotulo rotulo--claro">Cadena de frío</p>
        <h2>No le decimos si hubo una excursión.<br>Le decimos si el producto sirve.</h2>
        <p class="plomo plomo--claro">
          Una alarma que dice «se salió del rango» no resuelve nada: su químico farmacéutico
          igual tiene que decidir si libera o rechaza el lote. Nosotros entregamos ese análisis
          hecho, con la norma detrás.
        </p>

        <ol class="pasos-frio">
          <li class="paso-frio"><span class="paso-frio-n">1</span>
            <div><h3>Registro continuo durante todo el trayecto</h3>
            <p>Sondas en el vehículo reportan temperatura y posición sin intervención del mensajero.</p></div>
          </li>
          <li class="paso-frio"><span class="paso-frio-n">2</span>
            <div><h3>Temperatura Cinética Media del viaje</h3>
            <p>La temperatura equivalente que el producto realmente sintió, ponderada por tiempo. Es el indicador de USP &lt;1079&gt; y del Anexo 9 de la OMS, no un promedio simple.</p></div>
          </li>
          <li class="paso-frio"><span class="paso-frio-n">3</span>
            <div><h3>Consumo del presupuesto de estabilidad</h3>
            <p>Cuántos minutos fuera de rango se acumularon contra los que su producto tolera.</p></div>
          </li>
          <li class="paso-frio"><span class="paso-frio-n">4</span>
            <div><h3>Dictamen en el soporte de entrega</h3>
            <p>Apto, requiere evaluación de Calidad, o no apto. Con el motivo escrito, no un código.</p></div>
          </li>
        </ol>
      </div>

      <div>
        <div class="dictamen">
          <div class="dictamen-cab">
            <div style="display: flex; align-items: center; gap: .55rem">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#6FD46F" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
              <strong style="font-size: 1.1rem; letter-spacing: -.02em">Producto apto</strong>
            </div>
            <p style="margin: .4rem 0 0; font-size: .84rem; color: var(--marino-ink)">Todo el trayecto transcurrió dentro del rango exigido de 2 – 8 °C.</p>
          </div>
          <div class="dictamen-cuerpo">
            <div class="dictamen-grid">
              <div><p class="k">MKT</p><p class="v">4,73 °C</p></div>
              <div><p class="k">Fuera de rango</p><p class="v">0 min</p></div>
              <div><p class="k">Presupuesto</p><p class="v">0 %</p></div>
            </div>
            <p style="margin: 1.1rem 0 0; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,.14); font-size: .8rem; color: var(--marino-ink-2); line-height: 1.55">
              El análisis distingue el calor del frío: una congelación breve puede arruinar un
              biológico y aun así dar un promedio impecable. Por eso se juzga aparte.
            </p>
          </div>
        </div>
        <p style="margin: 1.2rem 0 0; font-size: .8rem; color: var(--marino-ink-2); line-height: 1.6">
          El dictamen es un cálculo de apoyo. La decisión de liberar o rechazar producto
          corresponde a su área de Calidad y al titular del registro sanitario.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================== trazabilidad -->
<section id="trazabilidad">
  <div class="env">
    <div class="sec-cab">
      <p class="rotulo">Trazabilidad y evidencia</p>
      <h2>La prueba no depende de que usted nos crea</h2>
      <p class="plomo">
        Cada envío deja un rastro que un tercero puede verificar sin pedirnos permiso. Es la
        diferencia entre un sistema que dice qué pasó y uno que puede demostrarlo.
      </p>
    </div>

    <div class="prueba">
      <div class="prueba-item">
        <h3>Evidencia obligatoria</h3>
        <p>Foto del receptor y firma en todas las entregas, sin excepción. El mensajero no puede cerrar una entrega sin ellas: no es un recordatorio, es una condición del sistema.</p>
      </div>
      <div class="prueba-item">
        <h3>Bitácora inalterable</h3>
        <p>Cada evento encadena la huella digital del anterior y la jornada se cierra con un sello firmado. Modificar un registro rompería todos los siguientes de forma visible.</p>
      </div>
      <div class="prueba-item">
        <h3>Verificación abierta</h3>
        <p>Su auditor, su interventor o un revisor de INVIMA comprueba cualquier certificado en una página pública, con el código impreso en el documento. Sin cuenta y sin intermediarios.</p>
      </div>
    </div>

    <div style="margin-top: 2.6rem; display: grid; grid-template-columns: 1.1fr .9fr; gap: 2.5rem; align-items: center">
      <div>
        <h3 style="font-size: 1.2rem; margin-bottom: .6rem">Consulte por su propio número, no por el nuestro</h3>
        <p style="font-size: .95rem; color: var(--tinta-2); max-width: 52ch">
          Busque por su radicado, su orden de compra o su remisión — como lo tenga registrado
          en su sistema. No tiene que aprender nuestra numeración ni mantener una tabla de
          equivalencias.
        </p>
        <a class="btn btn--linea btn--sm" href="{{ url('/rastreo') }}" data-plataforma style="margin-top: 1.2rem">Probar el rastreo</a>
      </div>

      <div style="background: var(--papel-2); border: 1px solid var(--linea); border-radius: 14px; padding: 1.3rem 1.4rem">
        <p style="margin: 0 0 .8rem; font-family: var(--mono); font-size: .58rem; letter-spacing: .12em; text-transform: uppercase; color: var(--tinta-3)">Lo que trae cada soporte</p>
        <ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: .55rem">
          <li style="display: flex; gap: .55rem; font-size: .88rem; color: var(--tinta-2)"><svg width="14" height="14" style="flex:none;margin-top:.25rem" viewBox="0 0 24 24" fill="none" stroke="#004CB9" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Fecha y hora exactas de la entrega</li>
          <li style="display: flex; gap: .55rem; font-size: .88rem; color: var(--tinta-2)"><svg width="14" height="14" style="flex:none;margin-top:.25rem" viewBox="0 0 24 24" fill="none" stroke="#004CB9" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Quién recibió: nombre, documento y cargo</li>
          <li style="display: flex; gap: .55rem; font-size: .88rem; color: var(--tinta-2)"><svg width="14" height="14" style="flex:none;margin-top:.25rem" viewBox="0 0 24 24" fill="none" stroke="#004CB9" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Coordenada de la entrega y distancia al destino</li>
          <li style="display: flex; gap: .55rem; font-size: .88rem; color: var(--tinta-2)"><svg width="14" height="14" style="flex:none;margin-top:.25rem" viewBox="0 0 24 24" fill="none" stroke="#004CB9" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Fotos y firma, con huella SHA-256</li>
          <li style="display: flex; gap: .55rem; font-size: .88rem; color: var(--tinta-2)"><svg width="14" height="14" style="flex:none;margin-top:.25rem" viewBox="0 0 24 24" fill="none" stroke="#004CB9" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Perfil térmico completo, si es refrigerado</li>
          <li style="display: flex; gap: .55rem; font-size: .88rem; color: var(--tinta-2)"><svg width="14" height="14" style="flex:none;margin-top:.25rem" viewBox="0 0 24 24" fill="none" stroke="#004CB9" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg> Código para que un tercero lo verifique</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ================================================== aliados y clientes -->
<section class="sec-gris" id="clientes">
  <div class="env">
    <div class="sec-cab">
      <p class="rotulo">Clientes y aliados</p>
      <h2>Quiénes confían su cadena de frío a Avalia</h2>
      <p class="plomo">
        Trabajamos con clínicas, EPS, laboratorios y operadores logísticos que responden por
        producto regulado. Todos ellos pueden auditarnos sin pedirnos nada.
      </p>
    </div>

    <div class="aliados">
      <div class="aliado">
        <svg width="92" height="34" viewBox="0 0 92 34" role="img" aria-label="Logotipo de cliente, marcador"><rect width="92" height="34" rx="5" fill="#EAEEF6"></rect><circle cx="19" cy="17" r="8" fill="#C2CAD9"></circle><rect x="34" y="10" width="44" height="6" rx="3" fill="#C2CAD9"></rect><rect x="34" y="20" width="28" height="5" rx="2.5" fill="#D9E3F1"></rect></svg>
      </div>
      <div class="aliado">
        <svg width="92" height="34" viewBox="0 0 92 34" role="img" aria-label="Logotipo de cliente, marcador"><rect width="92" height="34" rx="5" fill="#EAEEF6"></rect><path d="M14 24 22 9l8 15z" fill="#C2CAD9"></path><rect x="38" y="11" width="40" height="6" rx="3" fill="#C2CAD9"></rect><rect x="38" y="21" width="24" height="5" rx="2.5" fill="#D9E3F1"></rect></svg>
      </div>
      <div class="aliado">
        <svg width="92" height="34" viewBox="0 0 92 34" role="img" aria-label="Logotipo de cliente, marcador"><rect width="92" height="34" rx="5" fill="#EAEEF6"></rect><rect x="12" y="9" width="16" height="16" rx="4" fill="#C2CAD9"></rect><rect x="36" y="10" width="42" height="6" rx="3" fill="#C2CAD9"></rect><rect x="36" y="20" width="32" height="5" rx="2.5" fill="#D9E3F1"></rect></svg>
      </div>
      <div class="aliado">
        <svg width="92" height="34" viewBox="0 0 92 34" role="img" aria-label="Logotipo de cliente, marcador"><rect width="92" height="34" rx="5" fill="#EAEEF6"></rect><path d="M20 8v18M11 17h18" stroke="#C2CAD9" stroke-width="5" stroke-linecap="round"></path><rect x="38" y="11" width="40" height="6" rx="3" fill="#C2CAD9"></rect><rect x="38" y="21" width="20" height="5" rx="2.5" fill="#D9E3F1"></rect></svg>
      </div>
      <div class="aliado">
        <svg width="92" height="34" viewBox="0 0 92 34" role="img" aria-label="Logotipo de cliente, marcador"><rect width="92" height="34" rx="5" fill="#EAEEF6"></rect><circle cx="16" cy="17" r="7" fill="#C2CAD9"></circle><circle cx="28" cy="17" r="7" fill="#D9E3F1"></circle><rect x="44" y="11" width="34" height="6" rx="3" fill="#C2CAD9"></rect><rect x="44" y="21" width="22" height="5" rx="2.5" fill="#D9E3F1"></rect></svg>
      </div>
      <div class="aliado">
        <svg width="92" height="34" viewBox="0 0 92 34" role="img" aria-label="Logotipo de cliente, marcador"><rect width="92" height="34" rx="5" fill="#EAEEF6"></rect><rect x="12" y="12" width="20" height="10" rx="5" fill="#C2CAD9"></rect><rect x="40" y="11" width="38" height="6" rx="3" fill="#C2CAD9"></rect><rect x="40" y="21" width="26" height="5" rx="2.5" fill="#D9E3F1"></rect></svg>
      </div>
    </div>

    <p style="margin: 1rem 0 0; font-size: .8rem; color: var(--tinta-3)">
      <span class="dato">[LOGOTIPOS DE CLIENTES]</span> — reemplazar por las marcas que autoricen aparecer.
    </p>

    <div class="rejilla-3" style="margin-top: 2.4rem">
      <article class="testimonio">
        <blockquote>«<span class="dato">[TESTIMONIO DEL CLIENTE]</span> — una frase concreta sobre qué problema les resolvió el servicio.»</blockquote>
        <footer>
          <span class="testimonio-av dato" style="background: #EAEEF6; color: #7A5A00">[ ]</span>
          <div>
            <div style="font-size: .88rem; font-weight: 600"><span class="dato">[NOMBRE]</span></div>
            <div style="font-size: .78rem; color: var(--tinta-3)"><span class="dato">[CARGO · INSTITUCIÓN]</span></div>
          </div>
        </footer>
      </article>

      <article class="testimonio">
        <blockquote>«<span class="dato">[TESTIMONIO DEL CLIENTE]</span> — idealmente con un dato medible: tiempos, reclamaciones, auditorías.»</blockquote>
        <footer>
          <span class="testimonio-av dato" style="background: #EAEEF6; color: #7A5A00">[ ]</span>
          <div>
            <div style="font-size: .88rem; font-weight: 600"><span class="dato">[NOMBRE]</span></div>
            <div style="font-size: .78rem; color: var(--tinta-3)"><span class="dato">[CARGO · INSTITUCIÓN]</span></div>
          </div>
        </footer>
      </article>

      <article class="testimonio" style="background: var(--marino-3); border-color: var(--marino-3)">
        <h3 style="color: #FFFFFF; font-size: 1.1rem">¿Quiere ser el siguiente?</h3>
        <p style="color: var(--marino-ink); font-size: .9rem; margin: .4rem 0 0; line-height: 1.6">
          Cuéntenos su operación y le mostramos, con un envío real, cómo queda el soporte que
          recibiría cada vez.
        </p>
        <a class="btn btn--vidrio btn--sm" href="#cotizar" style="margin-top: 1.2rem; align-self: flex-start">Agendar una demostración</a>
      </article>
    </div>
  </div>
</section>

<!-- ============================================================ integración -->
<section id="integracion">
  <div class="env">
    <div class="sec-cab">
      <p class="rotulo">Integraciones</p>
      <h2>Conecte su sistema y deje de escribir guías a mano</h2>
      <p class="plomo">
        Su ERP o sistema hospitalario radica, consulta y descarga soportes por API. Y si
        prefiere no integrar nada, el portal web y la carga por archivo hacen lo mismo.
      </p>
    </div>

    <div class="rejilla-2" style="align-items: start">
      <div>
        <div class="codigo">
<span class="c-not"># Consultar por SU propia referencia</span>
curl -H <span class="c-cad">"Authorization: Bearer $TOKEN"</span> \
     <span class="c-cad">"https://api.ltp.com.co/v1/rastreo?q=RAD-2608-00459"</span>

{
  <span class="c-cla">"encontrado"</span>: true,
  <span class="c-cla">"buscado_como"</span>: <span class="c-cad">"referencia"</span>,
  <span class="c-cla">"envios"</span>: [{
    <span class="c-cla">"guia_avalia"</span>: <span class="c-cad">"AVL-260825-0001"</span>,
    <span class="c-cla">"estado"</span>: <span class="c-cad">"entregado"</span>,
    <span class="c-cla">"entrega"</span>: { <span class="c-cla">"fecha"</span>: <span class="c-cad">"2026-08-25"</span>, <span class="c-cla">"hora"</span>: <span class="c-cad">"10:36:00"</span> }
  }]
}</div>
        <p style="margin: 1rem 0 0; font-size: .8rem; color: var(--tinta-3)">
          Dirección de ejemplo. La definitiva se entrega con el token de integración.
        </p>
      </div>

      <div style="display: flex; flex-direction: column; gap: 1rem">
        <article class="tarj" style="padding: 1.2rem 1.3rem"><h3>API REST versionada</h3><p>Radicar, rastrear, descargar soporte y exportar. Un contrato que no se rompe cuando cambiamos algo por dentro.</p></article>
        <article class="tarj" style="padding: 1.2rem 1.3rem"><h3>Carga por archivo</h3><p>Suba una planilla con doscientos afiliados y sus direcciones. Sin integrar nada.</p></article>
        <article class="tarj" style="padding: 1.2rem 1.3rem"><h3>Exportación lista para Excel</h3><p>Sus envíos con 28 columnas, separadas y con tildes correctas. La primera columna es su referencia, no la nuestra.</p></article>
        <article class="tarj" style="padding: 1.2rem 1.3rem"><h3>Portal para su equipo</h3><p>Sus farmacias radican y consultan directamente, cada una viendo solo lo suyo.</p></article>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================== cobertura -->
<section class="sec-gris">
  <div class="env">
    <div class="rejilla-2" style="gap: 3.5rem; align-items: start">
      <div>
        <p class="rotulo">Cobertura</p>
        <h2>Dónde operamos</h2>
        <p class="plomo">
          Operación propia en Cartagena y su área de influencia, y cobertura nacional a través
          de aliados con las mismas exigencias de trazabilidad.
        </p>
        <div class="ciudades">
          <span class="ciudad">Cartagena</span>
          <span class="ciudad">Bolívar</span>
          <span class="ciudad dato">[CIUDAD]</span>
          <span class="ciudad dato">[CIUDAD]</span>
          <span class="ciudad dato">[CIUDAD]</span>
          <span class="ciudad">Cobertura nacional</span>
        </div>
      </div>

      <div>
        <p class="rotulo">Cumplimiento</p>
        <h2 style="font-size: clamp(1.4rem, 2.6vw, 1.8rem)">Bajo qué reglas trabajamos</h2>
        <div style="margin-top: 1.4rem">
          <div class="norma">
            <svg width="19" height="19" style="flex:none;margin-top:.15rem" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></svg>
            <div><h3>Buenas prácticas de distribución</h3><p>Certificación <span class="dato">[NÚMERO Y VIGENCIA]</span> ante la autoridad sanitaria.</p></div>
          </div>
          <div class="norma">
            <svg width="19" height="19" style="flex:none;margin-top:.15rem" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V6a2 2 0 0 1 2-2h9l5 5v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z"/><path d="M15 4v5h5"/></svg>
            <div><h3>Medicamentos de control especial</h3><p>Habilitación <span class="dato">[NÚMERO Y VIGENCIA]</span> y personal certificado.</p></div>
          </div>
          <div class="norma">
            <svg width="19" height="19" style="flex:none;margin-top:.15rem" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <div><h3>Protección de datos personales</h3><p>Tratamiento conforme a la Ley 1581 de 2012. Los datos de pacientes se guardan con finalidad declarada y retención acotada.</p></div>
          </div>
          <div class="norma">
            <svg width="19" height="19" style="flex:none;margin-top:.15rem" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <div><h3>Acuerdos de nivel de servicio</h3><p>Tiempos de respuesta pactados por contrato y medidos, no estimados.</p></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================================================================== pasos -->
<section>
  <div class="env">
    <div class="sec-cab">
      <p class="rotulo">Cómo funciona</p>
      <h2>De la solicitud al soporte, en cuatro pasos</h2>
    </div>
    <div class="pasos">
      <div class="paso"><h3>Usted radica</h3><p>Desde el portal, por API o con una llamada. Indica origen, destino y qué lleva cada bulto.</p></div>
      <div class="paso"><h3>Asignamos</h3><p>El sistema elige mensajero y vehículo según el producto: sin cava certificada, un refrigerado no sale.</p></div>
      <div class="paso"><h3>Movemos y medimos</h3><p>Temperatura y posición se registran solas durante todo el trayecto.</p></div>
      <div class="paso"><h3>Entregamos con prueba</h3><p>Foto, firma, hora, coordenada y dictamen térmico, disponibles en su portal al instante.</p></div>
    </div>
  </div>
</section>

<!-- =============================================================== cotizar -->
<!-- ==================================================== política de calidad -->
<section id="calidad">
  <div class="env">
    <div class="calidad">
      <div class="sec-cab" style="margin-bottom: 1.6rem">
        <p class="rotulo">Política de calidad</p>
        <h2>A qué nos obligamos</h2>
      </div>

      <p class="calidad-plomo">
        En Avalia Logística nos comprometemos a prestar servicios de transporte, logística
        y mensajería que cumplan y superen las expectativas de nuestros clientes, mediante:
      </p>

      <ul class="calidad-lista">
        <li>La mejora continua de nuestros procesos operativos y de servicio.</li>
        <li>El cumplimiento de los requisitos legales, contractuales y normativos aplicables al sector transporte y logística.</li>
        <li>La gestión eficiente de nuestros recursos, flota y tecnología.</li>
        <li>El desarrollo permanente de las competencias de nuestro equipo humano.</li>
      </ul>
    </div>
  </div>
</section>

<section class="sec-gris" id="cotizar">
  <div class="env">
    <div class="cot-int">
      <div>
        <p class="rotulo">Hablemos</p>
        <h2>Cuéntenos qué necesita mover</h2>
        <p class="plomo">
          Le respondemos con una propuesta concreta: modalidad, tiempos comprometidos y tarifa.
          Sin rodeos y sin compromiso.
        </p>

        <div class="contacto-directo">
          <div class="contacto-fila">
            <svg width="19" height="19" style="flex:none;margin-top:.35rem" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.6a2 2 0 0 1-.5 2.1L8.1 9.6a16 16 0 0 0 6 6l1.2-1.1a2 2 0 0 1 2.1-.5c.8.3 1.7.6 2.6.7a2 2 0 0 1 1.7 2Z"/></svg>
            <div><p class="k">Línea de atención</p><p class="v dato">[605 000 0000]</p></div>
          </div>
          <div class="contacto-fila">
            <svg width="19" height="19" style="flex:none;margin-top:.35rem" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>
            <div><p class="k">Correo</p><p class="v">info@ltp.com</p></div>
          </div>
          <div class="contacto-fila">
            <svg width="19" height="19" style="flex:none;margin-top:.35rem" viewBox="0 0 24 24" fill="none" stroke="#003C93" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
            <div><p class="k">Sede principal</p><p class="v"><span class="dato">[DIRECCIÓN]</span> · Cartagena</p></div>
          </div>
        </div>
      </div>

      <form class="forma" data-forma="cotizar">
        <div class="campo"><label for="c-nombre">Nombre</label><input id="c-nombre" name="Nombre" type="text" placeholder="Su nombre" required></div>
        <div class="campo"><label for="c-empresa">Institución</label><input id="c-empresa" name="Institución" type="text" placeholder="Clínica, EPS, laboratorio…"></div>
        <div class="campo"><label for="c-correo">Correo</label><input id="c-correo" name="Correo" type="email" placeholder="nombre@institucion.com" required></div>
        <div class="campo"><label for="c-tel">Teléfono</label><input id="c-tel" name="Teléfono" type="tel" placeholder="Con indicativo"></div>
        <div class="campo campo--ancho">
          <label for="c-serv">Qué necesita mover</label>
          <select id="c-serv" name="Servicio" required>
            <option value="" disabled selected>Seleccione una opción…</option>
            <option>Cadena de frío</option>
            <option>Medicamentos de control especial</option>
            <option>Entrega domiciliaria a afiliados</option>
            <option>Traslado entre sedes</option>
            <option>Urgencias</option>
            <option>Varias modalidades</option>
          </select>
        </div>
        <div class="campo campo--ancho">
          <label for="c-msg">Cuéntenos el caso</label>
          <textarea id="c-msg" name="Detalle" placeholder="Volumen aproximado, ciudades, rango de temperatura, frecuencia…"></textarea>
        </div>
        <p class="forma-aviso" role="status" aria-live="polite" data-aviso></p>
        <div class="forma-pie">
          <button class="btn btn--pri" type="submit">Enviar solicitud</button>
          <p class="forma-nota">
            Se envía a <strong>info@ltp.com</strong>. Al enviar autoriza el tratamiento de sus
            datos para responder esta solicitud.
          </p>
        </div>
      </form>
    </div>
  </div>
</section>

<!-- ================================================================== pqrs -->
<section id="pqrs">
  <div class="env">
    <div class="cot-int">
      <div>
        <p class="rotulo">PQRS</p>
        <h2>Peticiones, quejas, reclamos y sugerencias</h2>
        <p class="plomo">
          Toda radicación recibe número y respuesta dentro de los términos de ley. Si su caso
          tiene que ver con un envío concreto, indíquenos la guía o su propia referencia: eso
          nos permite adjuntar el soporte completo a la respuesta.
        </p>

        <div style="margin-top: 1.6rem; padding: 1.1rem 1.2rem; border-radius: 12px; background: var(--vivo-piso); border: 1px solid #BFE6EB">
          <h3 style="font-size: .95rem; margin-bottom: .4rem">Términos de respuesta</h3>
          <ul style="margin: 0; padding-left: 1.1rem; font-size: .86rem; color: var(--tinta-2); line-height: 1.7">
            <li>Petición de información: <span class="dato">[N]</span> días hábiles</li>
            <li>Queja o reclamo: <span class="dato">[N]</span> días hábiles</li>
            <li>Solicitud sobre datos personales: <span class="dato">[N]</span> días hábiles</li>
          </ul>
        </div>
      </div>

      <form class="forma" data-forma="pqrs">
        <div class="campo campo--ancho">
          <label for="p-tipo">Tipo de solicitud</label>
          <select id="p-tipo" name="Tipo" required>
            <option value="" disabled selected>Seleccione una opción…</option>
            <option>Petición</option>
            <option>Queja</option>
            <option>Reclamo</option>
            <option>Sugerencia</option>
            <option>Protección de datos personales</option>
          </select>
        </div>
        <div class="campo"><label for="p-nombre">Nombre</label><input id="p-nombre" name="Nombre" type="text" required></div>
        <div class="campo"><label for="p-doc">Documento</label><input id="p-doc" name="Documento" type="text"></div>
        <div class="campo"><label for="p-correo">Correo</label><input id="p-correo" name="Correo" type="email" required></div>
        <div class="campo"><label for="p-tel">Teléfono</label><input id="p-tel" name="Teléfono" type="tel"></div>
        <div class="campo campo--ancho"><label for="p-guia">Guía o referencia relacionada</label><input id="p-guia" name="Guía" type="text" placeholder="AVL-000000-0000 o su propio número"></div>
        <div class="campo campo--ancho"><label for="p-msg">Descripción</label><textarea id="p-msg" name="Descripción" placeholder="Cuéntenos qué ocurrió, con fechas y lugares" required></textarea></div>
        <div class="campo campo--ancho">
          <label for="p-arch">Adjuntar evidencia</label>
          <input id="p-arch" type="file" multiple accept="image/*,application/pdf" data-adjuntos>
          <span style="font-size: .74rem; color: var(--tinta-3)">Fotos o PDF. Máximo 5 archivos.</span>
        </div>
        <p class="forma-aviso" role="status" aria-live="polite" data-aviso></p>
        <div class="forma-pie">
          <button class="btn btn--marino" type="submit">Radicar PQRS</button>
          <p class="forma-nota">
            Se envía a <strong>info@ltp.com</strong> y recibirá el número de radicado en su correo.
          </p>
        </div>
      </form>
    </div>
  </div>
</section>

<!-- ==================================================================== pie -->
<footer class="pie">
  <div class="env">
    <div class="pie-int">
      <div>
        {{-- Aquí la pieza que lleva el eslogan, y en blanco: el mismo archivo
             del encabezado tomando el color del pie. --}}
        <div class="logo" style="color: #FFFFFF">
          <span class="logo-pieza" style="width: 208px">{!! file_get_contents(public_path('marca/avalia-eslogan.svg')) !!}</span>
        </div>
        <p style="margin: 1.1rem 0 0; font-size: .86rem; color: var(--marino-ink-2); max-width: 34ch; line-height: 1.6">
          Carga y transporte terrestre, logística para el sector salud y última milla,
          con cadena de frío monitoreada y evidencia verificable.
        </p>
      </div>

      <div>
        <h4>Servicios</h4>
        <ul>
          <li><a href="#frio">Cadena de frío</a></li>
          <li><a href="#servicios">Control especial</a></li>
          <li><a href="#servicios">Domiciliaria</a></li>
          <li><a href="#servicios">Entre sedes</a></li>
        </ul>
      </div>

      <div>
        <h4>Plataforma</h4>
        <ul>
          <li><a href="{{ url('/ingreso') }}" data-plataforma>Portal de clientes</a></li>
          <li><a href="{{ url('/rastreo') }}" data-plataforma>Rastrear un envío</a></li>
          <li><a href="{{ url('/verificar') }}" data-plataforma>Verificar un soporte</a></li>
          <li><a href="#integracion">API para integrar</a></li>
        </ul>
      </div>

      <div>
        <h4>Atención</h4>
        <ul>
          <li><a href="#cotizar">Cotizar</a></li>
          <li><a href="#pqrs">PQRS</a></li>
          <li><a href="#pqrs">Tratamiento de datos</a></li>
          <li><a href="mailto:info@ltp.com">info@ltp.com</a></li>
        </ul>
      </div>
    </div>

    <div class="pie-legal">
      <span>Avalia Logística S.A.S. · NIT 000.000.000-0 · Cartagena, Colombia</span>
      <span>© 2026 Avalia Logística S.A.S. Todos los derechos reservados.</span>
    </div>
  </div>
</footer>

<script>
/* --------------------------------------------------------------- carrusel
   El desplazamiento y el anclaje son de CSS; esto solo añade los mandos. Si el
   guion falla, el carrusel sigue funcionando con el dedo y con la rueda. */
document.querySelectorAll('[data-carrusel]').forEach((car) => {
    const pista  = car.querySelector('[data-pista]')
    const ant    = car.querySelector('[data-ant]')
    const sig    = car.querySelector('[data-sig]')
    const puntos = car.querySelector('[data-puntos]')
    const laminas = [...pista.children]

    laminas.forEach((_, i) => {
        const b = document.createElement('button')
        b.className = 'carrusel-punto'
        b.type = 'button'
        b.setAttribute('aria-label', `Ir a la imagen ${i + 1}`)
        b.addEventListener('click', () => irA(i))
        puntos.appendChild(b)
    })

    function actual() {
        // La lámina activa es la más cercana al borde izquierdo de la pista.
        let mejor = 0
        let dist = Infinity

        laminas.forEach((l, i) => {
            const d = Math.abs(l.offsetLeft - pista.scrollLeft)
            if (d < dist) { dist = d; mejor = i }
        })

        return mejor
    }

    function irA(i) {
        const l = laminas[Math.max(0, Math.min(laminas.length - 1, i))]
        pista.scrollTo({ left: l.offsetLeft, behavior: 'smooth' })
    }

    function pintar() {
        const i = actual()

        ;[...puntos.children].forEach((p, k) => p.setAttribute('aria-current', k === i ? 'true' : 'false'))

        ant.disabled = pista.scrollLeft <= 4
        sig.disabled = pista.scrollLeft >= pista.scrollWidth - pista.clientWidth - 4
    }

    ant.addEventListener('click', () => irA(actual() - 1))
    sig.addEventListener('click', () => irA(actual() + 1))
    pista.addEventListener('scroll', pintar, { passive: true })
    window.addEventListener('resize', pintar)
    pintar()
})

/* ------------------------------------------------------------- formularios
   Cotización y PQRS se guardan en la plataforma: el servidor las escribe en
   base de datos y solo después intenta avisar por correo. Ese orden es
   deliberado — si el correo está caído, la solicitud del cliente no se pierde
   por eso.

   Cuando esta página se abre fuera de la plataforma (una vista previa, un
   archivo suelto) no hay servidor que responda. En ese caso se cae a un correo
   redactado en el cliente, para que el visitante no quede sin manera de
   escribir. Los adjuntos no viajan por esa vía: ningún navegador permite que
   una página adjunte archivos a un correo, y se le dice al usuario en vez de
   dejarlo creer que fueron. */
/* La ruta base de la aplicacion, que el servidor sabe y este guion no:
   vacia en la raiz de un dominio, "/Avalia" si cuelga de una subcarpeta.
   Sin esto, el formulario pide /api/v1/... a la raiz del dominio y ahi no
   hay nada. */
const BASE_APP = @json(rtrim(request()->getBaseUrl(), '/'))

const API = BASE_APP + '/api/v1/sitio'
const DESTINO = 'info@ltp.com'

document.querySelectorAll('[data-forma]').forEach((forma) => {
    const esPqrs = forma.dataset.forma === 'pqrs'
    const aviso  = forma.querySelector('[data-aviso]')
    const boton  = forma.querySelector('button[type="submit"]')

    forma.addEventListener('submit', async (e) => {
        e.preventDefault()

        if (! forma.reportValidity()) return

        const original = boton.textContent
        boton.disabled = true
        boton.textContent = 'Enviando…'
        aviso.style.color = ''
        aviso.textContent = ''

        try {
            const r = await fetch(`${API}/${esPqrs ? 'pqrs' : 'contacto'}`, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: cuerpo(forma, esPqrs),
            })

            const j = await r.json()

            if (r.ok) {
                aviso.style.color = 'var(--bien)'
                aviso.textContent = j.mensaje ?? 'Recibimos su solicitud.'
                forma.reset()

                return
            }

            if (r.status === 429) {
                aviso.style.color = 'var(--tinta-2)'
                aviso.textContent = 'Demasiados envíos seguidos. Espere un minuto e intente de nuevo.'

                return
            }

            // 422: el servidor dice exactamente qué campo está mal. Repetirlo es
            // más útil que un «revise los datos» genérico.
            aviso.style.color = '#A02A2A'
            aviso.textContent = j.errors
                ? Object.values(j.errors).flat().join(' ')
                : (j.message ?? 'No se pudo enviar. Intente de nuevo.')
        } catch {
            porCorreo(forma, esPqrs, aviso)
        } finally {
            boton.disabled = false
            boton.textContent = original
        }
    })
})

/** Arma el envío. Con adjuntos hace falta FormData; sin ellos, JSON basta. */
function cuerpo(forma, esPqrs) {
    const v = (id) => forma.querySelector(`#${id}`)?.value?.trim() ?? ''

    if (! esPqrs) {
        return new Blob([JSON.stringify({
            nombre:      v('c-nombre'),
            institucion: v('c-empresa'),
            correo:      v('c-correo'),
            telefono:    v('c-tel'),
            servicio:    v('c-serv'),
            detalle:     v('c-msg'),
        })], { type: 'application/json' })
    }

    const fd = new FormData()

    // El servidor espera la clave interna, no la etiqueta que ve el visitante.
    const TIPOS = {
        'Petición': 'peticion',
        'Queja': 'queja',
        'Reclamo': 'reclamo',
        'Sugerencia': 'sugerencia',
        'Protección de datos personales': 'datos_personales',
    }

    fd.append('tipo', TIPOS[v('p-tipo')] ?? 'peticion')
    fd.append('nombre', v('p-nombre'))
    fd.append('documento', v('p-doc'))
    fd.append('correo', v('p-correo'))
    fd.append('telefono', v('p-tel'))
    fd.append('guia', v('p-guia'))
    fd.append('descripcion', v('p-msg'))

    const archivos = forma.querySelector('[data-adjuntos]')?.files ?? []
    ;[...archivos].slice(0, 5).forEach((a) => fd.append('adjuntos[]', a))

    return fd
}

/** Respaldo cuando no hay plataforma detrás: redactar el correo en el cliente. */
function porCorreo(forma, esPqrs, aviso) {
    const lineas = []

    forma.querySelectorAll('input, select, textarea').forEach((c) => {
        if (c.type === 'file' || ! c.name) return
        const val = (c.value || '').trim()
        if (val !== '') lineas.push(`${c.name}: ${val}`)
    })

    const n = forma.querySelector('[data-adjuntos]')?.files?.length ?? 0
    if (n > 0) lineas.push('', `Adjuntos anunciados: ${n} archivo(s).`)

    const asunto = esPqrs ? 'PQRS desde el sitio web' : 'Solicitud de cotización'
    const texto  = `${lineas.join('\n')}\n\n— Enviado desde el sitio de Avalia Logística S.A.S.`

    window.location.href =
        `mailto:${DESTINO}?subject=${encodeURIComponent(asunto)}&body=${encodeURIComponent(texto)}`

    aviso.style.color = 'var(--tinta-2)'
    aviso.textContent = n > 0
        ? `Se abrió su correo hacia ${DESTINO}. Adjunte allí los ${n} archivo(s): el navegador no permite adjuntarlos solo.`
        : `Se abrió su correo hacia ${DESTINO}. Revise y envíe.`
}

/* --------------------------------------------------- enlaces a la plataforma
   «Portal de clientes», «Rastrear un envío» y «Verificar un soporte» apuntan a
   rutas de la aplicación, que vive en el mismo dominio que este sitio cuando
   está desplegado.

   Abierta como vista previa —fuera de ese dominio— esas rutas no existen y el
   navegador llevaba a una página en blanco, que es la peor respuesta posible:
   parece que algo se rompió cuando en realidad la plataforma sencillamente no
   está ahí.

   Se comprueba una vez si la aplicación responde en este origen. Si no, los
   enlaces dejan de navegar y lo explican en su sitio. */
(async () => {
    const enlaces = [...document.querySelectorAll('[data-plataforma]')]

    if (enlaces.length === 0) return

    const hayPlataforma = await (async () => {
        try {
            // Cualquier respuesta sirve como prueba de vida, incluido un 401:
            // significa que la aplicación está ahí y solo pide credenciales.
            const r = await fetch(BASE_APP + '/api/v1/catalogos', {
                method: 'GET',
                headers: { Accept: 'application/json' },
            })

            return r.status !== 404
        } catch {
            return false
        }
    })()

    if (hayPlataforma) return

    const aviso = document.createElement('div')
    aviso.setAttribute('role', 'status')
    aviso.style.cssText = [
        'position:fixed', 'left:50%', 'bottom:1.5rem', 'transform:translateX(-50%)',
        'z-index:100', 'max-width:min(92vw, 30rem)',
        'background:#0D1333', 'color:#FFFFFF',
        'border-radius:10px', 'padding:.85rem 1.1rem',
        'font-size:.86rem', 'line-height:1.5',
        'box-shadow:0 12px 40px -12px rgba(11,18,32,.55)',
        'display:none', 'gap:.6rem', 'align-items:flex-start',
    ].join(';')

    aviso.innerHTML =
        '<span style="flex:none;margin-top:.15rem" aria-hidden="true">'
        + '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4C8AE0" '
        + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        + '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01"/><path d="M11 12h1v4h1"/></svg></span>'
        + '<span>Esta es una vista previa del sitio. El portal de clientes, el rastreo y la '
        + 'verificación viven en la plataforma; desde aquí no se puede entrar a ellos.</span>'

    document.body.appendChild(aviso)

    let temporizador = null

    enlaces.forEach((a) => {
        // Se conserva el href para que el enlace siga siendo un enlace —copiable,
        // legible por un lector de pantalla— pero no lleve a ninguna parte.
        a.setAttribute('aria-disabled', 'true')
        a.style.opacity = '.72'

        a.addEventListener('click', (e) => {
            e.preventDefault()

            aviso.style.display = 'flex'
            clearTimeout(temporizador)
            temporizador = setTimeout(() => { aviso.style.display = 'none' }, 6000)
        })
    })
})()
</script>
</body>
</html>
