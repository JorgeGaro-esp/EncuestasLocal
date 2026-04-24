<?php
require_once __DIR__ . '/../src/Core/Functions.php';
require_once __DIR__ . '/../src/OMR/GeometryDetector.php';

use App\OMR\GeometryDetector;

$config = require __DIR__ . '/../config/config.php';
$pdfPath = __DIR__ . '/../uploads/Test_1.pdf';
$exportDir = __DIR__ . '/claude_export';
$imgDir = $exportDir . '/img';

if (!file_exists($imgDir)) {
    mkdir($imgDir, 0777, true);
}

echo "--- Exportador de Recortes para Claude ---\n";
echo "[1] Analizando PDF: $pdfPath\n";

try {
    $imagickMain = new \Imagick();
    $imagickMain->pingImage($pdfPath);
    $totalPages = $imagickMain->getNumberImages();
    $imagickMain->clear(); $imagickMain->destroy();

    echo "[INFO] El PDF tiene $totalPages paginas.\n";

    $mapping = [];

    for ($i = 0; $i < $totalPages; $i++) {
        $pageNum = $i + 1;
        echo "[...] Procesando Pagina $pageNum...\n";

        // 1. Extraer página temporal
        $tempPath = $config['paths']['temp'] . "/export_p$pageNum.png";
        $page = new \Imagick();
        $page->setResolution(200, 200);
        $page->readImage($pdfPath . "[$i]");
        
        // --- REPLICAR ENDEREZADO DE GeometryDetector ---
        $page->deskewImage(40 * 655.35); 
        $page->trimImage(0);
        $page->setImagePage(0,0,0,0); 
        $page->writeImage($tempPath);
        
        // 2. Detectar cajas
        $boxes = GeometryDetector::detect($tempPath);

        // 3. Recortar cada checkbox
        foreach ($boxes as $idx => $box) {
            if ($box['type'] !== 'checkbox') continue;

            $qid = $box['question_id'] ?? "q_unknown";
            $col = $box['column_index'] ?? "c_unknown";
            
            $cropName = "p{$pageNum}_{$qid}_col{$col}.png";
            $cropPath = $imgDir . "/" . $cropName;

            // Clonar y recortar
            $crop = clone $page;
            $crop->cropImage($box['w'], $box['h'], $box['x'], $box['y']);
            $crop->writeImage($cropPath);
            $crop->clear(); $crop->destroy();

            $mapping[] = [
                'filename' => $cropName,
                'page' => $pageNum,
                'question' => $qid,
                'column' => $col,
                'x' => $box['x'],
                'y' => $box['y']
            ];
        }

        $page->clear(); $page->destroy();
        @unlink($tempPath);
    }

    file_put_contents($exportDir . '/mapping.json', json_encode($mapping, JSON_PRETTY_PRINT));
    echo "[EXITO] Exportación completada.\n";
    echo "Crops: $imgDir\n";
    echo "Mapping: $exportDir/mapping.json\n";

} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
