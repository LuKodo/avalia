<?php

namespace App\Services\Consulta;

use App\Models\Orden;
use App\Models\Paquete;
use App\Services\CadenaFrio\PerfilTermico;
use App\Services\Trazabilidad\VerificacionPublica;
use App\Services\Trazabilidad\VerificadorCadena;

/**
 * Armado del soporte de entrega.
 *
 * Es el documento que el cliente necesita para cerrar SU proceso: cuándo llegó,
 * a qué hora exacta, quién lo recibió, con qué documento y cargo, dónde estaba
 * el mensajero en ese momento y qué evidencia lo respalda. Es lo que adjunta a
 * su auditoría interna o a una reclamación, así que se arma completo o no se
 * arma: un soporte con campos faltantes no le sirve a nadie.
 */
class SoporteEntrega
{
    /**
     * Qué evidencias viajan al repositorio del cliente.
     *
     * Solo la foto del soporte de entrega. Vive aquí y en el disparo de la
     * cola; si algún día se añade la firma, hay que tocar los dos sitios —y
     * este comentario existe para que quien lo haga lo sepa—.
     */
    private const SE_ENVIAN_AL_CLIENTE = ['foto_receptor'];

    public function __construct(
        private VerificadorCadena $verificador,
        private PerfilTermico $perfil,
        private VerificacionPublica $verificacionPublica,
    ) {}

