USE whatsapp_orders;

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER name,
  ADD COLUMN IF NOT EXISTS category VARCHAR(120) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS type VARCHAR(120) NULL AFTER category,
  ADD COLUMN IF NOT EXISTS brand VARCHAR(120) NULL AFTER type,
  ADD COLUMN IF NOT EXISTS color VARCHAR(80) NULL AFTER brand,
  ADD COLUMN IF NOT EXISTS gender VARCHAR(80) NULL AFTER color,
  ADD COLUMN IF NOT EXISTS sale_price DECIMAL(10,2) NULL AFTER price;

CREATE TABLE IF NOT EXISTS product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  image_path VARCHAR(1000) NULL,
  image_url VARCHAR(1000) NOT NULL,
  is_primary BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_images_product (product_id),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(64) NOT NULL,
  size VARCHAR(64) NOT NULL DEFAULT 'Única',
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  reserved_stock INT UNSIGNED NOT NULL DEFAULT 0,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_variants_sku (sku),
  KEY idx_product_variants_product (product_id),
  KEY idx_product_variants_size_stock (size, stock),
  CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT chk_product_variants_stock CHECK (stock >= 0),
  CONSTRAINT chk_product_variants_reserved CHECK (reserved_stock >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone VARCHAR(40) NOT NULL,
  customer_name VARCHAR(255) NULL,
  state ENUM('NEW','BROWSING','WAITING_SELECTION','WAITING_DELIVERY_DATA','WAITING_PAYMENT_PROOF','PENDING_OPERATOR_APPROVAL','APPROVED','REJECTED','DISPATCHED','CANCELLED') NOT NULL DEFAULT 'NEW',
  last_intent VARCHAR(80) NULL,
  context_json JSON NULL,
  active_order_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conversations_phone (phone),
  KEY idx_conversations_state (state)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('IN','OUT') NOT NULL,
  message_type VARCHAR(40) NOT NULL,
  body TEXT NULL,
  whatsapp_message_id VARCHAR(191) NULL,
  media_id VARCHAR(191) NULL,
  media_url VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conversation_messages_whatsapp (whatsapp_message_id),
  KEY idx_conversation_messages_conversation (conversation_id, created_at),
  CONSTRAINT fk_conversation_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS conversation_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN IF NOT EXISTS delivery_notes VARCHAR(500) NULL AFTER delivery_address,
  ADD COLUMN IF NOT EXISTS payment_proof_url VARCHAR(1000) NULL AFTER total;

ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS variant_id BIGINT UNSIGNED NULL AFTER product_id,
  ADD COLUMN IF NOT EXISTS size VARCHAR(64) NULL AFTER product_name;

INSERT INTO product_variants (product_id, sku, size, stock, active)
SELECT p.id, p.sku, 'Única', p.stock, p.active
FROM products p
LEFT JOIN product_variants pv ON pv.sku = p.sku
WHERE pv.id IS NULL;

INSERT INTO product_images (product_id, image_url, is_primary)
SELECT p.id, p.image_url, TRUE
FROM products p
LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.image_url = p.image_url
WHERE p.image_url IS NOT NULL AND pi.id IS NULL;

UPDATE products
SET category = COALESCE(category, 'general'),
    type = COALESCE(type, unit),
    description = COALESCE(description, '')
WHERE category IS NULL OR type IS NULL OR description IS NULL;

UPDATE order_items oi
JOIN product_variants pv ON pv.sku = oi.sku
SET oi.variant_id = COALESCE(oi.variant_id, pv.id),
    oi.size = COALESCE(oi.size, pv.size);

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
  DECLARE v_variant_id BIGINT UNSIGNED;
  DECLARE v_product_name VARCHAR(255);
  DECLARE v_size VARCHAR(64);
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
    SET v_variant_id = NULL;
    SET v_product_id = NULL;

    SELECT pv.id, p.id, p.name, COALESCE(p.sale_price, p.price), pv.stock, pv.size, COALESCE(pi.image_url, p.image_url)
      INTO v_variant_id, v_product_id, v_product_name, v_price, v_stock, v_size, v_image_url
      FROM product_variants pv
      JOIN products p ON p.id = pv.product_id
      LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = TRUE
      WHERE pv.sku = v_sku AND pv.active = TRUE AND p.active = TRUE
      LIMIT 1 FOR UPDATE;

    IF v_variant_id IS NULL THEN
      SELECT id, name, COALESCE(sale_price, price), stock, image_url
        INTO v_product_id, v_product_name, v_price, v_stock, v_image_url
        FROM products
        WHERE sku = v_sku AND active = TRUE
        LIMIT 1 FOR UPDATE;
      SET v_size = NULL;
    END IF;

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
    SET v_variant_id = NULL;

    SELECT pv.id, p.id, p.name, COALESCE(p.sale_price, p.price), pv.size, COALESCE(pi.image_url, p.image_url)
      INTO v_variant_id, v_product_id, v_product_name, v_price, v_size, v_image_url
      FROM product_variants pv
      JOIN products p ON p.id = pv.product_id
      LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = TRUE
      WHERE pv.sku = v_sku
      LIMIT 1;

    IF v_variant_id IS NULL THEN
      SELECT id, name, COALESCE(sale_price, price), image_url
        INTO v_product_id, v_product_name, v_price, v_image_url
        FROM products WHERE sku = v_sku LIMIT 1;
      SET v_size = NULL;
    END IF;

    INSERT INTO order_items (order_id, product_id, variant_id, sku, product_name, size, quantity, unit_price, image_url)
    VALUES (v_order_id, v_product_id, v_variant_id, v_sku, v_product_name, v_size, v_quantity, v_price, v_image_url);

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
        'size', oi.size,
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
  LEFT JOIN product_variants pv ON pv.id = oi.variant_id
  JOIN products p ON p.id = oi.product_id
  WHERE oi.order_id = p_order_id
    AND COALESCE(pv.stock, p.stock) < oi.quantity;

  IF v_missing > 0 THEN
    ROLLBACK;
    SELECT JSON_OBJECT('ok', FALSE, 'error', 'El inventario cambió y ya no hay stock suficiente') AS result;
    LEAVE proc;
  END IF;

  UPDATE product_variants pv
  JOIN order_items oi ON oi.variant_id = pv.id AND oi.order_id = p_order_id
  SET pv.stock = pv.stock - oi.quantity;

  UPDATE products p
  SET p.stock = COALESCE((SELECT SUM(pv.stock) FROM product_variants pv WHERE pv.product_id = p.id), p.stock)
  WHERE p.id IN (SELECT oi.product_id FROM order_items oi WHERE oi.order_id = p_order_id);

  UPDATE products p
  JOIN order_items oi ON oi.product_id = p.id AND oi.order_id = p_order_id AND oi.variant_id IS NULL
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
  o.delivery_notes,
  o.total,
  o.created_at,
  o.payment_confirmed_at,
  GROUP_CONCAT(CONCAT(oi.quantity, ' x ', oi.product_name, IF(oi.size IS NULL OR oi.size = '', '', CONCAT(' talla ', oi.size))) ORDER BY oi.id SEPARATOR '\n') AS items
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.status = 'CONFIRMED'
GROUP BY o.id, o.customer_name, o.phone, o.delivery_address, o.delivery_notes, o.total, o.created_at, o.payment_confirmed_at;

CREATE OR REPLACE VIEW catalog_available_variants AS
SELECT
  p.id AS product_id,
  pv.id AS variant_id,
  pv.sku,
  p.name,
  p.description,
  p.category,
  p.type,
  p.brand,
  p.color,
  p.gender,
  pv.size,
  pv.stock,
  pv.reserved_stock,
  COALESCE(p.sale_price, p.price) AS price,
  p.price AS regular_price,
  p.sale_price,
  COALESCE(pi.image_url, p.image_url) AS image_url,
  p.aliases
FROM product_variants pv
JOIN products p ON p.id = pv.product_id
LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = TRUE
WHERE p.active = TRUE
  AND pv.active = TRUE
  AND pv.stock > 0;
