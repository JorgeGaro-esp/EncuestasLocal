<?php
namespace App\OMR;

use Imagick;
use ImagickKernel;
use Exception;

/**
 * CLASE: GeometryDetector (v39 - Enderezado Físico Total)
 * =======================================================
 * Endereza físicamente la imagen al inicio del proceso y realiza la 
 * detección sobre una base 100% nivelada y centrada.
 */
class GeometryDetector
{
    private const THRESHOLD = 0.79;
    private const ANCHOR_MIN_RATIO = 0.40; 
    private const ROW_Y_TOLERANCE = 55;
    private const LOG_FILE = __DIR__ . '/../../runtime/logs/omr_vision.log';

    public static function detect(string $imagePath): array
    {
        ini_set('memory_limit', '1024M');

        if (!class_exists('Imagick')) {
            throw new Exception("La extensión Imagick es necesaria.");
        }

        $config    = require dirname(__DIR__, 2) . '/config/config.php';
        $imagick   = new Imagick($imagePath);
        
        // --- 1. ENDEREZADO FÍSICO INICIAL ---
        // Lo hacemos sobre la imagen original para no perder calidad
        $imagick->deskewImage(40 * 655.35); 
        $imagick->trimImage(0);
        $imagick->setImagePage(0,0,0,0); 

        $originalW = $imagick->getImageWidth();
        $originalH = $imagick->getImageHeight();
        $scaledW   = (int)($originalW / 2);
        $scaledH   = (int)($originalH / 2);
        $scale     = $originalW / $scaledW;

        // Reescalado para procesamiento rápido
        $imagick->resizeImage($scaledW, $scaledH, Imagick::FILTER_BOX, 1);
        $imagick->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $imagick->setImageType(Imagick::IMGTYPE_GRAYSCALE);

        $binary = clone $imagick;
        $binary->thresholdImage(self::THRESHOLD * 65535);
        $binary->negateImage(false);

        // 2. DETECCIÓN DE ANCLAS (En imagen recta)
        $anchors = self::findBulletproofAnchors($binary, $scale);
        
        // 3. MÁSCARAS DE GEOMETRÍA
        $gridRobust = self::createGrid($binary, (int)(32/$scale), (int)(18/$scale));
        $gridRobust->negateImage(false);
        $gridFine = self::createGrid($binary, (int)(18/$scale), (int)(7/$scale));
        $gridFine->negateImage(false);

        // 4. DETECCIÓN ZONAL RESILIENTE
        $allCandidates = self::detectWithResilience($gridRobust, $gridFine, $anchors, $scaledW, $scaledH, $scale);

        // 5. VALIDACIÓN DE CALIDAD
        $imgW = $imagick->getImageWidth();
        $imgH = $imagick->getImageHeight();
        $binaryPixels = $binary->exportImagePixels(0, 0, $imgW, $imgH, "I", Imagick::PIXEL_CHAR);
        
        foreach ($allCandidates as &$c) {
            if ($c['type'] === 'checkbox') {
                $sb = $c['_scaled_box'];
                $c['solid_sides'] = self::countSolidEdges($binaryPixels, $sb['x'], $sb['y'], $sb['w'], $sb['h'], $imgW, $imgH);
                $c['interior_density'] = self::checkInteriorDensity($binaryPixels, $sb['x'], $sb['y'], $sb['w'], $sb['h'], $imgW, $imgH);
            }
        }

        $final = self::applyPrecisionFilter($allCandidates);
        
        // --- 6. RECUPERACIÓN DE METADATOS (Mapeo de zonas Y y X) ---
        foreach ($final as &$box) {
            if ($box['type'] === 'checkbox') {
                $y = $box['y'];
                $x = $box['x'];
                
                // Determinamos ID de pregunta por Y
                // Determinamos ID de pregunta por Y (Ajustado para detectar más de 3)
                // RANGOS AJUSTADOS: Muy granulares para evitar mezclar bloques
                if ($y < 850) $box['question_id'] = 'pregunta1';
                elseif ($y < 1100) $box['question_id'] = 'pregunta2';
                elseif ($y < 1400) $box['question_id'] = 'pregunta3';
                elseif ($y < 1700) $box['question_id'] = 'pregunta4';
                elseif ($y < 2000) $box['question_id'] = 'pregunta5';
                else $box['question_id'] = 'pregunta_extra_' . (int)($y / 300);
                
                // Determinamos columna por X (Aproximación estructural)
                // Empiezan en ~380 y tienen ~280 de ancho
                if (isset($box['question_id'])) {
                    $relX = $x - 380;
                    if ($relX < 0) $box['column_index'] = 0;
                    else $box['column_index'] = (int)($relX / 280);
                }
            }
        }

        // Limpieza de memoria
        foreach ([$imagick, $binary, $gridRobust, $gridFine] as $obj) {
            $obj->clear(); $obj->destroy();
        }

        // --- NUEVO: AÑADIR ZONAS AZULES (ESCRITURA MANUSCRITA) ---
        $handwritingZones = [
            [
                'type' => 'handwriting',
                'question_id' => 'pregunta3_abierta',
                'x' => 240, 'y' => 1300, 'w' => 1020, 'h' => 200
            ],
            [
                'type' => 'handwriting',
                'question_id' => 'pregunta4_abierta',
                'x' => 240, 'y' => 1560, 'w' => 1020, 'h' => 380
            ]
        ];
        
        foreach ($handwritingZones as $zone) {
            $final[] = $zone;
        }

        return $final;
    }

