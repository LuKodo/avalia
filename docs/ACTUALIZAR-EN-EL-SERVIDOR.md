# Actualizar Avalia en el servidor

Esta guía es para **actualizar** una instalación que ya funciona, no para
instalar de cero. Son cosas distintas y mezclarlas hace daño.

> **No use `2-INSTALAR.bat` para actualizar.** Ese guion reescribe el `.env`, y
> dentro del `.env` está el `APP_URL` — que es exactamente lo que va impreso
> dentro de cada código QR de cada etiqueta ya pegada en una caja. Si se
> reescribe mal, los QR impresos dejan de abrir. Además pregunta por rehacer el
> esquema, y rehacer el esquema borra la operación.

---

## Antes de empezar

Tenga a mano:

- El `.zip` nuevo que le pasé.
- Acceso de escritorio al servidor **190.242.42.242**.
- Diez minutos sin despachos en curso. No es obligatorio, pero si alguien está
  registrando una entrega justo cuando se copian los archivos, esa petición
  puede fallar y tendrá que repetirla.

La plataforma vive en `C:\xampp\htdocs\Avalia` y se sirve en
`https://plataforma.avalia.com.co`.

---

## 1 · Descomprimir APARTE

**Nunca encima de la instalación.** Descomprimir encima borró el `artisan` una
vez y dejó la plataforma sin arrancar.

Descomprima en una carpeta nueva y vacía, por ejemplo `C:\avalia-nuevo`.

Desde el Explorador: clic derecho sobre el `.zip` → *Extraer todo…* → escriba
`C:\avalia-nuevo`.

> El servidor tiene PowerShell 4 y **no existe `Expand-Archive`**. Si prefiere
> la consola:
>
> ```powershell
> Add-Type -A System.IO.Compression.FileSystem
> [IO.Compression.ZipFile]::ExtractToDirectory('C:\ruta\al\paquete.zip','C:\avalia-nuevo')
> ```

---

## 2 · Copiar solo el código

Abra PowerShell **como administrador** y ejecute, tal cual:

```powershell
robocopy C:\avalia-nuevo C:\xampp\htdocs\Avalia /E /XD storage vendor node_modules /XF .env
```

Qué hace cada parte, para que no quede como una fórmula mágica:

| Parte | Por qué está |
|---|---|
| `/E` | Copia también las subcarpetas. |
| `/XD storage` | **No toca `storage`**: ahí viven las evidencias, las firmas y las fotos de entrega. |
| `/XD vendor node_modules` | El paquete no los trae; los que hay en el servidor siguen sirviendo. |
| `/XF .env` | **No toca el `.env`**: ahí está `APP_URL`, la clave de la base y `DB_MOTOR_ANTIGUO`. |

`robocopy` termina con un código distinto de cero aunque haya ido bien. Lo que
hay que mirar es la tabla del final: si dice archivos copiados y ningún
*FAILED*, fue bien.

---

## 3 · Migraciones (esta vez hay varias)

Esta actualización **sí trae cambios de base**. Ejecute:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Avalia\artisan migrate --force --database=mysql_admin
```

**El `--database=mysql_admin` no es opcional.** La cuenta con la que corre la
aplicación (`avalia_app`) no puede alterar tablas, a propósito. Sin ese
parámetro verá *1142 ALTER command denied* y no es un error: es el diseño.

Son estas diez. Verá `DONE` en las que falten por correr en su servidor; las
que ya corrieron en una actualización anterior no se repiten ni se listan, y
eso es correcto:

```
2026_10_21_100000_paquetes_de_alto_costo ......................... DONE
2026_10_21_120000_reabrir_entregas_sin_borrar .................... DONE
2026_10_21_130000_rol_cliente_radicador .......................... DONE
2026_10_21_140000_destinos_rurales ............................... DONE
2026_10_21_141000_parametro_recargo_rural ........................ DONE
2026_10_21_150000_parametros_de_alerta ........................... DONE
2026_10_21_160000_franjas_horarias ............................... DONE
2026_10_21_170000_anio_de_inicio_de_operacion .................... DONE
2026_10_22_100000_cierre_automatico_de_pausas .................... DONE
```

Lo que importa es que **termine sin ningún error en rojo**. Para ver cómo quedó
todo:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Avalia\artisan migrate:status
```

