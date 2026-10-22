<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AutenticacionController;
use App\Http\Controllers\Api\SitioController;
use App\Http\Controllers\Api\VerificacionController;
use App\Http\Controllers\Api\CampoController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ConsultaController;
use App\Http\Controllers\Api\DespachoController;
use App\Http\Controllers\Api\NovedadController;
use App\Http\Controllers\Api\InformeController;
use App\Http\Controllers\Api\IntegracionController;
use App\Http\Controllers\Api\MapaController;
use App\Http\Controllers\Api\PuntoPactadoController;
use App\Http\Controllers\Api\TramoController;
use App\Http\Controllers\Api\OrdenController;
use App\Http\Controllers\Api\TableroController;
use App\Http\Controllers\Api\TelemetriaController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| API v1
|---------------------------------------------------------------------------
| Tres audiencias con necesidades distintas, y por eso tres bloques:
|
|   · INTEGRACIÓN — el ERP del cliente, autenticado con un token del cliente.
|     Consulta por su referencia o por la guía Avalia, y exporta.
|   · OPERACIÓN — el panel interno, autenticado por sesión con doble factor.
|   · CAMPO — la interfaz del mensajero, tolerante a la falta de señal.
|
| El versionado en la ruta es deliberado: un cliente que integró contra /v1 no
| puede quedar roto porque nosotros cambiemos una respuesta.
*/

