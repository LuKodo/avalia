@echo off
:: ===========================================================================
::  AVALIA - paso 2: instalar
:: ---------------------------------------------------------------------------
::  Deja la plataforma corriendo: base de datos, configuracion, migraciones,
::  Apache sirviendo /public y las colas trabajando.
::
::  Doble clic. Pide permisos de administrador solo (Windows mostrara su aviso
::  de siempre) porque toca Apache, servicios y tareas programadas.
::
::  Le preguntara por teclado el usuario y la contrasena de MySQL con permiso
::  para crear bases -normalmente `root`-. No los escriba aqui dentro: en la
::  linea de comandos quedarian en el historial y a la vista de cualquiera con
::  la lista de procesos.
:: ===========================================================================

setlocal EnableExtensions
title Avalia - instalacion

:: ---------------------------------------------------------------------------
::  AJUSTE ESTOS VALORES SI SU SERVIDOR ES DISTINTO
:: ---------------------------------------------------------------------------
set "XAMPP=C:\xampp"
set "PUERTO=8082"
set "PUERTO_BD=3307"
set "HOST_BD=127.0.0.1"
set "DOMINIO=190.242.42.242"
set "ENTORNO=pruebas"

:: ---------------------------------------------------------------------------
::  MOTOR ANTIGUO
::  Pongalo en "si" solo si su MySQL es anterior a 5.7.8 (o su MariaDB anterior
::  a 10.3). El esquema se construye entonces con columnas de texto en lugar de
::  JSON y con juego de caracteres utf8.
::
::  La aplicacion funciona igual, pero es una pasarela para pruebas: MySQL 5.5
::  no recibe parches de seguridad desde diciembre de 2018. Al migrar a un
::  motor moderno, pongalo en "no" y reinstale.
:: ---------------------------------------------------------------------------
set "MOTOR_ANTIGUO=si"

:: ---------------------------------------------------------------------------
::  REHACER
::  "si" borra todas las tablas de la base de Avalia y las crea de nuevo.
::  Uselo si un intento anterior quedo a medias. No toca ninguna otra base del
::  servidor, pero si borra los datos de esta.
:: ---------------------------------------------------------------------------
set "REHACER=no"

:: ---------------------------------------------------------------------------
::  SIN_DEMO
::  "si" omite los datos de ejemplo. Poner en "si" cuando se reinstala sobre una
::  base que YA los tiene: el sembrador aniade, no reemplaza, y volver a
::  pasarlo deja la demostracion duplicada.
:: ---------------------------------------------------------------------------
set "SIN_DEMO=no"

:: ---------------------------------------------------------------------------
::  RUTA_BASE
::  Dejelo vacio si Avalia se queda con el puerto entero.
::  Pongalo como /Avalia si la publico en una subcarpeta con
::  5-PUBLICAR-EN-SUBCARPETA.bat: las comprobaciones finales miraran ahi.
:: ---------------------------------------------------------------------------
set "RUTA_BASE="
:: ---------------------------------------------------------------------------

set "AQUI=%~dp0"

:: --- permisos --------------------------------------------------------------
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
if /i "%REHACER%"=="si" set "EXTRA=%EXTRA% -Rehacer"
if /i "%SIN_DEMO%"=="si" set "EXTRA=%EXTRA% -SinDatosDemo"
if not "%RUTA_BASE%"=="" set "EXTRA=%EXTRA% -RutaBase %RUTA_BASE%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%AQUI%instalar.ps1" ^
  -RaizXampp "%XAMPP%" -Puerto %PUERTO% -PuertoBd %PUERTO_BD% -HostBd "%HOST_BD%" ^
  -Dominio "%DOMINIO%" -Entorno %ENTORNO% %EXTRA%

echo.
pause
endlocal
