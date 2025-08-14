# 📦 Módulo ExportSevi para PrestaShop

**Exporta referencias y stock de productos a CSV con soporte para cron automático**

## 📁 Estructura de Archivos

```
/modules/exportsevi/
├── exportsevi.php          ← Archivo principal del módulo
├── export.php              ← Script para ejecución por cron
├── config.xml              ← Configuración del módulo
├── index.php               ← Archivo de seguridad
└── README.md               ← Esta documentación
```

## 🚀 Instalación Rápida

1. **Subir archivos** a `/modules/exportsevi/` en tu servidor
2. **Ir al backoffice** → `Módulos y Servicios`
3. **Buscar** "Export Sevi"
4. **Instalar** el módulo
5. **Configurar** desde la página del módulo

## ⚙️ Configuración

### Panel de Administración
**Ruta:** `Módulos → Export Sevi → Configurar`

**Opciones disponibles:**
- 📂 **Carpeta destino**: Usa el explorador visual de carpetas
- 📄 **Nombre del archivo**: Ej: `productos_stock.csv`
- 🎯 **Estado de productos**: Todos / Solo activos / Solo inactivos

### Explorador de Carpetas
- **[+]** = Expandir carpeta
- **[-]** = Contraer carpeta  
- **Click en nombre** = Seleccionar como destino
- **Verde ✓** = Carpeta escribible
- **Rojo ✗** = Sin permisos de escritura

## 📊 Estructura del Export

### Columnas Generadas

| Columna | Descripción | Ejemplo |
|---------|-------------|---------|
| **Referencias Completas** | Productos únicos + referencias padre para combinaciones | `REF-A`, `2181`, `P41028` |
| **Referencias Filtradas** | Productos únicos + referencias específicas de combinaciones | `REF-A`, `5456-D`, `41028` |
| **Nombre** | Nombre concatenado con atributos | `Producto A`, `Llanta 8" - DELANTERO` |
| **Stock** | Stock individual de cada referencia | `10`, `15`, `0` |

### Ejemplo de Salida

```csv
Referencias Completas;Referencias Filtradas;Nombre;Stock
REF-A;REF-A;Producto Simple A;10
2181;5456-D;Llanta miniguad 8" - DELANTERO;15
2181;5457-T;Llanta miniguad 8" - TRASERO;28
P41028;41028;Plasticos ttr - Color: Azul;0
P41028;41026;Plasticos ttr - Color: Verde;14
```

## 🔄 Métodos de Exportación

### 1. Exportación Manual
- **Botón** "Exportar Ahora" en la configuración
- **Resultado inmediato** con confirmación
- **Ideal para** pruebas y exports puntuales

### 2. Exportación Automática (Cron)
**URL fija:** `https://tudominio.com/modules/exportsevi/export.php`

#### Ejemplos de Configuración Cron

**Cada hora:**
```bash
0 * * * * curl -s "https://tudominio.com/modules/exportsevi/export.php"
```

**Diario a las 2:00 AM:**
```bash
0 2 * * * curl -s "https://tudominio.com/modules/exportsevi/export.php"
```

**Semanal (lunes 6:00 AM):**
```bash
0 6 * * 1 curl -s "https://tudominio.com/modules/exportsevi/export.php"
```

**Con wget (alternativa):**
```bash
0 2 * * * wget -q -O /dev/null "https://tudominio.com/modules/exportsevi/export.php"
```

## 🎛️ Filtros de Productos

### Opciones Disponibles
- **Todos los productos**: Exporta activos e inactivos
- **Solo productos activos**: Solo productos visibles en tienda  
- **Solo productos inactivos**: Solo productos deshabilitados

### Lógica de Productos con Combinaciones
❌ **NO incluye** fila del producto padre (evita stock duplicado)  
✅ **SÍ incluye** cada combinación individual con su stock real

## 🔍 Verificación y Monitoreo

### Comprobar Funcionamiento
1. **Export manual**: Usar botón en configuración
2. **URL cron**: Abrir en navegador para ver respuesta JSON
3. **Archivo generado**: Verificar que existe en carpeta configurada
4. **Logs del servidor**: Revisar errores de Apache/Nginx

### Respuesta del Cron (JSON)
```json
{
  "status": "success",
  "message": "150 products exported to /var/www/exports/productos.csv",
  "timestamp": "2024-01-15 14:30:00"
}
```

## ❌ Solución de Problemas

### 🚫 No se crea el archivo
**Causas posibles:**
- Permisos incorrectos en carpeta destino
- Ruta de carpeta incorrecta
- Espacio insuficiente en disco

**Soluciones:**
```bash
# Dar permisos de escritura
chmod 755 /ruta/carpeta/destino

# Verificar espacio disponible  
df -h
```

### 📄 CSV vacío o incompleto
**Causas posibles:**
- No hay productos del tipo seleccionado
- Error en consulta SQL
- Problema de codificación

**Soluciones:**
- Verificar que existen productos activos/inactivos
- Revisar logs de PrestaShop
- Comprobar configuración de idioma

### ⚙️ Botón manual no funciona
**Causas posibles:**
- Permisos de administrador insuficientes
- Error en tokens de seguridad
- JavaScript deshabilitado

**Soluciones:**
- Verificar rol de usuario
- Refrescar página de configuración
- Habilitar JavaScript en navegador

### 🌐 Cron no ejecuta
**Causas posibles:**
- URL incorrecta
- Módulo no instalado/activo
- Configuración cron incorrecta

**Soluciones:**
```bash
# Probar URL manualmente
curl -v "https://tudominio.com/modules/exportsevi/export.php"

# Verificar configuración cron
crontab -l
```

## 🛡️ Seguridad y Rendimiento

### Características de Seguridad
- ✅ Archivo `index.php` previene listado de directorios
- ✅ Verificación de módulo instalado/activo
- ✅ Validación de permisos de carpetas
- ✅ Escape de rutas para prevenir inyección

### Optimización
- 📊 Consultas SQL optimizadas con índices
- 💾 Escritura directa a archivo (sin cargar en memoria)
- 🔄 Sobreescritura de archivo (no acumulativo)
- ⚡ Consultas separadas por tipo de producto

## 📝 Especificaciones Técnicas

### Compatibilidad
- **PrestaShop:** 1.6+
- **PHP:** 5.6+ (recomendado 7.4+)
- **MySQL:** 5.6+

### Formato de Archivo
- **Codificación:** UTF-8 with BOM
- **Separador:** Punto y coma (`;`)
- **Compatibilidad:** Excel, LibreOffice, Google Sheets

### Límites y Consideraciones
- **Productos:** Sin límite teórico
- **Memoria:** Mínima (escritura streaming)
- **Tiempo ejecución:** Depende del número de productos
- **Concurrencia:** Solo una exportación simultánea

## 🔄 Actualizaciones y Mantenimiento

### Backup Recomendado
Antes de actualizar, respaldar:
- Archivo de configuración del módulo
- Exports anteriores importantes
- Configuración de cron

### Log de Cambios
- **v1.0.0**: Versión inicial con todas las funcionalidades

## 📞 Soporte

### Información del Sistema
Para reportar problemas, incluir:
- Versión de PrestaShop
- Versión de PHP
- Número de productos en tienda
- Mensaje de error exacto
- Configuración del módulo

### Archivos de Log Útiles
- `/var/log/apache2/error.log`
- `/var/log/nginx/error.log`  
- `[prestashop]/var/logs/`
- Logs del cron del sistema

---

**Desarrollado para exportaciones eficientes de stock entre tiendas PrestaShop** 🚀