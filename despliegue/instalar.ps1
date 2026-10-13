<#
=============================================================================
 AVALIA LOGISTICA - instalador para Windows Server con XAMPP
=============================================================================

 Deja la plataforma corriendo en un servidor limpio: base de datos con sus dos
 usuarios, configuracion, migraciones, compilacion del frontend, Apache
 sirviendo /public y el trabajador de colas en marcha.

 Es IDEMPOTENTE: correrlo dos veces no rompe nada. Lo que ya este hecho se
 detecta y se salta, asi que sirve tanto para instalar como para actualizar.

 ANTES DE CORRERLO hacen falta en el servidor:
   - XAMPP con Apache y PHP 8.2 o superior
   - MariaDB (el portable de XAMPP sirve) accesible y arrancado
   - Node.js 20 o superior  (para compilar el frontend)
   - Composer

 USO
   .\instalar.ps1 -Dominio "190.242.42.242" -Puerto 8082 -Entorno pruebas

 Para actualizar una instalacion existente:
   .\instalar.ps1 -SoloActualizar

=============================================================================
#>

[CmdletBinding()]
param(
    [string]$RaizXampp   = 'C:\xampp',
    [string]$RaizApp     = '',
    [string]$Dominio     = '',
    [int]$Puerto         = 8082,
    [ValidateSet('pruebas','produccion')]
    [string]$Entorno     = 'pruebas',
    [string]$BaseDatos   = 'avalia',
    [int]$PuertoBd       = 3307,

    # Donde escucha MariaDB/MySQL. Por omision 127.0.0.1: si la base vive en
    # el mismo servidor esa direccion NO sale a la red, y asi la base no
    # queda expuesta aunque el cortafuegos este mal puesto.
    [string]$HostBd       = '127.0.0.1',

    [switch]$SoloActualizar,
    [switch]$SinDatosDemo,

    # Comprobacion previa: revisa que el servidor cumple y se detiene. No pide
    # permisos de administrador y no toca NADA. Sirve para validar la maquina
    # destino antes de comprometerse con la instalacion.
    [switch]$Comprobar,

    # Fuerza recompilar el frontend aunque el paquete ya lo traiga hecho.
    [switch]$Recompilar,

    # Fuerza reinstalar las dependencias de PHP aunque el paquete ya las traiga.
    [switch]$Recomponer,

    <#
        Permite instalar sobre un motor anterior a MySQL 5.7.8 / MariaDB 10.3.

        El esquema se construye entonces con columnas de texto en lugar de JSON
        y con juego de caracteres utf8 en vez de utf8mb4. Funciona -las catorce
        columnas JSON del sistema se leen y escriben enteras, sin usar
        operadores JSON del motor-, pero es una pasarela para un entorno de
        pruebas, no un destino: MySQL 5.5 no recibe parches de seguridad desde
        diciembre de 2018.
    #>
    [switch]$MotorAntiguo,

    <#
        Rehace el esquema desde cero: borra todas las tablas de la base y
        vuelve a crearlas.

        Existe porque un intento fallido deja tablas a medias, y el intento
        siguiente choca contra ellas con un error que habla de tablas
        duplicadas en vez del problema original. Solo afecta a la base de este
        sistema; ninguna otra del mismo servidor se toca.
    #>
    [switch]$Rehacer,

    <#
        Fuerza APP_DEBUG=true aunque la direccion sea publica.

        Solo para un rato y sabiendo lo que implica: con la depuracion activa,
        cualquier error muestra la traza, las rutas del disco y la consulta SQL
        que fallo -con sus valores-. En una direccion alcanzable desde fuera eso
        se lo ensena a quien provoque el error, no solo a usted.

        Lo habitual no es esto, sino mirar storage/logs, donde queda lo mismo
        sin publicarlo.
    #>
    [switch]$Depurar,

    <#
        La subcarpeta donde queda publicada la aplicacion, si no es la raiz.

        En un servidor que ya tiene otros sitios, Avalia puede colgar de un
        Alias -/Avalia- en vez de quedarse con el puerto entero. Las
        comprobaciones finales tienen que apuntar ahi; si no, informan 404 en
        todas las rutas y el instalador termina diciendo que algo fallo cuando
        lo que fallaba era donde miraba.
    #>
    [string]$RutaBase = ''
)

$ErrorActionPreference = 'Stop'

# --------------------------------------------------------------------- salida
$script:Fallos = 0
<#
    Ejecuta un programa externo y devuelve su salida y su codigo.

    Existe porque `& programa ... 2>&1` es una trampa en PowerShell 5.1: al
    redirigir el error estandar de un ejecutable NATIVO, cada linea se envuelve
    en un ErrorRecord. Con $ErrorActionPreference = 'Stop' eso es un error
    terminante, asi que el guion muere aunque el programa haya terminado bien
    -basta con que escribiera un aviso por esa via-. Y muere mostrando un
    NativeCommandError que no dice nada del problema real.

    Aqui los dos flujos van a archivos, que es la forma que no tiene sorpresas.
#>
function Correr {
    param(
        [Parameter(Mandatory)][string]$Programa,
        [string[]]$Argumentos = @(),
        [string]$Donde = ''
    )

    $marca = "$PID-" + [guid]::NewGuid().ToString('N').Substring(0, 8)
    $fSal  = Join-Path $env:TEMP "avalia-$marca.out"
    $fErr  = Join-Path $env:TEMP "avalia-$marca.err"

    $opciones = @{
        FilePath               = $Programa
        NoNewWindow            = $true
        Wait                   = $true
        PassThru               = $true
        RedirectStandardOutput = $fSal
        RedirectStandardError  = $fErr
    }
    <#
        Los argumentos con espacios van entrecomillados.

        Start-Process une la lista con espacios y NO la entrecomilla: un
        argumento como `--execute=try { ... }` llega troceado al programa, que
        responde con un error sobre argumentos invalidos sin mencionar de cual
        habla. Se entrecomilla aqui para que ningun sitio de llamada tenga que
        acordarse.

        Limite conocido: si un argumento lleva comillas dobles dentro, esto no
        basta. Ninguno de los de este guion las lleva.
    #>
    $listos = @()
    foreach ($a in $Argumentos) {
        if ($a -match '\s' -and $a -notmatch '^".*"$') { $listos += '"' + $a + '"' }
        else { $listos += $a }
    }

    if ($listos.Count) { $opciones.ArgumentList = $listos }
    if ($Donde)            { $opciones.WorkingDirectory = $Donde }

    $pr = Start-Process @opciones

    $salida = @()
    if (Test-Path $fSal) { $salida += Get-Content $fSal }
    if (Test-Path $fErr) { $salida += Get-Content $fErr }

    Remove-Item -LiteralPath $fSal -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $fErr -Force -ErrorAction SilentlyContinue

    return @{ Salida = $salida; Codigo = $pr.ExitCode; Texto = ($salida -join "`n") }
}

function MostrarCola($salida, [int]$cuantas = 3) {
    $salida | Select-Object -Last $cuantas | ForEach-Object {
        Write-Host "         $_" -ForegroundColor DarkGray
    }
}

function Titulo($t) { Write-Host "`n=== $t ===" -ForegroundColor Cyan }
function Bien($t)   { Write-Host "  [ OK ] $t" -ForegroundColor Green }
function Aviso($t)  { Write-Host "  [ !  ] $t" -ForegroundColor Yellow }
function Malo($t)   { Write-Host "  [FALLA] $t" -ForegroundColor Red; $script:Fallos++ }
function Paso($t)   { Write-Host "  ...... $t" -ForegroundColor DarkGray }

if ($RaizApp -eq '') { $RaizApp = Split-Path -Parent $PSScriptRoot }

Write-Host @"

  ___                _ _
 / _ \__ ____ _ _ __(_) |__ _
 AVALIA LOGISTICA - instalador
 Aplicacion : $RaizApp
 XAMPP      : $RaizXampp
 Entorno    : $Entorno
 Publicara  : http://$(if($Dominio){$Dominio}else{'localhost'}):$Puerto

