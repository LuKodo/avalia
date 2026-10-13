<?php

namespace App\Http\Controllers\Api;

use App\Models\Contacto;
use App\Models\Orden;
use App\Models\Pqrs;
use App\Models\PqrsAdjunto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lo que llega desde el sitio público: cotizaciones y PQRS.
 *
 * Responde sin sesión, igual que la verificación de soportes, y por eso está
 * igual de estrecho: valida con dureza, limita por frecuencia y guarda de dónde
 * vino cada envío. Un formulario abierto en internet es la puerta más fácil de
 * inundar que tiene cualquier sitio.
 *
 * Se guarda SIEMPRE en base de datos y solo DESPUÉS se intenta avisar por
 * correo. Ese orden importa: si el servidor de correo está caído, la solicitud
 * del cliente no se puede perder por eso.
 */
class SitioController
{
    /** Tipos de PQRS admitidos, con el término de respuesta en días hábiles. */
    private const TERMINOS = [
        'peticion'         => 15,
        'queja'            => 15,
        'reclamo'          => 15,
        'sugerencia'       => 15,
        'datos_personales' => 10,
    ];

    private const MAX_ADJUNTOS = 5;

    public function contacto(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre'      => ['required', 'string', 'max:120'],
            'institucion' => ['nullable', 'string', 'max:150'],
            'correo'      => ['required', 'email', 'max:150'],
            'telefono'    => ['nullable', 'string', 'max:40'],
            'servicio'    => ['required', 'string', 'max:60'],
            'detalle'     => ['nullable', 'string', 'max:2000'],
        ]);

        $contacto = Contacto::create($datos + [
            'estado' => 'nuevo',
            'origen' => 'sitio_web',
            'ip'     => $request->ip(),
            'agente' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        $this->avisar(
            'Nueva solicitud de cotización',
            [
                'Nombre'      => $contacto->nombre,
                'Institución' => $contacto->institucion ?: '—',
                'Correo'      => $contacto->correo,
                'Teléfono'    => $contacto->telefono ?: '—',
                'Servicio'    => $contacto->servicio,
                'Detalle'     => $contacto->detalle ?: '—',
                'Recibida'    => $contacto->created_at->format('Y-m-d H:i'),
            ],
            $contacto->correo
        );

        return response()->json([
            'recibido' => true,
            'mensaje'  => 'Recibimos su solicitud. Le respondemos al correo indicado.',
        ], 201);
    }

    public function pqrs(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tipo'        => ['required', 'string', 'in:'.implode(',', array_keys(self::TERMINOS))],
            'nombre'      => ['required', 'string', 'max:120'],
            'documento'   => ['nullable', 'string', 'max:30'],
            'correo'      => ['required', 'email', 'max:150'],
            'telefono'    => ['nullable', 'string', 'max:40'],
            'guia'        => ['nullable', 'string', 'max:40'],
            'descripcion' => ['required', 'string', 'max:5000'],

            'adjuntos'    => ['nullable', 'array', 'max:'.self::MAX_ADJUNTOS],
            /*
             * Se restringe por tipo Y por tamaño. Aceptar cualquier archivo en un
             * formulario público es entregarle al primero que pase un lugar donde
             * dejar lo que quiera dentro del servidor.
             */
            'adjuntos.*'  => ['file', 'max:8192', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
        ]);

        $pqrs = DB::transaction(function () use ($datos, $request) {
            $pqrs = Pqrs::create([
                'radicado'    => $this->radicado(),
                'tipo'        => $datos['tipo'],
                'nombre'      => $datos['nombre'],
                'documento'   => $datos['documento'] ?? null,
                'correo'      => $datos['correo'],
                'telefono'    => $datos['telefono'] ?? null,
                'guia_relacionada' => $datos['guia'] ?? null,
                // Si la guía citada existe, se enlaza: así la respuesta puede
                // adjuntar el soporte sin que nadie tenga que buscarlo a mano.
                'orden_id'    => $this->ordenDe($datos['guia'] ?? null),
                'descripcion' => $datos['descripcion'],
                'estado'      => 'radicada',
                'responder_antes_de' => $this->terminoDe($datos['tipo']),
                'ip'          => $request->ip(),
            ]);

            foreach ($request->file('adjuntos', []) as $archivo) {
                $this->guardarAdjunto($pqrs, $archivo);
            }

            return $pqrs;
        });

        $this->avisar(
            "PQRS {$pqrs->radicado} · {$pqrs->tipoEtiqueta()}",
            [
                'Radicado'    => $pqrs->radicado,
                'Tipo'        => $pqrs->tipoEtiqueta(),
                'Nombre'      => $pqrs->nombre,
                'Correo'      => $pqrs->correo,
                'Teléfono'    => $pqrs->telefono ?: '—',
                'Guía'        => $pqrs->guia_relacionada ?: '—',
                'Descripción' => $pqrs->descripcion,
                'Adjuntos'    => $pqrs->adjuntos()->count().' archivo(s)',
                'Responder antes de' => $pqrs->responder_antes_de->format('Y-m-d'),
            ],
            $pqrs->correo
        );

        return response()->json([
            'recibido' => true,
            'radicado' => $pqrs->radicado,
            'responder_antes_de' => $pqrs->responder_antes_de->toDateString(),
            'mensaje'  => "Su solicitud quedó radicada con el número {$pqrs->radicado}.",
        ], 201);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Número de radicado.
     *
     * Legible y ordenable por fecha: PQRS-260905-0001. El consecutivo se calcula
     * dentro de la transacción, contando lo del día.
     */
    private function radicado(): string
    {
        $prefijo = 'PQRS-'.now()->format('ymd').'-';

        $ultimo = Pqrs::withTrashed()
            ->where('radicado', 'like', $prefijo.'%')
            ->orderByDesc('radicado')
            ->value('radicado');

        $n = $ultimo === null ? 1 : ((int) substr($ultimo, -4)) + 1;

        return $prefijo.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Fecha límite en días HÁBILES.
     *
     * Contar corridos acortaría el término real y podría dejar una respuesta
     * fuera de plazo sin que nadie lo notara hasta la reclamación.
     */
    private function terminoDe(string $tipo): \Illuminate\Support\Carbon
    {
        $dias   = self::TERMINOS[$tipo] ?? 15;
        $fecha  = now()->startOfDay();
        $vistos = 0;

        while ($vistos < $dias) {
            $fecha->addDay();

            if (! $fecha->isWeekend()) {
                $vistos++;
            }
        }

        return $fecha;
    }

    /** Busca la orden por número de guía o por la referencia del cliente. */
    private function ordenDe(?string $guia): ?int
    {
        if ($guia === null || trim($guia) === '') {
            return null;
        }

        $clave = strtoupper(trim($guia));

        return Orden::where('numero_guia', $clave)
            ->orWhereHas('referencias', fn ($q) => $q->where('valor', $clave))
            ->value('id');
    }

    private function guardarAdjunto(Pqrs $pqrs, UploadedFile $archivo): void
    {
        $contenido = file_get_contents($archivo->getRealPath());

        // Nombre aleatorio en disco: el que trae el archivo lo eligió alguien de
        // fuera y no tiene por qué gobernar dónde se escribe.
        $ruta = "pqrs/{$pqrs->radicado}/".Str::random(40).'.'.$archivo->extension();

        Storage::disk('local')->put($ruta, $contenido);

        PqrsAdjunto::create([
            'pqrs_id'         => $pqrs->id,
            'nombre_original' => Str::limit($archivo->getClientOriginalName(), 200, ''),
            'ruta'            => $ruta,
            'mime'            => $archivo->getMimeType(),
            'bytes'           => strlen($contenido),
            'checksum'        => hash('sha256', $contenido),
        ]);
    }

    /**
     * Avisa por correo, sin dejar que un fallo del correo tumbe la radicación.
     *
     * El dato ya está guardado cuando esto corre. Si el servidor de correo no
     * responde, se anota en el registro y la persona igual tiene su radicado.
     */
    private function avisar(string $asunto, array $campos, string $responderA): void
    {
        $destino = config('ltp.sitio.correo_avisos');

        if (empty($destino)) {
            return;
        }

        $cuerpo = collect($campos)
            ->map(fn ($v, $k) => "{$k}: {$v}")
            ->implode("\n");

        try {
            Mail::raw($cuerpo, function ($m) use ($destino, $asunto, $responderA) {
                $m->to($destino)->subject($asunto)->replyTo($responderA);
            });
        } catch (\Throwable $e) {
            logger()->error('No se pudo enviar el aviso del sitio público', [
                'asunto' => $asunto,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
