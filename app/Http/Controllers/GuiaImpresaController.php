<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * La guía impresa: el papel que viaja pegado a la caja.
 *
 * Sale en SVG, no en PNG. Dos razones, y las dos son prácticas:
 *
 *   · Una etiqueta se lee con un escáner o con la cámara de un teléfono, y un
 *     trazo vectorial imprime nítido tanto en una térmica de 10 cm como en una
 *     hoja A4. Un PNG a 96 ppp sale borroso y un escáner falla con el borde.
 *
 *   · PNG exigiría la extensión `gd`, que NO está instalada en el servidor.
 *     Depender de ella significaría que la etiqueta funciona aquí y no allá.
 */
class GuiaImpresaController extends Controller
{
    public function __invoke(Request $request, string $guia)
    {
        $orden = Orden::with(['cliente', 'origen', 'destino', 'destinatario', 'referencias', 'paquetes', 'paquetes.referencias'])
            ->where('numero_guia', $guia)
            ->firstOrFail();

        /*
         * Quien puede ver esta guia se decidio al emitir el enlace, no aqui.
         *
         * La firma de la URL es la credencial: la emite el servidor solo para
         * quien pidio ESA guia y tenia derecho a ella, y caduca sola. Repetir
         * la comprobacion aqui seria imposible -no hay sesion en esta peticion-
         * y fingirla, peor que no hacerla.
         */

        return view('guia-impresa', [
            'orden'    => $orden,
            'paquetes' => $orden->paquetes->map(fn ($p) => [
                'modelo'  => $p,
                'qr'      => $this->qr($this->enlaceDeRecogida($p->codigo)),
                'barras'  => $this->barras($p->codigo),
            ]),
            // `avalia-principal.svg` ya trae el símbolo y el nombre juntos: añadir
            // el icono aparte lo repetía.
            'marca' => $this->marca('avalia-principal.svg'),
        ]);
    }

    /**
     * Una pieza del logotipo, leída de `public/marca/`.
     *
     * Se lee del mismo sitio del que la sirve el sitio público, no de una copia
     * dentro de `resources/`: dos originales del logotipo terminan divergiendo,
     * y el día que cambie la marca nadie se acuerda del segundo.
     *
     * Si el archivo faltara se devuelve cadena vacía en vez de reventar. Una
     * guía sin logotipo se sigue pudiendo pegar a la caja; una guía que no se
     * imprime detiene el despacho.
     */
    private function marca(string $archivo): string
    {
        $ruta = public_path('marca/'.$archivo);

        if (! is_file($ruta)) {
            return '';
        }

        // Fuera la declaración XML: incrustada dentro del HTML no pinta nada y
        // algunos navegadores se quejan.
        return preg_replace('/<\?xml.*?\?>\s*/s', '', (string) file_get_contents($ruta));
    }

    /**
     * Lo que lleva dentro el QR: una DIRECCIÓN, no los datos del envío.
     *
     * Así lo abre también la cámara normal del teléfono, sin pasar por el
     * escáner de la aplicación. El mensajero apunta, el
     * navegador abre la pantalla de recolección de ese paquete, y confirma.
     *
     * Y porque un QR con los datos dentro es un QR que cualquiera lee: quien
     * fotografíe una caja en un andén vería el remitente, el destinatario y
     * qué transporta. Con una dirección no ve nada: hay que tener sesión de
     * mensajero y el paquete asignado.
     */
    private function enlaceDeRecogida(string $codigo): string
    {
        return rtrim(config('app.url'), '/').'/campo/recoger/'.$codigo;
    }

    private function qr(string $contenido): string
    {
        $escritor = new Writer(new ImageRenderer(
            new RendererStyle(180, 0),      // margen 0: la etiqueta ya deja aire
            new SvgImageBackEnd()
        ));

        return $escritor->writeString($contenido);
    }

    private function barras(string $codigo): string
    {
        // Code128: el estándar de logística. Admite letras y guiones, que es lo
        // que tienen nuestros códigos -AVL-260928-0010-P01-.
        return (new BarcodeGeneratorSVG())->getBarcode(
            $codigo,
            BarcodeGeneratorSVG::TYPE_CODE_128,
            2,
            48
        );
    }
}