"@ -ForegroundColor White

# ------------------------------------------------------------- 0. privilegios
$soyAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $soyAdmin -and -not $Comprobar) {
    Malo 'Hay que ejecutarlo como Administrador: toca Apache, servicios y tareas programadas.'
    Write-Host '        Abra PowerShell con boton derecho -> "Ejecutar como administrador".' -ForegroundColor Yellow
    Write-Host '        Para revisar el servidor sin tocarlo:  .\instalar.ps1 -Comprobar' -ForegroundColor Yellow
    exit 1
}

if ($Comprobar) {
    Write-Host '  MODO COMPROBACION: no se modifica nada.' -ForegroundColor Cyan
    if (-not $soyAdmin) { Aviso 'Sin permisos de administrador (para instalar de verdad si hacen falta).' }
}

# ------------------------------------------------------- 1. requisitos previos
Titulo '1. Requisitos'

$php      = Join-Path $RaizXampp 'php\php.exe'
$httpd    = Join-Path $RaizXampp 'apache\bin\httpd.exe'
$confDir  = Join-Path $RaizXampp 'apache\conf'

foreach ($par in @(
    @{ n = 'PHP';    r = $php },
    @{ n = 'Apache'; r = $httpd }
)) {
    if (Test-Path $par.r) { Bien "$($par.n): $($par.r)" }
    else { Malo "No encuentro $($par.n) en $($par.r). Revise -RaizXampp." }
}

$hayPhp = Test-Path $php

if (-not (Test-Path (Join-Path $RaizApp 'artisan'))) {
    Malo "No encuentro 'artisan' en $RaizApp. Copie el proyecto ahi primero."
}

if ($script:Fallos -gt 0 -and -not $Comprobar) {
    Write-Host "`nCorrija lo anterior y vuelva a intentarlo.`n" -ForegroundColor Red
    exit 1
}

if (-not $hayPhp) {
    Write-Host "`nSin PHP no se puede seguir comprobando.`n" -ForegroundColor Red
    exit 1
}

# Version de PHP
$verPhp = & $php -r "echo PHP_VERSION;"
if ([version]($verPhp -replace '-.*$','') -lt [version]'8.2') {
    Malo "PHP $verPhp es muy viejo. Hace falta 8.2 o superior."
    if (-not $Comprobar) { exit 1 }
} else {
    Bien "PHP $verPhp"
}

<#
    Extensiones de PHP.

    La lista OBLIGATORIA sale de lo que el proyecto usa de verdad, comprobado
    contra `composer check-platform-reqs` y contra el codigo, no de una lista
    generica de Laravel. Poner de mas no es prudente: un requisito falso
    bloquea una instalacion que habria funcionado. `intl`, por ejemplo, no hace
    falta aqui -las fechas se formatean en el navegador- y exigirla detenia el
    instalador sin motivo.
#>
$obligatorias = @('pdo_mysql','mbstring','openssl','fileinfo','curl','tokenizer','xml','json','ctype','filter')
$deseables    = @('zip','gd','opcache')

$instaladas = (& $php -m) -split "`r?`n"
$faltan     = $obligatorias | Where-Object { $_ -notin $instaladas }
$sinEllas   = $deseables    | Where-Object { $_ -notin $instaladas }

if ($faltan.Count -gt 0) {
    Malo "Faltan extensiones de PHP obligatorias: $($faltan -join ', ')"
    Write-Host "        Descomentelas en $RaizXampp\php\php.ini (quite el ';' de 'extension=...')" -ForegroundColor Yellow
    if (-not $Comprobar) { exit 1 }
} else {
    Bien "Extensiones obligatorias completas ($($obligatorias.Count))"
}

if ($sinEllas.Count -gt 0) {
    # No detienen nada: 'zip' solo lo usa composer al descomprimir, 'opcache'
    # es rendimiento y 'gd' quedaria para un redimensionado de fotos que hoy
    # no se hace en el servidor.
    Aviso "Recomendables ausentes (no bloquean): $($sinEllas -join ', ')"
} else {
    Bien 'Extensiones recomendables presentes'
}

<#
    Composer solo hace falta si hay que INSTALAR dependencias.

    El paquete autonomo trae `vendor` ya resuelto. Su autoloader usa rutas
    relativas -`dirname(__DIR__)`- asi que funciona movido a cualquier carpeta,
    y no queda dentro ni una ruta absoluta de la maquina donde se genero.

    Exigir composer igualmente bloqueaba una instalacion que iba a funcionar,
    que es el mismo error que ya costo antes pedir la extension `intl`.
#>
$script:HayVendor = Test-Path (Join-Path $RaizApp 'vendor/autoload.php')
$script:Componer  = $Recomponer -or (-not $script:HayVendor)

if ($script:HayVendor -and -not $Recomponer) {
    Bien 'El paquete trae vendor resuelto: no hara falta composer'
} else {
    $ruta = (Get-Command composer -ErrorAction SilentlyContinue).Source
    if ($ruta) { Bien 'composer disponible' } else { Malo 'Falta composer en el PATH (hace falta para instalar dependencias).' }
}

<#
    Node solo hace falta si hay que COMPILAR.

    El paquete de despliegue trae el frontend ya compilado en public/build, asi
    que un servidor que solo va a servir la aplicacion no necesita Node ni
    descargar 170 MB de dependencias. Exigirlo igualmente bloquearia
    instalaciones que iban a funcionar perfectamente.
#>
$script:HayBuild = Test-Path (Join-Path $RaizApp 'public/build/manifest.json')
$script:Compilar = $Recompilar -or (-not $script:HayBuild)

if ($script:HayBuild -and -not $Recompilar) {
    Bien 'El paquete trae el frontend compilado: no hara falta Node'
}

if ($script:Compilar) {
    foreach ($h in @('node','npm')) {
        $r = (Get-Command $h -ErrorAction SilentlyContinue).Source
        if ($r) { Bien "$h disponible" } else { Malo "Falta $h en el PATH (hace falta para compilar)." }
    }

    if (Get-Command node -ErrorAction SilentlyContinue) {
        $verNode = (& node --version) -replace '^v',''
        if ([version]($verNode -split '-')[0] -lt [version]'18.0.0') {
            Malo "Node $verNode es muy viejo. Vite necesita 18 o superior."
        } else { Bien "Node $verNode" }
    }
}

# El puerto que se va a usar, libre o ya nuestro.
$ocupa = Get-NetTCPConnection -LocalPort $Puerto -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($ocupa) {
    $quien = (Get-Process -Id $ocupa.OwningProcess -ErrorAction SilentlyContinue).ProcessName
    if ($quien -eq 'httpd') { Bien "Puerto $Puerto ya lo atiende Apache" }
    else { Aviso "Puerto $Puerto ocupado por '$quien'. Habra que liberarlo o usar otro." }
} else { Bien "Puerto $Puerto libre" }

if ($script:Fallos -gt 0 -and -not $Comprobar) { exit 1 }

if ($Comprobar) {
    Write-Host ''
    if ($script:Fallos -eq 0) {
        Write-Host '  El servidor cumple los requisitos. Puede instalar.' -ForegroundColor Green
        Write-Host '  Lo normal es hacerlo con doble clic en 2-INSTALAR.bat.' -ForegroundColor DarkGray

        # La bandera se arrastra a la sugerencia: omitirla aqui haria que quien
        # copie la linea instale en modo normal y choque contra la version.
        $extra = if ($MotorAntiguo) { ' -MotorAntiguo' } else { '' }
        Write-Host "  .\instalar.ps1 -RaizXampp '$RaizXampp' -Dominio '$Dominio' -Puerto $Puerto -Entorno $Entorno$extra" -ForegroundColor Green

        if ($MotorAntiguo) {
            Write-Host ''
            Write-Host '  Va en modo compatible (-MotorAntiguo): si el motor resulta ser' -ForegroundColor Yellow
            Write-Host '  moderno, esa bandera no estorba; solo actua si hace falta.'     -ForegroundColor Yellow
        }
    } else {
        Write-Host "  Faltan $script:Fallos cosa(s). Corrijalas antes de instalar." -ForegroundColor Red
    }
    Write-Host ''
    exit $(if ($script:Fallos -eq 0) { 0 } else { 1 })
}

