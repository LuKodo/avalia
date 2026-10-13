<#
    Publica Avalia en una SUBCARPETA del servidor web, sin VirtualHost.

    Por que asi y no con un VirtualHost:

    Un <VirtualHost *:80> se convierte en el predeterminado de ese puerto, y a
    partir de ahi todo lo que se servia por http://localhost/<proyecto>/ deja
    de responder. En un equipo con decenas de proyectos en htdocs eso rompe
    mucho mas de lo que arregla. Un Alias afecta solo a su propia ruta.

    Que resuelve:

    Sin esto, http://localhost/Avalia/ lista la carpeta del proyecto -con
    `.env`, `vendor`, `storage` y el codigo fuente descargables-. El Alias hace
    que esa direccion apunte a `public`, que es lo unico que debe ser publico.

    Requiere permisos de administrador: toca la configuracion de Apache y
    reinicia su servicio.
#>

param(
    [string]$RaizXampp = 'C:\xampp2',
    [string]$RaizApp   = 'C:\xampp2\htdocs\Avalia',
    [string]$Ruta      = '/Avalia',
    [string]$Servicio  = 'Apache2.4',
    [switch]$SinColas,

    <#
        Ensayo: hace todo el trabajo de verdad -comprobar rutas, construir la
        configuracion, validarla- pero no escribe nada ni reinicia nada.

        Existe porque comprobar la sintaxis de un guion no basta: un error en
        una expresion regular o en una ruta solo aparece al EJECUTARLO, y sin
        esto la unica forma de ejecutarlo era como administrador y con el
        servidor de por medio.
    #>
    [switch]$SoloProbar
)

$ErrorActionPreference = 'Stop'

# La barra invertida por su codigo de caracter. Escribirla entre comillas
# obliga a acertar con los escapes de cada herramienta que toque este
# archivo; por codigo no hay nada que escapar.
$BARRA = [char]92

function Titulo($t) { Write-Host ''; Write-Host "=== $t ===" -ForegroundColor Cyan }
function Bien($t)   { Write-Host "  [ OK ] $t" -ForegroundColor Green }
function Aviso($t)  { Write-Host "  [ !  ] $t" -ForegroundColor Yellow }
function Malo($t)   { Write-Host "  [FALLA] $t" -ForegroundColor Red }

$soyAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $soyAdmin -and -not $SoloProbar) {
    Malo 'Hacen falta permisos de administrador.'
    Write-Host '  Use 5-PUBLICAR-EN-SUBCARPETA.bat, que los pide solo.'
    exit 1
}

if ($SoloProbar) { Aviso 'ENSAYO: no se escribira ni se reiniciara nada.' }

$httpd     = Join-Path $RaizXampp 'apache\bin\httpd.exe'
$confDir   = Join-Path $RaizXampp 'apache\conf'
$httpdConf = Join-Path $confDir 'httpd.conf'
$miConf    = Join-Path $confDir 'extra\httpd-avalia.conf'
# .Replace() y no -replace: el segundo interpreta el patron como
# expresion regular, y una barra invertida suelta no es una regex valida.
$publico   = (Join-Path $RaizApp 'public').Replace($BARRA, '/')

Titulo '1. Comprobaciones'

foreach ($r in @($httpd, $httpdConf, $publico)) {
    if (-not (Test-Path $r)) { Malo "No encuentro $r"; exit 1 }
}
Bien 'Apache y la carpeta publica estan donde se espera'

if (-not (Test-Path (Join-Path $publico '.htaccess'))) {
    Malo 'Falta public/.htaccess. Sin el solo respondera la pagina de inicio.'
    exit 1
}
Bien 'public/.htaccess presente'

Titulo '2. Configuracion de Apache'

$conf = @"
# =============================================================================
#  Avalia Logistica - publicacion en la subcarpeta $Ruta
# -----------------------------------------------------------------------------
#  Generado por publicar-subcarpeta.ps1. Si lo edita a mano, sepa que el guion
#  lo reescribe entero la proxima vez.
#
#  El Alias es lo que impide que $Ruta muestre la carpeta del proyecto. Sin el,
#  Apache sirve la raiz del codigo y quedan a la vista -y descargables- el
#  .env con las contrasenas, vendor, storage y todo el codigo fuente.
# =============================================================================

