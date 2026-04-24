<?php
/**
 * ARCHIVO: test_geometry.php
 * --------------------------
 * PROPÓSITO: Es una herramienta de diagnóstico visual. 
 * Permite ver qué es lo que el motor de "visión" está detectando en tiempo real.
 */
require_once __DIR__ . '/src/Core/Functions.php';
require_once __DIR__ . '/src/OMR/GeometryDetector.php';

use App\Core\Functions;
use App\OMR\GeometryDetector;

echo "--- Herramienta de Diagnóstico de Visión ---\n";

$config = require __DIR__ . '/config/config.php';
$paths = $config['paths'];

try {
    // Buscamos un PDF para hacer la prueba
    $pdfFiles = glob($paths['uploads'] . '/*.pdf');
    if (empty($pdfFiles)) throw new Exception("Pon un PDF en 'uploads' para probar.");
    
    $pdfPath = $pdfFiles[0];
    echo "[...] Procesando primera pagina de: " . basename($pdfPath) . "\n";
    
    // Extraemos la primera página para el test
    $imagick = new \Imagick();
    $imagick->setResolution(200, 200); 
    $imagick->readImage($pdfPath . '[0]'); 
    
    // VERIFICACIÓN DE BRILLO:
    // Analizamos el brillo medio de la imagen para saber si el escaneo es muy claro u oscuro.
    $stats = $imagick->getImageChannelMean(\Imagick::CHANNEL_RED);
    $qr = $imagick->getQuantumRange();
    $range = $qr['quantumRangeLarge'] ?? $qr['quantumRange'] ?? 65535;
    $brightness = ($stats['mean'] / $range) * 100;
    echo "[INFO] Brillo medio de la pagina: " . round($brightness, 1) . "%\n";

    $testPage = $paths['temp'] . '/test_geometry_page.png';
    $imagick->writeImage($testPage);

    // Ejecutamos la detección geométrica (con el nuevo algoritmo de contraste corregido)
    echo "[...] Detectando formas geometricas...\n";
    $boxes = GeometryDetector::detect($testPage);
    echo "    Resultado: " . count($boxes) . " elementos encontrados.\n";

    // DIBUJO DE OVERLAY:
    // Creamos una capa visual para marcar los hallazgos.
    $draw = new \ImagickDraw();
    $draw->setFillOpacity(0); // Rectángulos sin fondo (solo borde)
    $draw->setStrokeWidth(2);

    foreach ($boxes as $box) {
        if ($box['type'] === 'checkbox') {
            $draw->setStrokeColor('green'); // Casillas en verde
        } elseif ($box['type'] === 'writing_line') {
            $draw->setStrokeColor('blue');  // Renglones en azul
        } else {
            $draw->setStrokeColor('red');   // Otros objetos en rojo
        }
        
        // Dibujamos el rectángulo
        $draw->rectangle($box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h']);
    }

    $imagick->drawImage($draw);
    $overlayPath = $paths['debug'] . '/debug_geometry_overlay.png';
    $imagick->writeImage($overlayPath);

    echo "\n[EXITO] Diagnostico completado.\n";
    echo "Imagen de control: " . realpath($overlayPath) . "\n";
    echo "Fijate si ahora los rectangulos verdes coinciden con tus casillas.\n";

} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
}
