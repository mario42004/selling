# Diseño del sistema de ventas por WhatsApp

## Objetivo

Construir un sistema donde un comprador pueda escribir por WhatsApp como si hablara con un vendedor humano, y el vendedor automatizado consulte un catálogo real, muestre productos con imágenes, tome la elección, pida datos de entrega y comprobante de pago, y deje el pedido listo para aprobación manual en el panel de operador.

El sistema no debe depender de mensajes rígidos como "una agua grande". Debe manejar una conversación por etapas.

## Roles

### Comprador

Persona que compra por WhatsApp.

Puede escribir mensajes libres como:

- "Hola, que zapatos deportivos tienes talla M a menos de 150000 pesos"
- "Me interesa el numero 2"
- "Mi direccion es Calle 10 # 20-30"
- Enviar una imagen como comprobante de transferencia

### Vendedor

Bot automatizado controlado por n8n.

Debe:

- Saludar de forma natural.
- Entender la intención del comprador.
- Consultar el catálogo actualizado.
- Enviar opciones con imagen, precio y disponibilidad.
- Guiar al comprador hasta crear un pedido completo.
- Verificar stock antes de reservar o confirmar.
- Pedir datos de entrega.
- Pedir comprobante de transferencia.
- Enviar el pedido al panel de operador para aprobación manual.
- Responder al comprador cuando el pedido quede aprobado o rechazado.

### Operador

Persona humana que usa el panel web.

Debe:

- Crear y mantener el catálogo.
- Subir fotos de productos.
- Definir precios, tallas, cantidades y categorías.
- Revisar pedidos pendientes.
- Ver comprobantes de transferencia.
- Aprobar o rechazar pagos.
- Marcar pedidos como despachados.
- Consultar la tabla diaria de envíos.

## Flujo general esperado

1. El operador crea productos en el catálogo.
2. El comprador escribe por WhatsApp preguntando por productos.
3. n8n recibe el mensaje.
4. El bot identifica la intención y extrae filtros.
5. El bot consulta catálogo, variantes, precios, tallas, stock e imágenes.
6. El bot envía opciones al comprador.
7. El comprador escoge una opción.
8. El bot verifica stock de nuevo.
9. El bot crea o actualiza el pedido.
10. El bot pide datos de entrega.
11. El comprador envía datos de entrega.
12. El bot pide comprobante de transferencia.
13. El comprador envía imagen del comprobante.
14. El pedido queda en el panel del operador como pendiente de aprobación.
15. El operador aprueba o rechaza.
16. Si aprueba, el bot confirma al comprador que el pedido está en proceso.
17. Si rechaza, el bot avisa al comprador que debe corregir o reenviar comprobante.
18. El pedido aprobado aparece en la tabla de envíos.

## Estados de conversación

Cada comprador debe tener una conversación persistente asociada a su teléfono.

Estados principales:

- `NEW`: conversación nueva sin contexto.
- `BROWSING`: el comprador está buscando productos.
- `WAITING_SELECTION`: el bot ya mostró opciones y espera elección.
- `WAITING_DELIVERY_DATA`: el bot espera dirección y datos de entrega.
- `WAITING_PAYMENT_PROOF`: el bot espera imagen del comprobante.
- `PENDING_OPERATOR_APPROVAL`: el pedido espera aprobación manual.
- `APPROVED`: pago aprobado y pedido en proceso.
- `REJECTED`: pago rechazado.
- `DISPATCHED`: pedido despachado.
- `CANCELLED`: conversación o pedido cancelado.

Regla importante: el bot no debe asumir que un mensaje es un pedido completo. Debe mirar el estado actual antes de decidir qué hacer.

## Intenciones del comprador

El bot debe clasificar cada mensaje en una intención:

- `search_products`: busca productos.
- `select_product`: escoge una opción.
- `provide_delivery_data`: entrega nombre, dirección o datos de envío.
- `send_payment_proof`: envía comprobante de pago como imagen.
- `ask_price`: pregunta precio.
- `ask_stock`: pregunta disponibilidad.
- `change_selection`: cambia producto elegido.
- `cancel_order`: cancela.
- `unknown`: no se entiende y hay que pedir aclaración.

## Catálogo

El catálogo será la autoridad de productos.

Cada producto debe tener:

- Nombre.
- Descripción corta.
- Categoría principal.
- Subcategoría o clase.
- Marca opcional.
- Color opcional.
- Género opcional.
- Precio.
- Precio promocional opcional.
- Estado activo o inactivo.
- Una o varias imágenes.
- Variantes con talla, cantidad y SKU.