    private static function findBulletproofAnchors(Imagick $binary, float $scale): array
    {
        $w = $binary->getImageWidth(); $h = $binary->getImageHeight();
        
        // --- BOOST CONTRAST PARA ANCLAS ---
        // Aplicamos un realce de contraste solo en la franja izquierda para anclas tenues
        $searchW = (int)($w * 0.20);
        $anchorBinary = clone $binary;
        $anchorBinary->cropImage($searchW, $h, 0, 0);
        $anchorBinary->sigmoidalContrastImage(true, 6.0, 0.5 * 65535); // Realza negros tenues
        
        $pixels = $anchorBinary->exportImagePixels(0, 0, $searchW, $h, "I", Imagick::PIXEL_CHAR);
        $visited = array_fill(0, $searchW * $h, false);
        $found = [];

        for ($y = 20; $y < $h - 20; $y += 5) {
            for ($x = 20; $x < $searchW - 20; $x += 5) {
                $idx = $y * $searchW + $x;
                if ($pixels[$idx] > 200 && !$visited[$idx]) {
                    $box = self::floodFillBox($pixels, $x, $y, $searchW, $h, $visited, 2);
                    $rw = $box['w'] * $scale; $rh = $box['h'] * $scale;
                    $maxDim = max($rw, $rh);
                    if ($maxDim <= 0) continue;
                    $ratio = min($rw, $rh) / $maxDim;
                    
                    // Al estar la imagen recta, el ratio debe ser casi perfecto
                    // Aumentamos el rango de tamaño para anclas (de 35 a 150)
                    if ($rw > 35 && $rw < 150 && $rh > 35 && $rh < 150 && $ratio > self::ANCHOR_MIN_RATIO) {
                        $found[] = [
                            'x' => $box['x'],
                            'y' => $box['y'],
                            'w' => $box['w'],
                            'h' => $box['h'],
                            'realX' => $box['x'] * $scale,
                            'realY' => $box['y'] * $scale
                        ];
                    }
                }
            }
        }

        usort($found, fn($a, $b) => $a['realY'] <=> $b['realY']);
        
        // Agrupación por proximidad Y para eliminar duplicados de la misma ancla
        $unique = [];
        foreach ($found as $a) {
            if (empty($unique) || abs($a['realY'] - $unique[count($unique)-1]['realY']) > 150) {
                $unique[] = $a;
            }
        }

        // Si hay más de 4, filtramos por alineación X (las anclas están a la izquierda)
        if (count($unique) > 4) {
             usort($unique, fn($a, $b) => $a['realX'] <=> $b['realX']);
             $unique = array_slice($unique, 0, 4); // Nos quedamos con las 4 más a la izquierda
             usort($unique, fn($a, $b) => $a['realY'] <=> $b['realY']);
        }

        // Si faltan anclas (páginas 7, 11, 12, 17), intentamos predecir las faltantes basándonse en el espaciado
        if (count($unique) > 0 && count($unique) < 4) {
            $complete = [];
            $firstY = $unique[0]['realY'];
            // Buscamos si la que hemos encontrado es realmente la primera o alguna intermedia
            // El espaciado estándar entre anclas es de unos 530px
            for ($i = 0; $i < 4; $i++) {
                $targetY = $firstY + ($i * 530); 
                $closest = null;
                foreach ($unique as $u) {
                    if (abs($u['realY'] - $targetY) < 250) { 
                        $closest = $u; 
                        break; 
                    }
                }
                if ($closest) {
                    $complete[] = $closest;
                } else {
                    $complete[] = [
                        'realY' => $targetY, 
                        'y' => $targetY / $scale, 
                        'realX' => $unique[0]['realX'],
                        'x' => $unique[0]['realX'] / $scale,
                        'estimated' => true
                    ];
                }
            }
            return $complete;
        }
        return $unique;
    }

