USE whatsapp_orders;

INSERT INTO permissions (code, name, description) VALUES
  ('orders.approve', 'Aprobar pagos', 'Confirmar o rechazar pagos de pedidos dentro del alcance asignado.'),
  ('shipments.dispatch', 'Entregar pedidos a reparto', 'Marcar pedidos confirmados como entregados al repartidor.'),
  ('shipments.export', 'Generar documentos de entrega', 'Generar fichas diarias y guías individuales de entrega en PDF.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.code = 'DISPATCHER'
  AND p.code IN ('orders.view', 'orders.approve');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON
  (r.code = 'SELLER' AND p.code = 'orders.approve')
  OR (r.code = 'DISPATCHER' AND p.code IN ('shipments.view', 'shipments.dispatch', 'shipments.export'))
  OR (r.code = 'STORE_MANAGER' AND p.code IN ('orders.approve', 'shipments.dispatch', 'shipments.export'))
  OR (r.code = 'CITY_MANAGER' AND p.code IN ('orders.approve', 'shipments.dispatch', 'shipments.export'))
  OR (r.code = 'ADMIN' AND p.code IN ('orders.approve', 'shipments.dispatch', 'shipments.export'));