Ejemplo:

Producto:

- Nombre: Zapato deportivo Nike negro
- Categoría: zapatos
- Clase: deportivos
- Color: negro
- Precio: 145000
- Imagen principal: foto subida por operador

Variantes:

- Talla M, stock 3
- Talla L, stock 1
- Talla S, stock 0

## Panel de catálogo

Debe ser una interfaz web clara para que el operador cargue productos.

Pantallas necesarias:

- Lista de productos.
- Crear producto.
- Editar producto.
- Subir imágenes.
- Gestionar variantes.
- Activar o desactivar productos.
- Ver stock por talla.

Campos mínimos para crear producto:

- Nombre.
- Categoría.
- Clase o tipo.
- Precio.
- Descripción.
- Imágenes.
- Tallas y cantidades.

Validaciones:

- No se puede publicar producto sin nombre.
- No se puede publicar producto sin precio.
- No se puede publicar producto sin al menos una variante con stock.
- Las cantidades deben ser enteros mayores o iguales a cero.
- Los precios deben ser mayores o iguales a cero.

## Panel de pedidos

El panel actual debe evolucionar.

Debe mostrar:

- Pedido.
- Cliente.
- Teléfono.
- Productos.
- Tallas.
- Cantidades.
- Total.
- Dirección.
- Estado.
- Imagen del comprobante.
- Fecha de creación.
- Botones de aprobar, rechazar y despachar.

Estados visibles para operador:

- Esperando comprobante.
- Pendiente de aprobación.
- Aprobado.
- Rechazado.
- Despachado.
- Cancelado.

## Envío de imágenes por WhatsApp

Cuando el bot muestre opciones, debe enviar cada producto con:

- Imagen.
- Nombre.
- Precio.
- Talla disponible.
- Código u opción numerada.

Ejemplo de respuesta:

```text
Tengo estas opciones para ti:

1. Zapato deportivo Nike negro
Talla M disponible
Precio: 145000 pesos

2. Zapato deportivo Adidas blanco
Talla M disponible
Precio: 120000 pesos

Respóndeme con el número que prefieres.
```

Las imágenes se deben enviar usando la API de WhatsApp Cloud desde n8n.

## Comprobante de pago

El comprobante debe ser una imagen enviada por WhatsApp.

Cuando llegue una imagen en estado `WAITING_PAYMENT_PROOF`, el sistema debe:

1. Descargar o guardar referencia segura del media de WhatsApp.
2. Asociarla al pedido.
3. Cambiar el pedido a `PENDING_OPERATOR_APPROVAL`.
4. Mostrarlo en el panel de operador.
5. Responder al comprador:

```text
Gracias. Recibimos tu comprobante y lo estamos verificando.
```

## Verificación de stock

El stock debe verificarse en dos momentos:

1. Al mostrar opciones, para no ofrecer productos agotados.
2. Justo cuando el comprador escoge, para evitar vender algo que se agotó mientras tanto.

Si no hay stock al escoger:

```text
Ese producto se acaba de agotar. Te puedo mostrar opciones similares.
```

Decisión pendiente: definir si el sistema reserva stock al escoger o solo descuenta al aprobar pago.

Recomendación inicial:

- Crear pedido con stock reservado temporalmente al escoger.
- Liberar reserva si el pedido se cancela o expira.
- Descontar stock definitivo al aprobar pago.

## Base de datos propuesta

### `products`

Productos generales.

Campos:

- `id`
- `name`
- `description`
- `category`
- `type`
- `brand`
- `color`
- `gender`
- `price`
- `sale_price`
- `active`
- `created_at`
- `updated_at`

### `product_images`

Imágenes de productos.

Campos:

- `id`
- `product_id`
- `image_path`
- `image_url`
- `is_primary`
- `created_at`

### `product_variants`

Tallas, cantidades y SKU.

Campos:

- `id`
- `product_id`
- `sku`
- `size`
- `stock`
- `reserved_stock`
- `active`
- `created_at`
- `updated_at`

### `conversations`

Estado conversacional por comprador.

Campos:

- `id`
- `phone`
- `customer_name`
- `state`
- `last_intent`
- `context_json`
- `active_order_id`
- `created_at`
- `updated_at`

### `conversation_messages`

Historial de mensajes.

Campos:

- `id`
- `conversation_id`
- `direction`
- `message_type`
- `body`
- `whatsapp_message_id`
- `media_id`
- `media_url`
- `created_at`

