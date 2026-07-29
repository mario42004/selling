# Pedidos por WhatsApp con n8n y MariaDB

Prototipo local del flujo de pedidos:

1. Recibe un pedido escrito en texto libre.
2. Reconoce productos del catálogo y cantidades.
3. Comprueba inventario y crea un pedido pendiente.
4. Espera la verificación manual del pago.
5. Al aprobarlo, descuenta el inventario de forma transaccional.
6. Genera la confirmación para el cliente.
7. Construye el resumen diario para logística.

La versión actual utiliza webhooks locales. WhatsApp Business Cloud, el LLM y
la búsqueda de imágenes se conectarán en la siguiente fase sin cambiar el
modelo de datos.

## Requisitos

- Docker y Docker Compose.
- Puertos locales `5678`, `5680` y `3308` disponibles.
- `curl` para las pruebas de ejemplo.

## Arranque

```bash
cp .env.example .env
make setup
```

Abre <http://localhost:5678> y crea la cuenta propietaria local cuando n8n la
solicite. Los tres flujos se importan y publican desde la línea de comandos.

Servicios:

- n8n: <http://localhost:5678>
- Panel de pedidos: <http://localhost:5680>
- MariaDB desde el host: `127.0.0.1:3308`
- MariaDB dentro de Docker: `mariadb:3306`

Los valores de `n8n/credentials/local-mariadb.json` son exclusivamente para
desarrollo local. Al cambiar la contraseña de MariaDB, actualiza también esa
credencial o créala de nuevo desde la interfaz de n8n.

El panel crea el primer usuario admin automáticamente si la tabla `users` está
vacía. En local usa `OPERATOR_ADMIN_EMAIL` y `OPERATOR_ADMIN_PASSWORD` desde
`.env`; si no existen, los valores de desarrollo son `admin@example.com` y
`ChangeMe123!`.

## Probar el flujo

### 1. Crear un pedido pendiente

```bash
curl -sS -X POST http://127.0.0.1:5678/webhook/orders/intake \
  -H 'Content-Type: application/json' \
  --data '{
    "external_message_id": "manual-001",
    "customer_name": "Ana",
    "phone": "+34622222222",
    "address": "Avenida Local 8",
    "message": "una agua grande y dos ron barcelo"
  }'
```

La respuesta contiene el `order_id`. El identificador externo evita duplicar
un pedido si WhatsApp reintenta el mismo webhook.

### 2. Revisar y aprobar manualmente el pago

Abre <http://localhost:5680>. El panel permite filtrar pedidos, confirmar o
rechazar el pago y marcar los pedidos confirmados como despachados. La
confirmación vuelve a comprobar las existencias y descuenta el inventario de
forma transaccional.

Para pruebas técnicas también se conserva el webhook local. Sustituye `3` por
el identificador recibido:

```bash
curl -sS -X POST http://127.0.0.1:5678/webhook/orders/approve \
  -H 'Content-Type: application/json' \
  --data '{"order_id": 3, "approved_by": "Operador"}'
```

La aprobación es idempotente: aprobar dos veces no descuenta dos veces.

### 3. Obtener el resumen del día

```bash
curl -sS http://127.0.0.1:5678/webhook/orders/daily-summary
```

El campo `message` está listo para enviarse al WhatsApp de logística.

## Catálogo local

El catálogo de ejemplo contiene:

- Coca-Cola 2 L
- Bolsa de hielo 2 kg
- Ron Barceló 70 cl
- Agua mineral 1,5 L

Puedes inspeccionarlo con:

```bash
make test-db
```

El intérprete local reconoce los alias almacenados en `products.aliases` y
números del uno al diez. Es un fallback verificable para desarrollar sin pagar
una API. El siguiente flujo sustituirá este nodo por un LLM con salida JSON,
manteniendo MariaDB como autoridad para SKU, precio y existencias.

## Archivos principales

