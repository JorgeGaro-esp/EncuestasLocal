<?php
namespace App\OMR;

use App\Core\SurveyImage;
use App\OCR\TesseractEngine;

/**
 * CLASE: MarkDetector (v3 - Edición Cuadrículas)
 * ---------------------------------------------
 * Optimizada para detectar marcas DENTRO de las celdas de una tabla.
 */
class MarkDetector
{
    public static function detectSequentially(
        SurveyImage $img,
        array $boxes,
        array $ocrWords,
        TesseractEngine $ocr,
        float $scale = 1.0
    ): array {
        // 1. Separamos por tipo
        $cells = array_filter($boxes, fn($b) => $b['type'] === 'checkbox');
        $writingLines = array_filter($boxes, fn($b) => $b['type'] === 'writing_line');

        // 2. Ordenamos espacialmente
        usort($cells, fn($a,$b) => abs($a['y']-$b['y']) < 30 ? $a['x'] <=> $b['x'] : $a['y'] <=> $b['y']);
        
        $answers = [];
        $config  = require dirname(__DIR__, 2) . '/config/config.php';

        // --- PROCESAR CELDAS DE TABLA ---
        foreach ($cells as $cell) {
            // El texto ("Si", "Nada", "Frecuente") ahora está DENTRO o muy cerca de la celda detectada.
            $label = self::findLabelInCell($cell, $ocrWords, $scale);
            
            if ($label !== null) {
                // Si encontramos la etiqueta, miramos si hay una marca (tachón, X, V) dentro
                // Al ser una celda de tabla, el margen interior es vital
                $score = $img->getRegionScore($cell['x'], $cell['y'], $cell['w'], $cell['h']);
                
                // Si el score es mayor a un umbral (ej: 18), la opción está seleccionada
                if ($score > 18) {
                    $answers[] = $label;
                }
            }
        }

        // --- PROCESAR RENGLONES ---
        foreach ($writingLines as $box) {
            $text = self::ocrWritingLine($img, $box, $ocr, $config);
            if ($text !== '' && mb_strlen($text) > 3) {
                $answers[] = '[Escrito]: ' . $text;
            }
        }

        return array_values(array_unique($answers));
    }

    /**
     * Busca qué palabra del OCR corresponde a esta celda de la tabla.
     * La palabra suele estar DENTRO de la celda o justo debajo.
     */
    private static function findLabelInCell(array $cell, array $words, float $scale): ?string
    {
        $bestMatch = null;
        $minDist = 120 * $scale;

        foreach ($words as $word) {
            $text = trim($word['text'] ?? '');
            if (mb_strlen($text) < 2 || strpos($text, '?') !== false) continue;

            $wx = ($word['x'] ?? 0) * $scale;
            $wy = ($word['y'] ?? 0) * $scale;

            // Distancia al centro de la celda
            $dist = sqrt(pow($cell['center_x'] - $wx, 2) + pow($cell['center_y'] - $wy, 2));

            if ($dist < $minDist) {
                $minDist = $dist;
                $bestMatch = $text;
            }
        }
        return $bestMatch;
    }

    private static function ocrWritingLine(SurveyImage $img, array $box, TesseractEngine $ocr, array $config): string 
    {
        $tempPath = $config['paths']['temp'] . '/crop_ms_' . uniqid() . '.png';
        try {
            $captureH = (int)($box['h'] * 4);
            $captureY = (int)($box['y'] - $captureH * 0.5); // Captura sobre la línea
            $captureY = max(0, $captureY);

            $img->saveCrop($tempPath, $box['x'], $captureY, $box['w'], $captureH);

            $prep = new \Imagick($tempPath);
            $prep->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            $prep->levelImage(0.2 * 65535, 1.0, 0.8 * 65535); // Forzar contraste
            $prep->writeImage($tempPath);
            $prep->clear(); $prep->destroy();

            $result = $ocr->process($tempPath);
            @unlink($tempPath);

            $text = trim($result['text'] ?? '');
            if (strpos($text, '?') !== false || strpos($text, '¿') !== false) return '';
            
            $clean = preg_replace('/[^a-záéíóúüñA-ZÁÉÍÓÚÜÑ\s]/u', '', $text);
            return mb_strlen(trim($clean)) >= 3 ? $text : '';
        } catch (\Exception $e) {
            @unlink($tempPath);
            return '';
        }
    }
    private static function getOptionLabel(string $qid, int $colIndex): string
    {
        $labels = [
            'pregunta1' => ['Nada interesante', 'Poco interesante', 'Interesante', 'Bastante interesante'],
            'pregunta2' => ['Nada importante',  'Poco importante',  'Importante',  'Bastante importante'],
            'pregunta3' => ['Sí', 'No'],
        ];
        return $labels[$qid][$colIndex] ?? "Opción " . ($colIndex + 1);
    }
}
