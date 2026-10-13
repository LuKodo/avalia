@echo off
:: ===========================================================================
::  AVALIA - paso 3 (opcional): que motores de base hay en este servidor
:: ---------------------------------------------------------------------------
::  Uselo si la instalacion se detuvo diciendo que el motor es demasiado
::  viejo. Hace inventario de las MySQL/MariaDB de la maquina, lee sus
::  versiones y dice cual sirve.
::
::  NO modifica nada y NO necesita permisos de administrador.
:: ===========================================================================

setlocal EnableExtensions
title Avalia - motores de base disponibles

set "XAMPP=C:\xampp"
set "AQUI=%~dp0"

if not exist "%AQUI%revisar-motores.ps1" (
    echo.
    echo   ERROR: no encuentro revisar-motores.ps1 junto a este archivo.
    echo   Este .bat tiene que quedarse dentro de la carpeta 'despliegue'.
    echo.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%AQUI%revisar-motores.ps1" -RaizXampp "%XAMPP%"

echo.
pause
endlocal
