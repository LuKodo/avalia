<?php

/**
 * Parametros de operacion de LTP.
 *
 * Todo lo que la operacion vaya a ajustar en las primeras semanas vive aqui y en
 * la tabla `parametros`, no en el codigo: cambiar un peso del motor de asignacion
 * no puede exigir un despliegue.
 */
return [

    'guia' => [
        'prefijo'  => env('AVALIA_GUIA_PREFIJO', 'AVL'),
        // AVL-AAMMDD-NNNN  ->  AVL-260821-0417
        'formato'  => '{prefijo}-{fecha}-{consecutivo}',
        'longitud_consecutivo' => 4,
    ],

    'asignacion' => [
        // Suman 100. Configurables desde el panel sin desplegar codigo.
        'pesos' => [
            'cercania'  => (int) env('LTP_PESO_CERCANIA', 35),
            'holgura'   => (int) env('LTP_PESO_HOLGURA', 25),
            'carga'     => (int) env('LTP_PESO_CARGA', 20),
            'ruta'      => (int) env('LTP_PESO_RUTA', 15),
            'desempeno' => (int) env('LTP_PESO_DESEMPENO', 5),
        ],
        'radio_busqueda_km'   => (float) env('LTP_RADIO_BUSQUEDA_KM', 15),
        'velocidad_media_kmh' => (float) env('LTP_VELOCIDAD_MEDIA_KMH', 22),
        'minimo_candidatos'   => 3,
    ],

    'cadena_frio' => [
        // Rango por defecto cuando el cliente no especifica. El rango real se
        // captura por paquete: no todo refrigerado comparte el mismo rango.
        'rango_defecto' => ['min' => 2.0, 'max' => 8.0],
        'tolerancia_excursion_minutos' => 20,

        // Temperatura Cinetica Media (USP <1079>, OMS Anexo 9).
        'mkt' => [
            // DeltaH / R en Kelvin. Con la energia de activacion
            // convencional para farmaceutico (83,144 kJ/mol) sobre la
            // constante de los gases (0,0083144 kJ/mol.K) da 10 000 K
            // exactos. Se deja configurable porque un laboratorio puede
            // declarar una energia propia respaldada por su estudio de
            // estabilidad; cambiarla sin ese respaldo invalida el dato.
            'delta_h_sobre_r' => 10_000.0,
        ],

        // Minutos fuera de rango que el producto tolera acumulados a lo
        // largo de toda la cadena. Es dato del estudio de estabilidad del
        // fabricante y deberia venir por paquete; este es el respaldo
        // mientras el cliente no lo declare, y el dictamen avisa cuando
        // esta usando el valor por defecto en vez de uno declarado.
        'presupuesto_estabilidad_minutos' => 240,
    ],

    /*
     * Sitio publico. El correo de avisos NO es el remitente: es a donde
     * llega lo que la gente escribe en el formulario. Vacio desactiva el
     * aviso sin romper el guardado, que es lo que de verdad importa.
     */
    'sitio' => [
        'correo_avisos' => env('LTP_CORREO_AVISOS', 'info@avalia.com.co'),
    ],

    'trazabilidad' => [
        'algoritmo_hash'      => 'sha256',
        'verificacion_diaria' => true,
        'sello_clave'         => env('LTP_SELLO_CLAVE'),
    ],

    'entrega' => [
        // Radio maximo entre la coordenada de la entrega y la sede de destino
        // para considerar la geolocalizacion coherente.
        'radio_validacion_metros' => 150,
        'foto_max_lado_px'        => 1600,
        'foto_calidad'            => 70,
    ],

    'mapa' => [
        /*
         * Teselas del mapa base.
         *
         * Por omision OpenStreetMap, que no pide llave y sirve para operar y
         * para demostrar. ATENCION antes de salir a produccion: su politica de
         * uso prohibe el trafico pesado sobre estos servidores y exige el
         * credito visible (que la aplicacion ya pinta). Un operador con decenas
         * de pantallas abiertas todo el dia es trafico pesado. Para eso se
         * cambia esta URL por un proveedor con contrato -o por un servidor de
         * teselas propio- sin tocar codigo.
         */
        'teselas'  => env('AVALIA_MAPA_TESELAS', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'credito'  => env('AVALIA_MAPA_CREDITO', '© OpenStreetMap'),
        'zoom_max' => 19,

        /*
         * Servicio de ruteo por carretera.
         *
         * El de por omision es el servidor de demostracion publico de OSRM: no
         * pide llave, pero NO tiene acuerdo de servicio y puede negarse o
         * desaparecer. Cuando falla, el sistema traza la linea recta y lo marca
         * como tal -nunca finge una ruta que no calculo-.
         */
        'ruteador'     => env('AVALIA_RUTEADOR', 'https://router.project-osrm.org'),
        'ruteo_timeout_seg' => 6,

        /*
         * Cuanto puede apartarse el mensajero de la ruta trazada antes de que
         * cuente como desvio.
         *
         * 250 m no es un numero redondo elegido al azar: por debajo, una via
         * paralela, un giro en U obligado por un separador o el error normal
         * del GPS urbano (que en calle cerrada llega a 50-80 m) dispararian
         * avisos falsos todo el dia, y un aviso que suena sin motivo deja de
         * mirarse. Para que cuente, ademas, tiene que sostenerse.
         */
        'desvio_metros'       => 250,
        'desvio_minutos'      => 4,

        // Cada cuanto pide posiciones nuevas la pantalla de seguimiento.
        'refresco_segundos'   => 20,

        // Una posicion mas vieja que esto no se muestra como "en vivo": se
        // dice cuando se tomo. Un punto viejo presentado como actual es peor
        // que no tener punto.
        'posicion_vigencia_minutos' => 10,

        /*
         * Tope de la ventana de rastro de un envio, en horas desde la
         * recoleccion.
         *
         * Es una salvaguarda de privacidad, no una preferencia. Un paquete que
         * se recogio y nunca se cerro deja su hora de entrega en nulo para
         * siempre; sin este tope, su ventana se extiende "hasta ahora" y le
         * muestra al cliente semanas del recorrido de ese mensajero, con las
         * paradas de todos los demas clientes dentro.
         */
        'rastro_horas_max' => 24,
    ],

    'integraciones' => [
        // Tipos de referencia que un cliente puede definir para radicar.
        'tipos_referencia' => [
            'radicado'      => 'Numero de radicado',
            'orden_compra'  => 'Orden de compra',
            'remision'      => 'Remision',
            'factura'       => 'Factura',
            'historia'      => 'Historia clinica',
            'interno'       => 'Referencia interna',
        ],
        'max_consulta_lote'   => 200,
        'webhook_reintentos'  => 5,
        'webhook_timeout_seg' => 8,
    ],
];
