# Inventario de retroalimentaciones · octubre 2026

Las peticiones de los cuatro documentos de verificación, numeradas y con estado.
Existe para que ninguna se pierda y para que el orden del resto sea una decisión
y no una inercia.

**Estados:** ✅ hecho · 🔧 en curso · ⏳ pendiente · ❓ necesita decisión

---

## A · Perfil cliente

| # | Petición | Estado |
|---|---|---|
| A1 | Tope de 5 referencias (SSC) por guía → necesitan más de 40 | ✅ subido a 100, y se pegan en bloque |
| A2 | Diferenciar bolsa / caja / pañales | ✅ |
| A3 | Pañales cuestan más por volumen | ✅ recargo por tipo de empaque, configurable; **falta el número** |
| A4 | Ventana *desde* con fecha y hora actual, editable | ✅ ya lo hacía |
| A5 | Urgencia: cierre automático a 3 h del compromiso | ✅ y corregido el cálculo del compromiso |
| A6 | Al radicar, la pantalla «vuelve a una notificación vieja» | ✅ era el desplazamiento; sube al confirmar |
| A7 | «Radicar otro» deja los campos llenos | ✅ formulario en blanco |
| A8 | Destinos atados a lista fija; falta dirección editable | ✅ domicilios con destinatario y coordenada |
| A9 | Puntos pactados: marcar en el mapa en vez de teclear coordenadas | ✅ mapa arrastrable |
| A10 | Enlace al paciente para que envíe su ubicación | ❌ descartado por el cliente: el contrato es con el cliente, no con el usuario final |
| A11 | «Remisión (traslados)»: ¿cuál se usa para traslados entre sedes? | ✅ respondido: **Remisión** |
| A12 | Tarifa distinta para destinos rurales (población), avisando al escogerlo | ✅ marca en la sede/domicilio, aviso al escoger; **falta el número** |
| A13 | Radicación masiva por Excel | ✅ *Radicación masiva*: plantilla .xlsx, revisión fila a fila y radicado en bloque |
| A14 | El cliente puede eliminar o cancelar una guía creada por error | ✅ **cancela, no borra**, y solo antes de que la caja salga |
| A15 | Perfil restringido: solo genera guías, sin eliminar | ✅ rol `cliente_radicador` |

---

## B · Perfil operador logístico

| # | Petición | Estado |
|---|---|---|
| B1 | «Revisar» debe abrir un **diálogo** con todo, no desplazar la página | ✅ |
| B2 | Exportar CSV también **después** de asignar | ✅ panel *En ruta* con su exportación |
| B3 | Identificar medicamentos de **alto costo** en el listado de despacho | ✅ marca al radicar y distintivo morado |
| B4 | Reasignar una guía a otro mensajero, con motivo | ✅ |
| B5 | Navegar al sitio de entrega propuesto para verlo en el mapa | ✅ |

---

## C · Perfil mensajero

| # | Petición | Estado |
|---|---|---|
| C1 | No indica la fecha en que se asignó la guía | ✅ «Asignada: hoy 09:14 / hace 3 días» |
| C2 | ¿Cuál es el criterio para apilar las tareas? | ✅ respondido; hoy es por estado y conviene cambiarlo |
| C3 | Ruta del día automática por puntos de navegación | ✅ por compromiso y, dentro de cada franja, por cercanía |
| C4 | Reordenar tareas a mano, arrastrando | ✅ con flechas, no arrastrando: se usa de pie y con guantes |
| C5 | Tablero por estados: urgentes, en tránsito, no recibidas, entregadas | ✅ pestañas con conteo en *Mis tareas* |
| C6 | Ver lo gestionado en el día | ✅ en *Mi jornada* |
| C7 | **Recogida rápida**: escaneo continuo de QR para cargar 50 bolsas | ✅ cámara continua, lista y confirmación en bloque |
| C8 | Contraste de la firma digital | ✅ trazo más grueso y fondo blanco al exportar |
| C9 | Una orden con 3 SSC registra solo uno | ✅ referencias por caja |
| C10 | Las SSC se piden una por una; debe ser un solo recibido por guía | ✅ una caja, un recibido |
| C11 | No permite registrar novedad durante la entrega: se reinicia | ✅ diálogo de novedad sin perder lo capturado |
| C12 | Novedades de tránsito y devolución parcial o total | ✅ **la unidad es la caja**: cada caja se devuelve o se entrega por separado |
| C13 | Datos de quien recibe, automáticos y editables | ✅ se propone el contacto del destino, editable y señalado |

---

## D · Perfil administrador

