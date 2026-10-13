<?php

namespace App\Services\Dispositivos;

use App\Services\Dispositivos\Proveedores\Tempubi;

/**
 * Catálogo de adaptadores.
 *
 * Agregar un fabricante nuevo es una línea aquí y un archivo en `Proveedores`.
 * Nada más del sistema cambia.
 */
class RegistroProveedores
{
    /** @var array<string, class-string<AdaptadorDispositivo>> */
    private const PROVEEDORES = [
        'tempubi' => Tempubi::class,
    ];

    public function para(string $proveedor): AdaptadorDispositivo
    {
        $clase = self::PROVEEDORES[strtolower($proveedor)] ?? null;

        if ($clase === null) {
            throw new \InvalidArgumentException(
                "No hay adaptador para el proveedor «{$proveedor}». Disponibles: "
                .implode(', ', array_keys(self::PROVEEDORES))
            );
        }

        return app($clase);
    }

    /** @return array<int, string> */
    public function disponibles(): array
    {
        return array_keys(self::PROVEEDORES);
    }
}
