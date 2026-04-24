<?php
require_once __DIR__ . '/../src/Core/Functions.php';
require_once __DIR__ . '/../src/OMR/GeometryDetector.php';
use App\OMR\GeometryDetector;

$config = require __DIR__ . '/../config/config.php';
$pdfPath = $config['paths']['uploads'] . '/Test_1.pdf';
$targetPages = [6, 10, 11, 16]; // 0-indexed: 7, 11, 12, 17

foreach ($targetPages as $pageIdx) {
    echo "Processing Page " . ($pageIdx + 1) . "...\n";
    $imagick = new \Imagick();
    $imagick->setResolution(200, 200); 
    $imagick->readImage($pdfPath . '[' . $pageIdx . ']'); 
    
    $testPage = __DIR__ . "/page_" . ($pageIdx + 1) . ".png";
    $imagick->writeImage($testPage);
    
    $boxes = GeometryDetector::detect($testPage);
    
    // Obtenemos las anclas para dibujarlas también (esto requiere que detect las devuelva o las loguee)
    // Para no complicar detect, las buscaremos de nuevo aquí para el debug
    
    $draw = new \ImagickDraw();
    $draw->setFillOpacity(0);
    $draw->setStrokeWidth(2);
    
    foreach ($boxes as $box) {
        $draw->setStrokeColor($box['type'] === 'checkbox' ? 'green' : 'blue');
        $draw->rectangle($box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h']);
    }
    $imagick->drawImage($draw);
    $imagick->writeImage(__DIR__ . "/debug_v7_page_" . ($pageIdx + 1) . ".png");
}
echo "Done! Check runtime/debug_v7_page_X.png\n";
        $draw->rectangle($box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h']);
    }
    $debugImg->drawImage($draw);
    $debugImg->writeImage(__DIR__ . "/debug_v11_page_$pageNum.png");
    
    @unlink($testPage);
}

// GUARDAR JSON FINAL
$resPath = __DIR__ . '/omr_results.json';
file_put_contents($resPath, json_encode($allResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n============================================\n";
echo "✓ PROCESO COMPLETADO\n";
echo "✓ JSON GUARDADO EN: " . realpath($resPath) . "\n";
echo "✓ IMÁGENES DEBUG EN: " . __DIR__ . "\n";
echo "============================================\n\n";
