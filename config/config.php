<?php
/**
 * CONFIGURACIÓN - EncuestasLocal (Tesseract Native)
 */

return [
    'tesseract' => [
        'path' => 'C:\\Users\\Jorgegaro\\AppData\\Local\\Programs\\Tesseract-OCR\\tesseract.exe',
        'language' => 'spa',
        'psm' => 6, // Sparse text with orientation and script detection
    ],
    'paths' => [
        'root' => dirname(__DIR__),
        'runtime' => dirname(__DIR__) . '/runtime',
        'temp' => dirname(__DIR__) . '/runtime/temp',
        'debug' => dirname(__DIR__) . '/runtime/debug',
        'logs' => dirname(__DIR__) . '/runtime/logs',
        'uploads' => dirname(__DIR__) . '/uploads',
        'results' => dirname(__DIR__) . '/results',
    ],
    'processing' => [
        'ocr_upscale' => 2.0,      // Aumento para mejor legibilidad de Tesseract
        'contrast_enhance' => true,
        'save_debug_images' => true,
        'max_execution_time' => 1200, // 20 minutos para PDFs grandes
    ],
    'debug' => true
];
