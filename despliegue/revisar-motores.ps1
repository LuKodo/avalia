<#
    AVALIA - inventario de motores de base de datos.

    No modifica nada y no necesita permisos de administrador. Busca las
    instalaciones de MySQL/MariaDB de la maquina, lee su version y dice cual
    sirve para este sistema.

    El minimo no es arbitrario: el esquema guarda geometrias de ruta,
    poligonos de zona y cargas de webhook en catorce columnas de tipo JSON.
    Ese tipo aparece en MySQL 5.7.8 y en MariaDB 10.2; Laravel 12 pide ademas
    MariaDB 10.3 como piso. Por debajo de eso las migraciones se rompen a
    mitad y dejan la base a medias, que es peor que no haber empezado.
#>

param(
    [string]$RaizXampp = 'C:\xampp'
)

$ErrorActionPreference = 'Continue'

function Titulo($t) {
    Write-Host ''
    Write-Host ('  ' + ('=' * 62)) -ForegroundColor DarkGray
    Write-Host "   $t" -ForegroundColor Cyan
    Write-Host ('  ' + ('=' * 62)) -ForegroundColor DarkGray
}

function Linea($estado, $texto) {
    $color = switch ($estado) {
        'OK'    { 'Green' }
        'NO'    { 'Red' }
        'AVISO' { 'Yellow' }
        default { 'Gray' }
    }
    Write-Host ('  [{0,-5}] ' -f $estado) -ForegroundColor $color -NoNewline
    Write-Host $texto
}

<#
    Devuelve $true si esa version corre el esquema.

    MariaDB y MySQL numeran por separado, asi que el minimo depende de cual
    sea. La cadena que devuelve el motor trae cosas como
    '10.1.37-MariaDB for Win32 on AMD64', de ahi se recorta el numero.
#>
function Sirve([string]$version) {
    $esMaria = $version -match '(?i)mariadb'
    $numero  = ([regex]::Match($version, '\d+(\.\d+)+')).Value
    if (-not $numero) { return $null }

    $minimo = if ($esMaria) { '10.3' } else { '5.7.8' }

    # Se comparan como objetos Version y no como texto: '10.1' -lt '5.7' es
    # verdadero en comparacion alfabetica, que seria justo al reves.
    $a = [version]($numero -replace '^(\d+(\.\d+){0,2}).*$', '$1')
    $b = [version]$minimo

    [pscustomobject]@{
        Numero = $numero
        Motor  = if ($esMaria) { 'MariaDB' } else { 'MySQL' }
        Minimo = $minimo
        Pasa   = ($a -ge $b)
    }
}

Titulo 'Motores de base en este servidor'

$candidatos = @(
    (Join-Path $RaizXampp 'mysql\bin\mysqld.exe'),
    (Join-Path $RaizXampp 'mysql\bin\mariadbd.exe'),
    (Join-Path $RaizXampp 'tools\mariadb\bin\mariadbd.exe')
)

# Cualquier MariaDB/MySQL instalado como programa, sin fijar versiones a mano.
foreach ($base in @("${env:ProgramFiles}", "${env:ProgramFiles(x86)}")) {
    if (-not $base -or -not (Test-Path $base)) { continue }
    foreach ($patron in 'MariaDB*', 'MySQL\MySQL Server*') {
        Get-ChildItem -Path (Join-Path $base $patron) -Directory -ErrorAction SilentlyContinue |
            ForEach-Object {
                $candidatos += (Join-Path $_.FullName 'bin\mysqld.exe')
                $candidatos += (Join-Path $_.FullName 'bin\mariadbd.exe')
            }
    }
}

<#
    Y los que estan registrados como servicio, vengan de donde vengan.

    Esto importa mas de lo que parece: un motor instalado a mano o portatil no
    esta en Archivos de programa ni en la carpeta de XAMPP, y buscando solo por
    rutas conocidas se pasa por alto justo el que podria servir. El servicio si
    guarda la ruta real del ejecutable, asi que se lee de ahi.

    La ruta viene entre comillas y a veces con argumentos detras
    (`"C:\...\mysqld.exe" --defaults-file=...`), de modo que se recorta hasta
    el .exe.
