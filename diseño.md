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

### Usuarios internos del sistema

Personas humanas que usan el panel web. Ya no existe un único "operador"
genérico; el sistema debe controlar permisos por rol, ciudad, zona y tienda.

#### Proveedor

Responsable de cargar y mantener catálogo.

Puede:

- Crear productos.
- Editar productos propios o asignados.
- Subir imágenes.
- Actualizar precios.
- Actualizar tallas, variantes y cantidades.
- Activar o desactivar productos según permisos asignados.
- Ver stock de productos asignados.

No puede:

- Aprobar pagos.
- Crear usuarios.
- Ver estadísticas globales.
- Ver pedidos de zonas o tiendas no asignadas, salvo que el admin lo permita.

#### Vendedor

Responsable comercial asociado a una ciudad y tienda.

Puede:

- Ver pedidos de su tienda asignada.
- Ver y actualizar catálogo de su tienda asignada cuando el admin lo permita.
- Ver disponibilidad de productos de su tienda.
- Dar seguimiento operativo a compradores de su tienda.

No puede:

- Aprobar pagos.
- Crear usuarios.
- Ver tiendas no asignadas.
- Ver estadísticas globales.

#### Despachador

Responsable de revisar pagos y preparar despacho.

Puede:

- Ver pedidos pendientes de aprobación.
- Ver comprobantes de transferencia.
- Aprobar pagos para que el pedido pase a despacho.
- Rechazar pagos.
- Marcar pedidos como despachados.
- Ver la tabla de envíos que tiene asignada.

No puede:

- Crear usuarios.
- Modificar catálogo, salvo ajuste operativo explícito concedido por admin.
- Ver estadísticas globales.

#### Admin

Responsable total del sistema.

Puede:

- Crear usuarios.
- Editar usuarios.
- Activar o desactivar usuarios.
- Asignar roles.
- Asignar ciudades, zonas y tiendas.
- Ver todos los pedidos.
- Ver todos los productos.
- Ver todos los comprobantes.
- Aprobar o rechazar cualquier pedido.
- Actualizar cualquier catálogo.
- Ver estadísticas globales.
- Monitorear todo el flujo conversacional y operativo.

#### Gerente de ciudad

Responsable de una o varias ciudades, zonas o tiendas asignadas.

Puede:

- Ver pedidos de sus ciudades, zonas y tiendas asignadas.
- Aprobar o rechazar pedidos dentro de su alcance geográfico y comercial.
- Ver comprobantes dentro de su alcance.
- Actualizar catálogo de tiendas asignadas.
- Ver estadísticas de ventas por periodo dentro de su alcance.
- Ver desempeño por tienda, categoría, producto, día y estado.

No puede:

- Crear admins.
- Ver ciudades, zonas o tiendas no asignadas.
- Cambiar permisos globales del sistema.

## Matriz de permisos

| Funcionalidad | Vendedor | Proveedor | Despachador | Gerente de ciudad | Admin |
| --- | --- | --- | --- | --- | --- |
| Ver catálogo asignado | Sí | Sí | Opcional | Sí | Sí |
| Crear producto | Sí, tienda asignada | Sí | No | Sí, en tiendas asignadas | Sí |
| Editar producto | Sí, tienda asignada | Sí | No | Sí, en tiendas asignadas | Sí |
| Subir imágenes | Sí, tienda asignada | Sí | No | Sí, en tiendas asignadas | Sí |
| Actualizar stock | Sí, tienda asignada | Sí | No | Sí, en tiendas asignadas | Sí |
| Ver pedidos | Sí, tienda asignada | Limitado | Sí, asignados | Sí, por alcance | Sí |
| Ver comprobantes | No | No | Sí | Sí, por alcance | Sí |
| Aprobar pago | No | No | Sí | Sí, por alcance | Sí |
| Rechazar pago | No | No | Sí | Sí, por alcance | Sí |
| Marcar despachado | No | No | Sí | Sí, por alcance | Sí |
| Ver tabla de envíos | No | No | Sí, asignada | Sí, por alcance | Sí |
| Ver estadísticas | No | No | No | Sí, por alcance | Sí |
| Crear usuarios | No | No | No | No | Sí |
| Asignar roles | No | No | No | No | Sí |

## Alcance por ciudad, zona y tienda

Cada producto, pedido y usuario interno debe poder relacionarse con una tienda.
Cada tienda pertenece a una zona y ciudad. Los permisos se calculan por ese
alcance.

La ciudad representa un grupo de tiendas. Por eso el gerente de ciudad puede
trasladar existencias entre tiendas de esa misma ciudad, mientras que los roles
asociados a una sola tienda no pueden mover stock fuera de su tienda.

Ejemplo:

