<?php
/**
 * ARCHIVO: process.php
 * --------------------
 * PROPÓSITO: Es la "Orquesta" que coordina todos los componentes del sistema.
 * 
 * FLUJO DE TRABAJO:
 * 1. Lee el PDF de la carpeta 'uploads'.
 * 2. Convierte el PDF a imágenes PNG (una por página).
 * 3. Analiza el OCR (texto) para saber dónde están las etiquetas.
 * 4. Analiza la GEOMETRÍA (líneas y cajas) para saber dónde están los campos.
 * 5. Junta todo y genera un JSON y un TXT con las respuestas reales.
 */

// Cargamos todas las clases necesarias
require_once __DIR__ . '/src/Core/Functions.php';
require_once __DIR__ . '/src/Core/SurveyImage.php';
require_once __DIR__ . '/src/OCR/TesseractEngine.php';
require_once __DIR__ . '/src/OMR/MarkDetector.php';
require_once __DIR__ . '/src/OMR/GeometryDetector.php';

use App\Core\Functions;
use App\Core\SurveyImage;
use App\OCR\TesseractEngine;
use App\OMR\MarkDetector;
use App\OMR\GeometryDetector;

// Cargamos la configuración (rutas de carpetas, etc.)
$config = require __DIR__ . '/config/config.php';
// Aumentamos el tiempo de ejecución para PDF largos
set_time_limit($config['processing']['max_execution_time'] ?? 600);

try {
    $paths = $config['paths'];
    // Aseguramos que las carpetas de salida existen
    Functions::ensureDir($paths['temp']);
    Functions::ensureDir($paths['results']);
    
    Functions::log("Iniciando procesamiento V4 (Visión Corregida + Comentarios)");

    // Buscamos archivos PDF en la carpeta uploads
    $pdfFiles = glob($paths['uploads'] . '/*.pdf');
    if (empty($pdfFiles)) throw new Exception("No hay PDFs en 'uploads'.");

    $pdfPath = $pdfFiles[0];
    Functions::log("Archivo: " . basename($pdfPath));

    // PASO 1: EXTRACCIÓN
    // Convertimos el PDF en imágenes PNG individuales
    $pages = Functions::extractPdfPages($pdfPath, $paths['temp']);
    // Inicializamos el motor de Tesseract
    $engine = new TesseractEngine($config['tesseract']);
    
    $finalResults = [];
    $total = count($pages);

    // PASO 2: BUCLE DE PÁGINAS
    foreach ($pages as $page) {
        $num = $page['number'];
        // Mostramos progreso en la consola del usuario
        echo "[...] Procesando pagina $num de $total...\r";
        Functions::log("Analizando pagina $num");

        $originalImg = new SurveyImage($page['path']);
        
        // A. FASE OCR (Detección de texto "Ancla")
        // Creamos una imagen optimizada (más grande y con contraste) para el OCR
        $ocrScale = $config['processing']['ocr_upscale'] ?? 2.0;
        $prep = clone $originalImg;
        $prep->prepareForOcr($ocrScale);
        $ocrImgPath = $paths['temp'] . "/ocr_prep_{$num}.png";
        $prep->save($ocrImgPath);
        
        // Obtenemos el texto y su posición
        $ocrData = $engine->process($ocrImgPath);

        // B. FASE GEOMETRÍA (Localización de casillas y renglones)
        // Buscamos dónde hay cuadrados y líneas físicas en la hoja original
        $boxes = GeometryDetector::detect($page['path']);

        // C. FASE OMR (Fusión de datos y limpieza de ruido)
        // Vinculamos cada cuadrado físico con el texto más cercano que no sea una pregunta.
        $answers = MarkDetector::detectSequentially($originalImg, $boxes, $ocrData['words'], $engine, 1 / $ocrScale);

        // Guardamos los resultados de esta página en el array final
        $finalResults["Pagina $num"] = $answers;

        // Limpieza de archivos pesados usados durante el OCR de la página
        @unlink($ocrImgPath);
        @unlink($page['path']);
    }

    // PASO 3: GUARDAR RESULTADOS
    // Generamos el archivo JSON estructurado
    $resultFile = $paths['results'] . '/' . pathinfo($pdfPath, PATHINFO_FILENAME) . '.json';
    file_put_contents($resultFile, json_encode($finalResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Generamos un archivo de texto TXT muy legible para humanos
    $txtSummary = "RESUMEN DE ENCUESTA: " . basename($pdfPath) . "\n" . str_repeat("=", 40) . "\n\n";
    foreach ($finalResults as $pageName => $pageAnswers) {
        $txtSummary .= "--- $pageName ---\n";
        if (empty($pageAnswers)) {
            $txtSummary .= "(Sin respuestas detectadas en esta pagina)\n";
        } else {
            foreach ($pageAnswers as $ans) {
                $txtSummary .= "  - $ans\n";
            }
        }
        $txtSummary .= "\n";
    }
    file_put_contents($paths['results'] . '/' . pathinfo($pdfPath, PATHINFO_FILENAME) . '_RESUMEN.txt', $txtSummary);

    echo "\n[EXITO] Procesamiento terminado.\n";
    echo "JSON generado: results/" . basename($resultFile) . "\n";
    echo "Resumen TXT: results/" . pathinfo($pdfPath, PATHINFO_FILENAME) . "_RESUMEN.txt\n";

} catch (Exception $e) {
    Functions::log("ERROR FATAL: " . $e->getMessage(), 'CRITICAL');
    echo "\n[ERROR] " . $e->getMessage() . "\n";
}