Alias $Ruta "$publico"

# Todo el proyecto, cerrado. Apache resuelve los <Directory> de la ruta mas
# corta a la mas larga, asi que el bloque de `public` que viene despues gana
# sobre este aunque este escrito antes. Es un cinturon ademas de los tirantes:
# con el Alias de arriba el resto ya no deberia ser alcanzable, pero basta un
# Alias mal puesto en el futuro para que vuelva a serlo.
<Directory "$($RaizApp.Replace($BARRA, '/'))">
    Require all denied
</Directory>

# Y solo la carpeta publica abierta.
<Directory "$publico">
    Require all granted

    # Que Apache no adivine el archivo a partir de la direccion ni liste
    # carpetas.
    Options -Indexes -MultiViews

    # AllowOverride None y las reglas AQUI, no en public/.htaccess.
    #
    # El motivo es RewriteBase. Bajo un Alias, mod_rewrite no puede deducir a
    # que direccion corresponde esta carpeta: reescribe a "index.php", vuelve a
    # entrar por la misma regla, y asi hasta que Apache corta con
    # "AH00124: Request exceeded the limit of 10 internal redirects".
    #
    # RewriteBase se lo dice. Pero no puede ir en public/.htaccess, porque ese
    # archivo tiene que seguir valiendo cuando la aplicacion se despliega en la
    # raiz de un dominio -donde la base es "/"-. Aqui sabemos en que subcarpeta
    # estamos; alli no.
    AllowOverride None

    RewriteEngine On
    RewriteBase $Ruta

    # La cabecera Authorization no llega a PHP por si sola con algunos modulos,
    # y sin ella TODA la API con token deja de autenticar.
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Barras finales sobrantes en carpetas reales.
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^(.+)/$ `$1 [L,R=301]

    # Todo lo que no sea un archivo o carpeta existente va al enrutador.
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</Directory>
"@

if ($SoloProbar) {
    Bien 'Configuracion construida (no se escribe en ensayo):'
    $conf -split "`r?`n" | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkGray }
} else {
    Set-Content -Path $miConf -Value $conf -Encoding ascii
    Bien "Escrito $miConf"
}

$incluir = "Include conf/extra/httpd-avalia.conf"

if (Select-String -Path $httpdConf -Pattern ([regex]::Escape($incluir)) -Quiet) {
    Bien 'httpd.conf ya la incluia'
} elseif ($SoloProbar) {
    Bien ("Faltaria anadir a httpd.conf: $incluir")
} else {
    Copy-Item $httpdConf "$httpdConf.antes-de-avalia-$(Get-Date -Format yyyyMMdd-HHmmss)"
    Add-Content -Path $httpdConf -Value "`r`n# Avalia Logistica`r`n$incluir" -Encoding ascii
    Bien 'httpd.conf ahora incluye la configuracion de Avalia (con respaldo del anterior)'
}

Titulo '3. Validacion y reinicio'

<#
    La salida de httpd se recoge en archivos, NO con `2>&1`.

    En PowerShell 5.1 redirigir el error estandar de un ejecutable nativo
    envuelve cada linea en un ErrorRecord. Con $ErrorActionPreference = 'Stop'
    eso es un error TERMINANTE: el guion muere aqui aunque Apache haya dicho
    'Syntax OK'. Y httpd escribe su veredicto por el error estandar.
#>
function ValidarApache([string]$configuracion) {
    $fSal = Join-Path $env:TEMP "avalia-httpd-$PID.out"
    $fErr = Join-Path $env:TEMP "avalia-httpd-$PID.err"

    $args = if ($configuracion) { @('-t', '-f', "`"$configuracion`"") } else { @('-t') }

    $p = Start-Process -FilePath $httpd -ArgumentList $args `
        -NoNewWindow -Wait -PassThru `
        -RedirectStandardOutput $fSal -RedirectStandardError $fErr

    $salida = @()
    if (Test-Path $fSal) { $salida += Get-Content $fSal }
    if (Test-Path $fErr) { $salida += Get-Content $fErr }
    Remove-Item $fSal, $fErr -Force -ErrorAction SilentlyContinue

    return @{ Texto = ($salida -join ' '); Codigo = $p.ExitCode }
}

