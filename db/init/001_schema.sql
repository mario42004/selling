CREATE DATABASE IF NOT EXISTS whatsapp_orders
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE whatsapp_orders;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  aliases JSON NOT NULL,
  unit VARCHAR(32) NOT NULL DEFAULT 'unidad',
  price DECIMAL(10,2) NOT NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  image_url VARCHAR(1000) NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  CONSTRAINT chk_products_price CHECK (price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  external_message_id VARCHAR(191) NULL,
  customer_name VARCHAR(255) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  delivery_address VARCHAR(500) NOT NULL,
  raw_message TEXT NOT NULL,
  status ENUM('PENDING_PAYMENT','CONFIRMED','REJECTED','CANCELLED','DISPATCHED') NOT NULL DEFAULT 'PENDING_PAYMENT',
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_confirmed_by VARCHAR(255) NULL,
  payment_confirmed_at DATETIME NULL,
  logistics_notified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_external_message (external_message_id),
  KEY idx_orders_status_created (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(64) NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  image_url VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT chk_order_items_quantity CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor VARCHAR(255) NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_events_order (order_id),
  CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

INSERT INTO products (sku, name, aliases, unit, price, stock, image_url)
VALUES
  ('COCA-2L', 'Coca-Cola 2 L', JSON_ARRAY('coca cola grande', 'coca-cola grande', 'cocacola grande', 'coca cola 2l', 'coca-cola 2 l'), 'botella', 2.75, 30, 'https://placehold.co/600x400?text=Coca-Cola+2L'),
  ('HIELO-2KG', 'Bolsa de hielo 2 kg', JSON_ARRAY('bolsa de hielo', 'bolsas de hielo', 'hielo 2kg', 'hielo'), 'bolsa', 2.50, 40, 'https://placehold.co/600x400?text=Hielo+2kg'),
  ('RON-BARCELO-70', 'Ron Barceló 70 cl', JSON_ARRAY('ron barcelo', 'barcelo', 'ron dominicano'), 'botella', 18.90, 12, 'https://placehold.co/600x400?text=Ron+Barcelo'),
  ('AGUA-15L', 'Agua mineral 1,5 L', JSON_ARRAY('agua grande', 'agua 1,5l', 'agua mineral'), 'botella', 1.20, 60, 'https://placehold.co/600x400?text=Agua+1.5L')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  aliases = VALUES(aliases),
  unit = VALUES(unit),
  price = VALUES(price),
  image_url = VALUES(image_url);

DELIMITER //

DROP PROCEDURE IF EXISTS create_draft_order//
CREATE PROCEDURE create_draft_order(IN p_payload LONGTEXT)
proc: BEGIN
  DECLARE v_order_id BIGINT UNSIGNED;
  DECLARE v_existing_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_external_id VARCHAR(191);
  DECLARE v_customer_name VARCHAR(255);
  DECLARE v_phone VARCHAR(40);
  DECLARE v_address VARCHAR(500);
  DECLARE v_raw_message TEXT;
  DECLARE v_items_count INT DEFAULT 0;
  DECLARE v_index INT DEFAULT 0;
  DECLARE v_sku VARCHAR(64);
  DECLARE v_quantity INT;
  DECLARE v_product_id BIGINT UNSIGNED;
  DECLARE v_product_name VARCHAR(255);
  DECLARE v_price DECIMAL(10,2);
  DECLARE v_stock INT;
  DECLARE v_image_url VARCHAR(1000);

  IF JSON_VALID(p_payload) = 0 THEN
    SELECT JSON_OBJECT('ok', FALSE, 'error', 'El payload no es JSON válido') AS result;
    LEAVE proc;
  END IF;

  SET v_external_id = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_payload, '$.external_message_id')), '');
  SET v_customer_name = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_payload, '$.customer_name')), '');
  SET v_phone = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_payload, '$.phone')), '');
  SET v_address = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_payload, '$.address')), '');
  SET v_raw_message = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_payload, '$.raw_message')), '');
  SET v_items_count = COALESCE(JSON_LENGTH(JSON_EXTRACT(p_payload, '$.items')), 0);

  IF v_customer_name IS NULL OR v_phone IS NULL OR v_address IS NULL OR v_raw_message IS NULL THEN
    SELECT JSON_OBJECT('ok', FALSE, 'error', 'Faltan customer_name, phone, address o raw_message') AS result;
    LEAVE proc;
  END IF;

  IF v_items_count = 0 THEN
    SELECT JSON_OBJECT('ok', FALSE, 'error', 'No se reconoció ningún producto') AS result;
    LEAVE proc;
  END IF;

  IF v_external_id IS NOT NULL THEN
    SELECT MAX(id) INTO v_existing_id FROM orders WHERE external_message_id = v_external_id;
    IF v_existing_id IS NOT NULL THEN
      SELECT JSON_OBJECT('ok', TRUE, 'idempotent', TRUE, 'order_id', v_existing_id, 'status', status, 'total', total)
      AS result FROM orders WHERE id = v_existing_id;
      LEAVE proc;
    END IF;
  END IF;

  START TRANSACTION;

  WHILE v_index < v_items_count DO
    SET v_sku = JSON_UNQUOTE(JSON_EXTRACT(p_payload, CONCAT('$.items[', v_index, '].sku')));
    SET v_quantity = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_payload, CONCAT('$.items[', v_index, '].quantity'))) AS UNSIGNED);
    SET v_product_id = NULL;

    SELECT id, name, price, stock, image_url
      INTO v_product_id, v_product_name, v_price, v_stock, v_image_url
      FROM products
      WHERE sku = v_sku AND active = TRUE
      LIMIT 1 FOR UPDATE;

    IF v_product_id IS NULL THEN
      ROLLBACK;
      SELECT JSON_OBJECT('ok', FALSE, 'error', CONCAT('Producto no encontrado: ', COALESCE(v_sku, '(vacío)'))) AS result;
      LEAVE proc;
    END IF;

    IF v_quantity IS NULL OR v_quantity < 1 OR v_stock < v_quantity THEN
      ROLLBACK;
      SELECT JSON_OBJECT('ok', FALSE, 'error', CONCAT('Stock insuficiente para ', v_product_name), 'available', v_stock) AS result;
      LEAVE proc;
    END IF;

    SET v_index = v_index + 1;
  END WHILE;

  INSERT INTO orders (external_message_id, customer_name, phone, delivery_address, raw_message)
  VALUES (v_external_id, v_customer_name, v_phone, v_address, v_raw_message);
  SET v_order_id = LAST_INSERT_ID();
  SET v_index = 0;

  WHILE v_index < v_items_count DO
    SET v_sku = JSON_UNQUOTE(JSON_EXTRACT(p_payload, CONCAT('$.items[', v_index, '].sku')));
    SET v_quantity = CAST(JSON_UNQUOTE(JSON_EXTRACT(p_payload, CONCAT('$.items[', v_index, '].quantity'))) AS UNSIGNED);

    SELECT id, name, price, image_url
      INTO v_product_id, v_product_name, v_price, v_image_url
      FROM products WHERE sku = v_sku LIMIT 1;

    INSERT INTO order_items (order_id, product_id, sku, product_name, quantity, unit_price, image_url)
    VALUES (v_order_id, v_product_id, v_sku, v_product_name, v_quantity, v_price, v_image_url);

    SET v_index = v_index + 1;
  END WHILE;

  UPDATE orders
  SET total = (SELECT SUM(quantity * unit_price) FROM order_items WHERE order_id = v_order_id)
  WHERE id = v_order_id;

  INSERT INTO order_events (order_id, event_type, actor, details)
  VALUES (v_order_id, 'DRAFT_CREATED', 'n8n-local', JSON_OBJECT('source', 'local-webhook'));

  COMMIT;

  SELECT JSON_OBJECT(
    'ok', TRUE,
    'order_id', o.id,
    'status', o.status,
    'total', o.total,
    'customer_name', o.customer_name,
    'phone', o.phone,
    'address', o.delivery_address,
    'items', (
      SELECT JSON_ARRAYAGG(JSON_OBJECT(
        'sku', oi.sku,
        'name', oi.product_name,
        'quantity', oi.quantity,
        'unit_price', oi.unit_price,
        'image_url', oi.image_url
      )) FROM order_items oi WHERE oi.order_id = o.id
    )
  ) AS result
  FROM orders o WHERE o.id = v_order_id;