# ------------------------------------------------------------ 2. base de datos
Titulo '2. Base de datos'

<#
    La preparacion de la base va por PHP, no por el cliente mysql.exe.

    Ese cliente no siempre esta en el servidor -sobre todo cuando la base vive
    en otra maquina- y obligaba a instalar algo solo para tres sentencias SQL.
    PHP con pdo_mysql ya esta ahi: sin el la aplicacion tampoco arrancaria.

    Las credenciales viajan por variables de entorno del proceso hijo, nunca
    por argumentos: los argumentos los ve cualquiera con la lista de tareas.
#>
$preparador = Join-Path $PSScriptRoot 'preparar-base.php'

if (-not (Test-Path $preparador)) {
    Malo "No encuentro preparar-base.php junto a este instalador."
    exit 1
}

if (-not $SoloActualizar) {
    Write-Host ''
    Write-Host '  Se necesita una cuenta con permiso para CREAR bases y usuarios (normalmente root).' -ForegroundColor White

    # Alfabeto acotado: GeneratePassword mete simbolos que en un .env rompen la
    # linea -la almohadilla inicia un comentario y deja media contrasena-.
    $alfabeto = [char[]]'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789._-+='
    $clveApp   = -join (1..32 | ForEach-Object { $alfabeto | Get-Random })
    $clveAdmin = -join (1..32 | ForEach-Object { $alfabeto | Get-Random })

    <#
        Hasta tres intentos con la credencial, y solo si el motor dice que la
        rechaza.

        La contrasena se escribe a ciegas y suele llevar simbolos: un tecleo
        malo no deberia costar repetir la instalacion entera, sobre todo cuando
        para este punto ya se comprobo medio servidor. Cualquier otro fallo
        -motor inalcanzable, version insuficiente, permisos- corta de una vez,
        porque reintentar no lo arregla y solo entretiene.
    #>
    $intento    = 0
    $maxIntento = 3
    $ok         = $false

    while (-not $ok) {
        $intento++

        Write-Host ''
        $usrRaiz = Read-Host '  Usuario administrador de la base [root]'
        if ([string]::IsNullOrWhiteSpace($usrRaiz)) { $usrRaiz = 'root' }
        $pwdRaiz = Read-Host '  Su contrasena (vacia si no tiene)' -AsSecureString
        $pwdRaizPlano = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
            [Runtime.InteropServices.Marshal]::SecureStringToBSTR($pwdRaiz))

        Paso 'Preparando base y usuarios...'

        $sobres = @{
            AVALIA_HOST          = $HostBd
            AVALIA_PUERTO        = "$PuertoBd"
            AVALIA_USUARIO       = $usrRaiz
            AVALIA_CLAVE         = $pwdRaizPlano
            AVALIA_BASE          = $BaseDatos
            AVALIA_CLAVE_APP     = $clveApp
            AVALIA_CLAVE_ADMIN   = $clveAdmin
            AVALIA_MOTOR_ANTIGUO = $(if ($MotorAntiguo) { '1' } else { '0' })
        }
        foreach ($k in $sobres.Keys) { [Environment]::SetEnvironmentVariable($k, $sobres[$k]) }

        <#
            Los dos flujos se recogen en archivos, NO con `2>&1`.

            En PowerShell 5.1, redirigir el error estandar de un ejecutable
            nativo envuelve cada linea en un ErrorRecord: en pantalla sale un
            NativeCommandError en rojo con rastro de llamadas, y el mensaje util
            -el que dice que motor hay y cual hace falta- queda sepultado.
        #>
        $fSalida = Join-Path $env:TEMP "avalia-bd-$PID.out"
        $fError  = Join-Path $env:TEMP "avalia-bd-$PID.err"

        $proceso = Start-Process -FilePath $php -ArgumentList "`"$preparador`"" `
            -NoNewWindow -Wait -PassThru `
            -RedirectStandardOutput $fSalida -RedirectStandardError $fError

        $salida = @()
        if (Test-Path $fSalida) { $salida += Get-Content $fSalida }
        if (Test-Path $fError)  { $salida += Get-Content $fError }
        Remove-Item $fSalida, $fError -Force -ErrorAction SilentlyContinue

        $ok = $proceso.ExitCode -eq 0

        # Se limpian en cuanto dejan de hacer falta.
        foreach ($k in $sobres.Keys) { [Environment]::SetEnvironmentVariable($k, $null) }
        $pwdRaizPlano = $null

        if ($ok) { break }

        <#
            Que se reintenta y que no.

            1045 es el rechazo limpio de credencial que devuelve MySQL. Pero
            MariaDB, ante una clave que no es, contesta 2054 -"metodo de
            autenticacion desconocido"- sin mencionar la contrasena por ningun
            lado. Mirando solo 1045 el reintento no saltaria justo en la mitad
            de los motores.

            2054 tambien puede ser una incompatibilidad real de plugin, que
            reintentar no arregla; por eso el aviso nombra las dos causas en
            vez de afirmar cual es. Todo lo demas -motor inalcanzable, version
            insuficiente, permisos- corta de una vez.
        #>
        $credencialMala = $salida | Select-String -Pattern '1045|2054|Access denied|authentication method' -Quiet

        if ($credencialMala -and $intento -lt $maxIntento) {
            Write-Host ''
            Aviso "El motor rechazo la credencial (intento $intento de $maxIntento)."
            Write-Host '         Casi siempre es la contrasena: se escribe a ciegas y no se ve' -ForegroundColor Yellow
            Write-Host '         lo que se teclea. Revise mayusculas y simbolos.'               -ForegroundColor Yellow
            Write-Host '         Si el mensaje habla de un metodo de autenticacion y la clave'  -ForegroundColor DarkGray
            Write-Host '         es correcta, puede ser el plugin del motor; compruebelo con'   -ForegroundColor DarkGray
            Write-Host '         4-PROBAR-CONEXION.bat.'                                        -ForegroundColor DarkGray
            continue
        }

        Malo 'No se pudo preparar la base.'
        $salida | Where-Object { $_ -notmatch '^(VERSION|MOTOR|JUEGO|COTEJO|MODO)=' } |
            ForEach-Object { Write-Host "         $_" -ForegroundColor Red }

        if ($credencialMala) {
            Write-Host ''
            Write-Host "         Agotados los $maxIntento intentos. Compruebe la credencial con" -ForegroundColor Yellow
            Write-Host '         4-PROBAR-CONEXION.bat y vuelva a lanzar la instalacion.'        -ForegroundColor Yellow
        }

        exit 1
    }

    function Dato($clave) {
        $l = ($salida | Select-String "^$clave=" | Select-Object -First 1)
        if ($l) { return ($l.ToString() -replace "^$clave=", '') }
        return ''
    }

    $version = Dato 'VERSION'
    if ($version) { Bien "Motor: $version" }

    # Lo que informa el preparador manda sobre lo que supongamos aqui.
    $script:Controlador = Dato 'MOTOR'
    $script:Juego       = Dato 'JUEGO'
    $script:Cotejo      = Dato 'COTEJO'
    $script:ModoAntiguo = (Dato 'MODO') -eq 'antiguo'

    if (-not $script:Controlador) { $script:Controlador = 'mariadb' }
    if (-not $script:Juego)       { $script:Juego       = 'utf8mb4' }
    if (-not $script:Cotejo)      { $script:Cotejo      = 'utf8mb4_unicode_ci' }

    Bien "Base '$BaseDatos' y usuarios avalia_app / avalia_admin listos"

    if ($script:ModoAntiguo) {
        Write-Host ''
        Aviso 'MODO COMPATIBLE: el motor esta por debajo del minimo.'
        Write-Host '         Las columnas JSON se guardan como texto y el juego de'  -ForegroundColor Yellow
        Write-Host '         caracteres es utf8 (sin emojis). La aplicacion funciona' -ForegroundColor Yellow
        Write-Host '         igual, pero esto es para pruebas.'                       -ForegroundColor Yellow
        Write-Host ''
        Write-Host '         Al pasar a MySQL 8 o MariaDB 10.4+: quite DB_MOTOR_ANTIGUO' -ForegroundColor DarkGray
        Write-Host '         del .env y reconstruya el esquema.'                         -ForegroundColor DarkGray
        Write-Host ''
    }
} else {
    Aviso 'Modo actualizacion: no se toca la base ni los usuarios.'
}

# ------------------------------------------------------------ 3. configuracion
Titulo '3. Configuracion'

Set-Location $RaizApp
$envFile = Join-Path $RaizApp '.env'

if (-not $SoloActualizar) {
    <#
        La configuracion de correo del .env anterior se conserva.

        El instalador reescribe el .env entero, y eso borraria el servidor SMTP
        y su contrasena cada vez que se actualiza. Esos datos no los genera el
        instalador: los escribio alguien a mano, no estan en ningun otro sitio
        y recuperarlos significa ir a pedirlos al proveedor de correo.

        Se rescatan tal cual, sin leerlos ni mostrarlos.
    #>
    $correoAnterior = @()

    if (Test-Path $envFile) {
        $resp = Join-Path $RaizApp ".env.antes-de-instalar-$(Get-Date -Format yyyyMMdd-HHmmss)"
        Copy-Item $envFile $resp
        Aviso "Ya habia un .env; se respaldo en $(Split-Path -Leaf $resp)"

        $correoAnterior = Get-Content $envFile | Where-Object { $_ -match '^\s*MAIL_' }

        if ($correoAnterior.Count) {
            Bien "Conservando $($correoAnterior.Count) linea(s) de configuracion de correo"
        }

        # La direccion publica tambien se conserva, y por una razon concreta:
        # APP_URL es lo que va DENTRO de cada QR impreso y de los enlaces que
        # salen por correo. Este guion solo sabe armar http://host:puerto, asi
        # que en un sitio ya publicado con dominio y certificado siempre tiene
        # razon el .env anterior. Pisarlo dejaba guias impresas apuntando a
        # localhost y correos que el cliente no podia abrir.
        $urlAnterior = (Get-Content $envFile |
            Where-Object { $_ -match '^\s*APP_URL\s*=\s*\S' } |
            Select-Object -First 1)
    }

    $urlBase = "http://$(if($Dominio){$Dominio}else{'localhost'}):$Puerto"

    if ($urlAnterior) {
        Bien "APP_URL conservada del .env anterior: $(($urlAnterior -replace '^\s*APP_URL\s*=\s*','').Trim('"'))"
    } elseif ($Dominio -and $urlBase -match 'localhost') {
        Aviso "APP_URL quedo en localhost con -Dominio '$Dominio'. Revisela: es lo que se imprime en cada QR."
    }
    <#
        La depuracion se decide por la DIRECCION, no por el nombre del entorno.

        Antes bastaba con que el entorno no fuese 'produccion' para dejar
        APP_DEBUG=true. Eso esta bien en una maquina de desarrollo y es un
        problema en cuanto la aplicacion es alcanzable desde fuera: la pagina
        de error de Laravel muestra la traza completa, las rutas del disco y la
        consulta SQL que fallo con sus valores. Un servidor de pruebas en una
        IP publica sigue siendo publico.

        Asi que se enciende solo cuando la direccion es local, y se puede
        forzar con -Depurar a sabiendas.
    #>
    $esLocal = (-not $Dominio) -or ($Dominio -in @('localhost', '127.0.0.1', '::1')) -or
               ($Dominio -match '\.(local|test)$')

    $depuracion = if ($Depurar) { 'true' }
               elseif ($Entorno -eq 'produccion') { 'false' }
               elseif ($esLocal) { 'true' }
               else { 'false' }

    $entLar  = if ($Entorno -eq 'produccion') { 'production' } else { 'local' }

    if ($depuracion -eq 'true' -and -not $esLocal) {
        Aviso "APP_DEBUG=true sobre una direccion publica ($Dominio), por -Depurar."
        Write-Host '         Quitelo en cuanto termine de diagnosticar.' -ForegroundColor Yellow
    } elseif ($depuracion -eq 'false' -and $Entorno -ne 'produccion') {
        Bien "APP_DEBUG=false: $Dominio no es una direccion local"
        Write-Host '         Los errores quedan en storage/logs, no en pantalla.' -ForegroundColor DarkGray
    }

    <#
        El bloque de correo: lo que ya habia, o el valor de fabrica.

        En modo `log` los avisos no salen a ningun sitio: se escriben en
        storage/logs. Es lo correcto mientras no haya un servidor de verdad,
        porque es preferible que no salga nada a que salga a medias.
    #>
    $bloqueCorreo = if ($correoAnterior.Count) {
        "# Correo: conservado de la instalacion anterior.`r`n" + ($correoAnterior -join "`r`n")
    } else {
        @'
# Correo: sin configurar. Las alertas de SOAT y los avisos de PQRS no saldran
# hasta que aqui haya un servidor real.
MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-responder@avalialogistica.com"
MAIL_FROM_NAME="Avalia Logistica"
'@
    }

    $notaMotor = 
    if ($script:ModoAntiguo) {
        $notaMotor = @'
# Motor anterior a MySQL 5.7.8: las columnas JSON se guardan como texto y las
# cadenas indexadas se limitan a 191 caracteres, que es lo que aguanta InnoDB
# de esa epoca. Al migrar a un motor moderno, quite esta linea y reconstruya el
# esquema para recuperar columnas JSON de verdad.
DB_MOTOR_ANTIGUO=true
'@
    }

    @"
APP_NAME="Avalia Logistica"
APP_ENV=$entLar
APP_KEY=
APP_DEBUG=$depuracion
APP_TIMEZONE=America/Bogota
$(if ($urlAnterior) { $urlAnterior.Trim() } else { "APP_URL=$urlBase" })

APP_LOCALE=es
APP_FALLBACK_LOCALE=es

LOG_CHANNEL=daily
LOG_LEVEL=$(if($Entorno -eq 'produccion'){'warning'}else{'debug'})

DB_CONNECTION=$script:Controlador
DB_HOST=$HostBd
DB_PORT=$PuertoBd
DB_DATABASE=$BaseDatos
DB_USERNAME=avalia_app
DB_PASSWORD="$clveApp"
DB_CHARSET=$script:Juego
DB_COLLATION=$script:Cotejo
$notaMotor

DB_ADMIN_USERNAME=avalia_admin
DB_ADMIN_PASSWORD="$clveAdmin"

SESSION_DRIVER=database
SESSION_LIFETIME=120
CACHE_STORE=database
QUEUE_CONNECTION=database

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

$bloqueCorreo

AVALIA_GUIA_PREFIJO=AVL
AVALIA_MAPA_TESELAS="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
AVALIA_MAPA_CREDITO="(c) OpenStreetMap"
AVALIA_RUTEADOR=https://router.project-osrm.org

# Clave del sello diario de la bitacora. Se genera aqui; cambiarla invalida la
# comprobacion de los sellos ya emitidos.
LTP_SELLO_CLAVE=$([guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N'))
"@ | Set-Content -Path $envFile -Encoding utf8

    Bien '.env escrito con credenciales generadas'
} else {
    Aviso 'Modo actualizacion: se conserva el .env existente.'
}

# ------------------------------------------------------------ 4. dependencias
Titulo '4. Dependencias y compilacion'

if ($script:Componer) {
    Paso 'composer install...'
    $argsComposer = @('install','--no-interaction','--prefer-dist')
    if ($Entorno -eq 'produccion') { $argsComposer += @('--no-dev','--optimize-autoloader') }
    $r = Correr 'composer' $argsComposer $RaizApp
    MostrarCola $r.Salida 3
    if ($r.Codigo -ne 0) { Malo 'composer install fallo.'; exit 1 }
    Bien 'Dependencias de PHP instaladas'
} else {
    Bien 'Dependencias de PHP ya presentes (use -Recomponer para rehacerlas)'
}

# Se comprueba siempre, vengan hechas o recien instaladas.
if (-not (Test-Path (Join-Path $RaizApp 'vendor/autoload.php'))) {
    Malo 'Falta vendor/autoload.php. Sin el la aplicacion no arranca.'
    exit 1
}

if ($script:Compilar) {
    Paso 'npm ci...'
    $orden = if (Test-Path (Join-Path $RaizApp 'package-lock.json')) { 'ci' } else { 'install' }
    $r = Correr 'npm.cmd' @($orden, '--no-audit', '--no-fund') $RaizApp
    if ($r.Codigo -ne 0) { Malo 'La instalacion de paquetes de Node fallo.'; MostrarCola $r.Salida 5; exit 1 }
    Bien 'Dependencias de Node instaladas'

    Paso 'npm run build...'
    $r = Correr 'npm.cmd' @('run', 'build') $RaizApp
    MostrarCola $r.Salida 2
    if ($r.Codigo -ne 0) { Malo 'La compilacion del frontend fallo.'; exit 1 }
    Bien 'Frontend compilado'
} else {
    Bien 'Frontend ya compilado en el paquete (use -Recompilar para rehacerlo)'
}

# Se comprueba SIEMPRE, se haya compilado aqui o venga hecho: sin el manifiesto
# la aplicacion carga en blanco y el fallo aparece en el navegador, no aqui.
if (-not (Test-Path (Join-Path $RaizApp 'public/build/manifest.json'))) {
    Malo 'Falta public/build/manifest.json. Sin eso la aplicacion carga en blanco.'
    exit 1
}

# ------------------------------------------------------------- 5. la aplicacion
Titulo '5. Aplicacion'

if (-not (Select-String -Path $envFile -Pattern '^APP_KEY=base64:' -Quiet)) {
    & $php artisan key:generate --force | Out-Null
    Bien 'APP_KEY generada'
} else {
    Bien 'APP_KEY ya existia'
}

<#
    Se prueba la conexion ANTES de migrar.

    Si el host, el puerto o la contrasena estan mal, migrar suelta un volcado
    de PDO de treinta lineas que no dice cual de las tres cosas falla. Esta
    comprobacion falla en una linea y nombra el problema.
#>
Paso 'Probando la conexion a la base...'
$codigoPrueba = "try { DB::connection()->getPdo(); echo 'OK'; } catch (\Throwable `$e) { echo 'ERROR: ' . `$e->getMessage(); }"
$prueba = (Correr $php @('artisan', 'tinker', "--execute=$codigoPrueba") $RaizApp).Texto

if ($prueba -match 'OK') {
    Bien "Conectado a $BaseDatos en ${HostBd}:$PuertoBd como avalia_app"
} else {
    Malo 'La aplicacion no puede conectarse a la base.'
    Write-Host "         $($prueba.Trim() -split "`n" | Select-Object -First 2)" -ForegroundColor Red
    Write-Host '         Revise DB_HOST, DB_PORT y DB_PASSWORD en el .env.' -ForegroundColor Yellow
    exit 1
}
<#
    La conexion administrativa que toca segun el motor.

    No es intercambiable: en Laravel 12 `mysql` y `mariadb` son controladores
    distintos, y la capa de compatibilidad para motores antiguos se engancha por
    controlador. Migrar un MySQL a traves del controlador de MariaDB deja esa
    capa sin efecto y el esquema vuelve a pedir columnas JSON.