<#
    En ensayo se valida una COPIA de httpd.conf que incluye la configuracion
    nueva desde una carpeta temporal. Validar la de verdad no probaria nada:
    todavia no incluye lo que estamos a punto de anadir.
#>
$paraValidar = ''

if ($SoloProbar) {
    $confTmp  = Join-Path $env:TEMP "avalia-alias-$PID.conf"
    $httpdTmp = Join-Path $env:TEMP "avalia-httpd-$PID.conf"

    Set-Content -Path $confTmp -Value $conf -Encoding ascii
    Get-Content $httpdConf | Set-Content $httpdTmp -Encoding ascii
    Add-Content -Path $httpdTmp -Value "`r`nInclude `"$($confTmp.Replace($BARRA, '/'))`"" -Encoding ascii

    $paraValidar = $httpdTmp
}

$v = ValidarApache $paraValidar

if ($SoloProbar -and $paraValidar) {
    Remove-Item $paraValidar, $confTmp -Force -ErrorAction SilentlyContinue
}

if ($v.Texto -match 'Syntax OK') {
    Bien 'Apache acepta la configuracion'
} else {
    Malo "Apache la rechaza: $($v.Texto)"
    Write-Host '  No se reinicia nada. Su servidor sigue como estaba.' -ForegroundColor Yellow
    exit 1
}

<#
    El reinicio de Apache no debe tumbar el guion.

    Para este punto la configuracion ya esta escrita y validada: lo unico que
    falta es que Apache la lea. Si el servicio esta deshabilitado -porque se
    gestiona desde el panel de XAMPP- o se niega a arrancar, eso no invalida el
    trabajo hecho; solo significa que el reinicio lo tiene que dar usted.
#>
$svc = Get-Service -Name $Servicio -ErrorAction SilentlyContinue

$gestionadoAParte = $svc -and ((Get-CimInstance Win32_Service -Filter "Name='$Servicio'").StartMode -eq 'Disabled')

if ($SoloProbar) {
    Bien "Ensayo: aqui se reiniciaria $Servicio"
} elseif ($svc -and -not $gestionadoAParte) {
    try {
        Restart-Service -Name $Servicio -Force
        Bien "Servicio $Servicio reiniciado"
    } catch {
        Aviso "No se pudo reiniciar $Servicio automaticamente."
        Write-Host "         $($_.Exception.Message)" -ForegroundColor DarkGray
        Write-Host '         La configuracion YA esta escrita y validada: solo falta' -ForegroundColor Yellow
        Write-Host '         que Apache la lea. Reinicielo desde el panel de XAMPP.'  -ForegroundColor Yellow
    }
} elseif ($gestionadoAParte) {
    Aviso "El servicio $Servicio esta deshabilitado: Apache se gestiona desde el panel."
    Write-Host '         La configuracion YA esta escrita y validada.' -ForegroundColor Yellow
    Write-Host '         Pulse Stop y luego Start en el panel de XAMPP para que surta efecto.' -ForegroundColor Yellow
} else {
    Aviso "No existe el servicio $Servicio; reinicie Apache desde el panel de XAMPP."
}

if (-not $SinColas -and -not $SoloProbar) {
    Titulo '4. Colas y tareas programadas'

    $php = Join-Path $RaizXampp 'php\php.exe'

    foreach ($t in @(
        @{ n = 'Avalia-Colas';       c = "artisan queue:work --stop-when-empty --tries=3 --max-time=55" },
        @{ n = 'Avalia-Programador'; c = "artisan schedule:run" }
    )) {
        & schtasks /Create /F /TN $t.n /SC MINUTE /MO 1 /RL HIGHEST /RU SYSTEM `
            /TR "cmd /c cd /d `"$RaizApp`" && `"$php`" $($t.c)" 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { Bien "$($t.n) registrada (cada minuto)" }
        else { Malo "No se pudo registrar $($t.n)" }
    }
}

Titulo 'Listo'
Write-Host ''
Write-Host "   La aplicacion responde en:  http://localhost$Ruta" -ForegroundColor Green
Write-Host ''
Write-Host '   Y lo que ya tenia sigue igual: el puerto 80 no cambio de dueno,' -ForegroundColor DarkGray
Write-Host '   asi que el resto de proyectos de htdocs responden como antes.'   -ForegroundColor DarkGray
Write-Host ''
