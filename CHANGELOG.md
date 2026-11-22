# Cambios

## 1.0.2 — 2026-11-22

- **Arreglado: los botones Todo / Solo lo seguro / Nada no hacían nada después de actualizar.** El navegador se quedaba con el
  JavaScript de la versión anterior, que todavía no tenía esos botones. Ahora el CSS y el JavaScript del módulo llevan la versión en
  la dirección, así que al actualizar el navegador coge siempre los nuevos.
- La versión del módulo se ve al lado de las pestañas, para saber de un vistazo qué versión está cargada.

## 1.0.1 — 2026-11-22

- **Mucho más rápida la limpieza de la base de datos.** Antes cada llamada al servidor borraba un solo lote de 500 filas, así que en
  tablas de millones de filas casi todo el tiempo se iba en ir y volver. Ahora cada llamada encadena lotes hasta agotar el tiempo que
  da el servidor, el lote por defecto pasa de 500 a 2000 filas y, cuando no hace falta copia previa, el borrado va directo a la tabla
  sin leer antes las filas.
- **Nueva opción «Guardar copia previa de lo que se borre»**, marcada por defecto. Al quitarla, la limpieza es muchísimo más rápida
  (es lo que más tarda en las tablas enormes) pero lo borrado no se puede recuperar desde el módulo. Para registros y estadísticas no
  suele hacer falta.
- **Botones para marcar y desmarcar**: Todo, Solo lo seguro y Nada, y una casilla en la cabecera de cada grupo para marcar o desmarcar
  el grupo entero. Antes había que ir apartado por apartado.
- La barra de progreso de la limpieza va por filas borradas sobre el total contado, no por apartados, y dice cuánto lleva.
- Retirar imágenes a la cuarentena y borrar los registros sin archivo también encadenan lotes por tiempo, igual que la base de datos.
- Si un apartado falla a mitad, las filas que ya se habían borrado se apuntan igual en el historial.

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