### `orders`

Pedido.

Campos:

- `id`
- `conversation_id`
- `customer_name`
- `phone`
- `delivery_address`
- `delivery_notes`
- `status`
- `total`
- `payment_proof_url`
- `payment_confirmed_by`
- `payment_confirmed_at`
- `created_at`
- `updated_at`

### `order_items`

Productos dentro del pedido.

Campos:

- `id`
- `order_id`
- `product_id`
- `variant_id`
- `sku`
- `product_name`
- `size`
- `quantity`
- `unit_price`
- `image_url`

### `order_events`

Auditoría de cambios.

Campos:

- `id`
- `order_id`
- `event_type`
- `actor`
- `details`
- `created_at`

## Workflows n8n propuestos

### Workflow 1: entrada WhatsApp

Recibe todos los mensajes entrantes.

Responsabilidades:

- Normalizar mensaje.
- Identificar teléfono y nombre.
- Guardar mensaje en historial.
- Cargar conversación actual.
- Enviar a router de estado.

### Workflow 2: router conversacional

Decide qué hacer según estado e intención.

Responsabilidades:

- Clasificar intención.
- Extraer filtros.
- Enviar a búsqueda, selección, entrega, comprobante o fallback.

### Workflow 3: búsqueda de productos

Consulta catálogo.

Responsabilidades:

- Buscar por categoría, tipo, talla, precio, color y texto libre.
- Devolver máximo 3 a 5 opciones.
- Guardar opciones ofrecidas en `context_json`.
- Enviar respuesta con imágenes.
- Cambiar estado a `WAITING_SELECTION`.

### Workflow 4: selección de producto

Procesa la elección del comprador.

Responsabilidades:

- Entender número de opción o referencia textual.
- Verificar stock actualizado.
- Crear pedido o agregar al pedido.
- Reservar stock si se decide usar reservas.
- Pedir datos de entrega.
- Cambiar estado a `WAITING_DELIVERY_DATA`.

### Workflow 5: datos de entrega

Procesa dirección y datos.

Responsabilidades:

- Extraer dirección.
- Guardar datos en pedido.
- Pedir comprobante de transferencia.
- Cambiar estado a `WAITING_PAYMENT_PROOF`.

### Workflow 6: comprobante

Procesa imagen de pago.

Responsabilidades:

- Validar que el mensaje sea imagen.
- Guardar media o referencia.
- Asociar imagen al pedido.
- Cambiar pedido a `PENDING_OPERATOR_APPROVAL`.
- Cambiar conversación a `PENDING_OPERATOR_APPROVAL`.
- Avisar al comprador que el pago será revisado.

### Workflow 7: notificación tras aprobación

Se dispara cuando el operador aprueba o rechaza.

Responsabilidades:

- Si aprueba, enviar confirmación al comprador.
- Si rechaza, pedir nuevo comprobante o avisar rechazo.
- Actualizar conversación.

### Workflow 8: resumen de envíos

Genera tabla diaria de envíos.

Responsabilidades:

- Listar pedidos aprobados o pendientes de despacho.
- Mostrar dirección, cliente, teléfono, productos y notas.
- Exportar o mostrar tabla en panel.

## Uso de inteligencia artificial

El sistema puede iniciar con reglas simples y después añadir LLM.

Uso recomendado del LLM:

- Clasificar intención.
- Extraer filtros de búsqueda.
- Interpretar respuestas naturales del comprador.
- Convertir texto de dirección en campos.

La base de datos siempre debe seguir siendo la autoridad para:

- Productos.
- Precios.
- Stock.
- Tallas.
- Estados de pedidos.

El LLM nunca debe inventar productos, precios ni stock.

## Etapas de construcción

### Etapa 1: rediseñar base de datos

Objetivo:

Preparar el modelo para catálogo real, variantes, imágenes, conversaciones y comprobantes.

Tareas:

- Crear migración SQL nueva.
- Mantener compatibilidad con pedidos existentes si es posible.
- Agregar tablas de catálogo, imágenes, variantes y conversaciones.
- Agregar campos para comprobante en pedidos.
- Agregar vista de envíos.

Resultado esperado:

La base puede representar productos reales con tallas, precios, fotos y stock.

### Etapa 2: construir panel de catálogo

Objetivo:

Permitir al operador administrar productos desde el navegador.

Tareas:

- Crear lista de productos.
- Crear formulario de alta.
- Crear formulario de edición.
- Subir imágenes.
- Gestionar variantes por talla y cantidad.
- Activar/desactivar productos.

