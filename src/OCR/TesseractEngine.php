<?php
namespace App\OCR;

use Exception;
use App\Core\Functions;

class TesseractEngine
{
    private $binary;
    private $lang;
    private $psm;

    public function __construct($config)
    {
        $this->binary = $config['path'] ?? 'tesseract';
        $this->lang = $config['language'] ?? 'spa';
        $this->psm = $config['psm'] ?? 6;

        if (!is_file($this->binary)) {
            // Intentar búsqueda en la ruta de usuario por si acaso
            $userBinary = 'C:\\Users\\' . get_current_user() . '\\AppData\\Local\\Programs\\Tesseract-OCR\\tesseract.exe';
            if (is_file($userBinary)) {
                $this->binary = $userBinary;
            } else {
                throw new Exception("Ejecutable de Tesseract no encontrado en: " . $this->binary);
            }
        }
    }

    /**
     * Ejecuta Tesseract sobre una imagen y devuelve el JSON estructurado
     */
    public function process($imagePath)
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $tempBase = $config['paths']['temp'] . '/ocr_output_' . uniqid();
        
        // El comando generará archivos base.txt y base.tsv
        $cmd = sprintf(
            '%s %s %s --psm %d -l %s txt tsv 2>&1',
            escapeshellarg($this->binary),
            escapeshellarg($imagePath),
            escapeshellarg($tempBase),
            $this->psm,
            $this->lang
        );

        Functions::log("Ejecutando Tesseract: " . $cmd);
        
        exec($cmd, $outputRows, $exitCode);
        $fullOutput = implode("\n", $outputRows);

        if ($exitCode !== 0) {
            Functions::log("ERROR Tesseract (Código $exitCode): " . $fullOutput, 'ERROR');
            throw new Exception("Error al ejecutar Tesseract: " . $fullOutput);
        }

        $txtFile = $tempBase . '.txt';
        $tsvFile = $tempBase . '.tsv';

        if (!file_exists($tsvFile)) {
            throw new Exception("Tesseract terminó pero no generó el archivo TSV de coordenadas.");
        }

        $text = file_exists($txtFile) ? trim(file_get_contents($txtFile)) : "";
        $words = $this->parseTsv($tsvFile);

        // Limpieza
        if (file_exists($txtFile)) unlink($txtFile);
        if (file_exists($tsvFile)) unlink($tsvFile);

        return [
            'text' => $text,
            'words' => $words,
            'debug_cmd' => $cmd,
            'debug_output' => $fullOutput
        ];
    }

    /**
     * Convierte el archivo TSV de Tesseract en un array de palabras con coordenadas
     */
    private function parseTsv($file)
    {
        $rows = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $header = array_shift($rows); // Quitar cabecera: level page_num block_num...
        
        $words = [];
        foreach ($rows as $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12) continue;
            
            $conf = (float)$cols[10];
            $text = trim($cols[11]);
            
            if ($text === "" || $conf < 10) continue;

            $words[] = [
                'text' => $text,
                'x' => (int)$cols[6],
                'y' => (int)$cols[7],
                'w' => (int)$cols[8],
                'h' => (int)$cols[9],
                'conf' => $conf
            ];
        }
        return $words;
    }
}