| # | Petición | Estado |
|---|---|---|
| D1 | Informe diario por mensajero, con semanas y meses | ✅ *Informes · Por mensajero*, con día/semana/mes |
| D2 | Promedio de entregas por mensajero | ✅ promedio por día activo |
| D3 | Tiempo entre cada gestión | ✅ media entre eventos de la bitácora |
| D4 | Parametrizar franjas horarias (entrada → mediodía) | ✅ cortes configurables; el mensajero marca entrada y salida |
| D5 | El mensajero registra su almuerzo | ✅ |
| D6 | Novedades externas: lluvia, avería, con pausa y reanudación | ✅ |
| D7 | Informe de estado de guías por mensajero | ✅ matriz mensajero × estado |
| D8 | Informe consolidado agrupado por mensajero y por cliente | ✅ |
| D9 | Urgentes como observación en el informe, con el tiempo que costaron | ✅ detalle una por una, total y tránsito |
| D10 | Alerta de guías con más de 3 días sin entregar, y en custodia de quién | ✅ en pantalla y por correo a Avalia y al cliente |
| D11 | Informe diario de guías generadas por cliente | ✅ |
| D12 | Tablero por cliente, además de la visión general | ✅ pestaña *Por cliente* |
| D13 | Seleccionar y comparar varios clientes a la vez | ✅ fichas de selección múltiple |
| D14 | Revertir una entrega confirmada por error, borrando la evidencia mala | ❓ **ver la advertencia de abajo** |
| D15 | Revertir sin borrar evidencias, para añadir actas faltantes | ✅ *Rastreo · Reabrir entrega*, con motivo obligatorio |
| D16 | No deja editar la asignación de vehículo; poder asignarlo después | ✅ *Flota · Mensajeros · Editar* |

---

## Una advertencia sobre D14

Borrar una evidencia y revertir una entrega entra en conflicto directo con lo
que hace valiosa a esta plataforma: la bitácora es **de solo anexado**, con cada
evento encadenado al anterior por una firma, y hay disparadores en la base que
impiden modificarla o borrarla. Eso es lo que permite decirle a un auditor que
el historial no se tocó.

Si se añade un botón que borra evidencias y retrocede estados, esa afirmación
deja de ser cierta — y no solo para el caso en que se usó.

**La forma correcta de dar lo mismo sin perder eso** es no borrar nunca, sino
**anular dejando rastro**: la evidencia equivocada se marca como anulada —con
quién, cuándo y por qué—, deja de mostrarse en el soporte, y la entrega se
reabre con un evento nuevo que dice que se reabrió. El resultado para quien
trabaja es idéntico; la diferencia es que en marzo se puede explicar qué pasó.

Lo mismo vale para A14: una guía cancelada debería quedar **cancelada**, no
desaparecer.

**Ya está construido así** (ver D15): *Rastreo · Reabrir entrega*. Operación
reabre la entrega escribiendo el motivo, la evidencia anterior queda marcada
como reemplazada —no se borra—, el paquete vuelve a «en sitio» para que el
mensajero complete la captura, y la reapertura aparece en el soporte.

Lo que queda por decidir en D14 es solo si además hace falta un borrado real.
Mi recomendación sigue siendo que no, por lo de arriba.

---

## Decisiones pendientes, en una lista

1. **A3** — ¿cuánto más cuestan los pañales? Fijo, porcentaje o tarifa propia.
2. **A12** — ya se puede marcar cada sede o domicilio como rural y el cliente lo ve al escoger. **Falta el número**: `tarifa.recargo_rural` está en cero, así que hoy no cobra nada. Dígame el porcentaje.
3. ~~**A14** — ¿desde qué estado puede el cliente cancelar una guía?~~ Hecho con este criterio: **solo mientras ninguna caja haya salido** (estado «radicado»). En cuanto hay mensajero asignado, la caja está en la operación de otra persona y la cancelación la resuelve Avalia. Dígame si quiere otro corte.
4. ~~**C3 / C4** — ¿ruta automática, reordenar a mano, o las dos?~~ Hechas las dos:
   el botón *Usar ruta sugerida* ordena por compromiso y, dentro de cada franja, por
   cercanía; después el mensajero la retoca con las flechas.
5. ~~**C12** — devolución parcial: ¿por caja o por dispensación?~~ Resuelto con lo que usted dijo: **la caja**. Una caja lleva N SSC y se recibe una sola vez; si esa caja se devuelve, se devuelven sus SSC, y las demás cajas de la guía se entregan igual.
6. ~~**D4** — ¿qué franjas, y de dónde sale la hora de entrada?~~ La hora de entrada
   **no** es la de radicación de la guía: es la del mensajero, y ahora la marca él en
   *Mi jornada*. Las franjas se configuran en `jornada.cortes_franja`, hoy
   «12:00,14:00».
7. ~~**D10** — alerta de 3 días: ¿a quién, y por correo o en pantalla?~~ Las dos: en
   *Despacho* y por correo de lunes a viernes a las 07:00, a Avalia con la custodia y
   a cada cliente solo lo suyo. **Falta llenar** `alertas.correo_operacion` con la
   dirección de Avalia.
8. **D14** — ¿anular dejando rastro, como recomiendo, o borrar de verdad?
