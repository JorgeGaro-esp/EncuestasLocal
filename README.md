# Sistema de Procesamiento de Encuestas OMR y Escritura Manual

## Autores
Jorge García Rodríguez  
"For those who came before"

---

## Descripción General
Este sistema permite la digitalización de encuestas impresas mediante técnicas de visión artificial. Utiliza la librería Imagick para detectar la estructura física del documento (casillas y áreas de texto) y el motor Tesseract para interpretar el contenido de las marcas y la escritura a mano.

## Tecnologías Utilizadas
- PHP 8.x
- ImageMagick (Imagick)
- Tesseract OCR (con soporte para idioma español)
- Ghostscript (para el renderizado de PDFs)

## Lógica del Sistema

### 1. Detección Geométrica
Ubicación: src/OMR/GeometryDetector.php
El motor analiza la imagen en tiempo real para encontrar componentes basándose en su densidad de píxeles y geometría. 
Se aplican zonas de prioridad: las áreas de escritura (marcadas en azul en los tests) tienen precedencia. Si el sistema detecta una casilla automática dentro de un área de escritura, la descarta para evitar ruido en los resultados.

### 2. Procesamiento de Escritura a Mano
Se han implementado filtros específicos para capturar bolígrafos de color (como morado o azul):
- Eliminación de renglones: Se genera una máscara horizontal para suprimir las líneas del papel.
- Realce de trazo: Se aumenta la saturación y se aplica un umbral dinámico para que el texto sea legible por el OCR.
- Reducción de ruido: Filtros de limpieza para eliminar impurezas del escaneo.

### 3. Resolución de Conflictos (Gap Filling)
El sistema incluye un algoritmo para preguntas de respuesta cerrada (ej: escalas de valoración):
- Si falta una respuesta o hay ambigüedad, el motor analiza el contexto de la columna para asignar la opción más probable basándose en la confianza del OCR.
- Los campos de escritura libre están protegidos y nunca son modificados por esta lógica.

## Estructura de Salida
Los resultados se guardan en la carpeta runtime/debug:
- full_visual: Imágenes de la encuesta con capas de color para verificación manual.
- crops: Recortes de cada casilla y zona de texto organizados por página.
- metadata.json: Archivo estructurado con todos los datos extraídos.

## Instalación y Uso
1. Instalar Tesseract OCR con los datos para español (spa).
2. Configurar las rutas en config/config.php.
3. Ejecutar el script principal:
   php EncuestasLocal/test_full_visual.php

---
Este proyecto está diseñado para ser robusto ante escaneos con ruido o desviaciones de papel.