#>
Get-CimInstance Win32_Service -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match '(?i)mysql|maria' -or $_.PathName -match '(?i)mysqld|mariadbd' } |
    ForEach-Object {
        $m = [regex]::Match($_.PathName, '(?i)^\s*"?(.+?\.exe)"?')
        if ($m.Success) { $candidatos += $m.Groups[1].Value }
    }

$servibles = @()
$vistos    = @()

Write-Host ''
Write-Host '  Ejecutables encontrados' -ForegroundColor White
Write-Host ''

foreach ($exe in ($candidatos | Select-Object -Unique)) {
    if (-not (Test-Path $exe)) { continue }

    $cruda = (& $exe --version 2>$null | Select-Object -First 1)
    if (-not $cruda) { continue }

    $v = Sirve $cruda
    $vistos += $exe

    Write-Host "    $exe" -ForegroundColor Gray
    if ($null -eq $v) {
        Linea 'AVISO' "No pude leer la version: $cruda"
        continue
    }

    if ($v.Pasa) {
        Linea 'OK' "$($v.Motor) $($v.Numero) - sirve"
        $servibles += $v
    } else {
        Linea 'NO' "$($v.Motor) $($v.Numero) - hace falta $($v.Motor) $($v.Minimo) o superior"
    }
}

if (-not $vistos) {
    Linea 'AVISO' 'Ninguno en las rutas habituales.'
    Write-Host '         Puede que la base viva en otra maquina; eso no es problema,' -ForegroundColor DarkGray
    Write-Host '         basta con que la version cumpla el minimo.' -ForegroundColor DarkGray
}

# --- servicios --------------------------------------------------------------
Write-Host ''
Write-Host '  Servicios de Windows relacionados' -ForegroundColor White
Write-Host ''

$servicios = Get-Service -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match '(?i)mysql|maria' }

if ($servicios) {
    $servicios | ForEach-Object {
        Write-Host ('    {0,-24} {1}' -f $_.Name, $_.Status) -ForegroundColor Gray
    }
} else {
    Write-Host '    ninguno' -ForegroundColor DarkGray
}

# --- puertos ----------------------------------------------------------------
Write-Host ''
Write-Host '  Puertos' -ForegroundColor White
Write-Host ''

$libres   = @()
$ocupados = @()
foreach ($p in 3306, 3307, 3308, 3309, 3310, 3311) {
    $ocupado = $null -ne (Get-NetTCPConnection -State Listen -LocalPort $p -ErrorAction SilentlyContinue)
    if ($ocupado) {
        Write-Host ('    {0}  ocupado' -f $p) -ForegroundColor DarkYellow
        $ocupados += $p
    } else {
        Write-Host ('    {0}  libre' -f $p) -ForegroundColor DarkGray
        $libres += $p
    }
}

# --- veredicto --------------------------------------------------------------
Titulo 'Conclusion'
Write-Host ''

if ($servibles) {
    $m = $servibles[0]
    Write-Host "   Hay un motor que sirve: $($m.Motor) $($m.Numero)." -ForegroundColor Green
    Write-Host ''
    Write-Host '   Si ya esta corriendo, use el puerto en el que escucha. Si no, levantelo'
    Write-Host '   en uno libre. En cualquier caso ese numero va en PUERTO_BD, dentro de'
    Write-Host '   2-INSTALAR.bat y de 1-COMPROBAR.bat.'
    Write-Host ''
    Write-Host ('   Ocupados ahora: ' + ($ocupados -join ', ')) -ForegroundColor DarkGray
    Write-Host ('   Libres ahora:   ' + ($libres   -join ', ')) -ForegroundColor DarkGray
    Write-Host ''
    Write-Host '   El puerto en el que escucha sale del my.ini de ese motor, o de:' -ForegroundColor DarkGray
    Write-Host '     netstat -ano | findstr LISTENING' -ForegroundColor DarkGray
} else {
    Write-Host '   Ningun motor de esta maquina alcanza el minimo.' -ForegroundColor Red
    Write-Host ''
    Write-Host '   El sistema necesita MySQL 5.7.8+ o MariaDB 10.3+ por las columnas'
    Write-Host '   de tipo JSON del esquema (rutas, zonas, webhooks).'
    Write-Host ''
    Write-Host '   Instalar MariaDB al lado no toca lo que ya esta: es otro servicio'
    Write-Host '   en otro puerto, y el motor actual sigue sirviendo a lo que hoy'
    Write-Host '   dependa de el.'
}

Write-Host ''
