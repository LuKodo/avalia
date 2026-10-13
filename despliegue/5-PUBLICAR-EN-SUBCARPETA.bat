@echo off
:: ===========================================================================
::  AVALIA - publicar en una subcarpeta del servidor web
:: ---------------------------------------------------------------------------
::  Hace que http://localhost/Avalia sirva la aplicacion en lugar de listar la
::  carpeta del proyecto -donde quedan a la vista el .env, vendor, storage y
::  todo el codigo-.
::
::  No usa VirtualHost a proposito: uno en el puerto 80 se vuelve el
::  predeterminado del puerto y dejaria sin responder al resto de proyectos de
::  htdocs. Un Alias afecta solo a su propia ruta.
::
::  Doble clic. Pide permisos de administrador porque toca la configuracion de
::  Apache y reinicia su servicio.
:: ===========================================================================

setlocal EnableExtensions
title Avalia - publicar en subcarpeta

:: ---------------------------------------------------------------------------
::  AJUSTE ESTOS VALORES SI SU EQUIPO ES DISTINTO
:: ---------------------------------------------------------------------------
set "XAMPP=C:\xampp2"
set "APP=C:\xampp2\htdocs\Avalia"
set "RUTA=/Avalia"
:: ---------------------------------------------------------------------------

set "AQUI=%~dp0"

if /i "%~1"=="/elevado" goto :conPermisos
net session >nul 2>&1
if not errorlevel 1 goto :conPermisos

echo.
echo   Pidiendo permisos de administrador.
echo   Acepte el aviso de Windows que va a aparecer.
echo.
powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -ArgumentList '/elevado' -Verb RunAs"
exit /b 0

:conPermisos

if not exist "%AQUI%publicar-subcarpeta.ps1" (
    echo.
    echo   ERROR: no encuentro publicar-subcarpeta.ps1 junto a este archivo.
    echo.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%AQUI%publicar-subcarpeta.ps1" ^
  -RaizXampp "%XAMPP%" -RaizApp "%APP%" -Ruta "%RUTA%"

echo.
pause
endlocal