- Admin ve todo.
- Gerente de Bogotá Norte ve pedidos y catálogo de Bogotá Norte y de sus tiendas asignadas.
- Vendedor de Tienda Centro trabaja solo sobre Tienda Centro.
- Despachador de Tienda Centro ve pedidos asignados a esa tienda.
- Proveedor de Tienda Centro actualiza solo productos de esa tienda.

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

Debe ser una interfaz web clara para que proveedor, gerente de ciudad o admin
carguen productos según su alcance.

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

El panel actual debe evolucionar y mostrar acciones según rol.

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
- Ciudad, zona y tienda asociada.
- Usuario que aprobó o rechazó.
- Usuario que marcó despacho.

Estados visibles para operador:

- Esperando comprobante.
- Pendiente de aprobación.
- Aprobado.
- Rechazado.
- Despachado.
- Cancelado.

Reglas de acceso:

- Proveedor solo ve pedidos relacionados con productos o tiendas asignadas si el admin lo habilita.
- Despachador ve y gestiona pedidos de tiendas asignadas.
- Gerente de ciudad ve y gestiona pedidos de ciudades, zonas o tiendas asignadas.
- Admin ve y gestiona todo.

## Panel de usuarios

Debe existir una sección solo para admin.

Pantallas necesarias:

- Lista de usuarios.
- Crear usuario.
- Editar usuario.
- Activar/desactivar usuario.
- Cambiar rol.
- Asignar ciudades.
- Asignar zonas o tiendas.

Validaciones:

- Solo admin puede crear usuarios.
- Solo admin puede asignar rol `ADMIN`.
- Un usuario inactivo no puede entrar al sistema.
- Todo cambio de rol o asignación debe quedar auditado.

## Panel de estadísticas

Debe estar disponible para admin y gerente de ciudad.

Debe permitir filtrar por:

- Periodo.
- Ciudad.
- Zona.
- Tienda.
- Categoría.
- Producto.
- Estado del pedido.

Métricas mínimas:

- Ventas totales.
- Número de pedidos.
- Ticket promedio.
- Productos más vendidos.
- Pedidos aprobados.
- Pedidos rechazados.
- Pedidos pendientes.
- Ventas por día.
- Ventas por tienda.

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

### `users`

Usuarios internos del sistema.

Campos:

- `id`
- `name`
- `email`
- `password_hash`
- `role`
- `active`
- `last_login_at`
- `created_at`
- `updated_at`

Roles válidos:

- `PROVIDER`
- `SELLER`
- `DISPATCHER`
- `CITY_MANAGER`
- `ADMIN`

### `cities`

Ciudades donde opera el sistema.

Campos:

- `id`
- `name`
- `active`
- `created_at`
- `updated_at`

### `zones`

Zonas dentro de una ciudad.

Campos:

- `id`
- `city_id`
- `name`
- `active`
- `created_at`
- `updated_at`

### `stores`

Tiendas o unidades comerciales.

Campos:

- `id`
- `city_id`
- `zone_id`
- `name`
- `address`
- `phone`
- `active`
- `created_at`
- `updated_at`

### `user_store_assignments`

Relación entre usuarios y tiendas.

Campos:

- `id`
- `user_id`
- `store_id`
- `created_at`

Uso:

- Proveedor: tiendas donde puede actualizar catálogo.
- Despachador: tiendas cuyos pedidos puede aprobar/despachar.
- Gerente de ciudad: tiendas dentro de su alcance.

### `user_city_assignments`

Relación entre gerentes y ciudades.

Campos:

- `id`
- `user_id`
- `city_id`
- `created_at`

Uso:

- Gerente de ciudad puede ver y aprobar pedidos de sus ciudades.
- Admin puede ver todo sin asignaciones.

### `products`

Productos generales.

Campos:

- `id`
- `store_id`
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
- `store_id`
- `city_id`
- `zone_id`
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

Preparar el modelo para catálogo real, variantes, imágenes, conversaciones, comprobantes, usuarios internos y alcance geográfico.

Tareas:

- Crear migración SQL nueva.
- Mantener compatibilidad con pedidos existentes si es posible.
- Agregar usuarios, roles, ciudades, zonas, tiendas y asignaciones.
- Agregar tablas de catálogo, imágenes, variantes y conversaciones.
- Agregar campos para comprobante en pedidos.
- Asociar productos y pedidos con tienda, zona y ciudad.
- Agregar vista de envíos.

Resultado esperado:

La base puede representar productos reales con tallas, precios, fotos, stock y permisos por rol/territorio.

### Etapa 2: autenticación, usuarios y permisos

Objetivo:

Crear el acceso seguro al backoffice con roles reales.

Tareas:

