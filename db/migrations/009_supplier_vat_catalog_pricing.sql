USE whatsapp_orders;

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS supplier_net_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit,
  ADD COLUMN IF NOT EXISTS supplier_vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER supplier_net_price,
  ADD COLUMN IF NOT EXISTS supplier_vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER supplier_vat_rate,
  ADD COLUMN IF NOT EXISTS supplier_total_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER supplier_vat_amount;

INSERT INTO permissions (code, name, description) VALUES
  ('catalog.cost', 'Registrar coste del proveedor', 'Registrar el importe neto, IVA y total aportados por el proveedor.'),
  ('catalog.price', 'Administrar precio de venta', 'Modificar el precio normal y el precio promocional publicados en el catálogo.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON
  (r.code = 'PROVIDER' AND p.code = 'catalog.cost')
  OR (r.code IN ('SELLER', 'STORE_MANAGER') AND p.code = 'catalog.price')
  OR (r.code = 'ADMIN' AND p.code IN ('catalog.cost', 'catalog.price'));