No debe quedar ninguna en `Pending`.

### Si falla con «Unknown column 'generation_expression'»

Falta `DB_MOTOR_ANTIGUO=true` en el `.env`. Compruébelo:

```powershell
findstr /i "DB_MOTOR_ANTIGUO" C:\xampp\htdocs\Avalia\.env
```

Si no aparece, añada la línea al final del `.env` y repita la migración. Esa
línea es la que activa la capa de compatibilidad con MySQL 5.5; sin ella la
plataforma arranca igual —por eso la falta no se nota— pero las migraciones
mueren.

---

## 4 · Limpiar la caché

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Avalia\artisan optimize:clear
```

Sin esto, Laravel sigue sirviendo la configuración y las rutas viejas, y las
pantallas nuevas dan 404 aunque los archivos estén.

---

## 5 · Comprobar, en este orden

Entre a `https://plataforma.avalia.com.co` y verifique:

1. **Administrador → Informes** (menú nuevo, sigla `IN`). Debe cargar con
   cifras y el gráfico. Si da 404, falta el paso 4.
2. **Despacho**. Arriba debe aparecer *«Más de N días sin cerrarse»* con la
   columna **En custodia de**. Pulse **Revisar** en cualquier fila: tiene que
   abrirse un **diálogo** encima de la tabla, no desplazar la página.
3. **Administración → Flota → Mensajeros**. Cada fila tiene ahora **Editar**:
   ábralo y confirme que puede cambiar el vehículo.
4. **Perfil de cliente → Radicación masiva** (sigla `RM`). Descargue la
   plantilla: debe bajar un `.xlsx` con dos hojas.
5. **El sitio público** (`/sitio`). Las cifras de la portada ya no son
   marcadores: salen de la base. Debe ver el porcentaje de entregas dentro de
   ventana y el volumen de cadena de frío de los últimos doce meses.
6. **Perfil de mensajero → Mis tareas**. Deben verse las pestañas
   *Todas · Urgentes · En tránsito · No recibidas · Entregadas*, el botón
   **✦ Usar ruta sugerida** y, si tiene recogidas pendientes,
   **⚡ Recogida rápida**.
7. **Perfil de mensajero → Mi jornada**. Marque una pausa y vaya a
   *Mis tareas*: arriba debe salir la franja «En pausa» y los botones de cada
   tarea deben decir **Reanudar y continuar**. No se puede entregar sin cerrar
   la pausa.
8. **Marcar entrada y salida**, en esa misma pantalla. Arriba debe aparecer **Marcar entrada**
   o **Marcar salida**. Pruébelo: la hora que queda ahí es de donde salen las
   franjas horarias del informe.

Si algo de esto no aparece, es casi siempre la caché del navegador del equipo
desde el que mira, no el servidor: pruebe con **Ctrl + F5**.

---

## 6 · Dos líneas del `.env` (el correo cambió)

El `.env` no se toca al copiar —por eso `robocopy` lo excluye—, así que estas
dos siguen con la dirección vieja. Ábralo con el Bloc de notas y corríjalas:

```
MAIL_FROM_ADDRESS="no-responder@avalia.com.co"
LTP_CORREO_AVISOS=info@avalia.com.co
```

La segunda es a donde llegan el formulario de cotización y las PQRS del sitio
público. Si queda con el correo viejo, esos mensajes se pierden sin que nadie
se entere. Después del cambio, vuelva a ejecutar el paso 4.

---

## 7 · Parámetros que hay que llenar

Entre en **Administración → Parámetros**. Tres nacen en blanco o en cero a
propósito, porque poner un número inventado sería peor que no poner ninguno:

