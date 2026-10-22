<#
=============================================================================
 Arma el .zip de despliegue de Avalia Logistica
=============================================================================

 Copia el proyecto a una carpeta limpia, DESCARTANDO todo lo que no debe salir
 de este servidor, y lo comprime.

 Lo que se excluye, y por que:

   .env                     credenciales de ESTA maquina. El instalador
                            genera las del servidor destino.
   storage/app/private/     evidencias reales: fotos y firmas de entregas.
                            Son prueba de operaciones de clientes.
   storage/app/respaldos/   volcados de la base con datos reales.
   storage/app/video/       la demostracion; pesa 8 MB y no hace falta.
   storage/logs/            registros, que suelen traer rutas y correos.
   database/*.sqlite        base local, puede tener datos.
   vendor/                  63 MB que `composer install` rehace en destino.
   node_modules/            169 MB que no hacen falta: el frontend ya va
                            compilado en public/build.

 Lo que SI se incluye y sorprende a alguna gente: `public/build`, el frontend
 ya compilado. Es lo que permite instalar en un servidor sin Node.

 USO
   .\empaquetar.ps1
   .\empaquetar.ps1 -ConVendor      # autonomo, sin composer en destino (+63 MB)
=============================================================================
#>

[CmdletBinding()]
param(
    [string]$RaizApp = '',
    [string]$Destino = '',
    [switch]$ConVendor
)

$ErrorActionPreference = 'Stop'

function Bien($t) { Write-Host "  [ OK ] $t" -ForegroundColor Green }
function Aviso($t){ Write-Host "  [ !  ] $t" -ForegroundColor Yellow }
function Malo($t) { Write-Host "  [FALLA] $t" -ForegroundColor Red }

if ($RaizApp -eq '') { $RaizApp = Split-Path -Parent $PSScriptRoot }
if ($Destino -eq '') { $Destino = Join-Path (Split-Path -Parent $RaizApp) 'paquetes-avalia' }

$sello  = Get-Date -Format 'yyyyMMdd-HHmm'
$nombre = "avalia-despliegue-$sello"
$stage  = Join-Path $env:TEMP $nombre
$zip    = Join-Path $Destino "$nombre.zip"

Write-Host "`n  Empaquetando Avalia Logistica" -ForegroundColor White
Write-Host "  Origen : $RaizApp"
Write-Host "  Salida : $zip`n"

New-Item -ItemType Directory -Force -Path $Destino | Out-Null
Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $stage | Out-Null

# --------------------------------------------------------------- que se copia
$carpetas = @(
    'app', 'bootstrap', 'config', 'database', 'public',
    'resources', 'routes', 'storage', 'despliegue', 'herramientas',
    # La documentacion viaja con el paquete: la guia de actualizacion y el
    # inventario de peticiones tienen que estar DONDE se hace la actualizacion,
    # no en un correo de hace dos semanas.
    'docs'
)

$sueltos = @(
    'artisan', 'composer.json', 'composer.lock',
    'package.json', 'package-lock.json', 'vite.config.js',
    '.env.example', '.gitignore'
)

if ($ConVendor) { $carpetas += 'vendor' }

# --------------------------------------------------------------- que se queda
$excluir = @(
    'node_modules'
    'storage\app\private'
    'storage\app\respaldos'
    'storage\app\video'
    'storage\logs'
    'storage\framework\cache\data'
    'storage\framework\sessions'
    'storage\framework\views'
    'storage\framework\testing'
    'bootstrap\cache'
    '.git'
)

Write-Host "  Copiando..." -ForegroundColor DarkGray

foreach ($c in $carpetas) {
    $origen = Join-Path $RaizApp $c
    if (-not (Test-Path $origen)) { continue }

    # /MIR no: reflejaria tambien los borrados. /XD excluye carpetas por ruta.
    $args = @($origen, (Join-Path $stage $c), '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
    foreach ($x in $excluir) {
        $ruta = Join-Path $RaizApp $x
        if (Test-Path $ruta) { $args += @('/XD', $ruta) }
    }

    & robocopy @args | Out-Null
    # robocopy devuelve <8 cuando todo fue bien. 8 o mas es error de verdad.
    if ($LASTEXITCODE -ge 8) { Malo "robocopy fallo copiando $c (codigo $LASTEXITCODE)"; exit 1 }
}

foreach ($f in $sueltos) {
    $origen = Join-Path $RaizApp $f
    if (Test-Path $origen) { Copy-Item $origen (Join-Path $stage $f) -Force }
}

# --------------------------------------------------- limpieza de lo que quede
# Cinturon y tirantes: aunque las exclusiones de arriba ya lo evitan, se borra
# explicitamente lo sensible. Un fallo aqui publica datos de clientes.
$peligroso = @(
    '.env', '.env.backup',
    'database\database.sqlite',
    'storage\app\private', 'storage\app\respaldos', 'storage\app\video'
)

foreach ($p in $peligroso) {
    $ruta = Join-Path $stage $p
    if (Test-Path $ruta) { Remove-Item $ruta -Recurse -Force }
}

Get-ChildItem $stage -Recurse -Include '*.log','*.sqlite','.env','.env.*' -Force -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne '.env.example' } |
    Remove-Item -Force -ErrorAction SilentlyContinue

# Los respaldos de configuracion de Apache que dejo el instalador local.
Get-ChildItem $stage -Recurse -Filter '*.antes-de-*' -Force -ErrorAction SilentlyContinue |
    Remove-Item -Force -Recurse -ErrorAction SilentlyContinue

# --------------------------------------------- estructura que Laravel espera
# Las carpetas vacias no viajan en un zip. Sin ellas Laravel revienta al
# arrancar, con un error que no dice que falta una carpeta.
foreach ($d in @(
    'storage\app\public', 'storage\app\private',
    'storage\framework\cache\data', 'storage\framework\sessions',
    'storage\framework\views', 'storage\logs', 'bootstrap\cache'
)) {
    $ruta = Join-Path $stage $d
    New-Item -ItemType Directory -Force -Path $ruta | Out-Null
    Set-Content -Path (Join-Path $ruta '.gitignore') -Value "*`n!.gitignore" -Encoding ascii
}

# ------------------------------------------------------------ comprobaciones
Write-Host "`n  Revisando el paquete..." -ForegroundColor DarkGray

$fallos = 0

# Archivos que no deben existir en absoluto.
foreach ($p in @('.env', 'database\database.sqlite', 'storage\app\respaldos', 'storage\app\video')) {
    if (Test-Path (Join-Path $stage $p)) { Malo "$p SIGUE DENTRO del paquete"; $fallos++ }
}

<#
    `storage/app/private` SI debe existir -Laravel la necesita- pero VACIA.
    La comprobacion mira que no lleve evidencias dentro, no que falte la
    carpeta: exigir su ausencia rompia el paquete por el motivo contrario al
    que se queria evitar.
#>
foreach ($d in @('storage\app\private', 'storage\app\public')) {
    $ruta = Join-Path $stage $d
    if (-not (Test-Path $ruta)) { continue }

    $sobran = Get-ChildItem $ruta -Recurse -File -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -ne '.gitignore' }

    if ($sobran) {
        Malo "$d lleva $($sobran.Count) archivo(s) de datos dentro"
        $sobran | Select-Object -First 3 | ForEach-Object { Write-Host "          $($_.Name)" -ForegroundColor Red }
        $fallos++
    }
}

if ($fallos -eq 0) { Bien 'Sin credenciales ni datos de clientes' }

foreach ($p in @('artisan', 'public\index.php', 'public\.htaccess', 'public\build\manifest.json',
                 'despliegue\instalar.ps1', 'despliegue\preparar-base.php', 'despliegue\revisar-motores.ps1',
                 'despliegue\probar-conexion.php', 'despliegue\probar-conexion.ps1',
                 'despliegue\1-COMPROBAR.bat', 'despliegue\2-INSTALAR.bat', 'despliegue\3-REVISAR-MOTORES.bat',
                 'despliegue\4-PROBAR-CONEXION.bat')) {
    if (Test-Path (Join-Path $stage $p)) { Bien "$p presente" }
    else { Malo "FALTA $p"; $fallos++ }
}

if ($fallos -gt 0) { Write-Host "`n  El paquete NO se genero: corrija lo anterior.`n" -ForegroundColor Red; exit 1 }

# ------------------------------------------------------------------ comprimir
Write-Host "`n  Comprimiendo..." -ForegroundColor DarkGray
Remove-Item $zip -Force -ErrorAction SilentlyContinue
Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zip -CompressionLevel Optimal

Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue

$mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)

Write-Host ''
Write-Host '  ============================================================' -ForegroundColor Green
Write-Host "   PAQUETE LISTO - $mb MB" -ForegroundColor Green
Write-Host "   $zip" -ForegroundColor Green
Write-Host '  ============================================================' -ForegroundColor Green
Write-Host ''
Write-Host '   En el servidor destino:' -ForegroundColor White
Write-Host '     1. Descomprima en C:\xampp\htdocs\avalia'
Write-Host '     2. Abra la carpeta despliegue'
Write-Host '     3. Doble clic en 1-COMPROBAR.bat    (no cambia nada)'
Write-Host '     4. Doble clic en 2-INSTALAR.bat     (pide permisos de administrador)'
Write-Host ''
Write-Host '   Doble clic, no linea de comandos: escribir .\instalar.ps1 en una' -ForegroundColor DarkGray
Write-Host '   ventana de CMD abre el Bloc de notas en vez de ejecutarlo, y' -ForegroundColor DarkGray
Write-Host '   PowerShell rechaza por omision los guiones sin firmar. Los .bat' -ForegroundColor DarkGray
Write-Host '   resuelven las dos cosas.' -ForegroundColor DarkGray
Write-Host ''
Write-Host '   Si la instalacion se detiene por la version del motor de base,' -ForegroundColor DarkGray
Write-Host '   3-REVISAR-MOTORES.bat hace inventario y dice cual sirve.' -ForegroundColor DarkGray
Write-Host ''
Write-Host '   Antes de empezar, ajuste PUERTO_BD y HOST_BD dentro de los .bat' -ForegroundColor DarkGray
Write-Host '   si su base no esta donde dice por omision.' -ForegroundColor DarkGray
Write-Host ''
if (-not $ConVendor) {
    Aviso 'El paquete NO trae vendor: el servidor necesita composer y salida a internet.'
    Write-Host '         Para uno autonomo:  .\empaquetar.ps1 -ConVendor' -ForegroundColor Yellow
}
Write-Host ''
