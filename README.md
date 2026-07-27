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
- Puertos locales `5678` y `3308` disponibles.
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
- MariaDB desde el host: `127.0.0.1:3308`
- MariaDB dentro de Docker: `mariadb:3306`

Los valores de `n8n/credentials/local-mariadb.json` son exclusivamente para
desarrollo local. Al cambiar la contraseña de MariaDB, actualiza también esa
credencial o créala de nuevo desde la interfaz de n8n.

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

### 2. Aprobar manualmente el pago

Sustituye `3` por el identificador recibido:

```bash
curl -sS -X POST http://127.0.0.1:5678/webhook/orders/approve \
  -H 'Content-Type: application/json' \
  --data '{"order_id": 3, "approved_by": "Operador"}'
```

La operación vuelve a comprobar las existencias, descuenta el inventario y es
idempotente: aprobar dos veces no descuenta dos veces.

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

- `compose.yaml`: n8n y MariaDB.
- `db/init/001_schema.sql`: tablas, catálogo, procedimientos y vista diaria.
- `n8n/workflows/01-local-order-intake.json`: entrada y creación del borrador.
- `n8n/workflows/02-local-payment-approval.json`: aprobación y descuento.
- `n8n/workflows/03-local-daily-summary.json`: resumen para logística.

## Comandos útiles

```bash
make up       # iniciar servicios
make logs     # seguir los logs
make import   # reimportar credencial y flujos
make publish  # publicar flujos y reiniciar n8n
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
n8n se publica únicamente en `127.0.0.1:5678`, por lo que no queda accesible
directamente desde Internet. MariaDB permanece exclusivamente en la red Docker.

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
docker compose --env-file .env.production -f compose.prod.yaml up -d
docker compose --env-file .env.production -f compose.prod.yaml ps
```

Cuando n8n figure como saludable, importa la credencial MariaDB y publica los
workflows:

```bash
sh scripts/bootstrap-production.sh
```

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

Después abre `https://DOMINIO_CONFIGURADO` y crea la cuenta propietaria de n8n.
Nginx solicitará primero el usuario de `.htpasswd-n8n`. Esta protección cubre
la interfaz y las APIs administrativas, mientras que `/webhook/*` permanece
público para que Meta pueda entregar mensajes.
La contraseña de MariaDB se lee desde `.env.production`, se importa cifrada en
la base interna de n8n y no se escribe dentro de los workflows.

Para revisar los logs:

```bash
docker compose --env-file .env.production -f compose.prod.yaml logs -f --tail=100
```

Datos persistentes:

- `mariadb_data`: catálogo, inventario y pedidos.
- `n8n_data`: usuarios, credenciales cifradas, workflows y ejecuciones.

Conserva siempre el mismo `N8N_ENCRYPTION_KEY`. Si lo pierdes, n8n no podrá
descifrar las credenciales guardadas.
