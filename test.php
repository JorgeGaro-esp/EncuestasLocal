<?php
/**
 * DIAGNÓSTICO - EncuestasLocal
 */
require_once __DIR__ . '/src/Core/Functions.php';
require_once __DIR__ . '/src/OCR/TesseractEngine.php';

use App\Core\Functions;
use App\OCR\TesseractEngine;

echo "--- Diagnostico EncuestasLocal ---\n";

$config = require __DIR__ . '/config/config.php';

try {
    // 1. Verificar Extensiones
    echo "[...] Verificando extensiones...\n";
    $exts = ['imagick', 'json', 'mbstring'];
    foreach ($exts as $ext) {
        if (!extension_loaded($ext)) {
            throw new Exception("Falta la extension PHP: $ext");
        }
        echo "    [OK] $ext detectada.\n";
    }

    // 2. Verificar Tesseract
    echo "[...] Verificando Tesseract...\n";
    $engine = new TesseractEngine($config['tesseract']);
    echo "    [OK] Tesseract listo.\n";

    // 3. Verificar Directorios
    echo "[...] Verificando permisos de escritura...\n";
    foreach ($config['paths'] as $name => $path) {
        Functions::ensureDir($path);
        if (!is_writable($path)) {
            throw new Exception("Directorio no escribible: $path");
        }
        echo "    [OK] $name listo.\n";
    }

    echo "\n[EXITO] El entorno local esta correctamente configurado.\n";

} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
}
