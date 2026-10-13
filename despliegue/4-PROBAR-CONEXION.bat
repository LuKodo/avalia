@echo off
:: ===========================================================================
::  AVALIA - paso 4 (opcional): probar una credencial de base de datos
:: ---------------------------------------------------------------------------
::  Usela para averiguar si un usuario y clave entran en determinado motor,
::  antes de lanzar la instalacion.
::
::  NO modifica nada y NO necesita permisos de administrador.
::  La clave se escribe por teclado y no queda en pantalla ni en el historial.
:: ===========================================================================

setlocal EnableExtensions
title Avalia - probar credencial de base

set "XAMPP=C:\xampp"
set "HOST_BD=127.0.0.1"
set "AQUI=%~dp0"

if not exist "%AQUI%probar-conexion.ps1" (
    echo.
    echo   ERROR: no encuentro probar-conexion.ps1 junto a este archivo.
    echo.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%AQUI%probar-conexion.ps1" -RaizXampp "%XAMPP%" -HostBd "%HOST_BD%"

echo.
pause
endlocal
