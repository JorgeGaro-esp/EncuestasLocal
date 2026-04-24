<?php
namespace App\Core;

use Exception;
use Imagick;
use ImagickDraw;
use ImagickPixel;

class Functions
{
    /**
     * Asegura que un directorio existe
     */
    public static function ensureDir($path)
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new Exception("No se pudo crear el directorio: $path");
            }
        }
    }

    /**
     * Limpia un directorio de archivos viejos
     */
    public static function cleanDir($path, $seconds = 3600)
    {
        if (!is_dir($path)) return;
        foreach (glob($path . '/*') as $file) {
            if (is_file($file) && (time() - filemtime($file) > $seconds)) {
                @unlink($file);
            }
        }
    }

    /**
     * Registra un mensaje en el archivo de logs
     */
    public static function log($message, $level = 'INFO')
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $logFile = $config['paths']['logs'] . '/app.log';
        self::ensureDir(dirname($logFile));
        
        $date = date('Y-m-d H:i:s');
        $formatted = "[$date] [$level] $message" . PHP_EOL;
        file_put_contents($logFile, $formatted, FILE_APPEND);
    }

    /**
     * Extrae las páginas de un PDF a imágenes individuales
     */
    public static function extractPdfPages($pdfPath, $outputDir)
    {
        if (!class_exists('Imagick')) {
            throw new Exception("La extensión Imagick de PHP es necesaria para procesar PDFs.");
        }

        self::ensureDir($outputDir);
        $baseName = pathinfo($pdfPath, PATHINFO_FILENAME);
        $pages = [];

        $imagick = new Imagick();
        $imagick->setResolution(200, 200); // Resolución estándar para OCR
        $imagick->readImage($pdfPath);

        $numPages = $imagick->getNumberImages();
        
        for ($i = 0; $i < $numPages; $i++) {
            $imagick->setIteratorIndex($i);
            $imagick->setImageFormat('png');
            $fileName = "{$baseName}_page_" . ($i + 1) . ".png";
            $targetPath = $outputDir . '/' . $fileName;
            
            $imagick->writeImage($targetPath);
            $pages[] = [
                'path' => $targetPath,
                'number' => $i + 1,
                'filename' => $fileName
            ];
        }

        $imagick->clear();
        $imagick->destroy();

        return $pages;
    }
}
