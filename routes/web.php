<?php

use App\Models\EnlaceRastreo;
use App\Services\Consulta\SoporteEntrega;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| Rutas web
|---------------------------------------------------------------------------
| La aplicación es una SPA responsive: una sola plantilla monta el frontend y
| el enrutamiento vive en el cliente. La excepción es el rastreo público, que
| responde JSON para que también sirva a quien lo consuma por programa.
*/

Route::get('/rastreo/{token}', function (string $token, SoporteEntrega $soporte) {
    $enlace = EnlaceRastreo::where('token', $token)->first();

    abort_if($enlace === null || ! $enlace->vigente(), 404, 'El enlace de rastreo no existe o ya venció.');

    $enlace->registrarVisita();

    // Vista pública: sin documento del receptor y sin datos comerciales. Quien
    // recibe este enlace por WhatsApp no tiene por qué ver la tarifa del envío.
    $datos = $soporte->armar($enlace->orden, incluirDatosReceptor: false);
    unset($datos['cliente']['nit']);

    return request()->wantsJson()
        ? response()->json($datos)
        : view('app', ['rastreo' => $datos]);
})->name('rastreo.publico');

/*
 * Comodín de la aplicación de una sola página.
 *
 * Dos detalles que se pagan caro si se olvidan:
 *
 *   · El patrón acepta barras (`.*`), porque por omisión un parámetro de ruta
 *     no las acepta y `/campo/12/entrega` —tres segmentos— caería en un 404.
 *   · Se excluyen `api/` y `storage/`. Lo de `storage/` costó un bug real: el
 *     comodín tapaba la ruta con la que Laravel sirve los archivos del disco
 *     local, así que los enlaces firmados de las evidencias devolvían el HTML
 *     de la aplicación en vez de la fotografía — y además respondían 200 sin
 *     firma, porque nunca llegaban a validarla.
 *   · El rastreo público de arriba está declarado antes, así que gana para
 *     `/rastreo/{token}`, mientras que `/rastreo` a secas llega hasta aquí.
 */
/*
 * Sitio público.
 *
 * Va declarado ANTES del comodín, o este se lo tragaría como cualquier otra
 * ruta de la aplicación.
 *
 * La raíz del dominio es el sitio, no la plataforma: quien escribe la dirección
 * a secas es un visitante, no un operador con sesión. Los que trabajan aquí
 * entran por `/ingreso`, que está enlazado desde el menú del sitio.
 *
 * Es una redirección y no una segunda vista sobre la misma plantilla para que
 * el sitio tenga UNA sola dirección: dos URLs con el mismo contenido dividen
 * las estadísticas y confunden a los buscadores.
 */
/*
 * Y no `Route::redirect('/', '/sitio')`, que seria lo obvio.
 *
 * Ese atajo construye el destino con el generador de URL en modo NO absoluto
 * -`toRoute($route, $parametros, false)`-, y eso devuelve «/sitio» contado
 * desde la raiz del servidor, sin la ruta base. Da igual en un dominio propio,
 * pero si la aplicacion cuelga de una subcarpeta manda al visitante fuera de
 * ella: /Avalia/ acababa en /sitio, que no existe.
 *
 * Con la ruta con nombre, el generador si antepone la base.
 */
Route::get('/', fn () => redirect()->route('sitio'));

/*
 * El sitio ya no es una vista estatica: las cifras de la portada salen de la
 * operacion real. Ver PortadaController, que ademas las esconde cuando no hay
 * datos suficientes para que signifiquen algo.
 */
Route::get('/sitio', \App\Http\Controllers\PortadaController::class)->name('sitio');

/*
 * La guia impresa: el papel que viaja pegado a la caja.
 *
 * Va FIRMADA y no autenticada. El token de sesion viaja en una cabecera, y al
 * abrir una pestania para imprimir el navegador no la manda: con `auth` la
 * pagina seria un 401 siempre. La aplicacion pide primero un enlace temporal
 * -ahi si comprueba quien es y de quien es la guia- y abre ese enlace.
 *
 * La firma la emite el servidor con su clave y caduca en minutos: no se puede
 * fabricar a mano ni reutilizar manana.
 *
 * Es una vista de servidor y no una pantalla de la aplicacion porque lo que se
 * busca aqui es imprimir: el navegador maneja los saltos de pagina, los
 * margenes en milimetros y el dialogo de impresion mejor que cualquier cosa
 * que montemos encima.
 *
 * Exige sesion. Los numeros de guia son correlativos -AVL-260928-0011 esta al
 * lado de la 0010- asi que sin autenticar bastaria contar para leer las
 * direcciones y contactos de otros clientes.
 */
Route::middleware('signed')
    ->get('/guias/{guia}/imprimir', \App\Http\Controllers\GuiaImpresaController::class)
    ->name('guia.imprimir');

Route::view('/{cualquiera?}', 'app')->where('cualquiera', '^(?!api\/|storage\/).*$');
