<?php
/**
 * Procesamiento de encuestas OMR y escritura manual
 * Autor: Jorge García Rodríguez
 * "For those who came before"
 */

require_once __DIR__ . '/src/Core/Functions.php';
require_once __DIR__ . '/src/Core/SurveyImage.php';
require_once __DIR__ . '/src/OMR/GeometryDetector.php';
require_once __DIR__ . '/src/OCR/TesseractEngine.php';

use App\Core\SurveyImage;
use App\OMR\GeometryDetector;
use App\OCR\TesseractEngine;

// Cargamos la configuracion y preparamos motores
$config = require __DIR__ . '/config/config.php';
$ocr = new TesseractEngine($config['tesseract']);
$pdfPath = __DIR__ . '/uploads/Test_1.pdf';
$outputDir = $config['paths']['debug'] . '/full_visual';
$cropsDir  = $config['paths']['debug'] . '/crops';

// Creamos los directorios de salida si no existen
foreach ([$outputDir, $cropsDir] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

$startTime = microtime(true);
echo "--- Generando Galeria Visual Completa ---\n";
echo "[1] Analizando PDF: $pdfPath\n";

try {
    // Calculamos cuantas paginas tiene el documento
    $imagick = new \Imagick();
    $imagick->pingImage($pdfPath);
    $totalPages = $imagick->getNumberImages();
    $imagick->clear(); $imagick->destroy();

    echo "[INFO] El PDF tiene $totalPages paginas.\n";

    for ($idx_page = 0; $idx_page < $totalPages; $idx_page++) {
        $pageNum = $idx_page + 1;
        echo "[...] Procesando Pagina $pageNum de $totalPages...\n";

        // Carpeta para los recortes de esta pagina concreta
        $pageCropsDir = $cropsDir . "/pagina_" . str_pad($pageNum, 2, '0', STR_PAD_LEFT);
        if (!file_exists($pageCropsDir)) {
            mkdir($pageCropsDir, 0777, true);
        } else {
            // Si ya existia, limpiamos lo que haya dentro
            $oldFiles = glob($pageCropsDir . '/*');
            foreach ($oldFiles as $f) { 
                if (is_file($f)) { 
                    if(is_dir($f)) deleteDir($f); 
                    else unlink($f); 
                } 
            }
        }

        // Subcarpetas para separar checkboxes de escritura manual
        $cbDir = $pageCropsDir . "/checkboxes";
        $hwDir = $pageCropsDir . "/handwriting";
        if (!file_exists($cbDir)) mkdir($cbDir, 0777, true);
        if (!file_exists($hwDir)) mkdir($hwDir, 0777, true);

        // Extraemos la pagina a un PNG temporal para la deteccion geometrica
        $tempPagePath = $config['paths']['temp'] . "/full_visual_p$pageNum.png";
        $page = new \Imagick();
        $page->setResolution(200, 200);
        $page->readImage($pdfPath . "[$idx_page]");
        $page->writeImage($tempPagePath);
        
        // El motor analiza la imagen y nos devuelve las coordenadas de todo lo que ve
        $rawBoxes = GeometryDetector::detect($tempPagePath);
        
        // Filtramos las cajas para evitar solapamientos
        $boxes = [];
        foreach ($rawBoxes as $b) {
            if (!isset($b['center_x'])) {
                $b['center_x'] = $b['x'] + ($b['w'] / 2);
                $b['center_y'] = $b['y'] + ($b['h'] / 2);
            }

            $duplicate = false;
            
            // Prioridad: Si una casilla verde esta dentro de una zona azul (escritura), la ignoramos
            if ($b['type'] === 'checkbox' || !isset($b['type'])) {
                foreach ($rawBoxes as $other) {
                    if (isset($other['type']) && $other['type'] === 'handwriting') {
                        if ($b['center_x'] > $other['x'] && $b['center_x'] < ($other['x'] + $other['w']) &&
                            $b['center_y'] > $other['y'] && $b['center_y'] < ($other['y'] + $other['h'])) {
                            $duplicate = true;
                            break;
                        }
                    }
                }
            }

            if (!$duplicate) {
                foreach ($boxes as $existing) {
                    $dist = sqrt(pow($b['center_x'] - $existing['center_x'], 2) + pow($b['center_y'] - $existing['center_y'], 2));
                    if ($dist < 20) { $duplicate = true; break; }
                }
            }
            if (!$duplicate) $boxes[] = $b;
        }

        // Preparamos la imagen final para el dibujo del overlay
        $fullPage = new \Imagick();
        $fullPage->setResolution(200, 200);
        $fullPage->readImage($pdfPath . "[$idx_page]");
        $fullPage->deskewImage(40 * 655.35); 
        $fullPage->trimImage(0);
        $fullPage->setImagePage(0,0,0,0); 

        $draw = new \ImagickDraw();
        $draw->setFillOpacity(0);
        $draw->setStrokeWidth(2);

        $pageMetadata = [];
        foreach ($boxes as $bidx => $box) {
            $type = $box['type'] ?? 'checkbox';
            
            // Marcamos el borde: verde para casillas, azul para escritura
            if ($type === 'checkbox') {
                $draw->setStrokeColor('green');
            } elseif ($type === 'handwriting') {
                $draw->setStrokeColor('blue');
            }

            if ($type === 'checkbox' || $type === 'handwriting') {
                
                // Mapeo manual de preguntas segun la altura (Y) en el papel
                $y = $box['y'];
                if ($y < 850) $box['question_id'] = 'pregunta1';
                elseif ($y < 1100) $box['question_id'] = 'pregunta2';
                elseif ($y < 1400) $box['question_id'] = 'pregunta3';
                elseif ($y < 1700) $box['question_id'] = 'pregunta4';
                elseif ($y < 2000) $box['question_id'] = 'pregunta5';
                else $box['question_id'] = 'pregunta_extra_' . (int)($y / 300);
                
                $qid = $box['question_id'] ?? "q_unknown";
                $col = $box['column_index'] ?? "c_unknown";
                $cropName = "box_{$bidx}_{$qid}_col{$col}.png";
                
                $subDir = ($type === 'handwriting') ? "handwriting" : "checkboxes";
                $cropPath = $pageCropsDir . "/" . $subDir . "/" . $cropName;
                
                $crop = clone $fullPage;
                $crop->cropImage($box['w'], $box['h'], $box['x'], $box['y']);
                
                if ($type === 'handwriting') {
                    $crop->resizeImage($box['w'] * 3, $box['h'] * 3, \Imagick::FILTER_LANCZOS, 1);
                    
                    // Borramos renglones para no confundir al OCR
                    $lineMask = clone $crop;
                    $lineMask->negateImage(false);
                    $lineMask->thresholdImage(0.5 * 65535); 
                    $kernel = \ImagickKernel::fromBuiltIn(\Imagick::KERNEL_RECTANGLE, "60x1");
                    $lineMask->morphology(\Imagick::MORPHOLOGY_OPEN, 1, $kernel);
                    $crop->compositeImage($lineMask, \Imagick::COMPOSITE_DSTOUT, 0, 0);
                    
                    // Realce del texto (especialmente para bolis de color)
                    $crop->modulateImage(100, 200, 100); 
                    $crop->thresholdImage(0.65 * 65535); 
                    $crop->despeckleImage();
                    
                    $crop->writeImage($cropPath);
                } else {
                    // Procesado estandar para casillas
                    $crop->resizeImage($box['w'] * 3, $box['h'] * 3, \Imagick::FILTER_LANCZOS, 1);
                    $crop->thresholdImage(0.6 * 65535); 
                    $crop->writeImage($cropPath);
                }
                
                $crop->clear(); $crop->destroy();

                $ocrResult = $ocr->process($cropPath);
                $rawText = trim($ocrResult['text'] ?? '');
                
                // Correccion de texto para casillas (autocorreccion difusa)
                $validOptions = [
                    "Nada interesante", "Poco interesante", "Interesante", "Bastante interesante",
                    "Nada importante", "Poco importante", "Importante", "Bastante importante",
                    "Si", "No"
                ];
                
                $text = $rawText; 
                $bestScore = 0;
                
                if ($type === 'handwriting') {
                    $text = $rawText;
                    $bestScore = 50; 
                } else {
                    $cleanOCR = preg_replace('/[^a-zA-ZáéíóúÁÉÍÓÚñÑ0]/u', '', $rawText);
                    foreach ($validOptions as $opt) {
                        $cleanOpt = preg_replace('/[^a-zA-ZáéíóúÁÉÍÓÚñÑ]/u', '', $opt);
                        similar_text(mb_strtolower($cleanOCR), mb_strtolower($cleanOpt), $percent);
                        $contains = (mb_strlen($cleanOCR) > 1 && mb_stripos($cleanOCR, $cleanOpt) !== false);
                        $threshold = (mb_strlen($cleanOpt) <= 2) ? 25 : 40;

                        if (($percent > $threshold || $contains) && $percent > $bestScore) {
                            $bestScore = $percent;
                            $text = $opt;
                        }
                    }
                }
                
                // Rescate de respuestas Si/No cuando la marca es muy grande
                if ($bestScore < 25 && mb_strlen($cleanOCR) > 0) {
                    $norm = mb_strtolower($cleanOCR);
                    if (str_starts_with($norm, 's') || str_ends_with($norm, 'i') || $norm == 'so') {
                        $text = "Si";
                        $bestScore = 20;
                    } elseif (str_starts_with($norm, 'n') || str_contains($norm, 'o') || $norm == 'ae' || $norm == 'xx' || $norm == 'lg') {
                        $text = "No";
                        $bestScore = 20;
                    } 
                    elseif ($col == 1 && ($qid == 'pregunta2' || $qid == 'pregunta3')) {
                        $text = "No";
                        $bestScore = 15;
                    }
                }

                $pageMetadata[] = [
                    'filename' => $subDir . "/" . $cropName,
                    'text' => $text,
                    'type' => $type,
                    'ocr_raw' => $rawText,
                    'ocr_score' => $bestScore,
                    'question_id' => $qid,
                    'column_index' => $col,
                    'x' => $box['x'],
                    'y' => $box['y'],
                    'w' => $box['w'],
                    'h' => $box['h']
                ];

            } elseif ($box['type'] === 'writing_line') {
                $draw->setStrokeColor('blue');
            } else {
                continue;
            }
            $draw->rectangle($box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h']);
        }

        $fullPage->drawImage($draw);
        
        // Guardamos el resultado en JPG para no saturar el disco
        $fullPagePath = $outputDir . "/pagina_" . str_pad($pageNum, 2, '0', STR_PAD_LEFT) . ".jpg";
        if (file_exists($fullPagePath)) @unlink($fullPagePath);
        
        $fullPage->setImageFormat('jpeg');
        $fullPage->setImageCompressionQuality(80);
        $fullPage->writeImage($fullPagePath);

        // Logica para rellenar huecos si falta alguna respuesta en el set
        $groups = [];
        foreach ($pageMetadata as $idx => $item) {
            $groups[$item['question_id']][] = $idx;
        }

        foreach ($groups as $qid => $indices) {
            if (count($indices) <= 1) continue;

            $set = [];
            if ($qid === 'pregunta1') {
                $set = ["Nada interesante", "Poco interesante", "Interesante", "Bastante interesante"];
            } elseif ($qid === 'pregunta2') {
                $set = ["Nada importante", "Poco importante", "Importante", "Bastante importante"];
            } elseif ($qid === 'pregunta3') {
                $set = ["Si", "No"];
            } else {
                continue;
            }

            $currentTexts = [];
            foreach ($indices as $idx) $currentTexts[] = $pageMetadata[$idx]['text'];
            $counts = array_count_values($currentTexts);
            $missing = array_diff($set, $currentTexts);
            
            if (!empty($missing)) {
                foreach ($missing as $mItem) {
                    $targetIdx = -1;
                    $lowestScore = 101;

                    foreach ($indices as $idx) {
                        // Solo corregimos casillas, nunca escritura libre
                        if ($pageMetadata[$idx]['type'] !== 'checkbox') continue;

                        $currText = $pageMetadata[$idx]['text'];
                        $isInvalid = !in_array($currText, $set);
                        $isDuplicate = ($counts[$currText] ?? 0) > 1;

                        if ($isInvalid || $isDuplicate) {
                            if ($pageMetadata[$idx]['ocr_score'] < $lowestScore) {
                                $lowestScore = $pageMetadata[$idx]['ocr_score'];
                                $targetIdx = $idx;
                            }
                        }
                    }

                    if ($targetIdx != -1) {
                        $pageMetadata[$targetIdx]['text'] = $mItem;
                        $pageMetadata[$targetIdx]['resolved_by'] = "gap_filler_exclusion";
                        $currentTexts = [];
                        foreach ($indices as $i) $currentTexts[] = $pageMetadata[$i]['text'];
                        $counts = array_count_values($currentTexts);
                    }
                }
            }
        }
        
        // Guardamos el JSON de resultados de la pagina
        file_put_contents($pageCropsDir . "/metadata.json", json_encode($pageMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Filtramos para tener un JSON solo con los campos manuscritos
        $handwritingMetadata = array_filter($pageMetadata, function($item) {
            return $item['type'] === 'handwriting';
        });
        if (!empty($handwritingMetadata)) {
            file_put_contents($hwDir . "/handwriting_data.json", json_encode(array_values($handwritingMetadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $fullPage->clear(); $fullPage->destroy();
        @unlink($tempPagePath);
        gc_collect_cycles();

        echo "    [OK] Imagen y Crops guardados.\n";
    }

    $duration = microtime(true) - $startTime;
    echo "\n[EXITO] Galeria completada en " . round($duration, 2) . " segundos.\n";
    echo "Revisa la carpeta: $outputDir\n";

} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}

/**
 * Borrado recursivo de directorios
 */
function deleteDir($dirPath) {
    if (!is_dir($dirPath)) return;
    $files = array_diff(scandir($dirPath), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dirPath/$file")) ? deleteDir("$dirPath/$file") : unlink("$dirPath/$file");
    }
    return rmdir($dirPath);
}