Resultado esperado:

El operador puede cargar catálogo sin tocar SQL.

### Etapa 3: mejorar panel de pedidos

Objetivo:

Adaptar el panel a pedidos conversacionales.

Tareas:

- Mostrar talla, variante e imagen.
- Mostrar comprobante de pago.
- Mostrar estado conversacional/pedido.
- Aprobar/rechazar pago.
- Marcar despachado.
- Mostrar tabla de envíos.

Resultado esperado:

El operador puede revisar ventas reales y gestionar despacho.

### Etapa 4: crear motor conversacional básico

Objetivo:

Reemplazar el parser rígido por conversación con estados.

Tareas:

- Crear tabla de conversaciones.
- Guardar historial de mensajes.
- Detectar estado actual por teléfono.
- Crear router por estado.
- Implementar búsqueda básica por texto, talla y precio.

Resultado esperado:

El bot puede responder búsquedas y esperar selección.

### Etapa 5: enviar opciones con imágenes por WhatsApp

Objetivo:

Mostrar catálogo visual al comprador.

Tareas:

- Enviar mensajes con imágenes desde n8n.
- Numerar opciones.
- Guardar opciones ofrecidas en contexto.
- Manejar "quiero el 1", "el segundo", "los negros".

Resultado esperado:

El comprador puede escoger producto desde opciones visuales.

### Etapa 6: crear pedido conversacional

Objetivo:

Crear pedido después de selección y verificación de stock.

Tareas:

- Verificar stock justo antes de crear pedido.
- Crear pedido con estado inicial.
- Guardar producto, variante, talla y precio.
- Pedir datos de entrega.
- Guardar dirección.

Resultado esperado:

El sistema crea pedidos reales desde una conversación natural.

### Etapa 7: comprobante y aprobación manual

Objetivo:

Completar el flujo de pago.

Tareas:

- Recibir imagen por WhatsApp.
- Guardar comprobante.
- Mostrar comprobante en operator.
- Aprobar/rechazar desde panel.
- Notificar resultado al comprador.

Resultado esperado:

El pago queda bajo control humano y el comprador recibe confirmación.

### Etapa 8: logística y resumen diario

Objetivo:

Preparar despachos.

Tareas:

- Crear tabla de envíos en panel.
- Generar resumen diario.
- Filtrar por aprobado, despachado y fecha.
- Marcar pedidos como despachados.

Resultado esperado:

El operador tiene una vista clara de qué enviar cada día.

### Etapa 9: endurecimiento y producción

Objetivo:

Hacer el sistema confiable.

Tareas:

- Manejar errores de WhatsApp.
- Evitar duplicados por `whatsapp_message_id`.
- Agregar expiración de reservas.
- Agregar logs útiles.
- Agregar backups de base de datos.
- Proteger subida de imágenes.
- Validar tamaño y tipo de archivo.
- Añadir pruebas automáticas.

Resultado esperado:

El sistema queda listo para uso continuo en producción.

## Criterios de aceptación

El sistema se considera listo cuando:

- El operador puede crear productos con fotos, tallas, cantidades y precios.
- El comprador puede preguntar por WhatsApp usando lenguaje natural.
- El bot responde con opciones del catálogo real.
- El comprador puede escoger una opción.
- El sistema verifica stock antes de crear pedido.
- El bot pide dirección y comprobante.
- El comprobante aparece en el panel.
- El operador puede aprobar o rechazar.
- El comprador recibe respuesta final.
- La tabla de envíos muestra los pedidos aprobados.

## Decisiones pendientes

- Moneda exacta a mostrar: pesos colombianos u otra moneda.
- Si el stock se reserva al escoger o solo se descuenta al aprobar.
- Tiempo de expiración de una reserva.
- Cantidad máxima de opciones enviadas por WhatsApp.
- Dónde guardar imágenes: disco local, volumen Docker, S3 u otro servicio.
- Si se usará LLM desde el inicio o primero reglas simples.
- Qué campos exactos de entrega son obligatorios.

## Recomendación de implementación

Construir en este orden:

1. Base de datos.
2. Panel de catálogo.
3. Panel de pedidos mejorado.
4. Conversación básica por WhatsApp.
5. Envío de imágenes.
6. Comprobante y aprobación.
7. Logística.
8. Pruebas y producción.

La razón es que el bot depende del catálogo. Primero debe existir una forma confiable de cargar productos, imágenes, tallas, precios y stock. Después n8n puede vender usando esa información.