    private static function detectWithResilience(Imagick $robust, Imagick $fine, array $anchors, int $sw, int $sh, float $scale): array
    {
        $all = [];
        $w = $robust->getImageWidth();
        $h = $robust->getImageHeight();
        $numAnchors = count($anchors);

        if ($numAnchors === 0) {
            return array_merge(
                self::extractHoles($robust, $w, $h, $scale, ['minW'=>80,'maxW'=>420,'minH'=>45,'maxH'=>160, 'minRatio'=>1.1, 'maxRatio'=>4.5]),
                self::extractHoles($fine, $w, $h, $scale, ['minW'=>350,'maxW'=>620,'minH'=>45,'maxH'=>160, 'minRatio'=>3.0, 'maxRatio'=>9.0])
            );
        }

        for ($i = 0; $i < $numAnchors; $i++) {
            $startY = (int)(max(0, $anchors[$i]['y'] - (35 / $scale)));
            
            // WINDOWING ULTRA-ABSOLUTO: Las tablas están pegadas al ancla.
            // Reducimos a 150px para descartar firmas o texto de aclaración de abajo.
            $roiH_real = ($i < 3) ? 150 : 600; 
            $roiH = (int)($roiH_real / $scale);
            
            if ($i < 3) {
                self::log("Zona " . ($i+1) . " (Q" . ($i+1) . "): ROI absoluta en Y=$startY + $roiH");
            }

            $zoneRobust = clone $robust; $zoneRobust->cropImage($w, $roiH, 0, $startY);
            $zoneFine = clone $fine; $zoneFine->cropImage($w, $roiH, 0, $startY);

            $currentZoneCandidates = [];
            if ($i < 3) {
                $numCols = ($i === 2) ? 2 : 4;
                // Métrica de Tabla: Permitimos anchos grandes para todas las zonas Q1, Q2, Q3
                $params = ['minW'=>30,'maxW'=>1600,'minH'=>20,'maxH'=>230, 'minRatio'=>0.4, 'maxRatio'=>30.0];
                
                $source = ($i === 2) ? $zoneFine : $zoneRobust;
                $cand = self::extractHoles($source, $w, $roiH, $scale, $params, $startY);
                
                // Filtro para eliminar anclas y ruido lejano al ancla Y
                $filtered = [];
                foreach ($cand as $c) { 
                    // FILTRO CRÍTICO: La tabla debe empezar justo debajo del ancla (35px a 85px reales)
                    $realAnchorY = $anchors[$i]['y'] * $scale;
                    $yDist = $c['y'] - $realAnchorY;
                    
                    // La tabla real suele estar entre 10px y 110px reales por debajo del centro del ancla
                    if ($c['x'] > 150 && $yDist > 10 && $yDist < 120) {
                        $filtered[] = $c; 
                    }
                }
                
                // RECONSTRUCCIÓN DE GRILLA RELATIVA AL ANCLA
                // Calculamos el inicio de la tabla (X) relativo al ancla física
                $anchorX = $anchors[$i]['realX'] ?? ($anchors[$i]['x'] * $scale);
                $minX = $anchorX + 150; 
                $masterW = 950;
                $masterH = (70 / $scale);
                
                // Si encontramos fragmentos reales, ajustamos levemente la Y para seguir la línea
                if (!empty($filtered)) {
                    $minY = min(array_column($filtered, 'y'));
                } else {
                    $minY = ($anchors[$i]['realY'] ?? ($anchors[$i]['y'] * $scale)) + (35 / $scale);
                }

                $stepW = $masterW / $numCols;
                for ($j = 0; $j < $numCols; $j++) {
                    $colX = $minX + ($j * $stepW);
                    $currentZoneCandidates[] = [
                        'x' => (int)$colX,
                        'y' => (int)$minY,
                        'w' => (int)$stepW,
                        'h' => (int)$masterH,
                        'center_x' => $colX + $stepW/2,
                        'center_y' => $minY + $masterH/2,
                        'original_y' => $minY,
                        'type' => 'checkbox',
                        'question_id' => 'pregunta' . ($i + 1),
                        'column_index' => $j,
                        'is_forced' => true,
                        '_scaled_box' => ['x' => $colX/$scale, 'y' => $minY/$scale, 'w' => $stepW/$scale, 'h' => $masterH/$scale]
                    ];
                }
            } else {
                // Zona 4: Solo líneas de escritura
                $currentZoneCandidates = self::extractHoles($zoneRobust, $w, $roiH, $scale, ['minW'=>600,'maxW'=>2200,'minH'=>8,'maxH'=>120, 'minRatio'=>5.0, 'maxRatio'=>150.0], $startY, 'writing_line');
            }

            foreach ($currentZoneCandidates as $c) {
                $isDuplicate = false;
                foreach ($all as $prev) {
                    if (abs($c['center_x'] - $prev['center_x']) < 50 && abs($c['center_y'] - $prev['center_y']) < 50) {
                        $isDuplicate = true; break;
                    }
                }
                if (!$isDuplicate) $all[] = $c;
            }
            $zoneRobust->clear(); $zoneFine->clear();
        }
        return $all;
    }