#>
$conexionAdmin = "$($script:Controlador)_admin"

Paso "Migraciones (usuario administrativo, conexion $conexionAdmin)..."

<#
    La salida entera se guarda antes de recortarla para la pantalla.

    Recortar a las ultimas lineas deja fuera justo lo que importa cuando algo
    falla: el mensaje del motor. La traza de PHP ocupa decenas de lineas y
    empuja el error real fuera de la vista, y sin el no hay nada que diagnosticar.
#>
$bitacora = Join-Path $PSScriptRoot 'ultima-instalacion.log'

<#
    Ejecuta las migraciones y devuelve la salida completa.

    La salida entera se guarda antes de recortarla para la pantalla: recortar
    deja fuera justo lo que importa cuando algo falla, porque la traza de PHP
    ocupa decenas de lineas y empuja el mensaje del motor fuera de la vista.
#>
function Migrar([string]$orden) {
    $r = Correr $php @('artisan', $orden, "--database=$conexionAdmin", '--force') $RaizApp
    $r.Salida | Out-File -FilePath $bitacora -Encoding utf8
    return @{ Salida = $r.Salida; Codigo = $r.Codigo }
}

# Del volcado interesan la sentencia que no paso y el SQLSTATE del motor, que
# en la traza van a decenas de lineas de distancia.
function MostrarFallo($salida) {
    $sql = $salida | Select-String -Pattern 'SQLSTATE|create table|alter table|Base table|Duplicate|Unknown|Syntax|errno' |
        Select-Object -First 8

    if ($sql) {
        Write-Host '  Lo que dijo el motor:' -ForegroundColor Yellow
        $sql | ForEach-Object {
            $t = $_.ToString().Trim()
            if ($t.Length -gt 300) { $t = $t.Substring(0, 300) + '...' }
            Write-Host "    $t" -ForegroundColor Red
        }
        Write-Host ''
    }

    Write-Host '  La salida completa quedo en:' -ForegroundColor Yellow
    Write-Host "    $bitacora" -ForegroundColor White
    Write-Host ''
}

