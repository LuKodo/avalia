<?php

namespace Database\Seeders;

use App\Enums\Criticidad;
use App\Enums\EstadoPaquete;
use App\Enums\TipoNovedad;
use App\Enums\TipoPaquete;
use App\Enums\TipoReferencia;
use App\Enums\TipoVehiculo;
use App\Models\Cliente;
use App\Models\Entrega;
use App\Models\LecturaTemperatura;
use App\Models\Mensajero;
use App\Models\Novedad;
use App\Models\Orden;
use App\Models\ReferenciaCliente;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\Zona;
use App\Services\Ordenes\GeneradorGuia;
use App\Services\Trazabilidad\RegistradorEventos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de demostración: 45 días de operación simulada sobre Medellín.
 *
 * Sirve para dos cosas concretas: que el tablero tenga series con forma real
 * —con picos de lunes, valles de domingo y una tasa de novedades creíble— y
 * que la consulta por referencia se pueda probar de verdad, con radicados y
 * órdenes de compra como las que manda un hospital.
 *
 * Las contraseñas de ejemplo van aquí porque este seeder JAMÁS debe correrse
 * en producción; el comando lo bloquea explícitamente.
 */
class DemoSeeder extends Seeder
{
    private GeneradorGuia $guias;

    private RegistradorEventos $registrador;

    /**
     * Consecutivo de referencias.
     *
     * Deliberadamente secuencial y no aleatorio: con valores al azar sobre un
     * rango corto, dos envíos del mismo mes chocan contra la restricción única
     * de `referencias_cliente` antes de llegar al envío número cien. Esa
     * restricción es correcta —dos radicados iguales del mismo cliente serían
     * un error real—, así que lo que se corrige es el generador.
     */
    private int $secuenciaReferencia = 0;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('DemoSeeder no se ejecuta en producción.');

