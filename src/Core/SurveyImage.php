<?php
namespace App\Core;

use Imagick;
use Exception;

/**
 * CLASE: SurveyImage (v2 - Corregida)
 * --------------------------------------
 * CAMBIOS PRINCIPALES vs v1:
 *
 * 1. getRegionScore MEJORADO:
 *    - En v1 medía el promedio de toda la región, que incluía las líneas del borde
 *      de la casilla (siempre oscuras). Esto elevaba el score de casillas VACÍAS.
 *    - En v2 aplicamos un MARGEN INTERIOR (10% del ancho/alto) para medir solo
 *      el contenido central, ignorando los bordes de la caja.
 *    - Esto hace que una casilla vacía dé ~0-5 y una marcada dé 15-80.
 *
 * 2. prepareForOcr MEJORADO:
 *    - Añadimos despeckle() antes del sharpen para eliminar el ruido de punto
 *      de los escaneos de baja calidad antes de escalar.
 *    - El orden correcto es: grises → despeckle → normalizar → escalar → sharpen.
 */
class SurveyImage
{
    private $imagick;
    private string $path;

    public function __construct(string $path)
    {
        if (!file_exists($path)) {
            throw new Exception("Imagen no encontrada: $path");
        }
        $this->path   = $path;
        $this->imagick = new Imagick($path);
    }

    /**
     * Prepara la imagen para Tesseract OCR.
     * Orden correcto: grises → limpiar ruido → normalizar contraste → escalar → enfocar.
     */
    public function prepareForOcr(float $upscale = 2.0): void
    {
        $this->imagick->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $this->imagick->setImageType(Imagick::IMGTYPE_GRAYSCALE);

        // Eliminamos el ruido de "sal y pimienta" del escaneo antes de escalar
        // (escalar ruido lo hace más grande y confunde a Tesseract)
        $this->imagick->despeckleImage();

        // Normalizamos el contraste (equivalente a autolevels)
        $this->imagick->normalizeImage();

        // Escalamos con LANCZOS (el mejor filtro para preservar nitidez de texto)
        $w = (int)($this->imagick->getImageWidth()  * $upscale);
        $h = (int)($this->imagick->getImageHeight() * $upscale);
        $this->imagick->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1);

        // Enfoque post-escalado para marcar los bordes de las letras
        $this->imagick->sharpenImage(1.5, 0.5);
    }

    /**
     * Guarda un recorte de la imagen original en disco.
     */
    public function saveCrop(string $targetPath, int $x, int $y, int $w, int $h): void
    {
        $crop = clone $this->imagick;
        $crop->cropImage($w, $h, $x, $y);
        $crop->writeImage($targetPath);
        $crop->clear();
        $crop->destroy();
    }

    /**
     * Calcula el % de "oscuridad" (marca) en una región, ignorando los bordes de la caja.
     *
     * MEJORA v2: aplicamos un margen interior del 15% para no medir los propios
     * bordes del rectángulo impreso (que siempre son oscuros y distorsionan el score).
     */
    public function getRegionScore(int $x, int $y, int $w, int $h): float
    {
        // Margen interior: 15% de cada dimensión
        $marginX = max(2, (int)($w * 0.15));
        $marginY = max(2, (int)($h * 0.15));

        $innerX = $x + $marginX;
        $innerY = $y + $marginY;
        $innerW = $w - 2 * $marginX;
        $innerH = $h - 2 * $marginY;

        // Evitamos regiones inválidas
        if ($innerW <= 0 || $innerH <= 0) {
            $innerX = $x; $innerY = $y; $innerW = $w; $innerH = $h;
        }

        $region = clone $this->imagick;
        $region->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $region->setImageType(Imagick::IMGTYPE_GRAYSCALE);
        $region->cropImage($innerW, $innerH, $innerX, $innerY);

        $stats = $region->getImageChannelMean(Imagick::CHANNEL_ALL);
        $mean  = $stats['mean'] ?? 0;

        $qr    = $region->getQuantumRange();
        $range = $qr['quantumRangeLarge'] ?? 65535;

        $region->clear();
        $region->destroy();

        // Score: 0 = blanco puro (vacío), 100 = negro puro (marcado completamente)
        return 100.0 - (($mean / $range) * 100.0);
    }

    public function save(string $targetPath): void
    {
        $this->imagick->writeImage($targetPath);
    }

    public function getWidth(): int  { return $this->imagick->getImageWidth();  }
    public function getHeight(): int { return $this->imagick->getImageHeight(); }

    public function __destruct()
    {
        if ($this->imagick) {
            $this->imagick->clear();
            $this->imagick->destroy();
        }
    }
}