$ordenMig = if ($Rehacer) { 'migrate:fresh' } else { 'migrate' }
if ($Rehacer) { Aviso "Rehaciendo el esquema desde cero en '$BaseDatos'." }

$mig = Migrar $ordenMig

<#
    Tablas sobrantes de un intento anterior: se ofrece rehacer en el momento.

    Antes esto pedia editar 2-INSTALAR.bat y volver a empezar. Mala idea por
    dos motivos: obliga a tocar un archivo a mano en mitad de una instalacion,
    y esa edicion se pierde en cuanto alguien vuelve a descomprimir el paquete
    encima. Preguntarlo aqui no depende de ningun archivo.
#>
if ($mig.Codigo -ne 0 -and -not $Rehacer -and
    ($mig.Salida | Select-String -Pattern 'already exists|1050' -Quiet)) {

    Malo 'Las migraciones fallaron.'
    Write-Host ''
    MostrarFallo $mig.Salida

    Write-Host '  Son tablas que dejo un intento anterior a medias.' -ForegroundColor Yellow
    Write-Host ''
    Write-Host "  Puedo borrar las tablas de '$BaseDatos' y crearlas de nuevo." -ForegroundColor White
    Write-Host '  No se toca ninguna otra base de este servidor.' -ForegroundColor DarkGray
    Write-Host ''

    $r = Read-Host '  Rehacer el esquema ahora? (s/N)'

    if ($r -match '^[sSyY]') {
        Write-Host ''
        Paso "Rehaciendo el esquema de '$BaseDatos'..."
        $mig = Migrar 'migrate:fresh'
    }
}

if ($mig.Codigo -ne 0) {
    Malo 'Las migraciones fallaron.'
    Write-Host ''
    MostrarFallo $mig.Salida
    Write-Host '  Para rehacer el esquema sin que pregunte, anada -Rehacer' -ForegroundColor DarkGray
    Write-Host "  (o ponga REHACER=si en 2-INSTALAR.bat). Solo afecta a '$BaseDatos'." -ForegroundColor DarkGray
    Write-Host ''
    exit 1
}