    /**
     * @param  bool  $incluirDatosReceptor  Si es falso, el documento del receptor
     *              va enmascarado. El valor completo solo se entrega al propio
     *              cliente dueño del envío y deja registro de acceso.
     * @return array<string, mixed>
     */
    public function armar(Orden $orden, bool $incluirDatosReceptor = false, bool $verificarCadena = false): array
    {
        $orden->loadMissing([
            'cliente', 'origen', 'destino', 'referencias',
            'paquetes.entrega.firma', 'paquetes.entrega.fotoReceptor',
            'paquetes.mensajero.user', 'paquetes.mensajero.vehiculo',
            'paquetes.temperaturas', 'paquetes.novedades', 'paquetes.evidencias',
            'paquetes.eventos',
        ]);

        $total = $orden->paquetes->count();

        return [
            'envio' => [
                'guia_avalia'        => $orden->numero_guia,
                'estado'          => $orden->estadoConsolidado(),
                'creado_en'       => $orden->created_at?->toIso8601String(),
                'criticidad'      => $orden->criticidad->value,
                'ventana_desde'   => $orden->ventana_desde?->toIso8601String(),
                'ventana_hasta'   => $orden->ventana_hasta?->toIso8601String(),
                'comprometida_en' => $orden->comprometida_en?->toIso8601String(),
                'dentro_de_ventana' => $orden->dentroDeVentana(),
            ],

            // Las referencias del cliente van primero en el documento: es como
            // él identifica el envío, y es lo que va a buscar en el PDF.
            'referencias_cliente' => $orden->referencias->map(fn ($r) => [
                'tipo'     => $r->tipo->value,
                'etiqueta' => $r->tipo->etiqueta(),
                'valor'    => $r->valor,
            ])->values()->all(),

            'cliente' => [
                'razon_social' => $orden->cliente->razon_social,
                'nit'          => $orden->cliente->nit,
            ],

            'origen'  => $this->sede($orden->origen),
            'destino' => $this->sede($orden->destino),

            'paquetes' => $orden->paquetes->map(
                fn (Paquete $p) => $this->paquete($p, $incluirDatosReceptor, $total)
            )->values()->all(),

            'verificacion' => $verificarCadena ? $this->verificacion($orden) : null,

            /*
             * El codigo que convierte este soporte en algo comprobable por
             * un tercero. Va impreso en el certificado: con la guia y este
             * codigo, cualquier auditor confirma la integridad en /verificar
             * sin depender de que Avalia se lo confirme. Un soporte que solo
             * nosotros podemos validar no es prueba, es afirmacion.
             */
            'codigo_verificacion' => $this->verificacionPublica->codigoDe($orden),

            'generado_en' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  int  $totalPaquetes  Se recibe ya contado desde la orden: pedirlo
     *              desde cada paquete dispararía una consulta por paquete.
     * @return array<string, mixed>
     */
    private function paquete(Paquete $paquete, bool $incluirDatosReceptor, int $totalPaquetes): array
    {
        $entrega = $paquete->entrega;

        return [
            'codigo'      => $paquete->codigo,
            'consecutivo' => "{$paquete->consecutivo} de {$totalPaquetes}",
            'tipo'        => $paquete->tipo->value,
            'tipo_etiqueta' => $paquete->tipo->etiqueta(),
            'estado'      => $paquete->estado->value,
            'estado_etiqueta' => $paquete->estado->etiqueta(),
            'descripcion' => $paquete->descripcion,
            'lote'        => $paquete->lote,
            'peso_kg'     => $paquete->peso_kg,
            'dimensiones_cm' => array_filter([
                'largo' => $paquete->largo_cm,
                'ancho' => $paquete->ancho_cm,
                'alto'  => $paquete->alto_cm,
            ]),

            /*
             * Las evidencias van AQUÍ, en el paquete, y no dentro del bloque de
             * entrega. Estaban anidadas allí, y eso las volvía invisibles
             * mientras el envío no estuviera entregado: la foto tomada en la
             * recolección existía, con su integridad verificada, y nadie podía
             * verla. La evidencia de un envío en curso es exactamente la que
             * hace falta cuando algo se está torciendo.
             */
            'evidencias' => $paquete->evidencias
                ->sortBy(fn ($e) => $e->capturada_en ?? $e->recibida_en)
                ->map(fn ($e) => [
                    'tipo'      => $e->tipo,
                    'etiqueta'  => $e->etiqueta(),
                    'momento'   => in_array($e->tipo, ['foto_indicador', 'foto_empaque'], true)
                        ? 'recoleccion'
                        : 'entrega',
                    'checksum'  => $e->checksum,
                    'mime'      => $e->mime,
                    'bytes'     => $e->bytes,
                    // La interfaz necesita saber si puede pintarla o si solo
                    // puede ofrecer la descarga: un PDF dentro de un <img> no
                    // muestra nada, solo un recuadro roto.
                    'es_imagen' => str_starts_with((string) $e->mime, 'image/'),
                    'capturada_en' => ($e->capturada_en ?? $e->recibida_en)?->toIso8601String(),

                    /*
                     * El estado del envío al repositorio del cliente.
                     *
                     * Van los dos datos, no solo el bueno. Mostrar «enviado»
                     * cuando lo está y nada cuando no, deja al cliente sin saber
                     * si su soporte va en camino o si nunca salió: el silencio
                     * se lee como «todo bien», que es justo lo contrario.
                     *
                     * Queda en nulo —y la interfaz no muestra nada— en dos
                     * casos donde el silencio SÍ es correcto: clientes sin
                     * integración, y evidencias que no se envían nunca.
                     *
                     * Lo segundo importa: al cliente se le manda únicamente la
                     * foto del receptor. Marcar la firma como «sin enviar»
                     * anunciaba un fallo inexistente, que es la clase de aviso
                     * que enseña a la gente a ignorar los avisos.
                     */
                    'integracion' => (! $paquete->orden->cliente?->integracion_activa
                        || ! in_array($e->tipo, self::SE_ENVIAN_AL_CLIENTE, true)) ? null : [
                        'enviada'   => $e->entregada_al_cliente_en !== null,
                        'enviada_en' => $e->entregada_al_cliente_en?->toIso8601String(),
                        'nombre'    => $e->referencia_externa,
                        'intentos'  => (int) $e->intentos_envio,
                    ],

                    // Enlace firmado de vigencia corta. Nunca una URL pública.
                    'enlace'    => $e->enlaceTemporal(30),
                ])->values()->all(),

            'recoleccion' => $paquete->recolectado_en === null ? null : [
                'fecha' => $paquete->recolectado_en->format('Y-m-d'),
                'hora'  => $paquete->recolectado_en->format('H:i:s'),
                'iso'   => $paquete->recolectado_en->toIso8601String(),
            ],

            // El bloque que el cliente vino a buscar.
            'entrega' => $entrega === null ? null : [
                'fecha'  => $entrega->entregada_en->format('Y-m-d'),
                'hora'   => $entrega->entregada_en->format('H:i:s'),
                'iso'    => $entrega->entregada_en->toIso8601String(),
                'zona_horaria' => config('app.timezone'),

                'recibido_por' => [
                    'nombre'    => $entrega->receptor_nombre,
                    'documento' => $incluirDatosReceptor
                        ? $entrega->receptor_documento
                        : $entrega->documentoEnmascarado(),
                    'cargo'     => $entrega->receptor_cargo,
                ],

                'ubicacion' => [
                    'latitud'            => $entrega->latitud,
                    'longitud'           => $entrega->longitud,
                    'precision_m'        => $entrega->precision_m,
                    'distancia_a_sede_m' => $entrega->distancia_a_sede_m,
                    'coherente'          => $entrega->geolocalizacion_coherente,
                ],

                'dentro_de_ventana' => $entrega->dentro_de_ventana,
                'consentimiento_datos' => $entrega->consentimiento_datos,

                'mensajero' => $paquete->mensajero === null ? null : [
                    'nombre'   => $paquete->mensajero->nombre(),
                    'codigo'   => $paquete->mensajero->codigo,
                    'vehiculo' => $paquete->mensajero->vehiculo?->placa,
                ],
            ],

            /*
             * El análisis reemplaza al «hubo excursión / no hubo excursión» que
             * vivía aquí. Ese booleano no respondía la pregunta que el cliente
             * trae: no quiere saber si la aguja se movió, quiere saber si puede
             * usar el producto. El bloque conserva las lecturas crudas —son la
             * prueba— y les antepone el MKT, el consumo del presupuesto de
             * estabilidad y el dictamen.
             */
            'cadena_frio' => ! $paquete->exigeCadenaFrio() ? null : [
                'rango_exigido' => $paquete->rangoTermico(),
                'excursion'     => $paquete->tuvoExcursionTermica(),

                // Anidado y no fusionado: el análisis trae su propio resumen de
                // lecturas, y fusionarlo pisaría la serie cruda que va debajo —
                // que es justamente la prueba sobre la que se sostiene todo lo
                // demás. Las claves antiguas se conservan para no romper a quien
                // ya consume este contrato.
                'analisis' => $this->perfil->analizar($paquete),

                'lecturas' => $paquete->temperaturas->map(fn ($l) => [
                    'momento'        => $l->momento,
                    'etiqueta'       => $l->momentoEtiqueta(),
                    'valor_c'        => $l->valor,
                    'fuera_de_rango' => $l->fuera_de_rango,
                    'fuente'         => $l->fuente,
                    'registrada_en'  => $l->registrada_en->toIso8601String(),
                ])->values()->all(),
            ],

            'novedades' => $paquete->novedades->map(fn ($n) => [
                'tipo'         => $n->tipo->value,
                'etiqueta'     => $n->tipo->etiqueta(),
                'severidad'    => $n->severidad,
                'descripcion'  => $n->descripcion,
                'reportada_en' => $n->reportada_en->toIso8601String(),
                'estado'       => $n->estado,
            ])->values()->all(),

            /*
             * Cada evento lleva DOS momentos y no son intercambiables:
             *
             *   · `registrado_en` — cuándo lo anotó el servidor. Es el que
             *     sostiene la cadena de hashes y el que vale ante una auditoría.
             *   · `ocurrido_en` — cuándo dice el dispositivo que pasó. Es el que
             *     le interesa a quien consulta su envío.
             *
             * Difieren cuando el mensajero trabajó sin señal y la sincronización
             * llegó horas después. Colapsarlos en un solo campo obligaría a
             * elegir entre mentirle al cliente sobre la hora de su entrega o
             * falsear la marca que la auditoría necesita; se entregan los dos y
             * cada consumidor toma el que le corresponde.
             */
            'trazabilidad' => $paquete->eventos->map(fn ($e) => [
                'secuencia' => (int) $e->secuencia,
                'estado'    => $e->estado_nuevo->value,
                'etiqueta'  => $e->estado_nuevo->etiqueta(),
                'tipo'      => $e->tipo_evento,
                'actor'     => $e->actor_nombre,
                'fecha'     => $e->registrado_en->format('Y-m-d'),
                'hora'      => $e->registrado_en->format('H:i:s'),
                'ocurrio_fecha' => $e->ocurrido_en_dispositivo?->format('Y-m-d'),
                'ocurrio_hora'  => $e->ocurrido_en_dispositivo?->format('H:i:s'),
                'desfase_seg'   => $e->desfase_seg,
                'latitud'   => $e->latitud,
                'longitud'  => $e->longitud,
                'hash'      => $e->hash,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function sede($sede): ?array
    {
        return $sede === null ? null : [
            'nombre'    => $sede->nombre,
            'direccion' => $sede->direccionCompleta(),
            'ciudad'    => $sede->ciudad,
            'contacto'  => $sede->contacto_nombre,
        ];
    }

    /**
     * Verificación criptográfica del tramo de cadena que cubre este envío. Es lo
     * que convierte el soporte en prueba: cualquiera puede recalcular los hashes
     * y comprobar que los eventos no se tocaron después de registrados.
     *
     * @return array<string, mixed>
     */
    private function verificacion(Orden $orden): array
    {
        $secuencias = $orden->paquetes
            ->flatMap(fn (Paquete $p) => $p->eventos->pluck('secuencia'))
            ->filter();

        if ($secuencias->isEmpty()) {
            return ['verificable' => false, 'motivo' => 'El envío no tiene eventos registrados'];
        }

        $resultado = $this->verificador->verificar($secuencias->min(), $secuencias->max());

        return [
            'verificable'       => true,
            'cadena_integra'    => $resultado['ok'],
            'eventos_revisados' => $resultado['revisados'],
            'algoritmo'         => config('ltp.trazabilidad.algoritmo_hash'),
            'primer_fallo'      => $resultado['primer_fallo'],
        ];
    }
}