- `compose.yaml`: n8n, MariaDB y panel de operador.
- `operator/`: interfaz protegida para gestionar pedidos.
- `db/init/001_schema.sql`: tablas, catálogo, procedimientos y vista diaria.
- `db/migrations/002_catalog_conversations.sql`: catálogo con imágenes, variantes, conversaciones y preparación del vendedor conversacional.
- `db/migrations/003_roles_locations.sql`: usuarios, roles, ciudades, zonas, tiendas y permisos territoriales.
- `db/migrations/006_inventory_views_store_manager.sql`: rol gerente de tienda, vistas de inventario por tienda y ciudad, y sincronización de pedidos con la tienda del producto vendido.
- `db/migrations/007_inventory_receipts.sql`: entradas de inventario auditadas para proveedores y gerentes dentro de su alcance.
- `db/migrations/008_rbac.sql`: roles y permisos normalizados con relaciones muchos-a-muchos y migración automática de usuarios existentes.
- `db/migrations/009_supplier_vat_catalog_pricing.sql`: coste neto del proveedor, IVA separado, total con IVA y permisos independientes para fijar precios de venta.
- `db/migrations/010_audit_reporting.sql`: bitácora inmutable, libro de movimientos de inventario y permisos para reportes territoriales.
- `db/migrations/011_sales_dispatch_documents.sql`: separación entre aprobación de pagos y despacho, bandeja diaria y documentos PDF de entrega.
- `n8n/workflows/01-local-order-intake.json`: entrada y creación del borrador.
- `n8n/workflows/02-local-payment-approval.json`: aprobación y descuento.
- `n8n/workflows/03-local-daily-summary.json`: resumen para logística.
- `n8n/workflows/04-whatsapp-order-intake.json`: recepción desde WhatsApp y creación del borrador.

## Comandos útiles

```bash
make up       # iniciar servicios
make logs     # seguir los logs
make import   # reimportar credencial y flujos
make publish  # publicar flujos y reiniciar n8n
make migrate  # aplicar migraciones de catálogo en desarrollo local
make down     # detener servicios sin borrar datos
make reset    # borrar volúmenes y reconstruir desde cero
```

`make reset` elimina todos los pedidos e inventario local y no debe utilizarse
si quieres conservar esos datos.

## Próxima fase

1. Configurar Meta Business y WhatsApp Cloud API.
2. Reemplazar el webhook local por el WhatsApp Trigger.
3. Añadir un LLM con salida estructurada y umbral de confianza.
4. Incorporar búsqueda y aprobación de imágenes faltantes.
5. Enviar la aprobación al WhatsApp interno.
6. Programar y enviar automáticamente el resumen diario.
7. Añadir pruebas de errores, copias de seguridad y despliegue remoto con HTTPS.

## Despliegue remoto con Docker

La configuración de producción reutiliza el Nginx instalado en el servidor.
n8n se publica únicamente en `127.0.0.1:5678` y el panel en
`127.0.0.1:5680`; ninguno queda accesible directamente desde Internet.
MariaDB permanece exclusivamente en la red Docker.

Antes de iniciar:

1. Instala Docker y Docker Compose en el servidor.
2. Haz que un registro DNS `A` del dominio apunte a la IP pública del servidor.
3. Comprueba que Nginx recibe tráfico por los puertos TCP `80` y `443`.
4. Copia este proyecto al servidor.

Prepara las variables:

```bash
cp .env.production.example .env.production
```

Edita `.env.production` y configura el dominio y contraseñas aleatorias. No
cambies `MARIADB_DATABASE=whatsapp_orders` en el primer despliegue, porque es el
nombre utilizado por el esquema inicial.

Protege el archivo después de editarlo:

```bash
chmod 600 .env.production
```

Inicia los contenedores:

```bash
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
docker compose --env-file .env.production -f compose.prod.yaml ps
```

Cuando n8n figure como saludable, importa la credencial MariaDB y publica los
workflows:

```bash
sh scripts/bootstrap-production.sh
```

Si el despliegue ya tenía datos antes de incorporar catálogo con variantes e
imágenes, aplica la migración una vez:

```bash
sh scripts/apply-migrations.sh
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
```

Antes de entrar al panel en producción, configura en `.env.production`:

```bash
OPERATOR_ADMIN_NAME=Admin
OPERATOR_ADMIN_EMAIL=correo-admin@dominio.com
OPERATOR_ADMIN_PASSWORD=una-contraseña-larga
```

El panel queda en `https://DOMINIO_CONFIGURADO/operator/`. Desde allí el admin
global puede crear proveedores, despachadores, gerentes de tienda y gerentes de
ciudad, además de crear ciudades, zonas, tiendas y asignaciones.

Orden recomendado dentro del panel:

1. Crear ciudad.
2. Crear zona, si aplica.
3. Crear tienda como centro de acción.
4. Crear usuarios y asignarles ciudad o tienda según el alcance de sus roles.

Los roles con alcance de tienda no deben quedar sin tienda asignada. Vendedor,
proveedor, despachador y gerente de tienda trabajan sobre sus tiendas. El
gerente de ciudad solo se asigna a una o varias ciudades y obtiene acceso a
todas las tiendas asociadas; el admin global no necesita asignación territorial.