$mig.Salida | Select-Object -Last 2 | ForEach-Object { Write-Host "         $_" -ForegroundColor DarkGray }
Bien 'Esquema al dia'

$r = Correr $php @('artisan', 'storage:link') $RaizApp
if ($r.Codigo -eq 0) { Bien 'Enlace de storage creado' }
else { Aviso 'No se pudo crear el enlace de storage; las evidencias no se veran.'; MostrarCola $r.Salida 2 }

<#
    Las carpetas escribibles. Si Apache no puede escribir aqui, la aplicacion
    responde 500 sin decir por que.

    Dos cosas aprendidas a base de romperlo:

    1. Los grupos se nombran por SID, no por nombre. "Users" no existe en un
       Windows en espanol -alli es "Usuarios"- y en una maquina unida a un
       dominio icacls intenta resolver el nombre contra el dominio; si la
       relacion de confianza esta rota, falla con un mensaje que no habla de
       permisos. El SID S-1-5-32-545 es el mismo en todos los idiomas y se
       resuelve sin salir de la maquina.

    2. Esto NO debe abortar la instalacion. Apache suele correr como
       LocalSystem, que ya puede escribir ahi; el ajuste hace falta cuando
       corre como usuario limitado. Si no se puede aplicar, se avisa y se sigue.
#>
$grupos = @(
    @{ sid = '*S-1-5-32-545'; nombre = 'Usuarios' },
    @{ sid = '*S-1-5-32-568'; nombre = 'IIS_IUSRS' }
)

$ajustados = @()
$fallidos  = @()