            return;
        }

        $this->guias       = app(GeneradorGuia::class);
        $this->registrador = app(RegistradorEventos::class);

        // Las cuentas las crea UsuariosSeeder. Si alguien corre este seeder
        // solo, se llama desde aquí para que no falle por falta de mensajeros.
        if (Mensajero::count() === 0) {
            $this->call(UsuariosSeeder::class);
        }

        $zonas      = $this->zonas();
        $cliente    = $this->cliente($zonas);
        $bodega     = $this->bodega($zonas);
        $mensajeros = $this->flota($zonas);

        $this->operacion($cliente, $bodega, $mensajeros);

        $this->command->newLine();
        $this->command->info('Demostración lista: 45 días de operación simulada.');
    }

    /** @return array<string, Zona> */
    private function zonas(): array
    {
        $definicion = [
            ['Zona 1 · Centro',    28_400, 41_200],
            ['Zona 2 · Norte',     32_900, 47_500],
            ['Zona 3 · Sur',       31_200, 45_800],
            ['Zona 4 · Occidente', 36_700, 52_400],
        ];

        $zonas = [];

        foreach ($definicion as [$nombre, $base, $frio]) {
            $zonas[$nombre] = Zona::firstOrCreate(
                ['nombre' => $nombre],
                ['ciudad' => 'Medellín', 'tarifa_base' => $base, 'tarifa_refrigerado' => $frio]
            );
        }

        return $zonas;
    }

    private function cliente(array $zonas): Cliente
    {
        // El cliente ya existe: lo creó UsuariosSeeder junto con su cuenta de
        // portal. Aquí solo se le montan las sedes sobre las que va a operar.
        $cliente = Cliente::where('nit', '890.900.123-4')->firstOrFail();

        $sedes = [
            ['Clínica del Norte · Farmacia', 'clinica', 'Cra 51 #100-25', 'Piso 3, farmacia interna', 'Zona 2 · Norte', 6.2988, -75.5619],
            ['Clínica del Norte · Urgencias', 'clinica', 'Cra 51 #100-25', 'Portería 2, muelle', 'Zona 2 · Norte', 6.2985, -75.5622],
            ['Sede Sur · Consulta externa', 'clinica', 'Cl 30 #43-15', 'Recepción', 'Zona 3 · Sur', 6.2265, -75.5735],
        ];

        foreach ($sedes as [$nombre, $tipo, $dir, $acceso, $zona, $lat, $lon]) {
            Sede::firstOrCreate(
                ['nombre' => $nombre],
                [
                    'cliente_id'            => $cliente->id,
                    'zona_id'               => $zonas[$zona]->id,
                    'tipo'                  => $tipo,
                    'direccion'             => $dir,
                    'detalle_acceso'        => $acceso,
                    'ciudad'                => 'Medellín',
                    'latitud'               => $lat,
                    'longitud'              => $lon,
                    'coordenada_verificada' => true,
                    'ventanas_recepcion'    => array_map(
                        fn ($d) => ['dia' => $d, 'desde' => '07:00', 'hasta' => '18:00'],
                        [1, 2, 3, 4, 5]
                    ),
                    'contacto_nombre'   => 'Q.F. Diana Restrepo',
                    'contacto_telefono' => '604 444 1230',
                ]
            );
        }

        return $cliente;
    }

    private function bodega(array $zonas): Sede
    {
        return Sede::firstOrCreate(
            ['nombre' => 'Bodega Central Avalia'],
            [
                'zona_id'               => $zonas['Zona 1 · Centro']->id,
                'tipo'                  => 'bodega',
                'direccion'             => 'Cra 48 #32-14',
                'detalle_acceso'        => 'Muelle 2',
                'ciudad'                => 'Medellín',
                'latitud'               => 6.2447,
                'longitud'              => -75.5812,
                'coordenada_verificada' => true,
            ]
        );
    }

    /**
     * Completa la flota.
     *
     * Los tres primeros mensajeros vienen de UsuariosSeeder —son las cuentas
     * con las que se prueba el filtro duro—. Aquí se agregan tres más para que
     * la operación simulada tenga volumen suficiente y el reparto de carga en
     * el tablero no salga concentrado en tres personas.
     *
     * Los correos van sin tildes a propósito: un correo con acentos se teclea
     * mal la mitad de las veces y no todos los servidores lo aceptan.
     *
     * @return array<int, Mensajero>
     */
    private function flota(array $zonas): array
    {
        $idsZonas = array_map(fn ($z) => $z->id, array_values($zonas));

        $adicionales = [
            ['Sandra Villa',  'sandra.villa',  'MSJ-004', 'PLM663', TipoVehiculo::Automovil, 80,   true,  97.9],
            ['Jorge Velez',   'jorge.velez',   'MSJ-005', 'KTR81E', TipoVehiculo::Moto,      15,   false, 92.3],
            ['Paola Cardona', 'paola.cardona', 'MSJ-006', 'RSV204', TipoVehiculo::Furgon,    1500, false, 95.5],
        ];

        foreach ($adicionales as [$nombre, $usuarioCorreo, $codigo, $placa, $tipo, $capacidad, $certificado, $cumplimiento]) {
            $vehiculo = Vehiculo::firstOrCreate(
                ['placa' => $placa],
                [
                    'tipo'                   => $tipo,
                    'refrigeracion'          => $tipo->tieneRefrigeracion(),
                    'capacidad_kg'           => $capacidad,
                    'soat_vence'             => now()->addMonths(7),
                    'tecnomecanica_vence'    => now()->addMonths(4),
                    'certificado_frio_vence' => $tipo->tieneRefrigeracion() ? now()->addMonths(9) : null,
                ]
            );

            $usuario = User::firstOrCreate(
                ['email' => "{$usuarioCorreo}@ltp.local"],
                ['nombre' => $nombre, 'password' => Hash::make('Avalia*2026')]
            );

            if (! $usuario->hasRole('mensajero')) {
                $usuario->assignRole('mensajero');
            }

            Mensajero::firstOrCreate(
                ['codigo' => $codigo],
                [
                    'user_id'                 => $usuario->id,
                    'vehiculo_id'             => $vehiculo->id,
                    'certificado_controlados' => $certificado,
                    'certificado_vence'       => $certificado ? now()->addYear() : null,
                    'zonas_habilitadas'       => $idsZonas,
                    'en_turno'                => true,
                    'turno_inicio'            => now()->startOfDay()->addHours(6),
                    'ultima_latitud'          => 6.24 + mt_rand(-400, 400) / 10000,
                    'ultima_longitud'         => -75.58 + mt_rand(-400, 400) / 10000,
                    'ultima_posicion_en'      => now()->subMinutes(mt_rand(1, 6)),
                    'bateria'                 => mt_rand(35, 98),
                    'indice_cumplimiento'     => $cumplimiento,
                ]
            );
        }

        // Todos habilitados en todas las zonas: en la demostración no interesa
        // probar restricciones de cobertura, interesa que haya candidatos.
        Mensajero::query()->update(['zonas_habilitadas' => json_encode($idsZonas)]);

        return Mensajero::with('vehiculo', 'user')->get()->all();
    }

    /** @param array<int, Mensajero> $mensajeros */
    private function operacion(Cliente $cliente, Sede $bodega, array $mensajeros): void
    {
        $destinos = Sede::where('cliente_id', $cliente->id)->get();
        $barra    = $this->command->getOutput()->createProgressBar(45);

        for ($dia = 44; $dia >= 0; $dia--) {
            $fecha = now()->subDays($dia)->startOfDay();

            // Volumen con forma real: los lunes pesan, los domingos casi no
            // existen. Una serie plana haría que el mapa de calor no dijera nada.
            $factor = match ((int) $fecha->format('N')) {
                1       => 1.35,
                6       => 0.45,
                7       => 0.15,
                default => 1.0,
            };

            $cantidad = (int) round(mt_rand(9, 16) * $factor);

            for ($i = 0; $i < $cantidad; $i++) {
                $this->envio($cliente, $bodega, $destinos->random(), $mensajeros, $fecha, $dia);
            }

            $barra->advance();
        }

        $barra->finish();
        $this->command->newLine(2);
    }

    private function envio(Cliente $cliente, Sede $origen, Sede $destino, array $mensajeros, $fecha, int $diasAtras): void
    {
        // La demanda se concentra entre las 7 y las 11: es cuando los servicios
        // farmacéuticos hospitalarios despachan su pedido del día.
        $hora    = mt_rand(0, 100) < 62 ? mt_rand(7, 11) : mt_rand(12, 18);
        $creada  = (clone $fecha)->setTime($hora, mt_rand(0, 59));

        $urgente    = mt_rand(0, 100) < 28;
        $criticidad = $urgente ? Criticidad::Urgente : Criticidad::Programado;

        $orden = Orden::create([
            'numero_guia'      => $this->guias->generar($creada),
            'cliente_id'       => $cliente->id,
            'sede_origen_id'   => $origen->id,
            'sede_destino_id'  => $destino->id,
            'criticidad'       => $criticidad,
            'ventana_desde'    => (clone $creada)->addMinutes(30),
            'ventana_hasta'    => (clone $creada)->addHours($urgente ? 3 : 8),
            'comprometida_en'  => (clone $creada)->addHours($urgente ? 3 : 8),
            'contacto_destino_nombre' => 'Q.F. Diana Restrepo',
            'created_at'       => $creada,
            'updated_at'       => $creada,
        ]);

        // Referencias del cliente: son la llave con la que va a consultar.
        $this->secuenciaReferencia++;

        ReferenciaCliente::create([
            'orden_id'   => $orden->id,
            'cliente_id' => $cliente->id,
            'tipo'       => TipoReferencia::Radicado,
            'valor'      => sprintf('RAD-%s-%05d', $creada->format('ym'), $this->secuenciaReferencia),
        ]);

        // Cerca de un 40 % de los envíos llega además con orden de compra: es
        // el caso que hace falta para probar que dos referencias distintas
        // recuperan el mismo envío.
        if ($this->secuenciaReferencia % 5 < 2) {
            ReferenciaCliente::create([
                'orden_id'   => $orden->id,
                'cliente_id' => $cliente->id,
                'tipo'       => TipoReferencia::OrdenCompra,
                'valor'      => sprintf('OC-%04d', 4000 + $this->secuenciaReferencia),
            ]);
        }

        $tipo = match (true) {
            mt_rand(0, 100) < 34 => TipoPaquete::CadenaFrio,
            mt_rand(0, 100) < 18 => TipoPaquete::Controlado,
            default              => TipoPaquete::General,
        };

        $paquete = $orden->paquetes()->create([
            'codigo'      => $this->guias->codigoPaquete($orden->numero_guia, 1),
            'consecutivo' => 1,
            'tipo'        => $tipo,
            'estado'      => EstadoPaquete::Creado,
            'descripcion' => match ($tipo) {
                TipoPaquete::CadenaFrio => 'Nevera con biológicos · lote '.strtoupper(bin2hex(random_bytes(3))),
                TipoPaquete::Controlado => 'Medicamento de control especial · acta adjunta',
                TipoPaquete::General    => 'Insumos médicos generales',
            },
            'peso_kg'   => round(mt_rand(50, 1800) / 100, 2),
            'largo_cm'  => mt_rand(20, 60),
            'ancho_cm'  => mt_rand(15, 45),
            'alto_cm'   => mt_rand(12, 40),
            'temp_min'  => $tipo->exigeRefrigeracion() ? 2 : null,
            'temp_max'  => $tipo->exigeRefrigeracion() ? 8 : null,
            'created_at' => $creada,
            'updated_at' => $creada,
        ]);

        $orden->calcularTarifa();

        $this->registrador->registrar($paquete, EstadoPaquete::Creado, [
            'actor_tipo' => 'sistema',
            'datos'      => ['origen_radicacion' => 'demo'],
        ]);

        // Los envíos de hoy no se cierran todos: el tablero necesita ver
        // operación viva, y la bandeja de despacho necesita tener algo dentro.
        // Un sistema sembrado sin nada pendiente no se puede probar.
        if ($diasAtras === 0) {
            $dado = mt_rand(0, 100);

            if ($dado < 45) {
                return;   // sin asignar: cae en la bandeja del operador
            }

            if ($dado < 80) {
                $this->enCurso($paquete, $mensajeros);

                return;
            }
        }

        $this->cerrar($paquete, $mensajeros, $creada, $tipo, $destino);
    }

    /**
     * Mensajero compatible con el paquete.
     *
     * La demostración tiene que respetar el mismo filtro duro que la operación
     * real: un paquete de cadena de frío entregado por una moto es un dato que
     * no puede existir, y verlo en pantalla hace desconfiar —con razón— de todo
     * lo demás que muestra el sistema.
     *
     * @param  array<int, Mensajero>  $mensajeros
     */
    private function mensajeroPara($paquete, array $mensajeros): Mensajero
    {
        $aptos = array_values(array_filter($mensajeros, function (Mensajero $m) use ($paquete) {
            if ($m->vehiculo === null) {
                return false;
            }

            if ($paquete->tipo->exigeRefrigeracion() && ! $m->vehiculo->refrigeracion) {
                return false;
            }

            if ($paquete->tipo->exigeCertificacion() && ! $m->certificado_controlados) {
                return false;
            }

            return $m->vehiculo->capacidad_kg >= $paquete->peso_kg;
        }));

        return $aptos === []
            ? $mensajeros[array_rand($mensajeros)]
            : $aptos[array_rand($aptos)];
    }

    private function enCurso($paquete, array $mensajeros): void
    {
        $mensajero = $this->mensajeroPara($paquete, $mensajeros);

        $paquete->update(['mensajero_id' => $mensajero->id]);

        $this->registrador->registrar($paquete, EstadoPaquete::Asignado, [
            'actor_tipo' => 'sistema',
            'datos'      => ['mensajero_nombre' => $mensajero->nombre(), 'origen' => 'automatica'],
        ]);

        if (mt_rand(0, 100) < 60) {
            $this->registrador->registrar($paquete, EstadoPaquete::EnRutaRecoleccion, ['actor_tipo' => 'sistema']);
            $this->registrador->registrar($paquete, EstadoPaquete::Recolectado, ['actor_tipo' => 'sistema']);
            $this->registrador->registrar($paquete, EstadoPaquete::EnTransito, ['actor_tipo' => 'sistema']);
        }
    }

    private function cerrar($paquete, array $mensajeros, $creada, TipoPaquete $tipo, Sede $destino): void
    {
        $mensajero = $this->mensajeroPara($paquete, $mensajeros);
        $paquete->update(['mensajero_id' => $mensajero->id]);

        foreach ([EstadoPaquete::Asignado, EstadoPaquete::EnRutaRecoleccion] as $estado) {
            $this->registrador->registrar($paquete, $estado, ['actor_tipo' => 'sistema']);
        }

        $recolectado = (clone $creada)->addMinutes(mt_rand(18, 70));
        $paquete->update(['recolectado_en' => $recolectado]);

        $this->registrador->registrar($paquete, EstadoPaquete::Recolectado, ['actor_tipo' => 'sistema']);

        if ($tipo->exigeRefrigeracion()) {
            $this->temperatura($paquete, 'recoleccion', $recolectado);
        }

        $this->registrador->registrar($paquete, EstadoPaquete::EnTransito, ['actor_tipo' => 'sistema']);
        $this->registrador->registrar($paquete, EstadoPaquete::EnSitio, ['actor_tipo' => 'sistema']);

        // Una de cada once termina en novedad: es la proporción que hace que el
        // gráfico de causas tenga algo que decir sin volverse alarmista.
        if (mt_rand(0, 100) < 9) {
            $this->novedad($paquete, $recolectado);

            return;
        }

        $entregado = (clone $recolectado)->addMinutes(mt_rand(25, 145));

        $paquete->update(['entregado_en' => $entregado]);

        $this->registrador->registrar($paquete, EstadoPaquete::Entregado, ['actor_tipo' => 'sistema']);

        if ($tipo->exigeRefrigeracion()) {
            $this->temperatura($paquete, 'entrega', $entregado);
        }

        $distancia = mt_rand(4, 190);

        Entrega::create([
            'paquete_id'         => $paquete->id,
            'receptor_nombre'    => ['Diana Restrepo', 'Luis Álvarez', 'Mónica Tabares', 'Óscar Yepes'][array_rand([0, 1, 2, 3])],
            'receptor_documento' => (string) mt_rand(10_000_000, 99_999_999),
            'receptor_cargo'     => 'Química farmacéutica',
            'latitud'            => $destino->latitud + mt_rand(-8, 8) / 10000,
            'longitud'           => $destino->longitud + mt_rand(-8, 8) / 10000,
            'precision_m'        => mt_rand(4, 25),
            'distancia_a_sede_m' => $distancia,
            'geolocalizacion_coherente' => $distancia <= 150,
            'entregada_en'       => $entregado,
            'entregada_en_dispositivo' => $entregado,
            'dentro_de_ventana'  => $entregado <= $paquete->orden->comprometida_en,
            'consentimiento_datos' => true,
        ]);
    }

    private function temperatura($paquete, string $momento, $cuando): void
    {
        // Distribución centrada en 5 °C con cola: la mayoría cumple, unas pocas
        // se salen. Es la forma que tiene una operación real con control.
        $valor = mt_rand(0, 100) < 6
            ? round(mt_rand(85, 120) / 10, 1)
            : round(mt_rand(25, 78) / 10, 1);

        LecturaTemperatura::create([
            'paquete_id'     => $paquete->id,
            'valor'          => $valor,
            'momento'        => $momento,
            'fuente'         => 'manual',
            'fuera_de_rango' => ! $paquete->temperaturaEnRango($valor),
            'registrada_en'  => $cuando,
        ]);
    }

    private function novedad($paquete, $cuando): void
    {
        $tipos = [
            TipoNovedad::ReceptorAusente,
            TipoNovedad::ReceptorAusente,
            TipoNovedad::DireccionErrada,
            TipoNovedad::AccesoRestringido,
            TipoNovedad::SedeCerrada,
            TipoNovedad::RupturaCadenaFrio,
            TipoNovedad::EmpaqueDanado,
            TipoNovedad::RechazoReceptor,
        ];

        $tipo = $tipos[array_rand($tipos)];

        Novedad::create([
            'paquete_id'   => $paquete->id,
            'tipo'         => $tipo,
            'descripcion'  => 'Registrada desde la aplicación de campo.',
            'reportada_en' => (clone $cuando)->addMinutes(mt_rand(30, 120)),
        ]);

        $this->registrador->registrar($paquete, EstadoPaquete::Novedad, [
            'tipo'       => 'novedad',
            'actor_tipo' => 'sistema',
            'datos'      => ['tipo_novedad' => $tipo->value, 'severidad' => $tipo->severidad()],
        ]);
    }
}
