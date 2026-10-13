# Demostración en video, generada sola

Graba la demostración de Avalia manejando el navegador: entra con cada perfil,
recorre las pantallas y produce un MP4 a doble velocidad con rótulos.

## Una vez

    cd herramientas/video
    npm init -y
    npm install ffmpeg-static

`ffmpeg-static` descarga un ffmpeg de ~80 MB. Si npm bloquea su script de
instalación, ejecute `npm approve-scripts ffmpeg-static`.

## Cada vez que quiera grabar

    php artisan avalia:datos-demo --forzar     # datos con fechas de hoy
    node herramientas/video/demo.mjs

Sale en `storage/app/video/avalia-demo.mp4`.

**El primer paso no es opcional.** Los datos sembrados envejecen: a la semana el
tablero muestra un hueco al final de sus series y el mapa de flota pinta a todos
en gris «sin señal». En cámara eso se ve como una avería aunque no lo sea.

## Qué tocar

- `demo.mjs`, constante `FACTOR`: 2 es el doble de rápido. Las pausas se piden
  en segundos **del video final** y el guion las multiplica solo.
- `GUIA_EN_REGLA` y `GUIA_DESVIADA`: las dos guías que salen en cámara. Cámbielas
  tras cada resiembra, porque los números se regeneran. Para encontrar una con
  desvío, mire la salida de `avalia:simular-recorridos`.
- Las llamadas a `rotulo(...)` son el texto que aparece abajo. No hay voz: si
  quiere locución, hay que ponerla después en un editor.

## Límites

- Sin audio. El rótulo hace de narración.
- Corre contra `http://127.0.0.1:8000`, así que Apache y MariaDB tienen que
  estar arriba.