foreach ($d in @('storage', 'bootstrap\cache')) {
    $ruta = Join-Path $RaizApp $d
    if (-not (Test-Path $ruta)) { New-Item -ItemType Directory -Force -Path $ruta | Out-Null }

    foreach ($g in $grupos) {
        # Sin `2>&1`: en PowerShell 5.1 eso envuelve la salida de un ejecutable
        # nativo en ErrorRecords y, con ErrorActionPreference = 'Stop', mata el
        # guion. Los dos flujos van a archivos.
        $fSal = Join-Path $env:TEMP "avalia-icacls-$PID.out"
        $fErr = Join-Path $env:TEMP "avalia-icacls-$PID.err"

        $pr = Start-Process -FilePath 'icacls.exe' `
            -ArgumentList "`"$ruta`"", '/grant', "$($g.sid):(OI)(CI)M", '/T', '/Q' `
            -NoNewWindow -Wait -PassThru `
            -RedirectStandardOutput $fSal -RedirectStandardError $fErr

        $detalle = ''
        if (Test-Path $fErr) { $detalle = ((Get-Content $fErr) -join ' ').Trim() }
        Remove-Item $fSal, $fErr -Force -ErrorAction SilentlyContinue

        if ($pr.ExitCode -eq 0) { $ajustados += "$($g.nombre) en $d" }
        else { $fallidos += "$($g.nombre) en ${d}: $detalle" }
    }
}

if ($ajustados) { Bien ('Permisos de escritura: ' + ($ajustados -join ', ')) }

if ($fallidos) {
    Aviso 'Algun permiso no se pudo ajustar. No detiene la instalacion.'
    $fallidos | ForEach-Object { Write-Host "         $_" -ForegroundColor DarkGray }
    Write-Host '         Si Apache corre como LocalSystem -lo habitual- ya puede' -ForegroundColor DarkGray
    Write-Host '         escribir ahi y esto no hace falta. Si mas adelante ve un' -ForegroundColor DarkGray
    Write-Host '         500 sin explicacion, revise que storage/ sea escribible.' -ForegroundColor DarkGray
}

if ($Entorno -eq 'produccion') {
    & $php artisan config:cache | Out-Null
    & $php artisan route:cache  | Out-Null
    & $php artisan view:cache   | Out-Null
    Bien 'Configuracion, rutas y vistas cacheadas'
} else {
    & $php artisan optimize:clear | Out-Null
    Bien 'Caches limpias (entorno de pruebas)'
}

if (-not $SoloActualizar -and -not $SinDatosDemo) {
    Paso 'Sembrando datos de demostracion...'
    <#
        Antes de sembrar, mirar si ya hay algo sembrado.

        El sembrador INSERTA, no reemplaza: pasarlo dos veces choca contra las
        restricciones de unicidad y revienta a mitad. Eso no deberia obligar a
        nadie a acordarse de poner una bandera antes de reinstalar, asi que se
        comprueba aqui.
    #>
    $cuenta = (Correr $php @('artisan', 'tinker', "--execute=echo DB::table('ordenes')->count();") $RaizApp).Texto
    $yaHay  = ($cuenta -match '(\d+)') -and ([int]$Matches[1] -gt 0)

    if ($yaHay) {
        Aviso "La base ya tiene $($Matches[1]) ordenes de ejemplo: no se vuelve a sembrar."
        Write-Host '         Para empezar de cero, reinstale con -Rehacer.' -ForegroundColor DarkGray
    } else {
        $r = Correr $php @('artisan', 'db:seed', '--force') $RaizApp
        MostrarCola $r.Salida 2

        if ($r.Codigo -ne 0) {
            # 1062 es «entrada duplicada»: los datos ya estaban, aunque la
            # cuenta de arriba no lo detectara. No es motivo para tirar abajo
            # una instalacion que por lo demas fue bien.
            if ($r.Texto -match '1062|Duplicate entry') {
                Aviso 'Los datos de ejemplo ya estaban cargados. Se continua.'
            } else {
                Malo 'La siembra de datos fallo.'
                MostrarCola $r.Salida 8
                exit 1
            }
        } else {
            Bien 'Datos de ejemplo cargados'
        }
    }
    Aviso 'Las cuentas de demostracion traen contrasena conocida. Cambielas antes de abrir al publico.'
}

# ----------------------------------------------------------------- 6. Apache
Titulo '6. Apache'

$vhostFile = Join-Path $confDir 'extra\httpd-vhosts.conf'
$httpdConf = Join-Path $confDir 'httpd.conf'
$publico   = (Join-Path $RaizApp 'public') -replace '\\','/'
$marca     = "# --- AVALIA $Puerto ---"
$raizWeb   = $RaizApp.Replace([char]92, '/')

<#
    Blindaje del .env, independiente de como quede el VirtualHost.

    Hoy el .env no se descarga porque la raiz web apunta a `public`. Eso es
    correcto, pero es una sola linea de configuracion: basta un Alias mal
    puesto, un DocumentRoot cambiado o un VirtualHost que no carga para que
    toda la carpeta del proyecto quede servida -ya ocurrio esta semana-.

    Estas reglas van en el ambito del servidor, no dentro de un VirtualHost, y
    se aplican por RUTA DE DISCO. Da igual que direccion invente nadie ni que
    vhost la atienda: si el archivo esta fuera de `public`, Apache no lo
    entrega.

    El FilesMatch cubre ademas los respaldos que genera este mismo instalador
    -.env.antes-de-instalar-20261003-131500- que son el .env completo con otro
    nombre, y por tanto igual de sensibles.
#>
$confSeguridad = Join-Path $confDir 'extra\httpd-avalia-seguridad.conf'

$reglas = @"
# =============================================================================
#  Avalia Logistica - proteccion del codigo y la configuracion
# -----------------------------------------------------------------------------
#  Generado por instalar.ps1. Se reescribe en cada instalacion.
#
#  Solo `public` es publico. Todo lo demas del proyecto -el .env con las
#  contrasenas, vendor, storage, el codigo fuente- queda fuera del alcance de
#  la web aunque alguien apunte mal un DocumentRoot.
# =============================================================================

<Directory "$raizWeb">
    Require all denied
</Directory>

<Directory "$publico">
    Require all granted
</Directory>

# Ningun archivo que empiece por .env, este donde este. Cubre tambien los
# respaldos .env.antes-de-instalar-*, que son el mismo contenido.
<FilesMatch "^\.env">
    Require all denied
</FilesMatch>

# Y los que delatan la estructura o las dependencias del proyecto.
<FilesMatch "^(composer\.(json|lock)|package(-lock)?\.json|artisan|\.gitignore)$">
    Require all denied
</FilesMatch>
"@

Set-Content -Path $confSeguridad -Value $reglas -Encoding ascii

$incluirSeguridad = 'Include conf/extra/httpd-avalia-seguridad.conf'

if (-not (Select-String -Path $httpdConf -Pattern ([regex]::Escape($incluirSeguridad)) -Quiet)) {
    Add-Content -Path $httpdConf -Value "`r`n# Avalia Logistica - proteccion`r`n$incluirSeguridad" -Encoding ascii
}

Bien 'Proteccion del .env y del codigo fuente aplicada'


<#
    Que httpd.conf incluya de verdad el archivo de VirtualHosts.

    Esto es lo que fallo en el despliegue del servidor, y de la peor manera:
    en XAMPP la linea `Include conf/extra/httpd-vhosts.conf` viene COMENTADA.
    El instalador escribia el VirtualHost en un archivo que Apache nunca leia,
    anadia `Listen 8082` -que si funciona-, y el resultado era un puerto
    abierto sirviendo la carpeta htdocs entera. Es decir: el .env, vendor y
    todo el codigo fuente, descargables desde internet.

    Y no daba ningun error: Apache arrancaba bien, solo que con la
    configuracion equivocada.
#>
$incluirVhosts = 'Include conf/extra/httpd-vhosts.conf'
$lineas        = Get-Content $httpdConf

$activo = $lineas | Where-Object { $_ -match '^\s*Include\s+conf/extra/httpd-vhosts\.conf\s*$' }

if ($activo) {
    Bien 'httpd.conf ya incluye los VirtualHosts'
} else {
    Copy-Item $httpdConf "$httpdConf.antes-de-avalia-$(Get-Date -Format yyyyMMdd-HHmmss)"

    $comentado = $lineas | Where-Object { $_ -match '^\s*#\s*Include\s+conf/extra/httpd-vhosts\.conf' }

    if ($comentado) {
        # Estaba, pero comentada: se descomenta en su sitio.
        $nuevas = $lineas | ForEach-Object {
            if ($_ -match '^\s*#\s*Include\s+conf/extra/httpd-vhosts\.conf') { $incluirVhosts } else { $_ }
        }
        Set-Content -Path $httpdConf -Value $nuevas -Encoding ascii
        Bien 'httpd.conf: se descomento la inclusion de VirtualHosts'
    } else {
        Add-Content -Path $httpdConf -Value "`r`n# Avalia Logistica`r`n$incluirVhosts" -Encoding ascii
        Bien 'httpd.conf: se anadio la inclusion de VirtualHosts'
    }
}

<#
    Y que mod_rewrite este cargado.

    Las reglas de public/.htaccess van dentro de <IfModule mod_rewrite.c>, asi
    que sin ese modulo se ignoran EN SILENCIO: Apache arranca, la pagina de
    inicio responde, y cualquier otra direccion da 404 sin explicacion.
#>
$modulos = (Correr $httpd @('-M')).Texto

if ($modulos -match 'rewrite_module') {
    Bien 'mod_rewrite cargado'
} else {
    $lineas = Get-Content $httpdConf
    $hay = $lineas | Where-Object { $_ -match '^\s*#\s*LoadModule\s+rewrite_module' }

    if ($hay) {
        Copy-Item $httpdConf "$httpdConf.antes-de-avalia-rewrite-$(Get-Date -Format yyyyMMdd-HHmmss)"
        $nuevas = $lineas | ForEach-Object {
            if ($_ -match '^\s*#\s*(LoadModule\s+rewrite_module.*)$') { $Matches[1] } else { $_ }
        }
        Set-Content -Path $httpdConf -Value $nuevas -Encoding ascii
        Bien 'mod_rewrite: se descomento su carga en httpd.conf'
    } else {
        Malo 'mod_rewrite no esta cargado y no encuentro su linea en httpd.conf.'
        Write-Host '         Sin el, toda direccion que no sea la inicial dara 404.' -ForegroundColor Red
        exit 1
    }
}

if (-not (Select-String -Path $httpdConf -Pattern "^Listen $Puerto\s*$" -Quiet)) {
    Copy-Item $httpdConf "$httpdConf.antes-de-avalia-$(Get-Date -Format yyyyMMdd-HHmmss)"
    Add-Content -Path $httpdConf -Value "`r`nListen $Puerto" -Encoding ascii
    Bien "Listen $Puerto anadido"
} else {
    Bien "Listen $Puerto ya estaba"
}

if (-not (Select-String -Path $vhostFile -Pattern ([regex]::Escape($marca)) -Quiet)) {
    Copy-Item $vhostFile "$vhostFile.antes-de-avalia-$(Get-Date -Format yyyyMMdd-HHmmss)"

    # La raiz apunta a /public y NO a la carpeta del proyecto. Fuera de /public
    # estan el codigo, el .env con las credenciales y storage con las
    # evidencias: apuntar a la raiz los deja descargables desde el navegador.
    @"

$marca
<VirtualHost *:$Puerto>
    ServerName $(if($Dominio){$Dominio}else{'localhost'})
    DocumentRoot "$publico"

    <Directory "$publico">
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    ErrorLog "logs/avalia-error.log"
    CustomLog "logs/avalia-access.log" combined
</VirtualHost>
"@ | Add-Content -Path $vhostFile -Encoding ascii

    Bien "VirtualHost *:$Puerto -> $publico"
} else {
    Bien 'El VirtualHost ya estaba configurado'
}

# El .htaccess de Laravel. Sin el, toda ruta que no sea '/' devuelve 404.
$hta = Join-Path $RaizApp 'public\.htaccess'
if (-not (Test-Path $hta)) {
    Malo 'Falta public/.htaccess. Sin el, solo funcionaria la pagina de inicio.'
} else { Bien 'public/.htaccess presente' }

Paso 'Comprobando la sintaxis de Apache...'
$chk = (Correr $httpd @('-t')).Texto
if ($chk -match 'Syntax OK') { Bien 'Sintaxis de Apache correcta' }
else { Malo "Apache rechaza la configuracion: $chk"; exit 1 }

# Servicio de Apache
$svc = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
if (-not $svc) {
    Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep 2
    & $httpd -k install -n 'Apache2.4' | Out-Null
    Bien 'Apache registrado como servicio'
}
Set-Service -Name 'Apache2.4' -StartupType Automatic
Restart-Service -Name 'Apache2.4' -Force
Bien 'Apache reiniciado y en arranque automatico'

# ------------------------------------------------------------- 7. las colas
Titulo '7. Colas'

$tareaCola = 'Avalia-Colas'
$tareaProg = 'Avalia-Programador'

# Un trabajador que se apaga solo al vaciar la cola, relanzado cada minuto.
# Windows no trae supervisor de procesos; esto consigue lo mismo sin instalar
# nada mas. Que dos se solapen no duplica trabajo: la cola bloquea cada uno.
& schtasks /Create /F /TN $tareaCola /SC MINUTE /MO 1 /RL HIGHEST /RU SYSTEM `
    /TR "cmd /c cd /d `"$RaizApp`" && `"$php`" artisan queue:work --stop-when-empty --tries=3 --max-time=55" | Out-Null
if ($LASTEXITCODE -eq 0) { Bien "$tareaCola registrada (cada minuto)" } else { Malo "No se pudo registrar $tareaCola" }

& schtasks /Create /F /TN $tareaProg /SC MINUTE /MO 1 /RL HIGHEST /RU SYSTEM `
    /TR "cmd /c cd /d `"$RaizApp`" && `"$php`" artisan schedule:run" | Out-Null
if ($LASTEXITCODE -eq 0) { Bien "$tareaProg registrado (cada minuto)" } else { Malo "No se pudo registrar $tareaProg" }

# ------------------------------------------------------------ 8. comprobacion
Titulo '8. Comprobacion'

Start-Sleep 4
$base = "http://127.0.0.1:$Puerto" + $RutaBase.TrimEnd('/')

foreach ($r in @('/', '/sitio', '/ingreso')) {
    try {
        $resp = Invoke-WebRequest -Uri "$base$r" -UseBasicParsing -TimeoutSec 15 -MaximumRedirection 3
        if ($resp.StatusCode -eq 200) { Bien "$r responde 200" } else { Malo "$r respondio $($resp.StatusCode)" }
    } catch { Malo "$r fallo: $($_.Exception.Message)" }
}

# La API sin token tiene que decir 401, no 500 ni 200.
try {
    Invoke-WebRequest -Uri "$base/api/v1/tablero" -UseBasicParsing -TimeoutSec 10 | Out-Null
    Malo '/api/v1/tablero respondio sin token. Revise la autenticacion.'
} catch {
    if ($_.Exception.Response.StatusCode.value__ -eq 401) { Bien 'La API exige token (401)' }
    else { Malo "La API respondio $($_.Exception.Response.StatusCode.value__) en vez de 401" }
}

<#
    Lo que NO debe ser accesible jamas desde fuera.

    Se prueba por DOS caminos, y el segundo es el que importa:

      /.env                 -> como si el VirtualHost estuviera bien puesto.
      /<carpeta>/.env       -> como si NO lo estuviera y el puerto sirviera la
                               raiz de htdocs.

    Comprobar solo el primero da un falso tranquilizador: si el VirtualHost no
    llego a activarse, "/.env" no existe -y devuelve 404- mientras el archivo
    de verdad se descarga sin problema desde "/Avalia/.env". Paso exactamente
    eso en un despliegue real.
#>
$carpeta = Split-Path -Leaf $RaizApp

<#
    Expuesto o no, mirando el CONTENIDO y no el codigo de respuesta.

    La version anterior daba por descargado cualquier 200, y eso produce una
    falsa alarma seria en un despliegue bajo subcarpeta: ahi Laravel atiende
    toda ruta desconocida y responde 200 con su propio HTML. El instalador
    gritaba que el .env estaba al alcance de cualquiera cuando lo que devolvia
    era la pagina de la aplicacion.

    Una alarma falsa de seguridad no es inocua: manda a rotar contrasenas y a
    buscar un agujero que no existe. Asi que se busca la huella del archivo de
    verdad -una clave en el .env, la seccion require en composer.json, el
    titulo de un listado de carpeta- y nada mas cuenta.
#>
function Expuesto([string]$url, [string]$huella) {
    try {
        $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 8
    } catch {
        return $false      # 403, 404 o conexion rechazada: no esta expuesto.
    }

    $cuerpo = [string]$r.Content

    <#
        El listado de carpeta se comprueba ANTES del guardia de HTML.

        La pagina que genera Apache al listar un directorio tambien empieza por
        <!DOCTYPE html, asi que el guardia la daba por pagina de la aplicacion
        y dejaba pasar una exposicion de verdad: un listado del proyecto deja
        ver -y descargar- todo lo que hay dentro.
    #>
    if ($cuerpo -match '(?i)<title>\s*Index of') {
        return $true
    }

    # El HTML de la aplicacion nunca es una filtracion, responda lo que responda.
    if ($cuerpo -match '(?i)^\s*<!DOCTYPE html') {
        return $false
    }

    return $cuerpo -match $huella
}

$sospechosos = @(
    @{ ruta = '/.env';                      huella = 'APP_KEY=|DB_PASSWORD=' },
    @{ ruta = '/storage/logs/laravel.log';  huella = 'local\.|production\.|\[\d{4}-\d{2}-\d{2}' },
    @{ ruta = '/composer.json';             huella = '"require"' },
    @{ ruta = "/$carpeta/.env";             huella = 'APP_KEY=|DB_PASSWORD=' },
    @{ ruta = "/$carpeta/composer.json";    huella = '"require"' },
    @{ ruta = "/$carpeta/";                 huella = '(?i)<title>Index of' }
)

$expuestos = @()

foreach ($s in $sospechosos) {
    if (Expuesto "$base$($s.ruta)" $s.huella) {
        $expuestos += $s.ruta
        Malo "$($s.ruta) SE PUEDE DESCARGAR."
    } else {
        Bien "$($s.ruta) no es accesible"
    }
}

if ($expuestos) {
    Write-Host ''
    Write-Host '  ############################################################' -ForegroundColor Red
    Write-Host '   LA CONFIGURACION DE APACHE NO ESTA SIRVIENDO LA CARPETA' -ForegroundColor Red
    Write-Host '   CORRECTA. Con el puerto abierto, esto deja el .env y el' -ForegroundColor Red
    Write-Host '   codigo fuente al alcance de cualquiera.' -ForegroundColor Red
    Write-Host '  ############################################################' -ForegroundColor Red
    Write-Host ''
    Write-Host '   Cierre el puerto en el cortafuegos AHORA y revise que' -ForegroundColor Red
    Write-Host "   httpd.conf incluya conf/extra/httpd-vhosts.conf." -ForegroundColor Red
    Write-Host ''
    Write-Host '   Y cambie las contrasenas de la base: hay que dar por hecho' -ForegroundColor Red
    Write-Host '   que el .env se leyo.' -ForegroundColor Red
    Write-Host ''
}

# ------------------------------------------------------------------- resumen
Write-Host ''
if ($script:Fallos -eq 0) {
    Write-Host '  ============================================================' -ForegroundColor Green
    Write-Host '   INSTALACION COMPLETA' -ForegroundColor Green
    Write-Host '  ============================================================' -ForegroundColor Green
    $publicada = "http://$(if($Dominio){$Dominio}else{'localhost'}):$Puerto" + $RutaBase.TrimEnd('/')
    Write-Host "   Sitio publico : $publicada" -ForegroundColor Green
    Write-Host "   Plataforma    : $publicada/ingreso" -ForegroundColor Green
} else {
    Write-Host "  Terminado con $script:Fallos problema(s). Revise lo marcado como [FALLA]." -ForegroundColor Red
}

# ------------------------------------------------------------------- avisos
Write-Host ''
Write-Host '  PENDIENTE ANTES DE ABRIRLO AL PUBLICO' -ForegroundColor Yellow
Write-Host '  ------------------------------------------------------------' -ForegroundColor Yellow

# El aviso se da si de verdad quedo encendida, no por el nombre del entorno.
if ($depuracion -eq 'true' -and -not $esLocal) {
    Write-Host '   * APP_DEBUG=true sobre una direccion publica. Cualquier error' -ForegroundColor Yellow
    Write-Host '     muestra rutas, consultas SQL con sus valores y fragmentos del' -ForegroundColor Yellow
    Write-Host '     .env a quien lo provoque. Apaguelo en cuanto pueda.' -ForegroundColor Yellow
}

Write-Host '   * SIN HTTPS. Los tokens de la API y las contrasenas viajan en' -ForegroundColor Yellow
Write-Host '     claro: cualquiera en la ruta puede quedarse con uno y entrar.' -ForegroundColor Yellow
Write-Host '     Es lo mas urgente en cuanto haya dominio y certificado.' -ForegroundColor Yellow
Write-Host '   * Correo en modo log: las alertas de SOAT y los avisos de PQRS' -ForegroundColor Yellow
Write-Host '     no salen. Configure MAIL_* cuando tenga servidor SMTP.' -ForegroundColor Yellow
Write-Host '   * Cambie las contrasenas de las cuentas de demostracion.' -ForegroundColor Yellow
Write-Host '   * Cierre el puerto de MariaDB en el cortafuegos: solo 127.0.0.1.' -ForegroundColor Yellow
Write-Host ''