| Parámetro | Qué pasa si se deja como está |
|---|---|
| `alertas.correo_operacion` | **Llénelo.** Sin él, el aviso diario de guías paradas no le llega a Avalia; al cliente sí. Admite varias direcciones separadas por coma. |
| `tarifa.recargo_empaque.panales` | En cero: los pañales cuestan lo mismo que una caja. |
| `tarifa.recargo_rural` | En cero: un destino rural cuesta lo mismo que uno urbano. |
| `sitio.operacion_desde` | En cero: la portada **no publica** la antigüedad de Avalia. Ponga el año en que empezaron a transportar producto farmacéutico y aparece sola. |

Los dos recargos son fracciones: `0.25` es 25 %. Antes de fijarlos, revíselos
con el área comercial y financiera —cambian lo que se factura—. En el PDF de
respuestas que acompaña a esta entrega está mi recomendación para cada uno y el
razonamiento detrás.

También puede ajustar, sin que haga falta tocarlos:

- `alertas.dias_sin_cerrar` — hoy 3 días.
- `jornada.cortes_franja` — hoy `12:00,14:00`, que da tres franjas.

---

## Si una pantalla dice «Server Error»

En producción `APP_DEBUG` está en false, y debe seguir así: con true, la
pantalla de error de Laravel muestra la configuración entera, clave de la base
incluida. El precio es que el navegador solo dice «Server Error».

Para ver el error de verdad, **sin tocar la configuración**, hay un comando que
repite la misma petición dentro del servidor, como el mismo usuario:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Avalia\artisan avalia:diagnosticar ordenes/catalogo --usuario=correo@delcliente.com
```

Imprime el código de respuesta y, si falla, la excepción completa con el
archivo y la línea. El `--usuario` importa: casi todos estos fallos dependen de
los datos de **ese** cliente, y con otro usuario la misma pantalla funciona.

La ruta se escribe sin `api/v1/`. Algunas útiles:

| Pantalla | Ruta |
|---|---|
| Radicar envío | `ordenes/catalogo` |
| Mis solicitudes | `ordenes` |
| Despacho | `despacho/bandeja` |
| Mis tareas (mensajero) | `campo/tareas` |
| Informes | `admin/informes` |

---

## Cambiar el correo de un cliente

Dos correos distintos que se confunden con facilidad: el de **acceso** (con el
que esa persona entra) y el de **avisos** (el de la ficha del cliente, donde
llegan cambios de estado, novedades y la alerta de envíos parados). Este
comando cambia los dos:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Avalia\artisan avalia:cambiar-correo --cliente="Pharmaser" --a=operacion@pharmaser.com.co
```

Pide confirmación antes de tocar nada y, al cambiar el acceso, **revoca las
sesiones abiertas**: si el cambio es porque esa persona ya no está, el acceso se
corta hoy y no cuando caduque el testigo. La contraseña no cambia.

`--solo-avisos` deja el acceso intacto, para cuando lo único que cambia es a
dónde se notifica. Si el cliente tiene varios usuarios, hay que decir cuál con
`--de=`; el comando los lista.

---

## Si algo sale mal

Los errores están en el registro **del día**, no en el `laravel.log` suelto —ese
está viejo y despista:

```powershell
type C:\xampp\htdocs\Avalia\storage\logs\laravel-2026-10-21.log
```

Para saber si una petición llegó siquiera al servidor y con qué código:

```powershell
findstr /i "informes" C:\xampp\apache\logs\avalia-ssl-access.log
```

### Volver atrás

La carpeta anterior no se borra en ningún paso de esta guía: si guardó una copia
antes de copiar, se restaura con el mismo `robocopy` al revés. Las migraciones
de esta entrega **solo añaden** columnas, índices y filas —ninguna borra datos—,
así que el código viejo sigue funcionando sobre la base nueva.

---

## Qué trae esta actualización

**48 de las 49 peticiones** de los cuatro documentos de verificación. La que
falta —el enlace para que el paciente envíe su ubicación— la descartó el cliente.

- El detalle, una por una y con su estado, está en
  [`inventario-retroalimentaciones.md`](inventario-retroalimentaciones.md).
- Las respuestas a las preguntas que ustedes hicieron, y mis recomendaciones
  para los dos números que faltan, están en
  **`Avalia - Respuestas a la verificacion - 21 oct 2026.pdf`**, en esta misma
  carpeta.
