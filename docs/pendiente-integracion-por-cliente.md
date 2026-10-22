# Plan · Integración por cliente

> **Implementado el 3 de octubre de 2026**, salvo la fase 0 —Redis—, que es
> infraestructura del servidor. El job funciona con el driver de cola que haya
> configurado; pasar a Redis será cambiar una línea del `.env`.
>
> Queda pendiente: montar Redis si se decide que compensa, capturar la
> credencial desde Administración → Clientes, y trasladar al cliente el
> hallazgo de seguridad de la sección 7.

---

## 1 · Objetivo

Que cada cliente pueda tener, o no, una integración con sus sistemas. Si la
tiene, Avalia le hace llegar automáticamente la foto del soporte en cuanto la
entrega se cierra.

El interruptor no es cosmético: decide si el sistema habla con un tercero cada
vez que se cierra una entrega. Un cliente sin integración no debe pagar el coste
—ni el riesgo— de una llamada que no va a ninguna parte.

---

## 2 · Punto de partida

**Lo que ya existe y se reutiliza**

- `DespachadorWebhooks` y `EntregarWebhook`: el patrón de job con reintentos ya
  está resuelto en este código, y conviene seguirlo en vez de inventar otro.
- `RegistradorEventos`: la bitácora por paquete, donde quedará constancia de
  cada intento de envío.
- Tabla `evidencias`: ya guarda la foto, su tipo, su checksum y su ruta.

**Lo que no existe**

- Cualquier noción de «integración» en el modelo de cliente.
- Redis: hoy `QUEUE_CONNECTION=database`, sin servidor Redis ni extensión de PHP.
- Columnas cifradas: sería el primer caso del sistema.

---

## 3 · La API del cliente

`https://cassandra.pharmaser.com.co/openapi.json` — Go + Fiber sobre buckets de
Oracle Cloud Infrastructure.

**Se usa una sola operación: la subida.** El resto de la API —listar, buscar,
descargar, ver, borrar— queda fuera del alcance.

```http
POST /buckets/bucket_cpscan/files/upload
Authorization: Bearer <token>
Content-Type: multipart/form-data

file=@6564684.jpg
```

| | |
|---|---|
| Cuerpo | `multipart/form-data`, un único campo llamado `file` |
| Éxito | **201**, con un objeto de pares cadena→cadena |
| Errores | 400 formato rechazado · 500 fallo del servidor |
| Credencial | cabecera `Authorization`, esquema `BearerAuth` |

**No hay identificador de archivo.** En esta API los archivos se direccionan por
nombre, no por un id que devuelva el servidor. El nombre que enviemos *es* la
llave, y es lo que se guarda de vuelta como acuse.

---

## 4 · Decisiones tomadas

**Qué se envía** — solo la foto del soporte de entrega. Ni la firma, ni
metadatos.

**Cómo se llama** — la referencia del cliente sin su prefijo:

```
Referencia radicada:   SSC6564684
Archivo que se sube:   6564684.jpg
```

El prefijo a recortar se **configura por cliente**. Es la convención de éste; el
siguiente usará otra o ninguna. Recorte sin distinguir mayúsculas, y sin tocar
lo que no empiece por el prefijo.

**Dónde vive la configuración** — con el cliente, en la base de datos. El `.env`
sirve para una integración; aquí hay tantas como clientes, cada una con su
bucket y su credencial.

**Cuándo se dispara** — al cerrarse la entrega, no al capturar la foto. La
evidencia ya viaja por su propia cola desde el dispositivo; esto es un paso
posterior, cuando el soporte está completo y verificado.

**Cómo** — job en cola **Redis**, con reintentos automáticos. Nunca dentro de la
petición que cierra la entrega: si el sistema del cliente está caído, el
mensajero no puede quedarse esperando en la puerta de una farmacia.

---

## 5 · Lo que falta decidir

