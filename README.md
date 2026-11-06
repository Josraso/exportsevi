# Export Sevi

Módulo de PrestaShop (1.6 a 9) que exporta las referencias y el stock de los productos a un CSV en una carpeta del servidor, a mano o por cron.

## Archivos

```
exportsevi/
├── exportsevi.php   módulo (configuración y exportación)
├── export.php       URL para el cron
├── upgrade/         scripts de actualización
├── config.xml
├── logo.png
└── index.php
```

## Configuración

Módulos > Export Sevi > Configurar:

- **Carpeta de destino**: ruta completa en el servidor. Se puede elegir en el apartado «Carpetas» (✓ = se puede escribir).
- **Nombre del archivo**: p. ej. `productos_stock.csv`. Se sobrescribe en cada exportación.
- **Productos**: todos, solo activos o solo desactivados.
- **Exportar ahora**: genera el archivo al momento.

## Cron

```
https://tienda.com/modules/exportsevi/export.php
```

En Plesk > Tareas programadas, «Obtener una URL» con esa dirección. Responde en JSON:

```json
{"status":"success","message":"1520 filas en /var/www/vhosts/tienda.com/exports/productos_stock.csv","timestamp":"2026-11-07 02:00:00"}
```

## Formato del CSV

UTF-8 con BOM, separado por punto y coma.

| Columna | Productos sin combinaciones | Combinaciones |
|---|---|---|
| Referencias Completas | referencia del producto | referencia del producto padre |
| Referencias Filtradas | referencia del producto | referencia de la combinación |
| Nombre | nombre | nombre - Atributo: valor - … |
| Stock | stock del producto | stock de la combinación |

Los productos con combinaciones no tienen fila propia: solo salen sus combinaciones, para no duplicar el stock.

## Cambios

- **1.0.1**: guardar la configuración y «Exportar ahora» daban «Not Found» en PrestaShop 8/9 (dirección relativa); textos en español; mensajes de error con el motivo; se quita un hook sin uso.
- **1.0.0**: primera versión.