    private static function applyPrecisionFilter(array $candidates): array
    {
        $checkboxes = []; $writingLines = [];
        foreach ($candidates as $c) {
            if ($c['type'] === 'writing_line') $writingLines[] = $c;
            else $checkboxes[] = $c;
        }
        if (empty($checkboxes)) return $writingLines;

        $heights = array_map(fn($b) => $b['h'], $checkboxes);
        sort($heights);
        $medianH = $heights[floor((count($heights) - 1) / 2)];

        $final = [];
        foreach ($checkboxes as $box) {
            $hRatio = $box['h'] / max(1, $medianH);
            if ($hRatio < 0.65 || $hRatio > 1.35) continue;
            
            // Refinamos: Una casilla suele tener al menos 1 lado sólido. 
            // Si el recuadro es FORZADO (proyectado), confiamos en él aunque sea tenue.
            $isForced = $box['is_forced'] ?? false;
            if (($isForced || $box['solid_sides'] >= 1) && $box['interior_density'] < 0.95) {
                unset($box['_scaled_box'], $box['solid_sides'], $box['interior_density'], $box['is_forced']);
                $final[] = $box;
            }
        }
        return array_merge($final, $writingLines);
    }

    private static function extractHoles(Imagick $mask, int $targetW, int $targetH, float $scale, array $limits, int $offsetY = 0, string $forcedType = 'checkbox'): array
    {
        $w = $mask->getImageWidth(); $h = $mask->getImageHeight();
        try { $pixels = $mask->exportImagePixels(0, 0, $w, $h, "I", Imagick::PIXEL_CHAR); } catch (\Exception $e) { return []; }
        $visited = array_fill(0, $w * $h, false); $candidates = []; $step = 3;
        for ($y = 5; $y < $h - 5; $y += $step) {
            for ($x = 10; $x < $w - 10; $x += $step) {
                $idx = $y * $w + $x;
                if ($pixels[$idx] > 200 && !$visited[$idx]) {
                    $box = self::floodFillBox($pixels, $x, $y, $w, $h, $visited, $step);
                    $rw = (int)($box['w'] * $scale); $rh = (int)($box['h'] * $scale);
                    $rx = (int)($box['x'] * $scale); $ry = (int)(($box['y'] + $offsetY) * $scale);
                    $ratio = $rw / max(1, $rh);
                    if ($rw >= $limits['minW'] && $rw <= $limits['maxW'] && $rh >= $limits['minH'] && $rh <= $limits['maxH'] && $ratio >= $limits['minRatio'] && $ratio <= $limits['maxRatio']) {
                         $candidates[] = ['x' => $rx, 'y' => $ry, 'w' => $rw, 'h' => $rh, 'center_x' => $rx + $rw/2, 'center_y' => $ry + $rh/2, 'type' => $forcedType, '_scaled_box' => ['x' => $box['x'], 'y' => $box['y'] + $offsetY, 'w' => $box['w'], 'h' => $box['h']]];
                    }
                }
            }
        }
        return $candidates;
    }

