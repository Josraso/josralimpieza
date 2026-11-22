# Cambios

## 1.0.0 — 2026-11-22

Primera versión.

- **Diagnóstico**: peso de cada tabla, espacio desperdiciado, peso de las imágenes de producto y de la cuarentena, y copias guardadas.
- **Base de datos**: limpieza por apartados, con la antigüedad que se quiera, de registros y estadísticas, caché de la base de datos,
  visitantes e invitados, carritos abandonados que nunca fueron pedido, filas huérfanas e índice del buscador. Antes de borrar se puede
  contar cuántas filas hay en cada apartado y ver las primeras de verdad.
- **Imágenes**: escáner de `img/p` por carpetas que encuentra las imágenes de producto que la tienda ya no usa (archivos sin registro,
  imágenes de productos borrados, miniaturas de tamaños que ya no existen e imágenes de la base de datos sin archivo). Los archivos no se
  borran: se mueven a una cuarentena desde la que se devuelven con un clic.
- **Optimizar**: recupera el espacio desperdiciado tabla por tabla, con aviso de que la tabla queda bloqueada mientras se optimiza.
- **Copias**: cada limpieza guarda antes lo que borra (filas en `.sql.gz`, imágenes en la cuarentena), se puede restaurar y se borra sola
  pasados los días de retención.
- **Automático**: URL de cron con clave propia que limpia solo los apartados de riesgo cero, guarda copia previa, borra las copias caducadas
  y manda un informe por correo. Nunca toca imágenes, carritos, clientes ni optimiza tablas.
- Todo por lotes AJAX, con pausa y reanudación, contador en vivo y resumen final.
- Compatible de PrestaShop 1.6 a 9.2, sin overrides y sin pestañas en el menú lateral: la configuración está en el propio módulo.
- Avisos de versión nueva y licencia desde el servidor de actualizaciones de josra.
