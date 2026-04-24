@echo off
setlocal enabledelayedexpansion

echo ======================================================
echo    ENCUESTAS LOCAL - Sistema de Procesamiento
echo ======================================================
echo.

:: Verificar si hay algún PDF en la carpeta uploads
set "PDF_FOUND=N"
for %%i in (uploads\*.pdf) do set "PDF_FOUND=Y"

if "!PDF_FOUND!"=="N" (
    echo [ALERTA] No se han encontrado archivos PDF en la carpeta 'uploads'.
    echo Por favor, copia tus PDF alli y vuelve a intentarlo.
    echo.
    pause
    exit /b
)

echo [1/2] Ejecutando diagnostico del sistema...
C:\xampp\php\php.exe test.php
if %errorlevel% neq 0 (
    echo [ERROR] El diagnostico ha fallado. Revisa los errores arriba.
    pause
    exit /b
)

echo.
echo [2/2] Iniciando procesamiento de encuestas...
echo Este proceso puede tardar unos minutos dependiendo de las paginas del PDF.
echo.

C:\xampp\php\php.exe process.php

if %errorlevel% neq 0 (
    echo.
    echo [ERROR] El procesamiento ha fallado.
) else (
    echo.
    echo [EXITO] Proceso terminado.
    echo Revisa los resultados en la carpeta 'results'.
)

echo.
pause
