# Estado actual para retomar

Fecha: 2026-07-28

## Resumen corto

Estamos construyendo un sistema de ventas por WhatsApp con n8n, MariaDB y un panel web llamado `operator`.

La idea central es:

- El comprador conversa por WhatsApp.
- n8n actúa como vendedor automatizado.
- El catálogo real vive en MariaDB.
- El panel `operator` permite administrar catálogo, roles, tiendas, pedidos, pagos, despacho, inventario y estadísticas.
- Cada producto pertenece a una tienda.
- Cada tienda maneja su propio inventario.
- El inventario de una ciudad es la suma del inventario de todas sus tiendas.
- Una venta descuenta stock de la variante exacta vendida en la tienda propietaria del producto.

## Último commit subido

Commit en `main`:

```text
c889a47 feat: add scoped inventory views
```

Cambios incluidos:

- Nuevo rol `STORE_MANAGER`, mostrado como `Gerente de tienda`.
- Nueva vista del panel: `Inventario`.
- Inventario por tienda usando `inventory_by_store`.
- Inventario consolidado usando `inventory_by_city` para admin.
- Inventario consolidado por alcance para usuarios no admin.
- Estadísticas ampliadas con ventas, pedidos, ticket promedio, unidades en inventario, productos activos y tiendas visibles.
- Nueva migración `db/migrations/006_inventory_views_store_manager.sql`.
- Trigger `order_items_set_order_location` para que los pedidos queden asociados a la tienda, ciudad y zona del producto vendido.
- Documentación actualizada en `README.md` y `diseño.md`.

## Roles definidos

### Admin

Puede ver y administrar todo:

- Usuarios.
- Ciudades.
- Zonas.
- Tiendas.
- Catálogo.
- Inventario.
- Pedidos.
- Pagos.
- Despachos.
- Estadísticas globales.
- Traslados de inventario entre tiendas.

### Gerente de ciudad

Trabaja dentro de sus ciudades o tiendas asignadas.

Puede:

- Ver pedidos de su alcance.
- Aprobar o rechazar pagos de su alcance.
- Ver envíos.
- Crear y actualizar catálogo.
- Ver inventario por tienda.
- Ver consolidado de ciudad.
- Ver estadísticas.
- Trasladar inventario entre tiendas de la misma ciudad.

### Gerente de tienda

Trabaja dentro de sus tiendas asignadas.

Puede:

- Ver pedidos de su tienda.
- Ver envíos de su tienda.
- Ver inventario de su tienda.
- Ver estadísticas y ventas de su tienda.
- Crear y actualizar productos de su tienda.

No puede:

- Aprobar pagos.
- Rechazar pagos.
- Trasladar inventario entre tiendas.
- Crear usuarios.
- Ver tiendas no asignadas.

### Despachador

Puede:

- Ver pedidos asignados.
- Ver comprobantes.
- Aprobar o rechazar pagos.
- Marcar pedidos como despachados.
- Ver tabla de envíos.

### Proveedor

Puede:

- Crear productos.
- Actualizar catálogo.
- Subir imágenes.
- Gestionar tallas, cantidades y precios dentro de sus tiendas asignadas.

### Vendedor

Puede:

- Ver pedidos de su tienda.
- Gestionar catálogo de su tienda si tiene permiso.
- Dar seguimiento operativo.

## Estado técnico actual

### Base de datos

Migraciones existentes:

- `002_catalog_conversations.sql`: catálogo con imágenes, variantes, conversaciones y procedimientos de pedido.
- `003_roles_locations.sql`: usuarios, roles, ciudades, zonas, tiendas y asignaciones.
- `004_seller_role_store_scope.sql`: alcance de vendedores por tienda.
- `005_catalog_store_inventory.sql`: producto asociado a tienda, eliminación lógica y traslados de stock.
- `006_inventory_views_store_manager.sql`: gerente de tienda, vistas de inventario y sincronización de pedido con tienda del producto.

El procedimiento `approve_order`:

- Revisa que el pedido exista.
- Evita doble descuento si ya estaba confirmado.
- Solo aprueba pedidos en `PENDING_PAYMENT`.
- Revalida stock antes de aprobar.
- Descuenta `product_variants.stock`.
- Actualiza `products.stock` como suma de variantes.
- Marca el pedido como `CONFIRMED`.

