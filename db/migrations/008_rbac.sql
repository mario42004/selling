USE whatsapp_orders;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  scope_level ENUM('STORE','CITY','GLOBAL') NOT NULL DEFAULT 'STORE',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB;

ALTER TABLE roles
  ADD COLUMN IF NOT EXISTS scope_level ENUM('STORE','CITY','GLOBAL') NOT NULL DEFAULT 'STORE' AFTER name;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(100) NOT NULL,
  name VARCHAR(180) NOT NULL,
  description VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  KEY idx_user_roles_role (role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO roles (code, name, scope_level, active) VALUES
  ('SELLER', 'Vendedor', 'STORE', TRUE),
  ('PROVIDER', 'Proveedor', 'STORE', TRUE),
  ('DISPATCHER', 'Despachador', 'STORE', TRUE),
  ('STORE_MANAGER', 'Gerente de tienda', 'STORE', TRUE),
  ('CITY_MANAGER', 'Gerente de ciudad', 'CITY', TRUE),
  ('ADMIN', 'Admin global', 'GLOBAL', TRUE)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO permissions (code, name, description) VALUES
  ('catalog.write', 'Administrar catálogo', 'Crear, editar, activar, desactivar y eliminar productos dentro del alcance asignado.'),
  ('inventory.view', 'Consultar inventario', 'Consultar existencias dentro del alcance asignado.'),
  ('inventory.restock', 'Alimentar inventario', 'Sumar unidades y registrar entradas de mercancía dentro del alcance asignado.'),
  ('inventory.transfer', 'Trasladar inventario', 'Mover existencias entre tiendas permitidas.'),
  ('orders.view', 'Consultar pedidos', 'Consultar pedidos dentro del alcance asignado.'),
  ('orders.approve', 'Gestionar pedidos', 'Aprobar, rechazar y despachar pedidos.'),
  ('orders.cancel', 'Cancelar pedidos', 'Cancelar pedidos sin eliminarlos y restaurar stock cuando corresponda.'),
  ('orders.delete', 'Eliminar pedidos', 'Eliminar pedidos definitivamente y restaurar stock cuando corresponda.'),
  ('shipments.view', 'Consultar envíos', 'Consultar los envíos dentro del alcance asignado.'),
  ('stats.view', 'Consultar estadísticas', 'Consultar estadísticas de ventas e inventario.'),
  ('users.manage', 'Administrar usuarios', 'Crear y editar usuarios, roles y asignaciones territoriales.'),
  ('locations.manage', 'Administrar ubicaciones', 'Crear y editar ciudades, zonas y tiendas.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON
  (r.code = 'SELLER' AND p.code IN ('orders.view'))
  OR (r.code = 'PROVIDER' AND p.code IN ('catalog.write', 'inventory.view', 'inventory.restock'))
  OR (r.code = 'DISPATCHER' AND p.code IN ('orders.view', 'orders.approve', 'shipments.view'))
  OR (r.code = 'STORE_MANAGER' AND p.code IN ('catalog.write', 'inventory.view', 'inventory.restock', 'orders.view', 'orders.cancel', 'shipments.view', 'stats.view'))
  OR (r.code = 'CITY_MANAGER' AND p.code IN ('catalog.write', 'inventory.view', 'inventory.restock', 'inventory.transfer', 'orders.view', 'orders.approve', 'orders.cancel', 'shipments.view', 'stats.view'))
  OR (r.code = 'ADMIN')
WHERE NOT EXISTS (SELECT 1 FROM role_permissions);

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
JOIN roles r ON r.code = u.role
WHERE NOT EXISTS (SELECT 1 FROM user_roles);

ALTER TABLE users
  MODIFY role VARCHAR(64) NOT NULL;
