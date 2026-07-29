USE whatsapp_orders;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) NOT NULL,
  operation ENUM('INSERT','UPDATE','DELETE','AUTH') NOT NULL,
  action_name VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id VARCHAR(100) NULL,
  city_id BIGINT UNSIGNED NULL,
  store_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_email VARCHAR(255) NULL,
  actor_name VARCHAR(255) NULL,
  actor_roles VARCHAR(1000) NULL,
  source VARCHAR(64) NOT NULL DEFAULT 'database',
  ip_address VARCHAR(64) NULL,
  before_data JSON NULL,
  after_data JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_actor_created (actor_user_id, created_at),
  KEY idx_audit_entity_created (entity_type, entity_id, created_at),
  KEY idx_audit_store_created (store_id, created_at),
  KEY idx_audit_city_created (city_id, created_at),
  KEY idx_audit_action_created (action_name, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) NOT NULL,
  movement_type VARCHAR(64) NOT NULL,
  city_id BIGINT UNSIGNED NULL,
  store_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity_delta INT NOT NULL,
  balance_before INT UNSIGNED NOT NULL,
  balance_after INT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_email VARCHAR(255) NULL,
  source VARCHAR(64) NOT NULL DEFAULT 'database',
  notes VARCHAR(500) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_inventory_movements_created (created_at),
  KEY idx_inventory_movements_store_created (store_id, created_at),
  KEY idx_inventory_movements_product_created (product_id, created_at),
  KEY idx_inventory_movements_request (request_id)
) ENGINE=InnoDB;

