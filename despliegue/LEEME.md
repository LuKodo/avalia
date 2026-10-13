# Desplegar Avalia Logística en un servidor Windows con XAMPP

## Antes de empezar

En el servidor destino hacen falta, y el instalador los comprueba:

| | Versión mínima |
|---|---|
| XAMPP con Apache y PHP | PHP **8.2** |
| MariaDB o MySQL | 10.6 / 8.0 |
| Node.js | **18** (para compilar el frontend) |
| Composer | cualquiera reciente |

Extensiones de PHP **obligatorias**: `pdo_mysql`, `mbstring`, `openssl`,
`fileinfo`, `curl`, `tokenizer`, `xml`, `json`, `ctype`, `filter`.
Se activan quitando el `;` delante de `extension=...` en `php\php.ini`.

`zip`, `gd` y `opcache` son recomendables pero no bloquean.

## 1. Revisar el servidor sin tocarlo

Copie el proyecto al servidor —por ejemplo a `C:\xampp\htdocs\avalia`— y
desde `despliegue\`:

```powershell
.\instalar.ps1 -RaizXampp "C:\xampp" -Puerto 8082 -Comprobar
```

No pide permisos de administrador y **no modifica nada**. Dice qué falta y se
detiene. Corríjalo y repítalo hasta que salga en verde.

## 2. Instalar

PowerShell **como administrador**:

```powershell
.\instalar.ps1 -RaizXampp "C:\xampp" -Dominio "190.242.42.242" -Puerto 8082 -Entorno pruebas
```

Le pedirá una cuenta de MariaDB con permiso para crear bases (normalmente
`root`). Con ella crea la base y **dos usuarios con contraseñas generadas**:

- `avalia_app` — el que usa la aplicación. Sin permisos de esquema.
- `avalia_admin` — solo para migraciones. La web nunca lo usa.

Esa separación es la que hace que una inyección de SQL no pueda alterar el
esquema ni leer otras bases. Las contraseñas se generan y quedan escritas en
el `.env`; nadie las teclea ni aparecen en pantalla.

Después hace, en orden: `.env`, `composer install`, `npm ci`, `npm run build`,
`APP_KEY`, migraciones, permisos de `storage`, el VirtualHost de Apache
apuntando a `/public`, el servicio de Apache, y las tareas programadas de las
colas. Termina comprobando que responde y que **el `.env` no se puede
descargar**.

## 3. Actualizar una instalación existente

```powershell
.\instalar.ps1 -RaizXampp "C:\xampp" -SoloActualizar
```

No toca la base ni el `.env`. Solo dependencias, compilación, migraciones y
cachés. Es idempotente: correrlo dos veces no rompe nada.

## Opciones

| Parámetro | Para qué |
|---|---|
| `-RaizXampp` | Dónde está XAMPP. Por omisión `C:\xampp` |
| `-Dominio` | Dominio o IP pública. Vacío = `localhost` |
| `-Puerto` | Puerto de Apache. Por omisión `8082` |
| `-Entorno` | `pruebas` (depuración activa) o `produccion` (cachés, sin depuración) |
| `-BaseDatos` | Nombre de la base. Por omisión `avalia` |
| `-PuertoBd` | Puerto de MariaDB. Por omisión `3306` |
| `-SinDatosDemo` | No sembrar datos de ejemplo |
| `-SoloActualizar` | Actualizar sin tocar base ni configuración |
| `-Comprobar` | Solo revisar requisitos. No requiere administrador |

## Lo que queda pendiente después de instalar

El instalador lo recuerda al terminar, pero conviene tenerlo escrito:

**Sin HTTPS, los tokens de la API y las contraseñas viajan en claro.**
Cualquiera en la ruta entre el navegador y el servidor puede quedarse con un
token y entrar como ese usuario. Es lo más urgente en cuanto haya dominio y
certificado. Mientras tanto, conviene no exponerlo más de lo imprescindible.

- **`APP_DEBUG=true`** en entorno de pruebas: cualquier error muestra rutas,
  consultas y fragmentos del `.env` a quien lo provoque. En producción va en
  `false`, y el instalador lo pone así con `-Entorno produccion`.
- **Correo en modo `log`**: las alertas de SOAT y los avisos de PQRS no salen
  hasta configurar `MAIL_*` con un SMTP real.
- **Contraseñas de demostración**: las cuentas sembradas traen una clave
  conocida. Cámbielas antes de abrir al público.
- **Puerto de MariaDB**: que el cortafuegos solo lo admita desde `127.0.0.1`.
  No hay motivo para que la base sea alcanzable desde internet.

## Comprobar que quedó bien

```powershell
# Servicios
Get-Service Apache2.4
schtasks /Query /TN "Avalia-Colas"

# La aplicación
curl http://localhost:8082/sitio
curl http://localhost:8082/api/v1/tablero      # debe decir 401

# Que lo privado NO se descargue -- si alguno responde, la raiz de Apache
# esta mal apuntada y hay que corregirla ANTES de exponer el servidor
curl http://localhost:8082/.env
curl http://localhost:8082/composer.json
```

## Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| Página en blanco | ¿Existe `public/build/manifest.json`? Si no, `npm run build` |
| 404 en todo salvo la portada | Falta `public/.htaccess` o `AllowOverride All` |
| 500 al entrar | `storage\logs\laravel.log` y `C:\xampp\apache\logs\avalia-error.log` |
| Los webhooks no salen | ¿Corre la tarea `Avalia-Colas`? `php artisan queue:failed` |
| «Access denied» de la base | Las credenciales del `.env` contra los usuarios creados |

Apache guarda sus registros de este sitio aparte, en `avalia-error.log` y
`avalia-access.log`, para no mezclarlos con los de otros proyectos del mismo
XAMPP.
