<#
    AVALIA - probar una credencial de base de datos.

    No crea ni modifica nada. Pregunta puerto, usuario y clave, intenta
    conectar y dice tres cosas: si entra, si la version sirve y si la cuenta
    puede crear la base.

    La clave se pide por teclado y viaja al proceso hijo en una variable de
    entorno. No se escribe en la linea de comandos a proposito: ahi quedaria
    en el historial de la consola y a la vista de cualquiera que liste los
    procesos de la maquina.
#>

param(
    [string]$RaizXampp = 'C:\xampp',
    [string]$HostBd    = '127.0.0.1',
    [int]$PuertoBd     = 0,
    [string]$Usuario   = ''
)

$ErrorActionPreference = 'Stop'
$aqui = Split-Path -Parent $MyInvocation.MyCommand.Path

$php = Join-Path $RaizXampp 'php\php.exe'
if (-not (Test-Path $php)) {
    $php = (Get-Command php.exe -ErrorAction SilentlyContinue).Source
}
if (-not $php) {
    Write-Host ''
    Write-Host '  No encuentro php.exe.' -ForegroundColor Red
    Write-Host "  Busque en $RaizXampp\php\ o pase -RaizXampp con la ruta real." -ForegroundColor Red
    Write-Host ''
    exit 1
}

Write-Host ''
Write-Host '  ==============================================================' -ForegroundColor DarkGray
Write-Host '   Probar una credencial de base de datos' -ForegroundColor Cyan
Write-Host '  ==============================================================' -ForegroundColor DarkGray
Write-Host ''
Write-Host '   No cambia nada. Solo intenta entrar y reporta.' -ForegroundColor DarkGray
Write-Host ''

if ($PuertoBd -le 0) {
    $r = Read-Host '   Puerto (3306 suele ser MySQL 8; 3307 el otro)'
    $PuertoBd = [int]$r
}

if (-not $Usuario) {
    $r = Read-Host '   Usuario [root]'
    $Usuario = if ($r) { $r } else { 'root' }
}

$segura = Read-Host "   Clave de $Usuario (no se muestra; Enter si esta vacia)" -AsSecureString
$bstr   = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
try {
    $clave = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
} finally {
    # Liberar el buffer sin dejar la clave rondando en memoria no administrada.
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
}

Write-Host ''

$env:AVALIA_HOST    = $HostBd
$env:AVALIA_PUERTO  = "$PuertoBd"
$env:AVALIA_USUARIO = $Usuario
$env:AVALIA_CLAVE   = $clave

try {
    $fSal = Join-Path $env:TEMP "avalia-probar-$PID.out"
    $fErr = Join-Path $env:TEMP "avalia-probar-$PID.err"

    $p = Start-Process -FilePath $php -ArgumentList "`"$(Join-Path $aqui 'probar-conexion.php')`"" `
        -NoNewWindow -Wait -PassThru -RedirectStandardOutput $fSal -RedirectStandardError $fErr

    foreach ($f in @($fSal, $fErr)) {
        if (Test-Path $f) {
            Get-Content $f | ForEach-Object {
                $c = if ($_ -match '^\s*\[NO') { 'Red' }
                     elseif ($_ -match '^\s*\[OK') { 'Green' }
                     elseif ($_ -match '^\s*\[AVISO') { 'Yellow' }
                     else { 'Gray' }
                Write-Host $_ -ForegroundColor $c
            }
        }
    }
    Remove-Item $fSal, $fErr -Force -ErrorAction SilentlyContinue

    if ($p.ExitCode -eq 0) {
        Write-Host '   Si arriba salio todo en verde, ponga ese puerto en PUERTO_BD' -ForegroundColor DarkGray
        Write-Host '   dentro de 1-COMPROBAR.bat y 2-INSTALAR.bat, y siga con el paso 1.' -ForegroundColor DarkGray
    }
} finally {
    # La clave no se queda en el entorno de esta ventana.
    Remove-Item Env:\AVALIA_CLAVE -ErrorAction SilentlyContinue
    $clave = $null
}

Write-Host ''