- Crear login.
- Crear cierre de sesión.
- Crear usuario admin inicial por variables de entorno o script.
- Crear CRUD de usuarios para admin.
- Implementar roles `SELLER`, `PROVIDER`, `DISPATCHER`, `CITY_MANAGER`, `ADMIN`.
- Implementar asignación de ciudades, zonas y tiendas.
- Filtrar vistas y acciones según rol.
- Registrar auditoría de acciones sensibles.

Resultado esperado:

Cada usuario entra con su cuenta y solo ve o ejecuta lo que su rol permite.

### Etapa 3: construir panel de catálogo

Objetivo:

Permitir administrar productos desde el navegador según permisos.

Tareas:

- Crear lista de productos.
- Crear formulario de alta.
- Crear formulario de edición.
- Subir imágenes.
- Gestionar variantes por talla y cantidad.
- Activar/desactivar productos.
- Asociar productos a tienda.
- Filtrar catálogo por tienda, zona y ciudad según rol.

Resultado esperado:

Proveedor, gerente de ciudad y admin pueden cargar catálogo sin tocar SQL, dentro de su alcance.

### Etapa 4: mejorar panel de pedidos

Objetivo:

Adaptar el panel a pedidos conversacionales.

Tareas:

- Mostrar talla, variante e imagen.
- Mostrar comprobante de pago.
- Mostrar estado conversacional/pedido.
- Aprobar/rechazar pago.
- Marcar despachado.
- Mostrar tabla de envíos.
- Filtrar pedidos por ciudad, zona y tienda.
- Restringir aprobación según rol y alcance.

Resultado esperado:

Despachador, gerente de ciudad y admin pueden revisar ventas reales y gestionar despacho según permisos.

### Etapa 5: estadísticas por periodo

Objetivo:

Dar visibilidad de ventas a admin y gerente de ciudad.

Tareas:

- Crear filtros por periodo.
- Mostrar ventas totales.
- Mostrar número de pedidos.
- Mostrar ticket promedio.
- Mostrar productos más vendidos.
- Mostrar ventas por tienda.
- Mostrar ventas por estado.
- Restringir datos según alcance del usuario.

Resultado esperado:

Admin ve todo el negocio y gerente de ciudad ve sus ciudades, zonas o tiendas asignadas.

### Etapa 6: crear motor conversacional básico

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

### Etapa 7: enviar opciones con imágenes por WhatsApp

Objetivo:

Mostrar catálogo visual al comprador.

Tareas:

- Enviar mensajes con imágenes desde n8n.
- Numerar opciones.
- Guardar opciones ofrecidas en contexto.
- Manejar "quiero el 1", "el segundo", "los negros".

Resultado esperado:

El comprador puede escoger producto desde opciones visuales.

### Etapa 8: crear pedido conversacional

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

### Etapa 9: comprobante y aprobación manual

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

### Etapa 10: logística y resumen diario

Objetivo:

Preparar despachos.

Tareas:

- Crear tabla de envíos en panel.
- Generar resumen diario.
- Filtrar por aprobado, despachado y fecha.
- Marcar pedidos como despachados.

Resultado esperado:

El operador tiene una vista clara de qué enviar cada día.

### Etapa 11: endurecimiento y producción

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

- Admin puede crear usuarios y asignar roles.
- Admin puede asignar ciudades, zonas y tiendas.
- Cada usuario ve solo las funcionalidades permitidas por su rol.
- Cada usuario ve solo las ciudades, zonas o tiendas de su alcance, salvo admin.
- Proveedor puede crear productos con fotos, tallas, cantidades y precios.
- Gerente de ciudad puede actualizar catálogo dentro de su alcance.
- Gerente de ciudad puede ver estadísticas por periodo dentro de su alcance.
- Despachador puede aprobar pagos y marcar despachos asignados.
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
- Si un proveedor puede pertenecer a varias tiendas.
- Si un despachador aprueba por tienda, zona o ciudad.
- Si el gerente de ciudad puede crear proveedores o solo admin.
- Qué estadísticas exactas necesita cada gerente.

## Recomendación de implementación

Construir en este orden:

1. Base de datos.
2. Login, usuarios, roles y permisos.
3. Ciudades, zonas, tiendas y asignaciones.
4. Panel de catálogo con alcance por tienda.
5. Panel de pedidos con aprobación por rol y territorio.
6. Estadísticas para admin y gerente de ciudad.
7. Conversación básica por WhatsApp.
8. Envío de imágenes.
9. Comprobante y aprobación.
10. Logística.
11. Pruebas y producción.

La razón es que el bot depende del catálogo, y el catálogo depende de permisos. Primero debe existir una forma confiable de saber quién puede ver, crear, aprobar o modificar cada cosa. Después n8n puede vender usando esa información.