La autorización utiliza RBAC desde MariaDB: `users` se relaciona con `roles`
mediante `user_roles`, y `roles` con `permissions` mediante
`role_permissions`. Un usuario puede combinar varios roles y recibe la unión de
sus permisos. `roles.scope_level` define si su alcance es de tienda, ciudad o
global. `user_store_assignments` y `user_city_assignments` delimitan el
alcance territorial de esos permisos. La columna histórica `users.role` se
mantiene temporalmente solo para compatibilidad durante despliegues; ya no es
la fuente de autorización.

En el catálogo, cada producto se crea asociado a una tienda propietaria. El
gerente de tienda puede crear, editar, ajustar cantidades y eliminar productos
de sus tiendas asignadas. El gerente de ciudad tiene esas mismas facultades
sobre las tiendas de sus ciudades y puede trasladar existencias entre ellas. El
admin global puede operar sobre todas las tiendas.

El proveedor registra por separado el importe neto, el porcentaje de IVA, el
importe de IVA y el total con IVA. Al crear el producto, el sistema propone el
precio normal del catálogo con un incremento del 30 % sobre el total del
proveedor. Después, solo vendedor, gerente de tienda y admin global pueden
modificar el precio normal o promocional; el admin también puede corregir los
valores aportados por el proveedor.

El proveedor puede crear, editar, activar, desactivar y eliminar productos,
además de ajustar sus cantidades, exclusivamente en sus tiendas asignadas. No
puede trasladar mercancía entre tiendas ni eliminar pedidos. Las entradas
realizadas desde la sección Inventario registran tienda, producto, variante,
cantidad, usuario, fecha y notas.

Los gerentes de tienda y ciudad pueden cancelar pedidos dentro de su alcance,
pero no eliminarlos del sistema. Al cancelar un pedido confirmado o despachado,
sus unidades vuelven al inventario. Solamente el admin global puede eliminar un
pedido definitivamente; el borrado elimina sus artículos y eventos y también
restaura el stock cuando corresponde.

El vendedor y los gerentes de tienda o ciudad pueden confirmar o rechazar pagos
dentro de su alcance; el admin global puede hacerlo en todo el sistema. El
despachador no participa en la aprobación: su única bandeja operativa muestra
las ventas confirmadas del día para sus tiendas. Desde allí genera una ficha
diaria consolidada en PDF, una guía de entrega PDF independiente por pedido y
registra cuándo cada paquete se entrega al repartidor. El gerente de tienda
conserva todas estas facultades operativas sobre su tienda, además del control
de catálogo, precios, inventario, cancelaciones, estadísticas y reportes, pero
no puede eliminar pedidos definitivamente.

El inventario se maneja por tienda, producto y talla. Cuando se aprueba una
venta, MariaDB vuelve a validar el stock y descuenta únicamente la variante de
la tienda asociada al producto vendido. El inventario de ciudad se calcula como
la suma de las tiendas visibles dentro de esa ciudad: admin ve todo, gerente de
ciudad ve su alcance geográfico y gerente de tienda ve sus tiendas asignadas.

La sección **Reportes** forma parte de Operator. El admin global obtiene datos
de todo el sistema; los gerentes de ciudad y tienda solo reciben ventas,
inventario y movimientos dentro de sus asignaciones. El periodo puede filtrarse
por fechas y, cuando el alcance lo permite, por ciudad y tienda. La exportación
genera un archivo Excel `.xlsx` con las hojas Resumen, Ventas, Productos
vendidos, Inventario y Movimientos.

La trazabilidad utiliza una entrada separada en
`https://DOMINIO_CONFIGURADO/operator/traceability.php` y es exclusiva del
admin global. Registra autenticaciones y cambios sobre usuarios, RBAC,
ubicaciones, catálogo, precios, inventario, pedidos y conversaciones, junto con
el usuario, roles, IP, origen, fecha y valores anteriores/posteriores. Puede
filtrarse por periodo, operación, acción, entidad, usuario, ciudad y tienda. Los
triggers de MariaDB impiden modificar o borrar entradas de `audit_log`; esta
migración no reconstruye cambios anteriores a su instalación.

Prepara la autenticación adicional de Nginx:

```bash
sudo htpasswd -c /etc/nginx/.htpasswd-n8n admin
```

