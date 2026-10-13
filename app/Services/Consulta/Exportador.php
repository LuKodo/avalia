<?php

namespace App\Services\Consulta;

use App\Models\Exportacion;
use App\Models\Orden;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de envíos con su soporte de entrega.
 *
 * Toda exportación queda registrada en `exportaciones`: descargar un lote con
 * nombres y documentos de receptores es un acceso a datos personales sensibles,
 * y tiene que poder responderse quién lo hizo, cuándo y con qué criterio.
 */
class Exportador
{
    public function __construct(private SoporteEntrega $soporte) {}

    /**
     * @param  Collection<int, Orden>  $ordenes
     * @return array<string, mixed>
     */
    public function json(Collection $ordenes, bool $incluirDatosReceptor = false): array
    {
        return [
            'generado_en' => now()->toIso8601String(),
            'total'       => $ordenes->count(),
            'envios'      => $ordenes->map(
                fn (Orden $o) => $this->soporte->armar($o, $incluirDatosReceptor, verificarCadena: true)
            )->values()->all(),
        ];
    }

    /**
     * CSV plano, una fila por paquete. Es el formato que la mayoría de clientes
     * cruza contra su propio sistema, así que las primeras columnas son las
     * llaves con las que van a hacer ese cruce: su referencia y nuestra guía.
     *
     * @param  Collection<int, Orden>  $ordenes
     */
    public function csv(Collection $ordenes, bool $incluirDatosReceptor = false): StreamedResponse
    {
        $columnas = [
            'referencia_cliente', 'tipo_referencia', 'guia_avalia', 'codigo_paquete',
            'estado', 'tipo_paquete', 'criticidad',
            'origen', 'destino', 'ciudad_destino',
            'creado_fecha', 'creado_hora',
            'recolectado_fecha', 'recolectado_hora',
            'entregado_fecha', 'entregado_hora',
            'recibido_por', 'documento_receptor', 'cargo_receptor',
            'dentro_de_ventana', 'latitud_entrega', 'longitud_entrega',
            'mensajero', 'vehiculo',
            'temperatura_entrega', 'excursion_termica',
            'novedad', 'peso_kg',
        ];

        $nombre = 'ltp-envios-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($ordenes, $columnas, $incluirDatosReceptor) {
            $salida = fopen('php://output', 'w');

            // BOM UTF-8: sin esto Excel en Windows destroza las tildes, y el
            // primer reporte que abre el cliente es el que define si confía.
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $columnas, ';');

            foreach ($ordenes as $orden) {
                foreach ($orden->paquetes as $paquete) {
                    fputcsv($salida, $this->fila($orden, $paquete, $incluirDatosReceptor), ';');
                }
            }

            fclose($salida);
        }, $nombre, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
        ]);
    }

    /** @return array<int, string|null> */
    private function fila(Orden $orden, $paquete, bool $incluirDatosReceptor): array
    {
        $ref     = $orden->referencias->first();
        $entrega = $paquete->entrega;
        $tempFin = $paquete->temperaturas->firstWhere('momento', 'entrega');
        $novedad = $paquete->novedades->last();

        return [
            $ref?->valor,
            $ref?->tipo->etiqueta(),
            $orden->numero_guia,
            $paquete->codigo,
            $paquete->estado->etiqueta(),
            $paquete->tipo->etiqueta(),
            $orden->criticidad->etiqueta(),
            $orden->origen?->nombre,
            $orden->destino?->nombre,
            $orden->destino?->ciudad,
            $orden->created_at?->format('Y-m-d'),
            $orden->created_at?->format('H:i:s'),
            $paquete->recolectado_en?->format('Y-m-d'),
            $paquete->recolectado_en?->format('H:i:s'),
            $entrega?->entregada_en->format('Y-m-d'),
            $entrega?->entregada_en->format('H:i:s'),
            $entrega?->receptor_nombre,
            $entrega === null ? null : ($incluirDatosReceptor ? $entrega->receptor_documento : $entrega->documentoEnmascarado()),
            $entrega?->receptor_cargo,
            $entrega === null ? null : ($entrega->dentro_de_ventana ? 'Si' : 'No'),
            $entrega?->latitud,
            $entrega?->longitud,
            $paquete->mensajero?->nombre(),
            $paquete->mensajero?->vehiculo?->placa,
            $tempFin?->valor,
            $paquete->tuvoExcursionTermica() ? 'Si' : 'No',
            $novedad?->tipo->etiqueta(),
            $paquete->peso_kg,
        ];
    }

    /**
     * Deja constancia de la exportación. Se llama siempre, incluso cuando la
     * descarga la hace el propio cliente sobre sus propios datos.
     *
     * @param  array<string, mixed>  $criterio
     */
    public function registrar(
        ?User $usuario,
        ?int $clienteId,
        string $tipo,
        string $formato,
        array $criterio,
        int $registros,
        bool $incluyeSoporte = false,
        bool $incluyeDatosReceptor = false,
    ): Exportacion {
        return Exportacion::create([
            'cliente_id'             => $clienteId,
            'user_id'                => $usuario?->id,
            'tipo'                   => $tipo,
            'formato'                => $formato,
            'criterio'               => $criterio,
            'registros'              => $registros,
            'incluye_soporte'        => $incluyeSoporte,
            'incluye_datos_receptor' => $incluyeDatosReceptor,
            'ip'                     => request()?->ip(),
        ]);
    }
}