END//

DROP PROCEDURE IF EXISTS approve_order//
CREATE PROCEDURE approve_order(IN p_order_id BIGINT UNSIGNED, IN p_approved_by VARCHAR(255))
proc: BEGIN
  DECLARE v_status VARCHAR(32);
  DECLARE v_missing INT DEFAULT 0;

  START TRANSACTION;
  SELECT MAX(status) INTO v_status FROM orders WHERE id = p_order_id FOR UPDATE;

  IF v_status IS NULL THEN
    ROLLBACK;
    SELECT JSON_OBJECT('ok', FALSE, 'error', 'Pedido no encontrado') AS result;
    LEAVE proc;
  END IF;

  IF v_status = 'CONFIRMED' THEN
    COMMIT;
    SELECT JSON_OBJECT('ok', TRUE, 'idempotent', TRUE, 'order_id', id, 'status', status, 'total', total) AS result
    FROM orders WHERE id = p_order_id;
    LEAVE proc;
  END IF;

  IF v_status <> 'PENDING_PAYMENT' THEN
    ROLLBACK;
    SELECT JSON_OBJECT('ok', FALSE, 'error', CONCAT('El pedido está en estado ', v_status)) AS result;
    LEAVE proc;
  END IF;

  SELECT COUNT(*) INTO v_missing
  FROM order_items oi
  JOIN products p ON p.id = oi.product_id
  WHERE oi.order_id = p_order_id AND p.stock < oi.quantity;

  IF v_missing > 0 THEN
    ROLLBACK;
    SELECT JSON_OBJECT('ok', FALSE, 'error', 'El inventario cambió y ya no hay stock suficiente') AS result;
    LEAVE proc;
  END IF;

  UPDATE products p
  JOIN order_items oi ON oi.product_id = p.id AND oi.order_id = p_order_id
  SET p.stock = p.stock - oi.quantity;

  UPDATE orders
  SET status = 'CONFIRMED',
      payment_confirmed_by = p_approved_by,
      payment_confirmed_at = NOW()
  WHERE id = p_order_id;

  INSERT INTO order_events (order_id, event_type, actor, details)
  VALUES (p_order_id, 'PAYMENT_CONFIRMED', p_approved_by, JSON_OBJECT('stock_discounted', TRUE));

  COMMIT;

  SELECT JSON_OBJECT(
    'ok', TRUE,
    'order_id', id,
    'status', status,
    'total', total,
    'customer_name', customer_name,
    'phone', phone,
    'address', delivery_address
  ) AS result FROM orders WHERE id = p_order_id;
END//

DELIMITER ;

CREATE OR REPLACE VIEW daily_confirmed_orders AS
SELECT
  o.id AS order_id,
  o.customer_name,
  o.phone,
  o.delivery_address,
  o.total,
  o.created_at,
  o.payment_confirmed_at,
  GROUP_CONCAT(CONCAT(oi.quantity, ' x ', oi.product_name) ORDER BY oi.id SEPARATOR '\n') AS items
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.status = 'CONFIRMED'
GROUP BY o.id, o.customer_name, o.phone, o.delivery_address, o.total, o.created_at, o.payment_confirmed_at;