| # | Pregunta | Bloquea |
|---|---|---|
| 1 | **Envíos de varias cajas**: el nombre limpio no puede servir para dos archivos. ¿Sufijo de paquete, rechazo, o sobrescritura a sabiendas? | Fase 2 |
| 2 | **¿`Authorization: Bearer <token>` o el token a secas?** La especificación no fija el prefijo. Se resuelve en la primera llamada. | Fase 2 |
| 3 | **¿Qué devuelve el 201?** El esquema solo dice «pares cadena→cadena». Hace falta una respuesta real para saber qué guardar. | Fase 2 |
| 4 | **¿Hay entorno de pruebas?** Subir fotos de prueba a un bucket de producción ensucia datos ajenos, y borrarlas exige credencial de borrado. | Fase 5 |
| 5 | **¿Redis de verdad?** Ver sección 8. | Fase 0 |

---

## 6 · Plan por fases

### Fase 0 · Infraestructura

Sin esto no hay cola Redis que usar.

- Servidor Redis en Windows Server — **Memurai** (compatible, instalador y
  servicio de Windows) o Redis en WSL2.
- Acceso desde PHP — `phpredis` compilada para PHP 8.2 en Windows, o
  `predis/predis` por Composer, que no necesita extensión.
- Proceso trabajador permanente (`queue:work`) como servicio de Windows. Hoy las
  colas se vacían con una tarea cada minuto, lo que daría hasta 60 segundos de
  retraso.
- Confirmar las preguntas 2 y 3 con una llamada de prueba.

**Hecho cuando**: una llamada de prueba sube un archivo y devuelve 201, y un job
encolado en Redis se ejecuta en segundos.

### Fase 1 · Modelo de datos

Una migración, dos tablas tocadas.

**`clientes`** — la integración:

| Columna | |
|---|---|
| `integracion_activa` | booleano, por omisión falso |
| `integracion_tipo` | cadena corta; hoy solo `cassandra`, pero habrá más |
| `integracion_config` | JSON: `api_url`, `bucket`, `prefijo_referencia` |
| `integracion_token` | **cifrado en columna** |

**`evidencias`** — el acuse, por evidencia y no por paquete, porque una entrega
lleva foto y firma y puede llegar una y fallar la otra:

| Columna | |
|---|---|
| `entregada_al_cliente_en` | cuándo acusó recibo. Nulo mientras no lo haya hecho |
| `referencia_externa` | el nombre con el que quedó en el bucket |
| `intentos_envio` | para diagnosticar sin abrir registros del servidor |
| `ultimo_error_envio` | idem |

**Hecho cuando**: la migración corre sobre MySQL 5.5 y el token se guarda y se
recupera cifrado.

### Fase 2 · El cliente HTTP y el job

- `App\Services\Integraciones\Cassandra` — una clase con un método: subir un
  archivo a un bucket. Nada más. Que sepa de HTTP, no de entregas.
- `App\Jobs\EnviarSoporteACliente` — resuelve el nombre, llama al servicio,
  marca el acuse, registra el intento en la bitácora del paquete.

**Reintentos**: 1 min, 5, 15, 1 h, 4 h. Se reintenta lo que puede mejorar con el
tiempo —sin red, 5xx, 429— y no lo que no: un 401 por credencial inválida o un
400 por formato rechazado se detienen y piden intervención. Misma distinción que
ya aplica la cola del dispositivo móvil, y por las mismas razones.

**La marca se pone con la respuesta del cliente en la mano**, nunca al encolar.
Un trabajo encolado no es un trabajo hecho, y confundirlo es exactamente cómo se
construye un panel que dice que todo salió bien mientras nada salió.

**Hecho cuando**: un job manual sube una foto real y la evidencia queda marcada
con su nombre externo.

### Fase 3 · El disparo

En `CampoController@entregar`, tras cerrar la entrega: si el cliente tiene
integración activa, encolar el envío de la foto del receptor.

Encolar, nunca ejecutar. Y envuelto de forma que un fallo al encolar no tumbe la
entrega — igual que ya se hace con el aviso por correo.

**Hecho cuando**: cerrar una entrega de un cliente con integración deja el job
en la cola, y cerrar una de un cliente sin ella no hace nada.

### Fase 4 · Pantallas

- **Administración → Clientes**: activar la integración y capturar sus datos. El
  token se muestra como «configurado», nunca en claro, y se puede reemplazar.