Si `htpasswd` no está instalado, en Ubuntu pertenece al paquete
`apache2-utils`. Copia la plantilla y reemplaza todas las apariciones de
`pedidos.example.com` por el dominio real:

```bash
sudo cp deploy/nginx-n8n.conf /etc/nginx/sites-available/n8n-orders
sudo nano /etc/nginx/sites-available/n8n-orders
sudo ln -s /etc/nginx/sites-available/n8n-orders /etc/nginx/sites-enabled/n8n-orders
sudo nginx -t
sudo systemctl reload nginx
```

La plantilla presupone que el certificado ya existe en
`/etc/letsencrypt/live/DOMINIO/`. Si todavía no existe, hay que emitirlo con el
método Certbot que ya utilice el servidor antes de activar el bloque HTTPS.

Después abre `https://DOMINIO_CONFIGURADO` para n8n y
`https://DOMINIO_CONFIGURADO/operator/` para gestionar los pedidos. Nginx
solicitará el usuario de `.htpasswd-n8n` en ambos casos. Solo
`/webhook/orders/intake` y la ruta exacta verificada del trigger de WhatsApp
permanecen públicas; los endpoints de aprobación, resumen y cualquier otro
webhook quedan bloqueados desde Internet.
La contraseña de MariaDB se lee desde `.env.production`, se importa cifrada en
la base interna de n8n y no se escribe dentro de los workflows.

### Actualizar el workflow de WhatsApp

El workflow de WhatsApp conserva el ID, webhook y referencia a la credencial
configurados en la instancia de producción. Se actualiza por separado para que
las instalaciones locales sin credenciales de Meta puedan ejecutar las pruebas:

```bash
sh scripts/update-whatsapp-workflow.sh
```

El mensaje entrante debe ser de texto. El flujo toma el nombre y teléfono desde
WhatsApp, utiliza el ID del mensaje para evitar duplicados y reconoce productos
del catálogo local. También admite una dirección escrita como
`Dirección: Calle Ejemplo 3` o `entrega en Calle Ejemplo 3`. Si no encuentra
una dirección, crea el borrador con `Pendiente de confirmar` para que el
operador no confunda el dato con una dirección definitiva.

Para revisar los logs:

```bash
docker compose --env-file .env.production -f compose.prod.yaml logs -f --tail=100
```

Datos persistentes:

- `mariadb_data`: catálogo, inventario y pedidos.
- `n8n_data`: usuarios, credenciales cifradas, workflows y ejecuciones.

Conserva siempre el mismo `N8N_ENCRYPTION_KEY`. Si lo pierdes, n8n no podrá
descifrar las credenciales guardadas.

## GitHub Actions

El repositorio incluye dos automatizaciones:

- `CI`: valida Docker Compose, los JSON, PHP y los scripts; después levanta
  n8n, MariaDB y el panel, importa los workflows y ejecuta una aprobación real.
- `Deploy production`: despliega manualmente la rama `main` en la máquina
  remota mediante SSH.

Antes del primer despliegue, crea en GitHub un entorno llamado `production`.
Es recomendable configurarlo con aprobación manual. Añade estos secretos al
entorno:

- `DEPLOY_HOST`: IP o nombre DNS del servidor.
- `DEPLOY_USER`: usuario SSH con permisos para Docker y para el proyecto.
- `DEPLOY_PATH`: ruta absoluta del repositorio en el servidor.
- `DEPLOY_SSH_KEY`: clave SSH privada dedicada al despliegue.
- `DEPLOY_KNOWN_HOSTS`: línea de `known_hosts` correspondiente al servidor.

Opcionalmente, crea la variable de entorno `DEPLOY_PORT`; si no existe se usa
el puerto `22`. Para obtener `DEPLOY_KNOWN_HOSTS` desde una conexión de
confianza puedes ejecutar localmente:

```bash
ssh-keyscan -H servidor.example.com
```

Antes de ejecutar el workflow, la máquina remota debe tener:

1. Este repositorio clonado en `DEPLOY_PATH`, con acceso de lectura a GitHub.
2. Docker Compose y Git instalados.
3. El archivo `.env.production` configurado y protegido con permisos `600`.
4. Nginx, DNS y el certificado TLS preparados según la sección anterior.

El despliegue se inicia en GitHub desde **Actions → Deploy production → Run
workflow**. El workflow actualiza `main` con avance rápido, descarga las
imágenes, inicia los contenedores y publica los workflows de n8n. Las
contraseñas de la aplicación nunca se copian desde GitHub: permanecen en
`.env.production` dentro del servidor.