### Panel `operator`

Pantallas actuales:

- Pedidos.
- Catálogo.
- Envíos.
- Inventario.
- Estadísticas.
- Ubicaciones.
- Usuarios.

Funcionalidad actual del catálogo:

- Crear productos.
- Asignar producto a tienda.
- Editar productos.
- Subir foto.
- Usar URL de imagen.
- Gestionar tallas y cantidades.
- Activar/desactivar producto.
- Eliminar producto con eliminación lógica.
- Trasladar existencias entre tiendas, solo admin y gerente de ciudad.

Funcionalidad actual de ubicaciones:

- Crear ciudad.
- Crear zona.
- Crear tienda.
- Ver centros de acción con ciudad, zona, tienda, catálogo y pedidos.

### n8n

Workflows actuales:

- `01-local-order-intake.json`
- `02-local-payment-approval.json`
- `03-local-daily-summary.json`
- `04-whatsapp-order-intake.json`

El flujo de WhatsApp actual todavía es básico. Recibe mensajes, identifica productos por catálogo local y crea borradores, pero falta convertirlo en conversación completa con búsqueda natural, selección, dirección, comprobante y respuesta final automática.

## Producción

URL esperada del panel:

```text
https://DOMINIO_CONFIGURADO/operator/
```

Comandos para actualizar producción desde GitHub:

```bash
cd ~/apis/selling
git pull origin main
sh scripts/apply-migrations.sh
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
docker compose --env-file .env.production -f compose.prod.yaml ps
```

Si solo se actualiza el workflow de WhatsApp:

```bash
cd ~/apis/selling
git pull origin main
sh scripts/update-whatsapp-workflow.sh
```

## Validaciones realizadas

Se ejecutó correctamente:

```bash
git diff --check
find n8n/workflows -name '*.json' -print0 | xargs -0 -n1 jq empty
```

No se pudo ejecutar localmente:

```bash
php -l operator/public/index.php
docker compose --env-file .env.production -f compose.prod.yaml config --quiet
```

Motivo: este entorno local no tiene `php` ni `docker` instalados.

## Pendiente principal para mañana

### 1. Probar en producción

Después del `git pull` en el servidor:

- Aplicar migraciones.
- Reconstruir contenedores.
- Entrar a `/operator/`.
- Confirmar que aparece el rol `Gerente de tienda`.
- Crear una tienda.
- Crear un usuario gerente de tienda asignado a ciudad y tienda.
- Crear producto en esa tienda con talla y stock.
- Verificar pantalla de inventario.
- Hacer un pedido de prueba.
- Aprobar pago.
- Confirmar que bajó el stock de esa tienda.

### 2. Mejorar conversación WhatsApp

Hay que reemplazar el comportamiento rígido que falló con:

```text
No se reconoció ningún producto
```

El bot debe:

- Entender búsquedas libres como "zapatos deportivos talla M menos de 150000".
- Consultar catálogo con filtros de categoría, tipo, talla, precio y stock.
- Responder con opciones e imágenes.
- Esperar selección.
- Verificar stock nuevamente.
- Pedir datos de entrega.
- Pedir comprobante como imagen.
- Dejar pedido en `operator` para aprobación manual.
- Responder al comprador cuando se apruebe o rechace.

### 3. Estadísticas por periodo

La pantalla actual tiene estadísticas generales. Falta agregar filtros por:

- Fecha inicial.
- Fecha final.
- Ciudad.
- Tienda.
- Categoría.
- Producto.
- Estado.

### 4. Reservas de inventario

Actualmente el descuento definitivo ocurre al aprobar pago. Falta decidir e implementar si se reserva stock cuando el comprador escoge producto y se libera al cancelar o expirar.

### 5. Ajustes finos de seguridad

Pendiente:

- Auditoría de cambios de usuario.
- Auditoría de cambios de catálogo.
- Límites de tamaño para imágenes.
- Mejor manejo de errores de subida.
- Backups automáticos de MariaDB.

## Regla que no se debe romper

Nunca manejar inventario solo a nivel de ciudad.

La ciudad es un consolidado. La tienda es la unidad real de inventario, venta, catálogo y operación.