Route::prefix('v1')->group(function () {

    // --------------------------------------------------------------- ingreso
    // Límite estricto: el ingreso es el punto que más se ataca de toda la API.
    Route::post('auth/ingresar', [AutenticacionController::class, 'ingresar'])
        ->middleware('throttle:10,1');

    // ---------------------------------------------------- verificacion publica
    /*
     * El unico punto que responde sin sesion. Exige el codigo impreso en el
     * certificado, de modo que no sirve para enumerar guias ajenas, y devuelve
     * lo minimo: integridad de la cadena y estado del sello, nunca el contenido
     * del envio ni quien lo recibio. Un verificador que de paso revele el
     * negocio del cliente seria peor que no tenerlo.
     */
    Route::post('verificar', [VerificacionController::class, 'verificar'])
        ->middleware('throttle:20,1');

    // ------------------------------------------------------- sitio publico
    /*
     * Formularios de la web. Sin sesion por definicion, asi que van con
     * limite estrecho: un formulario abierto en internet es la puerta mas
     * facil de inundar que tiene cualquier sitio.
     */
    Route::post('sitio/contacto', [SitioController::class, 'contacto'])
        ->middleware('throttle:5,1');

    Route::post('sitio/pqrs', [SitioController::class, 'pqrs'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/yo', [AutenticacionController::class, 'yo']);
        Route::post('auth/salir', [AutenticacionController::class, 'salir']);
    });

    // ------------------------------------------------------------ integración
    // Token del cliente. El alcance por cliente se aplica en la consulta.
    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
        Route::get('rastreo', [ConsultaController::class, 'rastrear']);
        Route::post('rastreo/lote', [ConsultaController::class, 'lote']);

        Route::get('envios/exportar', [ConsultaController::class, 'exportar']);
        Route::get('envios/{guia}/soporte', [ConsultaController::class, 'soporte']);
        Route::post('envios/{guia}/enlace', [ConsultaController::class, 'emitirEnlace']);

        // Ruta trazada, rastro real y dictamen de desvio de un envio. El
        // control de quien puede verlo esta en el controlador: un cliente solo
        // ve los suyos.
        Route::get('envios/{guia}/mapa', [MapaController::class, 'envio']);

        Route::get('ordenes/catalogo', [OrdenController::class, 'catalogo']);
        Route::get('ordenes/{guia}/enlace-impresion', [OrdenController::class, 'enlaceImpresion']);
        Route::get('ordenes', [OrdenController::class, 'index']);
        Route::post('ordenes', [OrdenController::class, 'store']);

        /*
         * Cancelar una guía radicada por error. Solo mientras ninguna caja
         * haya salido: a partir de ahí la resuelve operación. No borra nada —
         * la guía queda marcada como cancelada, con motivo y en la bitácora.
         */
        Route::post('ordenes/{guia}/cancelar', [OrdenController::class, 'cancelar'])
            ->middleware('rol:administrador|operador|cliente');

        Route::get('tablero', [TableroController::class, 'resumen']);

        // Puntos pactados. El cliente propone los suyos y consulta su estado;
        // la revision vive mas abajo, con la operacion.
        Route::get('puntos', [PuntoPactadoController::class, 'index']);
        Route::post('puntos', [PuntoPactadoController::class, 'proponer']);
    });

    // -------------------------------------------------------------- operación
    Route::middleware(['auth:sanctum', 'rol:administrador|operador'])->prefix('despacho')->group(function () {
        Route::get('bandeja', [DespachoController::class, 'bandeja']);
        // Lo que ya va en manos de alguien, para poder reasignarlo. La bandeja
        // solo enseña lo que está sin asignar, y la calle no respeta el reparto.
        Route::get('en-curso', [DespachoController::class, 'enCurso']);

        /*
         * Lo que lleva días parado y en manos de quién. Es la pregunta que
         * nadie se hace hasta que llama el cliente.
         */
        Route::get('custodia-prolongada', [DespachoController::class, 'enCustodiaProlongada']);

        Route::get('paquetes/{paquete}/candidatos', [DespachoController::class, 'candidatos']);
        Route::post('paquetes/{paquete}/asignar', [DespachoController::class, 'asignar']);

        /*
         * Reabrir una entrega cerrada por error. No borra la evidencia
         * anterior: la marca como reemplazada y deja el motivo en la bitácora.
         */
        Route::post('paquetes/{paquete}/reabrir-entrega', [DespachoController::class, 'reabrirEntrega']);

        // Posicion de la flota. Fuera del alcance de los clientes: donde esta
        // un mensajero es dato del trabajador, no del envio.
        Route::get('mapa', [MapaController::class, 'flota']);

        // Cadena de trayectos. La operacion la arma, la reencamina y la
        // programa; cerrarla es cosa del mensajero, mas abajo.
        Route::get('catalogo', [TramoController::class, 'catalogo']);

        /*
         * Novedades pendientes de decisión.
         *
         * Una novedad ya no detiene el envío —el mensajero puede reintentar la
         * entrega— pero sigue habiendo casos que no se resuelven volviendo: la
         * devolución al origen y el siniestro. Esas dos son decisiones de
         * operación, no de campo, y hasta ahora no tenían dónde tomarse.
         */
        // Soportes que no llegaron al repositorio del cliente. Agotados los
        // reintentos el trabajo cae en `failed_jobs`, y nadie mira `failed_jobs`.
        Route::get('soportes-pendientes', [IntegracionController::class, 'pendientes']);
        Route::post('evidencias/{evidencia}/reenviar', [IntegracionController::class, 'reenviar']);

        Route::get('novedades', [NovedadController::class, 'index']);
        Route::post('paquetes/{paquete}/resolver-novedad', [NovedadController::class, 'resolver']);

        // Aprobar o rechazar un punto propuesto por un cliente.
        Route::post('puntos/{sede}/revisar', [PuntoPactadoController::class, 'revisar']);
        Route::get('paquetes/{paquete}/tramos', [TramoController::class, 'index']);
        Route::post('paquetes/{paquete}/tramos', [TramoController::class, 'armar']);
        Route::post('paquetes/{paquete}/trasbordo', [TramoController::class, 'trasbordo']);
        Route::post('tramos/{tramo}/asignar', [TramoController::class, 'asignar']);
    });

    // ------------------------------------------------------------ telemetría
    // Ingesta SIN sesión: quien llama es un aparato, no una persona. La
    // autenticación es la firma HMAC del cuerpo con el secreto de cada
    // dispositivo, verificada dentro del controlador.
    Route::post('telemetria/{proveedor}', [TelemetriaController::class, 'ingesta'])
        ->middleware('throttle:600,1');

    Route::middleware(['auth:sanctum', 'rol:administrador|operador'])->group(function () {
        Route::get('telemetria/en-vivo', [TelemetriaController::class, 'enVivo']);
        Route::get('telemetria/dispositivos/{dispositivo}/serie', [TelemetriaController::class, 'serie']);
        Route::get('telemetria/excursiones', [TelemetriaController::class, 'excursiones']);
        Route::post('telemetria/excursiones/{excursion}/atender', [TelemetriaController::class, 'atenderExcursion']);
    });

    // ---------------------------------------------------------- administración
    // Solo administrador. El operador despacha; el administrador responde por
    // que el sistema siga siendo auditable. Son dos oficios distintos.
    Route::middleware(['auth:sanctum', 'rol:administrador'])->prefix('admin')->group(function () {
        Route::get('resumen', [AdminController::class, 'resumen']);

        Route::get('usuarios', [AdminController::class, 'usuarios']);
        Route::post('usuarios/{usuario}/alternar', [AdminController::class, 'alternarUsuario']);
        Route::post('usuarios/{usuario}/clave', [AdminController::class, 'restablecerClave']);

        Route::get('parametros', [AdminController::class, 'parametros']);
        Route::put('parametros', [AdminController::class, 'guardarParametros']);

        /*
         * Informes. El administrador no despacha: responde por lo que pasó, y
         * para eso necesita cifras agregadas que ninguna pantalla de operación
         * da. La exportación a CSV no vive aquí: la pantalla ya tiene los datos
         * y los escribe en el navegador, como el resto de listados.
         */
        Route::get('informes', [InformeController::class, 'index']);
        Route::get('informes/filtros', [InformeController::class, 'filtros']);

        Route::get('auditoria', [AdminController::class, 'auditoria']);
        Route::post('auditoria/verificar', [AdminController::class, 'verificarCadena']);

        Route::get('flota', [AdminController::class, 'flota']);
        Route::get('clientes', [AdminController::class, 'clientes']);

        // --- catálogo maestro: lo que hay que cargar ANTES de radicar nada ---
        Route::post('clientes', [CatalogoController::class, 'crearCliente']);
        Route::put('clientes/{cliente}', [CatalogoController::class, 'actualizarCliente']);
        Route::post('clientes/{cliente}/alternar', [CatalogoController::class, 'alternarCliente']);

        // Integración con los sistemas del cliente. Aparte de «actualizar
        // cliente» porque aquí se maneja una credencial, y eso merece su propia
        // entrada en la auditoría.
        Route::put('clientes/{cliente}/integracion', [CatalogoController::class, 'guardarIntegracion']);

        // Tokens de integración: pertenecen al cliente, no a una persona.
        Route::get('clientes/{cliente}/tokens', [CatalogoController::class, 'tokens']);
        Route::post('clientes/{cliente}/tokens', [CatalogoController::class, 'crearToken']);
        Route::delete('clientes/{cliente}/tokens/{token}', [CatalogoController::class, 'revocarToken']);

        Route::post('sedes', [CatalogoController::class, 'crearSede']);
        Route::put('sedes/{sede}', [CatalogoController::class, 'actualizarSede']);
        Route::post('sedes/{sede}/alternar', [CatalogoController::class, 'alternarSede']);

        Route::post('zonas', [CatalogoController::class, 'crearZona']);
        Route::put('zonas/{zona}', [CatalogoController::class, 'actualizarZona']);

        Route::post('vehiculos', [CatalogoController::class, 'crearVehiculo']);
        Route::put('vehiculos/{vehiculo}', [CatalogoController::class, 'actualizarVehiculo']);
        Route::post('vehiculos/{vehiculo}/alternar', [CatalogoController::class, 'alternarVehiculo']);

        Route::post('mensajeros', [CatalogoController::class, 'crearMensajero']);
        Route::put('mensajeros/{mensajero}', [CatalogoController::class, 'actualizarMensajero']);

        Route::post('dispositivos', [CatalogoController::class, 'crearDispositivo']);
        Route::post('dispositivos/{dispositivo}/instalar', [CatalogoController::class, 'instalarDispositivo']);
        Route::post('dispositivos/{dispositivo}/secreto', [CatalogoController::class, 'rotarSecreto']);
    });

    // ------------------------------------------------------------------ campo
    // Límite de tasa más alto: un mensajero que recupera señal descarga una
    // jornada entera de una vez, y eso no puede confundirse con un abuso.
    Route::middleware(['auth:sanctum', 'rol:mensajero', 'throttle:300,1'])->prefix('campo')->group(function () {
        Route::get('resumen', [CampoController::class, 'resumen']);

        // Pausas de jornada: almuerzo, lluvia, avería. Es lo que permite medir
        // la productividad contra el tiempo en que de verdad se podía repartir.
        /*
         * Entrada y salida de la jornada. De aqui sale la «hora de entrada»
         * con la que los informes arman las franjas horarias.
         */
        Route::post('jornada', [CampoController::class, 'marcarJornada']);

        Route::post('pausas', [CampoController::class, 'abrirPausa']);
        Route::post('pausas/cerrar', [CampoController::class, 'cerrarPausa']);
        Route::get('tareas', [CampoController::class, 'tareas']);
        Route::post('posiciones', [CampoController::class, 'posiciones']);
        Route::post('paquetes/{paquete}/evidencias', [CampoController::class, 'evidencia']);
        Route::post('paquetes/{paquete}/recolectar', [CampoController::class, 'recolectar']);
        Route::post('paquetes/{paquete}/entregar', [CampoController::class, 'entregar']);
        Route::post('paquetes/{paquete}/novedad', [CampoController::class, 'novedad']);

        // Cierre del tramo que el mensajero lleva encima. El controlador
        // comprueba que sea suyo: sin eso, cualquiera podria firmar la entrega
        // de un paquete que nunca tuvo en las manos.
        Route::post('tramos/{tramo}/cerrar', [TramoController::class, 'cerrar']);
    });

    // ------------------------------------------------------------- catálogos
    Route::middleware('auth:sanctum')->get('catalogos', function () {
        return response()->json([
            'estados'     => App\Enums\EstadoPaquete::catalogo(),
            'tipos'       => App\Enums\TipoPaquete::catalogo(),
            'novedades'   => App\Enums\TipoNovedad::catalogo(),
            'referencias' => App\Enums\TipoReferencia::catalogo(),
        ]);
    });
});