    private static function createGrid(Imagick $binary, int $kh, int $kv): Imagick
    {
        $hLines = clone $binary;
        $kernelH = ImagickKernel::fromBuiltIn(Imagick::KERNEL_RECTANGLE, "{$kh}x1");
        $hLines->morphology(Imagick::MORPHOLOGY_OPEN, 1, $kernelH);
        $vLines = clone $binary;
        $kernelV = ImagickKernel::fromBuiltIn(Imagick::KERNEL_RECTANGLE, "1x{$kv}");
        $vLines->morphology(Imagick::MORPHOLOGY_OPEN, 1, $kernelV);
        $grid = clone $hLines; $grid->compositeImage($vLines, Imagick::COMPOSITE_PLUS, 0, 0);
        $dilateK = ImagickKernel::fromBuiltIn(Imagick::KERNEL_RECTANGLE, "2x2");
        $grid->morphology(Imagick::MORPHOLOGY_DILATE, 1, $dilateK);
        $grid->morphology(Imagick::MORPHOLOGY_CLOSE, 1, $dilateK);
        $grid->borderImage('black', 5, 5);
        $hLines->clear(); $vLines->clear();
        return $grid;
    }

    private static function countSolidEdges($pixels, $x, $y, $w, $h, $imgW, $imgH): int
    {
        $solid = 0; $t = 0.14; 
        if (self::getAreaDensity($pixels, $x, $y-2, $w, 4, $imgW, $imgH) > $t) $solid++;
        if (self::getAreaDensity($pixels, $x, $y+$h-2, $w, 4, $imgW, $imgH) > $t) $solid++;
        if (self::getAreaDensity($pixels, $x-2, $y, 4, $h, $imgW, $imgH) > $t) $solid++;
        if (self::getAreaDensity($pixels, $x+$w-2, $y, 4, $h, $imgW, $imgH) > $t) $solid++;
        return $solid;
    }

    private static function checkInteriorDensity($pixels, $x, $y, $w, $h, $imgW, $imgH): float
    {
        $marginW = (int)($w * 0.25); $marginH = (int)($h * 0.25);
        return self::getAreaDensity($pixels, $x + $marginW, $y + $marginH, $w - 2*$marginW, $h - 2*$marginH, $imgW, $imgH);
    }

    private static function getAreaDensity($pixels, $x, $y, $w, $h, $imgW, $imgH): float
    {
        $dark = 0; $total = 0;
        for ($py = $y; $py < $y + $h; $py++) {
            for ($px = $x; $px < $x + $w; $px++) {
                if ($px < 0 || $py < 0 || $px >= $imgW || $py >= $imgH) continue;
                if ($pixels[$py * $imgW + $px] > 128) $dark++;
                $total++;
            }
        }
        return $total > 0 ? $dark / $total : 0.0;
    }

    private static function floodFillBox($pixels, $sx, $sy, $w, $h, &$visited, $step): array
    {
        $minX = $sx; $maxX = $sx; $minY = $sy; $maxY = $sy;
        $stack = [[$sx, $sy]];
        $count = 0;
        while (!empty($stack) && $count < 100000) {
            $count++;
            [$cx, $cy] = array_pop($stack);
            $idx = $cy * $w + $cx;
            if ($visited[$idx]) continue;
            $visited[$idx] = true;
            $minX = min($minX, $cx); $maxX = max($maxX, $cx);
            $minY = min($minY, $cy); $maxY = max($maxY, $cy);
            foreach ([[$cx+2,$cy],[$cx-2,$cy],[$cx,$cy+2],[$cx,$cy-2]] as [$nx,$ny]) {
                if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                    if ($pixels[$ny * $w + $nx] > 180 && !$visited[$ny * $w + $nx]) {
                        $stack[] = [$nx, $ny];
                    }
                }
            }
        }
        return ['x'=>$minX,'y'=>$minY,'w'=>$maxX-$minX,'h'=>$maxY-$minY];
    }
    private static function log(string $msg): void
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents(self::LOG_FILE, "[$timestamp] $msg\n", FILE_APPEND);
    }
}