- **Operación**: lo que falló. Agotados los reintentos, el job cae en
  `failed_jobs` y nadie mira `failed_jobs`. Una integración que falla en
  silencio es peor que no tenerla.
- **Soporte de entrega**: una marca discreta de que el soporte llegó al cliente,
  con su fecha.

**Hecho cuando**: un operador puede ver qué envíos fallaron y reintentarlos sin
tocar la consola.

### Fase 5 · Cliente de prueba y verificación

Crear el cliente y la sede:

| Campo | Valor |
|---|---|
| Razón social | Distribuciones Pharmaser LTDA |
| NIT | 806011811 |
| Encargado | Carlos Baldovino |
| Teléfono | 3006543210 |
| Ciudad | Cartagena |
| Sede | Bodega Bloc Port |
| Coordenadas | `10.3761883`, `-75.5037653` |

Las coordenadas salen del par `!3d!4d` del enlace de Google Maps, que marca el
**lugar**. El par que sigue a la `@` es el centro del encuadre del mapa: usarlo
dejaría la sede unos 280 m al oeste de la puerta, y con un radio de validación
de 150 m toda entrega hecha allí saldría marcada como incoherente.

Recorrido completo: radicar con referencia `SSC…` → asignar → recolectar →
entregar con foto → comprobar que el archivo aparece en el bucket con el nombre
recortado y que la evidencia quedó marcada.

---

## 7 · Seguridad

**El token llegó por el chat.** Mientras siga siendo válido, cualquiera con
acceso a esa conversación tiene acceso al bucket. Conviene **rotarlo** con el
cliente antes de producción.

**En el sistema**: cifrado en columna, nunca en el repositorio, ni en registros,
ni en respuestas de la API, ni en pantalla una vez guardado.

**Y un hallazgo que conviene trasladarles.** No afecta a lo que construimos
—solo subimos— pero sí al dato que sale de aquí.

Según su especificación, **descargar, ver, listar y buscar no exigen
credencial**; solo subir y borrar la piden. Si es literal, cualquiera en
internet puede listar ese bucket y bajarse lo que haya. Y lo que vamos a poner
allí son fotos de entrega **con la cara de una persona**, nombradas con un
número corto y secuencial: probar `6564683` lleva a la foto de otra entrega.

El dato es de pacientes y de personal de farmacia. Antes de activarlo:

- Confirmar con el cliente si el bucket es realmente público o hay algo delante
  que la especificación no refleja.
- Que lo revise el área de seguridad o cumplimiento, por tratarse de datos
  personales saliendo hacia un tercero.

*No se pudo comprobar en vivo: la llamada desde la máquina de desarrollo no
llegó a responder. Lo anterior sale de la especificación publicada.*

---

## 8 · Riesgos y una reserva

**Colisión de nombres** — pregunta 1 de la sección 5. Es el riesgo con más
probabilidad de morder: un envío de dos cajas y un soporte pisa al otro, sin
error visible.

**Dependencia de un tercero** — si su API cambia o cae, las entregas siguen
funcionando: el soporte vive en Avalia y eso es lo que vale como prueba. La
integración es una conveniencia para el cliente, no parte de la cadena de
custodia. Conviene que el diseño lo refleje y que nadie acabe tratándola como
obligatoria.

**Sobre Redis** — queda anotado como se pide, pero merece una decisión
consciente. Son tres servicios nuevos que vigilar, respaldar y reiniciar en un
servidor que ya aloja unos cuarenta proyectos, para un volumen de unas decenas
de soportes al día. La cola en base de datos que ya funciona daría el mismo
resultado. Redis se paga solo cuando hay mucho volumen o hace falta latencia
baja, y hoy no aplica ninguna de las dos. Si se quiere por volumen previsto o
por política, se monta sin problema.

---

## 9 · Para retomar

> Implementa el plan de `docs/pendiente-integracion-por-cliente.md`: integración
> por cliente, envío de la foto del soporte al bucket del cliente con el nombre
> de la referencia sin prefijo, encolado en Redis con reintentos y marca de
> acuse por evidencia. Crea el cliente y la sede de prueba de la fase 5.

**Antes de empezar**: fase 0 completa, y decidida la pregunta 1 de la sección 5.
