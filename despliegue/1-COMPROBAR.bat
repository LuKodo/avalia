@echo off
:: ===========================================================================
::  AVALIA - paso 1: comprobar el servidor
:: ---------------------------------------------------------------------------
::  Revisa que esta maquina cumple los requisitos. NO modifica nada y NO
::  necesita permisos de administrador.
::
::  Doble clic y listo. Si falta algo, lo dice y se detiene.
::
::  Existe este archivo porque `.\instalar.ps1` escrito en una ventana de CMD
::  no se ejecuta: Windows abre el Bloc de notas. Y PowerShell, ademas, se
::  niega por omision a correr guiones sin firmar. Aqui se resuelven las dos
::  cosas de una vez.
:: ===========================================================================

setlocal EnableExtensions
title Avalia - comprobacion del servidor

:: ---------------------------------------------------------------------------
::  AJUSTE ESTOS CUATRO VALORES SI SU SERVIDOR ES DISTINTO
:: ---------------------------------------------------------------------------
set "XAMPP=C:\xampp"
set "PUERTO=8082"
set "PUERTO_BD=3307"
set "DOMINIO=190.242.42.242"

:: Pongalo en "si" si su MySQL es anterior a 5.7.8 o su MariaDB anterior a 10.3.
set "MOTOR_ANTIGUO=si"
:: ---------------------------------------------------------------------------

set "AQUI=%~dp0"

if not exist "%AQUI%instalar.ps1" (
    echo.
    echo   ERROR: no encuentro instalar.ps1 junto a este archivo.
    echo   Este .bat tiene que quedarse dentro de la carpeta 'despliegue'.
    echo.
    pause
    exit /b 1
)

set "EXTRA="
if /i "%MOTOR_ANTIGUO%"=="si" set "EXTRA=-MotorAntiguo"

powershell -NoProfile -ExecutionPolicy Bypass -File "%AQUI%instalar.ps1" ^
  -RaizXampp "%XAMPP%" -Puerto %PUERTO% -PuertoBd %PUERTO_BD% -Dominio "%DOMINIO%" -Comprobar %EXTRA%

echo.
echo   Si todo salio en verde, ejecute ahora 2-INSTALAR.bat
echo.
pause
endlocal