INSERT INTO permissions (code, name, description) VALUES
  ('audit.view', 'Consultar trazabilidad', 'Consultar el registro global e inmutable de cambios del sistema.'),
  ('reports.view', 'Consultar reportes', 'Consultar reportes de ventas e inventario dentro del alcance territorial.'),
  ('reports.export', 'Exportar reportes', 'Descargar reportes Excel dentro del alcance territorial.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON
  (r.code = 'ADMIN' AND p.code IN ('audit.view', 'reports.view', 'reports.export'))
  OR (r.code IN ('CITY_MANAGER', 'STORE_MANAGER') AND p.code IN ('reports.view', 'reports.export'));

DELIMITER //

DROP PROCEDURE IF EXISTS append_audit//
CREATE PROCEDURE append_audit(
  IN p_operation VARCHAR(10), IN p_entity_type VARCHAR(100), IN p_entity_id VARCHAR(100),
  IN p_city_id BIGINT UNSIGNED, IN p_store_id BIGINT UNSIGNED,
  IN p_before_data LONGTEXT, IN p_after_data LONGTEXT
)
BEGIN
  INSERT INTO audit_log (
    request_id, operation, action_name, entity_type, entity_id, city_id, store_id,
    actor_user_id, actor_email, actor_name, actor_roles, source, ip_address,
    before_data, after_data
  ) VALUES (
    COALESCE(NULLIF(CONCAT(@audit_request_id), ''), CONCAT(UUID())), p_operation,
    COALESCE(@audit_action, CONCAT('DB_', p_operation)), p_entity_type, p_entity_id,
    p_city_id, p_store_id, @audit_actor_user_id, @audit_actor_email,
    @audit_actor_name, @audit_actor_roles, COALESCE(@audit_source, 'database'),
    @audit_ip, p_before_data, p_after_data
  );
END//

DROP TRIGGER IF EXISTS audit_log_no_update//
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de auditoría es inmutable'; END//
DROP TRIGGER IF EXISTS audit_log_no_delete//
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El registro de auditoría es inmutable'; END//

DROP TRIGGER IF EXISTS audit_users_insert//
CREATE TRIGGER audit_users_insert AFTER INSERT ON users FOR EACH ROW
BEGIN CALL append_audit('INSERT','users',NEW.id,NULL,NULL,NULL,JSON_OBJECT('name',NEW.name,'email',NEW.email,'role',NEW.role,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_users_update//
CREATE TRIGGER audit_users_update AFTER UPDATE ON users FOR EACH ROW
BEGIN CALL append_audit('UPDATE','users',NEW.id,NULL,NULL,JSON_OBJECT('name',OLD.name,'email',OLD.email,'role',OLD.role,'active',OLD.active),JSON_OBJECT('name',NEW.name,'email',NEW.email,'role',NEW.role,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_users_delete//
CREATE TRIGGER audit_users_delete AFTER DELETE ON users FOR EACH ROW
BEGIN CALL append_audit('DELETE','users',OLD.id,NULL,NULL,JSON_OBJECT('name',OLD.name,'email',OLD.email,'role',OLD.role,'active',OLD.active),NULL); END//

DROP TRIGGER IF EXISTS audit_user_roles_insert//
CREATE TRIGGER audit_user_roles_insert AFTER INSERT ON user_roles FOR EACH ROW
BEGIN CALL append_audit('INSERT','user_roles',CONCAT(NEW.user_id,':',NEW.role_id),NULL,NULL,NULL,JSON_OBJECT('user_id',NEW.user_id,'role_id',NEW.role_id)); END//
DROP TRIGGER IF EXISTS audit_user_roles_delete//
CREATE TRIGGER audit_user_roles_delete AFTER DELETE ON user_roles FOR EACH ROW
BEGIN CALL append_audit('DELETE','user_roles',CONCAT(OLD.user_id,':',OLD.role_id),NULL,NULL,JSON_OBJECT('user_id',OLD.user_id,'role_id',OLD.role_id),NULL); END//
DROP TRIGGER IF EXISTS audit_user_city_insert//
CREATE TRIGGER audit_user_city_insert AFTER INSERT ON user_city_assignments FOR EACH ROW
BEGIN CALL append_audit('INSERT','user_city_assignments',NEW.id,NEW.city_id,NULL,NULL,JSON_OBJECT('user_id',NEW.user_id,'city_id',NEW.city_id)); END//
DROP TRIGGER IF EXISTS audit_user_city_delete//
CREATE TRIGGER audit_user_city_delete AFTER DELETE ON user_city_assignments FOR EACH ROW
BEGIN CALL append_audit('DELETE','user_city_assignments',OLD.id,OLD.city_id,NULL,JSON_OBJECT('user_id',OLD.user_id,'city_id',OLD.city_id),NULL); END//
DROP TRIGGER IF EXISTS audit_user_store_insert//
CREATE TRIGGER audit_user_store_insert AFTER INSERT ON user_store_assignments FOR EACH ROW
BEGIN CALL append_audit('INSERT','user_store_assignments',NEW.id,(SELECT city_id FROM stores WHERE id=NEW.store_id),NEW.store_id,NULL,JSON_OBJECT('user_id',NEW.user_id,'store_id',NEW.store_id)); END//
DROP TRIGGER IF EXISTS audit_user_store_delete//
CREATE TRIGGER audit_user_store_delete AFTER DELETE ON user_store_assignments FOR EACH ROW
BEGIN CALL append_audit('DELETE','user_store_assignments',OLD.id,(SELECT city_id FROM stores WHERE id=OLD.store_id),OLD.store_id,JSON_OBJECT('user_id',OLD.user_id,'store_id',OLD.store_id),NULL); END//

DROP TRIGGER IF EXISTS audit_cities_insert//
CREATE TRIGGER audit_cities_insert AFTER INSERT ON cities FOR EACH ROW
BEGIN CALL append_audit('INSERT','cities',NEW.id,NEW.id,NULL,NULL,JSON_OBJECT('name',NEW.name,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_cities_update//
CREATE TRIGGER audit_cities_update AFTER UPDATE ON cities FOR EACH ROW
BEGIN CALL append_audit('UPDATE','cities',NEW.id,NEW.id,NULL,JSON_OBJECT('name',OLD.name,'active',OLD.active),JSON_OBJECT('name',NEW.name,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_zones_insert//
CREATE TRIGGER audit_zones_insert AFTER INSERT ON zones FOR EACH ROW
BEGIN CALL append_audit('INSERT','zones',NEW.id,NEW.city_id,NULL,NULL,JSON_OBJECT('city_id',NEW.city_id,'name',NEW.name,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_zones_update//
CREATE TRIGGER audit_zones_update AFTER UPDATE ON zones FOR EACH ROW
BEGIN CALL append_audit('UPDATE','zones',NEW.id,NEW.city_id,NULL,JSON_OBJECT('city_id',OLD.city_id,'name',OLD.name,'active',OLD.active),JSON_OBJECT('city_id',NEW.city_id,'name',NEW.name,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_stores_insert//
CREATE TRIGGER audit_stores_insert AFTER INSERT ON stores FOR EACH ROW
BEGIN CALL append_audit('INSERT','stores',NEW.id,NEW.city_id,NEW.id,NULL,JSON_OBJECT('city_id',NEW.city_id,'zone_id',NEW.zone_id,'name',NEW.name,'address',NEW.address,'phone',NEW.phone,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_stores_update//
CREATE TRIGGER audit_stores_update AFTER UPDATE ON stores FOR EACH ROW
BEGIN CALL append_audit('UPDATE','stores',NEW.id,NEW.city_id,NEW.id,JSON_OBJECT('city_id',OLD.city_id,'zone_id',OLD.zone_id,'name',OLD.name,'address',OLD.address,'phone',OLD.phone,'active',OLD.active),JSON_OBJECT('city_id',NEW.city_id,'zone_id',NEW.zone_id,'name',NEW.name,'address',NEW.address,'phone',NEW.phone,'active',NEW.active)); END//

DROP TRIGGER IF EXISTS audit_products_insert//
CREATE TRIGGER audit_products_insert AFTER INSERT ON products FOR EACH ROW
BEGIN CALL append_audit('INSERT','products',NEW.id,(SELECT city_id FROM stores WHERE id=NEW.store_id),NEW.store_id,NULL,JSON_OBJECT('sku',NEW.sku,'name',NEW.name,'category',NEW.category,'supplier_net_price',NEW.supplier_net_price,'supplier_vat_rate',NEW.supplier_vat_rate,'supplier_vat_amount',NEW.supplier_vat_amount,'supplier_total_price',NEW.supplier_total_price,'price',NEW.price,'sale_price',NEW.sale_price,'stock',NEW.stock,'active',NEW.active,'deleted_at',NEW.deleted_at)); END//
DROP TRIGGER IF EXISTS audit_products_update//
CREATE TRIGGER audit_products_update AFTER UPDATE ON products FOR EACH ROW
BEGIN CALL append_audit('UPDATE','products',NEW.id,(SELECT city_id FROM stores WHERE id=NEW.store_id),NEW.store_id,JSON_OBJECT('sku',OLD.sku,'name',OLD.name,'category',OLD.category,'supplier_net_price',OLD.supplier_net_price,'supplier_vat_rate',OLD.supplier_vat_rate,'supplier_vat_amount',OLD.supplier_vat_amount,'supplier_total_price',OLD.supplier_total_price,'price',OLD.price,'sale_price',OLD.sale_price,'stock',OLD.stock,'active',OLD.active,'deleted_at',OLD.deleted_at),JSON_OBJECT('sku',NEW.sku,'name',NEW.name,'category',NEW.category,'supplier_net_price',NEW.supplier_net_price,'supplier_vat_rate',NEW.supplier_vat_rate,'supplier_vat_amount',NEW.supplier_vat_amount,'supplier_total_price',NEW.supplier_total_price,'price',NEW.price,'sale_price',NEW.sale_price,'stock',NEW.stock,'active',NEW.active,'deleted_at',NEW.deleted_at)); END//
DROP TRIGGER IF EXISTS audit_products_delete//
CREATE TRIGGER audit_products_delete AFTER DELETE ON products FOR EACH ROW
BEGIN CALL append_audit('DELETE','products',OLD.id,(SELECT city_id FROM stores WHERE id=OLD.store_id),OLD.store_id,JSON_OBJECT('sku',OLD.sku,'name',OLD.name,'price',OLD.price,'stock',OLD.stock),NULL); END//

DROP TRIGGER IF EXISTS audit_variants_insert//
CREATE TRIGGER audit_variants_insert AFTER INSERT ON product_variants FOR EACH ROW
BEGIN
  CALL append_audit('INSERT','product_variants',NEW.id,(SELECT s.city_id FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=NEW.product_id),(SELECT store_id FROM products WHERE id=NEW.product_id),NULL,JSON_OBJECT('product_id',NEW.product_id,'sku',NEW.sku,'size',NEW.size,'stock',NEW.stock,'reserved_stock',NEW.reserved_stock,'active',NEW.active));
  IF NEW.stock <> 0 THEN INSERT INTO inventory_movements(request_id,movement_type,city_id,store_id,product_id,variant_id,quantity_delta,balance_before,balance_after,actor_user_id,actor_email,source,notes) SELECT COALESCE(NULLIF(CONCAT(@audit_request_id),''),CONCAT(UUID())),COALESCE(@inventory_movement_type,'INITIAL_STOCK'),s.city_id,p.store_id,NEW.product_id,NEW.id,NEW.stock,0,NEW.stock,@audit_actor_user_id,@audit_actor_email,COALESCE(@audit_source,'database'),@inventory_notes FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=NEW.product_id; END IF;
END//
DROP TRIGGER IF EXISTS audit_variants_update//
CREATE TRIGGER audit_variants_update AFTER UPDATE ON product_variants FOR EACH ROW
BEGIN
  CALL append_audit('UPDATE','product_variants',NEW.id,(SELECT s.city_id FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=NEW.product_id),(SELECT store_id FROM products WHERE id=NEW.product_id),JSON_OBJECT('product_id',OLD.product_id,'sku',OLD.sku,'size',OLD.size,'stock',OLD.stock,'reserved_stock',OLD.reserved_stock,'active',OLD.active),JSON_OBJECT('product_id',NEW.product_id,'sku',NEW.sku,'size',NEW.size,'stock',NEW.stock,'reserved_stock',NEW.reserved_stock,'active',NEW.active));
  IF NEW.stock <> OLD.stock THEN INSERT INTO inventory_movements(request_id,movement_type,city_id,store_id,product_id,variant_id,quantity_delta,balance_before,balance_after,actor_user_id,actor_email,source,notes) SELECT COALESCE(NULLIF(CONCAT(@audit_request_id),''),CONCAT(UUID())),COALESCE(@inventory_movement_type,'STOCK_ADJUSTMENT'),s.city_id,p.store_id,NEW.product_id,NEW.id,CAST(NEW.stock AS SIGNED)-CAST(OLD.stock AS SIGNED),OLD.stock,NEW.stock,@audit_actor_user_id,@audit_actor_email,COALESCE(@audit_source,'database'),@inventory_notes FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=NEW.product_id; END IF;
END//
DROP TRIGGER IF EXISTS audit_variants_delete//
CREATE TRIGGER audit_variants_delete AFTER DELETE ON product_variants FOR EACH ROW
BEGIN CALL append_audit('DELETE','product_variants',OLD.id,(SELECT s.city_id FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=OLD.product_id),(SELECT store_id FROM products WHERE id=OLD.product_id),JSON_OBJECT('product_id',OLD.product_id,'sku',OLD.sku,'size',OLD.size,'stock',OLD.stock,'reserved_stock',OLD.reserved_stock,'active',OLD.active),NULL); END//

DROP TRIGGER IF EXISTS audit_inventory_receipts_insert//
CREATE TRIGGER audit_inventory_receipts_insert AFTER INSERT ON inventory_receipts FOR EACH ROW
BEGIN CALL append_audit('INSERT','inventory_receipts',NEW.id,(SELECT city_id FROM stores WHERE id=NEW.store_id),NEW.store_id,NULL,JSON_OBJECT('product_id',NEW.product_id,'variant_id',NEW.variant_id,'quantity',NEW.quantity,'actor_user_id',NEW.actor_user_id,'notes',NEW.notes)); END//
DROP TRIGGER IF EXISTS audit_stock_transfers_insert//
CREATE TRIGGER audit_stock_transfers_insert AFTER INSERT ON stock_transfers FOR EACH ROW
BEGIN CALL append_audit('INSERT','stock_transfers',NEW.id,(SELECT city_id FROM stores WHERE id=NEW.from_store_id),NEW.from_store_id,NULL,JSON_OBJECT('from_store_id',NEW.from_store_id,'to_store_id',NEW.to_store_id,'from_product_id',NEW.from_product_id,'to_product_id',NEW.to_product_id,'quantity',NEW.quantity,'actor_user_id',NEW.actor_user_id,'notes',NEW.notes)); END//

DROP TRIGGER IF EXISTS audit_orders_insert//
CREATE TRIGGER audit_orders_insert AFTER INSERT ON orders FOR EACH ROW
BEGIN CALL append_audit('INSERT','orders',NEW.id,NEW.city_id,NEW.store_id,NULL,JSON_OBJECT('customer_name',NEW.customer_name,'phone',NEW.phone,'delivery_address',NEW.delivery_address,'status',NEW.status,'total',NEW.total)); END//
DROP TRIGGER IF EXISTS audit_orders_update//
CREATE TRIGGER audit_orders_update AFTER UPDATE ON orders FOR EACH ROW
BEGIN CALL append_audit('UPDATE','orders',NEW.id,NEW.city_id,NEW.store_id,JSON_OBJECT('status',OLD.status,'total',OLD.total,'payment_confirmed_at',OLD.payment_confirmed_at,'logistics_notified_at',OLD.logistics_notified_at),JSON_OBJECT('status',NEW.status,'total',NEW.total,'payment_confirmed_at',NEW.payment_confirmed_at,'logistics_notified_at',NEW.logistics_notified_at)); END//
DROP TRIGGER IF EXISTS audit_orders_delete//
CREATE TRIGGER audit_orders_delete AFTER DELETE ON orders FOR EACH ROW
BEGIN CALL append_audit('DELETE','orders',OLD.id,OLD.city_id,OLD.store_id,JSON_OBJECT('customer_name',OLD.customer_name,'phone',OLD.phone,'status',OLD.status,'total',OLD.total),NULL); END//
DROP TRIGGER IF EXISTS audit_order_items_insert//
CREATE TRIGGER audit_order_items_insert AFTER INSERT ON order_items FOR EACH ROW
BEGIN CALL append_audit('INSERT','order_items',NEW.id,(SELECT city_id FROM orders WHERE id=NEW.order_id),(SELECT store_id FROM orders WHERE id=NEW.order_id),NULL,JSON_OBJECT('order_id',NEW.order_id,'product_id',NEW.product_id,'variant_id',NEW.variant_id,'sku',NEW.sku,'quantity',NEW.quantity,'unit_price',NEW.unit_price)); END//
DROP TRIGGER IF EXISTS audit_order_items_update//
CREATE TRIGGER audit_order_items_update AFTER UPDATE ON order_items FOR EACH ROW
BEGIN CALL append_audit('UPDATE','order_items',NEW.id,(SELECT city_id FROM orders WHERE id=NEW.order_id),(SELECT store_id FROM orders WHERE id=NEW.order_id),JSON_OBJECT('quantity',OLD.quantity,'unit_price',OLD.unit_price),JSON_OBJECT('quantity',NEW.quantity,'unit_price',NEW.unit_price)); END//
DROP TRIGGER IF EXISTS audit_order_items_delete//
CREATE TRIGGER audit_order_items_delete AFTER DELETE ON order_items FOR EACH ROW
BEGIN CALL append_audit('DELETE','order_items',OLD.id,(SELECT city_id FROM orders WHERE id=OLD.order_id),(SELECT store_id FROM orders WHERE id=OLD.order_id),JSON_OBJECT('order_id',OLD.order_id,'product_id',OLD.product_id,'variant_id',OLD.variant_id,'sku',OLD.sku,'quantity',OLD.quantity,'unit_price',OLD.unit_price),NULL); END//
DROP TRIGGER IF EXISTS audit_order_events_insert//
CREATE TRIGGER audit_order_events_insert AFTER INSERT ON order_events FOR EACH ROW
BEGIN CALL append_audit('INSERT','order_events',NEW.id,(SELECT city_id FROM orders WHERE id=NEW.order_id),(SELECT store_id FROM orders WHERE id=NEW.order_id),NULL,JSON_OBJECT('order_id',NEW.order_id,'event_type',NEW.event_type,'actor',NEW.actor,'details',NEW.details)); END//
DROP TRIGGER IF EXISTS audit_order_events_delete//
CREATE TRIGGER audit_order_events_delete AFTER DELETE ON order_events FOR EACH ROW
BEGIN CALL append_audit('DELETE','order_events',OLD.id,(SELECT city_id FROM orders WHERE id=OLD.order_id),(SELECT store_id FROM orders WHERE id=OLD.order_id),JSON_OBJECT('order_id',OLD.order_id,'event_type',OLD.event_type,'actor',OLD.actor,'details',OLD.details),NULL); END//

DROP TRIGGER IF EXISTS audit_roles_insert//
CREATE TRIGGER audit_roles_insert AFTER INSERT ON roles FOR EACH ROW
BEGIN CALL append_audit('INSERT','roles',NEW.id,NULL,NULL,NULL,JSON_OBJECT('code',NEW.code,'name',NEW.name,'scope_level',NEW.scope_level,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_roles_update//
CREATE TRIGGER audit_roles_update AFTER UPDATE ON roles FOR EACH ROW
BEGIN CALL append_audit('UPDATE','roles',NEW.id,NULL,NULL,JSON_OBJECT('code',OLD.code,'name',OLD.name,'scope_level',OLD.scope_level,'active',OLD.active),JSON_OBJECT('code',NEW.code,'name',NEW.name,'scope_level',NEW.scope_level,'active',NEW.active)); END//
DROP TRIGGER IF EXISTS audit_roles_delete//
CREATE TRIGGER audit_roles_delete AFTER DELETE ON roles FOR EACH ROW
BEGIN CALL append_audit('DELETE','roles',OLD.id,NULL,NULL,JSON_OBJECT('code',OLD.code,'name',OLD.name,'scope_level',OLD.scope_level,'active',OLD.active),NULL); END//
DROP TRIGGER IF EXISTS audit_permissions_insert//
CREATE TRIGGER audit_permissions_insert AFTER INSERT ON permissions FOR EACH ROW
BEGIN CALL append_audit('INSERT','permissions',NEW.id,NULL,NULL,NULL,JSON_OBJECT('code',NEW.code,'name',NEW.name,'description',NEW.description)); END//
DROP TRIGGER IF EXISTS audit_permissions_update//
CREATE TRIGGER audit_permissions_update AFTER UPDATE ON permissions FOR EACH ROW
BEGIN CALL append_audit('UPDATE','permissions',NEW.id,NULL,NULL,JSON_OBJECT('code',OLD.code,'name',OLD.name,'description',OLD.description),JSON_OBJECT('code',NEW.code,'name',NEW.name,'description',NEW.description)); END//
DROP TRIGGER IF EXISTS audit_permissions_delete//
CREATE TRIGGER audit_permissions_delete AFTER DELETE ON permissions FOR EACH ROW
BEGIN CALL append_audit('DELETE','permissions',OLD.id,NULL,NULL,JSON_OBJECT('code',OLD.code,'name',OLD.name,'description',OLD.description),NULL); END//
DROP TRIGGER IF EXISTS audit_role_permissions_insert//
CREATE TRIGGER audit_role_permissions_insert AFTER INSERT ON role_permissions FOR EACH ROW
BEGIN CALL append_audit('INSERT','role_permissions',CONCAT(NEW.role_id,':',NEW.permission_id),NULL,NULL,NULL,JSON_OBJECT('role_id',NEW.role_id,'permission_id',NEW.permission_id)); END//
DROP TRIGGER IF EXISTS audit_role_permissions_delete//
CREATE TRIGGER audit_role_permissions_delete AFTER DELETE ON role_permissions FOR EACH ROW
BEGIN CALL append_audit('DELETE','role_permissions',CONCAT(OLD.role_id,':',OLD.permission_id),NULL,NULL,JSON_OBJECT('role_id',OLD.role_id,'permission_id',OLD.permission_id),NULL); END//

DROP TRIGGER IF EXISTS audit_cities_delete//
CREATE TRIGGER audit_cities_delete AFTER DELETE ON cities FOR EACH ROW
BEGIN CALL append_audit('DELETE','cities',OLD.id,OLD.id,NULL,JSON_OBJECT('name',OLD.name,'active',OLD.active),NULL); END//
DROP TRIGGER IF EXISTS audit_zones_delete//
CREATE TRIGGER audit_zones_delete AFTER DELETE ON zones FOR EACH ROW
BEGIN CALL append_audit('DELETE','zones',OLD.id,OLD.city_id,NULL,JSON_OBJECT('city_id',OLD.city_id,'name',OLD.name,'active',OLD.active),NULL); END//
DROP TRIGGER IF EXISTS audit_stores_delete//
CREATE TRIGGER audit_stores_delete AFTER DELETE ON stores FOR EACH ROW
BEGIN CALL append_audit('DELETE','stores',OLD.id,OLD.city_id,OLD.id,JSON_OBJECT('city_id',OLD.city_id,'zone_id',OLD.zone_id,'name',OLD.name,'address',OLD.address,'phone',OLD.phone,'active',OLD.active),NULL); END//

DROP TRIGGER IF EXISTS audit_product_images_insert//
CREATE TRIGGER audit_product_images_insert AFTER INSERT ON product_images FOR EACH ROW
BEGIN CALL append_audit('INSERT','product_images',NEW.id,(SELECT s.city_id FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=NEW.product_id),(SELECT store_id FROM products WHERE id=NEW.product_id),NULL,JSON_OBJECT('product_id',NEW.product_id,'image_path',NEW.image_path,'image_url',NEW.image_url,'is_primary',NEW.is_primary)); END//
DROP TRIGGER IF EXISTS audit_product_images_update//
CREATE TRIGGER audit_product_images_update AFTER UPDATE ON product_images FOR EACH ROW
BEGIN CALL append_audit('UPDATE','product_images',NEW.id,(SELECT s.city_id FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=NEW.product_id),(SELECT store_id FROM products WHERE id=NEW.product_id),JSON_OBJECT('image_path',OLD.image_path,'image_url',OLD.image_url,'is_primary',OLD.is_primary),JSON_OBJECT('image_path',NEW.image_path,'image_url',NEW.image_url,'is_primary',NEW.is_primary)); END//
DROP TRIGGER IF EXISTS audit_product_images_delete//
CREATE TRIGGER audit_product_images_delete AFTER DELETE ON product_images FOR EACH ROW
BEGIN CALL append_audit('DELETE','product_images',OLD.id,(SELECT s.city_id FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=OLD.product_id),(SELECT store_id FROM products WHERE id=OLD.product_id),JSON_OBJECT('product_id',OLD.product_id,'image_path',OLD.image_path,'image_url',OLD.image_url,'is_primary',OLD.is_primary),NULL); END//

DROP TRIGGER IF EXISTS audit_conversations_insert//
CREATE TRIGGER audit_conversations_insert AFTER INSERT ON conversations FOR EACH ROW
BEGIN CALL append_audit('INSERT','conversations',NEW.id,NULL,NULL,NULL,JSON_OBJECT('phone',NEW.phone,'customer_name',NEW.customer_name,'state',NEW.state,'last_intent',NEW.last_intent,'active_order_id',NEW.active_order_id)); END//
DROP TRIGGER IF EXISTS audit_conversations_update//
CREATE TRIGGER audit_conversations_update AFTER UPDATE ON conversations FOR EACH ROW
BEGIN CALL append_audit('UPDATE','conversations',NEW.id,NULL,(SELECT store_id FROM orders WHERE id=NEW.active_order_id),JSON_OBJECT('phone',OLD.phone,'customer_name',OLD.customer_name,'state',OLD.state,'last_intent',OLD.last_intent,'active_order_id',OLD.active_order_id),JSON_OBJECT('phone',NEW.phone,'customer_name',NEW.customer_name,'state',NEW.state,'last_intent',NEW.last_intent,'active_order_id',NEW.active_order_id)); END//
DROP TRIGGER IF EXISTS audit_conversations_delete//
CREATE TRIGGER audit_conversations_delete AFTER DELETE ON conversations FOR EACH ROW
BEGIN CALL append_audit('DELETE','conversations',OLD.id,NULL,NULL,JSON_OBJECT('phone',OLD.phone,'customer_name',OLD.customer_name,'state',OLD.state,'last_intent',OLD.last_intent,'active_order_id',OLD.active_order_id),NULL); END//
DROP TRIGGER IF EXISTS audit_messages_insert//
CREATE TRIGGER audit_messages_insert AFTER INSERT ON conversation_messages FOR EACH ROW
BEGIN CALL append_audit('INSERT','conversation_messages',NEW.id,NULL,NULL,NULL,JSON_OBJECT('conversation_id',NEW.conversation_id,'direction',NEW.direction,'message_type',NEW.message_type,'body',NEW.body,'whatsapp_message_id',NEW.whatsapp_message_id,'media_id',NEW.media_id,'media_url',NEW.media_url)); END//
DROP TRIGGER IF EXISTS audit_messages_update//
CREATE TRIGGER audit_messages_update AFTER UPDATE ON conversation_messages FOR EACH ROW
BEGIN CALL append_audit('UPDATE','conversation_messages',NEW.id,NULL,NULL,JSON_OBJECT('direction',OLD.direction,'message_type',OLD.message_type,'body',OLD.body,'media_url',OLD.media_url),JSON_OBJECT('direction',NEW.direction,'message_type',NEW.message_type,'body',NEW.body,'media_url',NEW.media_url)); END//
DROP TRIGGER IF EXISTS audit_messages_delete//
CREATE TRIGGER audit_messages_delete AFTER DELETE ON conversation_messages FOR EACH ROW
BEGIN CALL append_audit('DELETE','conversation_messages',OLD.id,NULL,NULL,JSON_OBJECT('conversation_id',OLD.conversation_id,'direction',OLD.direction,'message_type',OLD.message_type,'body',OLD.body,'whatsapp_message_id',OLD.whatsapp_message_id,'media_url',OLD.media_url),NULL); END//

DROP TRIGGER IF EXISTS audit_inventory_receipts_update//
CREATE TRIGGER audit_inventory_receipts_update AFTER UPDATE ON inventory_receipts FOR EACH ROW
BEGIN CALL append_audit('UPDATE','inventory_receipts',NEW.id,(SELECT city_id FROM stores WHERE id=NEW.store_id),NEW.store_id,JSON_OBJECT('product_id',OLD.product_id,'variant_id',OLD.variant_id,'quantity',OLD.quantity,'actor_user_id',OLD.actor_user_id,'notes',OLD.notes),JSON_OBJECT('product_id',NEW.product_id,'variant_id',NEW.variant_id,'quantity',NEW.quantity,'actor_user_id',NEW.actor_user_id,'notes',NEW.notes)); END//
DROP TRIGGER IF EXISTS audit_inventory_receipts_delete//
CREATE TRIGGER audit_inventory_receipts_delete AFTER DELETE ON inventory_receipts FOR EACH ROW
BEGIN CALL append_audit('DELETE','inventory_receipts',OLD.id,(SELECT city_id FROM stores WHERE id=OLD.store_id),OLD.store_id,JSON_OBJECT('product_id',OLD.product_id,'variant_id',OLD.variant_id,'quantity',OLD.quantity,'actor_user_id',OLD.actor_user_id,'notes',OLD.notes),NULL); END//
DROP TRIGGER IF EXISTS audit_stock_transfers_update//
CREATE TRIGGER audit_stock_transfers_update AFTER UPDATE ON stock_transfers FOR EACH ROW
BEGIN CALL append_audit('UPDATE','stock_transfers',NEW.id,(SELECT city_id FROM stores WHERE id=NEW.from_store_id),NEW.from_store_id,JSON_OBJECT('from_store_id',OLD.from_store_id,'to_store_id',OLD.to_store_id,'quantity',OLD.quantity,'actor_user_id',OLD.actor_user_id,'notes',OLD.notes),JSON_OBJECT('from_store_id',NEW.from_store_id,'to_store_id',NEW.to_store_id,'quantity',NEW.quantity,'actor_user_id',NEW.actor_user_id,'notes',NEW.notes)); END//
DROP TRIGGER IF EXISTS audit_stock_transfers_delete//
CREATE TRIGGER audit_stock_transfers_delete AFTER DELETE ON stock_transfers FOR EACH ROW
BEGIN CALL append_audit('DELETE','stock_transfers',OLD.id,(SELECT city_id FROM stores WHERE id=OLD.from_store_id),OLD.from_store_id,JSON_OBJECT('from_store_id',OLD.from_store_id,'to_store_id',OLD.to_store_id,'quantity',OLD.quantity,'actor_user_id',OLD.actor_user_id,'notes',OLD.notes),NULL); END//

DELIMITER ;
